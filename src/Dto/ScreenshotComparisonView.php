<?php

namespace App\Dto;

use App\Entity\FindingAssessment;

final readonly class ScreenshotComparisonView
{
    /** @param ?array<string, mixed> $before @param ?array<string, mixed> $after */
    public function __construct(
        public ?array $before,
        public ?array $after,
        public ?FindingAssessment $assessment,
    ) {
    }

    public function basisId(): ?string
    {
        return $this->assessment?->getEvidenceId();
    }
}
