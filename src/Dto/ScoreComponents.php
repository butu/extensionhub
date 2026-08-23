<?php

namespace App\Dto;

/**
 * The two normalized (0-100) components that make up an extension's public
 * `score`. Both are source-internal percentile ranks, never raw values, so
 * an EGO rating and a GitHub star count are never compared directly.
 */
final class ScoreComponents
{
    public function __construct(
        public readonly int $popularity,
        public readonly int $freshness,
    ) {
    }
}
