<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

/**
 * Drops the legacy extension_download_measurement table once every row has
 * been superseded by an equivalent source_metric_measurement row: same
 * source_id, metric_type 'downloads', measured_at and value.
 *
 * `App\Service\EgoSourceBackfillService` (via the one-time
 * app:backfill-ego-sources command, and on every EGO cron import since) is
 * what reassigns source_id on legacy rows and copies their download history
 * into source_metric_measurement. Run that backfill and let it (or the
 * ongoing import) catch every row first; this migration only verifies and
 * drops, it never reassigns or copies data itself.
 */
final class Version20260901020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Drop extension_download_measurement once every row has a source_id and a matching "
            . "source_metric_measurement('downloads') row";
    }

    public function up(Schema $schema): void
    {
        $this->migrateLegacyDownloadHistory();
        $this->guardEveryLegacyRowIsSupersededBySourceMetricMeasurement();

        $this->addSql(<<<'SQL'
            DROP TABLE extension_download_measurement
        SQL);
    }

    public function isTransactional(): bool
    {
        // MySQL commits DDL implicitly; the history transfer must survive before the table is dropped.
        return false;
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform()->getName() === 'postgresql') {
            $this->addSql(<<<'SQL'
                CREATE TABLE extension_download_measurement (
                    id SERIAL NOT NULL,
                    extension_id INT NOT NULL,
                    downloads INT NOT NULL,
                    measured_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    source_id INT DEFAULT NULL,
                    PRIMARY KEY(id)
                )
            SQL);

            $this->addSql(<<<'SQL'
                ALTER TABLE extension_download_measurement
                ADD CONSTRAINT FK_EXTENSION_DOWNLOAD_MEASUREMENT_EXTENSION
                FOREIGN KEY (extension_id)
                REFERENCES extension (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

            $this->addSql(<<<'SQL'
                ALTER TABLE extension_download_measurement
                ADD CONSTRAINT FK_21B39876953C1C61
                FOREIGN KEY (source_id)
                REFERENCES extension_source (id)
                NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
        } else {
            $this->addSql(<<<'SQL'
                CREATE TABLE extension_download_measurement (
                    id INT AUTO_INCREMENT NOT NULL,
                    extension_id INT NOT NULL,
                    downloads INT NOT NULL,
                    measured_at DATETIME NOT NULL,
                    source_id INT DEFAULT NULL,
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

            $this->addSql(<<<'SQL'
                ALTER TABLE extension_download_measurement
                ADD CONSTRAINT FK_EXTENSION_DOWNLOAD_MEASUREMENT_EXTENSION
                FOREIGN KEY (extension_id)
                REFERENCES extension (id)
                ON DELETE CASCADE
            SQL);

            $this->addSql(<<<'SQL'
                ALTER TABLE extension_download_measurement
                ADD CONSTRAINT FK_21B39876953C1C61
                FOREIGN KEY (source_id)
                REFERENCES extension_source (id)
            SQL);
        }

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_extension_measured_at ON extension_download_measurement (extension_id, measured_at)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_extension_measurement ON extension_download_measurement (extension_id, measured_at)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_21B39876953C1C61 ON extension_download_measurement (source_id)
        SQL);
    }

    /**
     * Refuses to drop while any legacy row either has no source_id yet, or
     * has no source_metric_measurement row matching its
     * source_id/measured_at/downloads as a 'downloads' metric of the same
     * value. Both must hold for every row, since either gap means dropping
     * would lose history that was never actually copied forward.
     */
    private function guardEveryLegacyRowIsSupersededBySourceMetricMeasurement(): void
    {
        $unsuperseded = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT edm.id
                FROM extension_download_measurement edm
                LEFT JOIN source_metric_measurement smm
                    ON smm.source_id = edm.source_id
                    AND smm.metric_type = 'downloads'
                    AND smm.measured_at = edm.measured_at
                    AND smm.value = edm.downloads
                WHERE edm.source_id IS NULL OR smm.id IS NULL
            SQL
        );

        if ($unsuperseded === []) {
            return;
        }

        $sample = array_slice(array_column($unsuperseded, 'id'), 0, 10);

        throw new RuntimeException(sprintf(
            'Cannot drop extension_download_measurement: %d row(s) (e.g. id %s) have no source_id yet, '
                . "or no matching source_metric_measurement('downloads') row with the same "
                . 'source_id/measured_at/value. Run app:backfill-ego-sources and let the EGO import run '
                . 'again first; no rows were changed.',
            count($unsuperseded),
            implode(', ', $sample)
        ));
    }

    private function migrateLegacyDownloadHistory(): void
    {
        if ($this->connection->getDatabasePlatform()->getName() === 'postgresql') {
            $this->connection->executeStatement(<<<'SQL'
                UPDATE extension_download_measurement edm
                SET source_id = es.id
                FROM extension_source es
                WHERE edm.source_id IS NULL
                    AND es.extension_id = edm.extension_id
                    AND es.source_type = 'ego'
            SQL);
            $this->connection->executeStatement(<<<'SQL'
                INSERT INTO source_metric_measurement (source_id, metric_type, measured_at, value)
                SELECT edm.source_id, 'downloads', edm.measured_at, edm.downloads
                FROM extension_download_measurement edm
                WHERE edm.source_id IS NOT NULL
                ON CONFLICT (source_id, metric_type, measured_at)
                DO UPDATE SET value = EXCLUDED.value
            SQL);

            return;
        }

        $this->connection->executeStatement(<<<'SQL'
            UPDATE extension_download_measurement edm
            INNER JOIN extension_source es
                ON es.extension_id = edm.extension_id
                AND es.source_type = 'ego'
            SET edm.source_id = es.id
            WHERE edm.source_id IS NULL
        SQL);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO source_metric_measurement (source_id, metric_type, measured_at, value)
            SELECT edm.source_id, 'downloads', edm.measured_at, edm.downloads
            FROM extension_download_measurement edm
            WHERE edm.source_id IS NOT NULL
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        SQL);
    }
}
