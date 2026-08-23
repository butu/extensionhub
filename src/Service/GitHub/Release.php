<?php

namespace App\Service\GitHub;

use DateTimeImmutable;

final class Release
{
    /**
     * @param ReleaseAsset[] $assets
     */
    public function __construct(
        public readonly string $tagName,
        public readonly bool $draft,
        public readonly bool $prerelease,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly array $assets,
    ) {
    }
}
