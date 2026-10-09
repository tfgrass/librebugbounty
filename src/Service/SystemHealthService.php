<?php

namespace App\Service;

use App\Value\FindingStatus;
use App\Value\ReviewState;
use App\Value\ScreenshotJobStatus;
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
    ) {
    }

    /**
     * @return array{
     *     recheck: array{active: bool, ageSeconds: ?int, dueNow: int, scheduled: int, pausedManual: int, nextDueAt: ?\DateTimeImmutable, intervalDays: int, errorBackoffDays: int},
     *     screenshot: array{active: bool, ageSeconds: ?int, queued: int, running: int, available: int, failed: int}
     * }
     */
    public function snapshot(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $nowSql = $now->format('Y-m-d H:i:s');
        $scope = $this->scopeSql();

        $recheckAge = $this->heartbeats->ageSeconds(WorkerHeartbeatService::RECHECK, $now);
        $screenshotAge = $this->heartbeats->ageSeconds(WorkerHeartbeatService::SCREENSHOT, $now);
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
            'recheck' => [
                'active' => $recheckAge !== null && $recheckAge <= self::RECHECK_STALE_SECONDS,
                'ageSeconds' => $recheckAge,
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
            ],
            'screenshot' => [
                'active' => $screenshotAge !== null && $screenshotAge <= self::SCREENSHOT_STALE_SECONDS,
                'ageSeconds' => $screenshotAge,
                'queued' => $jobs['queued'],
                'running' => $jobs['running'],
                'available' => $jobs['available'],
                'failed' => $jobs['failed'],
            ],
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

        return "status IN ('".$statuses."')"
            ." AND manual_assessment IS NULL"
            ." AND (review_state IS NULL OR review_state NOT IN ('"
            .ReviewState::MANUAL_CHECKING."', '".ReviewState::MANUALLY_CHECKED."', '".ReviewState::CONFIRMED_FIXED."'))";
    }
}
