<?php

namespace App\Tests\Service\GitHub;

use App\Entity\ExtensionSource;
use App\Service\GitHub\ApiCache;
use App\Service\GitHub\ApiClient;
use App\Service\GitHub\ApiException;
use App\Service\GitHub\CandidateLoader;
use App\Service\GitHub\CandidateProcessor;
use App\Service\GitHub\CandidateProcessResult;
use App\Service\GitHub\ExtensionCandidate;
use App\Service\GitHub\IconResolver;
use App\Service\GitHub\ImageProbe;
use App\Service\GitHub\ImageValidator;
use App\Service\GitHub\MetadataValidator;
use App\Service\GitHub\ReadmeImageExtractor;
use App\Service\GitHub\RepositoryDetails;
use App\Service\GitHub\RepositoryEligibilityChecker;
use App\Service\GitHub\ReleaseSelector;
use App\Service\GitHub\ScreenshotResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Isolated workflow contract for the shared "repository facts -> candidate
 * or skip" step that DiscoveryRunner and SourceRefreshRunner both need,
 * tested exclusively against a MockHttpClient: no live GitHub call and no
 * database connection are used by this test.
 */
class CandidateProcessorTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/github-candidate-processor-test-' . uniqid();
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

    private function processor(MockHttpClient $httpClient): CandidateProcessor
    {
        $apiClient = $this->apiClient($httpClient);
        $imageValidator = new ImageValidator();

        return new CandidateProcessor(
            new RepositoryEligibilityChecker(),
            new CandidateLoader($apiClient, new MetadataValidator()),
            new ReleaseSelector(),
            new ScreenshotResolver(
                $apiClient,
                new ReadmeImageExtractor(),
                new ImageProbe($httpClient, $imageValidator),
                $imageValidator,
            ),
            new IconResolver($apiClient, new ImageProbe($httpClient, $imageValidator), $imageValidator),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function repositoryDetails(array $overrides = []): RepositoryDetails
    {
        $fields = array_merge([
            'id' => 1,
            'fullName' => 'owner/repo',
            'private' => false,
            'archived' => false,
            'stargazersCount' => 10,
            'forksCount' => 2,
            'htmlUrl' => 'https://github.com/owner/repo',
            'description' => 'A gnome shell extension',
            'ownerLogin' => 'owner',
            'ownerHtmlUrl' => 'https://github.com/owner',
            'pushedAt' => null,
            'createdAt' => null,
        ], $overrides);

        return new RepositoryDetails(...$fields);
    }

    private function metadataContentResponse(
        string $uuid = 'repo@owner',
        array $shellVersion = ['45'],
    ): JsonMockResponse {
        $json = json_encode(['uuid' => $uuid, 'shell-version' => $shellVersion], JSON_THROW_ON_ERROR);

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

    /** Head-commit response the screenshot/icon resolvers each ask for first. */
    private function commitsResponse(string $sha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'): JsonMockResponse
    {
        return new JsonMockResponse([['sha' => $sha]]);
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

    public function testSuccessfulCandidateCarriesMetadataAndZipReleaseFacts(): void
    {
        $httpClient = new MockHttpClient([
            $this->metadataContentResponse(uuid: 'repo@owner', shellVersion: ['45', '46']),
            $this->releasesResponse([$this->zipRelease('repo.zip')]),
            ...$this->noScreenshotResponses(),
            ...$this->noIconResponses(),
        ]);

        $result = $this->processor($httpClient)->process('token', $this->repositoryDetails());

        self::assertInstanceOf(CandidateProcessResult::class, $result);
        self::assertTrue($result->success);
        self::assertNull($result->skipReason);

        $candidate = $result->candidate;
        self::assertInstanceOf(ExtensionCandidate::class, $candidate);
        self::assertSame(1, $candidate->repositoryId);
        self::assertSame('owner/repo', $candidate->fullName);
        self::assertSame('https://github.com/owner/repo', $candidate->htmlUrl);
        self::assertSame(10, $candidate->stargazersCount);
        self::assertSame(2, $candidate->forksCount);
        self::assertSame('repo@owner', $candidate->uuid);
        self::assertSame(['45', '46'], $candidate->shellVersion);
        self::assertSame('owner', $candidate->ownerLogin);
        self::assertSame('https://github.com/owner', $candidate->ownerHtmlUrl);
        self::assertSame(
            'https://github.com/owner/repo/releases/download/v1.0.0/repo.zip',
            $candidate->installUrl,
        );
        self::assertNotNull($candidate->lastReleaseAt);
        self::assertNull($candidate->screenshotUrl);
        self::assertNull($candidate->iconUrl);
    }

    public function testArchivedRepositoryIsSkippedBeforeAnyExtraHttpCall(): void
    {
        // No responses are queued at all: if the processor made any HTTP
        // call before (or despite) the eligibility check, MockHttpClient
        // would fail this test with "no more responses left".
        $httpClient = new MockHttpClient([]);

        $result = $this->processor($httpClient)->process(
            'token',
            $this->repositoryDetails(['archived' => true]),
        );

        self::assertFalse($result->success);
        self::assertNull($result->candidate);
        self::assertSame('archived_repository', $result->skipReason);
    }

    public function testIneligibleRepositoryDueToTooFewStarsIsSkippedBeforeAnyExtraHttpCall(): void
    {
        $httpClient = new MockHttpClient([]);

        $result = $this->processor($httpClient)->process(
            'token',
            $this->repositoryDetails(['stargazersCount' => 1]),
        );

        self::assertFalse($result->success);
        self::assertSame('insufficient_stars', $result->skipReason);
    }

    /**
     * Only the targeted path passes requireMinimumStars: false;
     * DiscoveryRunner/SourceRefreshRunner never do, so their default call
     * above keeps enforcing the star minimum unchanged.
     */
    public function testBypassesMinimumStarsWhenRequiredByTheTargetedPath(): void
    {
        $httpClient = new MockHttpClient([
            $this->metadataContentResponse(),
            $this->releasesResponse([]),
            ...$this->noScreenshotResponses(),
            ...$this->noIconResponses(),
        ]);

        $result = $this->processor($httpClient)->process(
            'token',
            $this->repositoryDetails(['stargazersCount' => 0]),
            requireMinimumStars: false,
        );

        self::assertTrue($result->success);
        self::assertNull($result->skipReason);
    }

    public function testArchivedRepositoryIsSkippedEvenWhenMinimumStarsIsBypassed(): void
    {
        $httpClient = new MockHttpClient([]);

        $result = $this->processor($httpClient)->process(
            'token',
            $this->repositoryDetails(['archived' => true, 'stargazersCount' => 0]),
            requireMinimumStars: false,
        );

        self::assertFalse($result->success);
        self::assertSame('archived_repository', $result->skipReason);
    }

    public function testPrivateRepositoryIsSkippedEvenWhenMinimumStarsIsBypassed(): void
    {
        $httpClient = new MockHttpClient([]);

        $result = $this->processor($httpClient)->process(
            'token',
            $this->repositoryDetails(['private' => true, 'stargazersCount' => 0]),
            requireMinimumStars: false,
        );

        self::assertFalse($result->success);
        self::assertSame('private_repository', $result->skipReason);
    }

    public function testNonRateLimitCandidateLoadFailureReturnsCandidateLoadFailedSkip(): void
    {
        // Only one response is queued (a plain server error for the first
        // metadata.json lookup): if the processor incorrectly kept going
        // (e.g. tried another metadata path or releases), MockHttpClient
        // would fail with "no more responses left" instead of the expected
        // skip.
        $httpClient = new MockHttpClient([
            new MockResponse('{"message":"Internal Server Error"}', ['http_code' => 500]),
        ]);

        $result = $this->processor($httpClient)->process('token', $this->repositoryDetails());

        self::assertFalse($result->success);
        self::assertNull($result->candidate);
        self::assertSame(CandidateProcessor::SKIP_CANDIDATE_LOAD_FAILED, $result->skipReason);
    }

    public function testRateLimitOn403DuringMetadataLoadPropagatesApiExceptionInsteadOfSkipping(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"message":"API rate limit exceeded"}', [
                'http_code' => 403,
                'response_headers' => ['X-RateLimit-Remaining' => '0'],
            ]),
        ]);

        try {
            $this->processor($httpClient)->process('token', $this->repositoryDetails());
            self::fail('Expected process() to propagate a rate-limited ApiException.');
        } catch (ApiException $exception) {
            self::assertTrue($exception->isRateLimited());
        }
    }

    public function testUnchangedExistingSourceRetainsStoredVisualAssetsWithoutProbing(): void
    {
        $sha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
        $existingScreenshot = 'https://raw.githubusercontent.com/owner/repo/' . $sha . '/assets/demo2.png';
        $existingIcon = 'https://raw.githubusercontent.com/owner/repo/' . $sha . '/logo.svg';

        $existing = new ExtensionSource();
        $existing->displayScreenshot = $existingScreenshot;
        $existing->displayIcon = $existingIcon;

        // Only two commit lookups are queued (one per resolver): if the
        // processor probed the README or the repository root despite the
        // unchanged head commit, MockHttpClient would fail with "no more
        // responses left" instead of returning the stored URLs untouched.
        $httpClient = new MockHttpClient([
            $this->metadataContentResponse(),
            $this->releasesResponse([]),
            $this->commitsResponse($sha),
            $this->commitsResponse($sha),
        ]);

        $result = $this->processor($httpClient)->process('token', $this->repositoryDetails(), $existing);

        self::assertTrue($result->success);
        self::assertSame($existingScreenshot, $result->candidate->screenshotUrl);
        self::assertSame($existingIcon, $result->candidate->iconUrl);
    }
}
