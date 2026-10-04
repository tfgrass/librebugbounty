<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Record explicit review-back resets and cancelled assessments without deleting human history.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE finding_assessment_reset (id VARCHAR(36) NOT NULL, finding_id VARCHAR(36) NOT NULL, reset_at DATETIME NOT NULL, source VARCHAR(30) NOT NULL, previous_state CLOB NOT NULL, evidence_id VARCHAR(36) DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_ASSESSMENT_RESET_FINDING FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_assessment_reset_finding_time ON finding_assessment_reset (finding_id, reset_at)');
        $this->addSql('CREATE TABLE finding_assessment_cancellation (assessment_id VARCHAR(36) NOT NULL, reset_id VARCHAR(36) NOT NULL, PRIMARY KEY (assessment_id), CONSTRAINT FK_ASSESSMENT_CANCELLATION_RESET FOREIGN KEY (reset_id) REFERENCES finding_assessment_reset (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_assessment_cancellation_reset ON finding_assessment_cancellation (reset_id)');
    }

    public function down(Schema $schema): void { $this->abortIf(true, 'Assessment resets cannot be reverted without losing human review history.'); }
}
