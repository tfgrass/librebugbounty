<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the original finding URL and persist selected active PoC URLs with immutable evidence history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finding ADD COLUMN poc_url CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE finding ADD COLUMN poc_revision INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('CREATE TABLE finding_poc_revision (id VARCHAR(36) NOT NULL PRIMARY KEY, finding_id VARCHAR(36) NOT NULL, revision INTEGER NOT NULL, previous_url CLOB NOT NULL, url CLOB NOT NULL, source VARCHAR(16) NOT NULL, evidence_id VARCHAR(36) NOT NULL, screenshot_job_id VARCHAR(36) NOT NULL, source_url CLOB DEFAULT NULL, captured_at DATETIME DEFAULT NULL, sha256 VARCHAR(64) DEFAULT NULL, changed_at DATETIME NOT NULL, CONSTRAINT FK_POC_REVISION_FINDING FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_finding_poc_revision ON finding_poc_revision (finding_id, revision)');
        $this->addSql('CREATE INDEX idx_finding_poc_history ON finding_poc_revision (finding_id, changed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Restore a deliberate backup instead of removing PoC URL and evidence history.');
    }
}
