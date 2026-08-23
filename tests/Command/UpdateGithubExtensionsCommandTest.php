<?php

namespace App\Tests\Command;

use App\Command\UpdateGithubExtensionsCommand;
use App\Entity\ExtensionSource;
use App\Repository\ExtensionSourceRepository;
use App\Service\GitHub\ApiCache;
use App\Service\GitHub\ApiClient;
use App\Service\GitHub\CandidateLoader;
use App\Service\GitHub\CandidateProcessor;
use App\Service\GitHub\DiscoveryRunner;
use App\Service\GitHub\IconResolver;
use App\Service\GitHub\ImageProbe;
use App\Service\GitHub\ImageValidator;
use App\Service\GitHub\MetadataValidator;
use App\Service\GitHub\ReadmeImageExtractor;
use App\Service\GitHub\RepositoryEligibilityChecker;
use App\Service\GitHub\ScreenshotResolver;
use App\Service\GitHub\ReleaseSelector;
use App\Service\GitHub\SourceMapper;
use App\Service\GitHub\SourcePersister;
use App\Service\GitHub\SourceRefreshRunner;
use App\Service\GitHub\TokenProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Command-mode, token-gate, and lock behaviour, tested with fakes/mocks so
 * no kernel boot, no database, and no live GitHub call is needed.
 */
class UpdateGithubExtensionsCommandTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/github-command-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            (new Filesystem())->remove($this->cacheDir);
        }
    }

    /**
     * @return ExtensionSource[]
     */
    private function fakeKnownSources(int $count): array
    {
        $sources = [];
        for ($i = 0; $i < $count; $i++) {
            $source = new ExtensionSource();
            $source->sourceType = ExtensionSource::TYPE_GITHUB;
            $source->externalIdentifier = (string) (1000 + $i);
            $sources[] = $source;
        }

        return $sources;
    }

    private function fakeRefreshRunner(
        ApiClient $apiClient,
        MockHttpClient $httpClient,
        SourcePersister $persister,
        int $knownSourceCount = 0,
    ): SourceRefreshRunner {
        $sourceRepository = $this->createMock(ExtensionSourceRepository::class);
        $sourceRepository->method('findAllGithubSourcesForRefresh')->willReturn($this->fakeKnownSources($knownSourceCount));

        return new SourceRefreshRunner(
            $sourceRepository,
            new CandidateLoader($apiClient, new MetadataValidator()),
            $this->candidateProcessor($apiClient, $httpClient),
            $persister,
        );
    }

    /** Real CandidateProcessor sharing this fixture's mock-backed collaborators. */
    private function candidateProcessor(ApiClient $apiClient, MockHttpClient $httpClient): CandidateProcessor
    {
        return new CandidateProcessor(
            new RepositoryEligibilityChecker(),
            new CandidateLoader($apiClient, new MetadataValidator()),
            new ReleaseSelector(),
            $this->screenshotResolver($apiClient, $httpClient),
            $this->iconResolver($apiClient, $httpClient),
        );
    }

    private function screenshotResolver(ApiClient $apiClient, MockHttpClient $httpClient): ScreenshotResolver
    {
        $imageValidator = new ImageValidator();

        return new ScreenshotResolver(
            $apiClient,
            new ReadmeImageExtractor(),
            new ImageProbe($httpClient, $imageValidator),
            $imageValidator,
        );
    }

    private function iconResolver(ApiClient $apiClient, MockHttpClient $httpClient): IconResolver
    {
        $imageValidator = new ImageValidator();

        return new IconResolver($apiClient, new ImageProbe($httpClient, $imageValidator), $imageValidator);
    }

    /**
     * @return array{0: TokenProvider, 1: DiscoveryRunner, 2: SourceRefreshRunner, 3: int[]}
     */
    private function buildDependencies(?string $token, ?callable $httpResponder = null, int $knownSourceCount = 0): array
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

        $httpCalls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$httpCalls, $httpResponder) {
            $httpCalls[] = $url;

            return $httpResponder !== null
                ? $httpResponder($method, $url, $options)
                : new MockResponse('{"items":[]}', ['http_code' => 200]);
        });

        $parameterBag = new ParameterBag(['kernel.project_dir' => $this->cacheDir]);
        $apiClient = new ApiClient($httpClient, new ApiCache($parameterBag));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $sourceRepository = $this->createMock(ExtensionSourceRepository::class);
        $metricRepository = $this->createMock(\App\Repository\SourceMetricMeasurementRepository::class);
        $persister = new SourcePersister($entityManager, $sourceRepository, $metricRepository, new SourceMapper());

        $discoveryRunner = new DiscoveryRunner(
            $apiClient,
            $this->candidateProcessor($apiClient, $httpClient),
            $persister,
        );

        $refreshRunner = $this->fakeRefreshRunner($apiClient, $httpClient, $persister, $knownSourceCount);

        return [$tokenProvider, $discoveryRunner, $refreshRunner, $httpCalls];
    }

    private function commandTester(
        TokenProvider $tokenProvider,
        DiscoveryRunner $discoveryRunner,
        SourceRefreshRunner $refreshRunner,
        ?string $lockFilePath = null,
        int $lockTimeoutSeconds = 10,
    ): CommandTester {
        $lockFilePath ??= $this->cacheDir . '/github-update.lock';
        $command = new UpdateGithubExtensionsCommand($tokenProvider, $discoveryRunner, $refreshRunner, $lockFilePath, $lockTimeoutSeconds);

        return new CommandTester($command);
    }

    public function testMissingTokenFailsWithoutHttpCall(): void
    {
        $calls = 0;
        $tokenProvider = new class extends TokenProvider {
            public function getToken(): ?string
            {
                return null;
            }
        };

        $httpClient = new MockHttpClient(function () use (&$calls) {
            $calls++;
            self::fail('No HTTP call should be made when the token is missing.');
        });

        $parameterBag = new ParameterBag(['kernel.project_dir' => $this->cacheDir]);
        $apiClient = new ApiClient($httpClient, new ApiCache($parameterBag));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $sourceRepository = $this->createMock(ExtensionSourceRepository::class);
        $metricRepository = $this->createMock(\App\Repository\SourceMetricMeasurementRepository::class);
        $persister = new SourcePersister($entityManager, $sourceRepository, $metricRepository, new SourceMapper());
        $discoveryRunner = new DiscoveryRunner(
            $apiClient,
            $this->candidateProcessor($apiClient, $httpClient),
            $persister,
        );

        $tester = $this->commandTester($tokenProvider, $discoveryRunner, $this->fakeRefreshRunner($apiClient, $httpClient, $persister));
        $statusCode = $tester->execute([]);

        self::assertSame(1, $statusCode);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('GITHUB_TOKEN is missing or empty', $display);
        self::assertStringContainsString('no unauthenticated fallback', $display);
        self::assertSame(0, $calls);
    }

    public function testDefaultModeRunsDiscoveryAndRefresh(): void
    {
        [$tokenProvider, $discoveryRunner, $refreshRunner, $httpCalls] = $this->buildDependencies('token');

        $tester = $this->commandTester($tokenProvider, $discoveryRunner, $refreshRunner);
        $statusCode = $tester->execute([]);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('Discovery processed', $tester->getDisplay());
        self::assertStringContainsString('0 persisted, 0 skipped', $tester->getDisplay());
        self::assertStringContainsString('0 known GitHub sources', $tester->getDisplay());
    }

    public function testRateLimitedDiscoveryFailsWithAClearMessageAndNoSecrets(): void
    {
        [$tokenProvider, $discoveryRunner, $refreshRunner] = $this->buildDependencies('super-secret-token', function () {
            return new MockResponse('{"message":"API rate limit exceeded"}', [
                'http_code' => 403,
                'response_headers' => ['X-RateLimit-Remaining' => '0'],
            ]);
        });

        $tester = $this->commandTester($tokenProvider, $discoveryRunner, $refreshRunner);
        $statusCode = $tester->execute(['--discover' => true]);

        self::assertSame(1, $statusCode);
        // Whitespace-normalized: console word-wrapping can otherwise break
        // "without partial updates" across two lines.
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('rate limit', $display);
        self::assertStringContainsString('without partial updates', $display);
        self::assertStringNotContainsString('super-secret-token', $display);
    }

    public function testRefreshReportsTheActualKnownSourceCountInsteadOfAHardcodedZero(): void
    {
        // No repository/full_name in the default '{"items":[]}' response, so
        // every known source is skipped as "not found" rather than refreshed.
        [$tokenProvider, $discoveryRunner, $refreshRunner] = $this->buildDependencies('token', knownSourceCount: 3);

        $tester = $this->commandTester($tokenProvider, $discoveryRunner, $refreshRunner);
        $statusCode = $tester->execute(['--refresh' => true]);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('updated 0 of 3 known GitHub source(s)', $tester->getDisplay());
        self::assertStringContainsString('3 skipped', $tester->getDisplay());
    }

    public function testRefreshActuallyPersistsAKnownEligibleSource(): void
    {
        $repositoryResponse = static fn () => new MockResponse(json_encode([
            'id' => 1000,
            'full_name' => 'owner/repo',
            'html_url' => 'https://github.com/owner/repo',
            'stargazers_count' => 10,
            'forks_count' => 2,
            'archived' => false,
            'private' => false,
            'pushed_at' => '2026-01-01T00:00:00Z',
            'owner' => ['login' => 'owner', 'html_url' => 'https://github.com/owner'],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);

        $metadataJson = json_encode(['uuid' => 'repo@owner', 'shell-version' => ['45']], JSON_THROW_ON_ERROR);
        $metadataResponse = static fn () => new MockResponse(json_encode([
            'name' => 'metadata.json',
            'path' => 'metadata.json',
            'type' => 'file',
            'encoding' => 'base64',
            'content' => base64_encode($metadataJson),
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);

        $releasesResponse = static fn () => new MockResponse('[]', ['http_code' => 200]);

        $responses = [$repositoryResponse(), $metadataResponse(), $releasesResponse()];
        $httpResponder = function () use (&$responses) {
            return array_shift($responses);
        };

        [$tokenProvider, $discoveryRunner, $refreshRunner] = $this->buildDependencies('token', $httpResponder, knownSourceCount: 1);

        $tester = $this->commandTester($tokenProvider, $discoveryRunner, $refreshRunner);
        $statusCode = $tester->execute(['--refresh' => true]);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('updated 1 of 1 known GitHub source(s)', $tester->getDisplay());
        self::assertStringContainsString('0 skipped', $tester->getDisplay());
    }

    public function testDiscoverOptionRunsOnlyDiscovery(): void
    {
        [$tokenProvider, $discoveryRunner, $refreshRunner] = $this->buildDependencies('token');

        $tester = $this->commandTester($tokenProvider, $discoveryRunner, $refreshRunner);
        $statusCode = $tester->execute(['--discover' => true]);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('Discovery processed', $tester->getDisplay());
        self::assertStringNotContainsString('known GitHub sources', $tester->getDisplay());
    }

    public function testRefreshOptionRunsOnlyRefresh(): void
    {
        [$tokenProvider, $discoveryRunner, $refreshRunner] = $this->buildDependencies('token');

        $tester = $this->commandTester($tokenProvider, $discoveryRunner, $refreshRunner);
        $statusCode = $tester->execute(['--refresh' => true]);

        self::assertSame(0, $statusCode);
        self::assertStringNotContainsString('Discovery processed', $tester->getDisplay());
        self::assertStringContainsString('0 known GitHub sources', $tester->getDisplay());
    }

    public function testBothOptionsTogetherRunBothModes(): void
    {
        [$tokenProvider, $discoveryRunner, $refreshRunner] = $this->buildDependencies('token');

        $tester = $this->commandTester($tokenProvider, $discoveryRunner, $refreshRunner);
        $statusCode = $tester->execute(['--discover' => true, '--refresh' => true]);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('Discovery processed', $tester->getDisplay());
        self::assertStringContainsString('0 known GitHub sources', $tester->getDisplay());
    }

    public function testRateLimitedRefreshFailsWithAClearMessageAndNoSecrets(): void
    {
        [$tokenProvider, $discoveryRunner, $refreshRunner] = $this->buildDependencies(
            'super-secret-token',
            function () {
                return new MockResponse('{"message":"API rate limit exceeded"}', [
                    'http_code' => 403,
                    'response_headers' => ['X-RateLimit-Remaining' => '0'],
                ]);
            },
            knownSourceCount: 1,
        );

        $tester = $this->commandTester($tokenProvider, $discoveryRunner, $refreshRunner);
        $statusCode = $tester->execute(['--refresh' => true]);

        self::assertSame(1, $statusCode);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('rate limit', $display);
        self::assertStringContainsString('without partial updates', $display);
        self::assertStringNotContainsString('super-secret-token', $display);
    }

    public function testLockAlreadyHeldFailsTheCommandWithoutALongWait(): void
    {
        [$tokenProvider, $discoveryRunner, $refreshRunner] = $this->buildDependencies('token');

        (new Filesystem())->mkdir($this->cacheDir);
        $lockFilePath = $this->cacheDir . '/github-update.lock';
        $heldHandle = fopen($lockFilePath, 'c');
        self::assertNotFalse($heldHandle, 'Could not open the lock file for the held-lock fixture.');
        self::assertTrue(flock($heldHandle, LOCK_EX), 'Could not acquire the fixture lock.');

        try {
            // A 1-second timeout keeps this test fast while still exercising
            // the real flock retry loop instead of failing before trying.
            $tester = $this->commandTester($tokenProvider, $discoveryRunner, $refreshRunner, $lockFilePath, lockTimeoutSeconds: 1);
            $statusCode = $tester->execute([]);

            self::assertSame(1, $statusCode);
            self::assertStringContainsString('Cannot acquire the GitHub update lock', $tester->getDisplay());
        } finally {
            flock($heldHandle, LOCK_UN);
            fclose($heldHandle);
        }
    }
}
