<?php

namespace App\Tests;

use App\Service\BrowserHealthService;
use App\Service\WorkerHealthRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BrowserHealthServiceTest extends TestCase
{
    public function testOnlyConfiguredHealthEndpointsAreReadOnceWithBoundedTimeouts(): void
    {
        $requests = [];
        $registry = new WorkerHealthRegistry(['recheck' => ['a' => 'http://localhost:3000', 'b' => 'http://localhost:3000'],
            'screenshot' => ['shot' => 'http://localhost:3001']]);
        $client = new MockHttpClient(static function ($method, $url, $options) use (&$requests) {
            $requests[] = [$method, $url];
            self::assertSame(1.0, $options['timeout']);
            self::assertSame(1.0, $options['max_duration']);
            self::assertSame(0, $options['max_redirects']);
            return new MockResponse('{"ok":true}');
        });
        self::assertSame(['http://localhost:3000' => true, 'http://localhost:3001' => true], (new BrowserHealthService($client, $registry))->check());
        self::assertSame([['GET', 'http://localhost:3000/health'], ['GET', 'http://localhost:3001/health']], $requests);
    }

    public function testMalformedFailedAndUnreachableServicesDoNotBreakOtherChecks(): void
    {
        $urls = ['http://localhost:3000', 'http://localhost:3001', 'http://localhost:3002', 'http://localhost:3003', 'http://localhost:3004', 'http://localhost:3005'];
        $registry = new WorkerHealthRegistry(['screenshot' => array_combine(['a', 'b', 'c', 'd', 'e', 'f'], $urls)]);
        $client = new MockHttpClient(static function ($method, $url) {
            return match ((int) parse_url($url, PHP_URL_PORT)) {
                3000 => new MockResponse('{"ok":true}'),
                3001 => new MockResponse('invalid JSON'),
                3002 => new MockResponse('{"ok":true}', ['http_code' => 503]),
                3003 => new MockResponse('{"ok":true}', ['http_code' => 302, 'response_headers' => ['location: https://example.com']]),
                3004 => throw new TransportException('Service offline'),
                3005 => new MockResponse((static function () { yield ''; })()), // Simulated timeout.
            };
        });
        self::assertSame(array_combine($urls, [true, false, false, false, false, false]), (new BrowserHealthService($client, $registry))->check());
    }
}
