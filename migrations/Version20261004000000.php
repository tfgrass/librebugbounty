<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index inventory ordering without changing case data or insertion-order ties.';
    }

    public function up(Schema $schema): void
    {
        // SQLite scans this index backwards for submitted_at, created_at and
        // the implicit rowid suffix, preserving the existing newest-first order.
        $this->addSql('CREATE INDEX idx_finding_list_order ON finding (submitted_at, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_finding_list_order');
    }
}
