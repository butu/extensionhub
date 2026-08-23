<?php

namespace App\Dto;

/**
 * Raw, source-type-scoped trend input for a single ExtensionSource, before
 * eligibility gating and growth-ratio normalization.
 *
 * `delta7d` is null when the source has no 7-day baseline yet (a brand new
 * source). `ExtensionTrendCalculator` prefers `delta7d` and falls back to
 * `delta1d` only when `delta7d` is null -- a present-but-too-small 7-day
 * delta is judged on its own merits and never replaced by `delta1d`. A
 * source with neither delta is never trend-eligible, never treated as a
 * zero delta.
 *
 * `currentValue` is the source's latest metric value (EGO downloads or
 * GitHub stars) at snapshot time; it's the denominator of the relative
 * growth ratio `delta / max(currentValue, floor)` that drives ranking.
 */
final class SourceTrendSignal
{
    public function __construct(
        public readonly string $sourceType,
        public readonly ?float $delta7d,
        public readonly ?float $delta1d = null,
        public readonly float $currentValue = 0.0,
    ) {
    }
}
