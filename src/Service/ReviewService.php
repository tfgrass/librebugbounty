<?php

namespace App\Service;

use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Value\FindingStatus;
use App\Value\RetestResult;
use App\Value\ReviewState;
use Doctrine\ORM\EntityManagerInterface;

final class ReviewService
{
    public function __construct(
        private readonly FindingRepository $findings,
        private readonly RetestService $retestService,
        private readonly BrowserRetestTransportInterface $browserRetestTransport,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<Finding>
     */
    public function getPendingFindings(int $limit = PHP_INT_MAX): array
    {
        $targets = [];
        $ordered = $this->findings->findAllOrdered($limit);
        foreach ($ordered as $index => $finding) {
            if ($this->shouldReview($finding)) {
                $targets[] = ['finding' => $finding, 'index' => $index];
            }
        }

        usort($targets, static function (array $left, array $right): int {
            $leftPriority = self::reviewPriority($left['finding']);
            $rightPriority = self::reviewPriority($right['finding']);

            return $leftPriority <=> $rightPriority ?: $left['index'] <=> $right['index'];
        });

        return array_values(array_map(static fn (array $row): Finding => $row['finding'], $targets));
    }

    public function scan(int $limit = PHP_INT_MAX): int
    {
        $processed = 0;

        foreach ($this->getPendingFindings($limit) as $finding) {
            $this->reviewFinding($finding);
            $processed++;
        }

        return $processed;
    }

    private function shouldReview(Finding $finding): bool
    {
        if (\in_array($finding->getReviewState(), [ReviewState::MANUAL_CHECKING, ReviewState::CONFIRMED_FIXED], true)) {
            return false;
        }

        return true;
    }

    private static function reviewPriority(Finding $finding): int
    {
        return match ($finding->getStatus()) {
            FindingStatus::NEW => 0,
            FindingStatus::VERIFIED => 1,
            FindingStatus::REPORTED => 2,
            FindingStatus::FIXED => 3,
            default => 4,
        };
    }

    public function reviewFinding(
        Finding $finding,
        int $timeoutMs = 45000,
        bool $captureScreenshots = false,
        bool $headless = true,
    ): void
    {
        $wasManualCheck = $finding->getReviewState() === ReviewState::MANUAL_CHECKING;
        $noStatusUpdate = $wasManualCheck;

        $chromiumRequest = new \App\Dto\BrowserRetestRequest(
            url: $finding->getUrl(),
            expectedEvidence: $finding->getExpectedEvidence(),
            timeoutMs: $timeoutMs,
            screenshot: $captureScreenshots,
            headless: $headless,
            browser: 'chromium',
        );
        $firefoxRequest = new \App\Dto\BrowserRetestRequest(
            url: $finding->getUrl(),
            expectedEvidence: $finding->getExpectedEvidence(),
            timeoutMs: $timeoutMs,
            screenshot: $captureScreenshots,
            headless: $headless,
            browser: 'firefox',
        );

        $chromium = $this->retestBrowser($chromiumRequest);
        $firefox = $this->retestBrowser($firefoxRequest);

        $this->retestService->recordBrowserResult($finding, $chromium, $captureScreenshots, true);
        $this->retestService->recordBrowserResult($finding, $firefox, $captureScreenshots, true);

        if ($wasManualCheck) {
            $finding->setReviewState(ReviewState::MANUAL_CHECKING);
            $this->entityManager->flush();

            return;
        }

        $results = [$chromium->result, $firefox->result];
        $hasStillVulnerable = in_array(RetestResult::STILL_VULNERABLE, $results, true);
        $hasFixed = in_array(RetestResult::FIXED, $results, true);
        $hasInconclusive = in_array(RetestResult::INCONCLUSIVE, $results, true);
        $hasError = in_array(RetestResult::ERROR, $results, true);
        $wasFixed = $finding->getStatus() === FindingStatus::FIXED;

        if ($hasStillVulnerable) {
            $finding->setStatus(FindingStatus::VERIFIED);
        } elseif ($hasFixed) {
            $finding->setStatus(FindingStatus::FIXED);
        }

        if ($wasManualCheck || $hasFixed || $hasInconclusive || $hasError || ($wasFixed && $hasStillVulnerable)) {
            $finding->setReviewState(ReviewState::MANUAL_CHECKING);
        } else {
            $finding->setReviewState(null);
        }

        $this->entityManager->flush();
    }

    private function retestBrowser(\App\Dto\BrowserRetestRequest $request): \App\Dto\RetestResultData
    {
        try {
            $response = $this->browserRetestTransport->request($request);

            return $this->browserRetestTransport->decode($response);
        } catch (\Throwable $error) {
            return new \App\Dto\RetestResultData(
                result: RetestResult::ERROR,
                errorMessage: $error->getMessage(),
                raw: [
                    'browser' => $request->browser,
                    'exceptionClass' => $error::class,
                ],
            );
        }
    }
}
