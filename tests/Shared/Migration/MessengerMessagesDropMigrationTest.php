<?php

namespace App\Tests\Shared\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260901010000;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/migrations/Version20260901010000.php';

/**
 * Unit-tests the messenger_messages drop guard against a mocked Connection.
 * Migration classes are intentionally excluded from Composer autoloading
 * (see config/packages/doctrine_migrations.yaml, "should NOT be
 * autoloaded"), and this project never executes real migrations from tests,
 * so the class is require_once'd directly and exercised via addSql()
 * staging instead of a live database.
 */
class MessengerMessagesDropMigrationTest extends TestCase
{
    public function testAbortsWhenMessengerMessagesStillHasRows(): void
    {
        $connection = $this->connectionMock();
        $connection->method('fetchOne')->willReturn(3);

        $migration = new Version20260901010000($connection, new NullLogger());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('3 row(s)');

        $migration->up($this->createMock(Schema::class));
    }

    public function testDropsTableWhenMessengerMessagesIsEmpty(): void
    {
        $connection = $this->connectionMock();
        $connection->method('fetchOne')->willReturn(0);

        $migration = new Version20260901010000($connection, new NullLogger());
        $migration->up($this->createMock(Schema::class));

        $statements = array_map(static fn ($query) => $query->getStatement(), $migration->getSql());

        self::assertCount(1, $statements);
        self::assertStringContainsString('DROP TABLE messenger_messages', $statements[0]);
    }

    public function testDownRecreatesTheTable(): void
    {
        $connection = $this->connectionMock();

        $migration = new Version20260901010000($connection, new NullLogger());
        $migration->down($this->createMock(Schema::class));

        $statements = array_map(static fn ($query) => $query->getStatement(), $migration->getSql());

        self::assertCount(1, $statements);
        self::assertStringContainsString('CREATE TABLE messenger_messages', $statements[0]);
    }

    /**
     * @return Connection&MockObject
     */
    private function connectionMock(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createMock(AbstractSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn($this->createMock(AbstractPlatform::class));

        return $connection;
    }
}
