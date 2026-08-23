<?php

namespace App\Tests\Service;

use App\Entity\Extension;
use App\Entity\ExtensionSource;
use App\Entity\SourceMetricMeasurement;
use App\Service\EgoExtensionMapper;
use App\Service\EgoSourceMapper;
use App\Service\ExtensionSnapshotMapper;
use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Pure Extension/ExtensionSource -> snapshot v2 item mapping, deliberately
 * tested without a database connection.
 */
class ExtensionSnapshotMapperTest extends TestCase
{
    private function makeExtension(array $overrides = []): Extension
    {
        $extension = new Extension();
        $extension->id = $overrides['id'] ?? 1;
        $extension->uuid = $overrides['uuid'] ?? 'plaid@plyply99';
        $extension->name = $overrides['name'] ?? 'Plaid';
        $extension->pk = array_key_exists('pk', $overrides) ? $overrides['pk'] : 12345;
        $extension->description = $overrides['description'] ?? 'A nice extension';
        $extension->creator = $overrides['creator'] ?? 'plyply99';
        $extension->creator_url = $overrides['creator_url'] ?? 'https://extensions.gnome.org/accounts/profile/plyply99/';
        $extension->link = $overrides['link'] ?? '/extension/12345/plaid/';
        $extension->sourceUrl = $overrides['sourceUrl'] ?? '/review/download/12345/plaid.shell-extension.zip';
        $extension->icon = $overrides['icon'] ?? '/static/extension-data/icons/12345.png';
        $extension->screenshot = array_key_exists('screenshot', $overrides) ? $overrides['screenshot'] : '/static/extension-data/screenshots/12345.png';
        $extension->creationDate = $overrides['creationDate'] ?? new DateTime('2024-01-01T00:00:00Z');
        $extension->lastChange = $overrides['lastChange'] ?? new DateTime('2025-06-01T00:00:00Z');
        $extension->supportedShellVersions = $overrides['supportedShellVersions'] ?? ['45'];

        return $extension;
    }

    private function makeEgoSource(array $overrides = []): ExtensionSource
    {
        $source = new ExtensionSource();
        $source->id = $overrides['id'] ?? 10;
        $source->sourceType = ExtensionSource::TYPE_EGO;
        $source->externalIdentifier = $overrides['externalIdentifier'] ?? '12345';
        $source->sourceUrl = array_key_exists('sourceUrl', $overrides) ? $overrides['sourceUrl'] : '/extension/12345/plaid/';
        $source->installUrl = array_key_exists('installUrl', $overrides) ? $overrides['installUrl'] : '/review/download/12345/plaid.shell-extension.zip';
        $source->displayName = $overrides['displayName'] ?? 'Plaid';
        $source->displayDescription = $overrides['displayDescription'] ?? 'A nice extension';
        $source->displayIcon = array_key_exists('displayIcon', $overrides) ? $overrides['displayIcon'] : null;
        $source->displayScreenshot = array_key_exists('displayScreenshot', $overrides) ? $overrides['displayScreenshot'] : '/static/extension-data/screenshots/12345.png';
        $source->supportedShellVersions = $overrides['supportedShellVersions'] ?? ['45'];
        $source->lastReleaseAt = array_key_exists('lastReleaseAt', $overrides) ? $overrides['lastReleaseAt'] : new DateTime('2025-06-01T00:00:00Z');
        $source->updatedAt = $overrides['updatedAt'] ?? new DateTime('2025-06-01T00:00:00Z');

        return $source;
    }

    private function makeGithubSource(array $overrides = []): ExtensionSource
    {
        $source = new ExtensionSource();
        $source->id = $overrides['id'] ?? 20;
        $source->sourceType = ExtensionSource::TYPE_GITHUB;
        $source->externalIdentifier = $overrides['externalIdentifier'] ?? '55555';
        $source->sourceUrl = $overrides['sourceUrl'] ?? 'https://github.com/ghowner/ghrepo';
        $source->installUrl = array_key_exists('installUrl', $overrides) ? $overrides['installUrl'] : 'https://github.com/ghowner/ghrepo/releases/download/v1/ext.zip';
        $source->displayName = $overrides['displayName'] ?? 'GH Extension';
        $source->displayDescription = $overrides['displayDescription'] ?? 'GH description';
        $source->displayIcon = null;
        $source->displayScreenshot = null;
        $source->supportedShellVersions = $overrides['supportedShellVersions'] ?? ['46', '47'];
        $source->lastCommitAt = array_key_exists('lastCommitAt', $overrides) ? $overrides['lastCommitAt'] : new DateTime('2025-09-01T00:00:00Z');
        $source->lastReleaseAt = array_key_exists('lastReleaseAt', $overrides) ? $overrides['lastReleaseAt'] : new DateTime('2025-08-15T00:00:00Z');
        $source->updatedAt = $overrides['updatedAt'] ?? new DateTime('2025-09-01T00:00:00Z');

        return $source;
    }

