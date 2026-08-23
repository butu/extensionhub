<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

/**
 * Drops the unused messenger_messages table. Symfony Messenger's Doctrine
 * transport is only a transitive dependency here: there is no
 * MESSENGER_TRANSPORT_DSN, no config/packages/messenger.yaml, and no direct
 * symfony/messenger requirement in this project, so nothing ever writes to
 * this table.
 *
 * Refuses to drop while any row is present: a non-empty table would
 * contradict the "no usage" assumption above and needs investigating instead
 * of being silently deleted.
 */
final class Version20260901010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the unused messenger_messages table (no live code/config/package usage)';
    }

    public function up(Schema $schema): void
    {
        $this->guardMessengerMessagesIsEmpty();

        $this->addSql(<<<'SQL'
            DROP TABLE messenger_messages
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    /**
     * Row count, not table existence, is what proves this table is unused:
     * an empty table is consistent with "nothing writes here", whereas any
     * row would mean something still does, contradicting that assumption.
     */
    private function guardMessengerMessagesIsEmpty(): void
    {
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages');

        if ($count !== 0) {
            throw new RuntimeException(sprintf(
                'Refusing to drop messenger_messages: %d row(s) present. Investigate what is still '
                    . 'writing to this table before dropping it; no rows were changed.',
                $count
            ));
        }
    }
}
