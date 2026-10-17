<?php

namespace App\Dto;

/** Worker-held fresh retry context; this does not resume a queued capture. */
final readonly class BrowserInteractiveSessionResult
{
    /**
     * @param array<string, mixed> $challenge
     * @param array<string, mixed> $initialChallenge
     * @param array<string, mixed> $dialogObservation
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
    ) {
    }
}
