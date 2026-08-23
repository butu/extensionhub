<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260322220228 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create extension_comment table for storing individual extension reviews';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE extension_comment (id INT AUTO_INCREMENT NOT NULL, extension_id INT NOT NULL, author_username VARCHAR(255) NOT NULL, author_url VARCHAR(255) DEFAULT NULL, gravatar VARCHAR(512) DEFAULT NULL, comment LONGTEXT NOT NULL, rating INT NOT NULL, is_extension_creator TINYINT(1) NOT NULL, comment_date DATETIME NOT NULL, INDEX idx_extension_comment_ext (extension_id), UNIQUE INDEX uniq_ext_author_date (extension_id, author_username, comment_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE extension_comment ADD CONSTRAINT FK_DB847C06812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE extension_comment DROP FOREIGN KEY FK_DB847C06812D5EB
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE extension_comment
        SQL);
    }
}
