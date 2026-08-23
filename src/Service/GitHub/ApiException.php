<?php

namespace App\Service\GitHub;

use RuntimeException;

/**
 * Raised for controlled GitHub API failures (transport, HTTP status, JSON).
 * Messages must never contain the token or other secrets.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?int $statusCode = null,
        private readonly bool $rateLimited = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * True for a GitHub primary rate limit (403 with X-RateLimit-Remaining: 0)
     * or a secondary/abuse rate limit (429). Callers must abort the whole run
     * on this, rather than skipping only the current candidate.
     */
    public function isRateLimited(): bool
    {
        return $this->rateLimited;
    }
}
