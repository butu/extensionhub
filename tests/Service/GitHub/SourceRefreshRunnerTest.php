<?php

namespace App\Tests\Service\GitHub;

use App\Entity\Extension;
use App\Entity\ExtensionSource;
use App\Entity\SourceMetricMeasurement;
use App\Repository\ExtensionSourceRepository;
use App\Repository\SourceMetricMeasurementRepository;
use App\Service\GitHub\ApiCache;
use App\Service\GitHub\ApiClient;
use App\Service\GitHub\ApiException;
use App\Service\GitHub\CandidateLoader;
use App\Service\GitHub\CandidateProcessor;
use App\Service\GitHub\IconResolver;
use App\Service\GitHub\ImageProbe;
use App\Service\GitHub\ImageValidator;
use App\Service\GitHub\MetadataValidator;
use App\Service\GitHub\ReadmeImageExtractor;
use App\Service\GitHub\RepositoryEligibilityChecker;
use App\Service\GitHub\ReleaseSelector;
use App\Service\GitHub\ScreenshotResolver;
use App\Service\GitHub\SourceMapper;
use App\Service\GitHub\SourcePersister;
use App\Service\GitHub\SourceRefreshRunner;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Refresh flow for already-known GitHub sources, tested exclusively against
 * a MockHttpClient and the real SourcePersister (with mocked Doctrine
 * collaborators): no live GitHub call and no database connection are used.
 */
class SourceRefreshRunnerTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/github-refresh-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            (new Filesystem())->remove($this->cacheDir);
        }
    }

    private function apiClient(MockHttpClient $httpClient): ApiClient
    {
        $parameterBag = new ParameterBag(['kernel.project_dir' => $this->cacheDir]);

        return new ApiClient($httpClient, new ApiCache($parameterBag));
    }

    /**
     * @return array{0: SourcePersister, 1: object{items: object[]}}
     */
    private function makePersister(?array &$measuredMetrics = null): array
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $sourceRepository = $this->createMock(ExtensionSourceRepository::class);
        $metricRepository = $this->createMock(SourceMetricMeasurementRepository::class);

        $extensionRepository = $this->createMock(EntityRepository::class);
        $extensionRepository->method('findOneBy')->willReturn(null);
        $entityManager->method('getRepository')->with(Extension::class)->willReturn($extensionRepository);

        $sourceRepository->method('findOneByTypeAndExternalIdentifier')->willReturn(null);

        $persistedHolder = new class {
            /** @var object[] */
            public array $items = [];
        };
        $entityManager->method('persist')->willReturnCallback(function (object $entity) use ($persistedHolder): void {
            $persistedHolder->items[] = $entity;
        });

        $metricRepository->method('recordMeasurement')->willReturnCallback(
            function ($source, string $metricType, float $value) use (&$measuredMetrics): void {
                if (is_array($measuredMetrics)) {
                    $measuredMetrics[$metricType] = $value;
                }
            },
        );

        $persister = new SourcePersister($entityManager, $sourceRepository, $metricRepository, new SourceMapper());

        return [$persister, $persistedHolder];
    }

    /**
     * @param ExtensionSource[] $sources
     */
    private function runner(MockHttpClient $httpClient, SourcePersister $persister, array $sources): SourceRefreshRunner
    {
        $apiClient = $this->apiClient($httpClient);

        $sourceRepository = $this->createMock(ExtensionSourceRepository::class);
        $sourceRepository->method('findAllGithubSourcesForRefresh')->willReturn($sources);

        $imageValidator = new ImageValidator();
        $candidateLoader = new CandidateLoader($apiClient, new MetadataValidator());

        $candidateProcessor = new CandidateProcessor(
            new RepositoryEligibilityChecker(),
            $candidateLoader,
            new ReleaseSelector(),
            new ScreenshotResolver(
                $apiClient,
                new ReadmeImageExtractor(),
                new ImageProbe($httpClient, $imageValidator),
                $imageValidator,
            ),
            new IconResolver($apiClient, new ImageProbe($httpClient, $imageValidator), $imageValidator),
        );

        return new SourceRefreshRunner($sourceRepository, $candidateLoader, $candidateProcessor, $persister, $apiClient);
    }

    /** Head-commit response the screenshot resolver asks for first. */
    private function commitsResponse(string $sha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'): JsonMockResponse
    {
        return new JsonMockResponse([['sha' => $sha]]);
    }

    private function readmeResponse(string $markdown, string $path = 'README.md'): JsonMockResponse
    {
        return new JsonMockResponse([
            'name' => basename($path),
            'path' => $path,
            'type' => 'file',
            'encoding' => 'base64',
            'content' => base64_encode($markdown),
        ]);
    }

    /**
     * The two responses a screenshot lookup needs when the repository has no
     * README at all, i.e. "no screenshot" without any image probing.
     *
     * @return MockResponse[]
     */
    private function noScreenshotResponses(): array
    {
        return [$this->commitsResponse(), $this->notFoundResponse()];
    }

    /**
     * The two responses an icon lookup needs when the repository root has
     * no matching file and no known layout subdirectory, i.e. "no icon"
     * without any image probing.
     *
     * @return MockResponse[]
     */
    private function noIconResponses(): array
    {
        return [$this->commitsResponse(), new JsonMockResponse([])];
    }

    private function knownSource(string $externalIdentifier = '1'): ExtensionSource
    {
        $source = new ExtensionSource();
        $source->sourceType = ExtensionSource::TYPE_GITHUB;
        $source->externalIdentifier = $externalIdentifier;

        return $source;
    }

    private function repositoryResponse(array $overrides = []): JsonMockResponse
    {
        return new JsonMockResponse($this->repositoryData($overrides));
    }

    private function metadataContentResponse(
        string $uuid = 'repo@owner',
        array $shellVersion = ['45'],
        ?string $name = null,
        ?string $description = null,
    ): JsonMockResponse {
        $metadata = ['uuid' => $uuid, 'shell-version' => $shellVersion];
        if ($name !== null) {
            $metadata['name'] = $name;
        }
        if ($description !== null) {
            $metadata['description'] = $description;
        }

        $json = json_encode($metadata, JSON_THROW_ON_ERROR);

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
     * @param array<int, array<string, mixed>> $releases
     */
    private function releasesResponse(array $releases): JsonMockResponse
    {
        return new JsonMockResponse($releases);
    }

    private function zipRelease(string $assetName = 'repo.zip'): array
    {
        return [
            'tag_name' => 'v1.0.0',
            'draft' => false,
            'prerelease' => false,
            'published_at' => '2026-02-01T00:00:00Z',
            'assets' => [
                ['name' => $assetName, 'browser_download_url' => 'https://github.com/owner/repo/releases/download/v1.0.0/' . $assetName],
            ],
        ];
    }

    public function testRefreshesAKnownSourceViaRepositoryByIdMetadataAndReleases(): void
    {
        $httpClient = new MockHttpClient([
            $this->repositoryResponse(),
            $this->metadataContentResponse(),
            $this->releasesResponse([$this->zipRelease()]),
            ...$this->noScreenshotResponses(),
            ...$this->noIconResponses(),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister, [$this->knownSource('1')]);
        $result = $runner->refresh('token');

        self::assertSame(1, $result->knownSourceCount);
        self::assertSame(1, $result->refreshedCount);
        self::assertSame(0, $result->skippedCount);
        self::assertCount(2, $persistedHolder->items);
        self::assertInstanceOf(Extension::class, $persistedHolder->items[0]);
        self::assertInstanceOf(ExtensionSource::class, $persistedHolder->items[1]);
        self::assertSame(
            'https://github.com/owner/repo/releases/download/v1.0.0/repo.zip',
            $persistedHolder->items[1]->installUrl,
        );
    }

    public function testRefreshPassesMetadataNameDescriptionAndRepositoryCreationDateOn(): void
    {
        $httpClient = new MockHttpClient([
            $this->repositoryResponse([
                'full_name' => 'ryohsuke1231/liquid-glass',
                'created_at' => '2026-04-08T20:53:45Z',
                'pushed_at' => '2026-08-16T04:39:52Z',
            ]),
            $this->metadataContentResponse(
                uuid: 'liquid-glass@thinkingcoding1231.gmail.com',
                shellVersion: ['49', '50'],
                name: 'Liquid Glass',
                description: 'Applies a translucent, refractive effect.',
            ),
            $this->releasesResponse([]),
            ...$this->noScreenshotResponses(),
            ...$this->noIconResponses(),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister, [$this->knownSource('1')]);
        $result = $runner->refresh('token');

        self::assertSame(1, $result->refreshedCount);

        $extension = $persistedHolder->items[0];
        $source = $persistedHolder->items[1];
        self::assertInstanceOf(Extension::class, $extension);
        self::assertInstanceOf(ExtensionSource::class, $source);

        self::assertSame('Liquid Glass', $extension->name);
        self::assertSame('Applies a translucent, refractive effect.', $extension->description);
        self::assertSame('Liquid Glass', $source->displayName);
        self::assertSame('2026-04-08', $extension->creationDate->format('Y-m-d'));
    }

    /**
     * The stored screenshot/icon are both pinned to a commit SHA. An
     * unchanged head commit therefore needs no README read, no directory
     * listing, and no image probe at all — only the two commit lookups
     * (one per resolver) are queued here, so any further request would
     * make MockHttpClient fail.
     */
    public function testUnchangedRepositoryKeepsItsScreenshotWithoutProbingImages(): void
    {
        $sha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
        $existingScreenshot = 'https://raw.githubusercontent.com/owner/repo/' . $sha . '/assets/demo2.png';
        $existingIcon = 'https://raw.githubusercontent.com/owner/repo/' . $sha . '/logo.svg';

        $source = $this->knownSource('1');
        $source->displayScreenshot = $existingScreenshot;
        $source->displayIcon = $existingIcon;

        $httpClient = new MockHttpClient([
            $this->repositoryResponse(),
            $this->metadataContentResponse(),
            $this->releasesResponse([]),
            $this->commitsResponse($sha),
            $this->commitsResponse($sha),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $result = $this->runner($httpClient, $persister, [$source])->refresh('token');

        self::assertSame(1, $result->refreshedCount);
        self::assertSame($existingScreenshot, $persistedHolder->items[1]->displayScreenshot);
        self::assertSame($existingIcon, $persistedHolder->items[1]->displayIcon);
    }

    /**
     * A source whose stored lastCommitAt still matches the freshly reported
     * pushed_at is refreshed from that single repository call alone: no
     * metadata, release, screenshot, or icon request happens (any extra
     * request would exhaust the mock queue and fail), while stars and forks
     * are re-measured from the fresh response.
     */
    public function testUnchangedSourceSkipsTheDeepFetchAndStillMeasuresFreshStars(): void
    {
        $source = $this->knownSource('1');
        $source->extension = new Extension();
        $source->extension->uuid = 'repo@owner';
        $source->displayName = 'Stored Name';
        $source->displayDescription = 'Stored description';
        $source->installUrl = 'https://github.com/owner/repo/releases/download/v1.0.0/repo.zip';
        $source->supportedShellVersions = ['45'];
        $source->lastReleaseAt = new \DateTime('2026-02-01T00:00:00Z');
        $source->lastCommitAt = new \DateTime('2026-01-01T00:00:00Z');

        $httpClient = new MockHttpClient([
            $this->repositoryResponse(['stargazers_count' => 42, 'forks_count' => 7]),
        ]);

        $metrics = [];
        [$persister, $persistedHolder] = $this->makePersister($metrics);
        $result = $this->runner($httpClient, $persister, [$source])->refresh('token');

        self::assertSame(1, $result->refreshedCount);
        self::assertSame(0, $result->skippedCount);
        self::assertFalse($result->stoppedForLowRateLimit);
        self::assertSame(1, $httpClient->getRequestsCount());

        /** @var ExtensionSource $persistedSource */
        $persistedSource = $persistedHolder->items[1];
        self::assertInstanceOf(ExtensionSource::class, $persistedSource);
        self::assertSame('Stored Name', $persistedSource->displayName);
        self::assertSame(
            'https://github.com/owner/repo/releases/download/v1.0.0/repo.zip',
            $persistedSource->installUrl,
        );
        self::assertSame(
            (new \DateTime('2026-01-01T00:00:00Z'))->getTimestamp(),
            $persistedSource->lastCommitAt?->getTimestamp(),
        );

        self::assertSame([
            SourceMetricMeasurement::METRIC_STARS => 42.0,
            SourceMetricMeasurement::METRIC_FORKS => 7.0,
        ], $metrics);
    }

    /**
     * A pushed_at newer than the stored lastCommitAt forces the full flow:
     * the deep fetch queue must be consumed completely.
     */
    public function testSourceWithNewerPushedAtRunsTheFullDeepFlowAgain(): void
    {
        $source = $this->knownSource('1');
        $source->extension = new Extension();
        $source->extension->uuid = 'repo@owner';
        $source->displayName = 'Stored Name';
        $source->installUrl = 'https://github.com/owner/repo/releases/download/v0.9.0/old.zip';
        $source->lastCommitAt = new \DateTime('2025-12-31T00:00:00Z');

        $httpClient = new MockHttpClient([
            $this->repositoryResponse(),
            $this->metadataContentResponse(name: 'Fresh Metadata Name'),
            $this->releasesResponse([$this->zipRelease()]),
            ...$this->noScreenshotResponses(),
            ...$this->noIconResponses(),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $result = $this->runner($httpClient, $persister, [$source])->refresh('token');

        self::assertSame(1, $result->refreshedCount);
        self::assertGreaterThanOrEqual(6, $httpClient->getRequestsCount());
        /** @var ExtensionSource $persistedSource */
        $persistedSource = $persistedHolder->items[1];
        self::assertInstanceOf(ExtensionSource::class, $persistedSource);
        self::assertSame('Fresh Metadata Name', $persistedSource->displayName);
        self::assertSame(
            'https://github.com/owner/repo/releases/download/v1.0.0/repo.zip',
            $persistedSource->installUrl,
        );
    }

    /**
     * The cheap path needs a canonical extension uuid; a source without one
     * (and a matching timestamp) falls back to the full loading flow.
     */
    public function testUnchangedSourceWithoutUuidFallsBackToTheFullFlow(): void
    {
        $source = $this->knownSource('1');
        $source->displayName = 'Stored Name';
        $source->lastCommitAt = new \DateTime('2026-01-01T00:00:00Z');

        $httpClient = new MockHttpClient([
            $this->repositoryResponse(),
            $this->metadataContentResponse(uuid: 'fresh@owner'),
            $this->releasesResponse([]),
            ...$this->noScreenshotResponses(),
            ...$this->noIconResponses(),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $result = $this->runner($httpClient, $persister, [$source])->refresh('token');

        self::assertSame(1, $result->refreshedCount);
        /** @var ExtensionSource $persistedSource */
        $persistedSource = $persistedHolder->items[1];
        self::assertInstanceOf(ExtensionSource::class, $persistedSource);
        self::assertSame('fresh@owner', $persistedHolder->items[0]->uuid);
    }

    /**
     * Once GitHub reports fewer remaining requests than the reserve, the
     * loop stops before the next source instead of running into the hard
     * 403 abort: the second source is never requested.
     */
    public function testRunStopsEarlyWhenTheRateLimitReserveIsHit(): void
    {
        $sources = [$this->unchangedCheapSource(), $this->unchangedCheapSource()];

        $httpClient = new MockHttpClient([
            new JsonMockResponse($this->repositoryData(), ['response_headers' => [
                'x-ratelimit-remaining' => '50',
            ]]),
        ]);

        [$persister] = $this->makePersister();
        $result = $this->runner($httpClient, $persister, $sources)->refresh('token');

        self::assertSame(2, $result->knownSourceCount);
        self::assertSame(1, $result->refreshedCount);
        self::assertSame(0, $result->skippedCount);
        self::assertTrue($result->stoppedForLowRateLimit);
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    private function unchangedCheapSource(): ExtensionSource
    {
        $source = $this->knownSource('1');
        $source->extension = new Extension();
        $source->extension->uuid = 'repo@owner';
        $source->lastCommitAt = new \DateTime('2026-01-01T00:00:00Z');

        return $source;
    }

    private function repositoryData(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'full_name' => 'owner/repo',
            'html_url' => 'https://github.com/owner/repo',
            'description' => 'A gnome shell extension',
            'stargazers_count' => 10,
            'forks_count' => 2,
            'archived' => false,
            'private' => false,
            'pushed_at' => '2026-01-01T00:00:00Z',
            'owner' => ['login' => 'owner', 'html_url' => 'https://github.com/owner'],
        ], $overrides);
    }

    public function testTransientReadmeFailureDoesNotDeleteAStoredScreenshot(): void
    {
        $existing = 'https://raw.githubusercontent.com/owner/repo/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/assets/demo2.png';

        $source = $this->knownSource('1');
        $source->displayScreenshot = $existing;

        $httpClient = new MockHttpClient([
            $this->repositoryResponse(),
            $this->metadataContentResponse(),
            $this->releasesResponse([]),
            $this->commitsResponse('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),
            new MockResponse('{"message":"Internal Server Error"}', ['http_code' => 500]),
            ...$this->noIconResponses(),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $result = $this->runner($httpClient, $persister, [$source])->refresh('token');

        self::assertSame(1, $result->refreshedCount);
        self::assertSame($existing, $persistedHolder->items[1]->displayScreenshot);
    }

    private function pngBytes(int $width = 64, int $height = 64): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * @param array<int, array{name: string, type: string}> $items
     */
    private function directoryListingResponse(array $items): JsonMockResponse
    {
        return new JsonMockResponse($items);
    }

    /**
     * The HEAD + GET pair the probe performs for one raster candidate.
     *
     * @return MockResponse[]
     */
    private function rasterImageResponses(string $bytes, string $contentType = 'image/png'): array
    {
        return [
            new MockResponse('', ['response_headers' => [
                'content-type' => $contentType,
                'content-length' => (string) strlen($bytes),
            ]]),
            new MockResponse($bytes),
        ];
    }

    /**
     * End-to-end guard that a repository-root icon actually reaches the
     * refreshed source: a new head commit invalidates the old (differently
     * pinned) stored icon, the icon resolver finds "icon.png" at the
     * repository root, and the resulting raw URL (pinned to the new head
     * commit SHA) replaces the stale one on ExtensionSource::$displayIcon.
     */
    public function testRefreshDiscoversANewIconWhenTheStoredOneIsStale(): void
    {
        $oldSha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $newSha = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $png = $this->pngBytes();

        $source = $this->knownSource('1');
        $source->displayIcon = 'https://raw.githubusercontent.com/owner/repo/' . $oldSha . '/icon.png';

        $httpClient = new MockHttpClient([
            $this->repositoryResponse(),
            $this->metadataContentResponse(),
            $this->releasesResponse([]),
            ...$this->noScreenshotResponses(),
            $this->commitsResponse($newSha),
            $this->directoryListingResponse([
                ['name' => 'README.md', 'type' => 'file'],
                ['name' => 'icon.png', 'type' => 'file'],
            ]),
            ...$this->rasterImageResponses($png),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $result = $this->runner($httpClient, $persister, [$source])->refresh('token');

        self::assertSame(1, $result->refreshedCount);
        self::assertSame(
            'https://raw.githubusercontent.com/owner/repo/' . $newSha . '/icon.png',
            $persistedHolder->items[1]->displayIcon,
        );
    }

    /**
     * A temporary API failure while listing the repository root must not
     * silently delete an icon that is already known to be good, mirroring
     * the equivalent screenshot guarantee.
     */
    public function testTransientRootListingFailureDoesNotDeleteAStoredIcon(): void
    {
        $existing = 'https://raw.githubusercontent.com/owner/repo/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/icon.png';

        $source = $this->knownSource('1');
        $source->displayIcon = $existing;

        $httpClient = new MockHttpClient([
            $this->repositoryResponse(),
            $this->metadataContentResponse(),
            $this->releasesResponse([]),
            ...$this->noScreenshotResponses(),
            $this->commitsResponse('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),
            new MockResponse('{"message":"Internal Server Error"}', ['http_code' => 500]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $result = $this->runner($httpClient, $persister, [$source])->refresh('token');

        self::assertSame(1, $result->refreshedCount);
        self::assertSame($existing, $persistedHolder->items[1]->displayIcon);
    }

    public function testRepositoryNoLongerFoundIsSkippedWithoutPersisting(): void
    {
        $httpClient = new MockHttpClient([$this->notFoundResponse()]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister, [$this->knownSource('1')]);
        $result = $runner->refresh('token');

        self::assertSame(1, $result->knownSourceCount);
        self::assertSame(0, $result->refreshedCount);
        self::assertSame(1, $result->skippedCount);
        self::assertSame(1, $result->skipReasonCounts[SourceRefreshRunner::SKIP_REPOSITORY_NOT_FOUND]);
        self::assertSame([], $persistedHolder->items);
    }

    public function testRepositoryThatBecamePrivateIsSkippedWithoutPersisting(): void
    {
        $httpClient = new MockHttpClient([$this->repositoryResponse(['private' => true])]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister, [$this->knownSource('1')]);
        $result = $runner->refresh('token');

        self::assertSame(0, $result->refreshedCount);
        self::assertSame(1, $result->skipReasonCounts['private_repository']);
        self::assertSame([], $persistedHolder->items);
    }

    /**
     * The default global path (refresh, same as Discovery) never bypasses
     * the star minimum; only TargetedRepositoryLoader does.
     */
    public function testZeroStarRepositoryIsSkippedByTheDefaultGlobalPath(): void
    {
        $httpClient = new MockHttpClient([$this->repositoryResponse(['stargazers_count' => 0])]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister, [$this->knownSource('1')]);
        $result = $runner->refresh('token');

        self::assertSame(0, $result->refreshedCount);
        self::assertSame(1, $result->skipReasonCounts['insufficient_stars']);
        self::assertSame([], $persistedHolder->items);
    }

    public function testInvalidMetadataIsSkippedWithoutPersisting(): void
    {
        $httpClient = new MockHttpClient([
            $this->repositoryResponse(),
            $this->notFoundResponse(), // metadata.json
            $this->notFoundResponse(), // extensions/metadata.json
            $this->notFoundResponse(), // src/metadata.json
            $this->notFoundResponse(), // extension/metadata.json
            $this->notFoundResponse(), // resources/metadata.json
            new JsonMockResponse([['name' => 'README.md', 'type' => 'file']]), // root listing, no uuid-like dir
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister, [$this->knownSource('1')]);
        $result = $runner->refresh('token');

        self::assertSame(0, $result->refreshedCount);
        self::assertSame(1, $result->skipReasonCounts['metadata_not_found']);
        self::assertSame([], $persistedHolder->items);
    }

    /**
     * boerdereinar/copyous ships metadata.json under resources/, a real
     * repository layout GitHub's tree confirms. This end-to-end refresh
     * proves CandidateLoader actually requests that fixed path (not just
     * that MetadataValidator accepts it in isolation).
     */
    public function testRefreshFindsMetadataJsonUnderResourcesDirectory(): void
    {
        $httpClient = new MockHttpClient([
            $this->repositoryResponse(),
            $this->notFoundResponse(), // metadata.json
            $this->notFoundResponse(), // extensions/metadata.json
            $this->notFoundResponse(), // src/metadata.json
            $this->notFoundResponse(), // extension/metadata.json
            $this->metadataContentResponse(), // resources/metadata.json
            $this->releasesResponse([]),
            ...$this->noScreenshotResponses(),
            ...$this->noIconResponses(),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister, [$this->knownSource('1')]);
        $result = $runner->refresh('token');

        self::assertSame(1, $result->refreshedCount);
        self::assertSame(0, $result->skippedCount);
    }

    public function testInvalidExternalIdentifierIsSkippedWithoutAnyHttpCall(): void
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('No HTTP call should be made for an invalid external identifier.');
        });

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister, [$this->knownSource('not-a-number')]);
        $result = $runner->refresh('token');

        self::assertSame(1, $result->knownSourceCount);
        self::assertSame(0, $result->refreshedCount);
        self::assertSame(1, $result->skipReasonCounts[SourceRefreshRunner::SKIP_INVALID_EXTERNAL_IDENTIFIER]);
        self::assertSame([], $persistedHolder->items);
    }

    public function testAbortsRunOnRateLimitDuringRepositoryLoadWithoutPersistingAnySource(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"message":"API rate limit exceeded"}', [
                'http_code' => 403,
                'response_headers' => ['X-RateLimit-Remaining' => '0'],
            ]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        // Two known sources queued, but only one HTTP response is provided:
        // if the runner incorrectly kept going to the second source,
        // MockHttpClient would fail with "no more responses left" instead
        // of the expected ApiException.
        $runner = $this->runner($httpClient, $persister, [$this->knownSource('1'), $this->knownSource('2')]);

        try {
            $runner->refresh('token');
            self::fail('Expected refresh() to abort with a rate-limited ApiException.');
        } catch (ApiException $exception) {
            self::assertTrue($exception->isRateLimited());
        }

        self::assertSame([], $persistedHolder->items, 'No source may be persisted once the run is rate-limited.');
    }

    public function testGitHubApiFailureWhileRefreshingOneSourceIsSkippedWithoutAbortingTheRun(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"message":"Internal Server Error"}', ['http_code' => 500]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister, [$this->knownSource('1')]);
        $result = $runner->refresh('token');

        self::assertSame(0, $result->refreshedCount);
        self::assertSame(1, $result->skipReasonCounts[SourceRefreshRunner::SKIP_CANDIDATE_LOAD_FAILED]);
        self::assertSame([], $persistedHolder->items);
    }
}
