<?php

namespace App\Tests\Service;

use App\Entity\Extension;
use App\Entity\ExtensionComment;
use App\Entity\ExtensionDownloadMeasurement;
use App\Entity\ExtensionSource;
use App\Entity\SourceMetricMeasurement;
use App\Service\EgoSourceMapper;
use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Pure mapping/backfill logic, deliberately tested without a database connection.
 */
class EgoSourceMapperTest extends TestCase
{
    private function makeExtension(array $overrides = []): Extension
    {
        $extension = new Extension();
        $extension->id = $overrides['id'] ?? 42;
        $extension->pk = $overrides['pk'] ?? 12345;
        $extension->uuid = $overrides['uuid'] ?? 'plaid@plyply99';
        $extension->name = $overrides['name'] ?? 'Plaid';
        $extension->link = $overrides['link'] ?? '/extension/12345/plaid/';
        $extension->sourceUrl = $overrides['sourceUrl'] ?? '/review/download/12345/plaid.shell-extension.zip';
        $extension->icon = $overrides['icon'] ?? '/static/extension-data/icons/12345.png';
        $extension->screenshot = $overrides['screenshot'] ?? '/static/extension-data/screenshots/12345.png';
        $extension->description = $overrides['description'] ?? 'A nice extension';
        $extension->creator = $overrides['creator'] ?? 'plyply99';
        $extension->creator_url = $overrides['creator_url'] ?? '/accounts/profile/plyply99/';
        $extension->downloads = array_key_exists('downloads', $overrides) ? $overrides['downloads'] : 500;
        $extension->rating = array_key_exists('rating', $overrides) ? $overrides['rating'] : 4.5;
        $extension->comments = array_key_exists('comments', $overrides) ? $overrides['comments'] : 12;
        $extension->supportedShellVersions = $overrides['supportedShellVersions'] ?? ['45', '46'];
        $extension->creationDate = $overrides['creationDate'] ?? new DateTime('2024-01-01');
        $extension->lastChange = $overrides['lastChange'] ?? new DateTime('2025-06-01');

        return $extension;
    }

    public function testMapToSourceProducesSingleEgoSourceWithMatchingIdentifiers(): void
    {
        $mapper = new EgoSourceMapper();
        $extension = $this->makeExtension(['pk' => 999]);

        $source = $mapper->mapToSource($extension);

        self::assertSame(ExtensionSource::TYPE_EGO, $source->sourceType);
        self::assertSame('999', $source->externalIdentifier);
        self::assertSame($extension, $source->extension);
        self::assertSame($extension->uuid, $source->extension->uuid);
    }

    public function testMapToSourceReusesExistingSourceInstanceInsteadOfCreatingASecondOne(): void
    {
        $mapper = new EgoSourceMapper();
        $extension = $this->makeExtension();
        $existing = new ExtensionSource();
        $existing->id = 7;

        $result = $mapper->mapToSource($extension, $existing);

        self::assertSame($existing, $result, 'Backfill must update the found source, never allocate a second one');
    }

    public function testMapToSourceTreatsEgoDefaultIconAsMissing(): void
    {
        $mapper = new EgoSourceMapper();
        $extension = $this->makeExtension(['icon' => '/static/images/plugin.png']);

        $source = $mapper->mapToSource($extension);

        self::assertNull($source->displayIcon);
    }

    public function testMapToSourceKeepsNonDefaultIcon(): void
    {
        $mapper = new EgoSourceMapper();
        $extension = $this->makeExtension(['icon' => '/static/extension-data/icons/12345.png']);

        $source = $mapper->mapToSource($extension);

        self::assertSame('/static/extension-data/icons/12345.png', $source->displayIcon);
    }

