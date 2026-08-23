<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260817114339 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ExtensionSource and SourceMetricMeasurement tables, unique extension.uuid index, '
            . 'and nullable source_id columns on extension_comment/extension_download_measurement for the EGO backfill';
    }

    public function up(Schema $schema): void
    {
        $this->guardAgainstDuplicateExtensionUuids();

        $this->addSql(<<<'SQL'
            CREATE TABLE extension_source (
              id INT AUTO_INCREMENT NOT NULL,
              extension_id INT NOT NULL,
              source_type VARCHAR(32) NOT NULL,
              external_identifier VARCHAR(255) NOT NULL,
              source_url VARCHAR(512) DEFAULT NULL,
              install_url VARCHAR(512) DEFAULT NULL,
              display_name VARCHAR(255) DEFAULT NULL,
              display_description LONGTEXT DEFAULT NULL,
              display_icon VARCHAR(512) DEFAULT NULL,
              display_screenshot VARCHAR(512) DEFAULT NULL,
              supported_shell_versions JSON DEFAULT NULL COMMENT '(DC2Type:json)',
              last_commit_at DATETIME DEFAULT NULL,
              last_release_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              INDEX IDX_705647D0812D5EB (extension_id),
              UNIQUE INDEX uniq_source_type_external_id (
                source_type, external_identifier
              ),
              UNIQUE INDEX uniq_extension_source_type (extension_id, source_type),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE source_metric_measurement (
              id INT AUTO_INCREMENT NOT NULL,
              source_id INT NOT NULL,
              metric_type VARCHAR(32) NOT NULL,
              value DOUBLE PRECISION NOT NULL,
              measured_at DATETIME NOT NULL,
              INDEX IDX_43DD6630953C1C61 (source_id),
              UNIQUE INDEX uniq_source_metric_measured_at (
                source_id, metric_type, measured_at
              ),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              extension_source
            ADD
              CONSTRAINT FK_705647D0812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              source_metric_measurement
            ADD
              CONSTRAINT FK_43DD6630953C1C61 FOREIGN KEY (source_id) REFERENCES extension_source (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_extension_uuid ON extension (uuid)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE extension_comment ADD source_id INT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              extension_comment
            ADD
              CONSTRAINT FK_DB847C06953C1C61 FOREIGN KEY (source_id) REFERENCES extension_source (id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_DB847C06953C1C61 ON extension_comment (source_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE extension_download_measurement ADD source_id INT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              extension_download_measurement
            ADD
              CONSTRAINT FK_21B39876953C1C61 FOREIGN KEY (source_id) REFERENCES extension_source (id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_21B39876953C1C61 ON extension_download_measurement (source_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE extension_comment DROP FOREIGN KEY FK_DB847C06953C1C61
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE extension_download_measurement DROP FOREIGN KEY FK_21B39876953C1C61
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE extension_source DROP FOREIGN KEY FK_705647D0812D5EB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE source_metric_measurement DROP FOREIGN KEY FK_43DD6630953C1C61
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE extension_source
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE source_metric_measurement
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX uniq_extension_uuid ON extension
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_DB847C06953C1C61 ON extension_comment
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE extension_comment DROP source_id
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_21B39876953C1C61 ON extension_download_measurement
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE extension_download_measurement DROP source_id
        SQL);
    }

    /**
     * The new unique index on extension.uuid would otherwise fail with an opaque
     * DB-level duplicate-key error; fail fast with the offending values instead.
     * No rows are modified or deleted here.
     */
    private function guardAgainstDuplicateExtensionUuids(): void
    {
        $duplicates = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT uuid, COUNT(*) AS total
                FROM extension
                WHERE uuid IS NOT NULL
                GROUP BY uuid
                HAVING COUNT(*) > 1
            SQL
        );

        if ($duplicates === []) {
            return;
        }

        $sample = array_slice(array_column($duplicates, 'uuid'), 0, 10);

        throw new RuntimeException(sprintf(
            'Cannot add a unique index on extension.uuid: %d duplicate UUID value(s) found (e.g. %s). '
                . 'Resolve these manually before migrating; no rows were changed.',
            count($duplicates),
            implode(', ', $sample)
        ));
    }
}
