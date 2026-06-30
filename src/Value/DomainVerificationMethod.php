<?php

namespace App\Value;

final class DomainVerificationMethod
{
    public const MANUAL = 'manual';
    public const DNS = 'dns';
    public const SECURITY_TXT = 'security_txt';
    public const EMAIL = 'email';
    public const OTHER = 'other';

    public static function values(): array
    {
        return [
            self::MANUAL,
            self::DNS,
            self::SECURITY_TXT,
            self::EMAIL,
            self::OTHER,
        ];
    }
}
