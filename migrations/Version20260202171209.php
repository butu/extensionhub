<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260202171209 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add latestVersionPk and firstVersionPk columns to track real extension creation/update dates from GNOME Extensions API';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE extension ADD latest_version_pk INT DEFAULT NULL, ADD first_version_pk INT DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE extension DROP latest_version_pk, DROP first_version_pk
        SQL);
    }
}
