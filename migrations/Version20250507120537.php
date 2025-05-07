<?php

declare(strict_types=1);

namespace migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250507120537 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds access_key column to attachments table.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `attachments` ADD `access_key` VARCHAR(255) DEFAULT NULL AFTER `file_uuid`');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `attachments` DROP COLUMN `access_key`');
    }
}
