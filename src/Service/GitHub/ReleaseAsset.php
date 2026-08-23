<?php

namespace App\Service\GitHub;

final class ReleaseAsset
{
    public function __construct(
        public readonly string $name,
        public readonly string $downloadUrl,
    ) {
    }

    public function isZip(): bool
    {
        return str_ends_with(strtolower($this->name), '.zip');
    }
}
