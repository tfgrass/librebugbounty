<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Service\AssessmentHistoryProjection;
use App\Service\FindingService;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;

final class ReviewResetMigrationTest extends DatabaseTestCase
{
    public function testAdditiveMigrationPreservesExistingRecordsAndAssessmentHistory(): void
    {
        $domain = (new Domain())->setHostname('migration.example.test');
        $finding = (new Finding())->setDomain($domain)->setTitle('Preserved review fixture')
            ->setType('synthetic')->setUrl('https://migration.example.test/case')
            ->setPrivateNotes('Retain this note')->setContactedAt(new \DateTimeImmutable('2026-10-01'));
        $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')
            ->setFilePath('storage/artifacts/unchanged-fixture.png');
        $observation = (new RetestRun())->setFinding($finding)->setResult('inconclusive');
        foreach ([$domain, $finding, $evidence, $observation] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        self::getContainer()->get(FindingService::class)->assess($finding, 'fixed');

        $db = $this->entityManager->getConnection();
        $db->executeStatement('DROP TABLE finding_assessment_cancellation');
        $db->executeStatement('DROP TABLE finding_assessment_reset');
        self::assertFalse(AssessmentHistoryProjection::available($db));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM finding_assessment a WHERE '.AssessmentHistoryProjection::activeSql($db)));
        $tables = ['domain', 'finding', 'evidence', 'retest_run', 'screenshot_job', 'finding_assessment', 'finding_review_acknowledgement', 'setting'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $db->fetchAllAssociative('SELECT rowid, * FROM '.$table.' ORDER BY rowid');
        }

        require_once dirname(__DIR__).'/migrations/Version20261004010000.php';
        $migration = new \DoctrineMigrations\Version20261004010000($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertTrue(AssessmentHistoryProjection::available($db));
        foreach ($tables as $table) {
            self::assertSame($before[$table], $db->fetchAllAssociative('SELECT rowid, * FROM '.$table.' ORDER BY rowid'), $table.' must retain its exact contents and row order.');
        }
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM finding_assessment a WHERE '.AssessmentHistoryProjection::activeSql($db)));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM finding_assessment_reset'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM finding_assessment_cancellation'));
        self::assertContains('idx_assessment_reset_finding_time', array_column($db->fetchAllAssociative('PRAGMA index_list(finding_assessment_reset)'), 'name'));
        self::assertContains('idx_assessment_cancellation_reset', array_column($db->fetchAllAssociative('PRAGMA index_list(finding_assessment_cancellation)'), 'name'));
    }
}
