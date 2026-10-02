<?php

namespace App\Dto;

final readonly class ScreenshotStatus
{
    public function __construct(
        public string $state,
        public string $label,
        public ?string $detail = null,
    ) {
    }

    public function isProblem(): bool
    {
        return in_array($this->state, ['failed', 'missing'], true);
    }
}
