<?php

namespace App\Service\GitHub;

use DateTimeImmutable;

/**
 * Loads the GitHub-hosted facts a candidate needs (repository details,
 * metadata.json content, releases) via {@see ApiClient}, using only
 * fixed, developer-defined API path shapes with path segments percent
 * encoded or cast to a validated integer. No arbitrary URL is ever requested.
 */
final class CandidateLoader
{
    private const NOT_FOUND_STATUS = 404;

    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly MetadataValidator $metadataValidator,
    ) {
    }

    /**
     * Loads a single repository by its stable numeric GitHub ID
     * (`GET /repositories/{id}`), never by a free-form URL. Returns null
     * when the repository is gone or no longer accessible (404); any other
     * HTTP error is left to the caller to decide how to handle.
     */
    public function loadRepository(string $token, int $repositoryId): ?RepositoryDetails
    {
        if ($repositoryId <= 0) {
            throw new \InvalidArgumentException('repositoryId must be a positive integer.');
        }

        try {
            $response = $this->apiClient->get($token, sprintf('repositories/%d', $repositoryId));
        } catch (ApiException $exception) {
            if ($exception->statusCode === self::NOT_FOUND_STATUS) {
                return null;
            }

            throw $exception;
        }

        return $this->mapRepositoryDetails($response->data);
    }

    /**
     * Loads a repository by `owner/repo` (`GET /repos/{owner}/{repo}`) for
     * the targeted path, where no numeric ID is known yet. Null on 404.
     */
    public function loadRepositoryByFullName(string $token, string $owner, string $repo): ?RepositoryDetails
    {
        try {
            $response = $this->apiClient->get(
                $token,
                sprintf('repos/%s/%s', $this->encodeSegment($owner), $this->encodeSegment($repo)),
            );
        } catch (ApiException $exception) {
            if ($exception->statusCode === self::NOT_FOUND_STATUS) {
                return null;
            }

            throw $exception;
        }

        return $this->mapRepositoryDetails($response->data);
    }

    public function loadMetadata(string $token, string $owner, string $repo): MetadataValidationResult
    {
        $staticPaths = $this->metadataValidator->candidatePaths([]);

        foreach ($staticPaths as $path) {
            $content = $this->fetchFileContent($token, $owner, $repo, $path);
            if ($content !== null) {
                return $this->metadataValidator->validate([$path => $content], []);
            }
        }

        $topLevelDirectories = $this->fetchTopLevelDirectories($token, $owner, $repo);
        $uuidPaths = array_diff($this->metadataValidator->candidatePaths($topLevelDirectories), $staticPaths);

        foreach ($uuidPaths as $path) {
            $content = $this->fetchFileContent($token, $owner, $repo, $path);
            if ($content !== null) {
                return $this->metadataValidator->validate([$path => $content], $topLevelDirectories);
            }
        }

        return MetadataValidationResult::skip('metadata_not_found');
    }

    /**
     * @return Release[]
     */
    public function loadReleases(string $token, string $owner, string $repo): array
    {
        try {
            $response = $this->apiClient->get(
                $token,
                sprintf('repos/%s/%s/releases', $this->encodeSegment($owner), $this->encodeSegment($repo)),
                ['per_page' => 100],
            );
        } catch (ApiException $exception) {
            if ($exception->statusCode === self::NOT_FOUND_STATUS) {
                return [];
            }

            throw $exception;
        }

        $items = array_is_list($response->data) ? $response->data : [];

        $releases = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $releases[] = $this->mapRelease($item);
            }
        }

        return $releases;
    }

    /**
     * @param array<mixed> $data
     */
    private function mapRepositoryDetails(array $data): ?RepositoryDetails
    {
        $id = $data['id'] ?? null;
        $fullName = $data['full_name'] ?? null;
        if (!is_int($id) || !is_string($fullName) || $fullName === '') {
            return null;
        }

        $ownerData = is_array($data['owner'] ?? null) ? $data['owner'] : [];

        return new RepositoryDetails(
            id: $id,
            fullName: $fullName,
            private: (bool) ($data['private'] ?? false),
            archived: (bool) ($data['archived'] ?? false),
            stargazersCount: (int) ($data['stargazers_count'] ?? 0),
            forksCount: (int) ($data['forks_count'] ?? 0),
            htmlUrl: is_string($data['html_url'] ?? null) ? $data['html_url'] : 'https://github.com/' . $fullName,
            description: is_string($data['description'] ?? null) ? $data['description'] : null,
            ownerLogin: is_string($ownerData['login'] ?? null) ? $ownerData['login'] : null,
            ownerHtmlUrl: is_string($ownerData['html_url'] ?? null) ? $ownerData['html_url'] : null,
            pushedAt: $this->parseTimestamp($data['pushed_at'] ?? null),
            createdAt: $this->parseTimestamp($data['created_at'] ?? null),
        );
    }

    /**
     * GitHub timestamps are ISO-8601 strings, but a malformed value must not
     * abort a whole run, so an unparsable timestamp is treated as absent.
     */
    private function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function fetchFileContent(string $token, string $owner, string $repo, string $path): ?string
    {
        try {
            $response = $this->apiClient->get($token, sprintf(
                'repos/%s/%s/contents/%s',
                $this->encodeSegment($owner),
                $this->encodeSegment($repo),
                $this->encodePath($path),
            ));
        } catch (ApiException $exception) {
            if ($exception->statusCode === self::NOT_FOUND_STATUS) {
                return null;
            }

            throw $exception;
        }

        return $this->decodeFileContent($response->data);
    }

    /**
     * @return string[]
     */
    private function fetchTopLevelDirectories(string $token, string $owner, string $repo): array
    {
        try {
            $response = $this->apiClient->get(
                $token,
                sprintf('repos/%s/%s/contents', $this->encodeSegment($owner), $this->encodeSegment($repo)),
            );
        } catch (ApiException $exception) {
            if ($exception->statusCode === self::NOT_FOUND_STATUS) {
                return [];
            }

            throw $exception;
        }

        if (!array_is_list($response->data)) {
            return [];
        }

        $directories = [];
        foreach ($response->data as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'dir' && is_string($item['name'] ?? null)) {
                $directories[] = $item['name'];
            }
        }

        return $directories;
    }

    /**
     * @param array<mixed> $data
     */
    private function decodeFileContent(array $data): ?string
    {
        $encoding = $data['encoding'] ?? null;
        $content = $data['content'] ?? null;

        if ($encoding !== 'base64' || !is_string($content)) {
            return null;
        }

        $decoded = base64_decode($content, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @param array<mixed> $item
     */
    private function mapRelease(array $item): Release
    {
        $assets = [];
        $rawAssets = $item['assets'] ?? [];
        if (is_array($rawAssets)) {
            foreach ($rawAssets as $rawAsset) {
                $asset = $this->mapReleaseAsset($rawAsset);
                if ($asset !== null) {
                    $assets[] = $asset;
                }
            }
        }

        $publishedAt = $item['published_at'] ?? null;

        return new Release(
            tagName: is_string($item['tag_name'] ?? null) ? $item['tag_name'] : '',
            draft: (bool) ($item['draft'] ?? false),
            prerelease: (bool) ($item['prerelease'] ?? false),
            publishedAt: is_string($publishedAt) && $publishedAt !== '' ? new DateTimeImmutable($publishedAt) : null,
            assets: $assets,
        );
    }

    private function mapReleaseAsset(mixed $rawAsset): ?ReleaseAsset
    {
        if (!is_array($rawAsset)) {
            return null;
        }

        $name = $rawAsset['name'] ?? null;
        $downloadUrl = $rawAsset['browser_download_url'] ?? null;

        if (!is_string($name) || !is_string($downloadUrl)) {
            return null;
        }

        return new ReleaseAsset($name, $downloadUrl);
    }

    private function encodeSegment(string $segment): string
    {
        return rawurlencode($segment);
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
