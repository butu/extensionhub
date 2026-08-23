<?php

namespace App\Service\GitHub;

/**
 * Picks the display icon/logo for a GitHub source directly from the
 * repository's own file layout, without relying on any README content.
 *
 * The search is deliberately conservative and its order is fixed (and
 * covered by tests), rather than an open-ended recursive search:
 *
 * - Directories, root first: the repository root, then the same
 *   "extension/extensions/src" layout directories
 *   {@see MetadataValidator::candidatePaths()} looks for metadata.json
 *   in, then — only when the repository has exactly one GNOME extension
 *   UUID-named ("name@domain") top-level directory — that directory too.
 * - File names, within each directory: "logo.svg", "logo.png", "logo.gif",
 *   "icon.svg", "icon.png", "icon.gif". SVG is tried first because it is
 *   vector art and scales to any display size; "logo" before "icon" because
 *   it is the more common name for a project's primary image.
 *
 * Every directory is only actually listed (one extra GitHub API call) when
 * it is known to exist: the root listing already reports which of the
 * static directories are present, so a repository without an "extensions/"
 * directory never causes a lookup for one. The resulting raw URL is pinned
 * to the repository's head commit SHA, satisfying the SVG immutability rule
 * {@see ImageValidator} enforces.
 */
final class IconResolver
{
    private const NOT_FOUND_STATUS = 404;

    /**
     * Static directories to check, root first, in this fixed order. A single
     * UUID-named top-level directory (if any) is appended after these.
     */
    private const STATIC_DIRECTORIES = ['', 'extension', 'extensions', 'src'];