    public function testMapExtensionDerivesUuidPathWithoutPkOrSlug(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension(['uuid' => 'weird uuid@needs/encoding']);
        $source = $this->makeEgoSource();

        $item = $mapper->mapExtension($extension, [$source], []);

        self::assertSame('weird uuid@needs/encoding', $item->uuid);
        self::assertSame('/extension/' . rawurlencode('weird uuid@needs/encoding'), $item->path);
    }

    public function testMapExtensionExcludesExtensionsWithoutAnySource(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension();

        self::assertNull($mapper->mapExtension($extension, [], []));
    }

    public function testMapExtensionExcludesExtensionsWithoutUuidOrName(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $source = $this->makeEgoSource();

        $noUuid = $this->makeExtension(['uuid' => '']);
        self::assertNull($mapper->mapExtension($noUuid, [$source], []));

        $noName = $this->makeExtension(['name' => '']);
        self::assertNull($mapper->mapExtension($noName, [$source], []));
    }

    public function testMapExtensionEgoOnlyProducesSingleEgoSourceWithMetrics(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension();
        $source = $this->makeEgoSource();
        $metricsBySourceType = [
            ExtensionSource::TYPE_EGO => [
                SourceMetricMeasurement::METRIC_DOWNLOADS => 500.0,
                SourceMetricMeasurement::METRIC_RATING => 4.5,
                SourceMetricMeasurement::METRIC_RATING_COUNT => 12.0,
            ],
        ];

        $item = $mapper->mapExtension($extension, [$source], $metricsBySourceType);

        self::assertCount(1, $item->sources);
        $egoSource = $item->sources[0];
        self::assertSame(ExtensionSource::TYPE_EGO, $egoSource->sourceType);
        self::assertSame(500, $egoSource->metrics['downloads']);
        self::assertSame(4.5, $egoSource->metrics['rating']);
        self::assertSame(12, $egoSource->metrics['comments']);
        self::assertArrayNotHasKey('stars', $egoSource->metrics);
        self::assertArrayNotHasKey('forks', $egoSource->metrics);
        self::assertSame('gnome-extensions://' . rawurlencode($extension->uuid) . '?action=install', $egoSource->links['installUrl']);
        self::assertArrayHasKey('pageUrl', $egoSource->links);
        self::assertArrayNotHasKey('repositoryUrl', $egoSource->links);
    }

    public function testMapExtensionEgoOnlyEmitsDownloadsDeltasOnlyWhenBaselineExists(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension();
        $source = $this->makeEgoSource();
        $metricsBySourceType = [
            ExtensionSource::TYPE_EGO => [SourceMetricMeasurement::METRIC_DOWNLOADS => 500.0],
        ];
        $trendDeltasBySourceType = [
            ExtensionSource::TYPE_EGO => [
                SourceMetricMeasurement::METRIC_DOWNLOADS => ['delta1d' => 5.0, 'delta7d' => 40.0, 'delta30d' => null],
            ],
        ];

        $item = $mapper->mapExtension($extension, [$source], $metricsBySourceType, $trendDeltasBySourceType);

        $metrics = $item->sources[0]->metrics;
        self::assertSame(5, $metrics['downloadsDelta1d']);
        self::assertSame(40, $metrics['downloadsDelta7d']);
        self::assertArrayNotHasKey('downloadsDelta30d', $metrics, 'A null (missing) baseline must be omitted, never 0 or null');
    }

