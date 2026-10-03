<?php

namespace App\Dto;

final readonly class ReviewNoticeView
{
    /** @param list<array<string, mixed>> $observations All unresolved qualifying stored runs, newest first; reason is contradiction|inconclusive|error. */
    public function __construct(
        public string $findingId,
        public string $assessment,
        public string $decisionKey,
        public bool $baselineKnown,
        public ?\DateTimeImmutable $lastAcknowledgedAt,
        public array $observations,
    ) {}
}
