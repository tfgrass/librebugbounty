<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add immutable review acknowledgements and future decision observation boundaries without assigning legacy review actions.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finding_assessment ADD COLUMN known_observation_states CLOB DEFAULT NULL');
        $this->addSql('CREATE TABLE finding_review_acknowledgement (id VARCHAR(36) NOT NULL, decision_key VARCHAR(80) NOT NULL, assessment VARCHAR(20) NOT NULL, reviewed_at DATETIME NOT NULL, known_observation_states CLOB NOT NULL, triggering_observation_ids CLOB NOT NULL, observation_id VARCHAR(36) DEFAULT NULL, evidence_id VARCHAR(36) DEFAULT NULL, reference_snapshot CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, finding_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_REVIEW_ACK_FINDING FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_review_ack_finding_decision ON finding_review_acknowledgement (finding_id, decision_key, reviewed_at)');
    }

    public function down(Schema $schema): void { $this->abortIf(true, 'Review acknowledgements cannot be reverted without losing human review history.'); }
}
