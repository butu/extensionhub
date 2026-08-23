<?php

namespace App\Service\GitHub;

/**
 * A previously cached GitHub API response for a single API path/query,
 * stored on the filesystem so a later request can send it as If-None-Match.
 */
final class CachedApiResponse
{
    public function __construct(
        public readonly string $etag,
        public readonly string $body,
    ) {
    }
}
