<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a global interactive browser lease with a persistent fencing generation.';
    }

    public function up(Schema $schema): void
    {
        // No finding FK: deleting a finding must not delete the fencing epoch
        // or free a retained browser before explicit worker reconciliation.
        $this->addSql("CREATE TABLE interactive_browser_lease (singleton_id INTEGER NOT NULL PRIMARY KEY CHECK (singleton_id = 1), generation INTEGER NOT NULL CHECK (generation >= 0), state VARCHAR(16) NOT NULL CHECK (state IN ('available', 'activating', 'active', 'closing')), finding_id VARCHAR(36) DEFAULT NULL, owner_hash VARCHAR(64) DEFAULT NULL, channel_id VARCHAR(48) DEFAULT NULL, worker_id VARCHAR(255) DEFAULT NULL, binding CLOB NOT NULL, created_at INTEGER DEFAULT NULL, expires_at INTEGER DEFAULT NULL)");
        $this->addSql("INSERT INTO interactive_browser_lease (singleton_id, generation, state, binding) VALUES (1, 0, 'available', '[]')");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Restore a deliberate backup instead of resetting the interactive browser fencing generation.');
    }
}
