<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260319120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create extension download measurement table with trend indexes';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform()->getName() === 'postgresql') {
            $this->addSql(<<<'SQL'
                CREATE TABLE extension_download_measurement (
                    id SERIAL NOT NULL,
                    extension_id INT NOT NULL,
                    downloads INT NOT NULL,
                    measured_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
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
        } else {
            $this->addSql(<<<'SQL'
                CREATE TABLE extension_download_measurement (
                    id INT AUTO_INCREMENT NOT NULL,
                    extension_id INT NOT NULL,
                    downloads INT NOT NULL,
                    measured_at DATETIME NOT NULL,
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
        }

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_extension_measured_at ON extension_download_measurement (extension_id, measured_at)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_extension_measurement ON extension_download_measurement (extension_id, measured_at)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP TABLE extension_download_measurement
        SQL);
    }
}
