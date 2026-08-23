<?php

namespace App\Service\GitHub;

/**
 * Picks the screenshot for a GitHub source: reads the repository's README,
 * turns its image references into immutable raw URLs, and returns the first
 * one that passes the image policy.
 *
 * Reading order decides. A README leads with its most representative
 * screenshot, so "first valid" is a better heuristic than any scoring, and
 * it keeps the number of probes low.
 *
 * Only two GitHub API calls are added per repository (head commit + README),
 * both ETag-cached; conditional requests answered with 304 do not count
 * against the API rate limit. The image probes themselves go to the raw
 * content hosts and never touch the API rate limit.
 */
final class ScreenshotResolver
{
    private const NOT_FOUND_STATUS = 404;

    /**
     * Upper bound on probed candidates, so a README full of badges cannot
     * turn one repository into dozens of HTTP requests.
     */
    private const MAX_PROBED_CANDIDATES = 8;

    /**
     * A screenshot has to actually show something. Badges, shields and
     * inline status icons are small, and would otherwise be picked simply
     * because they appear first in the README.
     */
    private const MIN_SCREENSHOT_WIDTH_PX = 320;
    private const MIN_SCREENSHOT_HEIGHT_PX = 180;

    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly ReadmeImageExtractor $extractor,
        private readonly ImageProbe $probe,
        private readonly ImageValidator $validator,
    ) {
    }

    /**
     * Resolve the screenshot URL for a repository, or null when the README
     * offers no acceptable image.
     *
     * $existingScreenshotUrl short-circuits the whole lookup when it is
     * already pinned to the current head commit: nothing in the repository
     * changed, so re-probing would produce the same answer. A new commit
     * changes the SHA and therefore triggers a fresh resolution.
     */
    public function resolve(string $token, string $owner, string $repo, ?string $existingScreenshotUrl = null): ?string
    {
        $commitSha = $this->loadHeadCommitSha($token, $owner, $repo);
        if ($commitSha === null) {
            return $existingScreenshotUrl;
        }

        if ($this->isPinnedToCommit($existingScreenshotUrl, $commitSha)) {
            return $existingScreenshotUrl;
        }

        try {
            $readme = $this->loadReadme($token, $owner, $repo);
        } catch (ApiException $exception) {
            if ($exception->isRateLimited()) {
                throw $exception;
            }

            // A temporary API failure must not delete a screenshot we already
            // have; only a readable README without a usable image may.
            return $existingScreenshotUrl;
        }

        if ($readme === null) {
            return null;
        }

        [$readmePath, $readmeContent] = $readme;
        $candidates = $this->extractor->extract($readmeContent, $readmePath, $owner, $repo, $commitSha);

        foreach (array_slice($candidates, 0, self::MAX_PROBED_CANDIDATES) as $candidateUrl) {
            if ($this->isAcceptableScreenshot($candidateUrl)) {
                return $candidateUrl;
            }
        }

        return null;
    }

    private function isAcceptableScreenshot(string $url): bool
    {
        $candidate = $this->probe->probe($url);
        if ($candidate === null) {
            return false;
        }

        if (!$this->validator->validate($candidate)->valid) {
            return false;
        }

        return $this->isLargeEnough($candidate);
    }

    /**
     * A valid SVG has no pixel dimensions to check and is treated as large
     * enough: it is vector art, so it scales to any display size.
     */
    private function isLargeEnough(ImageCandidate $candidate): bool
    {
        if ($candidate->widthPx === null || $candidate->heightPx === null) {
            return true;
        }

        return $candidate->widthPx >= self::MIN_SCREENSHOT_WIDTH_PX
            && $candidate->heightPx >= self::MIN_SCREENSHOT_HEIGHT_PX;
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

    /**
     * Null means the repository definitively has no usable README (none at
     * all, or an unreadable payload). Any other API failure is thrown, so the
     * caller can keep an existing screenshot instead of deleting it.
     *
     * @return array{0: string, 1: string}|null the README's repository path and decoded content
     *
     * @throws ApiException on any failure other than "no README"
     */
    private function loadReadme(string $token, string $owner, string $repo): ?array
    {
        try {
            $response = $this->apiClient->get(
                $token,
                sprintf('repos/%s/%s/readme', rawurlencode($owner), rawurlencode($repo)),
            );
        } catch (ApiException $exception) {
            if ($exception->statusCode === self::NOT_FOUND_STATUS) {
                return null;
            }

            throw $exception;
        }

        $data = $response->data;
        $path = $data['path'] ?? null;
        $content = $data['content'] ?? null;

        if (!is_string($path) || ($data['encoding'] ?? null) !== 'base64' || !is_string($content)) {
            return null;
        }

        $decoded = base64_decode($content, true);

        return $decoded === false ? null : [$path, $decoded];
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
