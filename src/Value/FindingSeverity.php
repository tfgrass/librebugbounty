<?php

namespace App\Value;

final class FindingSeverity
{
    public const INFO = 'info';
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';
    public const CRITICAL = 'critical';

    public static function values(): array
    {
        return [
            self::INFO,
            self::LOW,
            self::MEDIUM,
            self::HIGH,
            self::CRITICAL,
        ];
    }
}
