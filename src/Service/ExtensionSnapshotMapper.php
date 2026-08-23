<?php

namespace App\Service;

use App\Dto\ExtensionSnapshotItem;
use App\Dto\ScoreComponents;
use App\Dto\SourceRawSignal;
use App\Dto\SourceSnapshotItem;
use App\Entity\Extension;
use App\Entity\ExtensionSource;
use App\Entity\SourceMetricMeasurement;
use DateTime;
use DateTimeInterface;

/**
 * Pure Extension/ExtensionSource -> snapshot v2 DTO mapping, free of Doctrine
 * persistence calls so it stays testable without a database connection.
 *
 * Score is deliberately left at zero/empty by mapExtension(): the actual
 * popularity/freshness percentile ranks can only be computed once every
 * extension in the batch is known (see ExtensionScoreCalculator), so the
 * builder fills score/scoreComponents in afterwards.
 */
final class ExtensionSnapshotMapper
{
    /**
     * Build the raw (pre-normalization) popularity/freshness signal for one
     * source, from whichever metrics are actually available for it.
     *
     * @param array<string, float> $metrics metricType => value, for this source only
     */
    public function buildRawSignal(ExtensionSource $source, array $metrics, ?DateTimeInterface $now = null): SourceRawSignal
    {
        return new SourceRawSignal(
            sourceType: $source->sourceType ?? '',
            popularityRaw: $this->popularityRaw($source->sourceType ?? '', $metrics),
            freshnessRaw: (float) $this->freshnessTimestamp($source, $now),
        );
    }

    /**
     * @param array<string, float> $metrics
     */
    public function popularityRaw(string $sourceType, array $metrics): float
    {
        if ($sourceType === ExtensionSource::TYPE_EGO) {
            return ($metrics[SourceMetricMeasurement::METRIC_DOWNLOADS] ?? 0.0)
                + ($metrics[SourceMetricMeasurement::METRIC_RATING] ?? 0.0) * 1000
                + ($metrics[SourceMetricMeasurement::METRIC_RATING_COUNT] ?? 0.0) * 50;
        }

        if ($sourceType === ExtensionSource::TYPE_GITHUB) {
            return ($metrics[SourceMetricMeasurement::METRIC_STARS] ?? 0.0)
                + ($metrics[SourceMetricMeasurement::METRIC_FORKS] ?? 0.0) * 2;
        }

        return 0.0;
    }

    /**
     * First timestamp that is actually known, in the caller's order of
     * preference. 0 stands for "unknown", so it is skipped rather than
     * treated as 1970.
     */
    private function firstKnownTimestamp(int ...$timestamps): int
    {
        foreach ($timestamps as $timestamp) {
            if ($timestamp !== 0) {
                return $timestamp;
            }
        }

        return 0;
    }

    /**
     * Latest known activity timestamp for a source: last commit or last
     * release, whichever is newer.
     *
     * Deliberately ignores `ExtensionSource::$updatedAt`. That column is row
     * bookkeeping — every import run stamps it with the current time — so
     * including it made this method return the import time for every source,
     * flattening `freshness`, `updatedAt` and the "recently updated" sort
     * order into "whenever the importer last ran". Only facts reported by the
     * source itself count as activity here; 0 means the source reported none.
     */
    public function freshnessTimestamp(ExtensionSource $source, ?DateTimeInterface $now = null): int
    {
        $nowTimestamp = ($now ?? new DateTime('UTC'))->getTimestamp();

        $candidates = [
            $this->nonFutureTimestamp($source->lastCommitAt?->getTimestamp(), $nowTimestamp),
            $this->nonFutureTimestamp($source->lastReleaseAt?->getTimestamp(), $nowTimestamp),
        ];
        $candidates = array_values(array_filter($candidates, static fn (int $ts): bool => $ts !== 0));

        return $candidates === [] ? 0 : max($candidates);
    }

    /**
     * A future timestamp is not a real fact yet (e.g. a preallocated EGO
     * PK's overshot estimate), so it counts as "unknown" (0) rather than
     * inflating freshness/recency.
     */
    private function nonFutureTimestamp(?int $timestamp, int $nowTimestamp): int
    {
        if ($timestamp === null || $timestamp > $nowTimestamp) {
            return 0;
        }

        return $timestamp;
    }

