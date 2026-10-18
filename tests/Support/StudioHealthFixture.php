<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Only loaded by the disposable browser router, never by the application. */
final class StudioHealthFixture
{
    public static function httpClient(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            if ($method !== 'GET' || !in_array($path, ['/health', '/vnc-control/state'], true)) {
                throw new \RuntimeException('Fixture permits only mocked health reads.');
            }
            $scenario = @file_get_contents(getenv('STUDIO_BROWSER_ROOT').'/health-scenario');
            if ($path === '/vnc-control/state') {
                return new MockResponse($scenario === 'gateway-down' ? '{"error":"unavailable"}' : '{"activeGeneration":null,"sockets":[]}', ['http_code' => $scenario === 'gateway-down' ? 503 : 200]);
            }
            if ($scenario === 'interactive-down' && parse_url($url, PHP_URL_HOST) === 'interactive.test') {
                return new MockResponse('{"ok":false}', ['http_code' => 503]);
            }
            $down = $scenario === 'browser-down' && in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'playwright-shot-2'], true);
            return new MockResponse($down ? '{"ok":false}' : '{"ok":true}', ['http_code' => $down ? 503 : 200]);
        });
    }
}
