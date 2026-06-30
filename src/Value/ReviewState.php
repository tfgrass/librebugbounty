<?php

namespace App\Value;

final class ReviewState
{
    public const MANUAL_CHECKING = 'manual_checking';
    public const CONFIRMED_FIXED = 'confirmed_fixed';

    public static function values(): array
    {
        return [
            self::MANUAL_CHECKING,
            self::CONFIRMED_FIXED,
        ];
    }
}
