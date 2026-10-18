<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Persist review deferrals, bounded one-shot recovery attempts and idempotent skip prompts.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE finding_review_disposition (finding_id VARCHAR(36) NOT NULL PRIMARY KEY REFERENCES finding (id) ON DELETE CASCADE, revision INTEGER NOT NULL, state VARCHAR(16) NOT NULL, reason VARCHAR(32) NOT NULL, note CLOB DEFAULT NULL, due_at DATETIME DEFAULT NULL, changed_at DATETIME NOT NULL, retry_state VARCHAR(16) DEFAULT NULL, retry_token VARCHAR(64) DEFAULT NULL, retry_started_at DATETIME DEFAULT NULL, retry_finished_at DATETIME DEFAULT NULL, retry_result VARCHAR(32) DEFAULT NULL, retry_message CLOB DEFAULT NULL, retry_run_id VARCHAR(36) DEFAULT NULL, retry_context CLOB DEFAULT NULL)");
        $this->addSql('CREATE INDEX idx_review_recovery_due ON finding_review_disposition (state, retry_state, due_at)');
        $this->addSql('CREATE TABLE finding_review_skip (finding_id VARCHAR(36) NOT NULL REFERENCES finding (id) ON DELETE CASCADE, context_fingerprint VARCHAR(64) NOT NULL, actions CLOB NOT NULL, skip_count INTEGER NOT NULL, prompted BOOLEAN NOT NULL, PRIMARY KEY(finding_id, context_fingerprint))');
    }
    public function down(Schema $schema): void { $this->abortIf(true, 'Restore a deliberate backup instead of deleting review recovery history.'); }
}
