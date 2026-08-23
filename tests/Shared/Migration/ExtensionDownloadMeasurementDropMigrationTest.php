<?php

namespace App\Tests\Shared\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260901020000;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/migrations/Version20260901020000.php';

/**
 * Unit-tests the extension_download_measurement drop guard against a mocked
 * Connection. See MessengerMessagesDropMigrationTest for why migrations are
 * require_once'd instead of autoloaded/executed here.
 */
class ExtensionDownloadMeasurementDropMigrationTest extends TestCase
{
    public function testAbortsWhenAnyLegacyRowHasNoSourceIdOrNoMatchingMetric(): void
    {
        $connection = $this->connectionMock();
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 42],
            ['id' => 43],
        ]);

        $migration = new Version20260901020000($connection, new NullLogger());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2 row(s)');

        $migration->up($this->createMock(Schema::class));
    }

    public function testDropsTableWhenEveryLegacyRowIsSuperseded(): void
    {
        $connection = $this->connectionMock();
        $connection->method('fetchAllAssociative')->willReturn([]);

        $migration = new Version20260901020000($connection, new NullLogger());
        $migration->up($this->createMock(Schema::class));

        $statements = array_map(static fn ($query) => $query->getStatement(), $migration->getSql());

        self::assertStringContainsString('DROP TABLE extension_download_measurement', implode("\n", $statements));
    }

    public function testMigratesLegacyHistoryBeforeDroppingTheTable(): void
    {
        $connection = $this->connectionMock();
        $connection->method('fetchAllAssociative')->willReturn([]);
        $queries = [];
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$queries): int {
                $queries[] = $sql;

                return 0;
            }
        );

        $migration = new Version20260901020000($connection, new NullLogger());
        $migration->up($this->createMock(Schema::class));

        $statements = implode("\n", $queries);

        self::assertStringContainsString('UPDATE extension_download_measurement edm', $statements);
        self::assertStringContainsString("es.source_type = 'ego'", $statements);
        self::assertStringContainsString('INSERT INTO source_metric_measurement', $statements);
        self::assertStringContainsString("'downloads'", $statements);
    }

    public function testDownRecreatesTheMysqlTableByDefault(): void
    {
        $connection = $this->connectionMock('mysql');

        $migration = new Version20260901020000($connection, new NullLogger());
        $migration->down($this->createMock(Schema::class));

        $statements = array_map(static fn ($query) => $query->getStatement(), $migration->getSql());
        $combined = implode("\n", $statements);

        self::assertStringContainsString('CREATE TABLE extension_download_measurement', $combined);
        self::assertStringContainsString('AUTO_INCREMENT', $combined);
        self::assertStringContainsString('source_id INT DEFAULT NULL', $combined);
    }

    public function testDownRecreatesThePostgresTableWhenOnThatPlatform(): void
    {
        $connection = $this->connectionMock('postgresql');

        $migration = new Version20260901020000($connection, new NullLogger());
        $migration->down($this->createMock(Schema::class));

        $statements = array_map(static fn ($query) => $query->getStatement(), $migration->getSql());
        $combined = implode("\n", $statements);

        self::assertStringContainsString('CREATE TABLE extension_download_measurement', $combined);
        self::assertStringContainsString('SERIAL', $combined);
    }

    /**
     * @return Connection&MockObject
     */
    private function connectionMock(string $platformName = 'mysql'): Connection
    {
        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getName')->willReturn($platformName);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createMock(AbstractSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return $connection;
    }
}
