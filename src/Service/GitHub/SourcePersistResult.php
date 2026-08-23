<?php

namespace App\Service\GitHub;

use App\Entity\ExtensionSource;

/**
 * Outcome of a single GitHub candidate persistence attempt.
 *
 * Known skip reasons:
 * - "duplicate_external_identifier": the (github, repository id) pair already belongs to
 *   a different Extension.
 */
final class SourcePersistResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $skipReason,
        public readonly ?ExtensionSource $source,
    ) {
    }

    public static function success(ExtensionSource $source): self
    {
        return new self(true, null, $source);
    }

    public static function skip(string $reason): self
    {
        return new self(false, $reason, null);
    }
}
