<?php

namespace App\Service;

use App\Dto\ScoreComponents;
use App\Dto\SourceRawSignal;

/**
 * Pure, DB-free computation of the public snapshot `score` and its
 * `popularity`/`freshness` components.
 *
 * Each raw signal is normalized as a percentile rank (0-100) against every
 * other signal of the *same* source type only: an EGO rating and a GitHub
 * star count are never compared directly. When an extension has signals
 * from more than one source, each component takes the best (max) normalized
 * value across its sources, never their sum, so a dual-source extension is
 * not rewarded twice for the same kind of popularity or freshness.
 */
final class ExtensionScoreCalculator
{
    /**
     * Defaults per the score & snapshot contract: popularity 60%, freshness 40%.
     * Centrally configurable via services.yaml (App\Service\ExtensionScoreCalculator arguments).
     */
    public const DEFAULT_POPULARITY_WEIGHT = 0.6;
    public const DEFAULT_FRESHNESS_WEIGHT = 0.4;

    public function __construct(
        private readonly float $popularityWeight = self::DEFAULT_POPULARITY_WEIGHT,
        private readonly float $freshnessWeight = self::DEFAULT_FRESHNESS_WEIGHT,
        private readonly PercentileRanker $percentileRanker = new PercentileRanker(),
    ) {
    }

    /**
     * @param array<string, SourceRawSignal[]> $signalsByUuid canonical uuid => raw signals from that extension's sources
     * @return array<string, array{score: int, components: ScoreComponents}> keyed by the same uuid
     */
    public function calculateBatch(array $signalsByUuid): array
    {
        $popularityPopulations = $this->collectPopulations($signalsByUuid, static fn (SourceRawSignal $signal): float => $signal->popularityRaw);
        $freshnessPopulations = $this->collectPopulations($signalsByUuid, static fn (SourceRawSignal $signal): float => $signal->freshnessRaw);

        $results = [];
        foreach ($signalsByUuid as $uuid => $signals) {
            $popularity = 0;
            $freshness = 0;

            foreach ($signals as $signal) {
                $popularity = max($popularity, $this->percentileRanker->rank(
                    $signal->popularityRaw,
                    $popularityPopulations[$signal->sourceType] ?? []
                ));
                $freshness = max($freshness, $this->percentileRanker->rank(
                    $signal->freshnessRaw,
                    $freshnessPopulations[$signal->sourceType] ?? []
                ));
            }

            $results[$uuid] = [
                'score' => $this->combine($popularity, $freshness),
                'components' => new ScoreComponents(popularity: $popularity, freshness: $freshness),
            ];
        }

        return $results;
    }

    /**
     * Compatibility delegate for existing callers/tests: percentile rank of
     * $value within $population, now computed by the shared PercentileRanker.
     *
     * @param float[] $population
     */
    public function percentileRank(float $value, array $population): int
    {
        return $this->percentileRanker->rank($value, $population);
    }

    private function combine(int $popularity, int $freshness): int
    {
        $score = (int) round($popularity * $this->popularityWeight + $freshness * $this->freshnessWeight);

        return max(0, min(100, $score));
    }

    /**
     * @param array<string, SourceRawSignal[]> $signalsByUuid
     * @param callable(SourceRawSignal): float $valueOf
     * @return array<string, float[]> sourceType => raw values
     */
    private function collectPopulations(array $signalsByUuid, callable $valueOf): array
    {
        $populations = [];
        foreach ($signalsByUuid as $signals) {
            foreach ($signals as $signal) {
                $populations[$signal->sourceType][] = $valueOf($signal);
            }
        }

        return $populations;
    }
}
