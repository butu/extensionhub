<?php

namespace App\Service\GitHub;

final class EligibilityResult
{
    private function __construct(
        public readonly bool $eligible,
        public readonly ?string $skipReason,
    ) {
    }

    public static function eligible(): self
    {
        return new self(true, null);
    }

    public static function skip(string $reason): self
    {
        return new self(false, $reason);
    }
}
