<?php

namespace App\Service\GitHub;

/**
 * Decoded result of a single GitHub API GET call, including whether the
 * data came from the ETag cache via a 304 Not Modified response.
 */
final class ApiResponse
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly bool $notModified,
        public readonly ?string $etag,
    ) {
    }
}
