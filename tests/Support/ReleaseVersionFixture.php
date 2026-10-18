<?php

namespace App\Tests\Support;

use App\AppInfo;

/** Keep update-availability fixtures newer than the build under test. */
final class ReleaseVersionFixture
{
    public static function newerVersion(): string
    {
        $parts = explode('.', AppInfo::VERSION);
        $parts[2] = (string) ((int) $parts[2] + 1);

        return implode('.', $parts);
    }

    public static function newerTag(): string
    {
        return 'v'.self::newerVersion();
    }
}
