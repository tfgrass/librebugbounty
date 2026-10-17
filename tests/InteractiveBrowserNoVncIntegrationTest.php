<?php

namespace App\Tests;

use App\Entity\Finding;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use App\Service\InteractiveBrowserLeaseService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

require_once __DIR__.'/Support/studio_review_novnc_router.php';

/** P8: real PHP orchestration/SQLite with a bounded, in-process HTTP worker. */
final class InteractiveBrowserNoVncIntegrationTest extends DatabaseTestCase
{
    private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private Session $session;
    private \StudioReviewNoVncWorkerFixture $worker;
    private array $fixtures;
    private array $environment = [];

    protected function setUp(): void
    {
        foreach ([
            'PLAYWRIGHT_INTERACTIVE_URL' => 'http://127.0.0.1:1',
            'PLAYWRIGHT_WORKER_TOKEN' => \StudioReviewNoVncWorkerFixture::TOKEN,
            'PLAYWRIGHT_GATEWAY_TOKEN' => \StudioReviewNoVncWorkerFixture::GATEWAY_TOKEN,
        ] as $key => $value) {
            $this->environment[$key] = [getenv($key), $_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            putenv($key.'='.$value); $_SERVER[$key] = $_ENV[$key] = $value;
        }
        parent::setUp();
        \StudioReviewNoVncWorkerFixture::leaseSchema($this->entityManager->getConnection());
        $this->worker = new \StudioReviewNoVncWorkerFixture();
        self::getContainer()->set(HttpClientInterface::class, $this->worker->client());
        $this->session = $this->newSession();
        $this->fixtures = \StudioReviewNoVncWorkerFixture::seed($this->entityManager, self::getContainer()->get(EvidenceStorageInterface::class));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->environment as $key => [$value, $server, $env]) {
            $value === false ? putenv($key) : putenv($key.'='.$value);
            if ($server === null) unset($_SERVER[$key]); else $_SERVER[$key] = $server;
            if ($env === null) unset($_ENV[$key]); else $_ENV[$key] = $env;
        }
    }

