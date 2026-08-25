<?php

namespace App\Service\GitHub;

/**
 * Report of a single discovery run: how many repository hits each search
 * query returned across all fetched pages, how many pages were actually
 * fetched per query, how many distinct repositories remained after
 * deduplication by GitHub repository ID, and what happened to each of
 * those unique candidates (persisted, or skipped with a reason).
 */
final class DiscoveryResult
{
    /**
     * @param array<string, int> $hitCountByQuery    total item count per query, summed across fetched pages
     * @param array<string, int> $pageCountByQuery   number of pages actually fetched per query
     * @param array<string, int> $skipReasonCounts   number of skipped candidates per skip reason
     */
    public function __construct(
        public readonly array $hitCountByQuery,
        public readonly int $uniqueRepositoryCount,
        public readonly array $pageCountByQuery = [],
        public readonly int $persistedCount = 0,
        public readonly int $skippedCount = 0,
        public readonly array $skipReasonCounts = [],
        public readonly bool $stoppedForLowRateLimit = false,
    ) {
    }
}
