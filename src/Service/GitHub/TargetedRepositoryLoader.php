<?php

namespace App\Service\GitHub;

/**
 * Loads and validates one repository by `owner/repository`, reusing
 * {@see CandidateLoader} and {@see CandidateProcessor} without global
 * search discovery or refresh-by-ID. Persisting stays the caller's job.
 * Bypasses only the minimum-star rule; every other check stays mandatory.
 */
final class TargetedRepositoryLoader
{
    public const SKIP_REPOSITORY_NOT_FOUND = 'repository_not_found';
    public const SKIP_CANDIDATE_LOAD_FAILED = 'candidate_load_failed';

    public function __construct(
        private readonly CandidateLoader $candidateLoader,
        private readonly CandidateProcessor $candidateProcessor,
    ) {
    }

    /**
     * @throws ApiException only when the repository lookup is rate-limited;
     *                       any other load failure becomes a skip instead.
     */
    public function load(string $token, string $owner, string $repo): CandidateProcessResult
    {
        try {
            $details = $this->candidateLoader->loadRepositoryByFullName($token, $owner, $repo);
        } catch (ApiException $exception) {
            if ($exception->isRateLimited()) {
                throw $exception;
            }

            return CandidateProcessResult::skip(self::SKIP_CANDIDATE_LOAD_FAILED);
        }

        if ($details === null) {
            return CandidateProcessResult::skip(self::SKIP_REPOSITORY_NOT_FOUND);
        }

        return $this->candidateProcessor->process($token, $details, requireMinimumStars: false);
    }
}
