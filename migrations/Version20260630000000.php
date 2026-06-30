<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260630000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add review state to findings for manual checking and confirmed fixed workflow.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finding ADD COLUMN review_state VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'This migration cannot be safely reverted on SQLite.');
    }
}
