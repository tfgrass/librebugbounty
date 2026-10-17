<?php

namespace App\Service;

use App\Dto\BrowserInteractiveSessionResult;
use App\Dto\BrowserScreenshotResult;

/** Internal generation-bound RPCs; never expose worker capabilities to the UI. */
interface FencedBrowserInteractiveClientInterface extends BrowserInteractiveClientInterface
{
    public function startFenced(string $url, int $timeoutMs, int $generation, ?string $sessionId = null): BrowserInteractiveSessionResult;
    public function captureFenced(string $sessionId, int $generation): BrowserScreenshotResult;
    /** Read retained PNG bytes only; no browser actions or captures. */
    public function interactiveDialogFrame(string $sessionId, int $generation): string;
    public function closeFenced(string $sessionId, int $generation): void;
}
