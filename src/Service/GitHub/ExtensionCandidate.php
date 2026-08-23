<?php

namespace App\Service\GitHub;

use DateTimeInterface;

/**
 * Already-loaded, validated facts about a single GitHub candidate, ready to
 * be mapped onto an ExtensionSource. Nothing in this class fetches data:
 * repository facts, the matched metadata.json contents, and the selected
 * release are all supplied by the caller after discovery/validation.
 *
 * All three timestamps are facts reported by GitHub, never the time of the
 * import run: $repositoryCreatedAt is the repository's own creation date,
 * $lastCommitAt its last push, $lastReleaseAt the selected release's
 * publish date. A null value means GitHub reported none, so consumers must
 * not substitute "now" for it.
 */
final class ExtensionCandidate
{
    /**
     * @param array|string $shellVersion raw `shell-version` value from metadata.json, as validated by MetadataValidator
     */
    public function __construct(
        public readonly int $repositoryId,
        public readonly string $fullName,
        public readonly string $htmlUrl,
        public readonly int $stargazersCount,
        public readonly int $forksCount,
        public readonly string $uuid,
        public readonly array|string $shellVersion,
        public readonly ?string $description = null,
        public readonly ?string $ownerLogin = null,
        public readonly ?string $ownerHtmlUrl = null,
        public readonly ?string $metadataName = null,
        public readonly ?string $metadataDescription = null,
        public readonly ?DateTimeInterface $repositoryCreatedAt = null,
        public readonly ?DateTimeInterface $lastCommitAt = null,
        public readonly ?string $installUrl = null,
        public readonly ?DateTimeInterface $lastReleaseAt = null,
        public readonly ?string $screenshotUrl = null,
        public readonly ?string $iconUrl = null,
    ) {
    }
}
