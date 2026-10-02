<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add explicit manual assessments and their history without assigning legacy decisions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finding ADD COLUMN manual_assessment VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE finding ADD COLUMN discard_reason VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE finding ADD COLUMN assessed_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE TABLE finding_assessment (id VARCHAR(36) NOT NULL, assessment VARCHAR(20) NOT NULL, discard_reason VARCHAR(20) DEFAULT NULL, assessed_at DATETIME NOT NULL, source VARCHAR(20) NOT NULL, observation_id VARCHAR(36) DEFAULT NULL, evidence_id VARCHAR(36) DEFAULT NULL, reference_snapshot CLOB DEFAULT NULL, known_observation_ids CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, finding_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_FINDING_ASSESSMENT_FINDING FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_finding_assessment_finding_time ON finding_assessment (finding_id, assessed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Manual assessment history cannot be safely reverted without losing user decisions.');
    }
}
