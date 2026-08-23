<?php

namespace App\Service;

use App\Dto\SourceTrendSignal;
use App\Entity\ExtensionSource;

/**
 * Pure, DB-free computation of the public snapshot's top-level `trendScore`
 * (0-100 int; 0 means "not trend-eligible").
 *
 * Mirrors the pre-v2 relative-velocity trending model: eligibility and
 * magnitude are driven by *growth ratio* (recent delta relative to current
 * size), not by the absolute size of the delta itself. This keeps a small,
 * fast-growing extension competitive against a much larger one that merely
 * moves more units in absolute terms.
 *
 * For each source:
 * - The 7-day delta is preferred; the 1-day delta is used only as a
 *   fallback when no 7-day baseline exists yet (`delta7d === null`). A
 *   present-but-too-small 7-day delta does NOT fall back to the 1-day
 *   value -- it is simply not eligible.
 * - A minimum absolute delta gates eligibility per source type and window
 *   (EGO downloads: >=50 for the 7-day window, >=10 for the 1-day
 *   fallback; GitHub stars: >=1 for both). A source with neither delta is
 *   never eligible.
 * - An eligible source's growth ratio is `delta / max(currentValue, floor)`,
 *   where `floor` is a per-source-type constant (EGO downloads: 1000,
 *   GitHub stars: 25) that keeps tiny extensions/repos from posting
 *   artificially explosive ratios off a near-zero denominator.
 *
 * EGO downloads and GitHub stars aren't directly comparable, so each
 * source type's growth ratios are normalized as a percentile rank against
 * every other ratio of the *same* source type only (mirrors
 * ExtensionScoreCalculator). When an extension has signals from more than
 * one source, `trendScore` takes the best (max) normalized value across
 * its sources, never their sum.
 */
final class ExtensionTrendCalculator
{
    /**
     * Per-source-type minimum absolute delta for trend eligibility, one
     * per window: the 7-day delta is preferred, the 1-day delta is only a
     * fallback when no 7-day baseline exists.
     */
    public const EGO_MIN_DOWNLOADS_DELTA_7D = 50.0;
    public const EGO_MIN_DOWNLOADS_DELTA_1D = 10.0;
    public const GITHUB_MIN_STARS_DELTA_7D = 1.0;
    public const GITHUB_MIN_STARS_DELTA_1D = 1.0;

    /**
     * Per-source-type denominator floor for the growth ratio, guarding
     * against near-zero current values inflating the ratio.
     */
    public const EGO_DOWNLOADS_FLOOR = 1000.0;
    public const GITHUB_STARS_FLOOR = 25.0;

    public function __construct(
        private readonly PercentileRanker $percentileRanker = new PercentileRanker(),
    ) {
    }

    /**
     * @param array<string, SourceTrendSignal[]> $signalsByUuid canonical uuid => trend signals from that extension's sources
     * @return array<string, int> uuid => trendScore (0-100), same keys as input
     */
    public function calculateBatch(array $signalsByUuid): array
    {
        $populations = $this->collectPopulations($signalsByUuid);

        $results = [];
        foreach ($signalsByUuid as $uuid => $signals) {
            $trendScore = 0;

            foreach ($signals as $signal) {
                if (!$this->isEligible($signal)) {
                    continue;
                }

                $rank = $this->percentileRanker->rank(
                    $this->growthRatio($signal),
                    $populations[$signal->sourceType] ?? []
                );
                $trendScore = max($trendScore, $rank);
            }

            $results[$uuid] = $trendScore;
        }

        return $results;
    }

    /**
     * The delta this signal is judged on: the 7-day delta when a baseline
     * exists, otherwise the 1-day delta as a fallback. A present
     * (non-null) 7-day delta is always used as-is, even when it's too
     * small to be eligible; it never falls back to the 1-day value.
     */
    private function effectiveDelta(SourceTrendSignal $signal): ?float
    {
        return $signal->delta7d ?? $signal->delta1d;
    }

    /**
     * Whether this signal's effective delta clears its source type's
     * minimum threshold for the window it was drawn from. A signal with
     * neither a 7-day nor a 1-day delta is never eligible.
     */
    private function isEligible(SourceTrendSignal $signal): bool
    {
        $delta = $this->effectiveDelta($signal);
        if ($delta === null) {
            return false;
        }

        $threshold = $this->minimumDeltaFor($signal);
        if ($threshold === null) {
            return false;
        }

        return $delta >= $threshold;
    }

    /**
     * The eligibility threshold for this signal's source type and window
     * (7-day baseline present vs. 1-day fallback).
     */
    private function minimumDeltaFor(SourceTrendSignal $signal): ?float
    {
        $usingSevenDayWindow = $signal->delta7d !== null;

        return match ($signal->sourceType) {
            ExtensionSource::TYPE_EGO => $usingSevenDayWindow
                ? self::EGO_MIN_DOWNLOADS_DELTA_7D
                : self::EGO_MIN_DOWNLOADS_DELTA_1D,
            ExtensionSource::TYPE_GITHUB => $usingSevenDayWindow
                ? self::GITHUB_MIN_STARS_DELTA_7D
                : self::GITHUB_MIN_STARS_DELTA_1D,
            default => null,
        };
    }

    private function floorFor(string $sourceType): ?float
    {
        return match ($sourceType) {
            ExtensionSource::TYPE_EGO => self::EGO_DOWNLOADS_FLOOR,
            ExtensionSource::TYPE_GITHUB => self::GITHUB_STARS_FLOOR,
            default => null,
        };
    }

    /**
     * Relative growth ratio: the effective delta (clamped to >= 0, so
     * negative or missing deltas never produce a negative ratio) divided
     * by the source's current size, floored at a per-source-type constant
     * so small sources can't post artificially explosive ratios off a
     * near-zero denominator. Unknown source types always yield 0.
     */
    private function growthRatio(SourceTrendSignal $signal): float
    {
        $floor = $this->floorFor($signal->sourceType);
        if ($floor === null) {
            return 0.0;
        }

        $delta = max(0.0, $this->effectiveDelta($signal) ?? 0.0);
        $denominator = max($signal->currentValue, $floor);

        return $delta / $denominator;
    }

    /**
     * Only trend-eligible signals contribute to the ranking population.
     * Non-eligible signals (missing baseline, below threshold, negative
     * delta) are never trend-eligible and must never enter the population
     * as a "0" ratio either -- doing so would dilute the population with
     * values every genuinely eligible (always positive) ratio sits above,
     * inflating those ratios' percentile rank for free.
     *
     * @param array<string, SourceTrendSignal[]> $signalsByUuid
     * @return array<string, float[]> sourceType => growth ratios, one per eligible source of that type
     */
    private function collectPopulations(array $signalsByUuid): array
    {
        $populations = [];
        foreach ($signalsByUuid as $signals) {
            foreach ($signals as $signal) {
                if (!$this->isEligible($signal)) {
                    continue;
                }

                $populations[$signal->sourceType][] = $this->growthRatio($signal);
            }
        }

        return $populations;
    }
}
