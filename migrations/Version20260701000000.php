<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user-configurable settings for intake defaults and review workflow.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE setting (id VARCHAR(128) NOT NULL, value CLOB DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id))');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'This migration cannot be safely reverted on SQLite.');
    }
}
