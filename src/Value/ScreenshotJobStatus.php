<?php

namespace App\Value;

final class ScreenshotJobStatus
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const AVAILABLE = 'available';
    public const FAILED = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::QUEUED, self::RUNNING, self::AVAILABLE, self::FAILED];
    }
}
