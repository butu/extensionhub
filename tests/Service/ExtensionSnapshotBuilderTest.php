<?php

namespace App\Tests\Service;

use App\Entity\Extension;
use App\Entity\ExtensionSource;
use App\Entity\SourceMetricMeasurement;
use App\Repository\ExtensionCommentRepository;
use App\Repository\ExtensionRepository;
use App\Repository\ExtensionSourceRepository;
use App\Repository\SourceMetricMeasurementRepository;
use App\Service\ExtensionScoreCalculator;
use App\Service\ExtensionSnapshotBuilder;
use App\Service\ExtensionSnapshotMapper;
use App\Service\ExtensionTrendCalculator;
use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot v2 contract tests for the builder, deliberately run without a
 * database connection or a booted kernel: every repository dependency is a
 * PHPUnit mock returning fixed, in-memory fixtures. This exercises the real
 * production mapping/scoring/serialization code path end to end.
 */
class ExtensionSnapshotBuilderTest extends TestCase
{
    private function makeExtension(int $id, string $uuid, string $name, ?int $pk = null): Extension
    {
        $extension = new Extension();
        $extension->id = $id;
        $extension->uuid = $uuid;
        $extension->name = $name;
        $extension->pk = $pk;
        $extension->description = $name . ' description';
        $extension->creator = 'creator-' . $id;
        $extension->creator_url = 'https://example.com/creator-' . $id;
        $extension->link = '/extension/' . $id . '/';
        $extension->sourceUrl = '/download/' . $id . '.zip';
        $extension->icon = '/icons/' . $id . '.png';
        $extension->screenshot = null;
        $extension->creationDate = new DateTime('2024-01-01T00:00:00Z');
        $extension->lastChange = new DateTime('2025-01-01T00:00:00Z');
        $extension->supportedShellVersions = ['45'];

        return $extension;
    }

    private function makeSource(int $id, string $sourceType, string $externalIdentifier, array $overrides = []): ExtensionSource
    {
        $source = new ExtensionSource();
        $source->id = $id;
        $source->sourceType = $sourceType;
        $source->externalIdentifier = $externalIdentifier;
        $source->sourceUrl = $overrides['sourceUrl'] ?? 'https://example.com/' . $sourceType . '/' . $externalIdentifier;
        $source->installUrl = array_key_exists('installUrl', $overrides) ? $overrides['installUrl'] : null;
        $source->displayName = $overrides['displayName'] ?? null;
        $source->displayDescription = null;
        $source->displayIcon = null;
        $source->displayScreenshot = null;
        $source->supportedShellVersions = $overrides['supportedShellVersions'] ?? [];
        $source->lastCommitAt = $overrides['lastCommitAt'] ?? null;
        $source->lastReleaseAt = $overrides['lastReleaseAt'] ?? new DateTime('2025-01-01T00:00:00Z');
        $source->updatedAt = new DateTime('2025-01-01T00:00:00Z');
        $source->createdAt = new DateTime('2024-01-01T00:00:00Z');

        return $source;
    }

    /**
     * Build a builder wired to mocked repositories returning fixed fixtures,
     * plus the real (pure) mapper, score calculator and trend calculator.
     *
     * @param Extension[] $extensions
     * @param array<int, ExtensionSource[]> $sourcesByExtensionId
     * @param array<int, array<string, float>> $metricsBySourceId
     * @param array<int, array<string, array{delta1d: ?float, delta7d: ?float, delta30d: ?float}>> $trendDeltasBySourceId
     *   metricType => deltas only (no 'latest'); merged with $metricsBySourceId as the 'latest' value automatically.
     */
    private function makeBuilder(
        array $extensions,
        array $sourcesByExtensionId,
        array $metricsBySourceId,
        string $projectDir,
        array $trendDeltasBySourceId = [],
    ): ExtensionSnapshotBuilder {
        $extensionRepository = $this->createMock(ExtensionRepository::class);
        $extensionRepository->method('findAllForSnapshot')->willReturn($extensions);

        $sourceRepository = $this->createMock(ExtensionSourceRepository::class);
        $sourceRepository->method('findAllGroupedByExtensionIds')->willReturn($sourcesByExtensionId);

        $metricRepository = $this->createMock(SourceMetricMeasurementRepository::class);
        $metricRepository->method('findLatestValuesForSources')->willReturn($metricsBySourceId);
        $metricRepository->method('findTrendDeltasForSources')->willReturn(
            $this->mergeTrendData($metricsBySourceId, $trendDeltasBySourceId)
        );

        $commentRepository = $this->createMock(ExtensionCommentRepository::class);
        $commentRepository->method('findAllGroupedByExtensionUuid')->willReturn([]);

        return new ExtensionSnapshotBuilder(
            $extensionRepository,
            $sourceRepository,
            $metricRepository,
            $commentRepository,
            new ExtensionSnapshotMapper(),
            new ExtensionScoreCalculator(),
            new ExtensionTrendCalculator(),
            $projectDir,
        );
    }

