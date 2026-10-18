<?php

namespace App\Tests;

use App\AppInfo;
use App\Service\ReleaseUpdateService;
use App\Tests\Support\ReleaseVersionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ReleaseUpdateServiceTest extends TestCase
{
    private string $directory;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/lbb-release-check-'.bin2hex(random_bytes(8));
        $this->now = new \DateTimeImmutable('2026-10-18T18:00:00Z');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testUnknownSnapshotDoesNotCreateFilesOrCallHttp(): void
    {
        $client = new MockHttpClient(static function (): never { throw new \LogicException('No HTTP during rendering.'); });
        $snapshot = (new ReleaseUpdateService($client, $this->directory))->snapshot($this->now);

        self::assertSame('unknown', $snapshot['state']);
        self::assertNull($snapshot['latestTag']);
        self::assertNull($snapshot['checkedAt']);
        self::assertFalse($snapshot['available']);
        self::assertFalse($snapshot['stale']);
        self::assertDirectoryDoesNotExist($this->directory);
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testLookupIsFixedBoundedAndStoresOnlyValidatedMetadata(): void
    {
        $request = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$request): MockResponse {
            $request = [$method, $url, $options];
            return $this->response(['tag_name' => ReleaseVersionFixture::newerTag(), 'draft' => false, 'prerelease' => false,
                'html_url' => 'javascript:alert(1)', 'body' => 'PRIVATE UNTRUSTED RELEASE BODY']);
        });
        $service = new ReleaseUpdateService($client, $this->directory);
        $snapshot = $service->check(now: $this->now);

        [$method, $url, $options] = $request;
        self::assertSame('GET', $method);
        self::assertSame('https://api.github.com/repos/tfgrass/librebugbounty/releases/latest', $url);
        self::assertSame(5.0, $options['timeout']);
        self::assertSame(5.0, $options['max_duration']);
        self::assertSame(0, $options['max_redirects']);
        self::assertFalse($options['buffer']);
        self::assertContains('Accept: application/vnd.github+json', $options['headers']);
        self::assertContains('User-Agent: LibreBugBounty-ReleaseCheck', $options['headers']);
        self::assertEmpty($options['auth_basic'] ?? null);
        self::assertEmpty($options['auth_bearer'] ?? null);
        self::assertTrue($snapshot['performed']);
        self::assertSame('available', $snapshot['state']);
        self::assertSame(ReleaseVersionFixture::newerVersion(), $snapshot['latestVersion']);
        self::assertSame(AppInfo::REPOSITORY.'/releases/tag/'.ReleaseVersionFixture::newerTag(), $snapshot['releaseUrl']);
        self::assertEquals($this->now, $snapshot['checkedAt']);
        $stored = file_get_contents($this->directory.'/release.json');
        self::assertStringNotContainsString('PRIVATE', $stored);
        self::assertStringNotContainsString('javascript:', $stored);
        self::assertEquals($service->snapshot($this->now), array_diff_key($snapshot, ['performed' => true]));
    }

    #[DataProvider('stableVersions')]
    public function testStableVersionComparison(string $tag, string $state, bool $available): void
    {
        $service = new ReleaseUpdateService(new MockHttpClient($this->release($tag)), $this->directory);
        $result = $service->check(now: $this->now);

        self::assertSame($state, $result['state']);
        self::assertSame($available, $result['available']);
        self::assertSame(ltrim($tag, 'v'), $result['latestVersion']);
    }

    public static function stableVersions(): iterable
    {
        yield 'current' => [AppInfo::VERSION, 'current', false];
        yield 'prefixed current' => ['v'.AppInfo::VERSION, 'current', false];
        yield 'older release' => ['1.99.99', 'ahead', false];
        yield 'new release' => [ReleaseVersionFixture::newerTag(), 'available', true];
        yield 'large integer does not overflow' => ['999999999999999999999.0.0', 'available', true];
    }

    public function testNumericVersionOrderIncludesTwoDigitPatches(): void
    {
        $service = new ReleaseUpdateService(new MockHttpClient(), $this->directory);
        $compare = new \ReflectionMethod($service, 'compareVersions');

        self::assertGreaterThan(0, $compare->invoke($service, '2.1.10', '2.1.9'));
        self::assertLessThan(0, $compare->invoke($service, '2.1.9', '2.1.10'));
        self::assertGreaterThan(0, $compare->invoke($service, '2.10.0', '2.9.99'));
    }

    #[DataProvider('invalidReleases')]
    public function testUntrustedReleaseDataCannotBecomeAnUpdate(mixed $release): void
    {
        $service = new ReleaseUpdateService(new MockHttpClient($this->response($release)), $this->directory);
        $result = $service->check(now: $this->now);

        self::assertSame('error', $result['state']);
        self::assertTrue($result['performed']);
        self::assertNull($result['latestVersion']);
        self::assertNull($result['releaseUrl']);
        self::assertNull($result['checkedAt']);
        self::assertFalse($result['available']);
    }

    public static function invalidReleases(): iterable
    {
        $stable = ['tag_name' => 'v2.1.1', 'draft' => false, 'prerelease' => false];
        yield 'draft' => [array_replace($stable, ['draft' => true])];
        yield 'prerelease' => [array_replace($stable, ['prerelease' => true])];
        yield 'string boolean' => [array_replace($stable, ['draft' => 'false'])];
        yield 'missing draft' => [['tag_name' => 'v2.1.1', 'prerelease' => false]];
        yield 'array tag' => [array_replace($stable, ['tag_name' => ['v2.1.1']])];
        yield 'integer tag' => [array_replace($stable, ['tag_name' => 211])];
        yield 'null' => [null];
        yield 'list' => [[$stable]];
        foreach (['', '2.1', 'v02.1.1', 'v2.01.1', 'v2.1.01', 'v2.1.1-rc1', 'v2.1.1+build',
            'V2.1.1', '../v2.1.1', 'https://evil.example/2.1.1', 'v2.1.1?x=1', 'v2.1.1#x',
            "v2.1.1\n", '<img src=x onerror=alert(1)>', str_repeat('9', 97).'.0.0'] as $tag) {
            yield 'invalid tag '.$tag => [array_replace($stable, ['tag_name' => $tag])];
        }
    }

    #[DataProvider('failedResponses')]
    public function testHttpAndParsingFailuresAreGenericAndRateLimited(string $kind): void
    {
        $attempts = 0;
        $client = new MockHttpClient(function () use ($kind, &$attempts): MockResponse {
            ++$attempts;
            return match ($kind) {
                'timeout' => new MockResponse((static function (): iterable { yield ''; })(), ['response_headers' => ['content-type: application/json']]),
                'transport' => throw new TransportException('PRIVATE token url body'),
                'malformed json' => new MockResponse('{"tag_name": PRIVATE', ['response_headers' => ['content-type: application/json']]),
                'html' => new MockResponse('<html>PRIVATE</html>', ['response_headers' => ['content-type: text/html']]),
                'oversized body' => new MockResponse(str_repeat('x', 131073), ['response_headers' => ['content-type: application/json']]),
                'oversized header' => new MockResponse('{}', ['response_headers' => ['content-type: application/json', 'content-length: 131073']]),
                default => new MockResponse('PRIVATE failure', ['http_code' => (int) $kind, 'response_headers' => ['content-type: application/json', 'location: https://evil.example/']]),
            };
        });
        $service = new ReleaseUpdateService($client, $this->directory);

        self::assertSame('error', $service->check(now: $this->now)['state']);
        self::assertFalse($service->check(now: $this->now->modify('+59 minutes'))['performed']);
        self::assertTrue($service->check(now: $this->now->modify('+1 hour'))['performed']);
        self::assertSame(2, $attempts);
        self::assertStringNotContainsString('PRIVATE', file_get_contents($this->directory.'/release.json'));
        self::assertStringNotContainsString('evil.example', file_get_contents($this->directory.'/release.json'));
    }

    public static function failedResponses(): iterable
    {
        foreach (['301', '302', '403', '404', '500', 'timeout', 'transport', 'malformed json', 'html', 'oversized body', 'oversized header'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function testNormalChecksAreDailyAndForcedChecksShareAMinuteCooldown(): void
    {
        $client = new MockHttpClient(fn () => $this->release(ReleaseVersionFixture::newerTag()));
        $service = new ReleaseUpdateService($client, $this->directory);

        self::assertTrue($service->check(now: $this->now)['performed']);
        self::assertFalse($service->check(true, $this->now->modify('+59 seconds'))['performed']);
        self::assertTrue($service->check(true, $this->now->modify('+60 seconds'))['performed']);
        self::assertFalse($service->check(now: $this->now->modify('+24 hours'))['performed']);
        self::assertTrue($service->check(now: $this->now->modify('+24 hours +60 seconds'))['performed']);
        self::assertSame(3, $client->getRequestsCount());
    }

    public function testFailedCheckPreservesLastVerifiedCandidateAndDoesNotClaimCurrent(): void
    {
        $client = new MockHttpClient([$this->release('v'.AppInfo::VERSION), new MockResponse('', ['http_code' => 403])]);
        $service = new ReleaseUpdateService($client, $this->directory);
        self::assertSame('current', $service->check(now: $this->now)['state']);

        $result = $service->check(true, $this->now->modify('+1 minute'));
        self::assertSame('error', $result['state']);
        self::assertTrue($result['stale']);
        self::assertSame(AppInfo::VERSION, $result['latestVersion']);
        self::assertEquals($this->now, $result['checkedAt']);
        self::assertEquals($this->now->modify('+1 minute'), $result['attemptedAt']);
        self::assertFalse($result['available']);
    }

    public function testStaleSnapshotPreservesTheCandidateWithoutCallingHttp(): void
    {
        $client = new MockHttpClient($this->release(ReleaseVersionFixture::newerTag()));
        $service = new ReleaseUpdateService($client, $this->directory);
        $service->check(now: $this->now);
        $before = file_get_contents($this->directory.'/release.json');

        $result = $service->snapshot($this->now->modify('+24 hours'));
        self::assertSame('stale', $result['state']);
        self::assertTrue($result['stale']);
        self::assertTrue($result['available']);
        self::assertSame($before, file_get_contents($this->directory.'/release.json'));
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testCurrentVersionAlwaysComesFromRunningBuildNotCachedInstallationVersion(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory.'/release.json', json_encode([
            'schema' => 1, 'latestTag' => 'v'.AppInfo::VERSION, 'checkedAt' => $this->now->getTimestamp(),
            'attemptedAt' => $this->now->getTimestamp(), 'failed' => false,
            'currentVersion' => '0.0.1', 'available' => true, 'releaseUrl' => 'https://evil.example/',
        ], JSON_THROW_ON_ERROR));
        $client = new MockHttpClient();
        $result = (new ReleaseUpdateService($client, $this->directory))->snapshot($this->now);

        self::assertSame('current', $result['state']);
        self::assertFalse($result['available']);
        self::assertSame(AppInfo::REPOSITORY.'/releases/tag/v'.AppInfo::VERSION, $result['releaseUrl']);
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testMalformedCacheCannotBreakReadOnlyRendering(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory.'/release.json', '{broken');
        $client = new MockHttpClient();
        $result = (new ReleaseUpdateService($client, $this->directory))->snapshot($this->now);

        self::assertSame('error', $result['state']);
        self::assertNull($result['releaseUrl']);
        self::assertSame('{broken', file_get_contents($this->directory.'/release.json'));
        self::assertFileDoesNotExist($this->directory.'/check.lock');
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testUnavailableCachePreventsNetworkAndReturnsGenericError(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory.'/blocked', 'file');
        $client = new MockHttpClient();
        $result = (new ReleaseUpdateService($client, $this->directory.'/blocked/cache'))->check(now: $this->now);

        self::assertSame('error', $result['state']);
        self::assertFalse($result['performed']);
        self::assertSame(0, $client->getRequestsCount());
    }

    #[DataProvider('brokenCaches')]
    public function testMissingFieldsAndFutureClockCannotClaimCurrentOrBlockRecovery(array $cache): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory.'/release.json', json_encode($cache, JSON_THROW_ON_ERROR));
        $client = new MockHttpClient($this->release('v'.AppInfo::VERSION));
        $service = new ReleaseUpdateService($client, $this->directory);

        self::assertSame('error', $service->snapshot($this->now)['state']);
        self::assertSame(0, $client->getRequestsCount());
        self::assertSame('current', $service->check(now: $this->now)['state']);
        self::assertSame(1, $client->getRequestsCount());
    }

    public static function brokenCaches(): iterable
    {
        $now = (new \DateTimeImmutable('2026-10-18T18:00:00Z'))->getTimestamp();
        $valid = ['schema' => 1, 'latestTag' => 'v'.AppInfo::VERSION, 'checkedAt' => $now, 'attemptedAt' => $now, 'failed' => false];
        yield 'only schema and flag' => [['schema' => 1, 'failed' => false]];
        foreach (['latestTag', 'checkedAt', 'attemptedAt', 'failed'] as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing '.$key => [$missing];
        }
        yield 'future clock' => [array_replace($valid, ['checkedAt' => $now + 86400, 'attemptedAt' => $now + 86400])];
        yield 'future attemptedAt' => [array_replace($valid, ['attemptedAt' => $now + 86400])];
        yield 'success before attempt' => [array_replace($valid, ['attemptedAt' => $now - 1])];
        yield 'unknown attempt' => [array_replace($valid, ['attemptedAt' => null])];
        yield 'string timestamp' => [array_replace($valid, ['checkedAt' => (string) $now])];
        yield 'missing tag for success' => [array_replace($valid, ['latestTag' => null])];
    }

    public function testConcurrentCheckerDoesNotWaitOrDuplicateHttp(): void
    {
        mkdir($this->directory);
        $lock = fopen($this->directory.'/check.lock', 'c');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $client = new MockHttpClient();
            $started = microtime(true);
            $result = (new ReleaseUpdateService($client, $this->directory))->check(true, $this->now);

            self::assertFalse($result['performed']);
            self::assertLessThan(0.5, microtime(true) - $started);
            self::assertSame(0, $client->getRequestsCount());
            self::assertFileDoesNotExist($this->directory.'/release.json');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function release(string $tag): MockResponse
    {
        return $this->response(['tag_name' => $tag, 'draft' => false, 'prerelease' => false]);
    }

    private function response(mixed $release): MockResponse
    {
        return new MockResponse(json_encode($release, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json; charset=utf-8']]);
    }
}
