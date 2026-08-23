<?php

namespace App\Tests\Command;

use App\Command\ImportGithubRepositoryCommand;
use App\Entity\Extension;
use App\Repository\ExtensionSourceRepository;
use App\Repository\SourceMetricMeasurementRepository;
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
use App\Service\GitHub\ScreenshotResolver;
use App\Service\GitHub\SourceMapper;
use App\Service\GitHub\SourcePersister;
use App\Service\GitHub\TargetedRepositoryLoader;
use App\Service\GitHub\TokenProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The targeted `app:import-github-repository` command: same token-gate/lock
 * shape as UpdateGithubExtensionsCommand, tested against mocks only.
 */
class ImportGithubRepositoryCommandTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/import-github-repository-command-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            (new Filesystem())->remove($this->cacheDir);
        }
    }

    private function repositoryResponse(array $overrides = []): JsonMockResponse
    {
        return new JsonMockResponse(array_merge([
            'id' => 1,
            'full_name' => 'owner/repo',
            'html_url' => 'https://github.com/owner/repo',
            'stargazers_count' => 10,
            'forks_count' => 2,
            'archived' => false,
            'private' => false,
            'pushed_at' => '2026-01-01T00:00:00Z',
            'owner' => ['login' => 'owner', 'html_url' => 'https://github.com/owner'],
        ], $overrides));
    }

    private function metadataContentResponse(string $uuid = 'repo@owner'): JsonMockResponse
    {
        $json = json_encode(['uuid' => $uuid, 'shell-version' => ['45']], JSON_THROW_ON_ERROR);

        return new JsonMockResponse([
            'name' => 'metadata.json',
            'path' => 'metadata.json',
            'type' => 'file',
            'encoding' => 'base64',
            'content' => base64_encode($json),
        ]);
    }

    private function notFoundResponse(): MockResponse
    {
        return new MockResponse('{"message":"Not Found"}', ['http_code' => 404]);
    }

    /** @return MockResponse[] */
    private function noScreenshotAndIconResponses(): array
    {
        return [$this->notFoundResponse(), $this->notFoundResponse()];
    }

    /**
     * @return array{0: TokenProvider, 1: TargetedRepositoryLoader, 2: SourcePersister, 3: object{items: object[]}}
     */
    private function buildDependencies(?string $token, MockHttpClient $httpClient): array
    {
        $tokenProvider = new class($token) extends TokenProvider {
            public function __construct(private readonly ?string $fakeToken)
            {
            }

            public function getToken(): ?string
            {
                return $this->fakeToken;
            }
        };

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
        $targetedRepositoryLoader = new TargetedRepositoryLoader($candidateLoader, $candidateProcessor);

        $extensionRepository = $this->createMock(EntityRepository::class);
        $extensionRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Extension::class)->willReturn($extensionRepository);

        $persistedHolder = new class {
            /** @var object[] */
            public array $items = [];
        };
        $entityManager->method('persist')->willReturnCallback(function (object $entity) use ($persistedHolder): void {
            $persistedHolder->items[] = $entity;
        });

        $sourceRepository = $this->createMock(ExtensionSourceRepository::class);
        $sourceRepository->method('findOneByExtensionAndType')->willReturn(null);
        $sourceRepository->method('findOneByTypeAndExternalIdentifier')->willReturn(null);

        $metricRepository = $this->createMock(SourceMetricMeasurementRepository::class);
        $persister = new SourcePersister($entityManager, $sourceRepository, $metricRepository, new SourceMapper());

        return [$tokenProvider, $targetedRepositoryLoader, $persister, $persistedHolder];
    }

    private function commandTester(
        TokenProvider $tokenProvider,
        TargetedRepositoryLoader $loader,
        SourcePersister $persister,
        ?string $lockFilePath = null,
        int $lockTimeoutSeconds = 10,
    ): CommandTester {
        $lockFilePath ??= $this->cacheDir . '/github-single-import.lock';

        return new CommandTester(new ImportGithubRepositoryCommand($tokenProvider, $loader, $persister, $lockFilePath, $lockTimeoutSeconds));
    }

    public function testMissingTokenFailsWithoutHttpCall(): void
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('No HTTP call should be made when the token is missing.');
        });
        [, $loader, $persister] = $this->buildDependencies(null, $httpClient);
        $tokenProvider = new class extends TokenProvider {
            public function getToken(): ?string
            {
                return null;
            }
        };

        $tester = $this->commandTester($tokenProvider, $loader, $persister);
        $statusCode = $tester->execute(['repository' => 'owner/repo']);

        self::assertSame(1, $statusCode);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('GITHUB_TOKEN is missing or empty', $display);
    }

    public function testInvalidArgumentFormatFailsWithoutAnyHttpCall(): void
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('No HTTP call should be made for an invalid owner/repository argument.');
        });
        [$tokenProvider, $loader, $persister] = $this->buildDependencies('token', $httpClient);

        $tester = $this->commandTester($tokenProvider, $loader, $persister);
        $statusCode = $tester->execute(['repository' => 'not-a-valid-reference']);

        self::assertSame(1, $statusCode);
        self::assertStringContainsString('owner/repository', $tester->getDisplay());
    }

    public function testGitCloneStyleArgumentIsRejectedWithoutAnyHttpCall(): void
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('No HTTP call should be made for a .git-suffixed argument.');
        });
        [$tokenProvider, $loader, $persister] = $this->buildDependencies('token', $httpClient);

        $tester = $this->commandTester($tokenProvider, $loader, $persister);
        $statusCode = $tester->execute(['repository' => 'owner/repo.git']);

        self::assertSame(1, $statusCode);
        self::assertStringContainsString('owner/repository', $tester->getDisplay());
    }

    public function testSuccessfulImportPersistsSourceAndReportsSuccess(): void
    {
        $httpClient = new MockHttpClient([
            $this->repositoryResponse(),
            $this->metadataContentResponse(),
            new JsonMockResponse([]), // releases
            ...$this->noScreenshotAndIconResponses(),
        ]);
        [$tokenProvider, $loader, $persister, $persistedHolder] = $this->buildDependencies('token', $httpClient);

        $tester = $this->commandTester($tokenProvider, $loader, $persister);
        $statusCode = $tester->execute(['repository' => 'owner/repo']);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('Imported owner/repo', $tester->getDisplay());
        self::assertCount(2, $persistedHolder->items, 'Expected the new Extension and its GitHub ExtensionSource to be persisted.');
    }

    /**
     * This targeted command bypasses only the minimum-star rule, so a
     * low-star repository that is otherwise eligible must be imported.
     */
    public function testLowStarRepositoryIsImportedNotSkipped(): void
    {
        $httpClient = new MockHttpClient([
            $this->repositoryResponse(['stargazers_count' => 0]),
            $this->metadataContentResponse(),
            new JsonMockResponse([]), // releases
            ...$this->noScreenshotAndIconResponses(),
        ]);
        [$tokenProvider, $loader, $persister] = $this->buildDependencies('token', $httpClient);

        $tester = $this->commandTester($tokenProvider, $loader, $persister);
        $statusCode = $tester->execute(['repository' => 'owner/repo']);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('Imported owner/repo', $tester->getDisplay());
    }

    public function testIneligibleRepositoryIsReportedAsASkipNotAFailure(): void
    {
        $httpClient = new MockHttpClient([$this->repositoryResponse(['archived' => true])]);
        [$tokenProvider, $loader, $persister] = $this->buildDependencies('token', $httpClient);

        $tester = $this->commandTester($tokenProvider, $loader, $persister);
        $statusCode = $tester->execute(['repository' => 'owner/repo']);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('archived_repository', $tester->getDisplay());
    }

    public function testRepositoryNotFoundIsReportedAsASkipNotAFailure(): void
    {
        $httpClient = new MockHttpClient([$this->notFoundResponse()]);
        [$tokenProvider, $loader, $persister] = $this->buildDependencies('token', $httpClient);

        $tester = $this->commandTester($tokenProvider, $loader, $persister);
        $statusCode = $tester->execute(['repository' => 'owner/repo']);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString(TargetedRepositoryLoader::SKIP_REPOSITORY_NOT_FOUND, $tester->getDisplay());
    }

    public function testRateLimitedApiFailsWithAClearMessageAndNoSecrets(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"message":"API rate limit exceeded"}', [
                'http_code' => 403,
                'response_headers' => ['X-RateLimit-Remaining' => '0'],
            ]),
        ]);
        [$tokenProvider, $loader, $persister] = $this->buildDependencies('super-secret-token', $httpClient);

        $tester = $this->commandTester($tokenProvider, $loader, $persister);
        $statusCode = $tester->execute(['repository' => 'owner/repo']);

        self::assertSame(1, $statusCode);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('rate limit', $display);
        self::assertStringNotContainsString('super-secret-token', $display);
    }

    public function testLockAlreadyHeldFailsTheCommandWithoutALongWait(): void
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('No HTTP call should be made while the lock is held by another run.');
        });
        [$tokenProvider, $loader, $persister] = $this->buildDependencies('token', $httpClient);

        (new Filesystem())->mkdir($this->cacheDir);
        $lockFilePath = $this->cacheDir . '/github-single-import.lock';
        $heldHandle = fopen($lockFilePath, 'c');
        self::assertNotFalse($heldHandle, 'Could not open the lock file for the held-lock fixture.');
        self::assertTrue(flock($heldHandle, LOCK_EX), 'Could not acquire the fixture lock.');

        try {
            $tester = $this->commandTester($tokenProvider, $loader, $persister, $lockFilePath, lockTimeoutSeconds: 1);
            $statusCode = $tester->execute(['repository' => 'owner/repo']);

            self::assertSame(1, $statusCode);
            self::assertStringContainsString('Cannot acquire the GitHub import lock', $tester->getDisplay());
        } finally {
            flock($heldHandle, LOCK_UN);
            fclose($heldHandle);
        }
    }
}
