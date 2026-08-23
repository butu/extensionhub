<?php

namespace App\Tests\Service\GitHub;

use App\Entity\ExtensionSource;
use App\Entity\SourceMetricMeasurement;
use App\Service\GitHub\ExtensionCandidate;
use App\Service\GitHub\SourceMapper;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Pure GitHub candidate -> ExtensionSource mapping, deliberately tested
 * without a database connection or any GitHub HTTP call.
 */
class SourceMapperTest extends TestCase
{
    private function makeCandidate(array $overrides = []): ExtensionCandidate
    {
        return new ExtensionCandidate(
            repositoryId: $overrides['repositoryId'] ?? 123456,
            fullName: $overrides['fullName'] ?? 'plyply99/Plaid',
            htmlUrl: $overrides['htmlUrl'] ?? 'https://github.com/plyply99/Plaid',
            stargazersCount: $overrides['stargazersCount'] ?? 42,
            forksCount: $overrides['forksCount'] ?? 7,
            uuid: $overrides['uuid'] ?? 'plaid@plyply99',
            shellVersion: array_key_exists('shellVersion', $overrides) ? $overrides['shellVersion'] : ['45', '46'],
            description: array_key_exists('description', $overrides) ? $overrides['description'] : 'Repo description',
            ownerLogin: array_key_exists('ownerLogin', $overrides) ? $overrides['ownerLogin'] : 'plyply99',
            ownerHtmlUrl: array_key_exists('ownerHtmlUrl', $overrides) ? $overrides['ownerHtmlUrl'] : 'https://github.com/plyply99',
            metadataName: array_key_exists('metadataName', $overrides) ? $overrides['metadataName'] : 'Plaid',
            metadataDescription: array_key_exists('metadataDescription', $overrides) ? $overrides['metadataDescription'] : 'Metadata description',
            lastCommitAt: array_key_exists('lastCommitAt', $overrides) ? $overrides['lastCommitAt'] : new DateTimeImmutable('2026-01-01T00:00:00Z'),
            installUrl: array_key_exists('installUrl', $overrides) ? $overrides['installUrl'] : 'https://github.com/plyply99/Plaid/releases/download/v1/plaid.zip',
            lastReleaseAt: array_key_exists('lastReleaseAt', $overrides) ? $overrides['lastReleaseAt'] : new DateTimeImmutable('2026-01-02T00:00:00Z'),
            screenshotUrl: $overrides['screenshotUrl'] ?? null,
            iconUrl: $overrides['iconUrl'] ?? null,
        );
    }

    public function testMapToSourceProducesSingleGithubSourceWithMatchingIdentifiers(): void
    {
        $mapper = new SourceMapper();
        $candidate = $this->makeCandidate(['repositoryId' => 999]);

        $source = $mapper->mapToSource($candidate);

        self::assertSame(ExtensionSource::TYPE_GITHUB, $source->sourceType);
        self::assertSame('999', $source->externalIdentifier);
    }

    public function testMapToSourceReusesExistingSourceInstanceInsteadOfCreatingASecondOne(): void
    {
        $mapper = new SourceMapper();
        $candidate = $this->makeCandidate();
        $existing = new ExtensionSource();
        $existing->id = 7;

        $result = $mapper->mapToSource($candidate, $existing);

        self::assertSame($existing, $result, 'Persistence must update the found source, never allocate a second one');
    }

    public function testMapToSourceCopiesUrlsAndPrefersMetadataDisplayFields(): void
    {
        $mapper = new SourceMapper();
        $candidate = $this->makeCandidate();

        $source = $mapper->mapToSource($candidate);

        self::assertSame($candidate->htmlUrl, $source->sourceUrl);
        self::assertSame($candidate->installUrl, $source->installUrl);
        self::assertSame($candidate->metadataName, $source->displayName);
        self::assertSame($candidate->metadataDescription, $source->displayDescription);
        self::assertSame(['45', '46'], $source->supportedShellVersions);
        self::assertEquals(new DateTime('2026-01-01T00:00:00Z'), $source->lastCommitAt);
        self::assertEquals(new DateTime('2026-01-02T00:00:00Z'), $source->lastReleaseAt);
    }

    public function testMapToSourceFallsBackToRepositoryFieldsWhenMetadataFieldsAreMissing(): void
    {
        $mapper = new SourceMapper();
        $candidate = $this->makeCandidate(['metadataName' => null, 'metadataDescription' => null]);

        $source = $mapper->mapToSource($candidate);

        self::assertSame($candidate->fullName, $source->displayName);
        self::assertSame($candidate->description, $source->displayDescription);
    }

    public function testMapToSourceTakesTheAlreadyResolvedIconFromTheCandidate(): void
    {
        $mapper = new SourceMapper();
        $url = 'https://raw.githubusercontent.com/plyply99/Plaid/a1b2c3d4e5f60718293a4b5c6d7e8f9012345678/logo.svg';

        $source = $mapper->mapToSource($this->makeCandidate(['iconUrl' => $url]));

        self::assertSame($url, $source->displayIcon);
    }

