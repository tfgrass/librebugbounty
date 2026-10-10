<?php

namespace App\Service;

use App\Value\FindingStatus;
use App\Value\ReviewState;
use Doctrine\DBAL\Connection;

/**
 * Adjusts scheduled recheck slots when the configured interval shrinks.
 *
 * Only future slots later than "last check + new interval" move earlier;
 * error backoffs and already due slots stay untouched, and the live-claim
 * window is preserved so a running worker never loses its lease. Enlarging
 * the interval deliberately changes nothing: one earlier check is harmless
 * and each completed run then schedules with the new value.
 */
final class RecheckScheduleService
{
    private const BASE = 'COALESCE(last_retested_at, submitted_at, created_at)';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function applyIntervalChange(int $previousDays, int $intervalDays, \DateTimeImmutable $now): int
    {
        if ($intervalDays >= $previousDays) {
            return 0;
        }

        [$leaseLow, $leaseHigh] = RecheckPolicy::claimWindow($now);

        return (int) $this->connection->executeStatement(
            'UPDATE finding SET next_due_at = datetime('.self::BASE.', :modifier)'
            .' WHERE '.$this->scopeSql()
            .' AND next_due_at IS NOT NULL'
            .' AND next_due_at > :now'
            .' AND next_due_at > datetime('.self::BASE.', :modifier)'
            .' AND (next_due_at <= :leaseLow OR next_due_at > :leaseHigh)',
            [
                'modifier' => sprintf('+%d days', max(1, $intervalDays)),
                'now' => $now->format('Y-m-d H:i:s'),
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

        return FindingWorkPolicy::checksAllowedSql($this->connection, 'finding')." AND status IN ('".$statuses."')"
            ." AND manual_assessment IS NULL"
            ." AND (review_state IS NULL OR review_state NOT IN ('"
            .ReviewState::MANUAL_CHECKING."', '".ReviewState::MANUALLY_CHECKED."', '".ReviewState::CONFIRMED_FIXED."'))";
    }
}
