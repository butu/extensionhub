<?php

namespace App\Service\GitHub;

use InvalidArgumentException;

/**
 * Runs the two fixed candidate search queries, paging through results per
 * query and deduplicating hits by the stable GitHub repository ID so a
 * repository found by both queries (or repeated across pages) is only ever
 * checked and persisted once.
 *
 * Each unique candidate is mapped straight from its search-result item
 * ({@see RepositoryDetails::fromSearchResult()}, no extra HTTP call) and
 * handed to {@see CandidateProcessor}, which owns eligibility checking,
 * metadata/release loading, screenshot/icon resolution, and the
 * rate-limit-vs-skip decision. A processed candidate is always handed to
 * {@see SourcePersister}; any other outcome is counted as a skip with its
 * reason.
 *
 * Pagination per query stops as soon as a page returns fewer than 100
 * items (the last page) or the configured page limit is reached, whichever
 * comes first, so a single run can never loop indefinitely.
 */
final class DiscoveryRunner
{
    public const SEARCH_QUERIES = [
        'topic:gnome-shell-extension archived:false stars:>=5',
        'gnome-shell-extension in:name,description,readme archived:false stars:>=5',
    ];

    public const SKIP_INVALID_SEARCH_ITEM = 'invalid_search_item';

    private const PER_PAGE = 100;

    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly CandidateProcessor $candidateProcessor,
        private readonly SourcePersister $persister,
        private readonly int $maxPagesPerQuery = 10,
    ) {
        if ($this->maxPagesPerQuery < 1) {
            throw new InvalidArgumentException('maxPagesPerQuery must be at least 1.');
        }
    }

    public function discover(string $token): DiscoveryResult
    {
        $hitCountByQuery = [];
        $pageCountByQuery = [];
        $seenRepositoryIds = [];
        $persistedCount = 0;
        $skipReasonCounts = [];

        foreach (self::SEARCH_QUERIES as $query) {
            [$hitCount, $pageCount] = $this->discoverQuery(
                $token,
                $query,
                $seenRepositoryIds,
                $persistedCount,
                $skipReasonCounts,
            );
            $hitCountByQuery[$query] = $hitCount;
            $pageCountByQuery[$query] = $pageCount;
        }

        $skippedCount = array_sum($skipReasonCounts);

        return new DiscoveryResult(
            $hitCountByQuery,
            count($seenRepositoryIds),
            $pageCountByQuery,
            $persistedCount,
            $skippedCount,
            $skipReasonCounts,
        );
    }

    /**
     * @param array<int, true>    $seenRepositoryIds
     * @param array<string, int>  $skipReasonCounts
     *
     * @return array{0: int, 1: int} total item count and pages fetched for this query
     */
    private function discoverQuery(
        string $token,
        string $query,
        array &$seenRepositoryIds,
        int &$persistedCount,
        array &$skipReasonCounts,
    ): array {
        $totalHits = 0;
        $pagesFetched = 0;

        for ($page = 1; $page <= $this->maxPagesPerQuery; $page++) {
            $response = $this->apiClient->get($token, 'search/repositories', [
                'q' => $query,
                'per_page' => self::PER_PAGE,
                'page' => $page,
            ]);

            $items = $response->data['items'] ?? [];
            $items = is_array($items) ? $items : [];
            $itemCount = count($items);

            $totalHits += $itemCount;
            $pagesFetched++;

            foreach ($items as $item) {
                $this->processItem($token, $item, $seenRepositoryIds, $persistedCount, $skipReasonCounts);
            }

            if ($itemCount < self::PER_PAGE) {
                break;
            }
        }

        return [$totalHits, $pagesFetched];
    }

    /**
     * @param array<int, true>   $seenRepositoryIds
     * @param array<string, int> $skipReasonCounts
     */
    private function processItem(
        string $token,
        mixed $item,
        array &$seenRepositoryIds,
        int &$persistedCount,
        array &$skipReasonCounts,
    ): void {
        if (!is_array($item) || !isset($item['id'])) {
            return;
        }

        $repositoryId = (int) $item['id'];
        if (isset($seenRepositoryIds[$repositoryId])) {
            return;
        }

        $seenRepositoryIds[$repositoryId] = true;

        $repository = RepositoryDetails::fromSearchResult($item);
        if ($repository === null) {
            $this->recordSkip($skipReasonCounts, self::SKIP_INVALID_SEARCH_ITEM);

            return;
        }

        // CandidateProcessor::process() already turns any non-rate-limited
        // ApiException into a skip; a rate-limited one propagates
        // unhandled here too, aborting the whole run so no further
        // candidate is partially processed.
        $result = $this->candidateProcessor->process($token, $repository);
        if (!$result->success) {
            $this->recordSkip($skipReasonCounts, $result->skipReason ?? 'unknown_skip');

            return;
        }

        $persistResult = $this->persister->persistCandidate($result->candidate, new \DateTime());
        if ($persistResult->success) {
            $persistedCount++;
        } else {
            $this->recordSkip($skipReasonCounts, $persistResult->skipReason ?? 'unknown_persist_failure');
        }
    }

    /**
     * @param array<string, int> $skipReasonCounts
     */
    private function recordSkip(array &$skipReasonCounts, string $reason): void
    {
        $skipReasonCounts[$reason] = ($skipReasonCounts[$reason] ?? 0) + 1;
    }
}
