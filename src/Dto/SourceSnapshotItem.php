<?php

namespace App\Dto;

/**
 * A single source's public snapshot representation, embedded in the
 * `sources` array of a v2 ExtensionSnapshotItem.
 *
 * Links and metrics are source-specific by design (see the score/snapshot
 * contract todo): EGO offers `pageUrl`/`installUrl`, GitHub offers
 * `repositoryUrl`/`releaseUrl`. `metrics` only ever contains keys the source
 * actually measured; a missing GitHub rating or EGO star count is omitted
 * entirely rather than serialized as `0` or `null`.
 */
final class SourceSnapshotItem
{
    /**
     * @param string[] $supportedShellVersions
     * @param array<string, string> $links source-specific link keys, e.g. ['pageUrl' => ..., 'installUrl' => ...]
     * @param array<string, int|float> $metrics source-specific metric keys, present only when measured
     */
    public function __construct(
        public readonly string $sourceType,
        public readonly string $externalIdentifier,
        public readonly ?string $displayName,
        public readonly ?string $displayDescription,
        public readonly ?string $displayIcon,
        public readonly ?string $displayScreenshot,
        public readonly array $supportedShellVersions,
        public readonly ?string $lastCommitAt,
        public readonly ?string $lastReleaseAt,
        public readonly array $links,
        public readonly array $metrics,
    ) {
    }
}