    public function testMapExtensionGithubOnlyEmitsStarsDeltasOnlyWhenBaselineExists(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension(['pk' => null, 'uuid' => 'gh-only@example']);
        $source = $this->makeGithubSource();
        $metricsBySourceType = [
            ExtensionSource::TYPE_GITHUB => [SourceMetricMeasurement::METRIC_STARS => 200.0],
        ];
        $trendDeltasBySourceType = [
            ExtensionSource::TYPE_GITHUB => [
                SourceMetricMeasurement::METRIC_STARS => ['delta1d' => null, 'delta7d' => 12.0, 'delta30d' => 30.0],
            ],
        ];

        $item = $mapper->mapExtension($extension, [$source], $metricsBySourceType, $trendDeltasBySourceType);

        $metrics = $item->sources[0]->metrics;
        self::assertArrayNotHasKey('starsDelta1d', $metrics);
        self::assertSame(12, $metrics['starsDelta7d']);
        self::assertSame(30, $metrics['starsDelta30d']);
        self::assertArrayNotHasKey('downloadsDelta7d', $metrics, 'GitHub sources must never emit EGO-only delta keys');
    }

    public function testMapExtensionWithoutTrendDeltasArgumentOmitsAllDeltaKeys(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension();
        $source = $this->makeEgoSource();
        $metricsBySourceType = [
            ExtensionSource::TYPE_EGO => [SourceMetricMeasurement::METRIC_DOWNLOADS => 500.0],
        ];

        $item = $mapper->mapExtension($extension, [$source], $metricsBySourceType);

        $metrics = $item->sources[0]->metrics;
        self::assertArrayNotHasKey('downloadsDelta1d', $metrics);
        self::assertArrayNotHasKey('downloadsDelta7d', $metrics);
        self::assertArrayNotHasKey('downloadsDelta30d', $metrics);
    }

    public function testMapExtensionGithubOnlyOmitsMissingMetricsEntirely(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension(['pk' => null, 'uuid' => 'gh-only@example']);
        $source = $this->makeGithubSource();
        $metricsBySourceType = [
            ExtensionSource::TYPE_GITHUB => [
                SourceMetricMeasurement::METRIC_STARS => 200.0,
                SourceMetricMeasurement::METRIC_FORKS => 30.0,
            ],
        ];

        $item = $mapper->mapExtension($extension, [$source], $metricsBySourceType);

        self::assertCount(1, $item->sources);
        $githubSource = $item->sources[0];
        self::assertSame(ExtensionSource::TYPE_GITHUB, $githubSource->sourceType);
        self::assertSame(['stars' => 200, 'forks' => 30], $githubSource->metrics);
        self::assertArrayNotHasKey('downloads', $githubSource->metrics, 'Missing GitHub downloads must be absent, not 0 or null');
        self::assertArrayNotHasKey('rating', $githubSource->metrics, 'Missing GitHub rating must be absent, not 0 or null');
        self::assertArrayNotHasKey('comments', $githubSource->metrics, 'Missing GitHub comment count must be absent, not 0 or null');
        self::assertArrayHasKey('repositoryUrl', $githubSource->links);
        self::assertArrayNotHasKey('pageUrl', $githubSource->links);
        self::assertArrayNotHasKey('installUrl', $githubSource->links);
    }

    public function testMapExtensionGithubOnlyOmitsReleaseUrlWhenAbsent(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension(['pk' => null, 'uuid' => 'gh-only@example']);
        $source = $this->makeGithubSource(['installUrl' => null]);

        $item = $mapper->mapExtension($extension, [$source], []);

        self::assertArrayNotHasKey('releaseUrl', $item->sources[0]->links);
        self::assertArrayHasKey('repositoryUrl', $item->sources[0]->links);
    }

    public function testMapExtensionDualSourceProducesOneItemWithBothSourcesAndUnionShellVersions(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension(['uuid' => 'dual@example', 'supportedShellVersions' => ['45']]);
        $egoSource = $this->makeEgoSource(['supportedShellVersions' => ['45']]);
        $githubSource = $this->makeGithubSource(['supportedShellVersions' => ['46', '47']]);

        $item = $mapper->mapExtension($extension, [$egoSource, $githubSource], []);

        self::assertCount(2, $item->sources);
        $sourceTypes = array_map(static fn ($s) => $s->sourceType, $item->sources);
        self::assertContains(ExtensionSource::TYPE_EGO, $sourceTypes);
        self::assertContains(ExtensionSource::TYPE_GITHUB, $sourceTypes);
        self::assertEqualsCanonicalizing(['45', '46', '47'], $item->supportedShellVersions);
    }

