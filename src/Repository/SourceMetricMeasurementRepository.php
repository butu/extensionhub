<?php

namespace App\Repository;

use App\Entity\ExtensionSource;
use App\Entity\SourceMetricMeasurement;
use DateTime;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SourceMetricMeasurement>
 */
class SourceMetricMeasurementRepository extends ServiceEntityRepository
{
    /**
     * Minimum days of source metric history kept so per-source year-long trends stay available.
     */
    public const RETENTION_DAYS = 365;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SourceMetricMeasurement::class);
    }

    /**
     * Pure date-boundary helper, kept static so the retention policy is testable without a DB connection.
     */
    public static function retentionCutoff(DateTimeInterface $now, int $retentionDays = self::RETENTION_DAYS): DateTime
    {
        return DateTime::createFromInterface($now)->modify(sprintf('-%d days', $retentionDays));
    }

    /**
     * Upsert a single measurement so repeated runs for the same source/metric/day stay idempotent.
     */
    public function recordMeasurement(
        ExtensionSource $source,
        string $metricType,
        float $value,
        DateTimeInterface $measuredAt
    ): void {
        if ($source->id === null) {
            return;
        }

        $connection = $this->getEntityManager()->getConnection();
        $platform = $connection->getDatabasePlatform()->getName();

        $sql = $platform === 'postgresql'
            ? <<<'SQL'
                INSERT INTO source_metric_measurement (source_id, metric_type, measured_at, value)
                VALUES (:sourceId, :metricType, :measuredAt, :value)
                ON CONFLICT (source_id, metric_type, measured_at)
                DO UPDATE SET value = EXCLUDED.value
            SQL
            : <<<'SQL'
                INSERT INTO source_metric_measurement (source_id, metric_type, measured_at, value)
                VALUES (:sourceId, :metricType, :measuredAt, :value)
                ON DUPLICATE KEY UPDATE value = VALUES(value)
            SQL;

        $connection->executeStatement(
            $sql,
            [
                'sourceId' => $source->id,
                'metricType' => $metricType,
                'measuredAt' => $measuredAt,
                'value' => $value,
            ],
            [
                'measuredAt' => Types::DATETIME_MUTABLE,
            ]
        );
    }

    public function purgeOlderThan(DateTimeInterface $cutoff): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM source_metric_measurement WHERE measured_at < :cutoff',
            [
                'cutoff' => $cutoff,
            ],
            [
                'cutoff' => Types::DATETIME_MUTABLE,
            ]
        );
    }

    /**
     * Latest measured value per (source, metricType), for the given sources
     * only. Used by the snapshot builder to attach "current" source metrics
     * (e.g. GitHub stars/forks).
     *
     * Selects only the latest row per (source_id, metric_type) directly in
     * SQL via a MAX(measured_at) subquery join, so the full measurement
     * history (up to 365 days per source/metric) is never hydrated into PHP
     * just to pick the newest row.
     *
     * @param int[] $sourceIds
     * @return array<int, array<string, float>> sourceId => metricType => value
     */
    public function findLatestValuesForSources(array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT
                    m.source_id AS source_id,
                    m.metric_type AS metric_type,
                    m.value AS value
                FROM source_metric_measurement m
                INNER JOIN (
                    SELECT source_id, metric_type, MAX(measured_at) AS measured_at
                    FROM source_metric_measurement
                    WHERE source_id IN (:sourceIds)
                    GROUP BY source_id, metric_type
                ) latest
                    ON latest.source_id = m.source_id
                    AND latest.metric_type = m.metric_type
                    AND latest.measured_at = m.measured_at
                WHERE m.source_id IN (:sourceIds)
            SQL,
            [
                'sourceIds' => $sourceIds,
            ],
            [
                'sourceIds' => ArrayParameterType::INTEGER,
            ]
        )->fetchAllAssociative();

        $latest = [];
        foreach ($rows as $row) {
            $sourceId = (int) $row['source_id'];
            $latest[$sourceId][$row['metric_type']] = (float) $row['value'];
        }

        return $latest;
    }

    /**
     * Latest value plus 1/7/30-day deltas per (source, metricType), for the
     * given sources only. Used by the snapshot builder to compute trend
     * eligibility/ranking (e.g. EGO `downloadsDelta7d`, GitHub
     * `starsDelta7d`) without ever comparing raw values across source types.
     *
     * A delta is null when no measurement exists at or before the
     * respective cutoff, i.e. there is no baseline yet (a brand new
     * source/metric). Callers must treat that differently from "measured,
     * unchanged" (delta 0.0): a null delta means "not trend-eligible yet",
     * a zero delta means "eligible, but flat".
     *
     * Runs a fixed number of queries (1 latest + 3 baseline lookups)
     * regardless of how many sources are requested, so this stays cheap to
     * call once per snapshot build rather than once per source.
     *
     * @param int[] $sourceIds
     * @return array<int, array<string, array{latest: float, delta1d: ?float, delta7d: ?float, delta30d: ?float}>>
     */
    public function findTrendDeltasForSources(array $sourceIds, DateTimeInterface $now): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $latest = $this->findLatestValuesForSources($sourceIds);

        $baselinesByDeltaKey = [
            'delta1d' => $this->findBaselineValuesForSources($sourceIds, DateTime::createFromInterface($now)->modify('-1 day')),
            'delta7d' => $this->findBaselineValuesForSources($sourceIds, DateTime::createFromInterface($now)->modify('-7 days')),
            'delta30d' => $this->findBaselineValuesForSources($sourceIds, DateTime::createFromInterface($now)->modify('-30 days')),
        ];

        $result = [];
        foreach ($latest as $sourceId => $metrics) {
            foreach ($metrics as $metricType => $latestValue) {
                $entry = ['latest' => $latestValue, 'delta1d' => null, 'delta7d' => null, 'delta30d' => null];

                foreach ($baselinesByDeltaKey as $deltaKey => $baselineValues) {
                    $baselineValue = $baselineValues[$sourceId][$metricType] ?? null;
                    if ($baselineValue !== null) {
                        $entry[$deltaKey] = $latestValue - $baselineValue;
                    }
                }

                $result[$sourceId][$metricType] = $entry;
            }
        }

        return $result;
    }

    /**
     * Value at or nearest before $cutoff per (source, metricType), for use
     * as a trend baseline. Mirrors findLatestValuesForSources()'s
     * MAX(measured_at) self-join, bounded to measured_at <= $cutoff instead
     * of unbounded, so only one row per (source, metricType) is ever
     * hydrated.
     *
     * @param int[] $sourceIds
     * @return array<int, array<string, float>>
     */
    private function findBaselineValuesForSources(array $sourceIds, DateTimeInterface $cutoff): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT
                    m.source_id AS source_id,
                    m.metric_type AS metric_type,
                    m.value AS value
                FROM source_metric_measurement m
                INNER JOIN (
                    SELECT source_id, metric_type, MAX(measured_at) AS measured_at
                    FROM source_metric_measurement
                    WHERE source_id IN (:sourceIds) AND measured_at <= :cutoff
                    GROUP BY source_id, metric_type
                ) baseline
                    ON baseline.source_id = m.source_id
                    AND baseline.metric_type = m.metric_type
                    AND baseline.measured_at = m.measured_at
                WHERE m.source_id IN (:sourceIds)
            SQL,
            [
                'sourceIds' => $sourceIds,
                'cutoff' => $cutoff,
            ],
            [
                'sourceIds' => ArrayParameterType::INTEGER,
                'cutoff' => Types::DATETIME_MUTABLE,
            ]
        )->fetchAllAssociative();

        $values = [];
        foreach ($rows as $row) {
            $sourceId = (int) $row['source_id'];
            $values[$sourceId][$row['metric_type']] = (float) $row['value'];
        }

        return $values;
    }
}
