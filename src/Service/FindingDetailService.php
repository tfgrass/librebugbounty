<?php

namespace App\Service;

use App\Dto\FindingAssessmentState;
use App\Dto\FindingDetailView;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Repository\EvidenceRepository;
use App\Repository\FindingAssessmentRepository;
use App\Repository\RetestRunRepository;
use App\Repository\ScreenshotJobRepository;
use App\Value\EvidenceKind;
use App\Value\FindingStatus;
use App\Value\ReviewState;

/** Shared, read-only detail projection for Classic and Studio. */
final class FindingDetailService
{
    public function __construct(
        private readonly FindingService $findingService,
        private readonly EvidenceRepository $evidenceRepository,
        private readonly RetestRunRepository $retestRunRepository,
        private readonly ScreenshotJobRepository $screenshotJobRepository,
        private readonly FindingAssessmentRepository $assessmentRepository,
        private readonly EvidenceStorageInterface $storage,
    ) {
    }

    public function get(string $id): FindingDetailView
    {
        $finding = $this->findingService->getFindingOrFail($id);
        $evidence = $this->evidenceRepository->findBy(['finding' => $finding], ['createdAt' => 'DESC']);
        $runs = $this->retestRunRepository->findRecentByFinding($finding, 20);
        $screenshotJobs = $this->screenshotJobRepository->findRecentByFinding($finding, null);
        $assessments = $this->assessmentRepository->findBy(['finding' => $finding], ['assessedAt' => 'DESC', 'id' => 'DESC']);

        return new FindingDetailView(
            finding: $finding,
            evidence: $evidence,
            runs: $runs,
            screenshotJobs: $screenshotJobs,
            assessments: $assessments,
            screenshots: $this->screenshots($evidence, $screenshotJobs),
            assessmentState: $this->assessmentState($finding, $runs, $assessments[0] ?? null),
        );
    }

    /** @param list<RetestRun> $runs */
    public function assessmentState(Finding $finding, array $runs, ?FindingAssessment $newest): FindingAssessmentState
    {
        $assessment = $finding->getManualAssessment();
        $latestRun = $runs[0] ?? null;
        $latestAt = $latestRun?->getFinishedAt() ?? $latestRun?->getStartedAt();
        $newerObservation = $assessment !== null && $latestAt !== null
            && $finding->getAssessedAt() !== null && $latestAt > $finding->getAssessedAt();
        if ($assessment !== null && $newest !== null) {
            // Record existence at the decision boundary, without implying that
            // the user reviewed a run. This also handles same-second SQLite rows.
            $newerObservation = $this->retestRunRepository->hasObservationAfterAssessment(
                $finding,
                $newest->getKnownObservationIds(),
            );
        }
        $needsConfirmation = ($assessment === null || $newerObservation)
            && ($latestRun?->getResult() === 'inconclusive'
                || ($assessment === null && $finding->getReviewState() === ReviewState::MANUAL_CHECKING));
        // Preserve explicit corrections and legacy fixed cases while keeping
        // technical activity separate from a stored manual decision.
        $canConfirm = $needsConfirmation || $assessment === 'fixed' || $finding->isDiscarded()
            || ($assessment === null && $finding->getStatus() === FindingStatus::FIXED);

        return new FindingAssessmentState(
            latestRun: $latestRun,
            latestAt: $latestAt,
            newerObservation: $newerObservation,
            needsConfirmation: $needsConfirmation,
            canConfirm: $canConfirm,
            canMarkFixed: $assessment !== 'fixed',
            canDiscard: $assessment !== 'discarded' || $finding->getDiscardReason() !== 'duplicate',
        );
    }

    /**
     * @param list<Evidence> $evidence
     * @param list<ScreenshotJob> $jobs
     * @return list<array{evidence: Evidence, available: bool, url: ?string, capturedAt: ?\DateTimeImmutable, job: ?ScreenshotJob}>
     */
    private function screenshots(array $evidence, array $jobs): array
    {
        $jobsByPath = [];
        foreach ($jobs as $job) {
            $path = $job->getScreenshotPath();
            if ($path !== null && $path !== '') {
                // The jobs arrive newest first; retain that capture metadata if
                // historical records happen to reference the same file.
                $jobsByPath[$this->relativePath($path)] ??= $job;
            }
        }

        $screenshots = [];
        foreach ($evidence as $item) {
            if ($item->getKind() !== EvidenceKind::SCREENSHOT) {
                continue;
            }

            $path = $item->getFilePath();
            $available = $path !== null && $path !== '' && $this->storage->exists($path);
            $relativePath = $path !== null && $path !== '' ? $this->relativePath($path) : null;
            $job = $relativePath !== null ? ($jobsByPath[$relativePath] ?? null) : null;
            $screenshots[] = [
                'evidence' => $item,
                'available' => $available,
                'url' => $available && $relativePath !== null
                    ? '/artifacts/'.implode('/', array_map('rawurlencode', explode('/', $relativePath)))
                    : null,
                'capturedAt' => $job?->getCapturedAt(),
                'job' => $job,
            ];
        }

        return $screenshots;
    }

    private function relativePath(string $path): string
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        return str_starts_with($normalized, 'storage/artifacts/')
            ? substr($normalized, strlen('storage/artifacts/'))
            : $normalized;
    }
}
