<?php

namespace App\Service\GitHub;

/**
 * Outcome of validating a repository's `metadata.json`.
 *
 * Besides the fields that decide acceptance (`uuid`, `shell-version`), a
 * valid result also carries the extension's self-declared `name` and
 * `description`. Those are the extension author's own wording and are
 * therefore preferred over the repository's `full_name`/`description`
 * when naming a GitHub source; they are optional, so a missing or blank
 * value is reported as null rather than blocking the candidate.
 */
final class MetadataValidationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly ?string $skipReason,
        public readonly ?string $matchedPath,
        public readonly ?string $uuid,
        public readonly array|string|null $shellVersion,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
    ) {
    }

    public static function valid(
        string $matchedPath,
        string $uuid,
        array|string $shellVersion,
        ?string $name = null,
        ?string $description = null,
    ): self {
        return new self(true, null, $matchedPath, $uuid, $shellVersion, $name, $description);
    }

    public static function skip(string $reason, ?string $matchedPath = null): self
    {
        return new self(false, $reason, $matchedPath, null, null);
    }
}
