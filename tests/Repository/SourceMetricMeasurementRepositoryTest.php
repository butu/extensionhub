<?php

namespace App\Tests\Repository;

use App\Repository\SourceMetricMeasurementRepository;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Pure retention-cutoff date math, tested without a database connection.
 */
class SourceMetricMeasurementRepositoryTest extends TestCase
{
    public function testRetentionCutoffDefaultsToConfiguredRetentionDaysBeforeNow(): void
    {
        $now = new DateTime('2026-06-15 12:00:00');

        $cutoff = SourceMetricMeasurementRepository::retentionCutoff($now);

        $expected = (clone $now)->modify('-' . SourceMetricMeasurementRepository::RETENTION_DAYS . ' days');
        self::assertEquals($expected, $cutoff);
    }

    public function testRetentionDaysCoversAtLeastThreeHundredSixtyFiveDays(): void
    {
        self::assertGreaterThanOrEqual(365, SourceMetricMeasurementRepository::RETENTION_DAYS);
    }

    public function testRetentionCutoffAcceptsCustomRetentionWindow(): void
    {
        $now = new DateTime('2026-06-15 00:00:00');

        $cutoff = SourceMetricMeasurementRepository::retentionCutoff($now, 30);

        self::assertEquals(new DateTime('2026-05-16 00:00:00'), $cutoff);
    }

    public function testRetentionCutoffAcceptsImmutableDateWithoutMutatingIt(): void
    {
        $now = new DateTimeImmutable('2026-06-15 00:00:00');

        $cutoff = SourceMetricMeasurementRepository::retentionCutoff($now);

        self::assertInstanceOf(DateTime::class, $cutoff);
        self::assertSame('2025-06-15 00:00:00', $cutoff->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-15 00:00:00', $now->format('Y-m-d H:i:s'));
    }
}
