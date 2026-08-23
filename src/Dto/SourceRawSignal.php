<?php

namespace App\Dto;

/**
 * Raw, source-type-scoped popularity/freshness inputs for a single
 * ExtensionSource, before percentile normalization.
 *
 * Deliberately dumb data: computing the raw values from entities/measurements
 * is the caller's job, so this DTO (and the calculator that consumes it) stay
 * testable with plain fixed numbers.
 */
final class SourceRawSignal
{
    public function __construct(
        public readonly string $sourceType,
        public readonly float $popularityRaw,
        public readonly float $freshnessRaw,
    ) {
    }
}
