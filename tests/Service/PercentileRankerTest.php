<?php

namespace App\Tests\Service;

use App\Service\PercentileRanker;
use PHPUnit\Framework\TestCase;

/**
 * Contract: an empty population always ranks 0, a population of one always
 * ranks its sole member 100, ties count as "at or below" (<=), and ranks
 * are monotonic and bounded to the closed interval [0, 100].
 */
class PercentileRankerTest extends TestCase
{
    public function testEmptyPopulationRanksZero(): void
    {
        $ranker = new PercentileRanker();

        self::assertSame(0, $ranker->rank(42.0, []));
    }

    public function testSoleMemberRanksAtHundred(): void
    {
        $ranker = new PercentileRanker();

        self::assertSame(100, $ranker->rank(5.0, [5.0]));
    }

    public function testTiesCountAsAtOrBelow(): void
    {
        $ranker = new PercentileRanker();

        // All three population members equal the value, so all three must
        // count as "<= value" and the sole distinct value ranks at 100.
        self::assertSame(100, $ranker->rank(10.0, [10.0, 10.0, 10.0]));
    }

    public function testPartialTieCountsOnlyTiedAndLowerMembers(): void
    {
        $ranker = new PercentileRanker();

        // 3 of 4 population members (10, 10, 20) are <= 20; 30 is not.
        // 3/4 * 100 = 75.
        self::assertSame(75, $ranker->rank(20.0, [10.0, 10.0, 20.0, 30.0]));
    }

    public function testRankIsMonotonicAcrossIncreasingValues(): void
    {
        $ranker = new PercentileRanker();
        $population = [10.0, 20.0, 30.0, 40.0, 50.0];

        $previousRank = -1;
        foreach ([5.0, 15.0, 25.0, 35.0, 45.0, 55.0] as $value) {
            $rank = $ranker->rank($value, $population);

            self::assertGreaterThanOrEqual(
                $previousRank,
                $rank,
                'rank() must never decrease as the probed value increases against a fixed population'
            );
            $previousRank = $rank;
        }
    }

    public function testRankIsBoundedBetweenZeroAndHundred(): void
    {
        $ranker = new PercentileRanker();
        $population = [1.0, 2.0, 3.0];

        foreach ([-100.0, 0.0, 1.0, 2.0, 3.0, 100.0] as $value) {
            $rank = $ranker->rank($value, $population);

            self::assertGreaterThanOrEqual(0, $rank);
            self::assertLessThanOrEqual(100, $rank);
        }
    }

    public function testValueBelowEntirePopulationRanksZero(): void
    {
        $ranker = new PercentileRanker();

        self::assertSame(0, $ranker->rank(0.0, [10.0, 20.0, 30.0]));
    }

    public function testValueAtOrAboveEntirePopulationRanksHundred(): void
    {
        $ranker = new PercentileRanker();

        self::assertSame(100, $ranker->rank(100.0, [10.0, 20.0, 30.0]));
    }
}
