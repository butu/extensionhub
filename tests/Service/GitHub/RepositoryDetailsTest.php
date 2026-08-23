<?php

namespace App\Tests\Service\GitHub;

use App\Service\GitHub\RepositoryDetails;
use PHPUnit\Framework\TestCase;

/**
 * Pure mapping contract for `RepositoryDetails::fromSearchResult()`: turns a
 * single GitHub search-result item (`GET /search/repositories`) into the
 * same rich repository facts DiscoveryRunner today extracts by hand from
 * that raw array, deliberately tested without any GitHub HTTP call — this
 * test file never constructs an HttpClientInterface or ApiClient, only
 * plain arrays in, a value object (or null) out.
 */
class RepositoryDetailsTest extends TestCase
{
    /**
     * The realistic shape of one `GET /search/repositories` item, matching
     * DiscoveryRunnerTest's own search item fixture plus the additional
     * 'created_at' field GitHub's real response also carries for every
     * item.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function searchItem(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'full_name' => 'owner/repo',
            'html_url' => 'https://github.com/owner/repo',
            'description' => 'A gnome shell extension',
            'stargazers_count' => 10,
            'forks_count' => 2,
            'archived' => false,
            'private' => false,
            'created_at' => '2026-04-08T20:53:45Z',
            'pushed_at' => '2026-08-16T04:39:52Z',
            'owner' => ['login' => 'owner', 'html_url' => 'https://github.com/owner'],
        ], $overrides);
    }

    public function testMapsAllRichRepositoryFactsFromARealisticSearchItem(): void
    {
        $details = RepositoryDetails::fromSearchResult($this->searchItem());

        self::assertInstanceOf(RepositoryDetails::class, $details);
        self::assertSame(1, $details->id);
        self::assertSame('owner/repo', $details->fullName);
        self::assertFalse($details->private);
        self::assertSame('https://github.com/owner/repo', $details->htmlUrl);
        self::assertSame('A gnome shell extension', $details->description);
        self::assertSame(10, $details->stargazersCount);
        self::assertSame(2, $details->forksCount);
        self::assertFalse($details->archived);
        self::assertSame('owner', $details->ownerLogin);
        self::assertSame('https://github.com/owner', $details->ownerHtmlUrl);
        self::assertSame('2026-04-08', $details->createdAt?->format('Y-m-d'));
        self::assertSame('2026-08-16', $details->pushedAt?->format('Y-m-d'));
    }

    /**
     * Mirrors the existing html_url fallback DiscoveryRunner/CandidateLoader
     * already apply when GitHub omits it: built from the full name rather
     * than left null.
     */
    public function testFallsBackToAConstructedHtmlUrlWhenTheSearchItemHasNone(): void
    {
        $item = $this->searchItem();
        unset($item['html_url']);

        $details = RepositoryDetails::fromSearchResult($item);

        self::assertInstanceOf(RepositoryDetails::class, $details);
        self::assertSame('https://github.com/owner/repo', $details->htmlUrl);
    }

    public function testReturnsNullWhenTheRepositoryIdIsMissing(): void
    {
        $item = $this->searchItem();
        unset($item['id']);

        self::assertNull(RepositoryDetails::fromSearchResult($item));
    }

    public function testReturnsNullWhenTheRepositoryIdIsNotAnInteger(): void
    {
        self::assertNull(RepositoryDetails::fromSearchResult($this->searchItem(['id' => 'not-a-number'])));
    }

    public function testReturnsNullWhenFullNameIsMissing(): void
    {
        $item = $this->searchItem();
        unset($item['full_name']);

        self::assertNull(RepositoryDetails::fromSearchResult($item));
    }

    public function testReturnsNullWhenFullNameIsBlank(): void
    {
        self::assertNull(RepositoryDetails::fromSearchResult($this->searchItem(['full_name' => ''])));
    }
}
