<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist browser URL intake sessions and confirmed contributions without including imports.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE intake_browser (id VARCHAR(64) NOT NULL PRIMARY KEY, current_session_id VARCHAR(36) DEFAULT NULL, generation INTEGER NOT NULL)');
        $this->addSql('CREATE TABLE intake_session (id VARCHAR(36) NOT NULL PRIMARY KEY, browser_id VARCHAR(64) NOT NULL, started_at INTEGER NOT NULL, last_activity_at INTEGER NOT NULL, ended_at INTEGER DEFAULT NULL, end_reason VARCHAR(16) DEFAULT NULL, continuation_of VARCHAR(36) DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_intake_session_browser ON intake_session (browser_id, started_at)');
        $this->addSql('CREATE TABLE intake_session_entry (finding_id VARCHAR(36) NOT NULL PRIMARY KEY, session_id VARCHAR(36) NOT NULL, browser_id VARCHAR(64) NOT NULL, recorded_at INTEGER NOT NULL)');
        $this->addSql('CREATE INDEX idx_intake_entry_session ON intake_session_entry (session_id, recorded_at)');
        $this->addSql('CREATE INDEX idx_intake_entry_browser ON intake_session_entry (browser_id, recorded_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Restore a deliberate backup instead of removing intake session history.');
    }
}
