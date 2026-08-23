<?php

namespace App\Service\GitHub;

/**
 * Outcome of {@see CandidateProcessor::process()}: either a fully-built
 * candidate ready for {@see SourcePersister}, or a skip with its reason.
 */
final class CandidateProcessResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?ExtensionCandidate $candidate,
        public readonly ?string $skipReason,
    ) {
    }

    public static function success(ExtensionCandidate $candidate): self
    {
        return new self(true, $candidate, null);
    }

    public static function skip(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
