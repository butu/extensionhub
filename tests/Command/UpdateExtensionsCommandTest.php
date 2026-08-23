<?php

namespace App\Tests\Command;

use App\Command\UpdateExtensionsCommand;
use App\Entity\Extension;
use App\Entity\ExtensionComment;
use App\Repository\ExtensionSourceRepository;
use App\Repository\SourceMetricMeasurementRepository;
use App\Service\EgoExtensionImportService;
use App\Service\EgoExtensionMapper;
use App\Service\EgoSourceBackfillService;
use App\Service\EgoSourceMapper;
use App\Service\GitHub\ApiCache;
use App\Service\GitHub\ApiClient;
use App\Service\GitHub\CandidateLoader;
use App\Service\GitHub\CandidateProcessor;
use App\Service\GitHub\IconResolver;
use App\Service\GitHub\ImageProbe;
use App\Service\GitHub\ImageValidator;
use App\Service\GitHub\MetadataValidator;
use App\Service\GitHub\ReadmeImageExtractor;
use App\Service\GitHub\ReleaseSelector;
use App\Service\GitHub\RepositoryEligibilityChecker;
use App\Service\GitHub\RepositoryReferenceParser;
use App\Service\GitHub\ScreenshotResolver;
use App\Service\GitHub\SourceMapper as GithubSourceMapper;
use App\Service\GitHub\SourcePersister;
use App\Service\GitHub\TargetedRepositoryLoader;
use App\Service\GitHub\TokenProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Thin-orchestration test for the command: the actual EGO request/mapping/
 * persistence/backfill behaviour is covered by EgoExtensionImportServiceTest
 * and EgoExtensionMapperTest. This only verifies the command wires a real
 * EgoExtensionImportService and reports its result the way the previous,
 * unextracted command did. Uses a MockHttpClient and mocked Doctrine
 * collaborators only: no live EGO call and no database connection.
 */
class UpdateExtensionsCommandTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/update-extensions-command-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            (new Filesystem())->remove($this->cacheDir);
        }
    }

    private function extensionQueryPage(array $extensions): string
    {
        return json_encode(['extensions' => $extensions], JSON_THROW_ON_ERROR);
    }

    private function rawExtension(): array
    {
        return [
            'uuid' => 'plaid@plyply99',
            'name' => 'Plaid',
            'pk' => 12345,
            'description' => 'A nice extension',
            'link' => '/extension/12345/plaid/',
            'icon' => '/static/extension-data/icons/12345.png',
            'downloads' => 500,
            'shell_version_map' => ['45' => ['pk' => 64995, 'version' => 102]],
        ];
    }

    /**
     * @param array<int, array{id: int, first_version_pk: ?int}> $backfillRows
     */
    private function commandTester(callable $httpResponder, array $backfillRows = []): CommandTester
    {
        $httpClient = new MockHttpClient($httpResponder);

        $extensionRepository = $this->createMock(EntityRepository::class);
        $extensionRepository->method('findOneBy')->willReturn(null);

        $ormRepositoryReturningNothing = $this->createMock(EntityRepository::class);
        $ormRepositoryReturningNothing->method('findBy')->willReturn([]);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($backfillRows);
        $connection->method('executeStatement')->willReturn(1);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturnMap([
            [Extension::class, $extensionRepository],
            [ExtensionComment::class, $ormRepositoryReturningNothing],
        ]);

        $sourceMetricRepository = $this->createMock(SourceMetricMeasurementRepository::class);
        $sourceMetricRepository->method('purgeOlderThan')->willReturn(4);

        $sourceRepository = $this->createMock(ExtensionSourceRepository::class);
        $sourceRepository->method('findOneByExtensionAndType')->willReturn(null);
        $sourceRepository->method('findOneByTypeAndExternalIdentifier')->willReturn(null);

        $backfillService = new EgoSourceBackfillService($entityManager, $sourceRepository, $sourceMetricRepository, new EgoSourceMapper());

        $parameterBag = new ParameterBag(['kernel.project_dir' => $this->cacheDir]);
        $apiClient = new ApiClient($httpClient, new ApiCache($parameterBag));
        $imageValidator = new ImageValidator();
        $candidateLoader = new CandidateLoader($apiClient, new MetadataValidator());
        $candidateProcessor = new CandidateProcessor(
            new RepositoryEligibilityChecker(),
            $candidateLoader,
            new ReleaseSelector(),
            new ScreenshotResolver($apiClient, new ReadmeImageExtractor(), new ImageProbe($httpClient, $imageValidator), $imageValidator),
            new IconResolver($apiClient, new ImageProbe($httpClient, $imageValidator), $imageValidator),
        );
        $tokenProvider = new class extends TokenProvider {
            public function getToken(): ?string
            {
                // None of these fixtures set an EGO homepage, so the GitHub
                // check always short-circuits before a token would matter.
                return null;
            }
        };

        $importService = new EgoExtensionImportService(
            $entityManager,
            $sourceMetricRepository,
            $backfillService,
            new EgoExtensionMapper(),
            $httpClient,
            $tokenProvider,
            new RepositoryReferenceParser(),
            new TargetedRepositoryLoader($candidateLoader, $candidateProcessor),
            new SourcePersister($entityManager, $sourceRepository, $sourceMetricRepository, new GithubSourceMapper()),
            sleepMicroseconds: 0,
        );

        return new CommandTester(new UpdateExtensionsCommand($importService));
    }

    public function testSuccessfulRunReportsBackfillPerExtensionAndPurgeOutcomes(): void
    {
        $responses = [
            new MockResponse($this->extensionQueryPage([$this->rawExtension()]), ['http_code' => 200]),
            new MockResponse('', ['http_code' => 404]),
        ];
        $tester = $this->commandTester(
            function () use (&$responses) {
                return array_shift($responses);
            },
            backfillRows: [['id' => 1, 'first_version_pk' => 19642]],
        );

        $statusCode = $tester->execute([]);

        self::assertSame(0, $statusCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Backfilled 1 missing creation dates.', $display);
        self::assertStringContainsString('Extension Plaid updated', $display);
        self::assertStringContainsString('Purged 0 download measurements older than 12 months.', $display);
        self::assertStringContainsString('Purged 4 source metric measurements older than 365 days.', $display);

        // Backfill is reported before per-extension progress, matching the pre-refactor command.
        self::assertLessThan(
            strpos($display, 'Extension Plaid updated'),
            strpos($display, 'Backfilled 1 missing creation dates.')
        );
    }

    public function testNoBackfillNoteWhenNothingNeedsBackfilling(): void
    {
        $tester = $this->commandTester(function () {
            return new MockResponse('', ['http_code' => 404]);
        });

        $statusCode = $tester->execute([]);

        self::assertSame(0, $statusCode);
        self::assertStringNotContainsString('Backfilled', $tester->getDisplay());
    }

    public function testNeverCallsLiveEgo(): void
    {
        $calls = 0;
        $tester = $this->commandTester(function (string $method, string $url) use (&$calls) {
            $calls++;
            self::assertStringStartsWith('https://extensions.gnome.org/extension-query/', $url);

            return new MockResponse('', ['http_code' => 404]);
        });

        $tester->execute([]);

        self::assertSame(1, $calls, 'Must stop after the first failing page.');
    }
}
