<?php

namespace App\Tests\Service;

use App\Dto\SourceTrendSignal;
use App\Entity\ExtensionSource;
use App\Service\ExtensionTrendCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pure, DB-free tests for the relative-velocity trending model (mirrors the
 * pre-v2 growth-ratio behaviour): per-source-type eligibility gating on the
 * 7-day delta (falling back to 1-day only when no 7-day baseline exists),
 * growth-ratio-based magnitude (delta relative to current size, floored),
 * clamped negative/missing deltas, and the dual-source "best (max), never
 * summed" combination rule.
 */
class ExtensionTrendCalculatorTest extends TestCase
{
    public function testEgoSourceBelowSevenDayThresholdGetsZeroTrendScore(): void
    {
        $calculator = new ExtensionTrendCalculator();

        $results = $calculator->calculateBatch([
            'ego-below@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 49.0, currentValue: 2000.0)],
        ]);

        self::assertSame(0, $results['ego-below@example'], 'A 7-day EGO delta of 49 must stay below the >=50 threshold');
    }

    public function testEgoSourceAtSevenDayThresholdIsEligible(): void
    {
        $calculator = new ExtensionTrendCalculator();

        $results = $calculator->calculateBatch([
            'ego-at@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 50.0, currentValue: 2000.0)],
        ]);

        self::assertGreaterThan(0, $results['ego-at@example'], 'A 7-day EGO delta of exactly 50 must clear the threshold');
    }

    public function testEgoSourceFallsBackToOneDayDeltaWhenSevenDayMissing(): void
    {
        $calculator = new ExtensionTrendCalculator();

        $results = $calculator->calculateBatch([
            'ego-fallback@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: null, delta1d: 10.0, currentValue: 500.0)],
        ]);

        self::assertGreaterThan(0, $results['ego-fallback@example'], 'A 1-day fallback delta of 10 must clear the fallback threshold when no 7-day baseline exists');
    }

    public function testEgoSourceFallsBackButBelowOneDayThresholdIsNotEligible(): void
    {
        $calculator = new ExtensionTrendCalculator();

        $results = $calculator->calculateBatch([
            'ego-fallback-below@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: null, delta1d: 9.0, currentValue: 500.0)],
        ]);

        self::assertSame(0, $results['ego-fallback-below@example']);
    }

    public function testPresentButBelowThresholdSevenDayDeltaNeverFallsBackToOneDay(): void
    {
        $calculator = new ExtensionTrendCalculator();

        // delta7d is present (10, below the >=50 threshold) so it must be
        // judged on its own merits, never silently replaced by the huge
        // (and individually eligible) 1-day delta.
        $results = $calculator->calculateBatch([
            'ego-no-fallback@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 10.0, delta1d: 1000.0, currentValue: 2000.0)],
        ]);

        self::assertSame(0, $results['ego-no-fallback@example']);
    }

    public function testGithubSourceWithOneStarCanBeEligible(): void
    {
        $calculator = new ExtensionTrendCalculator();

        $results = $calculator->calculateBatch([
            'github-one-star@example' => [new SourceTrendSignal(ExtensionSource::TYPE_GITHUB, delta7d: 1.0, currentValue: 10.0)],
        ]);

        self::assertGreaterThan(0, $results['github-one-star@example'], 'A single-star 7-day delta must clear the GitHub >=1 threshold');
    }

    public function testGithubSourceBelowOneStarThresholdGetsZero(): void
    {
        $calculator = new ExtensionTrendCalculator();

        $results = $calculator->calculateBatch([
            'github-below@example' => [new SourceTrendSignal(ExtensionSource::TYPE_GITHUB, delta7d: 0.0, currentValue: 10.0)],
        ]);

        self::assertSame(0, $results['github-below@example']);
    }

    public function testMissingBaselineIsNeverEligibleRegardlessOfValue(): void
    {
        $calculator = new ExtensionTrendCalculator();

        $results = $calculator->calculateBatch([
            'new-source@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: null, currentValue: 500.0)],
            'established@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 100.0, currentValue: 500.0)],
        ]);

        self::assertSame(0, $results['new-source@example'], 'A brand new source without any baseline (7d or 1d) must never get a positive trendScore');
        self::assertGreaterThan(0, $results['established@example']);
    }