    /**
     * @param array<string, float> $metrics metricType => value, for this source only
     * @param array<string, array{delta1d: ?float, delta7d: ?float, delta30d: ?float}> $trendDeltas metricType => deltas, for this source only
     */
    public function mapSource(ExtensionSource $source, array $metrics, string $extensionUuid, array $trendDeltas = [], ?DateTimeInterface $now = null): SourceSnapshotItem
    {
        $nowTimestamp = ($now ?? new DateTime('UTC'))->getTimestamp();

        return new SourceSnapshotItem(
            sourceType: $source->sourceType ?? '',
            externalIdentifier: $source->externalIdentifier ?? '',
            displayName: $source->displayName,
            displayDescription: $source->displayDescription,
            displayIcon: $source->displayIcon,
            displayScreenshot: $source->displayScreenshot,
            supportedShellVersions: $source->supportedShellVersions ?? [],
            lastCommitAt: $this->formatIfNotFuture($source->lastCommitAt, $nowTimestamp),
            lastReleaseAt: $this->formatIfNotFuture($source->lastReleaseAt, $nowTimestamp),
            links: $this->buildLinks($source, $extensionUuid),
            metrics: $this->buildMetrics($source->sourceType ?? '', $metrics, $trendDeltas),
        );
    }

    /**
     * A future or epoch-sentinel date (see Extension::nonFutureDate()) is
     * not a real fact and must never be displayed as-is; null (this
     * field's existing "unknown") is safer than a fabricated date.
     */
    private function formatIfNotFuture(?DateTimeInterface $date, int $nowTimestamp): ?string
    {
        if ($date === null) {
            return null;
        }

        $timestamp = $date->getTimestamp();
        if ($timestamp === 0 || $timestamp > $nowTimestamp) {
            return null;
        }

        return $date->format('c');
    }

    /**
     * Resolve the absolute EGO detail page URL from the (possibly relative,
     * possibly empty) EGO source URL, falling back to a pk-based URL.
     */
    public function resolveEgoPageUrl(?string $sourceUrl, ?string $externalIdentifier): string
    {
        if ($sourceUrl !== null && $sourceUrl !== '') {
            if (str_starts_with($sourceUrl, 'http://') || str_starts_with($sourceUrl, 'https://')) {
                return $sourceUrl;
            }

            if (str_starts_with($sourceUrl, '/')) {
                return 'https://extensions.gnome.org' . $sourceUrl;
            }
        }

        return sprintf('https://extensions.gnome.org/extension/%s/', $externalIdentifier ?? '');
    }

    /**
     * @return array<string, string>
     */
    private function buildLinks(ExtensionSource $source, string $extensionUuid): array
    {
        if ($source->sourceType === ExtensionSource::TYPE_EGO) {
            return [
                'pageUrl' => $this->resolveEgoPageUrl($source->sourceUrl, $source->externalIdentifier),
                'installUrl' => sprintf('gnome-extensions://%s?action=install', rawurlencode($extensionUuid)),
            ];
        }

        if ($source->sourceType === ExtensionSource::TYPE_GITHUB) {
            $links = ['repositoryUrl' => $source->sourceUrl ?? ''];
            if ($source->installUrl !== null && $source->installUrl !== '') {
                $links['releaseUrl'] = $source->installUrl;
            }

            return $links;
        }

        return [];
    }

    /**
     * @param array<string, float> $metrics
     * @param array<string, array{delta1d: ?float, delta7d: ?float, delta30d: ?float}> $trendDeltas metricType => deltas
     * @return array<string, int|float>
     */
    private function buildMetrics(string $sourceType, array $metrics, array $trendDeltas = []): array
    {
        if ($sourceType === ExtensionSource::TYPE_EGO) {
            $result = [];
            if (array_key_exists(SourceMetricMeasurement::METRIC_DOWNLOADS, $metrics)) {
                $result['downloads'] = (int) $metrics[SourceMetricMeasurement::METRIC_DOWNLOADS];
            }
            if (array_key_exists(SourceMetricMeasurement::METRIC_RATING, $metrics)) {
                $result['rating'] = (float) $metrics[SourceMetricMeasurement::METRIC_RATING];
            }
            if (array_key_exists(SourceMetricMeasurement::METRIC_RATING_COUNT, $metrics)) {
                $result['comments'] = (int) $metrics[SourceMetricMeasurement::METRIC_RATING_COUNT];
            }
            $this->appendDeltas($result, $trendDeltas[SourceMetricMeasurement::METRIC_DOWNLOADS] ?? [], 'downloadsDelta');

            return $result;
        }

        if ($sourceType === ExtensionSource::TYPE_GITHUB) {
            $result = [];
            if (array_key_exists(SourceMetricMeasurement::METRIC_STARS, $metrics)) {
                $result['stars'] = (int) $metrics[SourceMetricMeasurement::METRIC_STARS];
            }
            if (array_key_exists(SourceMetricMeasurement::METRIC_FORKS, $metrics)) {
                $result['forks'] = (int) $metrics[SourceMetricMeasurement::METRIC_FORKS];
            }
            $this->appendDeltas($result, $trendDeltas[SourceMetricMeasurement::METRIC_STARS] ?? [], 'starsDelta');

            return $result;
        }

        return [];
    }

