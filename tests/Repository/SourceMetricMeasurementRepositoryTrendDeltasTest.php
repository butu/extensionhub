<?php

namespace App\Tests\Repository;

use App\Entity\Extension;
use App\Entity\ExtensionSource;
use App\Entity\SourceMetricMeasurement;
use App\Repository\SourceMetricMeasurementRepository;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

/**
 * Verifies findTrendDeltasForSources() computes the latest value plus
 * 1/7/30-day deltas per (source, metricType) directly in SQL.
 *
 * Deliberately avoids the project's own Postgres connection (unavailable in
 * this environment/CI) and the Symfony kernel/container entirely: an
 * isolated Doctrine ORM EntityManager is wired directly to an in-memory
 * SQLite database, mirroring
 * SourceMetricMeasurementRepositoryLatestValuesTest.
 */
class SourceMetricMeasurementRepositoryTrendDeltasTest extends TestCase
{
    private EntityManager $entityManager;
    private SourceMetricMeasurementRepository $repository;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../../src/Entity'],
            isDevMode: true,
        );
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER, true));

        // DBAL 3 deprecates connections without an explicit schema manager
        // factory; DefaultSchemaManagerFactory is the DBAL 4 default.
        $config->setSchemaManagerFactory(new DefaultSchemaManagerFactory());

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema([
            $this->entityManager->getClassMetadata(Extension::class),
            $this->entityManager->getClassMetadata(ExtensionSource::class),
            $this->entityManager->getClassMetadata(SourceMetricMeasurement::class),
        ]);

        $this->repository = new SourceMetricMeasurementRepository($this->makeRegistry());
    }

    public function testReturnsEmptyArrayForEmptySourceIdsWithoutQuerying(): void
    {
        self::assertSame([], $this->repository->findTrendDeltasForSources([], new DateTime('2026-01-08')));
    }

    public function testComputesSevenDayDeltaFromTheNearestBaselineAtOrBeforeCutoff(): void
    {
        $source = $this->makeSource(ExtensionSource::TYPE_GITHUB);

        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_STARS, 100.0, new DateTime('2026-01-01'));
        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_STARS, 150.0, new DateTime('2026-01-08'));

        $result = $this->repository->findTrendDeltasForSources([$source->id], new DateTime('2026-01-08'));

        $entry = $result[$source->id][SourceMetricMeasurement::METRIC_STARS];
        self::assertSame(150.0, $entry['latest']);
        self::assertSame(50.0, $entry['delta7d'], 'delta7d = latest(150) - baseline at -7d(100)');
    }

    public function testUsesTheNearestBaselineAtOrBeforeCutoffWhenNoExactDayMatchExists(): void
    {
        $source = $this->makeSource(ExtensionSource::TYPE_EGO);

        // No measurement exactly 7 days before "now"; the nearest earlier
        // one (day -9) must be used as the baseline instead of null.
        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_DOWNLOADS, 400.0, new DateTime('2025-12-30'));
        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_DOWNLOADS, 460.0, new DateTime('2026-01-08'));

        $result = $this->repository->findTrendDeltasForSources([$source->id], new DateTime('2026-01-08'));

        $entry = $result[$source->id][SourceMetricMeasurement::METRIC_DOWNLOADS];
        self::assertSame(60.0, $entry['delta7d']);
    }

    public function testDeltaIsNullWhenNoBaselineExistsAtOrBeforeTheCutoff(): void
    {
        $source = $this->makeSource(ExtensionSource::TYPE_EGO);

        // Only a single, very recent measurement: no 7-day-old baseline yet.
        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_DOWNLOADS, 10.0, new DateTime('2026-01-08'));

        $result = $this->repository->findTrendDeltasForSources([$source->id], new DateTime('2026-01-08'));

        $entry = $result[$source->id][SourceMetricMeasurement::METRIC_DOWNLOADS];
        self::assertSame(10.0, $entry['latest']);
        self::assertNull($entry['delta1d']);
        self::assertNull($entry['delta7d']);
        self::assertNull($entry['delta30d']);
    }

    public function testComputesAllThreeWindowsIndependently(): void
    {
        $source = $this->makeSource(ExtensionSource::TYPE_GITHUB);

        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_STARS, 10.0, new DateTime('2025-12-09')); // -30d baseline
        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_STARS, 40.0, new DateTime('2026-01-01')); // -7d baseline
        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_STARS, 48.0, new DateTime('2026-01-07')); // -1d baseline
        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_STARS, 50.0, new DateTime('2026-01-08')); // latest

        $result = $this->repository->findTrendDeltasForSources([$source->id], new DateTime('2026-01-08'));

        $entry = $result[$source->id][SourceMetricMeasurement::METRIC_STARS];
        self::assertSame(50.0, $entry['latest']);
        self::assertSame(2.0, $entry['delta1d']);
        self::assertSame(10.0, $entry['delta7d']);
        self::assertSame(40.0, $entry['delta30d']);
    }

    public function testNegativeDeltaIsReturnedAsIsNotClamped(): void
    {
        $source = $this->makeSource(ExtensionSource::TYPE_GITHUB);

        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_STARS, 100.0, new DateTime('2026-01-01'));
        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_STARS, 90.0, new DateTime('2026-01-08'));

        $result = $this->repository->findTrendDeltasForSources([$source->id], new DateTime('2026-01-08'));

        self::assertSame(-10.0, $result[$source->id][SourceMetricMeasurement::METRIC_STARS]['delta7d']);
    }

    public function testKeepsDifferentSourcesAndMetricTypesIndependent(): void
    {
        $sourceA = $this->makeSource(ExtensionSource::TYPE_EGO);
        $sourceB = $this->makeSource(ExtensionSource::TYPE_GITHUB);

        $this->insertMeasurement($sourceA->id, SourceMetricMeasurement::METRIC_DOWNLOADS, 500.0, new DateTime('2026-01-01'));
        $this->insertMeasurement($sourceA->id, SourceMetricMeasurement::METRIC_DOWNLOADS, 600.0, new DateTime('2026-01-08'));
        $this->insertMeasurement($sourceB->id, SourceMetricMeasurement::METRIC_STARS, 10.0, new DateTime('2026-01-01'));
        $this->insertMeasurement($sourceB->id, SourceMetricMeasurement::METRIC_STARS, 11.0, new DateTime('2026-01-08'));

        $result = $this->repository->findTrendDeltasForSources([$sourceA->id, $sourceB->id], new DateTime('2026-01-08'));

        self::assertSame(100.0, $result[$sourceA->id][SourceMetricMeasurement::METRIC_DOWNLOADS]['delta7d']);
        self::assertSame(1.0, $result[$sourceB->id][SourceMetricMeasurement::METRIC_STARS]['delta7d']);
    }

    private function makeSource(string $sourceType): ExtensionSource
    {
        static $counter = 0;
        $counter++;

        $extension = new Extension();
        $extension->name = 'Extension ' . $counter;
        $extension->link = '/extension/' . $counter . '/';
        $extension->icon = '/icons/' . $counter . '.png';
        $extension->creator = 'creator-' . $counter;
        $extension->creator_url = 'https://example.com/creator-' . $counter;
        $extension->uuid = 'uuid-' . $counter;
        $extension->description = 'Description ' . $counter;
        $extension->creationDate = new DateTime('2024-01-01');
        $extension->lastChange = new DateTime('2024-01-01');
        $this->entityManager->persist($extension);

        $source = new ExtensionSource();
        $source->extension = $extension;
        $source->sourceType = $sourceType;
        $source->externalIdentifier = 'external-' . $counter;
        $source->createdAt = new DateTime('2024-01-01');
        $source->updatedAt = new DateTime('2024-01-01');
        $this->entityManager->persist($source);

        $this->entityManager->flush();

        return $source;
    }

    private function insertMeasurement(int $sourceId, string $metricType, float $value, DateTime $measuredAt): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO source_metric_measurement (source_id, metric_type, measured_at, value) VALUES (:sourceId, :metricType, :measuredAt, :value)',
            [
                'sourceId' => $sourceId,
                'metricType' => $metricType,
                'measuredAt' => $measuredAt,
                'value' => $value,
            ],
            [
                'measuredAt' => Types::DATETIME_MUTABLE,
            ]
        );
    }

    private function makeRegistry(): ManagerRegistry
    {
        $entityManager = $this->entityManager;

        return new class($entityManager) implements ManagerRegistry {
            public function __construct(private EntityManager $entityManager)
            {
            }

            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            public function getConnection(?string $name = null): Connection
            {
                return $this->entityManager->getConnection();
            }

            public function getConnections(): array
            {
                return ['default' => $this->entityManager->getConnection()];
            }

            public function getConnectionNames(): array
            {
                return ['default'];
            }

            public function getDefaultManagerName(): string
            {
                return 'default';
            }

            public function getManager(?string $name = null): ObjectManager
            {
                return $this->entityManager;
            }

            public function getManagers(): array
            {
                return ['default' => $this->entityManager];
            }

            public function resetManager(?string $name = null): ObjectManager
            {
                return $this->entityManager;
            }

            public function getManagerNames(): array
            {
                return ['default'];
            }

            public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
            {
                return $this->entityManager->getRepository($persistentObject);
            }

            public function getManagerForClass(string $class): ?ObjectManager
            {
                return $this->entityManager;
            }
        };
    }
}