    public function testNegativeDeltaIsClampedToZeroNotNegativeRank(): void
    {
        $calculator = new ExtensionTrendCalculator();

        $results = $calculator->calculateBatch([
            'declining@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: -50.0, currentValue: 500.0)],
        ]);

        self::assertSame(0, $results['declining@example']);
    }

    public function testSmallFastGrowingExtensionOutranksLargeExtensionWithBiggerAbsoluteDelta(): void
    {
        $calculator = new ExtensionTrendCalculator();

        // The huge extension moves far more absolute downloads (1000 vs
        // 100) but relative to its own size that's a much smaller jump
        // (0.5% vs 10%), so the small extension must rank higher.
        $results = $calculator->calculateBatch([
            'huge@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 1000.0, currentValue: 200000.0)],
            'small@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 100.0, currentValue: 50.0)],
        ]);

        self::assertGreaterThan(0, $results['huge@example']);
        self::assertGreaterThan($results['huge@example'], $results['small@example']);
    }

    public function testGrowthRatioFloorTreatsNearZeroAndAtFloorEgoDownloadsEqually(): void
    {
        $calculator = new ExtensionTrendCalculator();

        // Without a floor, a near-zero current download count would make
        // the ratio explode (1000/1 = 1000) far past the at-floor sibling
        // (1000/1000 = 1). The EGO floor of 1000 makes both denominators
        // identical, so both ratios -- and thus both ranks -- must match.
        $results = $calculator->calculateBatch([
            'near-zero@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 1000.0, currentValue: 1.0)],
            'at-floor@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 1000.0, currentValue: 1000.0)],
        ]);

        self::assertSame(100, $results['near-zero@example']);
        self::assertSame(100, $results['at-floor@example']);
    }

    public function testGrowthRatioFloorTreatsNearZeroAndAtFloorGithubStarsEqually(): void
    {
        $calculator = new ExtensionTrendCalculator();

        // Same floor behaviour for GitHub, whose floor is 25 stars.
        $results = $calculator->calculateBatch([
            'near-zero@example' => [new SourceTrendSignal(ExtensionSource::TYPE_GITHUB, delta7d: 25.0, currentValue: 1.0)],
            'at-floor@example' => [new SourceTrendSignal(ExtensionSource::TYPE_GITHUB, delta7d: 25.0, currentValue: 25.0)],
        ]);

        self::assertSame(100, $results['near-zero@example']);
        self::assertSame(100, $results['at-floor@example']);
    }

    public function testEgoAndGithubRatiosAreNormalizedAgainstSeparatePopulations(): void
    {
        $calculator = new ExtensionTrendCalculator();

        // A GitHub ratio of 1.0 (huge for stars) must only be ranked
        // within the GitHub population, never mixed with EGO ratios.
        $results = $calculator->calculateBatch([
            'ego-huge@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 10000.0, currentValue: 1000.0)],
            'ego-small@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 50.0, currentValue: 1000.0)],
            'github-best@example' => [new SourceTrendSignal(ExtensionSource::TYPE_GITHUB, delta7d: 25.0, currentValue: 25.0)],
        ]);

        self::assertSame(100, $results['ego-huge@example']);
        self::assertLessThan($results['ego-huge@example'], $results['ego-small@example']);
        self::assertSame(100, $results['github-best@example'], 'GitHub population is independent of the much larger EGO ratios');
    }

