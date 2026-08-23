<?php

namespace App\Service\GitHub;

/**
 * A validated `owner/repository` pair, shared by URL parsing and the
 * command argument so both are held to identical rules.
 */
final class RepositoryReference
{
    public function __construct(
        public readonly string $owner,
        public readonly string $repository,
    ) {
    }

    public function fullName(): string
    {
        return $this->owner . '/' . $this->repository;
    }

    /**
     * Builds a reference from a single `owner/repository` string (as used by
     * the `app:import-github-repository` command argument), or null when it
     * is not exactly two valid path segments.
     */
    public static function fromFullName(string $value): ?self
    {
        $segments = explode('/', trim($value, '/'));
        if (count($segments) !== 2) {
            return null;
        }

        return self::fromOwnerAndRepo($segments[0], $segments[1]);
    }

    public static function fromOwnerAndRepo(string $owner, string $repo): ?self
    {
        if (!self::isValidSegment($owner) || !self::isValidSegment($repo)) {
            return null;
        }

        // A `.git` clone URL/argument must never be accepted as a repository
        // reference, matching the homepage parser's own rejection rule.
        if (str_ends_with(strtolower($repo), '.git')) {
            return null;
        }

        return new self($owner, $repo);
    }

    private static function isValidSegment(string $segment): bool
    {
        return $segment !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $segment) === 1;
    }
}