    public function testMapToSourceLeavesIconNullWhenNoneWasResolved(): void
    {
        $mapper = new SourceMapper();

        $source = $mapper->mapToSource($this->makeCandidate(['iconUrl' => null]));

        self::assertNull($source->displayIcon);
    }

    public function testMapToSourceTakesTheAlreadyResolvedScreenshotFromTheCandidate(): void
    {
        $mapper = new SourceMapper();
        $url = 'https://raw.githubusercontent.com/plyply99/Plaid/a1b2c3d4e5f60718293a4b5c6d7e8f9012345678/assets/shot.png';

        $source = $mapper->mapToSource($this->makeCandidate(['screenshotUrl' => $url]));

        self::assertSame($url, $source->displayScreenshot);
    }

    public function testMapToSourceLeavesScreenshotNullWhenNoneWasResolved(): void
    {
        $mapper = new SourceMapper();

        $source = $mapper->mapToSource($this->makeCandidate(['screenshotUrl' => null]));

        self::assertNull($source->displayScreenshot);
    }

    public function testMapToSourceAllowsMissingReleaseUrl(): void
    {
        $mapper = new SourceMapper();
        $candidate = $this->makeCandidate(['installUrl' => null, 'lastReleaseAt' => null]);

        $source = $mapper->mapToSource($candidate);

        self::assertNull($source->installUrl);
        self::assertNull($source->lastReleaseAt);
        self::assertSame($candidate->htmlUrl, $source->sourceUrl, 'Repository URL must still be stored when no release exists.');
    }

    public function testBuildMetricMeasurementsProducesOnlyStarsAndForks(): void
    {
        $mapper = new SourceMapper();
        $source = new ExtensionSource();
        $source->id = 1;
        $candidate = $this->makeCandidate(['stargazersCount' => 42, 'forksCount' => 7]);
        $measuredAt = new DateTime('2026-01-01 00:00:00');

        $measurements = $mapper->buildMetricMeasurements($source, $candidate, $measuredAt);

        self::assertCount(2, $measurements);

        $byType = [];
        foreach ($measurements as $measurement) {
            self::assertInstanceOf(SourceMetricMeasurement::class, $measurement);
            self::assertSame($source, $measurement->source);
            self::assertSame($measuredAt, $measurement->measuredAt);
            $byType[$measurement->metricType] = $measurement->value;
        }

        self::assertSame(42.0, $byType[SourceMetricMeasurement::METRIC_STARS]);
        self::assertSame(7.0, $byType[SourceMetricMeasurement::METRIC_FORKS]);
    }

    public function testBuildMetricMeasurementsNeverProducesDownloadsRatingOrRatingCount(): void
    {
        $mapper = new SourceMapper();
        $source = new ExtensionSource();
        $source->id = 1;
        $candidate = $this->makeCandidate();
        $measuredAt = new DateTime('2026-01-01 00:00:00');

        $measurements = $mapper->buildMetricMeasurements($source, $candidate, $measuredAt);

        $types = array_map(static fn (SourceMetricMeasurement $m) => $m->metricType, $measurements);
        self::assertNotContains(SourceMetricMeasurement::METRIC_DOWNLOADS, $types);
        self::assertNotContains(SourceMetricMeasurement::METRIC_RATING, $types);
        self::assertNotContains(SourceMetricMeasurement::METRIC_RATING_COUNT, $types);
    }

    public function testNormalizeShellVersionsWrapsASingleString(): void
    {
        $mapper = new SourceMapper();

        self::assertSame(['45'], $mapper->normalizeShellVersions('45'));
    }

    public function testNormalizeShellVersionsTrimsAndRejectsEmptyString(): void
    {
        $mapper = new SourceMapper();

        self::assertSame(['45'], $mapper->normalizeShellVersions('  45  '));
        self::assertSame([], $mapper->normalizeShellVersions('   '));
        self::assertSame([], $mapper->normalizeShellVersions(''));
    }

    public function testNormalizeShellVersionsKeepsValidArrayElements(): void
    {
        $mapper = new SourceMapper();

        self::assertSame(['44', '45', '46'], $mapper->normalizeShellVersions(['44', '45', '46']));
    }

    public function testNormalizeShellVersionsFiltersInvalidElementsInsteadOfFailing(): void
    {
        $mapper = new SourceMapper();

        $result = $mapper->normalizeShellVersions([null, '', '  ', '46', 47, ['nested'], true]);

        self::assertSame(['46', '47'], $result);
    }

    public function testNormalizeShellVersionsDeduplicatesElements(): void
    {
        $mapper = new SourceMapper();

        self::assertSame(['45'], $mapper->normalizeShellVersions(['45', '45']));
    }

    public function testExternalIdentifierForUsesTheStableRepositoryId(): void
    {
        $mapper = new SourceMapper();
        $candidate = $this->makeCandidate(['repositoryId' => 555]);

        self::assertSame('555', $mapper->externalIdentifierFor($candidate));
    }
}
