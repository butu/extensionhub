<?php

namespace App\Tests\Service\GitHub;

use App\Entity\Extension;
use App\Entity\ExtensionSource;
use App\Repository\ExtensionSourceRepository;
use App\Repository\SourceMetricMeasurementRepository;
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
use App\Service\GitHub\ReleaseSelector;
use App\Service\GitHub\ScreenshotResolver;
use App\Service\GitHub\SourceMapper;
use App\Service\GitHub\SourcePersister;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Full discovery flow, tested exclusively against a MockHttpClient and the
 * real SourcePersister (with mocked Doctrine collaborators): no live
 * GitHub call and no database connection are used by this test.
 */
class DiscoveryRunnerTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/github-discovery-test-' . uniqid();
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
     * Builds a real SourcePersister backed by mocked Doctrine
     * collaborators (no database), returning it together with a mutable
     * holder collecting every entity handed to EntityManager::persist(),
     * in call order.
     *
     * @return array{0: SourcePersister, 1: object{items: object[]}}
     */
    private function makePersister(): array
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

        $persister = new SourcePersister($entityManager, $sourceRepository, $metricRepository, new SourceMapper());

        return [$persister, $persistedHolder];
    }

    private function runner(MockHttpClient $httpClient, SourcePersister $persister, int $maxPages = 10): DiscoveryRunner
    {
        $apiClient = $this->apiClient($httpClient);

        $candidateProcessor = new CandidateProcessor(
            new RepositoryEligibilityChecker(),
            new CandidateLoader($apiClient, new MetadataValidator()),
            new ReleaseSelector(),
            $this->screenshotResolver($httpClient, $apiClient),
            $this->iconResolver($httpClient, $apiClient),
        );

        return new DiscoveryRunner($apiClient, $candidateProcessor, $persister, $maxPages);
    }

    /**
     * Real screenshot resolver on the same MockHttpClient, so screenshot
     * lookups consume queued responses exactly like the live flow.
     */
    private function screenshotResolver(MockHttpClient $httpClient, ApiClient $apiClient): ScreenshotResolver
    {
        $validator = new ImageValidator();

        return new ScreenshotResolver(
            $apiClient,
            new ReadmeImageExtractor(),
            new ImageProbe($httpClient, $validator),
            $validator,
        );
    }

    /**
     * Real icon resolver on the same MockHttpClient, so icon lookups
     * consume queued responses exactly like the live flow.
     */
    private function iconResolver(MockHttpClient $httpClient, ApiClient $apiClient): IconResolver
    {
        $validator = new ImageValidator();

        return new IconResolver($apiClient, new ImageProbe($httpClient, $validator), $validator);
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

    /**
     * @param array<int, array<string, mixed>> $ids
     */
    private function itemsResponse(array $ids): JsonMockResponse
    {
        return new JsonMockResponse(['items' => array_map(static fn (int $id) => ['id' => $id], $ids)]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function searchItem(array $overrides = []): array
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

    private function searchResponse(array $items): JsonMockResponse
    {
        return new JsonMockResponse(['items' => $items]);
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

    /**
     * @return array<string, mixed>
     */
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

    public function testRunsBothQueriesAndDeduplicatesByRepositoryId(): void
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse(['items' => [['id' => 1], ['id' => 2]]]),
            new JsonMockResponse(['items' => [['id' => 2], ['id' => 3]]]),
        ]);

        [$persister] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);
        $result = $runner->discover('token');

        self::assertCount(2, $result->hitCountByQuery);
        self::assertSame(3, $result->uniqueRepositoryCount);
    }

    public function testReportsZeroCandidatesWhenBothQueriesReturnNoHits(): void
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse(['items' => []]),
            new JsonMockResponse(['items' => []]),
        ]);

        [$persister] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);
        $result = $runner->discover('token');

        self::assertSame(0, $result->uniqueRepositoryCount);
        self::assertSame(0, $result->persistedCount);
        self::assertSame(0, $result->skippedCount);
    }

    public function testPaginatesUntilAShortPageAndDeduplicatesAcrossPages(): void
    {
        $queries = DiscoveryRunner::SEARCH_QUERIES;

        // Query 1: page 1 is a full page (100 items) so pagination continues;
        // page 2 is short (3 items, overlapping with page 1) so it stops there.
        // Query 2: the very first page is empty, so it stops after one page.
        $httpClient = new MockHttpClient([
            $this->itemsResponse(range(1, 100)),
            $this->itemsResponse([95, 96, 97]),
            $this->itemsResponse([]),
        ]);

        [$persister] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);
        $result = $runner->discover('token');

        self::assertSame(2, $result->pageCountByQuery[$queries[0]]);
        self::assertSame(1, $result->pageCountByQuery[$queries[1]]);
        self::assertSame(103, $result->hitCountByQuery[$queries[0]]);
        self::assertSame(0, $result->hitCountByQuery[$queries[1]]);
        // ids 1..100 deduplicated with the overlapping 95..97 from page 2.
        self::assertSame(100, $result->uniqueRepositoryCount);
        // Bare {"id": ...} items carry no full_name, so every one of them is
        // skipped as an invalid search item rather than crashing.
        self::assertSame(100, $result->skippedCount);
        self::assertSame(100, $result->skipReasonCounts[DiscoveryRunner::SKIP_INVALID_SEARCH_ITEM]);
    }

    public function testStopsAtConfiguredMaxPagesPerQueryEvenWhenPagesStayFull(): void
    {
        $queries = DiscoveryRunner::SEARCH_QUERIES;

        // Every page returns a full 100-item page, so without a limit this
        // would page forever. Exactly 2 responses per query (4 total) are
        // queued; if the runner asked for a 5th page, MockHttpClient would
        // fail the test with "no more responses left".
        $fullPage = fn () => $this->itemsResponse(range(1, 100));
        $httpClient = new MockHttpClient([$fullPage(), $fullPage(), $fullPage(), $fullPage()]);

        [$persister] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister, maxPages: 2);
        $result = $runner->discover('token');

        self::assertSame(2, $result->pageCountByQuery[$queries[0]]);
        self::assertSame(2, $result->pageCountByQuery[$queries[1]]);
        self::assertSame(200, $result->hitCountByQuery[$queries[0]]);
        self::assertSame(200, $result->hitCountByQuery[$queries[1]]);
        // Same ids 1..100 repeated on every page/query, so only 100 unique.
        self::assertSame(100, $result->uniqueRepositoryCount);
    }

    public function testRejectsMaxPagesPerQueryBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        [$persister] = $this->makePersister();
        $this->runner(new MockHttpClient(), $persister, maxPages: 0);
    }

    public function testDuplicateRepositoryInBothQueriesIsPersistedOnlyOnce(): void
    {
        $item = $this->searchItem();

        $httpClient = new MockHttpClient([
            $this->searchResponse([$item]),
            $this->metadataContentResponse(),
            $this->releasesResponse([$this->zipRelease()]),
            ...$this->noScreenshotResponses(),
            ...$this->noIconResponses(),
            $this->searchResponse([$item]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);
        $result = $runner->discover('token');

        self::assertSame(1, $result->uniqueRepositoryCount);
        self::assertSame(1, $result->persistedCount);
        self::assertSame(0, $result->skippedCount);
        // Exactly one persistCandidate() call: one Extension + one ExtensionSource.
        self::assertCount(2, $persistedHolder->items);
        self::assertInstanceOf(Extension::class, $persistedHolder->items[0]);
        self::assertInstanceOf(ExtensionSource::class, $persistedHolder->items[1]);
    }

    public function testIneligibleRepositoryIsSkippedWithoutPersisting(): void
    {
        $item = $this->searchItem(['archived' => true]);

        $httpClient = new MockHttpClient([
            $this->searchResponse([$item]),
            $this->searchResponse([$item]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);
        $result = $runner->discover('token');

        self::assertSame(0, $result->persistedCount);
        self::assertSame(1, $result->skippedCount);
        self::assertSame(1, $result->skipReasonCounts['archived_repository']);
        self::assertSame([], $persistedHolder->items);
    }

    public function testRepositoryWithoutFindableMetadataIsSkippedWithoutPersisting(): void
    {
        $item = $this->searchItem();

        $httpClient = new MockHttpClient([
            $this->searchResponse([$item]),
            $this->notFoundResponse(), // metadata.json
            $this->notFoundResponse(), // extensions/metadata.json
            $this->notFoundResponse(), // src/metadata.json
            $this->notFoundResponse(), // extension/metadata.json
            $this->notFoundResponse(), // resources/metadata.json
            new JsonMockResponse([['name' => 'README.md', 'type' => 'file']]), // root listing, no uuid-like dir
            $this->searchResponse([$item]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);
        $result = $runner->discover('token');

        self::assertSame(0, $result->persistedCount);
        self::assertSame(1, $result->skippedCount);
        self::assertSame(1, $result->skipReasonCounts['metadata_not_found']);
        self::assertSame([], $persistedHolder->items);
    }

    public function testValidRepositoryWithMetadataAndReleaseZipCallsPersisterOnce(): void
    {
        $item = $this->searchItem();

        $httpClient = new MockHttpClient([
            $this->searchResponse([$item]),
            $this->metadataContentResponse(uuid: 'repo@owner', shellVersion: ['45', '46']),
            $this->releasesResponse([$this->zipRelease('repo.zip')]),
            ...$this->noScreenshotResponses(),
            ...$this->noIconResponses(),
            $this->searchResponse([]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);
        $result = $runner->discover('token');

        self::assertSame(1, $result->persistedCount);
        self::assertSame(0, $result->skippedCount);
        self::assertCount(2, $persistedHolder->items);

        $extension = $persistedHolder->items[0];
        $source = $persistedHolder->items[1];
        self::assertInstanceOf(Extension::class, $extension);
        self::assertInstanceOf(ExtensionSource::class, $source);
        self::assertSame('repo@owner', $extension->uuid);
        self::assertSame(ExtensionSource::TYPE_GITHUB, $source->sourceType);
        self::assertSame(
            'https://github.com/owner/repo/releases/download/v1.0.0/repo.zip',
            $source->installUrl,
        );
    }

    /**
     * End-to-end guard for the two facts the importer used to throw away:
     * the extension's self-declared name/description from metadata.json and
     * the repository's own creation date.
     */
    public function testPersistsMetadataNameDescriptionAndRepositoryCreationDate(): void
    {
        $item = $this->searchItem([
            'full_name' => 'ryohsuke1231/liquid-glass',
            'description' => 'Gnome Shell Extension of Liquid Glass',
            'created_at' => '2026-04-08T20:53:45Z',
            'pushed_at' => '2026-08-16T04:39:52Z',
        ]);

        $httpClient = new MockHttpClient([
            $this->searchResponse([$item]),
            $this->metadataContentResponse(
                uuid: 'liquid-glass@thinkingcoding1231.gmail.com',
                shellVersion: ['49', '50'],
                name: 'Liquid Glass',
                description: 'Applies a translucent, refractive effect.',
            ),
            $this->releasesResponse([]),
            ...$this->noScreenshotResponses(),
            ...$this->noIconResponses(),
            $this->searchResponse([]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);
        $result = $runner->discover('token');

        self::assertSame(1, $result->persistedCount);

        $extension = $persistedHolder->items[0];
        $source = $persistedHolder->items[1];
        self::assertInstanceOf(Extension::class, $extension);
        self::assertInstanceOf(ExtensionSource::class, $source);

        self::assertSame('Liquid Glass', $extension->name);
        self::assertSame('Applies a translucent, refractive effect.', $extension->description);
        self::assertSame('Liquid Glass', $source->displayName);
        self::assertSame('Applies a translucent, refractive effect.', $source->displayDescription);
        self::assertSame('2026-04-08', $extension->creationDate->format('Y-m-d'));
        self::assertSame('2026-08-16', $source->lastCommitAt->format('Y-m-d'));
    }

    /**
     * End-to-end guard that a README screenshot actually reaches the stored
     * source: shields.io badges are dropped on the host allowlist, and the
     * first real screenshot is pinned to the head commit SHA.
     */
    public function testPersistsFirstValidReadmeScreenshotOnTheSource(): void
    {
        $sha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
        $readme = <<<'MD'
            # Liquid Glass for GNOME Shell

            ![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)

            ![Dash to Dock Screenshot](assets/demo2.png)
            MD;

        $png = $this->pngBytes(1280, 720);

        $httpClient = new MockHttpClient([
            $this->searchResponse([$this->searchItem()]),
            $this->metadataContentResponse(),
            $this->releasesResponse([]),
            $this->commitsResponse($sha),
            $this->readmeResponse($readme),
            new MockResponse('', ['response_headers' => [
                'content-type' => 'image/png',
                'content-length' => (string) strlen($png),
            ]]),
            new MockResponse($png),
            ...$this->noIconResponses(),
            $this->searchResponse([]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);
        $result = $runner->discover('token');

        self::assertSame(1, $result->persistedCount);

        $source = $persistedHolder->items[1];
        self::assertInstanceOf(ExtensionSource::class, $source);
        self::assertSame(
            'https://raw.githubusercontent.com/owner/repo/' . $sha . '/assets/demo2.png',
            $source->displayScreenshot,
        );
    }

    private function pngBytes(int $width, int $height): string
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
     * End-to-end guard that a repository-root icon/logo file actually
     * reaches the stored source, closing the gap left by only covering the
     * "no icon found" path elsewhere: the icon resolver finds "logo.png" at
     * the repository root and the resulting raw URL (pinned to the head
     * commit SHA) ends up on ExtensionSource::$displayIcon.
     */
    public function testPersistsDiscoveredRootIconOnTheSource(): void
    {
        $sha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
        $png = $this->pngBytes(64, 64);

        $httpClient = new MockHttpClient([
            $this->searchResponse([$this->searchItem()]),
            $this->metadataContentResponse(),
            $this->releasesResponse([]),
            ...$this->noScreenshotResponses(),
            $this->commitsResponse($sha),
            $this->directoryListingResponse([
                ['name' => 'README.md', 'type' => 'file'],
                ['name' => 'logo.png', 'type' => 'file'],
            ]),
            ...$this->rasterImageResponses($png),
            $this->searchResponse([]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);
        $result = $runner->discover('token');

        self::assertSame(1, $result->persistedCount);

        $source = $persistedHolder->items[1];
        self::assertInstanceOf(ExtensionSource::class, $source);
        self::assertSame(
            'https://raw.githubusercontent.com/owner/repo/' . $sha . '/logo.png',
            $source->displayIcon,
        );
    }

    public function testRepositoryWithoutInstallableReleaseIsStillPersistedWithNullInstallUrl(): void
    {
        $item = $this->searchItem();

        $httpClient = new MockHttpClient([
            $this->searchResponse([$item]),
            $this->metadataContentResponse(),
            $this->releasesResponse([]), // no releases at all
            ...$this->noScreenshotResponses(),
            ...$this->noIconResponses(),
            $this->searchResponse([]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);
        $result = $runner->discover('token');

        self::assertSame(1, $result->persistedCount);
        self::assertCount(2, $persistedHolder->items);

        $source = $persistedHolder->items[1];
        self::assertInstanceOf(ExtensionSource::class, $source);
        self::assertNull($source->installUrl);
    }

    public function testAbortsRunOn403RateLimitDuringMetadataLoadWithoutPersistingAnyCandidate(): void
    {
        $itemA = $this->searchItem(['id' => 1, 'full_name' => 'owner/repo-a']);
        $itemB = $this->searchItem(['id' => 2, 'full_name' => 'owner/repo-b']);

        // Only two responses are queued: the search page, then the 403 for
        // repo-a's metadata.json. If the runner incorrectly kept going (e.g.
        // moved on to repo-b or a second query), MockHttpClient would fail
        // this test with "no more responses left" instead of the expected
        // ApiException.
        $httpClient = new MockHttpClient([
            $this->searchResponse([$itemA, $itemB]),
            new MockResponse('{"message":"API rate limit exceeded"}', [
                'http_code' => 403,
                'response_headers' => ['X-RateLimit-Remaining' => '0'],
            ]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);

        try {
            $runner->discover('token');
            self::fail('Expected discover() to abort with a rate-limited ApiException.');
        } catch (\App\Service\GitHub\ApiException $exception) {
            self::assertTrue($exception->isRateLimited());
        }

        self::assertSame([], $persistedHolder->items, 'No candidate may be persisted once the run is rate-limited.');
    }

    public function testAbortsRunOn429DuringReleaseLoadWithoutStartingTheSecondQuery(): void
    {
        $itemA = $this->searchItem(['id' => 1, 'full_name' => 'owner/repo-a']);

        // Only three responses are queued: the first query's search page,
        // repo-a's metadata, then a 429 for its releases. The second query's
        // search call is deliberately never queued: if the run incorrectly
        // continued to it, MockHttpClient would fail with "no more responses
        // left" instead of the expected ApiException.
        $httpClient = new MockHttpClient([
            $this->searchResponse([$itemA]),
            $this->metadataContentResponse(),
            new MockResponse('{"message":"You have exceeded a secondary rate limit"}', ['http_code' => 429]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);

        try {
            $runner->discover('token');
            self::fail('Expected discover() to abort with a rate-limited ApiException.');
        } catch (\App\Service\GitHub\ApiException $exception) {
            self::assertTrue($exception->isRateLimited());
        }

        self::assertSame([], $persistedHolder->items, 'No candidate may be persisted once the run is rate-limited.');
    }

    public function testGitHubApiFailureWhileLoadingOneCandidateIsSkippedWithoutAbortingTheRun(): void
    {
        $item = $this->searchItem();

        $httpClient = new MockHttpClient([
            $this->searchResponse([$item]),
            new MockResponse('{"message":"Internal Server Error"}', ['http_code' => 500]), // metadata.json
            $this->searchResponse([]),
        ]);

        [$persister, $persistedHolder] = $this->makePersister();
        $runner = $this->runner($httpClient, $persister);
        $result = $runner->discover('token');

        self::assertSame(0, $result->persistedCount);
        self::assertSame(1, $result->skippedCount);
        self::assertSame(1, $result->skipReasonCounts[CandidateProcessor::SKIP_CANDIDATE_LOAD_FAILED]);
        self::assertSame([], $persistedHolder->items);
    }
}
