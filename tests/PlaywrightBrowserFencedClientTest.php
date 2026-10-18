<?php

namespace App\Tests;

use App\Service\PlaywrightBrowserInteractiveClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PlaywrightBrowserFencedClientTest extends TestCase
{
    public function testFencedStartAndCaptureKeepImmutableCaptureIdentityDistinctFromCurrentGeneration(): void
    {
        $id = str_repeat('a', 48);
        $calls = [];
        $client = new PlaywrightBrowserInteractiveClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, $id): MockResponse {
            $body = json_decode($options['body'], true);
            $calls[] = [$method, parse_url($url, PHP_URL_PATH), $body];
            self::assertContains('Authorization: Bearer local-test-token', $options['headers']);
            if (str_ends_with($url, '/interactive-sessions')) {
                return new MockResponse(json_encode(['id' => $id, 'createdAt' => '2026-10-17T00:00:00.000Z', 'expiresAt' => '2026-10-17T00:20:00.000Z', 'generation' => 7, 'captureLocked' => false]));
            }
            return new MockResponse(json_encode(['generation' => 7, 'screenshotBase64' => 'iVBORw0KGgo=', 'capturedAt' => '2026-10-17T00:05:00.000Z', 'metadata' => ['captureGeneration' => 3, 'captureId' => 'immutable-proof', 'inputMethod' => 'vnc', 'dialogCandidateId' => null]]));
        }), 'http://worker:3000', 'local-test-token');
        self::assertSame(7, $client->startFenced('http://127.0.0.1:1234/fixture', 1000, 7, $id)->generation);
        $result = $client->captureFenced($id, 7, null);
        self::assertSame(3, $result->metadata['captureGeneration']);
        self::assertSame('immutable-proof', $result->metadata['captureId']);
        self::assertSame(['url' => 'http://127.0.0.1:1234/fixture', 'timeoutMs' => 1000, 'generation' => 7, 'sessionId' => $id], $calls[0][2]);
        self::assertSame(['generation' => 7, 'expectedCandidateId' => null], $calls[1][2]);
    }

    public function testStaleCaptureResponseIsRejected(): void
    {
        $client = new PlaywrightBrowserInteractiveClient(new MockHttpClient(new MockResponse('{"generation":2,"screenshotBase64":"iVBORw0KGgo=","capturedAt":"2026-10-17T00:00:00.000Z"}')), 'http://worker', 'token');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(409);
        $client->captureFenced(str_repeat('a', 48), 3, null);
    }

    public function testCapturePinsSelectedCandidateAndRejectsUnconfirmedOrDifferentImages(): void
    {
        $candidate = str_repeat('b', 48);
        $base = ['generation' => 7, 'screenshotBase64' => 'iVBORw0KGgo=', 'capturedAt' => '2026-10-17T00:05:00.000Z'];
        $client = new PlaywrightBrowserInteractiveClient(new MockHttpClient(function (string $method, string $url, array $options) use ($candidate, $base): MockResponse {
            self::assertSame(['generation' => 7, 'expectedCandidateId' => $candidate], json_decode($options['body'], true));
            return new MockResponse(json_encode($base + ['metadata' => ['dialogCandidateId' => $candidate]]));
        }), 'http://worker', 'token');
        self::assertSame($candidate, $client->captureFenced(str_repeat('a', 48), 7, $candidate)->metadata['dialogCandidateId']);
        foreach ([[], ['dialogCandidateId' => null], ['dialogCandidateId' => str_repeat('c', 48)]] as $metadata) {
            $client = new PlaywrightBrowserInteractiveClient(new MockHttpClient(new MockResponse(json_encode($base + ['metadata' => $metadata]))), 'http://worker', 'token');
            try {
                $client->captureFenced(str_repeat('a', 48), 7, $candidate);
                self::fail('Unconfirmed or different screenshot accepted.');
            } catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
        }
        $client = new PlaywrightBrowserInteractiveClient(new MockHttpClient(new MockResponse(json_encode($base + ['metadata' => ['dialogCandidateId' => $candidate]]))), 'http://worker', 'token');
        try {
            $client->captureFenced(str_repeat('a', 48), 7, null);
            self::fail('A dialog must not replace the explicitly selected current page.');
        } catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
        $client = new PlaywrightBrowserInteractiveClient(new MockHttpClient(static fn () => self::fail('Malformed selection reached transport.')), 'http://worker', 'token');
        foreach (['', 'page', str_repeat('a', 47), str_repeat('A', 48), $candidate."\n"] as $invalid) {
            try {
                $client->captureFenced(str_repeat('a', 48), 7, $invalid);
                self::fail('Malformed selection accepted.');
            } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testCloseCarriesItsGenerationAndRequiresMatchingClosure(): void
    {
        $id = str_repeat('b', 48);
        $client = new PlaywrightBrowserInteractiveClient(new MockHttpClient(function (string $method, string $url, array $options) use ($id): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame(['generation' => 9], json_decode($options['body'], true));
            return new MockResponse(json_encode(['closed' => true, 'id' => $id]));
        }), 'http://worker', 'token');
        $client->closeFenced($id, 9);
    }
}
