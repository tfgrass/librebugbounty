<?php

namespace App\Tests;

use App\AppInfo;
use App\Command\UpdateWorkerCommand;
use App\Service\ReleaseUpdateService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UpdateWorkerCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/lbb-update-command-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testOnceChecksAndThenUsesSharedCachedResult(): void
    {
        $response = new MockResponse(json_encode(['tag_name' => 'v'.AppInfo::VERSION, 'draft' => false, 'prerelease' => false]), ['response_headers' => ['content-type: application/json']]);
        $client = new MockHttpClient($response);
        $tester = new CommandTester(new UpdateWorkerCommand(new ReleaseUpdateService($client, $this->directory)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--once' => true]));
        self::assertStringContainsString('Release check: current.', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['--once' => true, '--force' => true]));
        self::assertStringContainsString('Release check: current (cached).', $tester->getDisplay());
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testOnceReportsFailureWithoutExposingRemoteContent(): void
    {
        $client = new MockHttpClient(new MockResponse('SECRET RESPONSE', ['http_code' => 403]));
        $tester = new CommandTester(new UpdateWorkerCommand(new ReleaseUpdateService($client, $this->directory)));

        self::assertSame(Command::FAILURE, $tester->execute(['--once' => true]));
        self::assertStringContainsString('Release check: error.', $tester->getDisplay());
        self::assertStringNotContainsString('SECRET', $tester->getDisplay());
    }

    public function testInvalidSleepFailsBeforeNetworkOrCacheWrite(): void
    {
        $client = new MockHttpClient();
        $tester = new CommandTester(new UpdateWorkerCommand(new ReleaseUpdateService($client, $this->directory)));

        self::assertSame(Command::INVALID, $tester->execute(['--once' => true, '--sleep' => 'NaN']));
        self::assertSame(0, $client->getRequestsCount());
        self::assertDirectoryDoesNotExist($this->directory);
    }
}
