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
            if ($method !== 'GET' || parse_url($url, PHP_URL_PATH) !== '/health') {
                throw new \RuntimeException('Fixture permits only mocked health reads.');
            }
            $scenario = @file_get_contents(getenv('STUDIO_BROWSER_ROOT').'/health-scenario');
            $down = $scenario === 'browser-down' && in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'playwright-shot-2'], true);
            return new MockResponse($down ? '{"ok":false}' : '{"ok":true}', ['http_code' => $down ? 503 : 200]);
        });
    }
}
