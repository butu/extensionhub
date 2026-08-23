<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250522164116 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE extension ADD downloads INT DEFAULT NULL, ADD creator VARCHAR(255) NOT NULL, ADD creator_url VARCHAR(255) NOT NULL, ADD name VARCHAR(255) NOT NULL, ADD uuid VARCHAR(255) NOT NULL, ADD pk INT NOT NULL, ADD description LONGTEXT NOT NULL, ADD source_url VARCHAR(255) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE extension DROP downloads, DROP creator, DROP creator_url, DROP name, DROP uuid, DROP pk, DROP description, DROP source_url
        SQL);
    }
}
