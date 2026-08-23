<?php

namespace App\Service\GitHub;

/**
 * Extracts `owner/repository` from a homepage URL, accepting only the
 * canonical `https://github.com/{owner}/{repository}` shape so this never
 * becomes a general-purpose forge-URL crawler.
 */
final class RepositoryReferenceParser
{
    private const ALLOWED_SCHEME = 'https';
    private const ALLOWED_HOST = 'github.com';

    public function parse(string $homepageUrl): ?RepositoryReference
    {
        $parts = parse_url($homepageUrl);
        if (!is_array($parts)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        if (strtolower($parts['scheme'] ?? '') !== self::ALLOWED_SCHEME) {
            return null;
        }

        if (strtolower($parts['host'] ?? '') !== self::ALLOWED_HOST) {
            return null;
        }

        // parse_url() already separates ?query and #fragment from the path,
        // so simply never reading them is the "removed on normalization"
        // step; only a single trailing slash needs explicit trimming.
        $path = $parts['path'] ?? '';
        $path = rtrim($path, '/');
        $segments = explode('/', ltrim($path, '/'));

        if (count($segments) !== 2) {
            return null;
        }

        [$owner, $repo] = $segments;

        // fromOwnerAndRepo() also rejects a `.git` clone-URL repo segment.
        return RepositoryReference::fromOwnerAndRepo($owner, $repo);
    }
}
