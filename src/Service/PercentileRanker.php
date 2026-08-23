<?php

namespace App\Service;

/**
 * Percentile rank of a value within a population: the share (0-100) of
 * population values at or below it. Deterministic and monotonic; an empty
 * population always ranks 0, and a population of one always ranks its sole
 * member at 100.
 */
final class PercentileRanker
{
    /**
     * @param float[] $population
     */
    public function rank(float $value, array $population): int
    {
        $count = count($population);
        if ($count === 0) {
            return 0;
        }

        $countLessOrEqual = 0;
        foreach ($population as $candidate) {
            if ($candidate <= $value) {
                $countLessOrEqual++;
            }
        }

        return (int) round(($countLessOrEqual / $count) * 100);
    }
}
