<?php

namespace App\Dto;

final readonly class ReviewQueueView
{
    /** @param array{all: int, inconclusive: int, error: int, unchecked: int, ready: int, missing: int} $counts Global candidate counts, before filters/cursor. */
    public function __construct(
        public ?FindingDetailView $detail,
        public string $kind,
        public string $images,
        public array $counts,
        public int $total,
        public int $remaining,
        public string $nextPath,
        public string $restartPath,
        public string $currentPath,
        public ?string $after,
        public bool $eligible = true,
        public ?string $lastReviewedId = null,
        public string $stateFingerprint = '',
        public ?string $selectedEvidenceId = null,
    ) {
    }
}