    /**
     * Combine plain latest metric values with (optional) per-metric deltas
     * into findTrendDeltasForSources()'s
     * sourceId => metricType => ['latest'=>, 'delta1d'=>, 'delta7d'=>, 'delta30d'=>] shape,
     * so most tests only need to state the "current" fixture and only
     * trend-specific tests need to also state deltas.
     *
     * @param array<int, array<string, float>> $metricsBySourceId
     * @param array<int, array<string, array{delta1d: ?float, delta7d: ?float, delta30d: ?float}>> $trendDeltasBySourceId
     */
    private function mergeTrendData(array $metricsBySourceId, array $trendDeltasBySourceId): array
    {
        $result = [];
        foreach ($metricsBySourceId as $sourceId => $metrics) {
            foreach ($metrics as $metricType => $latestValue) {
                $deltas = $trendDeltasBySourceId[$sourceId][$metricType] ?? [];
                $result[$sourceId][$metricType] = [
                    'latest' => $latestValue,
                    'delta1d' => $deltas['delta1d'] ?? null,
                    'delta7d' => $deltas['delta7d'] ?? null,
                    'delta30d' => $deltas['delta30d'] ?? null,
                ];
            }
        }

        return $result;
    }

    private function threeSourceFixture(): array
    {
        $egoOnly = $this->makeExtension(1, 'ego-only@example', 'Ego Extension', 100);
        $githubOnly = $this->makeExtension(2, 'gh-only@example', 'GitHub Extension', null);
        $dual = $this->makeExtension(3, 'dual@example', 'Dual Extension', 300);

        $egoOnlySource = $this->makeSource(10, ExtensionSource::TYPE_EGO, '100', ['supportedShellVersions' => ['45']]);
        $githubOnlySource = $this->makeSource(20, ExtensionSource::TYPE_GITHUB, '55555', [
            'supportedShellVersions' => ['46', '47'],
            'installUrl' => 'https://github.com/owner/repo/releases/download/v1/ext.zip',
            'lastCommitAt' => new DateTime('2025-09-01T00:00:00Z'),
        ]);
        $dualEgoSource = $this->makeSource(30, ExtensionSource::TYPE_EGO, '300', ['supportedShellVersions' => ['45']]);
        $dualGithubSource = $this->makeSource(31, ExtensionSource::TYPE_GITHUB, '66666', [
            'supportedShellVersions' => ['46'],
            'lastCommitAt' => new DateTime('2025-09-10T00:00:00Z'),
        ]);

        $extensions = [$egoOnly, $githubOnly, $dual];
        $sourcesByExtensionId = [
            1 => [$egoOnlySource],
            2 => [$githubOnlySource],
            3 => [$dualEgoSource, $dualGithubSource],
        ];
        $metricsBySourceId = [
            10 => [
                SourceMetricMeasurement::METRIC_DOWNLOADS => 500.0,
                SourceMetricMeasurement::METRIC_RATING => 4.5,
                SourceMetricMeasurement::METRIC_RATING_COUNT => 12.0,
            ],
            20 => [
                SourceMetricMeasurement::METRIC_STARS => 200.0,
                SourceMetricMeasurement::METRIC_FORKS => 30.0,
            ],
            30 => [
                SourceMetricMeasurement::METRIC_DOWNLOADS => 1000.0,
                SourceMetricMeasurement::METRIC_RATING => 4.0,
                SourceMetricMeasurement::METRIC_RATING_COUNT => 20.0,
            ],
            31 => [
                SourceMetricMeasurement::METRIC_STARS => 50.0,
                SourceMetricMeasurement::METRIC_FORKS => 5.0,
            ],
        ];

        return [$extensions, $sourcesByExtensionId, $metricsBySourceId];
    }

