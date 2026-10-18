<?php

namespace App\Dto;

/** Worker-held fresh retry context; this does not resume a queued capture. */
final readonly class BrowserInteractiveSessionResult
{
    /**
     * @param array<string, mixed> $challenge
     * @param array<string, mixed> $initialChallenge
     * @param array<string, mixed>|null $retainedDialog
     * @param array<string, mixed> $dialogObservation
     * @param array<string, mixed> $dialogCapture
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public ?string $finalUrl = null,
        public ?int $httpStatus = null,
        public array $challenge = [],
        public array $initialChallenge = [],
        public ?string $navigationError = null,
        public ?string $initialUrl = null,
        public ?int $initialHttpStatus = null,
        public array $dialogObservation = [],
        public ?int $generation = null,
        public bool $captureLocked = false,
        public ?string $captureId = null,
        public ?int $captureGeneration = null,
        public ?array $retainedDialog = null,
        public bool $captureFinalized = false,
        public array $dialogCapture = [],
        public array $urlCandidates = [],
        public bool $urlCandidatesTruncated = false,
    ) {
    }
}
