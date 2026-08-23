<?php

namespace App\Dto;

/**
 * Data Transfer Object for an extension item in the public snapshot v2.
 *
 * The GNOME UUID is the extension's only public identity: there is no
 * numeric `pk`, no `slug`, and no cross-source `gnomeUrl`/`installUrl`.
 * Source-specific links and metrics live exclusively on the entries of
 * `sources`.
 */
final class ExtensionSnapshotItem
{
    /**
     * @param string[] $supportedShellVersions canonical union of every source's validated shell versions
     * @param SourceSnapshotItem[] $sources at least one entry; EGO and/or GitHub
     * @param int $trendScore source-neutral 7-day trend rank (0-100); 0 means not trend-eligible (see ExtensionTrendCalculator)
     */
    public function __construct(
        public string $uuid,
        public string $path,
        public string $name,
        public string $description,
        public string $creator,
        public ?string $creatorUrl,
        public array $supportedShellVersions,
        public string $createdAt,
        public string $updatedAt,
        public int $recentSortValue,
        public int $score,
        public ScoreComponents $scoreComponents,
        public array $sources,
        public bool $hasScreenshot,
        public int $trendScore = 0,
    ) {
    }
}