    public function testReviewAndDedicatedGetNeverStartOrAcquireAndNoImageUsesThePanel(): void
    {
        $before = $this->snapshot();
        $lease = $this->lease();
        foreach (['noImage', 'missing', 'queued', 'failed'] as $name) {
            $response = $this->request($this->fixtures[$name]['reviewPath']);
            self::assertSame(200, $response->getStatusCode(), $response->getContent());
            $xpath = $this->xpath($response->getContent());
            self::assertSame(1, $xpath->query('//*[@data-review-novnc and @data-finding-id="'.$this->id($name).'"]')->length, $name);
            self::assertSame(1, $xpath->query('//a[starts-with(@href, "'.$this->path($name).'")]')->length, $name.' permanent entry');
            self::assertSame(200, $this->request($this->path($name))->getStatusCode());
            self::assertSame('available', $this->panelStatus($name, self::A)['state']);
        }
        foreach (['normal', 'olderReadable'] as $name) {
            $response = $this->request($this->fixtures[$name]['reviewPath']);
            $xpath = $this->xpath($response->getContent());
            self::assertGreaterThan(0, $xpath->query('//*[@data-review-image]')->length, $name.' keeps its readable image');
            self::assertSame(0, $xpath->query('//*[@data-review-novnc]')->length);
            self::assertSame(1, $xpath->query('//a[starts-with(@href, "'.$this->path($name).'")]')->length);
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame($lease, $this->lease());
        self::assertSame([], $this->worker->state['calls'], 'GET/list/status must not probe or start workers');
    }

    #[DataProvider('manualCases')]
    public function testExplicitManualStartDoesNotRequireProtectionOrAStoredImage(string $name): void
    {
        $before = $this->snapshot();
        $started = $this->post($name, 'lease/acquire', self::A, 0);
        $state = $this->json($started, 200);
        self::assertSame('own_active', $state['state']);
        self::assertGreaterThan(0, $state['revision']);
        self::assertSame(1, $this->worker->state['starts']);
        self::assertSame($this->fixtures[$name]['url'], $this->worker->state['session']['initialUrl']);
        self::assertSame($before, $this->snapshot(), 'Starting never creates image evidence or assessment');
        $worker = $this->worker->state['session'];
        self::assertTrue($this->panelStatus($name, self::A)['reconnect']);
        self::assertSame(1, $this->worker->state['starts']);
        self::assertSame($worker['id'], $this->worker->state['session']['id']);
        self::assertSame($worker['expiresAt'], $this->worker->state['session']['expiresAt']);
    }

    public static function manualCases(): iterable
    {
        foreach (['noImage', 'normal', 'missing', 'queued', 'failed', 'olderReadable'] as $name) yield $name => [$name];
    }

    public function testSameCookieTwoTabsRequireExplicitTakeoverAndOldControlIsRevoked(): void
    {
        $first = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $worker = $this->worker->state['session'];
        $busy = $this->panelStatus('noImage', self::B);
        self::assertSame('busy', $busy['state']);
        self::assertTrue($busy['canTakeover']);
        self::assertFalse($busy['reconnect']);
        self::assertFalse($this->panelStatus('noImage')['reconnect'], 'A cookie without a tab never reconnects');
        $grant = $this->authorize('noImage', self::A, null);
        self::assertSame('allow', $grant['decision']);
        self::assertSame($first['revision'], $grant['generation']);
        self::assertGreaterThan(0, $grant['grantMs']);
        self::assertLessThanOrEqual(1000, $grant['grantMs']);
        self::assertSame('deny', $this->authorize('noImage', self::B, null)['decision']);
        $next = $this->json($this->post('noImage', 'lease/takeover', self::B, $first['revision']), 200);
        self::assertSame('own_active', $next['state']);
        self::assertGreaterThan($first['revision'], $next['revision']);
        self::assertSame($worker['id'], $this->worker->state['session']['id']);
        self::assertSame($worker['finalUrl'], $this->worker->state['session']['finalUrl']);
        self::assertSame($worker['expiresAt'], $this->worker->state['session']['expiresAt']);
        self::assertSame(1, $this->worker->state['starts']);
        self::assertSame('deny', $this->authorize('noImage', self::A, $first['revision'])['decision']);
        self::assertSame('allow', $this->authorize('noImage', self::B, null)['decision']);
        $calls = $this->worker->state['calls']; $before = $this->snapshot();
        foreach (['save', 'lease/release', 'lease/takeover'] as $operation) {
            self::assertSame(409, $this->post('noImage', $operation, self::A, $first['revision'])->getStatusCode());
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame($calls, $this->worker->state['calls'], 'Stale owner must neither capture nor cleanup-close the retained context');
        self::assertSame('own_active', $this->panelStatus('noImage', self::B)['state']);
    }

    public function testDifferentCookieIsBusyAndDifferentFindingNeedsConfirmedEndAndStart(): void
    {
        $first = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $worker = $this->worker->state['session'];
        $this->session = $this->newSession();
        self::assertSame('busy', $this->panelStatus('noImage', self::B)['state']);
        self::assertSame('deny', $this->authorize('noImage', self::A, null)['decision']);
        $other = $this->panelStatus('other', self::B);
        self::assertFalse($other['canTakeover']); self::assertTrue($other['requiresEndStart']);
        $calls = $this->mutations();
        self::assertSame(409, $this->post('other', 'lease/acquire', self::B, $first['revision'])->getStatusCode());
        self::assertSame(409, $this->post('other', 'lease/takeover', self::B, $first['revision'])->getStatusCode());
        self::assertSame(409, $this->post('other', 'lease/end-and-start', self::B, $first['revision'])->getStatusCode());
        self::assertSame($calls, $this->mutations());
        $next = $this->json($this->post('other', 'lease/end-and-start', self::B, $first['revision'], ['confirm' => '1']), 200);
        self::assertSame('own_active', $next['state']);
        self::assertSame(2, $this->worker->state['starts']);
        self::assertNotSame($worker['id'], $this->worker->state['session']['id']);
        self::assertSame($this->fixtures['other']['url'], $this->worker->state['session']['initialUrl']);
    }

    public function testTakeoverDuringCaptureDeniesOldSaveWithoutClosingNewOwner(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $before = $this->snapshot();
        $this->worker->afterCapture = function () use ($started): void {
            $next = $this->json($this->post('noImage', 'lease/takeover', self::B, $started['revision']), 200);
            self::assertSame('own_active', $next['state']);
        };
        self::assertSame(409, $this->post('noImage', 'save', self::A, $started['revision'])->getStatusCode());
        self::assertSame($before, $this->snapshot());
        self::assertNotContains('close', $this->operations());
        self::assertTrue($this->panelStatus('noImage', self::B)['captureLocked']);
        self::assertSame('own_active', $this->panelStatus('noImage', self::B)['state']);
        self::assertSame([], array_values(array_diff(self::getContainer()->get(EvidenceStorageInterface::class)->listPaths(), $this->sourcePaths())));
    }

    public function testFailedPersistenceTransfersImmutableCaptureAndKeepsAssessmentBasisExplicit(): void
    {
        $finding = $this->entityManager->find(Finding::class, $this->id('normal'));
        self::getContainer()->get(FindingService::class)->assess($finding, 'confirmed', evidenceId: $this->fixtures['normal']['evidenceId']);
        $before = $this->snapshot();
        $started = $this->json($this->post('normal', 'lease/acquire', self::A, 0), 200);
        $db = $this->entityManager->getConnection();
        $db->executeStatement("CREATE TRIGGER p8_reject_capture BEFORE INSERT ON evidence BEGIN SELECT RAISE(ABORT, 'P8 deliberate evidence failure'); END");
        try {
            self::assertSame(502, $this->post('normal', 'save', self::A, $started['revision'])->getStatusCode());
        } finally {
            $db->executeStatement('DROP TRIGGER p8_reject_capture');
        }
        self::assertSame($before, $this->snapshot());
        $proof = $this->worker->state['capture'];
        self::assertNotNull($proof);
        self::assertTrue($this->panelStatus('normal', self::A)['captureLocked']);
        self::assertNotContains('close', $this->operations());
        $next = $this->json($this->post('normal', 'lease/takeover', self::B, $started['revision']), 200);
        self::assertTrue($this->panelStatus('normal', self::B)['captureLocked']);
        self::assertSame($proof, $this->worker->state['capture'], 'Takeover transfers, never relabels, the immutable capture');
        $calls = $this->worker->state['calls'];
        self::assertSame(409, $this->post('normal', 'save', self::A, $started['revision'])->getStatusCode());
        self::assertSame(409, $this->post('normal', 'lease/release', self::A, $started['revision'])->getStatusCode());
        self::assertSame($calls, $this->worker->state['calls']);
        $saved = $this->post('normal', 'save', self::B, $next['revision']);
        self::assertSame(200, $saved->getStatusCode(), $saved->getContent());
        $after = $this->snapshot();
        foreach (['finding', 'finding_assessment', 'retest_run', 'finding_review_acknowledgement', 'finding_assessment_reset'] as $table) self::assertSame($before[$table], $after[$table], $table);
        self::assertCount(count($before['evidence']) + 1, $after['evidence']);
        self::assertCount(count($before['screenshot_job']) + 1, $after['screenshot_job']);
        $job = array_values(array_filter($after['screenshot_job'], static fn (array $row): bool => $row['screenshot_path'] !== null && !in_array($row['id'], array_column($before['screenshot_job'], 'id'), true)))[0];
        $metadata = json_decode($job['capture_metadata'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('vnc', $metadata['inputMethod']);
        foreach (['manualActionCount', 'manualInteraction'] as $key) {
            self::assertArrayHasKey($key, $metadata); self::assertNull($metadata[$key], $key.' is unknown on the separate VNC input path');
        }
        self::assertSame($proof['metadata']['captureId'], $metadata['captureId']);
        self::assertSame($started['revision'], $metadata['captureGeneration']);
        self::assertSame($proof['finalUrl'], $metadata['finalUrl']);
        self::assertSame((new \DateTimeImmutable($proof['capturedAt']))->getTimestamp(), (new \DateTimeImmutable($job['captured_at']))->getTimestamp());
        self::assertNull($metadata['challengeCleared'], 'Saving unknown observations never infers clearance');
        self::assertSame($this->fixtures['normal']['evidenceId'], $after['finding_assessment'][0]['evidence_id']);
        self::assertSame('available', $this->panelStatus('normal', self::B)['state']);
        self::assertNotSame(200, $this->post('normal', 'save', self::B, $next['revision'])->getStatusCode());
        self::assertSame($after, $this->snapshot(), 'Save replay must not duplicate evidence');
    }

    public function testCsrfPolicyAndUrlRejectionsNeverReachWorker(): void
    {
        $before = $this->snapshot();
        foreach (['lease/acquire', 'lease/takeover', 'lease/release', 'lease/end-and-start', 'save'] as $operation) {
            self::assertSame(403, $this->post('noImage', $operation, self::A, 0, ['_token' => 'invalid'])->getStatusCode());
        }
        self::assertSame(409, $this->post('blocked', 'lease/acquire', self::A, 0)->getStatusCode());
        self::assertSame(409, $this->post('invalid', 'lease/acquire', self::A, 0)->getStatusCode());
        self::assertSame([], $this->worker->state['calls']);
        self::assertSame($before, $this->snapshot());
    }

    public function testSourceLessSaveReturnsToReviewWithoutInventingTriggerAssessmentOrBasis(): void
    {
        $before = $this->snapshot();
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        // The ordinary form contract is 303; JSON clients use the same save API.
        $saved = $this->request($this->path('noImage').'/save', 'POST', [
            '_token' => $this->token('noImage'), 'channelId' => self::A, 'expectedRevision' => (string) $started['revision'],
        ]);
        self::assertSame(303, $saved->getStatusCode(), $saved->getContent());
        $after = $this->snapshot();
        foreach (['finding', 'finding_assessment', 'retest_run', 'finding_review_acknowledgement', 'finding_assessment_reset'] as $table) self::assertSame($before[$table], $after[$table], $table);
        self::assertCount(count($before['evidence']) + 1, $after['evidence']);
        $jobs = array_values(array_filter($after['screenshot_job'], fn (array $row): bool => $row['finding_id'] === $this->id('noImage')));
        self::assertCount(1, $jobs);
        $metadata = json_decode($jobs[0]['capture_metadata'], true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($metadata['triggerJobId']); self::assertNull($metadata['triggerEvidenceId']);
        self::assertSame('vnc', $metadata['inputMethod']);
        self::assertNull($metadata['manualInteraction']); self::assertNull($metadata['manualActionCount']);
        $review = $this->request($saved->headers->get('Location'));
        self::assertSame(200, $review->getStatusCode());
        $xpath = $this->xpath($review->getContent());
        self::assertSame('', $xpath->evaluate('string(//*[@name="evidence_id"]/@value)'));
        self::assertSame(0, $xpath->query('//select[@name="evidence_id"]/option[@selected and @value!=""]')->length);
        self::assertSame('available', $this->panelStatus('noImage', self::A)['state']);
    }

    public function testCommittedSaveReservesClosingBeforeRpcAndPendingCloseCanBeReleased(): void
    {
        $before = $this->snapshot();
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $barrierReached = false;
        $barrierFailure = null;
        $this->worker->beforeClose = function () use ($before, $started, &$barrierReached, &$barrierFailure): void {
            $barrierReached = true;
            // The coordinator catches close failures; rethrow any assertion in
            // the outer test so that failure handling cannot hide a bad oracle.
            try {
                $closing = $this->lease();
                self::assertSame('closing', $closing['state']);
                self::assertSame($started['revision'], $closing['generation']);
                self::assertFalse($this->entityManager->getConnection()->isTransactionActive(), 'No RPC while holding the SQLite persistence lock');
                $committed = $this->snapshot();
                self::assertCount(count($before['evidence']) + 1, $committed['evidence']);
                self::assertCount(count($before['screenshot_job']) + 1, $committed['screenshot_job']);
                $calls = $this->mutations();
                self::assertSame(409, $this->post('noImage', 'lease/takeover', self::B, $started['revision'])->getStatusCode());
                self::assertSame(409, $this->post('other', 'lease/end-and-start', self::B, $started['revision'], ['confirm' => '1'])->getStatusCode());
                self::assertSame($closing, $this->lease(), 'No new owner may slip between commit and close');
                self::assertSame($committed, $this->snapshot());
                self::assertSame($calls, $this->mutations());
            } catch (\Throwable $error) { $barrierFailure = $error; }
            throw new \RuntimeException('P8 bounded close failure after commit', 503);
        };
        $saved = $this->json($this->post('noImage', 'save', self::A, $started['revision']), 200);
        if ($barrierFailure !== null) throw $barrierFailure;
        self::assertTrue($barrierReached); self::assertTrue($saved['saved']); self::assertFalse($saved['contextClosed']);
        $committed = $this->snapshot();
        $pending = $this->panelStatus('noImage', self::A);
        self::assertSame('closing', $pending['state']); self::assertTrue($pending['canRelease']);
        self::assertFalse($this->panelStatus('noImage', self::B)['canRelease']);
        $released = $this->json($this->post('noImage', 'lease/release', self::A, $started['revision']), 200);
        self::assertSame('available', $released['state']);
        self::assertNull($this->worker->state['session']);
        self::assertSame(1, $this->worker->state['starts']);
        self::assertSame($committed, $this->snapshot(), 'Close retry never duplicates or rolls back committed proof');
    }

    private function newSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setId(bin2hex(random_bytes(16))); $session->start();
        return $session;
    }

    private function id(string $name): string { return $this->fixtures[$name]['id']; }
    private function path(string $name): string { return '/review/'.$this->id($name).'/interactive'; }
    private function lease(): array { return self::getContainer()->get(InteractiveBrowserLeaseService::class)->current(); }
    private function operations(): array { return array_map(static fn (array $call): string => basename($call['path']), $this->worker->state['calls']); }
    private function mutations(): array { return array_values(array_filter($this->worker->state['calls'], static fn (array $call): bool => $call['method'] !== 'GET')); }
    private function sourcePaths(): array { return array_values(array_filter(array_column($this->snapshot()['evidence'], 'file_path'))); }

    private function token(string $name): string
    {
        $request = Request::create('/review'); $request->setSession($this->session);
        $stack = self::getContainer()->get(RequestStack::class); $stack->push($request);
        try { return self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('review_interactive_'.$this->id($name))->getValue(); }
        finally { $stack->pop(); }
    }

    private function post(string $name, string $operation, string $channel, int $revision, array $extra = []): Response
    {
        return $this->request($this->path($name).'/'.$operation, 'POST', $extra + [
            '_token' => $this->token($name), 'channelId' => $channel, 'expectedRevision' => (string) $revision,
        ], ['HTTP_ACCEPT' => 'application/json']);
    }

    private function panelStatus(string $name, ?string $channel = null): array
    {
        return $this->json($this->request($this->path($name).'/status', 'GET', [], $channel === null ? [] : ['HTTP_X_LBB_TAB' => $channel]), 200);
    }

    private function authorize(string $name, string $channel, ?int $generation): array
    {
        $request = Request::create('/internal/interactive-gateway/authorize', 'POST', [], [$this->session->getName() => $this->session->getId()], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.\StudioReviewNoVncWorkerFixture::GATEWAY_TOKEN, 'CONTENT_TYPE' => 'application/json',
        ], json_encode(['findingId' => $this->id($name), 'channelId' => $channel, 'generation' => $generation], JSON_THROW_ON_ERROR));
        $request->setSession($this->session);
        $response = self::$kernel->handle($request); self::$kernel->terminate($request, $response);
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function request(string $path, string $method = 'GET', array $parameters = [], array $server = []): Response
    {
        $request = Request::create($path, $method, $parameters, [], [], $server); $request->setSession($this->session);
        $response = self::$kernel->handle($request); self::$kernel->terminate($request, $response);
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''), $path);
        foreach ([\StudioReviewNoVncWorkerFixture::TOKEN, \StudioReviewNoVncWorkerFixture::GATEWAY_TOKEN] as $secret) self::assertStringNotContainsString($secret, $response->getContent());
        if ($this->worker->state['session'] !== null) self::assertStringNotContainsString($this->worker->state['session']['id'], $response->getContent());
        return $response;
    }

    private function json(Response $response, int $status): array
    {
        self::assertSame($status, $response->getStatusCode(), $response->getContent());
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['finding', 'finding_assessment', 'finding_review_acknowledgement', 'finding_assessment_reset', 'retest_run', 'evidence', 'screenshot_job'] as $table) {
            $result[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid');
        }
        return $result;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument(); @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($document);
    }
}
