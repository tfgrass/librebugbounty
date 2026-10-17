<?php

namespace App\Dto;

use App\Entity\Evidence;
use App\Entity\ScreenshotJob;

final readonly class InteractiveCaptureSaveResult
{
    public function __construct(
        public ScreenshotJob $job,
        public Evidence $evidence,
        // A close error must not turn an already committed capture into a
        // failed save (and invite duplicate evidence on retry). Keep the PHP
        // session record for an explicit close until this is true.
        public bool $sessionClosed = true,
        public ?string $sessionCloseError = null,
    ) {
    }
}
