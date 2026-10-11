<?php

namespace App\Tests;

use App\AppInfo;
use PHPUnit\Framework\TestCase;

final class ReleaseMetadataTest extends TestCase
{
    public function testApplicationWorkerAndPublicationMetadataDescribeTheSameRelease(): void
    {
        $root = dirname(__DIR__);
        $version = AppInfo::VERSION;
        $title = AppInfo::RELEASE_NAME.' - v'.$version;
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        $package = json_decode(file_get_contents($root.'/playwright-worker/package.json'), true, flags: JSON_THROW_ON_ERROR);
        $lock = json_decode(file_get_contents($root.'/playwright-worker/package-lock.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($version, $package['version']);
        self::assertSame($version, $lock['version']);
        self::assertSame($version, $lock['packages']['']['version']);
        self::assertStringStartsWith('# '.AppInfo::NAME.' '.$version.' “'.AppInfo::RELEASE_NAME.'”', file_get_contents($root.'/README.md'));
        self::assertStringContainsString('This guide describes '.AppInfo::NAME.' '.$version, file_get_contents($root.'/USAGE.md'));
        self::assertStringContainsString('## '.$version.' — '.AppInfo::RELEASE_NAME.' (', file_get_contents($root.'/CHANGELOG.md'));
        $notes = $root.'/.github/releases/v'.$version.'.md';
        self::assertFileExists($notes);
        self::assertStringStartsWith('# '.$title."\n", file_get_contents($notes));
        $guide = file_get_contents($root.'/.github/releases/README.md');
        self::assertStringContainsString("--title '".$title."'", $guide);
        self::assertStringContainsString('--notes-file .github/releases/v'.$version.'.md', $guide);
    }
}
