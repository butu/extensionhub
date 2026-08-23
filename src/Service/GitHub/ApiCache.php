<?php

namespace App\Service\GitHub;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Filesystem-backed ETag/body cache for GitHub API GET responses, keyed by
 * API path and query. No database or entity involvement in this slice.
 */
final class ApiCache
{
    private readonly string $cacheDirectory;

    public function __construct(ParameterBagInterface $parameterBag)
    {
        $projectDir = $parameterBag->get('kernel.project_dir');
        $this->cacheDirectory = rtrim((string) $projectDir, '/') . '/var/github-api-cache';
    }

    public function get(string $cacheKey): ?CachedApiResponse
    {
        $path = $this->filePathFor($cacheKey);

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['etag'], $decoded['body']) || !is_string($decoded['etag']) || !is_string($decoded['body'])) {
            return null;
        }

        return new CachedApiResponse($decoded['etag'], $decoded['body']);
    }

    public function put(string $cacheKey, string $etag, string $body): void
    {
        if (!is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0755, true);
        }

        $payload = json_encode(['etag' => $etag, 'body' => $body], JSON_THROW_ON_ERROR);
        file_put_contents($this->filePathFor($cacheKey), $payload);
    }

    private function filePathFor(string $cacheKey): string
    {
        return $this->cacheDirectory . '/' . sha1($cacheKey) . '.json';
    }
}
