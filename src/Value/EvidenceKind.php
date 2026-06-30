<?php

namespace App\Value;

final class EvidenceKind
{
    public const SCREENSHOT = 'screenshot';
    public const HTML = 'html';
    public const ALERT_TEXT = 'alert_text';
    public const CONSOLE_LOG = 'console_log';
    public const HTTP_RESPONSE = 'http_response';
    public const NOTE = 'note';

    public static function values(): array
    {
        return [
            self::SCREENSHOT,
            self::HTML,
            self::ALERT_TEXT,
            self::CONSOLE_LOG,
            self::HTTP_RESPONSE,
            self::NOTE,
        ];
    }
}
