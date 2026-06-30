<?php

namespace App\Value;

final class FindingStatus
{
    public const NEW = 'new';
    public const VERIFIED = 'verified';
    public const REPORTED = 'reported';
    public const FIXED = 'fixed';
    public const FALSE_POSITIVE = 'false_positive';
    public const WONTFIX = 'wontfix';
    public const DUPLICATE = 'duplicate';

    public static function values(): array
    {
        return [
            self::NEW,
            self::VERIFIED,
            self::REPORTED,
            self::FIXED,
            self::FALSE_POSITIVE,
            self::WONTFIX,
            self::DUPLICATE,
        ];
    }

    public static function openValues(): array
    {
        return [
            self::NEW,
            self::VERIFIED,
            self::REPORTED,
        ];
    }
}
