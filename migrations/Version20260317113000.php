<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add supported_shell_versions column to store currently supported GNOME Shell versions from shell_version_map';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE extension ADD supported_shell_versions JSON DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE extension DROP supported_shell_versions
        SQL);
    }
}
