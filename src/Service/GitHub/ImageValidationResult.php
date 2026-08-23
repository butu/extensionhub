<?php

namespace App\Service\GitHub;

final class ImageValidationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly ?string $reason,
    ) {
    }

    public static function valid(): self
    {
        return new self(true, null);
    }

    public static function invalid(string $reason): self
    {
        return new self(false, $reason);
    }
}
