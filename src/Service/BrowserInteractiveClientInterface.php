<?php

namespace App\Service;

use App\Dto\BrowserInteractiveSessionResult;
use App\Dto\BrowserScreenshotResult;

/**
 * Transport for fresh, short-lived worker contexts.
 * Worker HTTP failures throw \RuntimeException with the HTTP status as the code
 * (in particular 404 for unknown IDs and 410 for expired sessions).
 */
interface BrowserInteractiveClientInterface
{
    /** Wait until the browser sidecar can accept interactive work. */
    public function waitUntilReady(): void;

    public function startInteractive(string $url, int $timeoutMs): BrowserInteractiveSessionResult;

    public function interactiveStatus(string $sessionId): BrowserInteractiveSessionResult;

    /** @param array<string, mixed> $event */
    public function interactiveInput(string $sessionId, array $event): BrowserInteractiveSessionResult;

    /** Returns raw PNG bytes, never JSON or base64. */
    public function interactiveFrame(string $sessionId): string;

    /** Captures without closing; the caller owns persistence and subsequent close. */
    public function captureInteractive(string $sessionId): BrowserScreenshotResult;

    public function closeInteractive(string $sessionId): void;
}