    public function testMapToSourceCopiesDisplayAndCompatibilityFields(): void
    {
        $mapper = new EgoSourceMapper();
        $extension = $this->makeExtension();

        $source = $mapper->mapToSource($extension);

        self::assertSame($extension->name, $source->displayName);
        self::assertSame($extension->description, $source->displayDescription);
        self::assertSame($extension->screenshot, $source->displayScreenshot);
        self::assertSame($extension->supportedShellVersions, $source->supportedShellVersions);
        self::assertSame($extension->link, $source->sourceUrl);
        self::assertSame($extension->sourceUrl, $source->installUrl);
        self::assertSame($extension->lastChange, $source->lastReleaseAt);
    }

    public function testReassignDownloadMeasurementsOnlyUpdatesUnassignedRows(): void
    {
        $mapper = new EgoSourceMapper();
        $source = new ExtensionSource();
        $source->id = 1;
        $otherSource = new ExtensionSource();
        $otherSource->id = 2;

        $alreadyAssigned = new ExtensionDownloadMeasurement();
        $alreadyAssigned->source = $otherSource;

        $unassignedOne = new ExtensionDownloadMeasurement();
        $unassignedTwo = new ExtensionDownloadMeasurement();

        $changed = $mapper->reassignDownloadMeasurements($source, [$alreadyAssigned, $unassignedOne, $unassignedTwo]);

        self::assertSame(2, $changed);
        self::assertSame($otherSource, $alreadyAssigned->source, 'Existing assignment must not be overwritten');
        self::assertSame($source, $unassignedOne->source);
        self::assertSame($source, $unassignedTwo->source);
    }

    public function testReassignCommentsOnlyUpdatesUnassignedRows(): void
    {
        $mapper = new EgoSourceMapper();
        $source = new ExtensionSource();
        $source->id = 1;
        $otherSource = new ExtensionSource();
        $otherSource->id = 2;

        $alreadyAssigned = new ExtensionComment();
        $alreadyAssigned->source = $otherSource;

        $unassigned = new ExtensionComment();

        $changed = $mapper->reassignComments($source, [$alreadyAssigned, $unassigned]);

        self::assertSame(1, $changed);
        self::assertSame($otherSource, $alreadyAssigned->source);
        self::assertSame($source, $unassigned->source);
    }

    public function testBuildMetricMeasurementsIncludesDownloadsRatingAndRatingCountSeparately(): void
    {
        $mapper = new EgoSourceMapper();
        $source = new ExtensionSource();
        $source->id = 1;
        $extension = $this->makeExtension(['downloads' => 500, 'rating' => 4.5, 'comments' => 12]);
        $measuredAt = new DateTime('2026-01-01 00:00:00');

        $measurements = $mapper->buildMetricMeasurements($source, $extension, $measuredAt);

        self::assertCount(3, $measurements);

        $byType = [];
        foreach ($measurements as $measurement) {
            self::assertInstanceOf(SourceMetricMeasurement::class, $measurement);
            self::assertSame($source, $measurement->source);
            self::assertSame($measuredAt, $measurement->measuredAt);
            $byType[$measurement->metricType] = $measurement->value;
        }

        // Values must stay distinguishable per metric type, never swapped.
        self::assertSame(500.0, $byType[SourceMetricMeasurement::METRIC_DOWNLOADS]);
        self::assertSame(4.5, $byType[SourceMetricMeasurement::METRIC_RATING]);
        self::assertSame(12.0, $byType[SourceMetricMeasurement::METRIC_RATING_COUNT]);
    }

    public function testBuildMetricMeasurementsOmitsNullRatingAndRatingCount(): void
    {
        $mapper = new EgoSourceMapper();
        $source = new ExtensionSource();
        $source->id = 1;
        $extension = $this->makeExtension(['downloads' => 200, 'rating' => null, 'comments' => null]);
        $measuredAt = new DateTime('2026-01-01 00:00:00');

        $measurements = $mapper->buildMetricMeasurements($source, $extension, $measuredAt);

        self::assertCount(1, $measurements);
        self::assertSame(SourceMetricMeasurement::METRIC_DOWNLOADS, $measurements[0]->metricType);
    }

