<?php

namespace App\Value;

final class ReviewState
{
    public const MANUAL_CHECKING = 'manual_checking';
    public const MANUALLY_CHECKED = 'manually_checked';
    public const CONFIRMED_FIXED = 'confirmed_fixed';

    public static function values(): array
    {
        return [
            self::MANUAL_CHECKING,
            self::MANUALLY_CHECKED,
            self::CONFIRMED_FIXED,
        ];
    }
}
