<?php

namespace App\Service;

use App\Entity\Extension;
use App\Entity\ExtensionComment;
use App\Entity\ExtensionDownloadMeasurement;
use App\Entity\ExtensionSource;
use App\Entity\SourceMetricMeasurement;
use DateTimeInterface;

/**
 * Pure EGO -> ExtensionSource mapping and reassignment logic, free of Doctrine
 * persistence calls so it stays testable without a database connection.
 */
final class EgoSourceMapper
{
    private const DEFAULT_ICON_PATH = '/static/images/plugin.png';

    public function externalIdentifierFor(Extension $extension): string
    {
        return (string) $extension->pk;
    }

    /**
     * Map an Extension onto an ExtensionSource, updating $existing in place when given
     * so callers never allocate a second EGO source for the same extension.
     */
    public function mapToSource(Extension $extension, ?ExtensionSource $existing = null, ?DateTimeInterface $now = null): ExtensionSource
    {
        $source = $existing ?? new ExtensionSource();
        $now = $now ?? new \DateTime();

        if ($source->createdAt === null) {
            $source->createdAt = \DateTime::createFromInterface($now);
        }

        $source->extension = $extension;
        $source->sourceType = ExtensionSource::TYPE_EGO;
        $source->externalIdentifier = $this->externalIdentifierFor($extension);
        $source->sourceUrl = $extension->link;
        $source->installUrl = $extension->sourceUrl;
        $source->displayName = $extension->name;
        $source->displayDescription = $extension->description;
        $source->displayIcon = $this->isDefaultIcon($extension->icon) ? null : $extension->icon;
        $source->displayScreenshot = $extension->screenshot;
        $source->supportedShellVersions = $extension->supportedShellVersions;
        $source->lastReleaseAt = $extension->lastChange;
        $source->updatedAt = \DateTime::createFromInterface($now);

        return $source;
    }

    public function isDefaultIcon(?string $iconPath): bool
    {
        return $iconPath === self::DEFAULT_ICON_PATH;
    }

    public function validateExtensionForBackfill(Extension $extension): ?string
    {
        if ($extension->uuid === null || $extension->uuid === '') {
            return 'missing_uuid';
        }

        if ($extension->pk === null || $extension->pk <= 0) {
            return 'missing_pk';
        }

        return null;
    }

    /**
     * Reassign download measurements that have no source yet. Rows already pointing
     * to a (possibly different) source are left untouched.
     *
     * @param iterable<ExtensionDownloadMeasurement> $measurements
     */
    public function reassignDownloadMeasurements(ExtensionSource $source, iterable $measurements): int
    {
        $changed = 0;
        foreach ($measurements as $measurement) {
            if ($measurement->source !== null) {
                continue;
            }

            $measurement->source = $source;
            $changed++;
        }

        return $changed;
    }

    /**
     * @param iterable<ExtensionComment> $comments
     */
    public function reassignComments(ExtensionSource $source, iterable $comments): int
    {
        $changed = 0;
        foreach ($comments as $comment) {
            if ($comment->source !== null) {
                continue;
            }

            $comment->source = $source;
            $changed++;
        }

        return $changed;
    }

    /**
     * Map download history rows to METRIC_DOWNLOADS measurements. Includes rows
     * already source_id-linked from prior backfill runs that were reassigned but
     * never copied into source_metric_measurement.
     *
     * @param iterable<ExtensionDownloadMeasurement> $measurements
     * @return SourceMetricMeasurement[]
     */
    public function buildDownloadHistoryMeasurements(ExtensionSource $source, iterable $measurements): array
    {
        $result = [];
        foreach ($measurements as $measurement) {
            $result[] = $this->buildMeasurement($source, SourceMetricMeasurement::METRIC_DOWNLOADS, (float) $measurement->downloads, $measurement->measuredAt);
        }

        return $result;
    }

    /**
     * Build the current EGO source metrics (downloads, rating, rating count) as
     * separate, never-swapped measurements. Metrics with a null underlying value
     * are omitted rather than stored as zero.
     *
     * @return SourceMetricMeasurement[]
     */
    public function buildMetricMeasurements(ExtensionSource $source, Extension $extension, DateTimeInterface $measuredAt): array
    {
        $measurements = [];

        if ($extension->downloads !== null) {
            $measurements[] = $this->buildMeasurement($source, SourceMetricMeasurement::METRIC_DOWNLOADS, (float) $extension->downloads, $measuredAt);
        }

        if ($extension->rating !== null) {
            $measurements[] = $this->buildMeasurement($source, SourceMetricMeasurement::METRIC_RATING, (float) $extension->rating, $measuredAt);
        }

        if ($extension->comments !== null) {
            $measurements[] = $this->buildMeasurement($source, SourceMetricMeasurement::METRIC_RATING_COUNT, (float) $extension->comments, $measuredAt);
        }

        return $measurements;
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
