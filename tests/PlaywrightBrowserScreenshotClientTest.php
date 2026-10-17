<?php

namespace App\Tests;

use App\Dto\BrowserScreenshotRequest;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\PlaywrightBrowserScreenshotClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlaywrightBrowserScreenshotClientTest extends KernelTestCase
{
    private const URL = 'https://example.org/proof';
    private const WORKER = 'http://playwright:3000';
    private const TOKEN = 'fixture-worker-token';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

    public function testCaptureSendsBearerTokenAndPreservesTimeouts(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame(self::WORKER.'/screenshot', $url);
            self::assertContains('Authorization: Bearer '.self::TOKEN, $options['headers']);
            self::assertSame([
                'url' => self::URL,
                'timeoutMs' => 45000,
                'settleMs' => 3000,
            ], json_decode($options['body'], true));
            self::assertSame(90.0, $options['timeout']);
            self::assertSame(120.0, $options['max_duration']);

            return self::jsonResponse(self::screenshot());
        });

        $result = (new PlaywrightBrowserScreenshotClient($http, self::WORKER, self::TOKEN))
            ->capture(new BrowserScreenshotRequest(self::URL));

        self::assertSame(self::PNG, $result->screenshotBase64);
        self::assertSame('2026-10-17T12:03:04.567Z', $result->capturedAt->format('Y-m-d\TH:i:s.v\Z'));
    }

    public function testHealthCheckRemainsTokenFree(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame(self::WORKER.'/health', $url);
            self::assertArrayNotHasKey('authorization', $options['normalized_headers']);
            self::assertSame(2.0, $options['timeout']);
            self::assertSame(2.0, $options['max_duration']);

            return new MockResponse('{"ok":true}', ['response_headers' => ['Content-Type' => 'application/json']]);
        });

        (new PlaywrightBrowserScreenshotClient($http, self::WORKER, self::TOKEN))->waitUntilReady();

        self::assertSame(1, $http->getRequestsCount());
    }

    #[DataProvider('missingTokens')]
    public function testCaptureFailsClearlyBeforeHttpWhenTokenIsMissing(?string $token): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('The worker must not be called.');
        });

        $client = new PlaywrightBrowserScreenshotClient($http, self::WORKER, $token);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Browser screenshot requests require PLAYWRIGHT_WORKER_TOKEN.');
        $client->capture(new BrowserScreenshotRequest(self::URL));
    }

    public function testServiceEntryInjectsConfiguredWorkerToken(): void
    {
        $name = 'PLAYWRIGHT_WORKER_TOKEN';
        $previous = [getenv($name), $_SERVER[$name] ?? null, $_ENV[$name] ?? null];
        putenv($name.'='.self::TOKEN);
        $_SERVER[$name] = $_ENV[$name] = self::TOKEN;
        try {
            self::bootKernel();
            $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame('http://127.0.0.1:1/screenshot', $url);
                self::assertContains('Authorization: Bearer '.self::TOKEN, $options['headers']);

                return self::jsonResponse(self::screenshot());
            });
            self::getContainer()->set(HttpClientInterface::class, $http);

            self::getContainer()->get(BrowserScreenshotClientInterface::class)->capture(new BrowserScreenshotRequest(self::URL));
            self::assertSame(1, $http->getRequestsCount());
        } finally {
            self::ensureKernelShutdown();
            putenv($previous[0] === false ? $name : $name.'='.$previous[0]);
            unset($_SERVER[$name], $_ENV[$name]);
            if ($previous[1] !== null) {
                $_SERVER[$name] = $previous[1];
            }
            if ($previous[2] !== null) {
                $_ENV[$name] = $previous[2];
            }
        }
    }

    public static function missingTokens(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    private static function screenshot(): array
    {
        return [
            'screenshotBase64' => self::PNG,
            'capturedAt' => '2026-10-17T12:03:04.567Z',
            'httpStatus' => 200,
            'finalUrl' => self::URL,
        ];
    }

    private static function jsonResponse(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }
}