    public function testBuildMetricMeasurementsOmitsNullDownloads(): void
    {
        $mapper = new EgoSourceMapper();
        $source = new ExtensionSource();
        $source->id = 1;
        $extension = $this->makeExtension(['downloads' => null, 'rating' => 3.0, 'comments' => 5]);
        $measuredAt = new DateTime('2026-01-01 00:00:00');

        $measurements = $mapper->buildMetricMeasurements($source, $extension, $measuredAt);

        $types = array_map(static fn (SourceMetricMeasurement $m) => $m->metricType, $measurements);
        self::assertNotContains(SourceMetricMeasurement::METRIC_DOWNLOADS, $types);
        self::assertCount(2, $measurements);
    }

    public function testBuildDownloadHistoryMeasurementsMapsEachRowToADownloadsMetric(): void
    {
        $mapper = new EgoSourceMapper();
        $source = new ExtensionSource();
        $source->id = 1;

        $rowOne = new ExtensionDownloadMeasurement();
        $rowOne->downloads = 100;
        $rowOne->measuredAt = new DateTime('2025-01-01 00:00:00');

        $rowTwo = new ExtensionDownloadMeasurement();
        $rowTwo->downloads = 150;
        $rowTwo->measuredAt = new DateTime('2025-01-02 00:00:00');

        $measurements = $mapper->buildDownloadHistoryMeasurements($source, [$rowOne, $rowTwo]);

        self::assertCount(2, $measurements);

        $byDate = [];
        foreach ($measurements as $measurement) {
            self::assertInstanceOf(SourceMetricMeasurement::class, $measurement);
            self::assertSame($source, $measurement->source);
            self::assertSame(SourceMetricMeasurement::METRIC_DOWNLOADS, $measurement->metricType);
            $byDate[$measurement->measuredAt->format('Y-m-d')] = $measurement->value;
        }

        self::assertSame(100.0, $byDate['2025-01-01']);
        self::assertSame(150.0, $byDate['2025-01-02']);
    }

    public function testBuildDownloadHistoryMeasurementsHandlesRowsRegardlessOfPriorSourceLink(): void
    {
        $mapper = new EgoSourceMapper();
        $source = new ExtensionSource();
        $source->id = 1;

        // Simulates a row already reassigned to $source by a previous backfill
        // run (source_id set) that was never copied into source_metric_measurement.
        $alreadyLinked = new ExtensionDownloadMeasurement();
        $alreadyLinked->source = $source;
        $alreadyLinked->downloads = 200;
        $alreadyLinked->measuredAt = new DateTime('2025-02-01 00:00:00');

        $measurements = $mapper->buildDownloadHistoryMeasurements($source, [$alreadyLinked]);

        self::assertCount(1, $measurements);
        self::assertSame(SourceMetricMeasurement::METRIC_DOWNLOADS, $measurements[0]->metricType);
        self::assertSame(200.0, $measurements[0]->value);
        self::assertSame($source, $measurements[0]->source);
    }

    public function testValidateExtensionForBackfillRejectsMissingUuid(): void
    {
        $mapper = new EgoSourceMapper();
        $extension = $this->makeExtension(['uuid' => '']);

        self::assertNotNull($mapper->validateExtensionForBackfill($extension));
    }

    public function testValidateExtensionForBackfillRejectsMissingPk(): void
    {
        $mapper = new EgoSourceMapper();
        $extension = $this->makeExtension(['pk' => 0]);

        self::assertNotNull($mapper->validateExtensionForBackfill($extension));
    }

    public function testValidateExtensionForBackfillAcceptsValidExtension(): void
    {
        $mapper = new EgoSourceMapper();
        $extension = $this->makeExtension();

        self::assertNull($mapper->validateExtensionForBackfill($extension));
    }
}
