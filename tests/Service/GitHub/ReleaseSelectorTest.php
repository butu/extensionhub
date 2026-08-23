<?php

namespace App\Tests\Service\GitHub;

use App\Service\GitHub\Release;
use App\Service\GitHub\ReleaseAsset;
use App\Service\GitHub\ReleaseSelector;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Pure release-asset selection against an already-loaded release list,
 * deliberately tested without any GitHub HTTP call.
 */
class ReleaseSelectorTest extends TestCase
{
    private function zipAsset(string $name = 'plaid.zip'): ReleaseAsset
    {
        return new ReleaseAsset(name: $name, downloadUrl: 'https://github.com/example/plaid/releases/download/v1/' . $name);
    }

    private function release(array $overrides = []): Release
    {
        return new Release(
            tagName: $overrides['tagName'] ?? 'v1.0.0',
            draft: $overrides['draft'] ?? false,
            prerelease: $overrides['prerelease'] ?? false,
            publishedAt: array_key_exists('publishedAt', $overrides)
                ? $overrides['publishedAt']
                : new DateTimeImmutable('2026-01-01T00:00:00Z'),
            assets: $overrides['assets'] ?? [$this->zipAsset()],
        );
    }

    public function testSelectsTheOnlyPublishedReleaseWithZipAsset(): void
    {
        $selector = new ReleaseSelector();
        $asset = $this->zipAsset('only.zip');
        $release = $this->release(['assets' => [$asset]]);

        $result = $selector->selectInstallableRelease([$release]);

        self::assertSame($asset, $result);
    }

    public function testSelectsTheNewestPublishedReleaseAmongSeveral(): void
    {
        $selector = new ReleaseSelector();
        $older = $this->release(['tagName' => 'v1.0.0', 'publishedAt' => new DateTimeImmutable('2025-01-01T00:00:00Z'), 'assets' => [$this->zipAsset('old.zip')]]);
        $newer = $this->release(['tagName' => 'v2.0.0', 'publishedAt' => new DateTimeImmutable('2026-01-01T00:00:00Z'), 'assets' => [$this->zipAsset('new.zip')]]);

        $result = $selector->selectInstallableRelease([$older, $newer]);

        self::assertSame('new.zip', $result->name);
    }

    public function testIgnoresPrereleaseEvenWhenNewest(): void
    {
        $selector = new ReleaseSelector();
        $stable = $this->release(['tagName' => 'v1.0.0', 'publishedAt' => new DateTimeImmutable('2025-01-01T00:00:00Z'), 'assets' => [$this->zipAsset('stable.zip')]]);
        $pre = $this->release(['tagName' => 'v2.0.0-beta', 'prerelease' => true, 'publishedAt' => new DateTimeImmutable('2026-01-01T00:00:00Z'), 'assets' => [$this->zipAsset('pre.zip')]]);

        $result = $selector->selectInstallableRelease([$stable, $pre]);

        self::assertSame('stable.zip', $result->name);
    }

    public function testIgnoresDraftReleases(): void
    {
        $selector = new ReleaseSelector();
        $draft = $this->release(['draft' => true, 'assets' => [$this->zipAsset('draft.zip')]]);

        $result = $selector->selectInstallableRelease([$draft]);

        self::assertNull($result);
    }

    public function testReturnsNullWhenNoReleaseHasAZipAsset(): void
    {
        $selector = new ReleaseSelector();
        $release = $this->release(['assets' => [new ReleaseAsset('source.tar.gz', 'https://example.com/source.tar.gz')]]);

        $result = $selector->selectInstallableRelease([$release]);

        self::assertNull($result);
    }

    public function testReturnsNullForEmptyReleaseList(): void
    {
        $selector = new ReleaseSelector();

        self::assertNull($selector->selectInstallableRelease([]));
    }

    public function testSkipsPrereleaseAndFallsBackToOlderStableZipRelease(): void
    {
        $selector = new ReleaseSelector();
        $stableOlder = $this->release(['tagName' => 'v1.0.0', 'publishedAt' => new DateTimeImmutable('2024-01-01T00:00:00Z'), 'assets' => [$this->zipAsset('stable-old.zip')]]);
        $prereleaseNewer = $this->release(['tagName' => 'v2.0.0-rc1', 'prerelease' => true, 'publishedAt' => new DateTimeImmutable('2026-06-01T00:00:00Z'), 'assets' => [$this->zipAsset('rc.zip')]]);

        $result = $selector->selectInstallableRelease([$prereleaseNewer, $stableOlder]);

        self::assertSame('stable-old.zip', $result->name);
    }

    public function testPicksReleaseWithZipOverNewerReleaseWithoutZip(): void
    {
        $selector = new ReleaseSelector();
        $withZip = $this->release(['tagName' => 'v1.0.0', 'publishedAt' => new DateTimeImmutable('2025-01-01T00:00:00Z'), 'assets' => [$this->zipAsset('has-zip.zip')]]);
        $withoutZip = $this->release(['tagName' => 'v2.0.0', 'publishedAt' => new DateTimeImmutable('2026-01-01T00:00:00Z'), 'assets' => [new ReleaseAsset('checksums.txt', 'https://example.com/checksums.txt')]]);

        $result = $selector->selectInstallableRelease([$withoutZip, $withZip]);

        self::assertSame('has-zip.zip', $result->name);
    }
}
