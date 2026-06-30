<?php

namespace App\Dto;

final class BrowserRetestRequest
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $expectedEvidence,
        public readonly int $timeoutMs,
        public readonly bool $screenshot,
        public readonly string $browser = 'chromium',
    ) {
    }
}
