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

        $result = $this->browserRetestClient->retest(new BrowserRetestRequest(
            url: $finding->getUrl(),
            expectedEvidence: $finding->getExpectedEvidence(),
            timeoutMs: $timeoutMs,
            screenshot: $screenshot,
            headless: $headless,
            browser: $browser,
        ));

        $this->applyResult($run, $finding, $result, $screenshot, $noStatusUpdate);

        return $run;
    }

    public function recordBrowserResult(
        Finding $finding,
        RetestResultData $result,
        bool $screenshot = false,
        bool $noStatusUpdate = false,
    ): RetestRun {
        $run = new RetestRun();
        $run->setFinding($finding);
        $run->setMode(RetestMode::BROWSER);
        $run->setResult(RetestResult::PENDING);
        $run->setStartedAt(new \DateTimeImmutable());

        $this->applyResult($run, $finding, $result, $screenshot, $noStatusUpdate);

        return $run;
    }

    private function applyResult(
        RetestRun $run,
        Finding $finding,
        RetestResultData $result,
        bool $screenshot,
        bool $noStatusUpdate,
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
        $run->setRawResult(array_merge($result->raw, ['screenshotRequested' => $screenshot]));
        $run->setFinishedAt(new \DateTimeImmutable());

        if ($result->screenshotBase64 !== null && $result->screenshotBase64 !== '') {
            $screenshotPath = $this->storeScreenshot($finding, $run, $result->screenshotBase64);
            $run->setScreenshotPath($screenshotPath);
        }

        if ($result->dialogText !== null && $result->dialogText !== '') {
            $this->storeAlertTextEvidence($finding, $result->dialogText);
        }

        $finding->setLastRetestedAt(new \DateTimeImmutable());

        if (!$noStatusUpdate && !$finding->hasProtectedAssessment() && !$finding->isDiscarded()) {
            if ($result->result === RetestResult::FIXED) {
                $finding->setStatus('fixed');
            } elseif ($result->result === RetestResult::STILL_VULNERABLE) {
                if (in_array($finding->getStatus(), ['new', 'fixed'], true)) {
                    $finding->setStatus('verified');
                }
            }
            if (in_array($result->result, [RetestResult::FIXED, RetestResult::INCONCLUSIVE], true)) {
                $finding->setReviewState(ReviewState::MANUAL_CHECKING);
            }
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
