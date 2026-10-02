<?php

namespace App\Dto;

use App\Entity\ScreenshotJob;

final readonly class ScreenshotEnqueueResult
{
    public function __construct(
        public ScreenshotJob $job,
        public bool $created,
    ) {
    }
}
