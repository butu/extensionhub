<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes extension.pk nullable so a canonical GitHub-only Extension can be
 * persisted without inventing a fake EGO primary key. No fake pk values are
 * introduced; existing EGO-backed rows keep their pk unchanged.
 */
final class Version20260817125502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make extension.pk nullable to allow GitHub-only canonical extensions without a fake EGO pk';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE extension CHANGE pk pk INT DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE extension CHANGE pk pk INT NOT NULL
        SQL);
    }
}