    public function testMapExtensionUpdatedAtUsesLatestSourceTimestamp(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension(['uuid' => 'dual@example']);
        $olderEgo = $this->makeEgoSource(['lastReleaseAt' => new DateTime('2024-01-01T00:00:00Z')]);
        $newerGithub = $this->makeGithubSource(['lastCommitAt' => new DateTime('2026-01-01T00:00:00Z'), 'lastReleaseAt' => null]);

        $item = $mapper->mapExtension($extension, [$olderEgo, $newerGithub], []);

        self::assertSame((new DateTime('2026-01-01T00:00:00Z'))->getTimestamp(), $item->recentSortValue);
        self::assertStringStartsWith('2026-01-01', $item->updatedAt);
    }

    /**
     * ExtensionSource::$updatedAt is row bookkeeping that every import run
     * stamps with the current time. If it counted as activity, every item's
     * updatedAt would collapse onto the last import run.
     */
    public function testMapExtensionUpdatedAtIgnoresSourceRowBookkeepingTimestamp(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension(['uuid' => 'stale@example']);
        $source = $this->makeGithubSource([
            'lastCommitAt' => new DateTime('2025-03-04T00:00:00Z'),
            'lastReleaseAt' => null,
            // Import ran long after the repository's last real activity.
            'updatedAt' => new DateTime('2026-08-17T19:22:41Z'),
        ]);

        $item = $mapper->mapExtension($extension, [$source], []);

        self::assertSame((new DateTime('2025-03-04T00:00:00Z'))->getTimestamp(), $item->recentSortValue);
        self::assertStringStartsWith('2025-03-04', $item->updatedAt);
    }

    public function testMapExtensionUpdatedAtFallsBackToExtensionDatesRatherThanImportTime(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension([
            'uuid' => 'noactivity@example',
            'lastChange' => new DateTime('2023-07-08T00:00:00Z'),
        ]);
        $source = $this->makeGithubSource([
            'lastCommitAt' => null,
            'lastReleaseAt' => null,
            'updatedAt' => new DateTime('2026-08-17T19:22:41Z'),
        ]);

        $item = $mapper->mapExtension($extension, [$source], []);

        self::assertSame((new DateTime('2023-07-08T00:00:00Z'))->getTimestamp(), $item->recentSortValue);
        self::assertStringStartsWith('2023-07-08', $item->updatedAt);
    }

    public function testFreshnessTimestampIsZeroWhenSourceReportsNoActivity(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $source = $this->makeGithubSource([
            'lastCommitAt' => null,
            'lastReleaseAt' => null,
            'updatedAt' => new DateTime('2026-08-17T19:22:41Z'),
        ]);

        self::assertSame(0, $mapper->freshnessTimestamp($source));
    }

    /**
     * A future lastReleaseAt/lastCommitAt is never a real fact yet - most
     * likely a legacy row corrupted by the since-fixed PK-date-estimation
     * bug - so it must be treated the same as "unknown" (0) rather than
     * inflate freshness/recency.
     */
    public function testFreshnessTimestampTreatsAFutureLastReleaseAtAsUnknown(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $now = new DateTime('2026-01-01T00:00:00Z');
        $source = $this->makeEgoSource(['lastReleaseAt' => new DateTime('2027-01-01T00:00:00Z')]);

        self::assertSame(0, $mapper->freshnessTimestamp($source, $now));
    }

    /**
     * mapExtension() must sanitize the same way for a source's persisted
     * future timestamp: the extension's own genuinely known (older)
     * lastChange must win instead, and the build must not fail.
     */
    public function testMapExtensionIgnoresAPersistedFutureSourceTimestamp(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $now = new DateTime('2026-01-01T00:00:00Z');
        $extension = $this->makeExtension([
            'uuid' => 'legacy-future-source@example',
            'lastChange' => new DateTime('2025-06-01T00:00:00Z'),
        ]);
        $source = $this->makeEgoSource(['lastReleaseAt' => new DateTime('2027-01-01T00:00:00Z')]);

        $item = $mapper->mapExtension($extension, [$source], [], [], $now);

        self::assertSame((new DateTime('2025-06-01T00:00:00Z'))->getTimestamp(), $item->recentSortValue);
        self::assertStringStartsWith('2025-06-01', $item->updatedAt);
    }

