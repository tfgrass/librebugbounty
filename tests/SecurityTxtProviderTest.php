<?php
namespace App\Tests;

use App\Service\SecurityTxtProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SecurityTxtProviderTest extends TestCase
{
    private const SOURCE = 'https://example.org/.well-known/security.txt';
    private const VALID = "Contact: mailto:security@example.org\nContact: https://example.org/report\nExpires: 2099-10-10T12:00:00Z\nPolicy: https://example.org/disclosure\nPreferred-Languages: de, en-US\n";

    public function testParsedContactsAreSourcedWithoutFollowingAnyContactUrl(): void
    {
        $client = new MockHttpClient(static function ($method, $url, $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame(self::SOURCE, $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(6.0, $options['max_duration']);
            return new MockResponse(self::VALID, ['http_code' => 200, 'response_headers' => ['Content-Type: text/plain; charset=utf-8']]);
        });
        $result = (new SecurityTxtProvider($client))->discover('example.org');
        self::assertSame('found', $result->status);
        self::assertSame(self::SOURCE, $result->source);
        self::assertSame(['email', 'web'], array_column($result->contacts, 'channel'));
        self::assertSame(['de', 'en-US'], $result->languages);
        self::assertSame(['https://example.org/disclosure'], $result->policies);
        self::assertSame(1, $client->getRequestsCount());
    }

    #[DataProvider('documents')]
    public function testDocumentValidation(string $body, string $status): void
    {
        $provider = new SecurityTxtProvider(new MockHttpClient());
        self::assertSame($status, $provider->parse($body, self::SOURCE, new \DateTimeImmutable('2026-10-10T00:00:00Z'))->status);
    }
    public static function documents(): iterable
    {
        yield [self::VALID, 'found'];
        yield [self::VALID.'Canonical: '.self::SOURCE, 'found'];
        yield [self::VALID.'Canonical: https://elsewhere.invalid/security.txt', 'invalid'];
        yield ["Contact: mailto:security@example.org\nExpires: 2020-01-01T00:00:00Z", 'expired'];
        yield ["Contact: mailto:security@example.org\nExpires: 2099-02-30T00:00:00Z", 'invalid'];
        yield [self::VALID."Expires: 2099-11-10T12:00:00Z\n", 'invalid'];
        yield ["Expires: 2099-11-10T12:00:00Z\nContact: javascript:alert(1)", 'invalid'];
        yield ["Contact: mailto:security@example.org", 'invalid'];
        yield [self::VALID."\xff", 'invalid'];
        yield [self::VALID."\0", 'invalid'];
        yield [str_repeat('a', 65537), 'invalid'];
        yield ["-----BEGIN PGP SIGNED MESSAGE-----\n".self::VALID, 'invalid'];
    }

    public function testOnlyMissingFileUsesLegacyFallback(): void
    {
        $urls = [];
        $client = new MockHttpClient(static function ($method, $url) use (&$urls): MockResponse {
            $urls[] = $url;
            return count($urls) === 1 ? new MockResponse('', ['http_code' => 404])
                : new MockResponse(self::VALID, ['response_headers' => ['content-type: text/plain']]);
        });
        self::assertSame('found', (new SecurityTxtProvider($client))->discover('example.org')->status);
        self::assertSame([self::SOURCE, 'https://example.org/security.txt'], $urls);
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 410]), new MockResponse('', ['http_code' => 404])]);
        self::assertSame('not_found', (new SecurityTxtProvider($client))->discover('example.org')->status);
        self::assertSame(2, $client->getRequestsCount());
    }

    #[DataProvider('failedResponses')]
    public function testRedirectsFailuresAndOversizedResponsesDoNotTriggerMoreRequests(int $code, string $body, string $mime, string $status): void
    {
        $client = new MockHttpClient(new MockResponse($body, ['http_code' => $code, 'response_headers' => ['Content-Type: '.$mime, 'Location: https://elsewhere.invalid/security.txt']]));
        self::assertSame($status, (new SecurityTxtProvider($client))->discover('example.org')->status);
        self::assertSame(1, $client->getRequestsCount());
    }
    public static function failedResponses(): iterable
    {
        yield [302, '', 'text/plain', 'error'];
        yield [500, '', 'text/plain', 'error'];
        yield [200, '<html>not security.txt</html>', 'text/html', 'invalid'];
        yield [200, str_repeat('a', 65537), 'text/plain', 'error'];
    }
    public function testTimeoutDoesNotTriggerFallback(): void
    {
        $client = new MockHttpClient(new MockResponse((static function () { yield ''; })(), ['response_headers' => ['content-type: text/plain']]));
        self::assertSame('error', (new SecurityTxtProvider($client))->discover('example.org')->status);
        self::assertSame(1, $client->getRequestsCount());
    }
    public function testPublicNetworkWrapperRejectsPrivateDnsAndChangedPeerWithoutRealDns(): void
    {
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '::1', 'fc00::1'] as $ip) {
            $transport = new MockHttpClient(static function () { self::fail('Private destination reached transport'); });
            $client = (new \Symfony\Component\HttpClient\NoPrivateNetworkHttpClient($transport))
                ->withOptions(['resolve' => ['example.org' => $ip]]);
            self::assertSame('error', (new SecurityTxtProvider($client))->discover('example.org')->status);
            self::assertSame(0, $transport->getRequestsCount());
        }
        $transport = new MockHttpClient(new MockResponse(self::VALID, ['primary_ip' => '10.0.0.1', 'response_headers' => ['content-type: text/plain']]));
        $client = (new \Symfony\Component\HttpClient\NoPrivateNetworkHttpClient($transport))
            ->withOptions(['resolve' => ['example.org' => '93.184.216.34']]);
        self::assertSame('error', (new SecurityTxtProvider($client))->discover('example.org')->status);
    }

    public function testPrivateAndMalformedHostnamesNeverReachTransport(): void
    {
        $client = new MockHttpClient(static function () { self::fail('Must not send a request'); });
        foreach (['127.0.0.1', '[::1]', 'localhost', 'a.local', 'a.internal', 'a.invalid', 'a.test', 'https://example.org', 'a.org/path', 'a.org:443', 'user@a.org', "a.org\r\nX: bad"] as $host) {
            self::assertSame('error', (new SecurityTxtProvider($client))->discover($host)->status, $host);
        }
        self::assertSame(0, $client->getRequestsCount());
    }
}
