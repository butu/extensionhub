<?php

namespace App\Tests\Service;

use App\Dto\SourceRawSignal;
use App\Entity\ExtensionSource;
use App\Service\ExtensionScoreCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pure score/scoreComponents computation, deliberately tested without a
 * database connection or the snapshot builder.
 *
 * Per the score & snapshot contract todo: components are source-internal
 * percentile ranks (0-100); an EGO raw value is never compared to a GitHub
 * raw value directly, and a dual-source extension's component is the best
 * of its sources, never their sum.
 */
class ExtensionScoreCalculatorTest extends TestCase
{
    public function testPercentileRankOfSoleMemberIsMax(): void
    {
        $calculator = new ExtensionScoreCalculator();

        self::assertSame(100, $calculator->percentileRank(42.0, [42.0]));
    }

    public function testPercentileRankOrdersHigherValuesHigher(): void
    {
        $calculator = new ExtensionScoreCalculator();
        $population = [10.0, 20.0, 30.0, 40.0];

        $low = $calculator->percentileRank(10.0, $population);
        $mid = $calculator->percentileRank(20.0, $population);
        $high = $calculator->percentileRank(40.0, $population);

        self::assertLessThan($mid, $low);
        self::assertLessThan($high, $mid);
        self::assertSame(100, $high);
    }

    public function testPercentileRankIsBoundedBetweenZeroAndHundred(): void
    {
        $calculator = new ExtensionScoreCalculator();

        self::assertGreaterThanOrEqual(0, $calculator->percentileRank(-5.0, [1.0, 2.0, 3.0]));
        self::assertLessThanOrEqual(100, $calculator->percentileRank(1000.0, [1.0, 2.0, 3.0]));
    }

    public function testCalculateBatchNormalizesWithinSourceTypeOnly(): void
    {
        $calculator = new ExtensionScoreCalculator();

        // Two EGO-only extensions: 'low' has fewer downloads than 'high'.
        // A GitHub-only extension with a numerically huge star count must
        // never inflate or shrink the EGO extensions' popularity ranking,
        // because normalization stays scoped to same-type sources.
        $signals = [
            'ego-low@example' => [new SourceRawSignal(ExtensionSource::TYPE_EGO, popularityRaw: 100.0, freshnessRaw: 1000.0)],
            'ego-high@example' => [new SourceRawSignal(ExtensionSource::TYPE_EGO, popularityRaw: 900.0, freshnessRaw: 1000.0)],
            'github-huge@example' => [new SourceRawSignal(ExtensionSource::TYPE_GITHUB, popularityRaw: 1_000_000.0, freshnessRaw: 1000.0)],
        ];

        $results = $calculator->calculateBatch($signals);

        self::assertLessThan(
            $results['ego-high@example']['components']->popularity,
            $results['ego-low@example']['components']->popularity,
            'Higher EGO downloads must rank higher than lower EGO downloads'
        );

        // The GitHub-only extension is the sole member of its own source-type
        // population, so it still ranks at the top of *its* population (100),
        // without ever being compared to the EGO raw values.
        self::assertSame(100, $results['github-huge@example']['components']->popularity);
    }

    public function testCalculateBatchDualSourceComponentIsBestNotSum(): void
    {
        $calculator = new ExtensionScoreCalculator();

        // A dual-source extension whose EGO signal alone would rank around
        // the middle, and whose GitHub signal alone would also rank around
        // the middle. If components were summed, popularity could exceed
        // 100; the contract requires the best (max), never the sum.
        $signals = [
            'ego-only-low@example' => [new SourceRawSignal(ExtensionSource::TYPE_EGO, popularityRaw: 10.0, freshnessRaw: 10.0)],
            'ego-only-high@example' => [new SourceRawSignal(ExtensionSource::TYPE_EGO, popularityRaw: 90.0, freshnessRaw: 10.0)],
            'github-only-low@example' => [new SourceRawSignal(ExtensionSource::TYPE_GITHUB, popularityRaw: 10.0, freshnessRaw: 10.0)],
            'github-only-high@example' => [new SourceRawSignal(ExtensionSource::TYPE_GITHUB, popularityRaw: 90.0, freshnessRaw: 10.0)],
            'dual@example' => [
                new SourceRawSignal(ExtensionSource::TYPE_EGO, popularityRaw: 50.0, freshnessRaw: 10.0),
                new SourceRawSignal(ExtensionSource::TYPE_GITHUB, popularityRaw: 50.0, freshnessRaw: 10.0),
            ],
        ];

        $results = $calculator->calculateBatch($signals);
        $dualPopularity = $results['dual@example']['components']->popularity;

        $egoRankOfFifty = $calculator->percentileRank(50.0, [10.0, 90.0, 50.0]);
        $githubRankOfFifty = $calculator->percentileRank(50.0, [10.0, 90.0, 50.0]);

        self::assertLessThanOrEqual(100, $dualPopularity);
        self::assertSame(max($egoRankOfFifty, $githubRankOfFifty), $dualPopularity);
        self::assertNotSame($egoRankOfFifty + $githubRankOfFifty, $dualPopularity);
    }

    public function testCalculateBatchCombinesComponentsWithConfiguredWeights(): void
    {
        $calculator = new ExtensionScoreCalculator();

        $signals = [
            'solo@example' => [new SourceRawSignal(ExtensionSource::TYPE_EGO, popularityRaw: 1.0, freshnessRaw: 1.0)],
        ];

        $results = $calculator->calculateBatch($signals);

        // Sole member of every population it participates in: both
        // components rank at 100, so score must be the full 100 too.
        self::assertSame(100, $results['solo@example']['components']->popularity);
        self::assertSame(100, $results['solo@example']['components']->freshness);
        self::assertSame(100, $results['solo@example']['score']);
    }

    public function testWeightsAreCentrallyConfigurableViaConstructor(): void
    {
        $popularityOnly = new ExtensionScoreCalculator(popularityWeight: 1.0, freshnessWeight: 0.0);

        $signals = [
            'solo@example' => [new SourceRawSignal(ExtensionSource::TYPE_EGO, popularityRaw: 5.0, freshnessRaw: 5.0)],
            'other@example' => [new SourceRawSignal(ExtensionSource::TYPE_EGO, popularityRaw: 1.0, freshnessRaw: 5.0)],
        ];

        $results = $popularityOnly->calculateBatch($signals);

        // With freshness weighted at 0, score must equal popularity alone.
        self::assertSame($results['solo@example']['components']->popularity, $results['solo@example']['score']);
        self::assertSame($results['other@example']['components']->popularity, $results['other@example']['score']);
    }

    public function testCalculateBatchScoreStaysWithinZeroToHundred(): void
    {
        $calculator = new ExtensionScoreCalculator();

        $signals = [
            'bottom@example' => [new SourceRawSignal(ExtensionSource::TYPE_EGO, popularityRaw: 1.0, freshnessRaw: 1.0)],
            'top@example' => [new SourceRawSignal(ExtensionSource::TYPE_EGO, popularityRaw: 999.0, freshnessRaw: 999.0)],
        ];

        $results = $calculator->calculateBatch($signals);

        foreach ($results as $result) {
            self::assertGreaterThanOrEqual(0, $result['score']);
            self::assertLessThanOrEqual(100, $result['score']);
            self::assertGreaterThanOrEqual(0, $result['components']->popularity);
            self::assertLessThanOrEqual(100, $result['components']->popularity);
            self::assertGreaterThanOrEqual(0, $result['components']->freshness);
            self::assertLessThanOrEqual(100, $result['components']->freshness);
        }
    }
}