    /**
     * A persisted future creationDate (same legacy bug) must not surface as
     * createdAt either; it falls back to "now", matching this method's
     * existing behaviour for a genuinely missing creationDate.
     */
    public function testMapExtensionCreatedAtFallsBackToNowWhenCreationDateIsPersistedInTheFuture(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $now = new DateTime('2026-01-01T00:00:00Z');
        $extension = $this->makeExtension([
            'uuid' => 'legacy-future-creation@example',
            'creationDate' => new DateTime('2027-01-01T00:00:00Z'),
        ]);
        $source = $this->makeEgoSource();

        $item = $mapper->mapExtension($extension, [$source], [], [], $now);

        self::assertStringStartsWith('2026-01-01', $item->createdAt);
    }

    /**
     * The per-source lastReleaseAt/lastCommitAt displayed directly in the
     * snapshot must never show a future date from a corrupted legacy row;
     * omitting it (null) is safer than fabricating a substitute date.
     */
    public function testMapSourceOmitsAFutureLastReleaseAtRatherThanDisplayingIt(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $now = new DateTime('2026-01-01T00:00:00Z');
        $source = $this->makeEgoSource(['lastReleaseAt' => new DateTime('2027-01-01T00:00:00Z')]);

        $sourceItem = $mapper->mapSource($source, [], 'uuid@example', [], $now);

        self::assertNull($sourceItem->lastReleaseAt);
    }

    /**
     * End-to-end regression: a preallocated EGO version PK whose linear
     * estimate overshoots into the future ends up as the epoch sentinel on
     * Extension::$lastChange (see Extension::nonFutureDate()), which
     * EgoSourceMapper then copies verbatim onto ExtensionSource::$lastReleaseAt.
     * That epoch must never surface as a fabricated 1970 date here.
     */
    public function testMapSourceNeverSerializesTheEpochSentinelFromAPreallocatedFutureVersionPk(): void
    {
        $now = new DateTime('2026-01-01T00:00:00Z');
        $extension = new Extension();
        (new EgoExtensionMapper())->mapDataToEntity($extension, [
            'uuid' => 'preallocated@example',
            'name' => 'Preallocated Extension',
            'link' => '/extension/1/preallocated/',
            'icon' => '/static/extension-data/icons/1.png',
            'shell_version_map' => ['99' => ['pk' => 999999999, 'version' => 999]],
        ], true, $now);
        $source = (new EgoSourceMapper())->mapToSource($extension, null, $now);

        $sourceItem = (new ExtensionSnapshotMapper())->mapSource($source, [], $extension->uuid, [], $now);

        self::assertNull($sourceItem->lastReleaseAt);
    }

    public function testMapExtensionHasScreenshotIsTrueWhenAnySourceHasOne(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension(['screenshot' => null]);
        $source = $this->makeEgoSource(['displayScreenshot' => '/static/screenshot.png']);

        $item = $mapper->mapExtension($extension, [$source], []);

        self::assertTrue($item->hasScreenshot);
    }

    public function testMapExtensionHasScreenshotIsFalseWhenNoSourceOrExtensionHasOne(): void
    {
        $mapper = new ExtensionSnapshotMapper();
        $extension = $this->makeExtension(['screenshot' => null]);
        $source = $this->makeGithubSource();
        $source->displayScreenshot = null;

        $item = $mapper->mapExtension($extension, [$source], []);

        self::assertFalse($item->hasScreenshot);
    }

    public function testResolveEgoPageUrlKeepsAbsoluteUrls(): void
    {
        $mapper = new ExtensionSnapshotMapper();

        self::assertSame(
            'https://extensions.gnome.org/extension/12345/plaid/',
            $mapper->resolveEgoPageUrl('https://extensions.gnome.org/extension/12345/plaid/', '12345')
        );
    }

    public function testResolveEgoPageUrlPrefixesRelativeUrls(): void
    {
        $mapper = new ExtensionSnapshotMapper();

        self::assertSame(
            'https://extensions.gnome.org/extension/12345/plaid/',
            $mapper->resolveEgoPageUrl('/extension/12345/plaid/', '12345')
        );
    }

    public function testResolveEgoPageUrlFallsBackToExternalIdentifier(): void
    {
        $mapper = new ExtensionSnapshotMapper();

        self::assertSame(
            'https://extensions.gnome.org/extension/12345/',
            $mapper->resolveEgoPageUrl(null, '12345')
        );
    }
}
