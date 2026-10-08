<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add finding.next_due_at for the 28-day stock recheck cadence.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE finding ADD COLUMN next_due_at DATETIME DEFAULT NULL");
        $this->addSql("UPDATE finding SET next_due_at = datetime(COALESCE(last_retested_at, submitted_at, created_at), '+28 days') WHERE status IN ('new', 'verified', 'reported', 'wontfix') AND (manual_assessment IS NULL OR manual_assessment != 'discarded')");
        $this->addSql('CREATE INDEX idx_finding_next_due ON finding (next_due_at)');
    }

    public function down(Schema $schema): void { $this->abortIf(true, 'Dropping next_due_at would lose recheck scheduling state.'); }
}
