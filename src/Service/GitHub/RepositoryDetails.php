<?php

namespace App\Service\GitHub;

use DateTimeImmutable;

/**
 * Already-mapped repository facts, ready for eligibility checking and
 * candidate assembly. Built either from a single `GET /repositories/{id}`
 * response ({@see CandidateLoader::loadRepository()}) or, via
 * {@see self::fromSearchResult()}, from one `GET /search/repositories`
 * item — both report the same repository shape, so both map onto this one
 * value type.
 */
final class RepositoryDetails
{
    public function __construct(
        public readonly int $id,
        public readonly string $fullName,
        public readonly bool $private,
        public readonly bool $archived,
        public readonly int $stargazersCount,
        public readonly int $forksCount,
        public readonly string $htmlUrl,
        public readonly ?string $description = null,
        public readonly ?string $ownerLogin = null,
        public readonly ?string $ownerHtmlUrl = null,
        public readonly ?DateTimeImmutable $pushedAt = null,
        public readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public function summary(): RepositorySummary
    {
        return new RepositorySummary($this->id, $this->fullName, $this->private, $this->archived, $this->stargazersCount);
    }

    /**
     * Maps one raw `GET /search/repositories` item straight from GitHub's
     * JSON response, without any HTTP call of its own. Returns null when
     * the item lacks a usable repository id or full name, the two fields
     * nothing else here can fall back to.
     *
     * @param array<mixed> $item
     */
    public static function fromSearchResult(array $item): ?self
    {
        $id = $item['id'] ?? null;
        $fullName = $item['full_name'] ?? null;
        if (!is_int($id) || !is_string($fullName) || $fullName === '') {
            return null;
        }

        $ownerData = is_array($item['owner'] ?? null) ? $item['owner'] : [];

        return new self(
            id: $id,
            fullName: $fullName,
            private: (bool) ($item['private'] ?? false),
            archived: (bool) ($item['archived'] ?? false),
            stargazersCount: (int) ($item['stargazers_count'] ?? 0),
            forksCount: (int) ($item['forks_count'] ?? 0),
            htmlUrl: is_string($item['html_url'] ?? null) ? $item['html_url'] : 'https://github.com/' . $fullName,
            description: is_string($item['description'] ?? null) ? $item['description'] : null,
            ownerLogin: is_string($ownerData['login'] ?? null) ? $ownerData['login'] : null,
            ownerHtmlUrl: is_string($ownerData['html_url'] ?? null) ? $ownerData['html_url'] : null,
            pushedAt: self::parseTimestamp($item['pushed_at'] ?? null),
            createdAt: self::parseTimestamp($item['created_at'] ?? null),
        );
    }

    /**
     * GitHub timestamps are ISO-8601 strings, but a malformed value must not
     * abort mapping the rest of the item, so an unparsable timestamp is
     * treated as absent.
     */
    private static function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
