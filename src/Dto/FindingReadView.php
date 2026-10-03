<?php

namespace App\Dto;

final readonly class FindingReadView
{
    public function __construct(
        public string $id,
        public string $domain,
        public string $title,
        public string $type,
        public string $severity,
        public string $legacyStatus,
        public ?string $legacyReviewState,
        public ?string $assessment,
        public ?string $discardReason,
        public ?\DateTimeImmutable $assessedAt,
        public ?\DateTimeImmutable $submittedAt,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $contactedAt,
        public ?string $observationId,
        public ?string $observationResult,
        public ?string $observationMode,
        public ?\DateTimeImmutable $observationAt,
        public bool $discarded,
        public string $url = '',
    ) {
    }
}
