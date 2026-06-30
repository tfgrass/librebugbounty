<?php

namespace App\Value;

final class RetestResult
{
    public const PENDING = 'pending';
    public const STILL_VULNERABLE = 'still_vulnerable';
    public const FIXED = 'fixed';
    public const INCONCLUSIVE = 'inconclusive';
    public const ERROR = 'error';

    public static function values(): array
    {
        return [
            self::PENDING,
            self::STILL_VULNERABLE,
            self::FIXED,
            self::INCONCLUSIVE,
            self::ERROR,
        ];
    }
}