    public function testBuildToStringProducesSchemaVersionTwo(): void
    {
        [$extensions, $sources, $metrics] = $this->threeSourceFixture();
        $builder = $this->makeBuilder($extensions, $sources, $metrics, sys_get_temp_dir());

        $payload = json_decode($builder->buildToString(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $payload['schemaVersion']);
        self::assertSame(3, $payload['count']);
        self::assertCount(3, $payload['items']);
    }

    public function testBuildToStringItemsNeverContainRetiredV1Fields(): void
    {
        [$extensions, $sources, $metrics] = $this->threeSourceFixture();
        $builder = $this->makeBuilder($extensions, $sources, $metrics, sys_get_temp_dir());

        $payload = json_decode($builder->buildToString(), true, 512, JSON_THROW_ON_ERROR);

        foreach ($payload['items'] as $item) {
            self::assertArrayNotHasKey('pk', $item);
            self::assertArrayNotHasKey('slug', $item);
            self::assertArrayNotHasKey('gnomeUrl', $item);
            self::assertArrayNotHasKey('installUrl', $item);
        }
    }

    public function testBuildToStringPathsAreUrlEncodedUuidsAndUnique(): void
    {
        [$extensions, $sources, $metrics] = $this->threeSourceFixture();
        $builder = $this->makeBuilder($extensions, $sources, $metrics, sys_get_temp_dir());

        $payload = json_decode($builder->buildToString(), true, 512, JSON_THROW_ON_ERROR);

        $paths = [];
        $uuids = [];
        foreach ($payload['items'] as $item) {
            self::assertSame('/extension/' . rawurlencode($item['uuid']), $item['path']);
            self::assertNotContains($item['path'], $paths);
            self::assertNotContains($item['uuid'], $uuids);
            $paths[] = $item['path'];
            $uuids[] = $item['uuid'];
        }
    }

    public function testBuildToStringDualSourceExtensionAppearsExactlyOnceWithBothSources(): void
    {
        [$extensions, $sources, $metrics] = $this->threeSourceFixture();
        $builder = $this->makeBuilder($extensions, $sources, $metrics, sys_get_temp_dir());

        $payload = json_decode($builder->buildToString(), true, 512, JSON_THROW_ON_ERROR);

        $dualItems = array_values(array_filter($payload['items'], static fn ($item) => $item['uuid'] === 'dual@example'));
        self::assertCount(1, $dualItems);
        self::assertCount(2, $dualItems[0]['sources']);
        $types = array_map(static fn ($s) => $s['sourceType'], $dualItems[0]['sources']);
        self::assertEqualsCanonicalizing([ExtensionSource::TYPE_EGO, ExtensionSource::TYPE_GITHUB], $types);
    }

    public function testBuildToStringGithubSourceNeverEmitsEgoOnlyMetrics(): void
    {
        [$extensions, $sources, $metrics] = $this->threeSourceFixture();
        $builder = $this->makeBuilder($extensions, $sources, $metrics, sys_get_temp_dir());

        $payload = json_decode($builder->buildToString(), true, 512, JSON_THROW_ON_ERROR);

        $githubItem = current(array_filter($payload['items'], static fn ($item) => $item['uuid'] === 'gh-only@example'));
        $githubSource = $githubItem['sources'][0];

        self::assertSame(ExtensionSource::TYPE_GITHUB, $githubSource['sourceType']);
        self::assertArrayHasKey('stars', $githubSource['metrics']);
        self::assertArrayHasKey('forks', $githubSource['metrics']);
        self::assertArrayNotHasKey('downloads', $githubSource['metrics']);
        self::assertArrayNotHasKey('rating', $githubSource['metrics']);
        self::assertArrayNotHasKey('comments', $githubSource['metrics']);
    }

    public function testBuildToStringScoreAndComponentsAreBoundedIntegers(): void
    {
        [$extensions, $sources, $metrics] = $this->threeSourceFixture();
        $builder = $this->makeBuilder($extensions, $sources, $metrics, sys_get_temp_dir());

        $payload = json_decode($builder->buildToString(), true, 512, JSON_THROW_ON_ERROR);

        foreach ($payload['items'] as $item) {
            self::assertIsInt($item['score']);
            self::assertGreaterThanOrEqual(0, $item['score']);
            self::assertLessThanOrEqual(100, $item['score']);
            self::assertIsInt($item['scoreComponents']['popularity']);
            self::assertIsInt($item['scoreComponents']['freshness']);
        }
    }

    public function testBuildToStringDefaultsTrendScoreToZeroWithoutAnyBaseline(): void
    {
        [$extensions, $sources, $metrics] = $this->threeSourceFixture();
        $builder = $this->makeBuilder($extensions, $sources, $metrics, sys_get_temp_dir());

        $payload = json_decode($builder->buildToString(), true, 512, JSON_THROW_ON_ERROR);

        foreach ($payload['items'] as $item) {
            self::assertSame(0, $item['trendScore'], "Item {$item['uuid']} must not be trend-eligible without any 7-day baseline");
        }
    }

    public function testBuildToStringComputesTrendScoreFromEligibleSevenDayDeltasOnly(): void
    {
        [$extensions, $sources, $metrics] = $this->threeSourceFixture();
        $builder = $this->makeBuilder($extensions, $sources, $metrics, sys_get_temp_dir(), [
            // ego-only: at the EGO >= 50 downloads threshold.
            10 => [SourceMetricMeasurement::METRIC_DOWNLOADS => ['delta7d' => 50.0]],
            // github-only: below the GitHub >= 1 stars threshold, so not eligible.
            20 => [SourceMetricMeasurement::METRIC_STARS => ['delta7d' => 0.0]],
            // dual: ego leg below its own threshold, github leg well above it.
            30 => [SourceMetricMeasurement::METRIC_DOWNLOADS => ['delta7d' => 3.0]],
            31 => [SourceMetricMeasurement::METRIC_STARS => ['delta7d' => 10.0]],
        ]);

        $payload = json_decode($builder->buildToString(), true, 512, JSON_THROW_ON_ERROR);
        $byUuid = [];
        foreach ($payload['items'] as $item) {
            $byUuid[$item['uuid']] = $item;
        }

        self::assertGreaterThan(0, $byUuid['ego-only@example']['trendScore'], 'EGO delta of 50 clears the >=5 threshold');
        self::assertSame(0, $byUuid['gh-only@example']['trendScore'], 'GitHub delta of 0 does not clear the >=1 threshold');
        self::assertGreaterThan(0, $byUuid['dual@example']['trendScore'], 'Dual-source extension takes the best (github) eligible leg');

        foreach ($payload['items'] as $item) {
            self::assertIsInt($item['trendScore']);
            self::assertGreaterThanOrEqual(0, $item['trendScore']);
            self::assertLessThanOrEqual(100, $item['trendScore']);
        }
    }

    public function testBuildToStringEmitsDeltaMetricsOnlyWhenBaselineExists(): void
    {
        [$extensions, $sources, $metrics] = $this->threeSourceFixture();
        $builder = $this->makeBuilder($extensions, $sources, $metrics, sys_get_temp_dir(), [
            10 => [SourceMetricMeasurement::METRIC_DOWNLOADS => ['delta1d' => 5.0, 'delta7d' => 50.0, 'delta30d' => 200.0]],
            31 => [SourceMetricMeasurement::METRIC_STARS => ['delta7d' => 10.0]],
        ]);

        $payload = json_decode($builder->buildToString(), true, 512, JSON_THROW_ON_ERROR);
        $byUuid = [];
        foreach ($payload['items'] as $item) {
            $byUuid[$item['uuid']] = $item;
        }

        $egoOnlySource = $byUuid['ego-only@example']['sources'][0];
        self::assertSame(5, $egoOnlySource['metrics']['downloadsDelta1d']);
        self::assertSame(50, $egoOnlySource['metrics']['downloadsDelta7d']);
        self::assertSame(200, $egoOnlySource['metrics']['downloadsDelta30d']);

        // github-only has no configured baseline at all: deltas must be
        // omitted entirely, never serialized as 0 or null.
        $githubOnlySource = $byUuid['gh-only@example']['sources'][0];
        self::assertArrayNotHasKey('starsDelta1d', $githubOnlySource['metrics']);
        self::assertArrayNotHasKey('starsDelta7d', $githubOnlySource['metrics']);
        self::assertArrayNotHasKey('starsDelta30d', $githubOnlySource['metrics']);

        $dualSources = $byUuid['dual@example']['sources'];
        $dualGithubSource = current(array_filter($dualSources, static fn ($s) => $s['sourceType'] === ExtensionSource::TYPE_GITHUB));
        self::assertSame(10, $dualGithubSource['metrics']['starsDelta7d']);
        self::assertArrayNotHasKey('starsDelta1d', $dualGithubSource['metrics'], 'Only the configured delta7d baseline exists in this fixture');
    }

    public function testBuildToStringExcludesExtensionsWithoutAnySource(): void
    {
        $orphan = $this->makeExtension(9, 'orphan@example', 'Orphan Extension');
        $builder = $this->makeBuilder([$orphan], [], [], sys_get_temp_dir());

        $payload = json_decode($builder->buildToString(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $payload['count']);
    }

    /**
     * A row already persisted with a future lastChange/lastReleaseAt (e.g.
     * from the since-fixed PK-date-estimation bug) must not make the build
     * fail, and the resulting item's dates must be safely represented:
     * never later than this snapshot's own generatedAt.
     */
    public function testBuildToStringSanitizesAPersistedFutureDateInsteadOfFailing(): void
    {
        $future = $this->makeExtension(1, 'legacy-future@example', 'Legacy Future Extension', 100);
        $future->lastChange = new DateTime('+5 years');
        $source = $this->makeSource(10, ExtensionSource::TYPE_EGO, '100', [
            'lastReleaseAt' => new DateTime('+5 years'),
        ]);
        $builder = $this->makeBuilder(
            [$future],
            [1 => [$source]],
            [10 => [SourceMetricMeasurement::METRIC_DOWNLOADS => 500.0]],
            sys_get_temp_dir()
        );

        $payload = json_decode($builder->buildToString(), true, 512, JSON_THROW_ON_ERROR);

        $generatedAtTimestamp = strtotime($payload['generatedAt']);
        $item = $payload['items'][0];
        self::assertLessThanOrEqual($generatedAtTimestamp, strtotime($item['updatedAt']));
        self::assertLessThanOrEqual($generatedAtTimestamp, strtotime($item['createdAt']));
    }

    public function testPublishWritesByteIdenticalExtensionsJsonAndV2Alias(): void
    {
        [$extensions, $sources, $metrics] = $this->threeSourceFixture();
        $tempDir = sys_get_temp_dir() . '/snapshot-builder-test-' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            $builder = $this->makeBuilder($extensions, $sources, $metrics, $tempDir);
            $json = $builder->buildToString();

            $builder->publish($json);

            $mainPath = $tempDir . '/public/data/extensions.json';
            $v2Path = $tempDir . '/public/data/extensions.v2.json';
            self::assertFileExists($mainPath);
            self::assertFileExists($v2Path);
            self::assertSame(file_get_contents($mainPath), file_get_contents($v2Path));
            self::assertFileDoesNotExist($tempDir . '/public/data/extensions.v1.json');
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * Structural check of the real builder payload against the published
     * v2 schema contract file, since no JSON-Schema validator library is
     * installed. Not a full draft 2020-12 validator: it targets exactly
     * this schema's shape (required keys, const values, forbidden v1
     * properties, enums, and closed link/metric key sets).
     */
    public function testBuildToStringPayloadMatchesPublishedSchemaFile(): void
    {
        [$extensions, $sources, $metrics] = $this->threeSourceFixture();
        $builder = $this->makeBuilder($extensions, $sources, $metrics, sys_get_temp_dir());

        $payload = json_decode($builder->buildToString(), true, 512, JSON_THROW_ON_ERROR);
        $schema = json_decode(
            file_get_contents(__DIR__ . '/../../docs/superpowers/schema/extensions-feed.schema.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame($schema['properties']['schemaVersion']['const'], $payload['schemaVersion']);
        self::assertSame($schema['properties']['pageSize']['const'], $payload['pageSize']);

        $itemSchema = $schema['properties']['items']['items'];
        $forbiddenItemFields = array_keys(array_filter(
            $itemSchema['properties'],
            static fn ($propertySchema): bool => $propertySchema === false
        ));
        self::assertNotEmpty($forbiddenItemFields, 'Schema fixture must still declare retired v1 fields as forbidden');

        $sourceSchema = $itemSchema['properties']['sources']['items'];
        $allowedLinkKeys = array_keys($sourceSchema['properties']['links']['properties']);
        $allowedMetricKeys = array_keys($sourceSchema['properties']['metrics']['properties']);

        self::assertNotEmpty($payload['items']);

        foreach ($payload['items'] as $item) {
            foreach ($itemSchema['required'] as $requiredField) {
                self::assertArrayHasKey($requiredField, $item, "Schema requires field: {$requiredField}");
            }

            foreach ($forbiddenItemFields as $forbiddenField) {
                self::assertArrayNotHasKey($forbiddenField, $item, "Schema forbids field: {$forbiddenField}");
            }

            self::assertMatchesRegularExpression('#^' . $itemSchema['properties']['path']['pattern'] . '#', $item['path']);
            self::assertGreaterThanOrEqual($itemSchema['properties']['score']['minimum'], $item['score']);
            self::assertLessThanOrEqual($itemSchema['properties']['score']['maximum'], $item['score']);

            foreach ($itemSchema['properties']['scoreComponents']['required'] as $componentField) {
                self::assertArrayHasKey($componentField, $item['scoreComponents']);
            }

            self::assertGreaterThanOrEqual($sourceSchema['minItems'] ?? 1, count($item['sources']));

            foreach ($item['sources'] as $source) {
                foreach ($sourceSchema['required'] as $requiredSourceField) {
                    self::assertArrayHasKey($requiredSourceField, $source, "Schema requires source field: {$requiredSourceField}");
                }

                self::assertContains($source['sourceType'], $sourceSchema['properties']['sourceType']['enum']);
                self::assertEmpty(array_diff(array_keys($source['links']), $allowedLinkKeys), 'links must only use schema-declared keys');
                self::assertEmpty(array_diff(array_keys($source['metrics']), $allowedMetricKeys), 'metrics must only use schema-declared keys');
            }
        }
    }

    public function testBuildCommentsToStringGroupsByExtensionUuid(): void
    {
        $extensionRepository = $this->createMock(ExtensionRepository::class);
        $sourceRepository = $this->createMock(ExtensionSourceRepository::class);
        $metricRepository = $this->createMock(SourceMetricMeasurementRepository::class);

        $commentRepository = $this->createMock(ExtensionCommentRepository::class);
        $comment = new \App\Entity\ExtensionComment();
        $comment->authorUsername = 'someone';
        $comment->comment = 'Great extension';
        $comment->rating = 5;
        $comment->isExtensionCreator = false;
        $comment->commentDate = new DateTime('2025-01-01T00:00:00Z');
        $commentRepository->method('findAllGroupedByExtensionUuid')->willReturn([
            'ego-only@example' => [$comment],
        ]);

        $builder = new ExtensionSnapshotBuilder(
            $extensionRepository,
            $sourceRepository,
            $metricRepository,
            $commentRepository,
            new ExtensionSnapshotMapper(),
            new ExtensionScoreCalculator(),
            new ExtensionTrendCalculator(),
            sys_get_temp_dir(),
        );

        $payload = json_decode($builder->buildCommentsToString(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('ego-only@example', $payload['comments']);
        self::assertCount(1, $payload['comments']['ego-only@example']);
        self::assertSame('someone', $payload['comments']['ego-only@example'][0]['author']);
        self::assertArrayNotHasKey('100', $payload['comments'], 'Comments must be keyed by uuid, never by the retired pk');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
