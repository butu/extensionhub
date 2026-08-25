<?php

namespace App\Service\GitHub;

use App\Entity\ExtensionSource;
use App\Repository\ExtensionSourceRepository;
use DateTime;

/**
 * Re-fetches every already-known GitHub source by its stable repository ID
 * and persists the current facts, mirroring the discovery candidate flow.
 *
 * Loading the repository itself by ID (and treating a 404 as
 * {@see self::SKIP_REPOSITORY_NOT_FOUND}) stays this class's own concern;
 * everything from there on — eligibility, metadata/release loading,
 * screenshot/icon resolution, and the rate-limit-vs-skip decision — is
 * delegated to {@see CandidateProcessor}, passing the known source through
 * so an unchanged repository needs no image probing at all.
 */
final class SourceRefreshRunner
{
    public const SKIP_INVALID_EXTERNAL_IDENTIFIER = 'invalid_external_identifier';
    public const SKIP_REPOSITORY_NOT_FOUND = 'repository_not_found';
    public const SKIP_CANDIDATE_LOAD_FAILED = 'candidate_load_failed';

    /**
     * Stop the run while this many API requests remain, instead of running
     * into the hard 403 abort: a single source costs at most ~10 calls, so
     * the reserve covers one more source plus buffer.
     */
    public const RATE_LIMIT_RESERVE = 100;

    public function __construct(
        private readonly ExtensionSourceRepository $sourceRepository,
        private readonly CandidateLoader $candidateLoader,
        private readonly CandidateProcessor $candidateProcessor,
        private readonly SourcePersister $persister,
        private readonly ApiClient $apiClient,
    ) {
    }

    public function refresh(string $token): RefreshResult
    {
        $sources = $this->sourceRepository->findAllGithubSourcesForRefresh();
        $refreshedCount = 0;
        $skipReasonCounts = [];
        $stoppedForLowRateLimit = false;

        foreach ($sources as $source) {
            if ($this->rateLimitReserveHit()) {
                $stoppedForLowRateLimit = true;
                break;
            }

            $this->refreshOne($token, $source, $refreshedCount, $skipReasonCounts);
        }

        return new RefreshResult(
            count($sources),
            $refreshedCount,
            array_sum($skipReasonCounts),
            $skipReasonCounts,
            $stoppedForLowRateLimit,
        );
    }

    private function rateLimitReserveHit(): bool
    {
        $remaining = $this->apiClient->rateLimitRemaining();

        return $remaining !== null && $remaining < self::RATE_LIMIT_RESERVE;
    }

    /**
     * @param array<string, int> $skipReasonCounts
     */
    private function refreshOne(
        string $token,
        ExtensionSource $source,
        int &$refreshedCount,
        array &$skipReasonCounts,
    ): void {
        $repositoryId = $this->parseRepositoryId($source->externalIdentifier);
        if ($repositoryId === null) {
            $this->recordSkip($skipReasonCounts, self::SKIP_INVALID_EXTERNAL_IDENTIFIER);

            return;
        }

        try {
            $details = $this->candidateLoader->loadRepository($token, $repositoryId);
        } catch (ApiException $exception) {
            if ($exception->isRateLimited()) {
                // Abort the whole run: no further source may be checked or
                // persisted once the token is rate-limited.
                throw $exception;
            }

            $this->recordSkip($skipReasonCounts, self::SKIP_CANDIDATE_LOAD_FAILED);

            return;
        }

        if ($details === null) {
            $this->recordSkip($skipReasonCounts, self::SKIP_REPOSITORY_NOT_FOUND);

            return;
        }

        // CandidateProcessor::process() already turns any non-rate-limited
        // ApiException into a skip; a rate-limited one propagates
        // unhandled here too, aborting the whole run so no further source
        // is partially refreshed.
        $result = $this->candidateProcessor->process($token, $details, $source);
        if (!$result->success) {
            $this->recordSkip($skipReasonCounts, $result->skipReason ?? 'unknown_skip');

            return;
        }

        $persistResult = $this->persister->persistCandidate($result->candidate, new DateTime());
        if ($persistResult->success) {
            $refreshedCount++;
        } else {
            $this->recordSkip($skipReasonCounts, $persistResult->skipReason ?? 'unknown_persist_failure');
        }
    }

    /**
     * Only digits, no leading zero, no sign: the externalIdentifier must be
     * exactly what {@see SourceMapper::externalIdentifierFor()} wrote,
     * never an arbitrary string used to build an API path.
     */
    private function parseRepositoryId(?string $externalIdentifier): ?int
    {
        if ($externalIdentifier === null || preg_match('/^[1-9][0-9]*$/', $externalIdentifier) !== 1) {
            return null;
        }

        return (int) $externalIdentifier;
    }

    /**
     * @param array<string, int> $skipReasonCounts
     */
    private function recordSkip(array &$skipReasonCounts, string $reason): void
    {
        $skipReasonCounts[$reason] = ($skipReasonCounts[$reason] ?? 0) + 1;
    }
}
