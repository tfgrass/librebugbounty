<?php

namespace App\Service;

use App\Dto\BrowserInteractiveSessionResult;
use App\Dto\BrowserScreenshotResult;

/** Internal generation-bound RPCs; never expose worker capabilities to the UI. */
interface FencedBrowserInteractiveClientInterface extends BrowserInteractiveClientInterface
{
    public function startFenced(string $url, int $timeoutMs, int $generation, ?string $sessionId = null): BrowserInteractiveSessionResult;
    /** Null explicitly selects the current page; a retained dialog requires its immutable ID. */
    public function captureFenced(string $sessionId, int $generation, ?string $expectedCandidateId): BrowserScreenshotResult;
    public function dialogFenced(string $sessionId, int $generation, string $action, int $dialogRevision): BrowserInteractiveSessionResult;
    /** Read retained PNG bytes only; no browser actions or captures. */
    public function interactiveDialogFrame(string $sessionId, int $generation, ?string $candidateId = null): string;
    public function closeFenced(string $sessionId, int $generation): void;
}
