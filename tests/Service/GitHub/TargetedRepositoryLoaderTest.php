<?php

namespace App\Tests\Service\GitHub;

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
use App\Service\GitHub\ReleaseSelector;
use App\Service\GitHub\RepositoryEligibilityChecker;
use App\Service\GitHub\ScreenshotResolver;
use App\Service\GitHub\TargetedRepositoryLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The shared repository-load path for the targeted command and the
 * automatic EGO homepage check, tested against a MockHttpClient only.
 */
class TargetedRepositoryLoaderTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/github-targeted-loader-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            (new Filesystem())->remove($this->cacheDir);
        }
    }

    private function loader(MockHttpClient $httpClient): TargetedRepositoryLoader
    {
        $parameterBag = new ParameterBag(['kernel.project_dir' => $this->cacheDir]);
        $apiClient = new ApiClient($httpClient, new ApiCache($parameterBag));
        $imageValidator = new ImageValidator();

        $candidateProcessor = new CandidateProcessor(
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

        return new TargetedRepositoryLoader(new CandidateLoader($apiClient, new MetadataValidator()), $candidateProcessor);
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

    public function testLoadsAnEligibleRepositoryByOwnerAndRepoName(): void
    {
        $httpClient = new MockHttpClient([
            $this->repositoryResponse(),
            $this->metadataContentResponse(),
            new JsonMockResponse([]), // releases
            ...$this->noScreenshotAndIconResponses(),
        ]);

        $result = $this->loader($httpClient)->load('token', 'owner', 'repo');

        self::assertTrue($result->success);
        self::assertSame('repo@owner', $result->candidate->uuid);
        self::assertSame('owner/repo', $result->candidate->fullName);
    }

    public function testRequestsTheExactOwnerRepoApiPath(): void
    {
        $capturedUrl = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl) {
            $capturedUrl ??= $url;

            return new MockResponse('{"message":"Not Found"}', ['http_code' => 404]);
        });

        $this->loader($httpClient)->load('token', 'owner', 'repo');

        self::assertStringContainsString('https://api.github.com/repos/owner/repo', (string) $capturedUrl);
    }

    public function testRepositoryNotFoundIsSkipped(): void
    {
        $httpClient = new MockHttpClient([$this->notFoundResponse()]);

        $result = $this->loader($httpClient)->load('token', 'owner', 'repo');

        self::assertFalse($result->success);
        self::assertSame(TargetedRepositoryLoader::SKIP_REPOSITORY_NOT_FOUND, $result->skipReason);
    }

    /**
     * This targeted path bypasses only the minimum-star rule; every other
     * eligibility check still applies (see the archived/private tests below).
     */
    public function testLowStarRepositoryIsAcceptedViaTheTargetedPath(): void
    {
        $httpClient = new MockHttpClient([
            $this->repositoryResponse(['stargazers_count' => 0]),
            $this->metadataContentResponse(),
            new JsonMockResponse([]), // releases
            ...$this->noScreenshotAndIconResponses(),
        ]);

        $result = $this->loader($httpClient)->load('token', 'owner', 'repo');

        self::assertTrue($result->success);
        self::assertSame(0, $result->candidate->stargazersCount);
    }

    public function testArchivedRepositoryIsStillSkippedViaTheTargetedPath(): void
    {
        $httpClient = new MockHttpClient([
            $this->repositoryResponse(['archived' => true, 'stargazers_count' => 0]),
        ]);

        $result = $this->loader($httpClient)->load('token', 'owner', 'repo');

        self::assertFalse($result->success);
        self::assertSame('archived_repository', $result->skipReason);
    }

    public function testPrivateRepositoryIsStillSkippedViaTheTargetedPath(): void
    {
        $httpClient = new MockHttpClient([
            $this->repositoryResponse(['private' => true, 'stargazers_count' => 0]),
        ]);

        $result = $this->loader($httpClient)->load('token', 'owner', 'repo');

        self::assertFalse($result->success);
        self::assertSame('private_repository', $result->skipReason);
    }

    public function testGenericApiFailureDuringRepositoryLoadIsSkipped(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"message":"Internal Server Error"}', ['http_code' => 500]),
        ]);

        $result = $this->loader($httpClient)->load('token', 'owner', 'repo');

        self::assertFalse($result->success);
        self::assertSame(TargetedRepositoryLoader::SKIP_CANDIDATE_LOAD_FAILED, $result->skipReason);
    }

    public function testRateLimitDuringRepositoryLoadPropagatesAsAnException(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"message":"API rate limit exceeded"}', [
                'http_code' => 403,
                'response_headers' => ['X-RateLimit-Remaining' => '0'],
            ]),
        ]);

        $this->expectException(ApiException::class);

        $this->loader($httpClient)->load('token', 'owner', 'repo');
    }
}
