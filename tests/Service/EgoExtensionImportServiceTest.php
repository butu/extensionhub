<?php

namespace App\Tests\Service;

use App\Entity\Extension;
use App\Entity\ExtensionComment;
use App\Entity\ExtensionSource;
use App\Entity\SourceMetricMeasurement;
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
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The focused EGO import service extracted from UpdateExtensionsCommand:
 * request/pagination, mapping, persistence and backfill orchestration.
 *
 * Wires a real EgoSourceBackfillService (like DiscoveryRunnerTest wires a
 * real SourcePersister) on top of mocked Doctrine leaf collaborators, so the
 * backfill interaction is exercised for real rather than assumed. Tested
 * exclusively against a MockHttpClient: no live EGO call and no database
 * connection are made.
 */
class EgoExtensionImportServiceTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ego-import-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            (new Filesystem())->remove($this->cacheDir);
        }
    }

    private function extensionQueryPage(array $extensions): string
    {
        return json_encode(['extensions' => $extensions, 'total' => count($extensions)], JSON_THROW_ON_ERROR);
    }

    private function rawExtension(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'plaid@plyply99',
            'name' => 'Plaid',
            'pk' => 12345,
            'description' => 'A nice extension',
            'link' => '/extension/12345/plaid/',
            'icon' => '/static/extension-data/icons/12345.png',
            'downloads' => 500,
            'shell_version_map' => ['45' => ['pk' => 64995, 'version' => 102]],
        ], $overrides);
    }

    /**
     * @return array{0: EgoExtensionImportService, 1: object{items: object[]}, 2: object{items: string[]}}
     */
    private function service(
        callable $httpResponder,
        ?EntityRepository $extensionRepository = null,
        ?SourceMetricMeasurementRepository $sourceMetricRepository = null,
        ?string $githubToken = null,
        ?HttpClientInterface $githubHttpClient = null,
    ): array {
        $urlHolder = new class {
            /** @var string[] */
            public array $items = [];
        };
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($urlHolder, $httpResponder) {
            $urlHolder->items[] = $url;

            return $httpResponder($method, $url, $options);
        });

        $persistedHolder = new class {
            /** @var object[] */
            public array $items = [];
        };

        // Distinguishes the two different findOneBy() lookups sharing this one
        // mocked repository: the EGO mapper's by-link lookup (always "new" here)
        // and SourcePersister's by-uuid lookup, which must find the very same
        // Extension the EGO import just persisted so the GitHub source attaches
        // to it instead of spawning a second, GitHub-only Extension.
        if ($extensionRepository === null) {
            $extensionRepository = $this->createMock(EntityRepository::class);
            $extensionRepository->method('findOneBy')->willReturnCallback(
                function (array $criteria) use ($persistedHolder) {
                    if (!isset($criteria['uuid'])) {
                        return null;
                    }

                    foreach ($persistedHolder->items as $item) {
                        if ($item instanceof Extension && $item->uuid === $criteria['uuid']) {
                            return $item;
                        }
                    }

                    return null;
                }
            );
        }

        $commentOrmRepository = $this->createMock(EntityRepository::class);
        $commentOrmRepository->method('findBy')->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        // Proves the dropped extension_download_measurement table is never
        // queried anymore: any repository other than these two known-good
        // ones fails the test instead of silently returning a stub.
        $entityManager->method('getRepository')->willReturnCallback(
            function (string $class) use ($extensionRepository, $commentOrmRepository) {
                return match ($class) {
                    Extension::class => $extensionRepository,
                    ExtensionComment::class => $commentOrmRepository,
                    default => self::fail("Unexpected repository requested: {$class}"),
                };
            }
        );
        $entityManager->method('persist')->willReturnCallback(function (object $entity) use ($persistedHolder): void {
            $persistedHolder->items[] = $entity;
        });

        $sourceMetricRepository ??= $this->createMock(SourceMetricMeasurementRepository::class);

        // Also stateful for the same reason: SourcePersister::hasEgoSource()
        // must see the EGO ExtensionSource the backfill step just persisted,
        // or it would wrongly treat every extension as GitHub-only and
        // overwrite its EGO display fields (including `link`).
        $sourceRepository = $this->createMock(ExtensionSourceRepository::class);
        $sourceRepository->method('findOneByExtensionAndType')->willReturnCallback(
            function (Extension $extension, string $sourceType) use ($persistedHolder) {
                foreach ($persistedHolder->items as $item) {
                    if ($item instanceof ExtensionSource && $item->extension === $extension && $item->sourceType === $sourceType) {
                        return $item;
                    }
                }

                return null;
            }
        );
        $sourceRepository->method('findOneByTypeAndExternalIdentifier')->willReturn(null);

        $backfillService = new EgoSourceBackfillService($entityManager, $sourceRepository, $sourceMetricRepository, new EgoSourceMapper());

        $parameterBag = new ParameterBag(['kernel.project_dir' => $this->cacheDir]);
        // Only the GitHub-facing calls need to go through $githubHttpClient
        // when a test overrides it; EGO paging above always uses $httpClient.
        $githubHttpClient ??= $httpClient;
        $apiClient = new ApiClient($githubHttpClient, new ApiCache($parameterBag));
        $imageValidator = new ImageValidator();
        $candidateLoader = new CandidateLoader($apiClient, new MetadataValidator());
        $candidateProcessor = new CandidateProcessor(
            new RepositoryEligibilityChecker(),
            $candidateLoader,
            new ReleaseSelector(),
            new ScreenshotResolver($apiClient, new ReadmeImageExtractor(), new ImageProbe($githubHttpClient, $imageValidator), $imageValidator),
            new IconResolver($apiClient, new ImageProbe($githubHttpClient, $imageValidator), $imageValidator),
        );
        $targetedRepositoryLoader = new TargetedRepositoryLoader($candidateLoader, $candidateProcessor);
        $sourcePersister = new SourcePersister($entityManager, $sourceRepository, $sourceMetricRepository, new GithubSourceMapper());

        $tokenProvider = new class($githubToken) extends TokenProvider {
            public function __construct(private readonly ?string $fakeToken)
            {
            }

            public function getToken(): ?string
            {
                return $this->fakeToken;
            }
        };

        $service = new EgoExtensionImportService(
            $entityManager,
            $sourceMetricRepository,
            $backfillService,
            new EgoExtensionMapper(),
            $httpClient,
            $tokenProvider,
            new RepositoryReferenceParser(),
            $targetedRepositoryLoader,
            $sourcePersister,
            sleepMicroseconds: 0,
        );

        return [$service, $persistedHolder, $urlHolder];
    }

    /**
     * @param object[] $items
     * @return Extension[]
     */
    private function extensionsAmong(array $items): array
    {
        return array_values(array_filter($items, static fn (object $item) => $item instanceof Extension));
    }

    public function testStopsPaginationOnFirstNonSuccessStatusAndNeverCallsLiveEgo(): void
    {
        $responses = [
            new MockResponse($this->extensionQueryPage([$this->rawExtension()]), ['http_code' => 200]),
            new MockResponse('not found', ['http_code' => 404]),
        ];
        [$service, $persistedHolder, $urlHolder] = $this->service(function () use (&$responses) {
            return array_shift($responses);
        });

        $result = $service->importAll(new DateTime());

        self::assertSame(1, $result->extensionsUpdatedCount);
        $persistedExtensions = $this->extensionsAmong($persistedHolder->items);
        self::assertCount(1, $persistedExtensions);
        self::assertSame('Plaid', $persistedExtensions[0]->name);

        self::assertCount(2, $urlHolder->items, 'Must stop requesting further pages once a page fails.');
        self::assertStringContainsString('https://extensions.gnome.org/extension-query/', $urlHolder->items[0]);
        self::assertStringContainsString('page=1', $urlHolder->items[0]);
        self::assertStringContainsString('page=2', $urlHolder->items[1]);
    }

    public function testStopsPaginationOnInvalidJson(): void
    {
        [$service, $persistedHolder] = $this->service(function () {
            return new MockResponse('not json', ['http_code' => 200]);
        });

        $result = $service->importAll(new DateTime());

        self::assertSame(0, $result->extensionsUpdatedCount);
        self::assertCount(0, $this->extensionsAmong($persistedHolder->items));
    }

    public function testUpdatesExistingExtensionInsteadOfCreatingADuplicate(): void
    {
        $existing = new Extension();
        $existing->id = 7;
        $existing->uuid = 'plaid@plyply99';
        $existing->creationDate = new DateTime('2020-01-01');

        $extensionRepository = $this->createMock(EntityRepository::class);
        $extensionRepository->method('findOneBy')->willReturn($existing);

        $responses = [
            new MockResponse($this->extensionQueryPage([$this->rawExtension()]), ['http_code' => 200]),
            new MockResponse('', ['http_code' => 404]),
        ];
        [$service, $persistedHolder] = $this->service(
            function () use (&$responses) {
                return array_shift($responses);
            },
            $extensionRepository,
        );

        $service->importAll(new DateTime());

        $persistedExtensions = $this->extensionsAmong($persistedHolder->items);
        self::assertCount(1, $persistedExtensions);
        self::assertSame($existing, $persistedExtensions[0], 'Must reuse the found extension instead of allocating a new one.');
        self::assertEquals(new DateTime('2020-01-01'), $existing->creationDate, 'Existing creation date must never be overwritten.');
    }

    public function testRecordsCurrentDownloadsMetricViaSourceMetricMeasurementWhenBackfillSucceeds(): void
    {
        $sourceMetricRepository = $this->createMock(SourceMetricMeasurementRepository::class);
        $recordedMetricTypes = [];
        $sourceMetricRepository->method('recordMeasurement')->willReturnCallback(
            function ($source, string $metricType) use (&$recordedMetricTypes): void {
                $recordedMetricTypes[] = $metricType;
            }
        );

        $responses = [
            new MockResponse($this->extensionQueryPage([$this->rawExtension()]), ['http_code' => 200]),
            new MockResponse('', ['http_code' => 404]),
        ];
        [$service] = $this->service(
            function () use (&$responses) {
                return array_shift($responses);
            },
            null,
            $sourceMetricRepository,
        );

        $service->importAll(new DateTime());

        // Real EgoSourceBackfillService::syncExtension() ran and recorded the current EGO
        // downloads metric straight into source_metric_measurement, proving EGO source
        // metrics keep working now that the legacy download-measurement path is gone.
        self::assertContains(SourceMetricMeasurement::METRIC_DOWNLOADS, $recordedMetricTypes);
    }

    public function testExtensionThatCannotBeBackfilledStillCountsAsUpdatedWithoutTouchingLegacyRepository(): void
    {
        $sourceMetricRepository = $this->createMock(SourceMetricMeasurementRepository::class);
        $sourceMetricRepository->expects(self::never())->method('recordMeasurement');

        // Missing pk fails EgoSourceMapper::validateExtensionForBackfill(), so
        // syncExtension() cannot create an ExtensionSource and returns a skip reason.
        // The dropped legacy fallback must no longer be attempted for this case
        // either — proven by this fixture's strict getRepository() callback.
        $responses = [
            new MockResponse($this->extensionQueryPage([$this->rawExtension(['pk' => null])]), ['http_code' => 200]),
            new MockResponse('', ['http_code' => 404]),
        ];
        [$service] = $this->service(
            function () use (&$responses) {
                return array_shift($responses);
            },
            null,
            $sourceMetricRepository,
        );

        $result = $service->importAll(new DateTime());

        self::assertSame(1, $result->extensionsUpdatedCount);
    }

    public function testInvokesCallbackForEachSuccessfullyProcessedExtension(): void
    {
        $responses = [
            new MockResponse($this->extensionQueryPage([
                $this->rawExtension(),
                $this->rawExtension(['uuid' => 'other@x', 'name' => 'Other', 'link' => '/extension/2/other/']),
            ]), ['http_code' => 200]),
            new MockResponse('', ['http_code' => 404]),
        ];
        [$service] = $this->service(function () use (&$responses) {
            return array_shift($responses);
        });

        $names = [];
        $service->importAll(new DateTime(), function (string $name) use (&$names) {
            $names[] = $name;
        });

        self::assertSame(['Plaid', 'Other'], $names);
    }

    public function testPurgesOldSourceMetricMeasurementsAfterImport(): void
    {
        $sourceMetricRepository = $this->createMock(SourceMetricMeasurementRepository::class);
        $sourceMetricRepository->expects(self::once())->method('purgeOlderThan')->willReturn(5);

        [$service] = $this->service(
            function () {
                return new MockResponse('', ['http_code' => 404]);
            },
            null,
            $sourceMetricRepository,
        );

        $result = $service->importAll(new DateTime());

        self::assertSame(0, $result->purgedDownloadMeasurements, 'The dropped legacy table has nothing left to purge.');
        self::assertSame(5, $result->purgedSourceMetricMeasurements);
    }

    /**
     * Backfill-only service wiring: a real EntityManager-backed EGO import
     * service whose HTTP client fails the test if ever called, since
     * backfillMissingCreationDates() is pure DB maintenance.
     */
    private function backfillOnlyService(EntityManagerInterface $entityManager): EgoExtensionImportService
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('Backfill must not perform any HTTP call.');
        });
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
                return null;
            }
        };

        return new EgoExtensionImportService(
            $entityManager,
            $this->createMock(SourceMetricMeasurementRepository::class),
            new EgoSourceBackfillService(
                $entityManager,
                $this->createMock(ExtensionSourceRepository::class),
                $this->createMock(SourceMetricMeasurementRepository::class),
                new EgoSourceMapper(),
            ),
            new EgoExtensionMapper(),
            $httpClient,
            $tokenProvider,
            new RepositoryReferenceParser(),
            new TargetedRepositoryLoader($candidateLoader, $candidateProcessor),
            new SourcePersister(
                $entityManager,
                $this->createMock(ExtensionSourceRepository::class),
                $this->createMock(SourceMetricMeasurementRepository::class),
                new GithubSourceMapper(),
            ),
        );
    }

    public function testBackfillMissingCreationDatesUpdatesRowsMissingOrZeroDate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 1, 'first_version_pk' => 19642],
            ['id' => 2, 'first_version_pk' => null],
        ]);
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturn(1);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $updated = $this->backfillOnlyService($entityManager)->backfillMissingCreationDates();

        self::assertSame(2, $updated);
    }

    /**
     * Same preallocated-PK overshoot as EgoExtensionMapper, reached via the
     * raw-SQL backfill path instead: it must reuse Extension::nonFutureDate()
     * rather than persist the future estimate (or a fabricated "now").
     */
    public function testBackfillMissingCreationDatesNeverPersistsAFutureEstimate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 1, 'first_version_pk' => 999999999],
        ]);
        $persistedCreationDate = null;
        $connection->expects(self::once())->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params) use (&$persistedCreationDate) {
                $persistedCreationDate = $params['creationDate'];

                return 1;
            }
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $this->backfillOnlyService($entityManager)->backfillMissingCreationDates();

        $epochInServerTimezone = (new DateTime())->setTimestamp(0)->format('Y-m-d H:i:s');
        self::assertSame($epochInServerTimezone, $persistedCreationDate);
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

    private function metadataContentResponse(string $uuid): JsonMockResponse
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

    /**
     * @return ExtensionSource[]
     */
    private function githubSourcesAmong(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (object $item) => $item instanceof ExtensionSource && $item->sourceType === ExtensionSource::TYPE_GITHUB,
        ));
    }

    public function testAttachesGithubSourceAndPreservesEgoLinkWhenHomepageUuidMatches(): void
    {
        $responses = [
            new MockResponse($this->extensionQueryPage([
                $this->rawExtension(['url' => 'https://github.com/owner/repo']),
            ]), ['http_code' => 200]),
            $this->repositoryResponse(),
            $this->metadataContentResponse('plaid@plyply99'),
            new JsonMockResponse([]), // releases
            $this->notFoundResponse(), // screenshot: head-commit lookup fails -> no screenshot, no README call
            $this->notFoundResponse(), // icon: head-commit lookup fails -> no icon, no root-listing call
            new MockResponse('', ['http_code' => 404]), // stop EGO pagination
        ];
        [$service, $persistedHolder] = $this->service(
            function () use (&$responses) {
                return array_shift($responses);
            },
            githubToken: 'token',
        );

        $result = $service->importAll(new DateTime());

        self::assertSame(1, $result->extensionsUpdatedCount, 'The GitHub check must never abort the EGO import.');

        $extensions = $this->extensionsAmong($persistedHolder->items);
        self::assertCount(1, $extensions, 'A matching UUID must attach to the same Extension, never create a second one.');
        self::assertSame(
            '/extension/12345/plaid/',
            $extensions[0]->link,
            'EGO stays the owner of the canonical link once its source exists; the GitHub attach must not touch it.'
        );

        $githubSources = $this->githubSourcesAmong($persistedHolder->items);
        self::assertCount(1, $githubSources);
        self::assertSame($extensions[0], $githubSources[0]->extension);
    }

    public function testMissingGithubTokenSkipsWithoutAnyGithubApiCall(): void
    {
        $responses = [
            new MockResponse($this->extensionQueryPage([
                $this->rawExtension(['url' => 'https://github.com/owner/repo']),
            ]), ['http_code' => 200]),
            new MockResponse('', ['http_code' => 404]),
        ];
        [$service, $persistedHolder, $urlHolder] = $this->service(
            function () use (&$responses) {
                return array_shift($responses);
            },
            githubToken: null,
        );

        $result = $service->importAll(new DateTime());

        self::assertSame(1, $result->extensionsUpdatedCount);
        foreach ($urlHolder->items as $url) {
            self::assertStringNotContainsString('api.github.com', $url, 'No GitHub API call may happen without a token.');
        }
        self::assertCount(0, $this->githubSourcesAmong($persistedHolder->items));
    }

    public function testDisallowedHomepageUrlIsSkippedWithoutAnyGithubApiCall(): void
    {
        $responses = [
            new MockResponse($this->extensionQueryPage([
                // A repository sub-path (not the bare owner/repo shape) must be rejected.
                $this->rawExtension(['url' => 'https://github.com/owner/repo/issues']),
            ]), ['http_code' => 200]),
            new MockResponse('', ['http_code' => 404]),
        ];
        [$service, , $urlHolder] = $this->service(
            function () use (&$responses) {
                return array_shift($responses);
            },
            githubToken: 'token',
        );

        $result = $service->importAll(new DateTime());

        self::assertSame(1, $result->extensionsUpdatedCount);
        foreach ($urlHolder->items as $url) {
            self::assertStringNotContainsString('api.github.com', $url);
        }
    }

    public function testUuidMismatchSkipsWithoutCreatingSourceOrGithubOnlyExtension(): void
    {
        $responses = [
            new MockResponse($this->extensionQueryPage([
                $this->rawExtension(['url' => 'https://github.com/owner/repo']),
            ]), ['http_code' => 200]),
            $this->repositoryResponse(),
            $this->metadataContentResponse('other@elsewhere'), // deviates from the EGO extension's uuid
            new JsonMockResponse([]),
            $this->notFoundResponse(),
            $this->notFoundResponse(),
            new MockResponse('', ['http_code' => 404]),
        ];
        [$service, $persistedHolder] = $this->service(
            function () use (&$responses) {
                return array_shift($responses);
            },
            githubToken: 'token',
        );

        $result = $service->importAll(new DateTime());

        self::assertSame(1, $result->extensionsUpdatedCount);
        self::assertCount(
            1,
            $this->extensionsAmong($persistedHolder->items),
            'A UUID mismatch must not spawn a second, GitHub-only Extension.'
        );
        self::assertCount(0, $this->githubSourcesAmong($persistedHolder->items));
    }

    public function testGithubRateLimitDuringHomepageCheckDoesNotAbortEgoImport(): void
    {
        $responses = [
            new MockResponse($this->extensionQueryPage([
                $this->rawExtension(['url' => 'https://github.com/owner/repo']),
            ]), ['http_code' => 200]),
            new MockResponse('{"message":"API rate limit exceeded"}', [
                'http_code' => 403,
                'response_headers' => ['X-RateLimit-Remaining' => '0'],
            ]),
            new MockResponse('', ['http_code' => 404]),
        ];
        [$service] = $this->service(
            function () use (&$responses) {
                return array_shift($responses);
            },
            githubToken: 'token',
        );

        $result = $service->importAll(new DateTime());

        self::assertSame(1, $result->extensionsUpdatedCount, 'A rate-limited GitHub lookup must not abort the EGO import.');
    }

    /**
     * A minimal HttpClientInterface whose responses throw a raw Symfony
     * HTTP-client exception from __destruct() — reproducing the live
     * incident where ApiClient::get() already turned a 404 into our own
     * ApiException, yet the underlying Symfony response object still threw
     * its own exception once it went out of scope, escaping past every
     * catch(ApiException) in the GitHub call chain.
     */
    private function githubHttpClientThrowingOnResponseCleanup(): HttpClientInterface
    {
        return new class implements HttpClientInterface {
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return new class implements ResponseInterface {
                    public function getStatusCode(): int
                    {
                        return 404;
                    }

                    public function getHeaders(bool $throw = true): array
                    {
                        return [];
                    }

                    public function getContent(bool $throw = true): string
                    {
                        return '';
                    }

                    public function toArray(bool $throw = true): array
                    {
                        return [];
                    }

                    public function cancel(): void
                    {
                    }

                    public function getInfo(?string $type = null): mixed
                    {
                        return null;
                    }

                    public function __destruct()
                    {
                        throw new TransportException(
                            'Simulated raw Symfony HTTP client exception on response cleanup.'
                        );
                    }
                };
            }

            public function stream($responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \LogicException('Not used by this test.');
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        };
    }

    public function testRawSymfonyHttpClientExceptionDuringHomepageCheckDoesNotAbortEgoImport(): void
    {
        $responses = [
            new MockResponse($this->extensionQueryPage([
                $this->rawExtension(['url' => 'https://github.com/owner/repo']),
            ]), ['http_code' => 200]),
            new MockResponse('', ['http_code' => 404]), // stop EGO pagination
        ];
        [$service] = $this->service(
            function () use (&$responses) {
                return array_shift($responses);
            },
            githubToken: 'token',
            githubHttpClient: $this->githubHttpClientThrowingOnResponseCleanup(),
        );

        $result = $service->importAll(new DateTime());

        self::assertSame(
            1,
            $result->extensionsUpdatedCount,
            'A raw Symfony HTTP client exception from the targeted GitHub path must not abort the EGO import.'
        );
    }
}
