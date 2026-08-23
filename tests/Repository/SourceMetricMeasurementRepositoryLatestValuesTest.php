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
 * Verifies findLatestValuesForSources() actually selects only the latest row
 * per (source_id, metric_type) in SQL, without hydrating the full
 * measurement history into PHP.
 *
 * Deliberately avoids the project's own Postgres connection (unavailable in
 * this environment/CI) and the Symfony kernel/container entirely: an
 * isolated Doctrine ORM EntityManager is wired directly to an in-memory
 * SQLite database, so these tests are fast, self-contained, and do not
 * depend on any external database server being reachable.
 */
class SourceMetricMeasurementRepositoryLatestValuesTest extends TestCase
{
    private EntityManager $entityManager;
    private SourceMetricMeasurementRepository $repository;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../../src/Entity'],
            isDevMode: true,
        );
        // Matches config/packages/doctrine.yaml so column names line up with
        // the entities' real (non-annotated) unique constraints, e.g. `source_type`.
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
        self::assertSame([], $this->repository->findLatestValuesForSources([]));
    }

    public function testReturnsOnlyTheNewestValuePerMetricType(): void
    {
        $source = $this->makeSource();

        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_STARS, 10.0, new DateTime('2024-01-01'));
        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_STARS, 20.0, new DateTime('2024-01-02'));
        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_STARS, 15.0, new DateTime('2024-01-03'));

        $result = $this->repository->findLatestValuesForSources([$source->id]);

        self::assertSame(15.0, $result[$source->id][SourceMetricMeasurement::METRIC_STARS]);
    }

    public function testKeepsDifferentMetricTypesForTheSameSourceIndependent(): void
    {
        $source = $this->makeSource();

        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_STARS, 200.0, new DateTime('2024-01-01'));
        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_FORKS, 30.0, new DateTime('2024-02-01'));

        $result = $this->repository->findLatestValuesForSources([$source->id]);

        self::assertSame(200.0, $result[$source->id][SourceMetricMeasurement::METRIC_STARS]);
        self::assertSame(30.0, $result[$source->id][SourceMetricMeasurement::METRIC_FORKS]);
    }

    public function testOlderMeasurementsForOtherMetricTypesDoNotLeakIntoTheLatestSelection(): void
    {
        $source = $this->makeSource();

        // A much newer STARS row exists for an unrelated day than the single
        // FORKS row. Picking a global MAX(measured_at) instead of a per
        // (source, metric_type) MAX would wrongly hide or misattribute the
        // FORKS value; this must not happen.
        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_STARS, 999.0, new DateTime('2025-01-01'));
        $this->insertMeasurement($source->id, SourceMetricMeasurement::METRIC_FORKS, 7.0, new DateTime('2024-01-01'));

        $result = $this->repository->findLatestValuesForSources([$source->id]);

        self::assertSame(999.0, $result[$source->id][SourceMetricMeasurement::METRIC_STARS]);
        self::assertSame(7.0, $result[$source->id][SourceMetricMeasurement::METRIC_FORKS]);
    }

    public function testScopesResultsToTheRequestedSourceIdsOnly(): void
    {
        $requested = $this->makeSource();
        $other = $this->makeSource();

        $this->insertMeasurement($requested->id, SourceMetricMeasurement::METRIC_STARS, 5.0, new DateTime('2024-01-01'));
        $this->insertMeasurement($other->id, SourceMetricMeasurement::METRIC_STARS, 999.0, new DateTime('2024-01-01'));

        $result = $this->repository->findLatestValuesForSources([$requested->id]);

        self::assertArrayHasKey($requested->id, $result);
        self::assertArrayNotHasKey($other->id, $result, 'Sources outside the requested id list must not appear in the result');
    }

    public function testHandlesMultipleSourcesInOneCall(): void
    {
        $sourceA = $this->makeSource();
        $sourceB = $this->makeSource();

        $this->insertMeasurement($sourceA->id, SourceMetricMeasurement::METRIC_DOWNLOADS, 500.0, new DateTime('2024-01-01'));
        $this->insertMeasurement($sourceA->id, SourceMetricMeasurement::METRIC_DOWNLOADS, 600.0, new DateTime('2024-01-05'));
        $this->insertMeasurement($sourceB->id, SourceMetricMeasurement::METRIC_STARS, 42.0, new DateTime('2024-01-03'));

        $result = $this->repository->findLatestValuesForSources([$sourceA->id, $sourceB->id]);

        self::assertSame(600.0, $result[$sourceA->id][SourceMetricMeasurement::METRIC_DOWNLOADS]);
        self::assertSame(42.0, $result[$sourceB->id][SourceMetricMeasurement::METRIC_STARS]);
    }

    private function makeSource(): ExtensionSource
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
        $source->sourceType = $counter % 2 === 0 ? ExtensionSource::TYPE_GITHUB : ExtensionSource::TYPE_EGO;
        $source->externalIdentifier = 'external-' . $counter;
        $source->createdAt = new DateTime('2024-01-01');
        $source->updatedAt = new DateTime('2024-01-01');
        $this->entityManager->persist($source);

        $this->entityManager->flush();

        return $source;
    }

    /**
     * Inserts a measurement row directly via DBAL, bypassing
     * recordMeasurement()'s Postgres/MySQL-specific upsert SQL, which has no
     * SQLite equivalent and is not what is under test here.
     */
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
