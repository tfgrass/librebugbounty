<?php

namespace App\Dto;

use App\Entity\RetestRun;

final readonly class FindingAssessmentState
{
    public function __construct(
        public ?RetestRun $latestRun,
        public ?\DateTimeImmutable $latestAt,
        public bool $newerObservation,
        public bool $needsConfirmation,
        public bool $canConfirm,
        public bool $canMarkFixed,
        public bool $canDiscard,
    ) {
    }
}
