<?php

namespace App\Service;

use App\Value\FindingStatus;
use App\Value\ReviewState;
use Doctrine\DBAL\Connection;

/**
 * One-off catch-up for the stock recheck cadence: findings whose last check
 * is older than a configurable age (default 14 days) are pulled due at once
 * so the recheck workers can burn the backlog down in parallel.
 *
 * The service never touches freshly claimed slots: a claim leases 24 hours
 * and a retest takes minutes at most, so a slot in the narrow window
 * (now+23.5h, now+25h] can only be a live claim. Everything else -- overdue
 * slots, spacing slots, error backoffs and claims older than 30 minutes
 * (crashed workers, which the catch-up thereby reclaims early) -- is
 * rescheduled to "now" in one UPDATE.
 */
final class RecheckCatchUpService
{
    private const COLUMNS = 'COALESCE(last_retested_at, submitted_at, created_at)';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{eligible: int, alreadyDue: int, waiting: int, claimed: int}
     */
    public function preview(\DateTimeImmutable $cutoff, \DateTimeImmutable $now): array
    {
        $scope = $this->scopeSql();
        [$leaseLow, $leaseHigh] = RecheckPolicy::claimWindow($now);

        $eligible = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM finding WHERE '.$scope.' AND '.self::COLUMNS.' <= :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );

        $alreadyDue = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM finding WHERE '.$scope.' AND '.self::COLUMNS.' <= :cutoff AND next_due_at <= :now',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s'), 'now' => $now->format('Y-m-d H:i:s')],
        );

        $claimed = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM finding WHERE '.$scope.' AND '.self::COLUMNS.' <= :cutoff AND next_due_at > :leaseLow AND next_due_at <= :leaseHigh',
            [
                'cutoff' => $cutoff->format('Y-m-d H:i:s'),
                'leaseLow' => $leaseLow->format('Y-m-d H:i:s'),
                'leaseHigh' => $leaseHigh->format('Y-m-d H:i:s'),
            ],
        );

        return [
            'eligible' => $eligible,
            'alreadyDue' => $alreadyDue,
            'waiting' => $eligible - $alreadyDue,
            'claimed' => $claimed,
        ];
    }

    /**
     * Pull every stale, unclaimed recheck slot to now. Returns the number of
     * rows rescheduled. Rows claimed by a running worker are skipped.
     */
    public function pullDue(\DateTimeImmutable $cutoff, \DateTimeImmutable $now): int
    {
        [$leaseLow, $leaseHigh] = RecheckPolicy::claimWindow($now);

        return (int) $this->connection->executeStatement(
            'UPDATE finding SET next_due_at = :now'
            .' WHERE '.$this->scopeSql()
            .' AND '.self::COLUMNS.' <= :cutoff'
            .' AND next_due_at IS NOT NULL'
            .' AND next_due_at > :now'
            .' AND (next_due_at <= :leaseLow OR next_due_at > :leaseHigh)',
            [
                'now' => $now->format('Y-m-d H:i:s'),
                'cutoff' => $cutoff->format('Y-m-d H:i:s'),
                'leaseLow' => $leaseLow->format('Y-m-d H:i:s'),
                'leaseHigh' => $leaseHigh->format('Y-m-d H:i:s'),
            ],
        );
    }

    private function scopeSql(): string
    {
        $statuses = implode("', '", [
            FindingStatus::NEW,
            FindingStatus::VERIFIED,
            FindingStatus::REPORTED,
            FindingStatus::WONTFIX,
        ]);

        return "status IN ('".$statuses."')"
            ." AND manual_assessment IS NULL"
            ." AND (review_state IS NULL OR review_state NOT IN ('"
            .ReviewState::MANUAL_CHECKING."', '".ReviewState::MANUALLY_CHECKED."', '".ReviewState::CONFIRMED_FIXED."'))";
    }
}
