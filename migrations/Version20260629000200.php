<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add submission and owner-notification timestamps to findings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finding ADD COLUMN submitted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE finding ADD COLUMN notified_owner_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'This migration cannot be safely reverted on SQLite.');
    }
}