    public function testNonEligibleSignalsNeverDilutePercentileRankOfEligibleSignals(): void
    {
        $calculator = new ExtensionTrendCalculator();

        // Two eligible EGO signals only: ratio 1.0 (delta 1000 / current
        // 1000) and ratio 0.05 (delta 50 / current 1000, right at the
        // threshold). Within just these two, the weaker one sits at the
        // bottom of its population.
        $withoutNoise = $calculator->calculateBatch([
            'eligible-high@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 1000.0, currentValue: 1000.0)],
            'eligible-low@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 50.0, currentValue: 1000.0)],
        ]);

        // Same two eligible signals, plus a pile of non-eligible EGO
        // signals: missing baseline, below-threshold-but-positive delta,
        // and negative delta. None of these are trend-eligible, so none
        // of them may enter the population as a "0" (or any other) ratio
        // -- doing so would let their count dilute/inflate the percentile
        // rank of the genuinely eligible signals.
        $withNoise = $calculator->calculateBatch([
            'eligible-high@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 1000.0, currentValue: 1000.0)],
            'eligible-low@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 50.0, currentValue: 1000.0)],
            'noise-missing-1@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: null, currentValue: 1000.0)],
            'noise-missing-2@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: null, currentValue: 1000.0)],
            'noise-below-1@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 10.0, currentValue: 1000.0)],
            'noise-below-2@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 20.0, currentValue: 1000.0)],
            'noise-negative-1@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: -500.0, currentValue: 1000.0)],
            'noise-negative-2@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: -10.0, currentValue: 1000.0)],
        ]);

        self::assertSame(
            $withoutNoise['eligible-high@example'],
            $withNoise['eligible-high@example'],
            'A pile of non-eligible signals must not change an eligible signal\'s rank'
        );
        self::assertSame(
            $withoutNoise['eligible-low@example'],
            $withNoise['eligible-low@example'],
            'Non-eligible signals (missing baseline, below threshold, negative) must never dilute the population and pull a weak-but-eligible signal\'s rank toward 100'
        );
        self::assertSame(50, $withNoise['eligible-low@example']);
        self::assertSame(100, $withNoise['eligible-high@example']);

        foreach (['noise-missing-1@example', 'noise-missing-2@example', 'noise-below-1@example', 'noise-below-2@example', 'noise-negative-1@example', 'noise-negative-2@example'] as $uuid) {
            self::assertSame(0, $withNoise[$uuid], "{$uuid} is not trend-eligible and must score 0");
        }
    }

    public function testDualSourceExtensionTakesTheBestNormalizedTrendValueNotTheSum(): void
    {
        $calculator = new ExtensionTrendCalculator();

        $results = $calculator->calculateBatch([
            'other-ego@example' => [new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 1000.0, currentValue: 500.0)],
            'other-github@example' => [new SourceTrendSignal(ExtensionSource::TYPE_GITHUB, delta7d: 1.0, currentValue: 10.0)],
            'dual@example' => [
                new SourceTrendSignal(ExtensionSource::TYPE_EGO, delta7d: 60.0, currentValue: 10000.0),
                new SourceTrendSignal(ExtensionSource::TYPE_GITHUB, delta7d: 50.0, currentValue: 10.0),
            ],
        ]);

        // The GitHub leg (ratio 50/25=2.0, top of its population) must win
        // over the comparatively weak EGO leg (ratio 60/10000=0.006, bottom
        // of its population), and the two must never be summed into
        // something >100.
        self::assertSame(100, $results['dual@example']);
        self::assertLessThanOrEqual(100, $results['dual@example']);
    }

    public function testUnknownSourceTypeIsNeverEligible(): void
    {
        $calculator = new ExtensionTrendCalculator();

        $results = $calculator->calculateBatch([
            'weird@example' => [new SourceTrendSignal('unknown-source-type', delta7d: 1000.0, currentValue: 1.0)],
        ]);

        self::assertSame(0, $results['weird@example']);
    }

    public function testEmptyBatchReturnsEmptyResult(): void
    {
        $calculator = new ExtensionTrendCalculator();

        self::assertSame([], $calculator->calculateBatch([]));
    }

    public function testExtensionWithNoSourcesGetsZeroTrendScore(): void
    {
        $calculator = new ExtensionTrendCalculator();

        $results = $calculator->calculateBatch(['no-sources@example' => []]);

        self::assertSame(0, $results['no-sources@example']);
    }
}
