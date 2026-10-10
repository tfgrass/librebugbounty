<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Store one independently chosen disclosure route per finding, with source snapshot and revision.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE finding_contact_route (finding_id VARCHAR(36) NOT NULL, channel VARCHAR(16) NOT NULL, destination VARCHAR(2048) NOT NULL, person VARCHAR(255) DEFAULT NULL, source VARCHAR(2048) DEFAULT NULL, notes CLOB DEFAULT NULL, provenance CLOB NOT NULL, revision VARCHAR(36) NOT NULL, changed_at DATETIME NOT NULL, PRIMARY KEY(finding_id), FOREIGN KEY(finding_id) REFERENCES finding(id) ON DELETE CASCADE)');
    }
    public function down(Schema $schema): void { $this->abortIf(true, 'Restore a deliberate backup instead of deleting chosen contact routes.'); }
}
