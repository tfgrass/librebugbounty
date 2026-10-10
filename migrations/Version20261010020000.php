<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add local disclosure activities and one revision-protected reminder per case; preserve legacy markers.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE disclosure_activity (id VARCHAR(36) NOT NULL, finding_id VARCHAR(36) NOT NULL, activity VARCHAR(16) NOT NULL, occurred_on VARCHAR(10) NOT NULL, channel VARCHAR(16) NOT NULL, recipient VARCHAR(2048) NOT NULL, ticket VARCHAR(255) DEFAULT NULL, comment CLOB DEFAULT NULL, recorded_at DATETIME NOT NULL, PRIMARY KEY(id), FOREIGN KEY(finding_id) REFERENCES finding(id) ON DELETE CASCADE)');
        $this->addSql('CREATE INDEX idx_disclosure_activity_case ON disclosure_activity (finding_id, occurred_on)');
        $this->addSql('CREATE TABLE disclosure_reminder (finding_id VARCHAR(36) NOT NULL, due_on VARCHAR(10) NOT NULL, next_step VARCHAR(255) NOT NULL, revision VARCHAR(36) NOT NULL, completed_at DATETIME DEFAULT NULL, changed_at DATETIME NOT NULL, PRIMARY KEY(finding_id), FOREIGN KEY(finding_id) REFERENCES finding(id) ON DELETE CASCADE)');
    }
    public function down(Schema $schema): void { $this->abortIf(true, 'Restore a deliberate backup instead of removing disclosure records.'); }
}
