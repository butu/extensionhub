<?php

namespace App\Tests\Service;

use App\Entity\Extension;
use App\Service\EgoExtensionMapper;
use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Pure EGO extension-query-payload -> Extension mapping logic, extracted from
 * UpdateExtensionsCommand. Deliberately tested without a database connection.
 */
class EgoExtensionMapperTest extends TestCase
{
    private function rawExtensionData(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'plaid@plyply99',
            'name' => 'Plaid',
            'creator' => 'plyply99',
            'creator_url' => '/accounts/profile/plyply99/',
            'pk' => 12345,
            'description' => 'A nice extension',
            'link' => '/extension/12345/plaid/',
            'icon' => '/static/extension-data/icons/12345.png',
            'screenshot' => '/static/extension-data/screenshots/12345.png',
            'downloads' => 500,
            'url' => '/review/download/12345/plaid.shell-extension.zip',
            'shell_version_map' => [
                '45' => ['pk' => 64995, 'version' => 102],
                '46' => ['pk' => 64995, 'version' => 102],
                '3.38' => ['pk' => 19642, 'version' => 69],
            ],
        ], $overrides);
    }

    public function testMapsPlainScalarFields(): void
    {
        $mapper = new EgoExtensionMapper();
        $extension = new Extension();

        $mapper->mapDataToEntity($extension, $this->rawExtensionData(), true);

        self::assertSame('plaid@plyply99', $extension->uuid);
        self::assertSame('Plaid', $extension->name);
        self::assertSame('plyply99', $extension->creator);
        self::assertSame('/accounts/profile/plyply99/', $extension->creator_url);
        self::assertSame(12345, $extension->pk);
        self::assertSame('A nice extension', $extension->description);
        self::assertSame('/extension/12345/plaid/', $extension->link);
        self::assertSame('/static/extension-data/icons/12345.png', $extension->icon);
        self::assertSame('/static/extension-data/screenshots/12345.png', $extension->screenshot);
        self::assertSame(500, $extension->downloads);
        self::assertSame('/review/download/12345/plaid.shell-extension.zip', $extension->sourceUrl);
    }

    public function testExtractsSortedUniqueSupportedShellVersions(): void
    {
        $mapper = new EgoExtensionMapper();
        $extension = new Extension();

        $mapper->mapDataToEntity($extension, $this->rawExtensionData(), true);

        self::assertSame(['3.38', '45', '46'], $extension->supportedShellVersions);
    }

    public function testMissingShellVersionMapYieldsNullSupportedShellVersions(): void
    {
        $mapper = new EgoExtensionMapper();
        $extension = new Extension();

        $mapper->mapDataToEntity($extension, $this->rawExtensionData(['shell_version_map' => []]), true);

        self::assertNull($extension->supportedShellVersions);
    }

    public function testDerivesFirstAndLatestVersionPkFromShellVersionMap(): void
    {
        $mapper = new EgoExtensionMapper();
        $extension = new Extension();

        $mapper->mapDataToEntity($extension, $this->rawExtensionData(), true);

        self::assertSame(19642, $extension->firstVersionPk);
        self::assertSame(64995, $extension->latestVersionPk);
        self::assertEquals(Extension::estimateDateFromPk(64995), $extension->lastChange);
    }

    public function testNewExtensionAlwaysGetsCreationDateSetToNow(): void
    {
        $mapper = new EgoExtensionMapper();
        $extension = new Extension();
        $now = new DateTime('2026-01-01 00:00:00');

        $mapper->mapDataToEntity($extension, $this->rawExtensionData(), true, $now);

        self::assertEquals($now, $extension->creationDate);
    }

    public function testExistingExtensionWithoutCreationDateGetsItEstimatedFromFirstVersionPk(): void
    {
        $mapper = new EgoExtensionMapper();
        $extension = new Extension();
        $extension->creationDate = null;

        $mapper->mapDataToEntity($extension, $this->rawExtensionData(), false);

        self::assertEquals(Extension::estimateDateFromPk(19642), $extension->creationDate);
    }

    public function testExistingExtensionKeepsItsCreationDateUnchanged(): void
    {
        $mapper = new EgoExtensionMapper();
        $extension = new Extension();
        $originalCreationDate = new DateTime('2020-05-01 00:00:00');
        $extension->creationDate = $originalCreationDate;

        $mapper->mapDataToEntity($extension, $this->rawExtensionData(), false);

        self::assertSame($originalCreationDate, $extension->creationDate);
    }

    /**
     * EGO pre-allocates version-map PKs ahead of their actual use, so the
     * linear PK->date estimate can overshoot "now" for a very recent
     * (large) latestVersionPk. lastChange feeds ExtensionSource::lastReleaseAt
     * and, from there, the public snapshot's updatedAt/recentSortValue and
     * freshness score: a future estimate must never be used as-is, and must
     * not become "now"/today either, since that would still inflate
     * recency/freshness. With no already-known older lastChange to fall
     * back to (a brand new extension has none yet), the result must be the
     * "old, unknown" sentinel instead.
     */
    public function testFallsBackToOldUnknownSentinelForLastChangeWithNoPriorKnownDate(): void
    {
        $mapper = new EgoExtensionMapper();
        $extension = new Extension();
        $now = new DateTime('2026-01-01 00:00:00');

        $mapper->mapDataToEntity($extension, $this->rawExtensionData([
            'shell_version_map' => [
                '99' => ['pk' => 999999999, 'version' => 999],
            ],
        ]), true, $now);

        self::assertSame(0, $extension->lastChange->getTimestamp());
        self::assertLessThan($now->getTimestamp(), $extension->lastChange->getTimestamp());
    }

    /**
     * When a re-import's new latestVersionPk estimate overshoots into the
     * future but the extension already has a genuinely known (older)
     * lastChange from a previous run, that known date must be preserved
     * rather than replaced by a future guess or by "now".
     */
    public function testPreservesPriorLastChangeWhenNewEstimateWouldBeInTheFuture(): void
    {
        $mapper = new EgoExtensionMapper();
        $extension = new Extension();
        $priorLastChange = new DateTime('2024-05-01 00:00:00');
        $extension->lastChange = $priorLastChange;
        $now = new DateTime('2026-01-01 00:00:00');

        $mapper->mapDataToEntity($extension, $this->rawExtensionData([
            'shell_version_map' => [
                '99' => ['pk' => 999999999, 'version' => 999],
            ],
        ]), false, $now);

        self::assertEquals($priorLastChange, $extension->lastChange);
    }

    /**
     * Same overshoot risk applies to the firstVersionPk-estimated
     * creationDate fallback for an existing extension that never had one
     * persisted yet; there is no known-older creationDate to preserve in
     * that case, so it must fall back to the "old, unknown" sentinel.
     */
    public function testFallsBackToOldUnknownSentinelForCreationDateWithNoPriorKnownDate(): void
    {
        $mapper = new EgoExtensionMapper();
        $extension = new Extension();
        $extension->creationDate = null;
        $now = new DateTime('2026-01-01 00:00:00');

        $mapper->mapDataToEntity($extension, $this->rawExtensionData([
            'shell_version_map' => [
                '99' => ['pk' => 999999999, 'version' => 999],
            ],
        ]), false, $now);

        self::assertSame(0, $extension->creationDate->getTimestamp());
    }

    public function testFallsBackToNowForBothDatesWithoutAShellVersionMap(): void
    {
        $mapper = new EgoExtensionMapper();
        $extension = new Extension();
        $extension->creationDate = null;
        $now = new DateTime('2026-02-02 00:00:00');

        $mapper->mapDataToEntity($extension, $this->rawExtensionData(['shell_version_map' => []]), false, $now);

        self::assertEquals($now, $extension->creationDate);
        self::assertEquals($now, $extension->lastChange);
    }

    public function testOptionalFieldsDefaultToNullWhenAbsent(): void
    {
        $mapper = new EgoExtensionMapper();
        $extension = new Extension();
        $data = $this->rawExtensionData();
        unset($data['creator'], $data['creator_url'], $data['pk'], $data['description'], $data['screenshot'], $data['downloads'], $data['url']);

        $mapper->mapDataToEntity($extension, $data, true);

        self::assertNull($extension->creator);
        self::assertNull($extension->creator_url);
        self::assertNull($extension->pk);
        self::assertNull($extension->description);
        self::assertNull($extension->screenshot);
        self::assertNull($extension->downloads);
        self::assertNull($extension->sourceUrl);
    }
}