    /**
     * Append `{$prefix}1d`/`{$prefix}7d`/`{$prefix}30d` to $result in place,
     * one key per window that actually has a baseline. A window without a
     * baseline is omitted entirely, matching the "only what was actually
     * measured" contract every other metric here already follows.
     *
     * @param array<string, int|float> $result modified in place
     * @param array{delta1d?: ?float, delta7d?: ?float, delta30d?: ?float} $deltas
     */
    private function appendDeltas(array &$result, array $deltas, string $prefix): void
    {
        foreach (['delta1d' => '1d', 'delta7d' => '7d', 'delta30d' => '30d'] as $deltaKey => $suffix) {
            $value = $deltas[$deltaKey] ?? null;
            if ($value !== null) {
                $result[$prefix . $suffix] = (int) round($value);
            }
        }
    }

    /**
     * Map one Extension plus its sources onto a v2 snapshot item.
     * `score`/`scoreComponents` are zeroed here; the builder fills them in
     * once the full batch's percentile populations are known.
     *
     * @param ExtensionSource[] $sources
     * @param array<string, array<string, float>> $metricsBySourceType sourceType => metricType => value
     * @param array<string, array<string, array{delta1d: ?float, delta7d: ?float, delta30d: ?float}>> $trendDeltasBySourceType sourceType => metricType => deltas
     */
    public function mapExtension(Extension $extension, array $sources, array $metricsBySourceType, array $trendDeltasBySourceType = [], ?DateTimeInterface $now = null): ?ExtensionSnapshotItem
    {
        if (empty($extension->uuid) || empty($extension->name) || $sources === []) {
            return null;
        }

        $now = $now ?? new DateTime('UTC');
        $nowTimestamp = $now->getTimestamp();

        $sourceItems = [];
        $shellVersions = [];
        $timestamps = [];
        $hasScreenshot = !empty($extension->screenshot);

        foreach ($sources as $source) {
            $metrics = $metricsBySourceType[$source->sourceType ?? ''] ?? [];
            $trendDeltas = $trendDeltasBySourceType[$source->sourceType ?? ''] ?? [];
            $sourceItems[] = $this->mapSource($source, $metrics, $extension->uuid, $trendDeltas, $now);

            foreach ($source->supportedShellVersions ?? [] as $version) {
                $shellVersions[$version] = true;
            }

            $timestamps[] = $this->freshnessTimestamp($source, $now);

            if (!empty($source->displayScreenshot)) {
                $hasScreenshot = true;
            }
        }

        // Fall back only to further real dates, never to "now": an extension
        // whose sources report no activity is old with an unknown date, not
        // freshly updated today.
        $updatedTimestamp = $this->firstKnownTimestamp(
            $timestamps === [] ? 0 : max($timestamps),
            $this->nonFutureTimestamp($extension->lastChange?->getTimestamp(), $nowTimestamp),
            $this->nonFutureTimestamp($extension->creationDate?->getTimestamp(), $nowTimestamp),
        );

        $creationTimestamp = $this->nonFutureTimestamp($extension->creationDate?->getTimestamp(), $nowTimestamp);
        $createdAt = $creationTimestamp !== 0
            ? (new DateTime('@' . $creationTimestamp))->format('c')
            : $now->format('c');
        $updatedAt = (new DateTime('@' . $updatedTimestamp))->format('c');

        return new ExtensionSnapshotItem(
            uuid: $extension->uuid,
            path: '/extension/' . rawurlencode($extension->uuid),
            name: $extension->name,
            description: $extension->description ?? '',
            creator: $extension->creator ?: 'Unknown',
            creatorUrl: $extension->creator_url ?: null,
            supportedShellVersions: array_keys($shellVersions),
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            recentSortValue: $updatedTimestamp,
            score: 0,
            scoreComponents: new ScoreComponents(0, 0),
            sources: $sourceItems,
            hasScreenshot: $hasScreenshot,
        );
    }
}
