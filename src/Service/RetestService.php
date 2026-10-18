<?php

namespace App\Service;

use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Repository\RetestRunRepository;
use App\Value\EvidenceKind;
use App\Value\RetestMode;
use App\Value\RetestResult;
use App\Value\ReviewState;
use Doctrine\ORM\EntityManagerInterface;

final class RetestService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RetestRunRepository $retestRuns,
        private readonly BrowserRetestClientInterface $browserRetestClient,
        private readonly ValidationService $validation,
        private readonly EvidenceStorageInterface $storage,
        private readonly RecheckPolicy $recheckPolicy,
    ) {
    }

    public function retest(
        Finding $finding,
        bool $screenshot = false,
        int $timeoutMs = 120000,
        bool $dryRun = false,
        bool $noStatusUpdate = false,
        bool $headless = true,
        string $browser = 'chromium',
    ): RetestRun {
        if ($this->entityManager->contains($finding)) {
            $this->entityManager->refresh($finding);
        }
        if ($finding->isDiscarded()) {
            throw new \LogicException('Discarded findings are ignored.');
        }
        (new FindingWorkPolicy($this->entityManager->getConnection()))->assertChecksAllowed($finding);
        if (ReviewDispositionService::isDeferred($this->entityManager->getConnection(), $finding->getId())) {
            throw new \LogicException('Zurückgestellte Fälle werden nur zum gewählten Termin erneut geprüft.');
        }

        $this->validation->assertRetestMode(RetestMode::BROWSER);

        $run = new RetestRun();
        $run->setFinding($finding);
        $run->setMode(RetestMode::BROWSER);
        $run->setResult(RetestResult::PENDING);
        $run->setStartedAt(new \DateTimeImmutable());

        if ($dryRun) {
            $run->setFinishedAt(new \DateTimeImmutable());
            $run->setResult(RetestResult::INCONCLUSIVE);
            return $run;
        }

        $context = $this->captureContext($finding);
        $result = $this->browserRetestClient->retest(new BrowserRetestRequest(
            url: $finding->getPocUrl(),
            expectedEvidence: $finding->getExpectedEvidence(),
            timeoutMs: $timeoutMs,
            screenshot: $screenshot,
            headless: $headless,
            browser: $browser,
        ));

        $this->applyResult($run, $finding, $result, $screenshot, $noStatusUpdate, $context['active_poc_url'], $context['poc_revision'], $context);

        return $run;
    }

    public function recordBrowserResult(
        Finding $finding,
        RetestResultData $result,
        bool $screenshot = false,
        bool $noStatusUpdate = false,
        ?string $expectedPocUrl = null,
        ?int $expectedPocRevision = null,
        ?array $expectedFindingContext = null,
        bool $recovery = false,
    ): RetestRun {
        $run = new RetestRun();
        $run->setFinding($finding);
        $run->setMode(RetestMode::BROWSER);
        $run->setResult(RetestResult::PENDING);
        $run->setStartedAt(new \DateTimeImmutable());

        $this->applyResult($run, $finding, $result, $screenshot, $noStatusUpdate,
            $expectedPocUrl ?? $finding->getPocUrl(), $expectedPocRevision ?? $finding->getPocRevision(),
            $expectedFindingContext, $recovery);

        return $run;
    }

    /** The browser result is valid only for this exact URL, marker and assessment. */
    public function captureContext(Finding $finding): array
    {
        $connection = $this->entityManager->getConnection();
        $disposition = ReviewDispositionService::available($connection)
            ? $connection->fetchAssociative('SELECT revision, state FROM finding_review_disposition WHERE finding_id = ?', [$finding->getId()])
            : false;

        return [
            'id' => $finding->getId(),
            'status' => $finding->getStatus(),
            'review_state' => $finding->getReviewState(),
            'manual_assessment' => $finding->getManualAssessment(),
            'assessed_at' => $finding->getAssessedAt()?->format(DATE_ATOM),
            'assessment_history_id' => $this->entityManager->getConnection()->fetchOne('SELECT id FROM finding_assessment WHERE finding_id = ? ORDER BY rowid DESC LIMIT 1', [$finding->getId()]),
            'expected_evidence' => $finding->getExpectedEvidence(),
            'active_poc_url' => $finding->getPocUrl(),
            'poc_revision' => $finding->getPocRevision(),
            // The revision detects defer → resume even when the case returns
            // to the same visible state while this browser request is running.
            'review_disposition' => $disposition === false ? null : [
                'revision' => (int) $disposition['revision'], 'state' => $disposition['state'],
            ],
        ];
    }

    private function applyResult(
        RetestRun $run,
        Finding $finding,
        RetestResultData $result,
        bool $screenshot,
        bool $noStatusUpdate,
        string $expectedPocUrl,
        int $expectedPocRevision,
        ?array $expectedFindingContext = null,
        bool $recovery = false,
    ): void {
        $connection = $this->entityManager->getConnection();
        $managed = $this->entityManager->contains($finding);
        $ownsTransaction = $managed && !$connection->isTransactionActive();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }
        try {
            // Acquire the writer before reading the binding. A PoC update cannot
            // interleave between the refresh and committing this observation.
            if ($managed) {
                $connection->executeStatement('UPDATE finding SET id = id WHERE id = ?', [$finding->getId()]);
            }
            $this->applyLockedResult($run, $finding, $result, $screenshot, $noStatusUpdate,
                $expectedPocUrl, $expectedPocRevision, $expectedFindingContext, $recovery);
            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (\Throwable $error) {
            if ($ownsTransaction && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    private function applyLockedResult(
        RetestRun $run,
        Finding $finding,
        RetestResultData $result,
        bool $screenshot,
        bool $noStatusUpdate,
        string $expectedPocUrl,
        int $expectedPocRevision,
        ?array $expectedFindingContext,
        bool $recovery,
    ): void {
        // Browser work may finish after another request has assessed this case.
        // Reload its current assessment before applying the received observation.
        if ($this->entityManager->contains($finding)) {
            $this->entityManager->refresh($finding);
        }

        $run->setResult($result->result);
        $run->setHttpStatus($result->httpStatus);
        $run->setFinalUrl($result->finalUrl);
        $run->setObservedEvidence($result->observedEvidence ?? $result->dialogText);
        $run->setErrorMessage($result->errorMessage);
        $stalePoc = $finding->getPocUrl() !== $expectedPocUrl || $finding->getPocRevision() !== $expectedPocRevision;
        $staleContext = $expectedFindingContext !== null && $this->captureContext($finding) !== $expectedFindingContext;
        $connection = $this->entityManager->getConnection();
        $deferred = !$recovery && ReviewDispositionService::isDeferred($connection, $finding->getId());
        $blocked = !$recovery && !(new FindingWorkPolicy($connection))->checksAllowed($finding);
        $run->setRawResult(array_merge($result->raw, [
            'screenshotRequested' => $screenshot,
            'pocUrl' => $expectedPocUrl,
            'pocRevision' => $expectedPocRevision,
            'stalePoc' => $stalePoc,
            'staleFindingContext' => $staleContext || $deferred || $blocked,
            'recovery' => $recovery,
        ]));
        $run->setFinishedAt(new \DateTimeImmutable());

        if ($stalePoc || $staleContext || $deferred || $blocked) {
            // Keep an auditable historical result without presenting its proof
            // or scheduling information as a check of the current PoC.
            $this->entityManager->persist($run);
            $this->entityManager->flush();
            return;
        }

        if ($result->screenshotBase64 !== null && $result->screenshotBase64 !== '') {
            $screenshotPath = $this->storeScreenshot($finding, $run, $result->screenshotBase64);
            $run->setScreenshotPath($screenshotPath);
        }

        if ($result->dialogText !== null && $result->dialogText !== '') {
            $this->storeAlertTextEvidence($finding, $result->dialogText);
        }

        if (!$recovery) {
            $finding->setLastRetestedAt(new \DateTimeImmutable());
        }

        if (!$noStatusUpdate && !$recovery && !$finding->hasProtectedAssessment() && !$finding->isDiscarded()) {
            if ($result->result === RetestResult::FIXED) {
                // An automatically observed fix is authoritative; only
                // inconclusive outcomes enter manual review (D2).
                $finding->setStatus('fixed');
            } elseif ($result->result === RetestResult::STILL_VULNERABLE) {
                if (in_array($finding->getStatus(), ['new', 'fixed'], true)) {
                    $finding->setStatus('verified');
                }
            }
            if ($result->result === RetestResult::INCONCLUSIVE) {
                $finding->setReviewState(ReviewState::MANUAL_CHECKING);
            }
        }

        if (!$recovery) {
            $finding->setNextDueAt($this->recheckPolicy->nextDueAfter($finding, $result->result, new \DateTimeImmutable()));
        }

        $this->entityManager->persist($run);
        $this->entityManager->flush();
    }

    private function storeScreenshot(Finding $finding, RetestRun $run, string $base64): string
    {
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            throw new \RuntimeException('Browser worker returned an invalid screenshot payload.');
        }

        $stored = $this->storage->storeContents($finding, $binary, 'retest-'.$run->getId().'.png');
        $path = $stored->relativePath;

        $evidence = new Evidence();
        $evidence->setFinding($finding);
        $evidence->setKind(EvidenceKind::SCREENSHOT);
        $evidence->setFilePath($path);
        $evidence->setSha256($stored->sha256);
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();

        return $path;
    }

    private function storeAlertTextEvidence(Finding $finding, string $text): void
    {
        $evidence = new Evidence();
        $evidence->setFinding($finding);
        $evidence->setKind(EvidenceKind::ALERT_TEXT);
        $evidence->setValue($text);
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();
    }
}
