<?php

namespace App\Dto;

use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;

final readonly class FindingDetailView
{
    /**
     * @param list<Evidence> $evidence
     * @param list<RetestRun> $runs
     * @param list<ScreenshotJob> $screenshotJobs
     * @param list<FindingAssessment> $assessments
     * @param list<array{evidence: Evidence, available: bool, url: ?string, capturedAt: ?\DateTimeImmutable, job: ?ScreenshotJob}> $screenshots
     */
    public function __construct(
        public Finding $finding,
        public array $evidence,
        public array $runs,
        public array $screenshotJobs,
        public array $assessments,
        public array $screenshots,
        public FindingAssessmentState $assessmentState,
    ) {
    }
}
