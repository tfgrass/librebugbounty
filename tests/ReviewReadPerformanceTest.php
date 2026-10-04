<?php

namespace App\Tests;

use App\Service\ReviewNoticeService;
use App\Service\ReviewSchema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/** Isolated read fixtures: no application worker or external request. */
final class ReviewReadPerformanceTest extends TestCase
{
    private Connection $connection;
    private ReviewNoticeService $notices;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE finding (id TEXT PRIMARY KEY, manual_assessment TEXT, status TEXT, assessed_at TEXT)',
            'CREATE TABLE finding_assessment (id TEXT PRIMARY KEY, finding_id TEXT, assessment TEXT, assessed_at TEXT, known_observation_ids TEXT, known_observation_states TEXT)',
            'CREATE TABLE retest_run (id TEXT PRIMARY KEY, finding_id TEXT, result TEXT, started_at TEXT, finished_at TEXT, updated_at TEXT, raw_result TEXT)',
            'CREATE INDEX idx_run_finding ON retest_run (finding_id)',
            'CREATE TABLE finding_review_acknowledgement (id TEXT PRIMARY KEY, finding_id TEXT, decision_key TEXT, reviewed_at TEXT, known_observation_states TEXT)',
        ] as $sql) {
            $this->connection->executeStatement($sql);
        }
        $this->notices = new ReviewNoticeService($this->connection, new ReviewSchema($this->connection));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testGroupedReadsPreserveEachCasesOrderAndStoredFingerprintBoundary(): void
    {
        foreach (['z' => 'fixed', 'a' => 'confirmed'] as $id => $assessment) {
            $this->connection->insert('finding', ['id' => $id, 'manual_assessment' => $assessment, 'status' => 'new', 'assessed_at' => '2026-01-01 12:00:00']);
            $this->observation($id.'-known', $id, 'error');
            $this->observation($id.'-matching', $id, $assessment === 'fixed' ? 'fixed' : 'still_vulnerable');
            $states = $this->notices->states($id);
            $this->connection->insert('finding_review_acknowledgement', [
                'id' => 'ack-'.$id, 'finding_id' => $id,
                'decision_key' => 'legacy:'.hash('sha256', json_encode([$assessment, '2026-01-01 12:00:00'])),
                'reviewed_at' => '2026-01-02 12:00:00', 'known_observation_states' => json_encode($states),
            ]);
        }
        // Interleave cases and reverse lexical IDs at one timestamp. Newest
        // SQLite rowid must still win within each case after grouping the sort.
        $this->observation('z-new-z', 'z', 'still_vulnerable');
        $this->observation('a-new-z', 'a', 'fixed');
        $this->observation('z-new-a', 'z', 'inconclusive');
        $this->observation('a-new-a', 'a', 'error');
        $this->observation('a-pending', 'a', 'pending');
        $statesBefore = ['a' => $this->notices->states('a'), 'z' => $this->notices->states('z')];
        $this->connection->executeStatement('PRAGMA query_only = ON');
        $notices = $this->notices->forFindings();
        foreach (['a' => ['error', 'contradiction'], 'z' => ['inconclusive', 'contradiction']] as $id => $reasons) {
            self::assertSame([$id.'-new-a', $id.'-new-z'], array_column($notices[$id]->observations, 'id'));
            self::assertSame($reasons, array_column($notices[$id]->observations, 'reason'));
            self::assertEquals($notices[$id], $this->notices->get($id));
            self::assertSame($statesBefore[$id], $this->notices->states($id));
            foreach ($notices[$id]->observations as $observation) {
                unset($observation['reason']);
                self::assertSame($statesBefore[$id][$observation['id']], ReviewNoticeService::runFingerprint($observation));
            }
        }
        // Changed known IDs and wholly new IDs both remain visible. Grouping
        // reads must not cache a prior notice or rewrite a saved boundary.
        $this->connection->executeStatement('PRAGMA query_only = OFF');
        $this->connection->executeStatement("UPDATE retest_run SET result = 'inconclusive', raw_result = 'changed fixture' WHERE id = 'a-matching'");
        $this->connection->executeStatement('PRAGMA query_only = ON');
        self::assertSame(['a-new-a', 'a-new-z', 'a-matching'], array_column($this->notices->get('a')->observations, 'id'));
    }

    private function observation(string $id, string $findingId, string $result): void
    {
        $this->connection->insert('retest_run', ['id' => $id, 'finding_id' => $findingId, 'result' => $result, 'started_at' => '2026-01-02 12:00:00', 'finished_at' => null, 'updated_at' => '2026-01-02 12:00:00', 'raw_result' => 'stored fixture']);
    }
}
