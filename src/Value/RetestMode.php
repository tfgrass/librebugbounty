<?php

namespace App\Value;

final class RetestMode
{
    public const BROWSER = 'browser';

    public static function values(): array
    {
        return [self::BROWSER];
    }
}
