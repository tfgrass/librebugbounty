<?php

namespace App\Service;

use App\Dto\BrowserScreenshotRequest;
use App\Dto\BrowserScreenshotResult;

interface BrowserScreenshotClientInterface
{
    /** Wait until the browser sidecar can accept work, or throw without claiming a job. */
    public function waitUntilReady(): void;

    public function capture(BrowserScreenshotRequest $request): BrowserScreenshotResult;
}
