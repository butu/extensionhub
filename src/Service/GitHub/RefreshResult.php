<?php

namespace App\Service\GitHub;

/**
 * Report of a single refresh run against already-known GitHub sources.
 */
final class RefreshResult
{
    /**
     * @param array<string, int> $skipReasonCounts
     */
    public function __construct(
        public readonly int $knownSourceCount,
        public readonly int $refreshedCount,
        public readonly int $skippedCount = 0,
        public readonly array $skipReasonCounts = [],
        public readonly bool $stoppedForLowRateLimit = false,
    ) {
    }
}
