<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add independent pursuit state, explicit work restrictions, their history and sourced contact discovery.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE finding_follow_up (finding_id VARCHAR(36) NOT NULL, pursuit VARCHAR(16) NOT NULL, reason VARCHAR(32) DEFAULT NULL, contact_blocked BOOLEAN NOT NULL, checks_blocked BOOLEAN NOT NULL, changed_at DATETIME NOT NULL, PRIMARY KEY(finding_id), FOREIGN KEY(finding_id) REFERENCES finding(id) ON DELETE CASCADE)');
        $this->addSql('CREATE TABLE domain_work_restriction (hostname VARCHAR(255) NOT NULL, contact_blocked BOOLEAN NOT NULL, checks_blocked BOOLEAN NOT NULL, changed_at DATETIME NOT NULL, PRIMARY KEY(hostname))');
        $this->addSql('CREATE TABLE work_policy_event (id VARCHAR(36) NOT NULL, finding_id VARCHAR(36) NOT NULL, hostname VARCHAR(255) NOT NULL, scope VARCHAR(16) NOT NULL, previous_state CLOB NOT NULL, new_state CLOB NOT NULL, changed_at DATETIME NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_work_event_case ON work_policy_event (finding_id, changed_at)');
        $this->addSql('CREATE INDEX idx_work_event_host ON work_policy_event (hostname, changed_at)');
        $this->addSql('CREATE TABLE contact_discovery (id VARCHAR(36) NOT NULL, finding_id VARCHAR(36) NOT NULL, provider VARCHAR(64) NOT NULL, result CLOB NOT NULL, fetched_at DATETIME NOT NULL, PRIMARY KEY(id), FOREIGN KEY(finding_id) REFERENCES finding(id) ON DELETE CASCADE)');
        $this->addSql('CREATE INDEX idx_contact_discovery_case ON contact_discovery (finding_id, fetched_at)');
    }
    public function down(Schema $schema): void { $this->abortIf(true, 'Do not silently remove recorded opt-outs and pursuit history. Restore a deliberate backup instead.'); }
}
