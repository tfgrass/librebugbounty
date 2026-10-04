<?php

namespace App;

final class AppInfo
{
    public const NAME = 'LibreBugBounty';
    public const VERSION = '2.0.0';
    public const RELEASE_NAME = 'Moneta';
    public const AUTHOR = 'Tom Graßmann IT+Media';
    public const HOMEPAGE = 'https://grassmann-it.de/';
    public const OPENBUGBOUNTY_PROFILE = 'grassmann-it';
    public const OPENBUGBOUNTY_URL = 'https://www.openbugbounty.org/researchers/grassmann-it/';
    public const REPOSITORY = 'https://github.com/tfgrass/librebugbounty';
    public const CHANGELOG_URL = self::REPOSITORY.'/blob/HEAD/CHANGELOG.md';
    public const LICENSE = 'GPL-3.0-or-later';

    private function __construct()
    {
    }
}
