<?php

namespace App\Service;

use App\Entity\Finding;
use App\Value\FindingStatus;
use App\Value\RetestResult;
use App\Value\ReviewState;

/**
 * Stock recheck cadence for the recheck worker.
 *
 * Scope (D4): new/verified/reported/wontfix are rechecked every 28 days;
 * fixed, discarded, duplicate and currently manually checked findings are
 * never queued. Error results
 * retry after a short backoff instead of blocking the slot for four weeks.
 * Claims lease a finding for 24 hours; a crashed worker's claim expires on
 * its own and the finding becomes due again.
 */
final class RecheckPolicy
{
    public const INTERVAL_DAYS = 28;
    public const ERROR_BACKOFF_DAYS = 3;
    public const DOMAIN_MIN_INTERVAL_SECONDS = 3600;
    public const CLAIM_LEASE_HOURS = 24;

    /** @var list<string> */
    private const SCOPE_STATUSES = [
        FindingStatus::NEW,
        FindingStatus::VERIFIED,
        FindingStatus::REPORTED,
        FindingStatus::WONTFIX,
    ];

    public function isInScope(Finding $finding): bool
    {
        return !$finding->isDiscarded()
            && $finding->getReviewState() !== ReviewState::MANUAL_CHECKING
            && in_array($finding->getStatus(), self::SCOPE_STATUSES, true);
    }

    public function nextDueAfter(Finding $finding, ?string $result, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if (!$this->isInScope($finding)) {
            return null;
        }

        $days = $result === RetestResult::ERROR ? self::ERROR_BACKOFF_DAYS : self::INTERVAL_DAYS;

        return $now->modify(sprintf('+%d days', $days));
    }

    public function ensureNextDue(Finding $finding, \DateTimeImmutable $now): void
    {
        if ($this->isInScope($finding) && $finding->getNextDueAt() === null) {
            $base = $finding->getLastRetestedAt() ?? $finding->getSubmittedAt() ?? $finding->getCreatedAt();
            $finding->setNextDueAt($base->modify(sprintf('+%d days', self::INTERVAL_DAYS)));
        }
        if (!$this->isInScope($finding)) {
            $finding->setNextDueAt(null);
        }
    }

    /**
     * Screenshot work is serialized by the screenshot queue, so stock rechecks
     * only request an image when the technical state needs visual review.
     * Stable repeated results do not create another screenshot job.
     */
    public function shouldQueueScreenshot(
        ?string $previousResult,
        string $result,
        ?string $previousStatus = null,
    ): bool {
        if (in_array($result, [RetestResult::INCONCLUSIVE, RetestResult::ERROR], true)) {
            return true;
        }
        if ($result === RetestResult::FIXED) {
            return $previousResult !== RetestResult::FIXED || $previousStatus !== FindingStatus::FIXED;
        }
        if ($result === RetestResult::STILL_VULNERABLE) {
            return ($previousResult !== null && $previousResult !== RetestResult::STILL_VULNERABLE)
                || $previousStatus === FindingStatus::FIXED;
        }

        return false;
    }
}
