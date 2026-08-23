<?php

namespace App\Service\GitHub;

use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GitHub HTTP-API foundation: builds safe HTTPS requests against the fixed
 * GitHub API base URL, sends the required headers, and applies the ETag
 * cache. Callers only ever pass developer-controlled API paths (never raw
 * user input), so no arbitrary external URL can be requested.
 */
final class ApiClient
{
    private const BASE_URL = 'https://api.github.com/';
    private const USER_AGENT = 'ExtensionHub-GitHub-Extension-Indexer';
    private const API_VERSION = '2022-11-28';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ApiCache $cache,
    ) {
    }

    /**
     * @param array<string, scalar> $query
     *
     * @throws ApiException on transport failure, HTTP error status, or invalid JSON
     */
    public function get(string $token, string $path, array $query = []): ApiResponse
    {
        if ($token === '') {
            throw new ApiException('Cannot call the GitHub API without a token.');
        }

        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '://')) {
            throw new ApiException('Refusing to call the GitHub API with an invalid path.');
        }

        $cacheKey = $path . '?' . http_build_query($query);
        $cached = $this->cache->get($cacheKey);

        $headers = [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . $token,
            'X-GitHub-Api-Version' => self::API_VERSION,
        ];

        if ($cached !== null) {
            $headers['If-None-Match'] = $cached->etag;
        }

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . $path, [
                'query' => $query,
                'headers' => $headers,
            ]);
            $statusCode = $response->getStatusCode();
        } catch (HttpClientExceptionInterface $exception) {
            throw new ApiException('GitHub API request failed: transport error.', 0, $exception);
        }

        if ($statusCode === 304) {
            if ($cached === null) {
                throw new ApiException('GitHub API returned 304 Not Modified without a cached response.');
            }

            return new ApiResponse($this->decode($cached->body), true, $cached->etag);
        }

        if ($statusCode >= 400) {
            $rateLimited = $this->isRateLimited($statusCode, $response);

            throw new ApiException(
                $rateLimited
                    ? sprintf('GitHub API rate limit exceeded (status %d).', $statusCode)
                    : sprintf('GitHub API request failed with status %d.', $statusCode),
                statusCode: $statusCode,
                rateLimited: $rateLimited,
            );
        }

        $body = $response->getContent(false);
        $data = $this->decode($body);

        $etag = $response->getHeaders(false)['etag'][0] ?? null;
        if ($etag !== null) {
            $this->cache->put($cacheKey, $etag, $body);
        }

        return new ApiResponse($data, false, $etag);
    }

    /**
     * GitHub signals its primary rate limit as 403 with X-RateLimit-Remaining: 0,
     * and its secondary/abuse rate limit as plain 429 (no such header guaranteed).
     */
    private function isRateLimited(int $statusCode, ResponseInterface $response): bool
    {
        if ($statusCode === 429) {
            return true;
        }

        if ($statusCode !== 403) {
            return false;
        }

        $remaining = $response->getHeaders(false)['x-ratelimit-remaining'][0] ?? null;

        return $remaining === '0';
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiException('GitHub API returned invalid JSON.', 0, $exception);
        }

        if (!is_array($data)) {
            throw new ApiException('GitHub API returned unexpected JSON payload.');
        }

        return $data;
    }
}
