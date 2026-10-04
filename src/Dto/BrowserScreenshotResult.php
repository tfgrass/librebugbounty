<?php

namespace App\Dto;

final readonly class BrowserScreenshotResult
{
    public function __construct(
        public string $screenshotBase64,
        public \DateTimeImmutable $capturedAt,
        public ?int $httpStatus = null,
        public ?string $finalUrl = null,
        public bool $dialogSeen = false,
        public ?string $dialogType = null,
        public ?string $dialogText = null,
        public ?string $captureMethod = null,
        public array $metadata = [],
    ) {
    }
}
