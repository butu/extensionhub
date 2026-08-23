<?php

namespace App\Tests\Service\GitHub;

use App\Service\GitHub\ApiCache;
use App\Service\GitHub\ApiClient;
use App\Service\GitHub\ApiException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * GitHub HTTP foundation, tested exclusively against a MockHttpClient:
 * no live GitHub call is made by this test.
 */
class ApiClientTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/github-api-cache-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            (new Filesystem())->remove($this->cacheDir);
        }
    }

    private function cache(): ApiCache
    {
        $parameterBag = new ParameterBag(['kernel.project_dir' => $this->cacheDir . '/project']);

        return new ApiCache($parameterBag);
    }

    public function testSendsAuthUserAgentAndAcceptHeaders(): void
    {
        $seenHeaders = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenHeaders) {
            $seenHeaders = $options['headers'];

            return new MockResponse('{"items":[]}', ['http_code' => 200]);
        });

        $client = new ApiClient($httpClient, $this->cache());
        $client->get('secret-token-value', 'search/repositories', ['q' => 'topic:gnome-shell-extension']);

        $headerLine = implode("\n", $seenHeaders);
        self::assertStringContainsString('Authorization: Bearer secret-token-value', $headerLine);
        self::assertStringContainsString('Accept: application/vnd.github+json', $headerLine);
        self::assertStringContainsString('User-Agent: ExtensionHub-GitHub-Extension-Indexer', $headerLine);
    }

    public function testSendsGitHubApiVersionHeader(): void
    {
        $seenHeaders = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenHeaders) {
            $seenHeaders = $options['headers'];

            return new MockResponse('{"items":[]}', ['http_code' => 200]);
        });

        $client = new ApiClient($httpClient, $this->cache());
        $client->get('token', 'search/repositories', ['q' => 'x']);

        $headerLine = implode("\n", $seenHeaders);
        self::assertStringContainsString('X-GitHub-Api-Version: 2022-11-28', $headerLine);
    }

    public function testStoresEtagAndBodyOnSuccessfulJsonResponse(): void
    {
        $httpClient = new MockHttpClient(function () {
            return new MockResponse('{"items":[{"id":1}]}', [
                'http_code' => 200,
                'response_headers' => ['ETag' => '"abc123"'],
            ]);
        });

        $cache = $this->cache();
        $client = new ApiClient($httpClient, $cache);

        $response = $client->get('token', 'search/repositories', ['q' => 'x']);

        self::assertFalse($response->notModified);
        self::assertSame('"abc123"', $response->etag);
        self::assertSame([['id' => 1]], $response->data['items']);

        $cacheKey = 'search/repositories?' . http_build_query(['q' => 'x']);
        $cached = $cache->get($cacheKey);
        self::assertNotNull($cached);
        self::assertSame('"abc123"', $cached->etag);
        self::assertSame('{"items":[{"id":1}]}', $cached->body);
    }

    public function testSendsIfNoneMatchAndReturnsCachedBodyOn304(): void
    {
        $cache = $this->cache();
        $cacheKey = 'search/repositories?' . http_build_query(['q' => 'x']);
        $cache->put($cacheKey, '"abc123"', '{"items":[{"id":42}]}');

        $seenHeaders = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenHeaders) {
            $seenHeaders = $options['headers'];

            return new MockResponse('', ['http_code' => 304]);
        });

        $client = new ApiClient($httpClient, $cache);
        $response = $client->get('token', 'search/repositories', ['q' => 'x']);

        $headerLine = implode("\n", $seenHeaders);
        self::assertStringContainsString('If-None-Match: "abc123"', $headerLine);

        self::assertTrue($response->notModified);
        self::assertSame('"abc123"', $response->etag);
        self::assertSame([['id' => 42]], $response->data['items']);
    }

    public function testThrowsControlledExceptionOnHttpErrorStatus(): void
    {
        $httpClient = new MockHttpClient(function () {
            return new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401]);
        });

        $client = new ApiClient($httpClient, $this->cache());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('GitHub API request failed with status 401.');

        $client->get('bad-token', 'search/repositories', ['q' => 'x']);
    }

    public function testFlagsPrimaryRateLimitOn403WithZeroRemaining(): void
    {
        $httpClient = new MockHttpClient(function () {
            return new MockResponse('{"message":"API rate limit exceeded"}', [
                'http_code' => 403,
                'response_headers' => ['X-RateLimit-Remaining' => '0'],
            ]);
        });

        $client = new ApiClient($httpClient, $this->cache());

        try {
            $client->get('token', 'search/repositories', ['q' => 'x']);
            self::fail('Expected a ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(403, $exception->statusCode);
            self::assertTrue($exception->isRateLimited());
            self::assertStringContainsString('rate limit', $exception->getMessage());
            self::assertStringNotContainsString('token', $exception->getMessage());
        }
    }

    public function testDoesNotFlagRateLimitOn403WithRemainingQuota(): void
    {
        $httpClient = new MockHttpClient(function () {
            return new MockResponse('{"message":"Forbidden"}', [
                'http_code' => 403,
                'response_headers' => ['X-RateLimit-Remaining' => '10'],
            ]);
        });

        $client = new ApiClient($httpClient, $this->cache());

        try {
            $client->get('token', 'search/repositories', ['q' => 'x']);
            self::fail('Expected a ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(403, $exception->statusCode);
            self::assertFalse($exception->isRateLimited());
            self::assertSame('GitHub API request failed with status 403.', $exception->getMessage());
        }
    }

    public function testDoesNotFlagRateLimitOn403WithoutARateLimitHeaderAtAll(): void
    {
        $httpClient = new MockHttpClient(function () {
            return new MockResponse('{"message":"Forbidden"}', ['http_code' => 403]);
        });

        $client = new ApiClient($httpClient, $this->cache());

        try {
            $client->get('token', 'search/repositories', ['q' => 'x']);
            self::fail('Expected a ApiException.');
        } catch (ApiException $exception) {
            self::assertFalse($exception->isRateLimited());
        }
    }

    public function testFlagsSecondaryRateLimitOn429RegardlessOfHeaders(): void
    {
        $httpClient = new MockHttpClient(function () {
            return new MockResponse('{"message":"You have exceeded a secondary rate limit"}', ['http_code' => 429]);
        });

        $client = new ApiClient($httpClient, $this->cache());

        try {
            $client->get('token', 'search/repositories', ['q' => 'x']);
            self::fail('Expected a ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(429, $exception->statusCode);
            self::assertTrue($exception->isRateLimited());
        }
    }

    public function testOtherStatusCodesAreNeverFlaggedAsRateLimited(): void
    {
        $httpClient = new MockHttpClient(function () {
            return new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401]);
        });

        $client = new ApiClient($httpClient, $this->cache());

        try {
            $client->get('bad-token', 'search/repositories', ['q' => 'x']);
            self::fail('Expected a ApiException.');
        } catch (ApiException $exception) {
            self::assertFalse($exception->isRateLimited());
        }
    }

    public function testThrowsControlledExceptionOnInvalidJson(): void
    {
        $httpClient = new MockHttpClient(function () {
            return new MockResponse('not json', ['http_code' => 200]);
        });

        $client = new ApiClient($httpClient, $this->cache());

        $this->expectException(ApiException::class);

        $client->get('token', 'search/repositories', ['q' => 'x']);
    }

    public function testRejectsEmptyToken(): void
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('No HTTP call should be made when the token is empty.');
        });

        $client = new ApiClient($httpClient, $this->cache());

        $this->expectException(ApiException::class);

        $client->get('', 'search/repositories');
    }

    public function testRejectsEmptyPathWithoutMakingARequest(): void
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('No HTTP call should be made for an empty path.');
        });

        $client = new ApiClient($httpClient, $this->cache());

        $this->expectException(ApiException::class);

        $client->get('token', '');
    }

    public function testRejectsPathThatIsOnlyASlashWithoutMakingARequest(): void
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('No HTTP call should be made for a path that is only a slash.');
        });

        $client = new ApiClient($httpClient, $this->cache());

        $this->expectException(ApiException::class);

        $client->get('token', '/');
    }

    public function testRejectsPathContainingASchemeWithoutMakingARequest(): void
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('No HTTP call should be made for a path containing a scheme.');
        });

        $client = new ApiClient($httpClient, $this->cache());

        $this->expectException(ApiException::class);

        $client->get('token', 'https://evil.example.com/steal-token');
    }

    public function testThrowsControlledExceptionOn304WithoutAPriorCacheEntry(): void
    {
        $httpClient = new MockHttpClient(function () {
            return new MockResponse('', ['http_code' => 304]);
        });

        $client = new ApiClient($httpClient, $this->cache());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('GitHub API returned 304 Not Modified without a cached response.');

        $client->get('token', 'search/repositories', ['q' => 'x']);
    }

    public function testTreatsAMalformedCacheFileAsNoCacheAndDoesNotSendIfNoneMatch(): void
    {
        $cache = $this->cache();
        $cacheKey = 'search/repositories?' . http_build_query(['q' => 'x']);
        $cacheFilePath = $this->cacheDir . '/project/var/github-api-cache/' . sha1($cacheKey) . '.json';

        (new Filesystem())->mkdir(dirname($cacheFilePath));
        file_put_contents($cacheFilePath, '{not valid json');

        // A malformed cache entry must not crash reads; ApiCache::get()
        // silently treats it as absent.
        self::assertNull($cache->get($cacheKey));

        $seenHeaders = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenHeaders) {
            $seenHeaders = $options['headers'];

            return new MockResponse('{"items":[]}', ['http_code' => 200]);
        });

        $client = new ApiClient($httpClient, $cache);
        $response = $client->get('token', 'search/repositories', ['q' => 'x']);

        $headerLine = implode("\n", $seenHeaders);
        self::assertStringNotContainsString('If-None-Match', $headerLine);
        self::assertFalse($response->notModified);
    }
}
