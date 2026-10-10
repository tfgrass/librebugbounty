<?php

namespace App\Service;

use App\Value\FindingStatus;
use App\Value\ReviewState;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Read-only health snapshot for the settings page: worker liveness from the
 * heartbeats plus the current recheck schedule and screenshot queue state.
 */
final class SystemHealthService
{
    public const RECHECK_STALE_SECONDS = 600;
    public const SCREENSHOT_STALE_SECONDS = 300;

    public function __construct(
        private readonly Connection $connection,
        private readonly WorkerHeartbeatService $heartbeats,
        private readonly RecheckPolicy $policy,
        private readonly WorkerHealthRegistry $registry,
        private readonly BrowserHealthService $browsers,
        private readonly FindingProblemService $problems,
    ) {
    }

    /** @return array<string, mixed> */
    public function snapshot(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $nowSql = $now->format('Y-m-d H:i:s');
        $scope = $this->scopeSql();

        $browserStatus = $this->browsers->check();
        $problemCounts = $this->problems->currentCounts();
        $nextDue = $this->connection->fetchOne(
            'SELECT MIN(next_due_at) FROM finding WHERE '.$scope.' AND next_due_at IS NOT NULL',
        );

        $jobs = ['queued' => 0, 'running' => 0, 'available' => 0, 'failed' => 0];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT status, COUNT(*) AS count FROM screenshot_job GROUP BY status'
        ) as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $jobs)) {
                $jobs[$status] = (int) $row['count'];
            }
        }

        return [
            'checkedAt' => $now,
            'recheck' => $this->workerGroup(WorkerHeartbeatService::RECHECK, self::RECHECK_STALE_SECONDS, $browserStatus, $now) + [
                'dueNow' => (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM finding WHERE '.$scope.' AND next_due_at IS NOT NULL AND next_due_at <= :now',
                    ['now' => $nowSql],
                ),
                'scheduled' => (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM finding WHERE '.$scope.' AND next_due_at > :now',
                    ['now' => $nowSql],
                ),
                'pausedManual' => (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM finding WHERE status IN (:statuses) AND review_state = :manual',
                    ['statuses' => $this->scopeStatuses(), 'manual' => ReviewState::MANUAL_CHECKING],
                    ['statuses' => ArrayParameterType::STRING],
                ),
                'nextDueAt' => is_string($nextDue) && $nextDue !== '' ? new \DateTimeImmutable($nextDue) : null,
                'intervalDays' => $this->policy->intervalDays(),
                'errorBackoffDays' => $this->policy->errorBackoffDays(),
                'errorCases' => $problemCounts['technical'],
            ],
            'screenshot' => $this->workerGroup(WorkerHeartbeatService::SCREENSHOT, self::SCREENSHOT_STALE_SECONDS, $browserStatus, $now) + [
                'queued' => $jobs['queued'],
                'running' => $jobs['running'],
                'available' => $jobs['available'],
                'failed' => $jobs['failed'],
                'errorCases' => $problemCounts['screenshot'],
            ],
        ];
    }

    /** @param array<string, bool> $browserStatus
     *  @return array<string, mixed>
     */
    private function workerGroup(string $kind, int $staleSeconds, array $browserStatus, \DateTimeImmutable $now): array
    {
        $rows = [];
        $active = 0;
        $seen = 0;
        $expected = $this->registry->forKind($kind);
        foreach ($expected as $id => $url) {
            $lastSeen = $this->heartbeats->lastSeen($kind, $id);
            $age = $lastSeen === null ? null : max(0, $now->getTimestamp() - $lastSeen->getTimestamp());
            $state = $age === null ? 'unknown' : ($age <= $staleSeconds ? 'ok' : 'stale');
            $active += $state === 'ok' ? 1 : 0;
            $seen += $age !== null ? 1 : 0;
            $rows[] = ['id' => $id, 'state' => $state, 'ageSeconds' => $age, 'lastSeen' => $lastSeen, 'browserReachable' => $browserStatus[$url] ?? false];
        }
        $browserUrls = array_unique(array_values($expected));
        $reachable = count(array_filter($browserUrls, static fn (string $url): bool => $browserStatus[$url] ?? false));
        $healthy = $expected !== [] && $active === count($expected) && $reachable === count($browserUrls);

        return [
            'active' => $healthy,
            'state' => $healthy ? 'ok' : ($seen === 0 ? 'unknown' : ($active === 0 ? 'stale' : 'degraded')),
            'activeWorkers' => $active, 'expectedWorkers' => count($expected),
            'reachableBrowsers' => $reachable, 'expectedBrowsers' => count($browserUrls),
            'workers' => $rows,
        ];
    }

    /** @return list<string> */
    private function scopeStatuses(): array
    {
        return [
            FindingStatus::NEW,
            FindingStatus::VERIFIED,
            FindingStatus::REPORTED,
            FindingStatus::WONTFIX,
        ];
    }

    private function scopeSql(): string
    {
        $statuses = implode("', '", $this->scopeStatuses());

        return FindingWorkPolicy::checksAllowedSql($this->connection, 'finding')." AND status IN ('".$statuses."')"
            ." AND manual_assessment IS NULL"
            ." AND (review_state IS NULL OR review_state NOT IN ('"
            .ReviewState::MANUAL_CHECKING."', '".ReviewState::MANUALLY_CHECKED."', '".ReviewState::CONFIRMED_FIXED."'))";
    }
}
