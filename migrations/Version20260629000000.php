<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create domains, findings, evidence, and retest_runs tables.';
    }

    public function up(Schema $schema): void
    {
        $domain = $schema->createTable('domain');
        $domain->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $domain->addColumn('hostname', 'string', ['length' => 255, 'notnull' => true]);
        $domain->addColumn('scheme', 'string', ['length' => 20, 'notnull' => false]);
        $domain->addColumn('authorized', 'boolean', ['notnull' => true]);
        $domain->addColumn('verification_method', 'string', ['length' => 32, 'notnull' => false]);
        $domain->addColumn('verification_note', 'text', ['notnull' => false]);
        $domain->addColumn('owner_contact', 'string', ['length' => 255, 'notnull' => false]);
        $domain->addColumn('notes', 'text', ['notnull' => false]);
        $domain->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $domain->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $domain->setPrimaryKey(['id']);
        $domain->addUniqueIndex(['hostname'], 'uniq_domain_hostname');

        $finding = $schema->createTable('finding');
        $finding->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $finding->addColumn('domain_id', 'string', ['length' => 36, 'notnull' => true]);
        $finding->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
        $finding->addColumn('type', 'string', ['length' => 32, 'notnull' => true]);
        $finding->addColumn('severity', 'string', ['length' => 20, 'notnull' => true]);
        $finding->addColumn('status', 'string', ['length' => 20, 'notnull' => true]);
        $finding->addColumn('url', 'text', ['notnull' => true]);
        $finding->addColumn('method', 'string', ['length' => 10, 'notnull' => true]);
        $finding->addColumn('request_params', 'json', ['notnull' => false]);
        $finding->addColumn('payload', 'text', ['notnull' => false]);
        $finding->addColumn('expected_evidence', 'text', ['notnull' => false]);
        $finding->addColumn('private_notes', 'text', ['notnull' => false]);
        $finding->addColumn('report_url', 'text', ['notnull' => false]);
        $finding->addColumn('reported_at', 'datetime_immutable', ['notnull' => false]);
        $finding->addColumn('last_retested_at', 'datetime_immutable', ['notnull' => false]);
        $finding->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $finding->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $finding->setPrimaryKey(['id']);
        $finding->addIndex(['domain_id'], 'idx_finding_domain');
        $finding->addIndex(['status'], 'idx_finding_status');
        $finding->addForeignKeyConstraint('domain', ['domain_id'], ['id'], ['onDelete' => 'CASCADE']);

        $evidence = $schema->createTable('evidence');
        $evidence->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $evidence->addColumn('finding_id', 'string', ['length' => 36, 'notnull' => true]);
        $evidence->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
        $evidence->addColumn('value', 'text', ['notnull' => false]);
        $evidence->addColumn('file_path', 'string', ['length' => 1024, 'notnull' => false]);
        $evidence->addColumn('sha256', 'string', ['length' => 64, 'notnull' => false]);
        $evidence->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $evidence->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $evidence->setPrimaryKey(['id']);
        $evidence->addIndex(['finding_id'], 'idx_evidence_finding');
        $evidence->addForeignKeyConstraint('finding', ['finding_id'], ['id'], ['onDelete' => 'CASCADE']);

        $retest = $schema->createTable('retest_run');
        $retest->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $retest->addColumn('finding_id', 'string', ['length' => 36, 'notnull' => true]);
        $retest->addColumn('started_at', 'datetime_immutable', ['notnull' => true]);
        $retest->addColumn('finished_at', 'datetime_immutable', ['notnull' => false]);
        $retest->addColumn('mode', 'string', ['length' => 20, 'notnull' => true]);
        $retest->addColumn('result', 'string', ['length' => 32, 'notnull' => true]);
        $retest->addColumn('http_status', 'integer', ['notnull' => false]);
        $retest->addColumn('final_url', 'text', ['notnull' => false]);
        $retest->addColumn('observed_evidence', 'text', ['notnull' => false]);
        $retest->addColumn('error_message', 'text', ['notnull' => false]);
        $retest->addColumn('screenshot_path', 'string', ['length' => 1024, 'notnull' => false]);
        $retest->addColumn('raw_result', 'json', ['notnull' => false]);
        $retest->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $retest->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $retest->setPrimaryKey(['id']);
        $retest->addIndex(['finding_id'], 'idx_retest_finding');
        $retest->addForeignKeyConstraint('finding', ['finding_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('retest_run');
        $schema->dropTable('evidence');
        $schema->dropTable('finding');
        $schema->dropTable('domain');
    }
}
