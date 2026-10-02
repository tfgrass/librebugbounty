<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persistent, serialized screenshot jobs without changing finding assessments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE screenshot_job (id VARCHAR(36) NOT NULL, url CLOB NOT NULL, status VARCHAR(20) NOT NULL, active_key VARCHAR(36) DEFAULT NULL, requested_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, captured_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, attempts INTEGER NOT NULL, error_message CLOB DEFAULT NULL, screenshot_path VARCHAR(1024) DEFAULT NULL, capture_metadata CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, finding_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_4690A0B54323B5E7 FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4690A0B5E46357AB ON screenshot_job (active_key)');
        $this->addSql('CREATE INDEX IDX_4690A0B54323B5E7 ON screenshot_job (finding_id)');
        $this->addSql('CREATE INDEX idx_screenshot_job_status_requested ON screenshot_job (status, requested_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE screenshot_job');
    }
}
