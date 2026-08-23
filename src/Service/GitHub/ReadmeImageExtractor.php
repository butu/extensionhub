<?php

namespace App\Service\GitHub;

/**
 * Pure extraction of image references from an already-fetched README, free
 * of any HTTP call. Turns the README's own markdown/HTML image syntax into
 * an ordered list of absolute, immutable candidate URLs.
 *
 * Every relative reference is resolved against a fixed commit SHA on
 * raw.githubusercontent.com rather than a branch name. That makes each URL
 * immutable (a requirement for SVG, see {@see ImageValidator}) and
 * means a URL can never silently start serving different bytes later.
 *
 * Reading order is preserved on purpose: a README shows its most
 * representative screenshot first, so the caller can simply take the first
 * candidate that passes validation.
 */
final class ReadmeImageExtractor
{
    private const RAW_HOST = 'https://raw.githubusercontent.com';

    /**
     * Fenced code blocks are stripped before extraction, so install
     * instructions that merely *show* markdown image syntax do not
     * contribute candidates.
     */
    private const FENCED_CODE_BLOCK_PATTERN = '/^[ \t]*(`{3,}|~{3,}).*?^[ \t]*\1[ \t]*$/ms';

    /** `![alt](url)`, optionally with an angle-bracketed URL and/or a title. */
    private const MARKDOWN_IMAGE_PATTERN = '/!\[[^\]]*\]\(\s*(?:<([^>]+)>|([^)\s]+))(?:\s+(?:"[^"]*"|\'[^\']*\'|\([^)]*\)))?\s*\)/';

    /** `<img ... src="url" ...>`, quoted or unquoted. */
    private const HTML_IMAGE_PATTERN = '/<img\b[^>]*?\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i';

    /** `https://github.com/{owner}/{repo}/blob|raw/{ref}/{path}` */
    private const GITHUB_BLOB_URL_PATTERN = '#^https://github\.com/([^/]+)/([^/]+)/(?:blob|raw)/([^/]+)/(.+)$#i';

    /**
     * Absolute candidate URLs in README reading order, deduplicated.
     *
     * @param string $readmeContent raw README text
     * @param string $readmePath    repository path of the README, used as the base for relative references
     * @param string $commitSha     commit SHA the README was read at
     *
     * @return string[]
     */
    public function extract(string $readmeContent, string $readmePath, string $owner, string $repo, string $commitSha): array
    {
        $content = preg_replace(self::FENCED_CODE_BLOCK_PATTERN, '', $readmeContent) ?? $readmeContent;

        $references = array_merge(
            $this->matchReferences(self::MARKDOWN_IMAGE_PATTERN, $content),
            $this->matchReferences(self::HTML_IMAGE_PATTERN, $content),
        );

        $baseDirectory = $this->directoryOf($readmePath);

        $urls = [];
        foreach ($references as $reference) {
            $url = $this->toAbsoluteUrl($reference, $baseDirectory, $owner, $repo, $commitSha);
            if ($url !== null) {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * Collects the first non-empty capture group of every match, so the
     * quoted/unquoted and bracketed/bare URL alternatives collapse into one
     * ordered list of raw references.
     *
     * @return string[]
     */
    private function matchReferences(string $pattern, string $content): array
    {
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $references = [];
        foreach ($matches as $match) {
            foreach (array_slice($match, 1) as $group) {
                if ($group !== '') {
                    $references[] = $group;

                    break;
                }
            }
        }

        return $references;
    }

    private function toAbsoluteUrl(string $reference, string $baseDirectory, string $owner, string $repo, string $commitSha): ?string
    {
        $reference = trim($reference);
        if ($reference === '' || str_starts_with($reference, '#')) {
            return null;
        }

        // A blob/raw page URL is the human-facing wrapper around a file; the
        // raw host serves the actual bytes, so rewrite it rather than
        // discarding an otherwise perfectly good reference.
        if (preg_match(self::GITHUB_BLOB_URL_PATTERN, $reference, $match) === 1) {
            return $this->rawUrl($match[1], $match[2], $match[3], $this->stripQueryAndFragment($match[4]));
        }

        if ($this->isAbsoluteOrProtocolRelative($reference)) {
            // Kept verbatim; the host allowlist is enforced during validation.
            return str_starts_with($reference, '//') ? 'https:' . $reference : $reference;
        }

        // Anything else is a repository-relative path.
        $path = $this->resolveRelativePath($baseDirectory, $this->stripQueryAndFragment($reference));

        return $path === null ? null : $this->rawUrl($owner, $repo, $commitSha, $path);
    }

    private function isAbsoluteOrProtocolRelative(string $reference): bool
    {
        return str_starts_with($reference, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference) === 1;
    }

    private function rawUrl(string $owner, string $repo, string $ref, string $path): string
    {
        return sprintf(
            '%s/%s/%s/%s/%s',
            self::RAW_HOST,
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode($ref),
            $this->encodePath($path),
        );
    }

    private function stripQueryAndFragment(string $reference): string
    {
        return preg_split('/[?#]/', $reference, 2)[0] ?? $reference;
    }

    private function directoryOf(string $readmePath): string
    {
        $directory = trim(str_replace('\\', '/', $readmePath), '/');
        $lastSlash = strrpos($directory, '/');

        return $lastSlash === false ? '' : substr($directory, 0, $lastSlash);
    }

    /**
     * Resolves `.`/`..` segments against the README's directory. A path that
     * tries to climb above the repository root is rejected rather than
     * clamped, since it cannot describe a file inside this repository.
     */
    private function resolveRelativePath(string $baseDirectory, string $reference): ?string
    {
        $isRootRelative = str_starts_with($reference, '/');
        $base = $isRootRelative || $baseDirectory === '' ? [] : explode('/', $baseDirectory);

        $segments = $base;
        foreach (explode('/', trim($reference, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment !== '..') {
                $segments[] = $segment;

                continue;
            }

            if ($segments === []) {
                return null;
            }

            array_pop($segments);
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
