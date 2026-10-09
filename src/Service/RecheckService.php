<?php

namespace App\Service;

use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Repository\RetestRunRepository;
use App\Value\RetestResult;
use App\Value\ReviewState;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stock recheck runner used by the recheck worker processes. Several worker
 * processes may run in parallel; every claim is an atomic conditional UPDATE
 * so two workers can never take the same finding. Claiming leases the slot
 * for 24 hours and pushes every other due finding of the same domain back by
 * one hour, which keeps the per-domain interval consistent across processes.
 * Each recheck commits on its own; a crashed worker's claim expires by
 * itself and the finding becomes due again.
 */
final class RecheckService
{
    public function __construct(
        private readonly FindingRepository $findings,
        private readonly RetestService $retestService,
        private readonly RecheckPolicy $policy,
        private readonly EntityManagerInterface $entityManager,
        private readonly RetestRunRepository $retestRuns,
        private readonly ScreenshotEnqueuerInterface $screenshots,
    ) {
    }

    /**
     * Run the next due recheck, if any. Returns a short outcome label or
     * null when nothing is currently claimable.
     */
    public function processNext(int $timeoutMs = 120000): ?string
    {
        $finding = $this->claimNext();
        if ($finding === null) {
            return null;
        }

        return $this->recheck($finding, $timeoutMs);
    }

    public function claimNext(): ?Finding
    {
        $now = new \DateTimeImmutable();
        foreach ($this->findings->findDueForRecheck($now) as $candidate) {
            if (!$this->policy->isInScope($candidate)) {
                // Status changed since scheduling; drop the slot.
                $candidate->setNextDueAt(null);
                $this->entityManager->flush();
                continue;
            }
            if ($this->claimAtomically($candidate, $now)) {
                return $candidate;
            }
            // Another worker claimed this finding between the read and our
            // UPDATE; continue with the next candidate.
        }

        return null;
    }

    private function claimAtomically(Finding $candidate, \DateTimeImmutable $now): bool
    {
        $connection = $this->entityManager->getConnection();
        $lease = $now->modify('+'.RecheckPolicy::CLAIM_LEASE_HOURS.' hours')->format('Y-m-d H:i:s');
        $spacedUntil = $now->modify('+'.RecheckPolicy::DOMAIN_MIN_INTERVAL_SECONDS.' seconds')->format('Y-m-d H:i:s');
        $expected = $candidate->getNextDueAt()?->format('Y-m-d H:i:s');

        $connection->beginTransaction();
        try {
            $claimed = $connection->executeStatement(
                'UPDATE finding SET next_due_at = ? WHERE id = ? AND next_due_at = ? AND (review_state IS NULL OR review_state <> ?)',
                [$lease, $candidate->getId(), $expected, ReviewState::MANUAL_CHECKING],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
            );
            if ($claimed !== 1) {
                $connection->rollBack();
                return false;
            }
            // Enforce the per-domain interval process-safe: every other due
            // finding of this domain moves back by one hour.
            $connection->executeStatement(
                'UPDATE finding SET next_due_at = ? WHERE domain_id = ? AND id != ? AND next_due_at IS NOT NULL AND next_due_at < ?',
                [$spacedUntil, $candidate->getDomain()->getId(), $candidate->getId(), $spacedUntil],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
            );
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        // Keep the in-memory entity in sync with the leased slot.
        $candidate->setNextDueAt(new \DateTimeImmutable($lease));

        return true;
    }

    private function recheck(Finding $finding, int $timeoutMs): string
    {
        try {
            // The database can change after selection and before the claim is
            // applied. Re-read before browser work so a concurrent manual
            // assessment or manual-review queue insertion pauses this run.
            if ($this->entityManager->contains($finding)) {
                $this->entityManager->refresh($finding);
            }
            if ($finding->getReviewState() === ReviewState::MANUAL_CHECKING) {
                $finding->setNextDueAt(null);
                $this->entityManager->flush();
                return 'skipped-manual-checking';
            }
            if ($finding->hasProtectedAssessment()) {
                // A human decision is authoritative; never retest it, just
                // push the slot forward.
                $finding->setNextDueAt($this->policy->nextDueAfter($finding, null, new \DateTimeImmutable()));
                $this->entityManager->flush();
                return 'skipped-protected';
            }

            $previousRun = $this->retestRuns->findRecentByFinding($finding, 1)[0] ?? null;
            $previousResult = $previousRun?->getResult();
            $previousStatus = $finding->getStatus();
            $run = $this->retestService->retest(
                $finding,
                screenshot: false,
                timeoutMs: $timeoutMs,
                headless: true,
            );

            if ($this->policy->shouldQueueScreenshot($previousResult, $run->getResult(), $previousStatus)) {
                try {
                    $this->screenshots->enqueue($finding);
                } catch (\Throwable $exception) {
                    return $run->getResult().' (screenshot queue failed: '.$exception->getMessage().')';
                }
            }

            return $run->getResult();
        } catch (\Throwable $exception) {
            // Do not lose the slot on unexpected failures; retry with the
            // short error backoff.
            if ($this->entityManager->contains($finding)) {
                $finding->setNextDueAt($this->policy->nextDueAfter($finding, RetestResult::ERROR, new \DateTimeImmutable()));
                $this->entityManager->flush();
            }
            return 'error: '.$exception->getMessage();
        }
    }
}
