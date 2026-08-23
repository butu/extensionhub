<?php

namespace App\Tests\Service\GitHub;

use App\Service\GitHub\RepositoryEligibilityChecker;
use App\Service\GitHub\RepositorySummary;
use PHPUnit\Framework\TestCase;

/**
 * Pure repository intake rule checks against already-loaded repository data,
 * deliberately tested without any GitHub HTTP call.
 */
class RepositoryEligibilityCheckerTest extends TestCase
{
    private function makeRepository(array $overrides = []): RepositorySummary
    {
        return new RepositorySummary(
            id: $overrides['id'] ?? 123456,
            fullName: $overrides['fullName'] ?? 'Plyply99/Plaid',
            private: $overrides['private'] ?? false,
            archived: $overrides['archived'] ?? false,
            stargazersCount: $overrides['stargazersCount'] ?? 5,
        );
    }

    public function testEligibleRepositoryPassesAllRules(): void
    {
        $checker = new RepositoryEligibilityChecker();
        $repository = $this->makeRepository(['stargazersCount' => 42]);

        $result = $checker->evaluate($repository);

        self::assertTrue($result->eligible);
        self::assertNull($result->skipReason);
    }

    public function testRepositoryWithExactlyFiveStarsIsEligible(): void
    {
        $checker = new RepositoryEligibilityChecker();
        $repository = $this->makeRepository(['stargazersCount' => 5]);

        $result = $checker->evaluate($repository);

        self::assertTrue($result->eligible);
    }

    public function testRepositoryWithTooFewStarsIsSkipped(): void
    {
        $checker = new RepositoryEligibilityChecker();
        $repository = $this->makeRepository(['stargazersCount' => 4]);

        $result = $checker->evaluate($repository);

        self::assertFalse($result->eligible);
        self::assertSame('insufficient_stars', $result->skipReason);
    }

    public function testArchivedRepositoryIsSkippedRegardlessOfStars(): void
    {
        $checker = new RepositoryEligibilityChecker();
        $repository = $this->makeRepository(['archived' => true, 'stargazersCount' => 1000]);

        $result = $checker->evaluate($repository);

        self::assertFalse($result->eligible);
        self::assertSame('archived_repository', $result->skipReason);
    }

    public function testPrivateRepositoryIsSkippedRegardlessOfStars(): void
    {
        $checker = new RepositoryEligibilityChecker();
        $repository = $this->makeRepository(['private' => true, 'stargazersCount' => 1000]);

        $result = $checker->evaluate($repository);

        self::assertFalse($result->eligible);
        self::assertSame('private_repository', $result->skipReason);
    }

    public function testPrivateCheckTakesPrecedenceOverArchivedAndStars(): void
    {
        $checker = new RepositoryEligibilityChecker();
        $repository = $this->makeRepository(['private' => true, 'archived' => true, 'stargazersCount' => 0]);

        $result = $checker->evaluate($repository);

        self::assertSame('private_repository', $result->skipReason);
    }

    /**
     * The targeted path bypasses only the minimum-star rule; global
     * Discovery must keep calling evaluate() without this flag.
     */
    public function testLowStarRepositoryIsEligibleWhenMinimumStarsIsBypassed(): void
    {
        $checker = new RepositoryEligibilityChecker();
        $repository = $this->makeRepository(['stargazersCount' => 0]);

        $result = $checker->evaluate($repository, requireMinimumStars: false);

        self::assertTrue($result->eligible);
        self::assertNull($result->skipReason);
    }

    public function testArchivedRepositoryIsStillSkippedEvenWhenMinimumStarsIsBypassed(): void
    {
        $checker = new RepositoryEligibilityChecker();
        $repository = $this->makeRepository(['archived' => true, 'stargazersCount' => 0]);

        $result = $checker->evaluate($repository, requireMinimumStars: false);

        self::assertFalse($result->eligible);
        self::assertSame('archived_repository', $result->skipReason);
    }

    public function testPrivateRepositoryIsStillSkippedEvenWhenMinimumStarsIsBypassed(): void
    {
        $checker = new RepositoryEligibilityChecker();
        $repository = $this->makeRepository(['private' => true, 'stargazersCount' => 0]);

        $result = $checker->evaluate($repository, requireMinimumStars: false);

        self::assertFalse($result->eligible);
        self::assertSame('private_repository', $result->skipReason);
    }
}
