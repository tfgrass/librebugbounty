<?php

namespace App\Tests;

use App\Dto\BrowserScreenshotRequest;
use App\Service\BrowserInteractiveClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\PlaywrightBrowserInteractiveClient;
use App\Service\PlaywrightBrowserScreenshotClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlaywrightBrowserInteractiveClientTest extends KernelTestCase
{
    private const ID = '0123456789abcdef0123456789abcdef0123456789abcdef';
    private const URL = 'https://example.org/proof?test=1';
    private const WORKER = 'http://playwright:3000';
    private const TOKEN = 'fixture-worker-token';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

    public function testStatusRetainsInitialHttpAndDialogObservationSeparatelyFromCurrentDocument(): void
    {
        $observation = ['seen' => true, 'type' => 'alert', 'message' => 'benign fixture',
            'detectedAt' => '2026-10-17T12:01:00.000Z', 'captureSucceeded' => true];
        $client = $this->client(self::jsonResponse(self::session([
            'initialUrl' => self::URL, 'initialHttpStatus' => 403, 'httpStatus' => 200,
            'dialogObservation' => $observation,
        ])));

        $status = $client->interactiveStatus(self::ID);

        self::assertSame(self::URL, $status->initialUrl);
        self::assertSame(403, $status->initialHttpStatus);
        self::assertSame(200, $status->httpStatus);
        self::assertSame($observation, $status->dialogObservation);
    }

    public function testRetainedDialogMetadataIsIndependentOfLastObservation(): void
    {
        $retained = ['capturedAt' => '2026-10-17T12:01:01.000Z', 'detectedAt' => '2026-10-17T12:01:00.000Z',
            'pageUrl' => self::URL, 'type' => 'alert', 'message' => 'first'];
        $status = $this->client(self::jsonResponse(self::session([
            'retainedDialog' => $retained,
            'dialogObservation' => ['message' => 'second', 'captureSucceeded' => false],
        ])))->interactiveStatus(self::ID);
        self::assertSame($retained, $status->retainedDialog);
        self::assertSame('second', $status->dialogObservation['message']);
        self::assertNull($this->client(self::jsonResponse(self::session()))->interactiveStatus(self::ID)->retainedDialog);
    }

    public function testDialogPreviewUsesAuthenticatedGenerationBoundGetAndValidatesPng(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame(self::WORKER.'/interactive-sessions/'.self::ID.'/dialog-frame?generation=7', $url);
            self::assertSame('Authorization: Bearer '.self::TOKEN, $options['normalized_headers']['authorization'][0]);
            self::assertSame(0, $options['max_redirects']);
            return new MockResponse(base64_decode(self::PNG, true), ['response_headers' => ['Content-Type: image/png']]);
        });
        self::assertSame(base64_decode(self::PNG, true), (new PlaywrightBrowserInteractiveClient($http, self::WORKER, self::TOKEN))->interactiveDialogFrame(self::ID, 7));
        foreach ([['{}', 'application/json'], ['', 'image/png'], ['invalid', 'image/png'], [base64_decode(self::PNG, true), 'text/plain']] as [$body, $type]) {
            try {
                $this->client(new MockResponse($body, ['response_headers' => ['Content-Type: '.$type]]))->interactiveDialogFrame(self::ID, 7);
                self::fail('Invalid preview was accepted.');
            } catch (\RuntimeException $error) { self::assertStringContainsString('invalid PNG', $error->getMessage()); }
        }
        foreach ([0, -1] as $generation) {
            try {
                (new PlaywrightBrowserInteractiveClient(new MockHttpClient(static fn () => self::fail('No transport for invalid generation')), self::WORKER, self::TOKEN))->interactiveDialogFrame(self::ID, $generation);
                self::fail('Invalid generation was accepted.');
            } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testDialogActionsUseAuthenticatedRevisionBoundPostAndRetainCandidateIdentity(): void
    {
        $capture = ['state' => 'waiting', 'revision' => 4, 'armed' => true, 'pending' => false, 'attemptsRemaining' => 2];
        $candidateId = str_repeat('a', 48);
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($capture, $candidateId): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame(self::WORKER.'/interactive-sessions/'.self::ID.'/dialog', $url);
            self::assertSame(['generation' => 7, 'action' => 'arm', 'dialogRevision' => 3], json_decode($options['body'], true));
            self::assertContains('Authorization: Bearer '.self::TOKEN, $options['headers']);
            self::assertSame(0, $options['max_redirects']);
            return self::jsonResponse(self::session(['generation' => 7, 'captureFinalized' => false,
                'dialogCapture' => $capture, 'retainedDialog' => ['id' => $candidateId]]));
        });
        $result = (new PlaywrightBrowserInteractiveClient($http, self::WORKER, self::TOKEN))->dialogFenced(self::ID, 7, 'arm', 3);
        self::assertSame($capture, $result->dialogCapture);
        self::assertFalse($result->captureFinalized);
        self::assertSame($candidateId, $result->retainedDialog['id']);
        foreach ([null, 6, '7'] as $generation) {
            try {
                $this->client(self::jsonResponse(self::session(['generation' => $generation])))->dialogFenced(self::ID, 7, 'arm', 0);
                self::fail('Changed generation was accepted.');
            } catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
        }
        foreach ([['unknown', 0], ['retry', -1]] as [$action, $revision]) {
            try {
                (new PlaywrightBrowserInteractiveClient(new MockHttpClient(static fn () => self::fail('Invalid mutation reached HTTP')), self::WORKER, self::TOKEN))->dialogFenced(self::ID, 7, $action, $revision);
                self::fail('Invalid action or revision accepted.');
            } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testCandidateIdentityIsPassedToPreviewAndMalformedIdentityNeverReachesHttp(): void
    {
        $candidateId = str_repeat('a', 48);
        $http = new MockHttpClient(static function (string $method, string $url) use ($candidateId): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame(self::WORKER.'/interactive-sessions/'.self::ID.'/dialog-frame?generation=7&candidateId='.$candidateId, $url);
            return new MockResponse(base64_decode(self::PNG, true), ['response_headers' => ['Content-Type: image/png']]);
        });
        self::assertSame(base64_decode(self::PNG, true), (new PlaywrightBrowserInteractiveClient($http, self::WORKER, self::TOKEN))->interactiveDialogFrame(self::ID, 7, $candidateId));
        $this->expectException(\InvalidArgumentException::class);
        (new PlaywrightBrowserInteractiveClient(new MockHttpClient(static fn () => self::fail('Invalid candidate reached HTTP')), self::WORKER, self::TOKEN))->interactiveDialogFrame(self::ID, 7, 'invalid');
    }

    #[DataProvider('invalidDialogCaptureFields')]
    public function testMalformedDialogCaptureFailsFast(array $override): void
    {
        $capture = array_replace(['state' => 'failed', 'revision' => 1, 'armed' => true, 'pending' => true, 'attemptsRemaining' => 1], $override);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid dialogCapture');
        $this->client(self::jsonResponse(self::session(['dialogCapture' => $capture])))->interactiveStatus(self::ID);
    }

    public static function invalidDialogCaptureFields(): iterable
    {
        foreach (['state' => 'done', 'revision' => -1, 'armed' => 'true', 'pending' => 1, 'attemptsRemaining' => -1] as $field => $value) yield [[$field => $value]];
        yield [['revision' => null]];
        yield [['attemptsRemaining' => '2']];
    }

    public function testLifecycleUsesDedicatedRoutesAndPreservesSessionAndCaptureData(): void
    {
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url];
            self::assertContains('Authorization: Bearer '.self::TOKEN, $options['headers']);
            self::assertSame(0, $options['max_redirects']);
            self::assertGreaterThan(0, $options['timeout']);
            self::assertGreaterThan($options['timeout'], $options['max_duration']);
            switch (count($calls)) {
                case 1:
                    self::assertSame(['url' => self::URL, 'timeoutMs' => 45000], json_decode($options['body'], true));
                    return self::jsonResponse(self::session(), 201);
                case 2:
                    return self::jsonResponse(self::session());
                case 3:
                    self::assertSame(['action' => 'click', 'x' => 12, 'y' => 34], json_decode($options['body'], true));
                    return self::jsonResponse(self::session(['challenge' => ['state' => 'cleared', 'active' => false]]));
                case 4:
                    return new MockResponse(base64_decode(self::PNG), ['response_headers' => ['Content-Type: image/png', 'Cache-Control: no-store']]);
                case 5:
                    return self::jsonResponse(self::screenshot());
                case 6:
                    return self::jsonResponse(self::session());
                default:
                    return self::jsonResponse(['closed' => true, 'id' => self::ID]);
            }
        });
        $client = new PlaywrightBrowserInteractiveClient($http, self::WORKER.'/', self::TOKEN);
        $session = $client->startInteractive(self::URL, 45000);
        self::assertSame(self::ID, $session->id);
        self::assertSame('2026-10-17T12:00:00.123Z', $session->createdAt->format('Y-m-d\TH:i:s.v\Z'));
        self::assertSame('2026-10-17T12:20:00.123Z', $session->expiresAt->format('Y-m-d\TH:i:s.v\Z'));
        self::assertSame('UTC', $session->createdAt->getTimezone()->getName());
        self::assertSame(self::URL, $session->finalUrl);
        self::assertSame(403, $session->httpStatus);
        self::assertSame(['state' => 'detected', 'active' => true], $session->initialChallenge);
        self::assertSame($session->initialChallenge, $session->challenge);
        self::assertSame('Navigation timed out; page remains live.', $session->navigationError);
        self::assertEquals($session, $client->interactiveStatus(self::ID));
        self::assertSame('cleared', $client->interactiveInput(self::ID, ['action' => 'click', 'x' => 12, 'y' => 34])->challenge['state']);
        self::assertSame(base64_decode(self::PNG), $client->interactiveFrame(self::ID));
        $capture = $client->captureInteractive(self::ID);
        self::assertSame(self::PNG, $capture->screenshotBase64);
        self::assertSame('2026-10-17T12:03:04.567Z', $capture->capturedAt->format('Y-m-d\TH:i:s.v\Z'));
        self::assertSame(200, $capture->httpStatus);
        self::assertSame(self::URL, $capture->finalUrl);
        self::assertTrue($capture->dialogSeen);
        self::assertSame('alert', $capture->dialogType);
        self::assertSame('proof', $capture->dialogText);
        self::assertSame('interactive-desktop-page', $capture->captureMethod);
        self::assertSame(self::screenshot()['metadata'], $capture->metadata);
        $client->interactiveStatus(self::ID); // Capture must not implicitly close.
        $client->closeInteractive(self::ID);
        self::assertSame([
            ['POST', self::WORKER.'/interactive-sessions'],
            ['GET', self::WORKER.'/interactive-sessions/'.self::ID.'/status'],
            ['POST', self::WORKER.'/interactive-sessions/'.self::ID.'/input'],
            ['GET', self::WORKER.'/interactive-sessions/'.self::ID.'/frame'],
            ['POST', self::WORKER.'/interactive-sessions/'.self::ID.'/capture'],
            ['GET', self::WORKER.'/interactive-sessions/'.self::ID.'/status'],
            ['POST', self::WORKER.'/interactive-sessions/'.self::ID.'/close'],
        ], $calls);
    }

    public function testReadinessUsesUnauthenticatedHealthAndCloseAcceptsNoContent(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame(self::WORKER.'/health', $url);
            self::assertArrayNotHasKey('authorization', $options['normalized_headers']);
            return new MockResponse('OK');
        });
        (new PlaywrightBrowserInteractiveClient($http, self::WORKER))->waitUntilReady();
        self::assertSame(1, $http->getRequestsCount());
        $this->client(new MockResponse('', ['http_code' => 204]))->closeInteractive(self::ID);
    }

    #[DataProvider('unconfirmedClosures')]
    public function testCloseRequiresConfirmationForTheRequestedSession(array $response): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not confirm closure');
        $this->client(self::jsonResponse($response))->closeInteractive(self::ID);
    }

    public static function unconfirmedClosures(): iterable
    {
        yield [['unexpected' => true]];
        yield [['closed' => false, 'id' => self::ID]];
        yield [['closed' => true, 'id' => str_repeat('a', 48)]];
        yield [['closed' => true]];
    }

    #[DataProvider('inputActions')]
    public function testInputTimeoutAllowsWorkerNavigationWatchdogToFinish(string $action, int $timeout, int $duration): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($action, $timeout, $duration): MockResponse {
            self::assertSame(['action' => $action], json_decode($options['body'], true));
            self::assertSame((float) $timeout, $options['timeout']);
            self::assertSame((float) $duration, $options['max_duration']);
            return self::jsonResponse(self::session());
        });
        (new PlaywrightBrowserInteractiveClient($http, self::WORKER, self::TOKEN))->interactiveInput(self::ID, ['action' => $action]);
    }

    public static function inputActions(): iterable
    {
        yield ['reload', 55, 60];
        yield ['back', 55, 60];
        yield ['key', 20, 25];
    }

    #[DataProvider('httpErrors')]
    public function testHttpErrorsPreserveStatusAndWorkerMessage(string $operation, int $status, string $body, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode($status);
        $this->expectExceptionMessage($message);
        self::invoke($this->client(new MockResponse($body, ['http_code' => $status])), $operation);
    }

    public static function httpErrors(): iterable
    {
        foreach (['start', 'status', 'input', 'frame', 'dialog-frame', 'dialog', 'capture', 'close'] as $operation) {
            foreach ([400, 401, 403, 404, 405, 409, 410, 413, 422, 429, 500, 502, 503, 504] as $status) {
                yield "$operation HTTP $status worker JSON" => [$operation, $status, '{"errorMessage":"Worker rejected this request."}', 'Worker rejected this request.'];
            }
            foreach (['client error' => 400, 'server error' => 500] as $category => $status) {
                foreach (['empty' => '', 'HTML' => '<html>upstream failed</html>', 'malformed JSON' => '{"errorMessage":',
                    'missing message' => '{}', 'wrong message type' => '{"errorMessage":{"bad":true}}', 'blank message' => '{"errorMessage":" "}',
                ] as $shape => $body) {
                    yield "$operation $category $shape" => [$operation, $status, $body, sprintf('Browser worker request failed with HTTP %d.', $status)];
                }
            }
        }
    }

    #[DataProvider('invalidIds')]
    public function testInvalidRequestIdsNeverReachTransport(string $id, string $operation): void
    {
        $http = new MockHttpClient(static function (): never { self::fail('Invalid ID reached HTTP transport.'); });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid interactive browser session ID.');
        self::invoke(new PlaywrightBrowserInteractiveClient($http, self::WORKER, self::TOKEN), $operation, $id);
    }

    public static function invalidIds(): iterable
    {
        foreach (['', 'fixture-session-id', str_repeat('a', 47), str_repeat('a', 49), str_repeat('A', 48), str_repeat('g', 48), self::ID."\n", '../'.self::ID, self::ID.'/status'] as $id) {
            foreach (['status', 'input', 'frame', 'dialog-frame', 'dialog', 'capture', 'close'] as $operation) {
                yield [$id, $operation];
            }
        }
    }

    #[DataProvider('invalidSessionFields')]
    public function testInvalidSessionPayloadsFailFast(array $fields, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        $this->client(self::jsonResponse(self::session($fields)))->startInteractive(self::URL, 45000);
    }

    public static function invalidSessionFields(): iterable
    {
        foreach ([null, 123, '', str_repeat('A', 48), str_repeat('a', 47), str_repeat('a', 49), self::ID."\n"] as $id) {
            yield [['id' => $id], 'invalid interactive session ID'];
        }
        foreach (['createdAt' => 'creation', 'expiresAt' => 'expiry'] as $field => $label) {
            foreach (self::invalidTimestamps() as $value) {
                yield [[$field => $value], 'invalid session '.$label.' timestamp'];
            }
        }
        yield [['expiresAt' => '2026-10-17T12:00:00.123Z'], 'invalid session expiry timestamp'];
        yield [['expiresAt' => '2026-10-17T11:59:59.999Z'], 'invalid session expiry timestamp'];
        foreach (['finalUrl' => [], 'httpStatus' => '200', 'challenge' => false, 'initialChallenge' => 'cleared', 'navigationError' => [], 'captureFinalized' => 'true', 'dialogCapture' => 'failed'] as $field => $value) {
            yield [[$field => $value], 'invalid '.$field];
        }
        foreach (['httpStatus', 'initialHttpStatus'] as $field) {
            foreach ([0, 99, 600] as $value) {
                yield [[$field => $value], 'invalid '.$field];
            }
        }
    }

    #[DataProvider('sessionOperations')]
    public function testStatusAndInputRejectDifferentResponseIdsAndInvalidTimestamps(string $operation, array $fields): void
    {
        $this->expectException(\RuntimeException::class);
        self::invoke($this->client(self::jsonResponse(self::session($fields))), $operation);
    }

    public static function sessionOperations(): iterable
    {
        foreach (['status', 'input'] as $operation) {
            yield [$operation, ['id' => str_repeat('a', 48)]];
            yield [$operation, ['createdAt' => '2026-02-30T12:00:00.123Z']];
            yield [$operation, ['expiresAt' => null]];
        }
    }

    public function testUnknownChallengeAndNullableFieldsArePreservedWithoutClaimingClearance(): void
    {
        $challenge = ['state' => 'unknown', 'inspectionError' => 'Page unavailable'];
        $result = $this->client(self::jsonResponse(self::session([
            'challenge' => $challenge, 'initialChallenge' => $challenge,
            'finalUrl' => null, 'httpStatus' => null, 'navigationError' => null,
        ])))->interactiveStatus(self::ID);
        self::assertSame($challenge, $result->challenge);
        self::assertSame($challenge, $result->initialChallenge);
        self::assertNull($result->finalUrl);
        self::assertNull($result->httpStatus);
        self::assertNull($result->navigationError);
    }

    public function testValidLeapDayAndMillisecondPrecisionAreAccepted(): void
    {
        $result = $this->client(self::jsonResponse(self::session([
            'createdAt' => '2024-02-29T23:59:59.999Z', 'expiresAt' => '2024-03-01T00:19:59.999Z',
        ])))->interactiveStatus(self::ID);
        self::assertSame('2024-02-29T23:59:59.999Z', $result->createdAt->format('Y-m-d\TH:i:s.v\Z'));
        self::assertSame(1200, $result->expiresAt->getTimestamp() - $result->createdAt->getTimestamp());
    }

    #[DataProvider('invalidCaptureFields')]
    public function testInvalidCapturePayloadsFailFast(array $fields, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        $this->client(self::jsonResponse(self::screenshot($fields)))->captureInteractive(self::ID);
    }

    public static function invalidCaptureFields(): iterable
    {
        foreach ([null, '', 123, []] as $value) {
            yield [['screenshotBase64' => $value], 'did not return an interactive screenshot'];
        }
        foreach (self::invalidTimestamps() as $value) {
            yield [['capturedAt' => $value], 'invalid capture timestamp'];
        }
        foreach (['httpStatus' => '200', 'finalUrl' => [], 'dialogSeen' => 'true', 'dialogText' => false, 'dialogType' => [], 'captureMethod' => 1, 'metadata' => 'invalid'] as $field => $value) {
            yield [[$field => $value], 'invalid '.$field];
        }
    }

    #[DataProvider('invalidJson')]
    public function testJsonMethodsRejectInvalidSuccessBodies(string $operation, string $body): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Browser worker returned invalid JSON');
        self::invoke($this->client(new MockResponse($body)), $operation);
    }

    public static function invalidJson(): iterable
    {
        foreach (['start', 'status', 'input', 'dialog', 'capture', 'close'] as $operation) {
            foreach (['', '{', '<html>not JSON</html>', "\x89PNG\r\n\x1a\n"] as $body) {
                if ($operation === 'close' && $body === '') {
                    continue;
                }
                yield [$operation, $body];
            }
        }
    }

    #[DataProvider('invalidJsonObjects')]
    public function testJsonMethodsRequireObjects(string $body): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON object');
        $this->client(new MockResponse($body))->startInteractive(self::URL, 45000);
    }

    public static function invalidJsonObjects(): iterable
    {
        foreach (['[]', '[1]', 'null', 'true', '42', '"string"'] as $body) {
            yield [$body];
        }
    }

    #[DataProvider('successErrors')]
    public function testWorkerErrorInSuccessResponseIsNotLostDuringPayloadValidation(string $operation, mixed $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(is_string($message) && trim($message) !== '' ? $message : 'invalid error response');
        self::invoke($this->client(self::jsonResponse(['errorMessage' => $message])), $operation);
    }

    public static function successErrors(): iterable
    {
        foreach (['start', 'status', 'input', 'dialog', 'capture', 'close'] as $operation) {
            foreach (['Worker reported a failure.', '', ['bad' => true]] as $message) {
                yield [$operation, $message];
            }
        }
    }

    #[DataProvider('invalidFrames')]
    public function testFrameRejectsJsonEmptyOrMislabelledSuccessBody(string $body, string $type): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid PNG frame');
        $this->client(new MockResponse($body, ['response_headers' => ['Content-Type: '.$type]]))->interactiveFrame(self::ID);
    }

    public static function invalidFrames(): iterable
    {
        yield ['', 'image/png'];
        yield ['{"errorMessage":"not an image"}', 'application/json'];
        yield [self::PNG, 'image/png']; // A base64 string is not a binary frame.
        yield [base64_decode(self::PNG), 'application/json'];
    }

    #[DataProvider('missingTokens')]
    public function testMissingOrMalformedTokenFailsBeforeHttp(string $operation, ?string $token): void
    {
        $http = new MockHttpClient(static function (): never { self::fail('Unconfigured token reached HTTP transport.'); });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PLAYWRIGHT_WORKER_TOKEN');
        self::invoke(new PlaywrightBrowserInteractiveClient($http, self::WORKER, $token), $operation);
    }

    public static function missingTokens(): iterable
    {
        foreach (['start', 'status', 'input', 'frame', 'dialog-frame', 'dialog', 'capture', 'close'] as $operation) {
            foreach ([null, '', '   ', "bad\r\nInjected: header", 'bad token'] as $token) {
                yield [$operation, $token];
            }
        }
    }

    public function testIncorrectTokenSurfacesWorkerAuthenticationFailure(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertContains('Authorization: Bearer incorrect-token', $options['headers']);
            return self::jsonResponse(['errorMessage' => 'Invalid worker token.'], 401);
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('Invalid worker token.');
        (new PlaywrightBrowserInteractiveClient($http, self::WORKER, 'incorrect-token'))->startInteractive(self::URL, 45000);
    }

    public function testRedirectIsNotFollowedAndRetainsStatus(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(302);
        $this->client(new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: http://elsewhere.invalid/']]))->interactiveStatus(self::ID);
    }

    #[DataProvider('transportFailures')]
    public function testTransportFailuresAreWrappedIncludingLazyBodyErrors(string $operation, bool $lazy): void
    {
        $http = new MockHttpClient(static function () use ($lazy): MockResponse {
            if (!$lazy) {
                throw new TransportException('Connection refused');
            }
            return new MockResponse((static function (): \Generator {
                yield 'partial';
                throw new TransportException('Connection interrupted');
            })());
        });
        try {
            self::invoke(new PlaywrightBrowserInteractiveClient($http, self::WORKER, self::TOKEN), $operation);
            self::fail('Expected transport failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('Browser worker request failed during transport.', $error->getMessage());
            self::assertInstanceOf(TransportException::class, $error->getPrevious());
        }
    }

    public static function transportFailures(): iterable
    {
        foreach (['start', 'status', 'input', 'frame', 'dialog-frame', 'dialog', 'capture', 'close'] as $operation) {
            yield [$operation, false];
            yield [$operation, true];
        }
    }

    public function testServiceAliasesResolveToDedicatedClients(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $screenshot = $container->get(BrowserScreenshotClientInterface::class);
        self::assertInstanceOf(PlaywrightBrowserScreenshotClient::class, $screenshot);
        self::assertNotInstanceOf(BrowserInteractiveClientInterface::class, $screenshot);
        self::assertInstanceOf(PlaywrightBrowserInteractiveClient::class, $container->get(BrowserInteractiveClientInterface::class));

        self::ensureKernelShutdown();
        self::bootKernel();
        $container = self::getContainer();
        $screenshot = $container->get(BrowserScreenshotClientInterface::class);
        $interactiveMock = $this->createMock(BrowserInteractiveClientInterface::class);
        $container->set(BrowserInteractiveClientInterface::class, $interactiveMock);
        self::assertSame($screenshot, $container->get(BrowserScreenshotClientInterface::class));
        self::assertSame($interactiveMock, $container->get(BrowserInteractiveClientInterface::class));

        self::ensureKernelShutdown();
        self::bootKernel();
        $container = self::getContainer();
        $screenshotMock = $this->createMock(BrowserScreenshotClientInterface::class);
        $container->set(BrowserScreenshotClientInterface::class, $screenshotMock);
        self::assertInstanceOf(PlaywrightBrowserInteractiveClient::class, $container->get(BrowserInteractiveClientInterface::class));
        self::assertSame($screenshotMock, $container->get(BrowserScreenshotClientInterface::class));
    }

    public function testQueueScreenshotTransportUsesSharedTimestampParsingWithWorkerToken(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame(self::WORKER.'/screenshot', $url);
            self::assertContains('Authorization: Bearer '.self::TOKEN, $options['headers']);
            return self::jsonResponse(self::screenshot());
        });
        $result = (new PlaywrightBrowserScreenshotClient($http, self::WORKER, self::TOKEN))->capture(new BrowserScreenshotRequest(self::URL));
        self::assertSame(self::PNG, $result->screenshotBase64);
        self::assertSame('2026-10-17T12:03:04.567Z', $result->capturedAt->format('Y-m-d\TH:i:s.v\Z'));
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
                self::assertSame('http://127.0.0.1:1/interactive-sessions', $url);
                self::assertContains('Authorization: Bearer '.self::TOKEN, $options['headers']);
                return self::jsonResponse(self::session(), 201);
            });
            self::getContainer()->set(HttpClientInterface::class, $http);
            self::assertSame(self::ID, self::getContainer()->get(BrowserInteractiveClientInterface::class)->startInteractive(self::URL, 45000)->id);
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

    private function client(MockResponse $response): PlaywrightBrowserInteractiveClient
    {
        return new PlaywrightBrowserInteractiveClient(new MockHttpClient($response), self::WORKER, self::TOKEN);
    }

    private static function invoke(PlaywrightBrowserInteractiveClient $client, string $operation, string $id = self::ID): mixed
    {
        return match ($operation) {
            'start' => $client->startInteractive(self::URL, 45000),
            'status' => $client->interactiveStatus($id),
            'input' => $client->interactiveInput($id, ['action' => 'reload']),
            'frame' => $client->interactiveFrame($id),
            'dialog-frame' => $client->interactiveDialogFrame($id, 7),
            'dialog' => $client->dialogFenced($id, 7, 'arm', 0),
            'capture' => $client->captureInteractive($id),
            'close' => $client->closeInteractive($id),
        };
    }

    private static function session(array $overrides = []): array
    {
        return array_replace([
            'id' => self::ID,
            'createdAt' => '2026-10-17T12:00:00.123Z',
            'expiresAt' => '2026-10-17T12:20:00.123Z',
            'finalUrl' => self::URL,
            'httpStatus' => 403,
            'challenge' => ['state' => 'detected', 'active' => true],
            'initialChallenge' => ['state' => 'detected', 'active' => true],
            'navigationError' => 'Navigation timed out; page remains live.',
        ], $overrides);
    }

    private static function screenshot(array $overrides = []): array
    {
        return array_replace([
            'screenshotBase64' => self::PNG,
            'capturedAt' => '2026-10-17T12:03:04.567Z',
            'httpStatus' => 200,
            'finalUrl' => self::URL,
            'dialogSeen' => true,
            'dialogType' => 'alert',
            'dialogText' => 'proof',
            'captureMethod' => 'interactive-desktop-page',
            'metadata' => [
                'interactive' => true,
                'initialUrl' => self::URL,
                'initialHttpStatus' => 403,
                'currentHttpStatus' => 200,
                'initialChallenge' => ['state' => 'detected'],
                'challenge' => ['state' => 'cleared'],
                'retryOf' => 'job-id',
            ],
        ], $overrides);
    }

    private static function jsonResponse(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), [
            'http_code' => $status, 'response_headers' => ['Content-Type: application/json'],
        ]);
    }

    private static function invalidTimestamps(): array
    {
        return [null, 123, '', 'yesterday', '2026-10-17T12:00:00Z', '2026-10-17T12:00:00.123+00:00',
            '2026-10-17T12:00:00.123456Z', '2026-10-17T12:00:00.123Z'."\n", '2026-02-30T12:00:00.123Z',
            '2026-02-29T12:00:00.123Z', '2026-13-01T12:00:00.123Z', '2026-10-17T24:00:00.123Z',
            '2026-10-17T12:60:00.123Z', '2026-10-17T12:00:60.123Z'];
    }
}
