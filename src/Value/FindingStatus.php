<?php

namespace App\Value;

final class FindingStatus
{
    public const NEW = 'new';
    public const VERIFIED = 'verified';
    public const REPORTED = 'reported';
    public const FIXED = 'fixed';
    public const WONTFIX = 'wontfix';
    public const DUPLICATE = 'duplicate';
    public const DISCARDED = 'discarded';

    public static function values(): array
    {
        return [
            self::NEW,
            self::VERIFIED,
            self::REPORTED,
            self::FIXED,
            self::WONTFIX,
            self::DUPLICATE,
            self::DISCARDED,
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
