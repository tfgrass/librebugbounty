<?php

namespace App\Service;

use App\Dto\ScreenshotStatus;
use App\Entity\RetestRun;

/** Describes stored capture information without changing a finding or running a browser. */
final class ScreenshotStatusService
{
    public function __construct(private readonly EvidenceStorageInterface $storage)
    {
    }

    public function forRun(RetestRun $run): ScreenshotStatus
    {
        $raw = $run->getRawResult() ?? [];
        $error = $raw['screenshotCaptureError'] ?? null;
        $error = is_string($error) && trim($error) !== '' ? trim($error) : null;
        $path = $run->getScreenshotPath();

        if ($path !== null && $path !== '') {
            if (!$this->storage->exists($path)) {
                return new ScreenshotStatus('missing', 'Screenshot file missing or unavailable', 'The historical file reference has been retained.');
            }

            return new ScreenshotStatus('available', 'Screenshot available', $error === null ? null : 'A capture attempt also reported: '.$error);
        }

        if ($error !== null) {
            return new ScreenshotStatus('failed', 'Screenshot capture failed', $error);
        }

        if (($raw['screenshotRequested'] ?? null) === true) {
            if ($run->getFinishedAt() === null) {
                return new ScreenshotStatus('unknown', 'No capture result recorded', 'This record has no completion time; an active capture is not confirmed.');
            }

            return new ScreenshotStatus('failed', 'Requested screenshot was not stored', 'No image or capture error was returned.');
        }

        if (($raw['screenshotRequested'] ?? null) === false) {
            return new ScreenshotStatus('not_requested', 'Screenshot not requested');
        }

        return new ScreenshotStatus('unknown', 'No screenshot stored', 'The historical record does not say whether a screenshot was requested.');
    }
}
