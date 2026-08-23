<?php

namespace App\Service\GitHub;

/**
 * Pure repository intake rule check, free of any GitHub HTTP call so it
 * stays testable against already-loaded repository data.
 */
final class RepositoryEligibilityChecker
{
    public const MIN_STARGAZERS = 5;

    /**
     * $requireMinimumStars defaults to true (Discovery/refresh); only the
     * targeted path passes false, bypassing this one rule only.
     */
    public function evaluate(RepositorySummary $repository, bool $requireMinimumStars = true): EligibilityResult
    {
        if ($repository->private) {
            return EligibilityResult::skip('private_repository');
        }

        if ($repository->archived) {
            return EligibilityResult::skip('archived_repository');
        }

        if ($requireMinimumStars && $repository->stargazersCount < self::MIN_STARGAZERS) {
            return EligibilityResult::skip('insufficient_stars');
        }

        return EligibilityResult::eligible();
    }
}