    /**
     * File name search order within any single directory.
     */
    private const FILE_NAME_ORDER = [
        'logo.svg', 'logo.png', 'logo.gif',
        'icon.svg', 'icon.png', 'icon.gif',
    ];

    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly ImageProbe $probe,
        private readonly ImageValidator $validator,
    ) {
    }

    /**
     * Resolve the icon/logo URL for a repository, or null when it definitely
     * has none.
     *
     * $existingIconUrl short-circuits the whole lookup when it is already
     * pinned to the current head commit: nothing in the repository changed,
     * so re-probing would produce the same answer.
     */
    public function resolve(string $token, string $owner, string $repo, ?string $existingIconUrl = null): ?string
    {
        $commitSha = $this->loadHeadCommitSha($token, $owner, $repo);
        if ($commitSha === null) {
            return $existingIconUrl;
        }

        if ($this->isPinnedToCommit($existingIconUrl, $commitSha)) {
            return $existingIconUrl;
        }

        try {
            $root = $this->loadDirectoryListing($token, $owner, $repo, '');
        } catch (ApiException $exception) {
            if ($exception->isRateLimited()) {
                throw $exception;
            }

            // A temporary API failure must not delete an icon we already
            // have; only a readable repository root without a usable image
            // may.
            return $existingIconUrl;
        }

        foreach ($this->candidateDirectories($root['directories']) as $directory) {
            $filesInDirectory = $directory === ''
                ? $root['files']
                : $this->filesInDirectory($token, $owner, $repo, $directory);

            $url = $this->firstAcceptableIconIn($owner, $repo, $commitSha, $directory, $filesInDirectory);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param string[] $filesInDirectory
     */
    private function firstAcceptableIconIn(string $owner, string $repo, string $commitSha, string $directory, array $filesInDirectory): ?string
    {
        foreach (self::FILE_NAME_ORDER as $fileName) {
            if (!in_array($fileName, $filesInDirectory, true)) {
                continue;
            }

            $path = $directory === '' ? $fileName : $directory . '/' . $fileName;
            $url = $this->rawUrl($owner, $repo, $commitSha, $path);

            if ($this->isAcceptableIcon($url)) {
                return $url;
            }
        }

        return null;
    }

    private function isAcceptableIcon(string $url): bool
    {
        $candidate = $this->probe->probe($url);

        return $candidate !== null && $this->validator->validate($candidate)->valid;
    }

    /**
     * Static directories in fixed order, kept only when actually present at
     * the repository root, plus the single UUID-named top-level directory
     * (if there is exactly one) appended last.
     *
     * @param string[] $directoriesAtRoot
     *
     * @return string[]
     */
    private function candidateDirectories(array $directoriesAtRoot): array
    {
        $directories = [''];

        foreach (array_slice(self::STATIC_DIRECTORIES, 1) as $name) {
            if (in_array($name, $directoriesAtRoot, true)) {
                $directories[] = $name;
            }
        }

        $uuidLikeDirectories = array_values(array_filter($directoriesAtRoot, $this->looksLikeUuid(...)));
        if (count($uuidLikeDirectories) === 1) {
            $directories[] = $uuidLikeDirectories[0];
        }

        return $directories;
    }

    /**
     * Mirrors {@see MetadataValidator}'s own UUID heuristic: a GNOME
     * extension UUID is conventionally "name@domain", not an RFC 4122 UUID.
     */
    private function looksLikeUuid(string $directoryName): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_.-]+@[A-Za-z0-9_.-]+$/', $directoryName);
    }

    /**
     * A secondary directory that cannot be listed is treated as empty
     * rather than aborting the whole icon lookup; only a rate limit still
     * propagates.
     *
     * @return string[]
     */
    private function filesInDirectory(string $token, string $owner, string $repo, string $directory): array
    {
        try {
            return $this->loadDirectoryListing($token, $owner, $repo, $directory)['files'];
        } catch (ApiException $exception) {
            if ($exception->isRateLimited()) {
                throw $exception;
            }

            return [];
        }
    }

    /**
     * @return array{files: string[], directories: string[]}
     *
     * @throws ApiException on any failure other than "directory not found"
     */
    private function loadDirectoryListing(string $token, string $owner, string $repo, string $directory): array
    {
        $path = sprintf('repos/%s/%s/contents', rawurlencode($owner), rawurlencode($repo));
        if ($directory !== '') {
            $path .= '/' . $this->encodePath($directory);
        }

        try {
            $response = $this->apiClient->get($token, $path);
        } catch (ApiException $exception) {
            if ($exception->statusCode === self::NOT_FOUND_STATUS) {
                return ['files' => [], 'directories' => []];
            }

            throw $exception;
        }

        if (!array_is_list($response->data)) {
            return ['files' => [], 'directories' => []];
        }

        $files = [];
        $directories = [];
        foreach ($response->data as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null)) {
                continue;
            }

            if (($item['type'] ?? null) === 'file') {
                $files[] = $item['name'];
            } elseif (($item['type'] ?? null) === 'dir') {
                $directories[] = $item['name'];
            }
        }

        return ['files' => $files, 'directories' => $directories];
    }

    private function loadHeadCommitSha(string $token, string $owner, string $repo): ?string
    {
        try {
            $response = $this->apiClient->get(
                $token,
                sprintf('repos/%s/%s/commits', rawurlencode($owner), rawurlencode($repo)),
                ['per_page' => 1],
            );
        } catch (ApiException $exception) {
            if ($exception->isRateLimited()) {
                throw $exception;
            }

            return null;
        }

        if (!array_is_list($response->data)) {
            return null;
        }

        $first = $response->data[0] ?? null;
        $sha = is_array($first) ? ($first['sha'] ?? null) : null;

        return is_string($sha) && preg_match('/^[0-9a-f]{40}$/i', $sha) === 1 ? $sha : null;
    }

    private function rawUrl(string $owner, string $repo, string $sha, string $path): string
    {
        return sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/%s',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode($sha),
            $this->encodePath($path),
        );
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function isPinnedToCommit(?string $url, string $commitSha): bool
    {
        if ($url === null) {
            return false;
        }

        $parsed = parse_url($url);
        if (!is_array($parsed) || ($parsed['host'] ?? null) !== 'raw.githubusercontent.com') {
            return false;
        }

        $path = $parsed['path'] ?? '';
        $segments = array_filter(explode('/', trim($path, '/')));
        $segments = array_values($segments); // Re-index after filter

        return isset($segments[2]) && $segments[2] === $commitSha;
    }
}
