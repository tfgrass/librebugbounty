<?php

namespace App\Tests;

use App\Dto\BrowserRetestRequest;
use App\Service\BrowserRetestClientInterface;
use App\Service\PlaywrightBrowserRetestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlaywrightBrowserRetestClientTest extends KernelTestCase
{
    private const URL = 'https://example.org/proof';
    private const WORKER = 'http://playwright:3000';
    private const TOKEN = 'fixture-worker-token';

    public function testRetestSendsBearerTokenAndPreservesTimeouts(): void
    {
        $previousSocketTimeout = (string) ini_get('default_socket_timeout');
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame(self::WORKER.'/retest', $url);
            self::assertContains('Authorization: Bearer '.self::TOKEN, $options['headers']);
            self::assertSame([
                'url' => self::URL,
                'expectedEvidence' => 'evidence',
                'timeoutMs' => 180000,
                'screenshot' => true,
                'headless' => true,
                'browser' => 'chromium',
            ], json_decode($options['body'], true));
            self::assertSame(840.0, $options['timeout']);
            self::assertSame(1200.0, $options['max_duration']);

            return self::jsonResponse(['result' => 'observed', 'observedEvidence' => 'evidence']);
        });

        $result = (new PlaywrightBrowserRetestClient($http, self::WORKER, self::TOKEN))
            ->retest(new BrowserRetestRequest(self::URL, 'evidence', 180000, true));

        self::assertSame('observed', $result->result);
        self::assertSame('evidence', $result->observedEvidence);
        self::assertSame($previousSocketTimeout, (string) ini_get('default_socket_timeout'));
    }

    #[DataProvider('missingTokens')]
    public function testRetestFailsClearlyBeforeHttpWhenTokenIsMissing(?string $token): void
    {
        $previousSocketTimeout = (string) ini_get('default_socket_timeout');
        $http = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('The worker must not be called.');
        });

        $client = new PlaywrightBrowserRetestClient($http, self::WORKER, $token);

        try {
            $client->request(new BrowserRetestRequest(self::URL, 'evidence', 10000, true));
            self::fail('A missing worker token must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Browser retest requests require PLAYWRIGHT_WORKER_TOKEN.', $error->getMessage());
        }
        self::assertSame(0, $http->getRequestsCount());
        self::assertSame($previousSocketTimeout, (string) ini_get('default_socket_timeout'));
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
                self::assertSame('http://127.0.0.1:1/retest', $url);
                self::assertContains('Authorization: Bearer '.self::TOKEN, $options['headers']);

                return self::jsonResponse(['result' => 'observed']);
            });
            self::getContainer()->set(HttpClientInterface::class, $http);

            $result = self::getContainer()->get(BrowserRetestClientInterface::class)
                ->retest(new BrowserRetestRequest(self::URL, 'evidence', 10000, true));
            self::assertSame('observed', $result->result);
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

    private static function jsonResponse(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }
}
