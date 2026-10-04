<?php

namespace App\Value;

final class ManualAssessment
{
    public const CONFIRMED = 'confirmed';
    public const FIXED = 'fixed';
    public const DISCARDED = 'discarded';
    public const DUPLICATE = 'duplicate';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::CONFIRMED, self::FIXED, self::DISCARDED];
    }

    public static function validate(string $assessment, ?string $discardReason = null): void
    {
        if (!in_array($assessment, self::values(), true)) {
            throw new \InvalidArgumentException('Unsupported manual assessment.');
        }
        if ($discardReason !== null && ($assessment !== self::DISCARDED || $discardReason !== self::DUPLICATE)) {
            throw new \InvalidArgumentException('Only a discarded finding can have the duplicate reason.');
        }
    }
}
