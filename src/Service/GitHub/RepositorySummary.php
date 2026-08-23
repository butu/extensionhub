<?php

namespace App\Service\GitHub;

/**
 * Already-loaded repository facts needed for the intake decision; never
 * fetched here, always supplied by the caller.
 */
final class RepositorySummary
{
    public function __construct(
        public readonly int $id,
        public readonly string $fullName,
        public readonly bool $private,
        public readonly bool $archived,
        public readonly int $stargazersCount,
    ) {
    }
}
