<?php

namespace App\Dto;

final readonly class BrowserScreenshotRequest
{
    public function __construct(
        public string $url,
        public int $timeoutMs = 45000,
        public int $settleMs = 3000,
    ) {
    }
}
