<?php

declare(strict_types=1);

namespace migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250502124038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds saved_notes and attachments tables for note taking feature.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE `saved_notes` (
            `id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `note_title` VARCHAR(50) NOT NULL,
            `note` TEXT NOT NULL,
            `pinned` tinyint NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_note_user_id` (`user_id`),
            CONSTRAINT `fk_note_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE `attachments` (
            `id` INT UNSIGNED AUTO_INCREMENT NOT NULL,
            `file_name` VARCHAR(255) NOT NULL,
            `file_uuid` VARCHAR(255) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `saved_note_id` INT UNSIGNED NOT NULL,
            INDEX `idx_attachment_note_id` (`saved_note_id`),
            CONSTRAINT `fk_attachment_note_id` FOREIGN KEY (`saved_note_id`) REFERENCES `saved_notes` (`id`) ON DELETE CASCADE,
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE `attachments`');
        $this->addSql('DROP TABLE `saved_notes`');
    }
}
