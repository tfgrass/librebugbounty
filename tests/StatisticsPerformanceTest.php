<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\StatisticsRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class StatisticsPerformanceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE finding (id TEXT PRIMARY KEY, submitted_at TEXT, created_at TEXT, contacted_at TEXT)');
        $this->connection->executeStatement('CREATE TABLE finding_assessment (finding_id TEXT, assessment TEXT, assessed_at TEXT)');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testCoverageHandlesAnEmptyInventoryAndIgnoresOrphanedHistory(): void
    {
        $repository = new StatisticsRepository($this->connection);
        self::assertSame(['activity' => null, 'assessment' => null], $repository->coverageDates());

        $this->connection->insert('finding_assessment', [
            'finding_id' => 'deleted', 'assessment' => 'fixed', 'assessed_at' => '2025-01-01 10:00:00',
        ]);
        $this->connection->executeStatement('PRAGMA query_only = ON');
        self::assertSame(['activity' => null, 'assessment' => null], $repository->coverageDates());
    }

    public function testCombinedCoveragePreservesTheDistinctActivityAndAssessmentTimelines(): void
    {
        $this->connection->insert('finding', [
            'id' => 'fallback', 'created_at' => '2026-07-02 10:00:00',
        ]);
        $this->connection->insert('finding', [
            'id' => 'submitted', 'created_at' => '2025-01-01 10:00:00',
            'submitted_at' => '2026-07-05 10:00:00', 'contacted_at' => '2026-07-01 23:59:59',
        ]);
        $this->connection->insert('finding_assessment', [
            'finding_id' => 'submitted', 'assessment' => 'confirmed', 'assessed_at' => '2026-06-01 10:00:00',
        ]);
        $repository = new StatisticsRepository($this->connection);
        self::assertSame([
            'activity' => '2026-07-01 23:59:59', 'assessment' => '2026-06-01 10:00:00',
        ], $repository->coverageDates(), 'Confirmation starts assessment coverage but is not an activity-series event.');

        $this->connection->insert('finding_assessment', [
            'finding_id' => 'fallback', 'assessment' => 'fixed', 'assessed_at' => '2026-06-30 10:00:00',
        ]);
        $this->connection->insert('finding_assessment', [
            'finding_id' => 'deleted', 'assessment' => 'fixed', 'assessed_at' => '2020-01-01 10:00:00',
        ]);
        self::assertSame([
            'activity' => '2026-06-30 10:00:00', 'assessment' => '2026-06-01 10:00:00',
        ], $repository->coverageDates(), 'Only history attached to a retained case contributes.');

        $this->connection->delete('finding', ['id' => 'fallback']);
        $this->connection->update('finding', ['contacted_at' => null], ['id' => 'submitted']);
        $this->connection->executeStatement('PRAGMA query_only = ON');
        self::assertSame([
            'activity' => '2026-07-05 10:00:00', 'assessment' => '2026-06-01 10:00:00',
        ], $repository->coverageDates(), 'Reads see current retained data and prefer submission over creation.');
    }
}
