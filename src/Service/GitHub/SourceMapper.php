<?php

namespace App\Service\GitHub;

use App\Entity\ExtensionSource;
use App\Entity\SourceMetricMeasurement;
use DateTimeInterface;

/**
 * Pure GitHub candidate -> ExtensionSource mapping, free of Doctrine
 * persistence calls so it stays testable without a database connection.
 *
 * Unlike the EGO source, GitHub never contributes downloads, ratings, or
 * comment counts: those metric types are simply never built here.
 *
 * The display screenshot and display icon are both taken from the
 * candidate, which the caller has already resolved and validated via
 * {@see ScreenshotResolver} and {@see IconResolver}
 * respectively; no fetching or image validation happens in this mapping
 * step.
 */
final class SourceMapper
{
    public function externalIdentifierFor(ExtensionCandidate $candidate): string
    {
        return (string) $candidate->repositoryId;
    }

    /**
     * Map a candidate onto an ExtensionSource, updating $existing in place when given
     * so callers never allocate a second GitHub source for the same extension.
     *
     * Note: the caller is responsible for setting $source->extension, since this
     * candidate carries no reference to the canonical Extension.
     */
    public function mapToSource(ExtensionCandidate $candidate, ?ExtensionSource $existing = null, ?DateTimeInterface $now = null): ExtensionSource
    {
        $source = $existing ?? new ExtensionSource();
        $now = $now ?? new \DateTime();

        if ($source->createdAt === null) {
            $source->createdAt = \DateTime::createFromInterface($now);
        }

        $source->sourceType = ExtensionSource::TYPE_GITHUB;
        $source->externalIdentifier = $this->externalIdentifierFor($candidate);
        $source->sourceUrl = $candidate->htmlUrl;
        $source->installUrl = $candidate->installUrl;
        $source->displayName = $this->firstNonEmpty($candidate->metadataName, $candidate->fullName);
        $source->displayDescription = $this->firstNonEmpty($candidate->metadataDescription, $candidate->description);
        $source->displayScreenshot = $candidate->screenshotUrl;
        $source->displayIcon = $candidate->iconUrl;
        $source->supportedShellVersions = $this->normalizeShellVersions($candidate->shellVersion);
        $source->lastCommitAt = $candidate->lastCommitAt !== null ? \DateTime::createFromInterface($candidate->lastCommitAt) : null;
        $source->lastReleaseAt = $candidate->lastReleaseAt !== null ? \DateTime::createFromInterface($candidate->lastReleaseAt) : null;
        $source->updatedAt = \DateTime::createFromInterface($now);

        return $source;
    }

    /**
     * Build the current GitHub source metrics (stars, forks) as separate,
     * never-swapped measurements. GitHub contributes no downloads, rating,
     * or rating-count measurements: those types are never produced here.
     *
     * @return SourceMetricMeasurement[]
     */
    public function buildMetricMeasurements(ExtensionSource $source, ExtensionCandidate $candidate, DateTimeInterface $measuredAt): array
    {
        return [
            $this->buildMeasurement($source, SourceMetricMeasurement::METRIC_STARS, (float) $candidate->stargazersCount, $measuredAt),
            $this->buildMeasurement($source, SourceMetricMeasurement::METRIC_FORKS, (float) $candidate->forksCount, $measuredAt),
        ];
    }

    /**
     * Normalize the raw `shell-version` metadata value (string or array) into a
     * list of non-empty string versions. Non-scalar or empty elements are
     * filtered out rather than causing a hard failure, since metadata.json is
     * third-party content.
     *
     * @return string[]
     */
    public function normalizeShellVersions(array|string $shellVersion): array
    {
        if (is_string($shellVersion)) {
            $trimmed = trim($shellVersion);

            return $trimmed === '' ? [] : [$trimmed];
        }

        $normalized = [];
        foreach ($shellVersion as $element) {
            $version = $this->normalizeShellVersionElement($element);
            if ($version !== null) {
                $normalized[] = $version;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeShellVersionElement(mixed $element): ?string
    {
        if (is_string($element)) {
            $trimmed = trim($element);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_int($element) || is_float($element)) {
            return (string) $element;
        }

        return null;
    }

    private function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function buildMeasurement(ExtensionSource $source, string $metricType, float $value, DateTimeInterface $measuredAt): SourceMetricMeasurement
    {
        $measurement = new SourceMetricMeasurement();
        $measurement->source = $source;
        $measurement->metricType = $metricType;
        $measurement->value = $value;
        $measurement->measuredAt = $measuredAt;

        return $measurement;
    }
}
