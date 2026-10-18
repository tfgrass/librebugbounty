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
        foreach (['lease/acquire', 'lease/takeover', 'lease/release', 'lease/end-and-start', 'save', 'dialog/arm', 'dialog/retry', 'dialog/dismiss'] as $operation) {
            self::assertSame(403, $this->post('noImage', $operation, self::A, 0, ['_token' => 'invalid'])->getStatusCode());
        }
        self::assertSame(409, $this->post('blocked', 'lease/acquire', self::A, 0)->getStatusCode());
        self::assertSame(409, $this->post('invalid', 'lease/acquire', self::A, 0)->getStatusCode());
        self::assertSame([], $this->worker->state['calls']);
        self::assertSame($before, $this->snapshot());
    }

    public function testSaveSelectionIsRequiredAndValidatedBeforeCapture(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $before = $this->snapshot(); $mutations = $this->mutations();
        foreach ([[], ['expectedCandidateId' => []], ['expectedCandidateId' => false], ['expectedCandidateId' => 'page'],
            ['expectedCandidateId' => str_repeat('A', 48)], ['expectedCandidateId' => str_repeat('a', 48)."\n"]] as $extra) {
            $response = $this->request($this->path('noImage').'/save', 'POST', $extra + [
                '_token' => $this->token('noImage'), 'channelId' => self::A, 'expectedRevision' => (string) $started['revision'],
            ], ['HTTP_ACCEPT' => 'application/json']);
            self::assertSame(400, $response->getStatusCode(), $response->getContent());
        }
        self::assertSame($mutations, $this->mutations());
        self::assertNull($this->worker->state['capture']);
        self::assertSame($before, $this->snapshot());
    }

    public function testReplacedCandidateCannotBeSavedThroughAnEarlierConfirmation(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $this->retainDialog();
        $first = str_repeat('a', 48); $second = str_repeat('b', 48);
        $this->worker->state['session']['retainedDialog']['id'] = $first;
        self::assertSame($first, $this->panelStatus('noImage', self::A)['retainedDialog']['id']);
        // The next poll has not yet exposed the replacement to the user.
        $this->worker->state['session']['retainedDialog']['id'] = $second;
        $before = $this->snapshot();
        foreach ([$first, ''] as $selected) {
            $response = $this->json($this->post('noImage', 'save', self::A, $started['revision'], ['expectedCandidateId' => $selected]), 409);
            self::assertSame($second, $response['retainedDialog']['id']);
            self::assertFalse($response['captureFinalized']);
            self::assertSame('stale_revision_or_busy', $response['reason']);
            self::assertNull($this->worker->state['capture']);
            self::assertSame($before, $this->snapshot());
        }
        self::assertNotContains('close', $this->operations());
        $this->json($this->post('noImage', 'save', self::A, $started['revision'], ['expectedCandidateId' => $second]), 200);
        $calls = array_values(array_filter($this->worker->state['calls'], static fn (array $call): bool => str_ends_with($call['path'], '/capture')));
        self::assertSame([$first, null, $second], array_column(array_column($calls, 'body'), 'expectedCandidateId'));
        $job = $this->entityManager->getConnection()->fetchAssociative('SELECT capture_metadata FROM screenshot_job WHERE finding_id = ?', [$this->id('noImage')]);
        self::assertSame($second, json_decode($job['capture_metadata'], true)['dialogCandidateId']);
    }

    public function testFinalizedRetryRejectsAnotherSelectionAndKeepsTheSameProof(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $this->retainDialog();
        $candidate = str_repeat('b', 48);
        $this->worker->state['session']['retainedDialog']['id'] = $candidate;
        $db = $this->entityManager->getConnection();
        $db->executeStatement("CREATE TRIGGER selection_reject_capture BEFORE INSERT ON evidence BEGIN SELECT RAISE(ABORT, 'Deliberate evidence failure'); END");
        try {
            self::assertSame(502, $this->post('noImage', 'save', self::A, $started['revision'], ['expectedCandidateId' => $candidate])->getStatusCode());
        } finally { $db->executeStatement('DROP TRIGGER selection_reject_capture'); }
        $proof = $this->worker->state['capture']; $before = $this->snapshot();
        self::assertNotNull($proof);
        foreach (['', str_repeat('a', 48)] as $selected) {
            self::assertSame(409, $this->post('noImage', 'save', self::A, $started['revision'], ['expectedCandidateId' => $selected])->getStatusCode());
            self::assertSame($proof, $this->worker->state['capture']);
            self::assertSame($before, $this->snapshot());
        }
        self::assertNotContains('close', $this->operations());
        $this->json($this->post('noImage', 'save', self::A, $started['revision'], ['expectedCandidateId' => $candidate]), 200);
        self::assertSame($proof, $this->worker->state['capture']);
    }

    public function testSourceLessSaveReturnsToReviewWithoutInventingTriggerAssessmentOrBasis(): void
    {
        $before = $this->snapshot();
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        // The ordinary form contract is 303; JSON clients use the same save API.
        $saved = $this->request($this->path('noImage').'/save', 'POST', [
            '_token' => $this->token('noImage'), 'channelId' => self::A, 'expectedRevision' => (string) $started['revision'],
            'expectedCandidateId' => '',
            'expectedPocRevision' => '0', 'pocMode' => 'keep',
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

    public function testPocSelectionContractRejectsMissingMalformedAndStaleInputBeforeCapture(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $before = $this->snapshot(); $calls = $this->mutations();
        $base = ['_token' => $this->token('noImage'), 'channelId' => self::A, 'expectedRevision' => (string) $started['revision'], 'expectedCandidateId' => ''];
        foreach ([
            [], ['pocMode' => 'keep'], ['expectedPocRevision' => '0'],
            ['expectedPocRevision' => '-1', 'pocMode' => 'keep'], ['expectedPocRevision' => ['0'], 'pocMode' => 'keep'],
            ['expectedPocRevision' => '0', 'pocMode' => 'unknown'], ['expectedPocRevision' => '0', 'pocMode' => ['keep']],
            ['expectedPocRevision' => '0', 'pocMode' => 'observed'], ['expectedPocRevision' => '0', 'pocMode' => 'manual', 'pocUrl' => []],
        ] as $selection) {
            $response = $this->request($this->path('noImage').'/save', 'POST', $selection + $base, ['HTTP_ACCEPT' => 'application/json']);
            self::assertSame(400, $response->getStatusCode(), $response->getContent());
        }
        self::assertSame(409, $this->post('noImage', 'save', self::A, $started['revision'], ['expectedPocRevision' => '1'])->getStatusCode());
        foreach (['http://elsewhere.invalid/test', 'http://user:pass@127.0.0.1/test', 'javascript:alert(1)', "http://127.0.0.1/test\n"] as $url) {
            self::assertSame(400, $this->post('noImage', 'save', self::A, $started['revision'], ['pocMode' => 'manual', 'pocUrl' => $url])->getStatusCode());
        }
        self::assertSame($calls, $this->mutations()); self::assertNull($this->worker->state['capture']);
        self::assertSame($before, $this->snapshot());
    }

    public function testObservedPocPreservesExactEncodingOriginalHistoryAndScreenshotSourceAndStartsNextSessionAtNewUrl(): void
    {
        $original = $this->fixtures['noImage']['url'];
        $selected = 'http://127.0.0.1/echo?z=%252f&x=%3Cscript%3Ealert(1)%3C%2Fscript%3E&x=%2f#%25';
        $source = 'http://127.0.0.1/after-dialog?redirected=1';
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $this->worker->state['session']['urlCandidates'][] = \StudioReviewNoVncWorkerFixture::urlCandidate($selected, ['navigation', 'dialog']);
        $this->worker->state['session']['finalUrl'] = $source;
        $status = $this->panelStatus('noImage', self::A);
        self::assertSame($selected, $status['urlCandidates'][2]['url']);
        self::assertSame($original, $status['pocUrl']); self::assertSame(0, $status['pocRevision']);
        $this->json($this->post('noImage', 'save', self::A, $started['revision'], ['pocMode' => 'observed', 'pocUrl' => $selected]), 200);
        $db = $this->entityManager->getConnection();
        $finding = $db->fetchAssociative('SELECT url, poc_url, poc_revision FROM finding WHERE id = ?', [$this->id('noImage')]);
        self::assertSame(['url' => $original, 'poc_url' => $selected, 'poc_revision' => 1], $finding);
        $history = $db->fetchAllAssociative('SELECT * FROM finding_poc_revision WHERE finding_id = ?', [$this->id('noImage')]);
        self::assertCount(1, $history); self::assertSame($original, $history[0]['previous_url']); self::assertSame($selected, $history[0]['url']);
        self::assertSame('observed', $history[0]['source']); self::assertSame($source, $history[0]['source_url']);
        $job = $db->fetchAssociative('SELECT * FROM screenshot_job WHERE id = ?', [$history[0]['screenshot_job_id']]);
        $evidence = $db->fetchAssociative('SELECT * FROM evidence WHERE id = ?', [$history[0]['evidence_id']]);
        $metadata = json_decode($job['capture_metadata'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($original, $job['url'], 'The saved source job retains the session entry URL');
        self::assertSame($source, $metadata['finalUrl']); self::assertSame($selected, $metadata['pocUrl']);
        self::assertSame($evidence['sha256'], $history[0]['sha256']); self::assertSame($job['screenshot_path'], $evidence['file_path']);
        self::assertSame($job['captured_at'], $history[0]['captured_at']);
        $reopened = $this->json($this->post('noImage', 'lease/acquire', self::A, $this->lease()['generation']), 200);
        self::assertSame('own_active', $reopened['state']); self::assertSame($selected, $this->worker->state['session']['initialUrl']);
        self::assertSame($selected, $this->lease()['binding']['start_url']); self::assertSame(1, $this->lease()['binding']['poc_revision']);
    }

    public function testObservedPocMustBelongToImmutableCaptureAndManualRecoveryCanReuseThatProof(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $manual = 'http://127.0.0.1/echo?chosen=%252F'; $before = $this->snapshot();
        $response = $this->post('noImage', 'save', self::A, $started['revision'], ['pocMode' => 'observed', 'pocUrl' => $manual]);
        self::assertSame(409, $response->getStatusCode(), $response->getContent());
        self::assertSame($before, $this->snapshot()); $proof = $this->worker->state['capture']; self::assertNotNull($proof);
        $this->worker->state['session']['urlCandidates'][] = \StudioReviewNoVncWorkerFixture::urlCandidate($manual);
        self::assertSame(409, $this->post('noImage', 'save', self::A, $started['revision'], ['pocMode' => 'observed', 'pocUrl' => $manual])->getStatusCode());
        self::assertSame($before, $this->snapshot()); self::assertSame($proof, $this->worker->state['capture']);
        $this->json($this->post('noImage', 'save', self::A, $started['revision'], ['pocMode' => 'manual', 'pocUrl' => $manual]), 200);
        self::assertSame($proof, $this->worker->state['capture']);
        $history = $this->entityManager->getConnection()->fetchAllAssociative('SELECT url, source FROM finding_poc_revision WHERE finding_id = ?', [$this->id('noImage')]);
        self::assertSame([['url' => $manual, 'source' => 'manual']], $history);
    }

    public function testRetainedDialogSuppliesItsOwnObservedUrlsAndSourceAfterTheBrowserRedirects(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $this->retainDialog(); $candidate = str_repeat('a', 48);
        $selected = 'http://127.0.0.1/earlier?x=%253C';
        $dialogUrl = 'http://127.0.0.1/dialog?x=%3C';
        $redirected = 'http://127.0.0.1/after-dialog';
        $this->worker->state['session']['retainedDialog'] = array_replace($this->worker->state['session']['retainedDialog'], [
            'id' => $candidate, 'pageUrl' => $dialogUrl,
            'urlCandidates' => [\StudioReviewNoVncWorkerFixture::urlCandidate($selected), \StudioReviewNoVncWorkerFixture::urlCandidate($dialogUrl, ['dialog'])],
        ]);
        $this->worker->state['session']['finalUrl'] = $redirected;
        $this->worker->state['session']['urlCandidates'] = [\StudioReviewNoVncWorkerFixture::urlCandidate($redirected, ['current'])];
        $status = $this->panelStatus('noImage', self::A);
        self::assertSame($redirected, $status['urlCandidates'][0]['url']);
        self::assertSame($selected, $status['retainedDialog']['urlCandidates'][0]['url']);
        $this->json($this->post('noImage', 'save', self::A, $started['revision'], [
            'expectedCandidateId' => $candidate, 'pocMode' => 'observed', 'pocUrl' => $selected,
        ]), 200);
        $history = $this->entityManager->getConnection()->fetchAssociative('SELECT url, source_url FROM finding_poc_revision WHERE finding_id = ?', [$this->id('noImage')]);
        self::assertSame(['url' => $selected, 'source_url' => $dialogUrl], $history);
        self::assertSame($candidate, $this->worker->state['capture']['metadata']['dialogCandidateId']);
        self::assertSame($dialogUrl, $this->worker->state['capture']['finalUrl']);
    }

    public function testConcurrentPocRevisionDuringCaptureRejectsTheWholeOldSave(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $db = $this->entityManager->getConnection(); $before = $this->snapshot();
        $files = self::getContainer()->get(EvidenceStorageInterface::class)->listPaths();
        $this->worker->afterCapture = function () use ($db): void {
            $db->update('finding', ['poc_url' => 'http://127.0.0.1/changed-elsewhere', 'poc_revision' => 1], ['id' => $this->id('noImage')]);
        };
        self::assertSame(409, $this->post('noImage', 'save', self::A, $started['revision'], ['pocMode' => 'manual', 'pocUrl' => 'http://127.0.0.1/old-tab'])->getStatusCode());
        $after = $this->snapshot();
        foreach (['evidence', 'screenshot_job', 'finding_poc_revision'] as $table) self::assertSame($before[$table], $after[$table]);
        self::assertSame('http://127.0.0.1/changed-elsewhere', $db->fetchOne('SELECT poc_url FROM finding WHERE id = ?', [$this->id('noImage')]));
        self::assertNotContains('close', $this->operations());
        self::assertSame($files, self::getContainer()->get(EvidenceStorageInterface::class)->listPaths());
    }

    #[DataProvider('keepPocCases')]
    public function testKeepingOrSelectingTheCurrentPocNeverCreatesARevision(string $mode): void
    {
        $original = $this->fixtures['noImage']['url'];
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $this->worker->state['session']['finalUrl'] = 'http://127.0.0.1/changed?actual=1';
        $this->json($this->post('noImage', 'save', self::A, $started['revision'], ['pocMode' => $mode, 'pocUrl' => $original]), 200);
        $db = $this->entityManager->getConnection();
        self::assertSame($original, $db->fetchOne('SELECT COALESCE(poc_url, url) FROM finding WHERE id = ?', [$this->id('noImage')]));
        self::assertSame(0, (int) $db->fetchOne('SELECT poc_revision FROM finding WHERE id = ?', [$this->id('noImage')]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM finding_poc_revision'));
    }

    public static function keepPocCases(): iterable
    {
        foreach (['keep', 'observed', 'manual'] as $mode) yield $mode => [$mode];
    }

    #[DataProvider('pocFailureCases')]
    public function testPocAndEvidenceCommitTogetherAndRetryCreatesExactlyOneRevision(string $failure): void
    {
        $db = $this->entityManager->getConnection(); $local = self::getContainer()->get(EvidenceStorageInterface::class);
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $selected = 'http://127.0.0.1/echo?new=%3Csvg%3E';
        $this->worker->state['session']['urlCandidates'][] = \StudioReviewNoVncWorkerFixture::urlCandidate($selected);
        $before = $this->snapshot(); $files = $local->listPaths();
        $storageBlocker = APP_TEST_ROOT.'/artifacts/'.$this->id('noImage');
        if ($failure === 'storage') {
            // A real filesystem failure: the per-finding directory cannot be
            // created while a file occupies its path. No permission assumptions.
            file_put_contents($storageBlocker, 'deliberate capture outage');
        } else {
            $table = $failure === 'history' ? 'finding_poc_revision' : 'evidence';
            $db->executeStatement("CREATE TRIGGER reject_poc_capture BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT, 'Temporary PoC capture database failure'); END");
        }
        try {
            self::assertSame(502, $this->post('noImage', 'save', self::A, $started['revision'], ['pocMode' => 'observed', 'pocUrl' => $selected])->getStatusCode());
        } finally {
            if ($failure === 'storage') unlink($storageBlocker);
            else $db->executeStatement('DROP TRIGGER reject_poc_capture');
        }
        self::assertSame($before, $this->snapshot()); self::assertSame($files, $local->listPaths());
        $proof = $this->worker->state['capture']; self::assertNotNull($proof);
        $this->json($this->post('noImage', 'save', self::A, $started['revision'], ['pocMode' => 'observed', 'pocUrl' => $selected]), 200);
        self::assertSame($proof, $this->worker->state['capture']);
        self::assertSame(1, (int) $db->fetchOne('SELECT poc_revision FROM finding WHERE id = ?', [$this->id('noImage')]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM finding_poc_revision WHERE finding_id = ?', [$this->id('noImage')]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM evidence WHERE finding_id = ?', [$this->id('noImage')]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM screenshot_job WHERE finding_id = ?', [$this->id('noImage')]));
        $after = $this->snapshot();
        self::assertSame(409, $this->post('noImage', 'save', self::A, $started['revision'], ['pocMode' => 'observed', 'pocUrl' => $selected])->getStatusCode());
        self::assertSame($after, $this->snapshot());
    }

    public static function pocFailureCases(): iterable
    {
        foreach (['storage', 'evidence', 'history'] as $failure) yield $failure => [$failure];
    }

    private function retainDialog(): array
    {
        $retained = ['capturedAt' => '2026-10-17T12:01:01.000Z', 'detectedAt' => '2026-10-17T12:01:00.000Z',
            'pageUrl' => $this->fixtures['noImage']['url'], 'type' => 'alert', 'message' => 'first retained proof',
            'urlCandidates' => [], 'urlCandidatesTruncated' => false];
        $this->worker->state['session']['retainedDialog'] = $retained + ['screenshotBase64' => \StudioReviewNoVncWorkerFixture::PNG, 'secret' => 'hidden'];
        $this->worker->state['session']['dialogObservation'] = ['message' => 'later failed observation', 'captureSucceeded' => false];
        return $retained;
    }

    private function preview(int $revision, string $channel = self::A, string $name = 'noImage'): Response
    {
        return $this->request($this->path($name).'/dialog-frame?revision='.$revision, 'GET', [], ['HTTP_X_LBB_TAB' => $channel]);
    }

    public function testRetainedStatusIsOwnerOnlySanitizedAndIndependentAndPreviewIsReadOnly(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $retained = $this->retainDialog();
        $lease = $this->lease(); $before = $this->snapshot(); $mutations = $this->mutations();
        $status = $this->panelStatus('noImage', self::A);
        self::assertSame($retained, $status['retainedDialog']);
        self::assertSame('later failed observation', $status['dialogObservation']['message']);
        self::assertStringNotContainsString(\StudioReviewNoVncWorkerFixture::PNG, json_encode($status));
        $calls = $this->worker->state['calls'];
        foreach ([self::B, null] as $channel) self::assertArrayNotHasKey('retainedDialog', $this->panelStatus('noImage', $channel));
        $original = $this->session; $this->session = $this->newSession();
        self::assertArrayNotHasKey('retainedDialog', $this->panelStatus('noImage', self::A));
        self::assertSame(409, $this->preview($started['revision'])->getStatusCode());
        $this->session = $original;
        self::assertSame($calls, $this->worker->state['calls'], 'Foreign status and preview never call worker');
        $response = $this->preview($started['revision']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame(base64_decode(\StudioReviewNoVncWorkerFixture::PNG, true), $response->getContent());
        $call = end($this->worker->state['calls']);
        self::assertSame('GET', $call['method']); self::assertSame('generation='.$started['revision'], $call['query']);
        self::assertSame($lease, $this->lease()); self::assertSame($before, $this->snapshot());
        self::assertSame($mutations, $this->mutations()); self::assertNull($this->worker->state['capture']);
    }

    public function testPreviewRejectsInvalidInputsAndRightsBeforeAnyRpc(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $this->retainDialog();
        $calls = $this->worker->state['calls'];
        foreach (['', '?revision=', '?revision=-1', '?revision=1.5', '?revision[]=1'] as $query) {
            self::assertSame(400, $this->request($this->path('noImage').'/dialog-frame'.$query, 'GET', [], ['HTTP_X_LBB_TAB' => self::A])->getStatusCode());
        }
        self::assertSame(400, $this->request($this->path('noImage').'/dialog-frame?revision='.$started['revision'])->getStatusCode());
        self::assertSame(409, $this->preview($started['revision'] + 1)->getStatusCode());
        self::assertSame(409, $this->preview($started['revision'], self::B)->getStatusCode());
        self::assertSame(409, $this->preview($started['revision'], self::A, 'other')->getStatusCode());
        self::assertSame($calls, $this->worker->state['calls']);
    }

    #[DataProvider('readRaces')]
    public function testPreviewAndStatusRecheckOwnershipPolicyAndBindingBeforeAndAfterRpc(string $change, bool $during, string $read): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $this->retainDialog();
        $mutate = function () use ($change): void {
            $db = $this->entityManager->getConnection();
            switch ($change) {
                case 'takeover':
                    $leases = self::getContainer()->get(InteractiveBrowserLeaseService::class);
                    $leases->takeover($this->id('noImage'), $this->lease()['owner_hash'], self::B, $this->lease()['generation']);
                    break;
                case 'channel': $db->executeStatement('UPDATE interactive_browser_lease SET channel_id = ?', [self::B]); break;
                case 'owner': $db->executeStatement('UPDATE interactive_browser_lease SET owner_hash = ?', [str_repeat('f', 64)]); break;
                case 'expiry': $db->executeStatement('UPDATE interactive_browser_lease SET expires_at = ?', [time() - 1]); break;
                case 'closing': $db->executeStatement("UPDATE interactive_browser_lease SET state = 'closing'"); break;
                case 'policy': $db->update('finding', ['status' => 'discarded'], ['id' => $this->id('noImage')]); break;
                case 'url': $db->update('finding', ['url' => 'http://127.0.0.1/changed'], ['id' => $this->id('noImage')]); break;
                case 'binding':
                    $binding = $this->lease()['binding']; $binding['start_url_hash'] = 'changed';
                    $db->executeStatement('UPDATE interactive_browser_lease SET binding = ?', [json_encode($binding)]); break;
                case 'worker': $db->executeStatement('UPDATE interactive_browser_lease SET worker_id = ?', [str_repeat('c', 48)]); break;
            }
        };
        if ($during) {
            if ($read === 'preview') $this->worker->afterDialogFrame = $mutate;
            else $this->worker->afterStatus = $mutate;
        } else { $mutate(); }
        $before = $this->snapshot(); $calls = count($this->worker->state['calls']); $mutations = $this->mutations();
        if ($read === 'preview') {
            // A different worker ID is only detectable against the pre-RPC row.
            $response = $this->preview($started['revision']);
            self::assertNotSame(200, $response->getStatusCode());
            self::assertNotSame('image/png', $response->headers->get('Content-Type'));
        } else {
            $status = $this->panelStatus('noImage', self::A);
            self::assertArrayNotHasKey('retainedDialog', $status);
            self::assertArrayNotHasKey('dialogObservation', $status);
        }
        if (!$during && $change !== 'worker') self::assertCount($calls, $this->worker->state['calls'], 'Denied before worker RPC');
        self::assertSame($mutations, $this->mutations());
        // Ignore the intentional finding mutation at the read barrier.
        if (!($during && in_array($change, ['policy', 'url'], true))) self::assertSame($before, $this->snapshot());
        self::assertNull($this->worker->state['capture']); self::assertSame(1, $this->worker->state['starts']);
    }

    public static function readRaces(): iterable
    {
        foreach (['takeover', 'channel', 'owner', 'expiry', 'closing', 'policy', 'url', 'binding', 'worker'] as $change) {
            foreach ([false, true] as $during) {
                foreach (['preview', 'status'] as $read) {
                    if ($change === 'worker' && !$during) continue;
                    yield $change.($during ? ' during ' : ' before ').$read => [$change, $during, $read];
                }
            }
        }
    }

    public function testDialogControlsRetainCandidateAndExposeOnlySanitizedCaptureState(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $retained = $this->retainDialog();
        $candidateId = str_repeat('a', 48);
        $this->worker->state['session']['retainedDialog']['id'] = $candidateId;
        $this->worker->state['session']['dialogCapture']['secret'] = 'hidden';
        $lease = $this->lease(); $before = $this->snapshot();
        $status = $this->panelStatus('noImage', self::A);
        self::assertEquals($retained + ['id' => $candidateId], $status['retainedDialog']);
        self::assertSame(['state' => 'waiting', 'revision' => 0, 'armed' => true, 'pending' => false, 'attemptsRemaining' => 2], $status['dialogCapture']);
        self::assertFalse($status['captureFinalized']);
        self::assertStringNotContainsString('hidden', json_encode($status));
        $armed = $this->json($this->post('noImage', 'dialog/arm', self::A, $started['revision'], ['dialogRevision' => '0']), 200);
        self::assertSame(1, $armed['dialogCapture']['revision']);
        self::assertSame($status['retainedDialog'], $armed['retainedDialog']);
        $calls = array_values(array_filter($this->mutations(), static fn (array $call): bool => basename($call['path']) === 'dialog'));
        self::assertCount(1, $calls);
        self::assertSame(['generation' => $started['revision'], 'action' => 'arm', 'dialogRevision' => 0], $calls[0]['body']);
        foreach ([self::B, null] as $channel) {
            $foreign = $this->panelStatus('noImage', $channel);
            self::assertArrayNotHasKey('dialogCapture', $foreign);
            self::assertArrayNotHasKey('captureFinalized', $foreign);
        }
        self::assertSame($lease, $this->lease()); self::assertSame($before, $this->snapshot());
        self::assertNull($this->worker->state['capture']); self::assertSame(1, $this->worker->state['starts']);
    }

    public function testDialogControlsRejectStaleDialogLeaseChannelAndMalformedInputs(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $before = $this->snapshot(); $mutations = $this->mutations();
        foreach ([[], ['dialogRevision' => '-1'], ['dialogRevision' => '0.5'], ['dialogRevision' => ['0']]] as $body) {
            self::assertSame(400, $this->post('noImage', 'dialog/arm', self::A, $started['revision'], $body)->getStatusCode());
        }
        foreach ([self::B, 'invalid'] as $channel) {
            self::assertSame($channel === self::B ? 409 : 400, $this->post('noImage', 'dialog/arm', $channel, $started['revision'], ['dialogRevision' => 0])->getStatusCode());
        }
        self::assertSame(400, $this->post('noImage', 'dialog/arm', self::A, $started['revision'], ['dialogRevision' => 0, 'expectedRevision' => ''])->getStatusCode());
        self::assertSame(409, $this->post('noImage', 'dialog/arm', self::A, $started['revision'] + 1, ['dialogRevision' => 0])->getStatusCode());
        self::assertSame($mutations, $this->mutations(), 'Invalid owner or input cannot mutate worker state');
        self::assertSame(409, $this->post('noImage', 'dialog/arm', self::A, $started['revision'], ['dialogRevision' => 1])->getStatusCode());
        self::assertSame(0, $this->worker->state['session']['dialogCapture']['revision']);
        self::assertSame($before, $this->snapshot());
    }

    public function testPendingDialogRetryDismissAndFinalCaptureUseSeparateControls(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $before = $this->snapshot();
        $this->worker->state['session']['captureLocked'] = true;
        $this->worker->state['session']['dialogCapture'] = ['state' => 'failed', 'revision' => 1, 'armed' => true, 'pending' => true, 'attemptsRemaining' => 1];
        $failed = $this->panelStatus('noImage', self::A);
        self::assertTrue($failed['captureLocked']); self::assertFalse($failed['captureFinalized']);
        self::assertSame(409, $this->post('noImage', 'save', self::A, $started['revision'])->getStatusCode());
        self::assertSame($before, $this->snapshot()); self::assertNotContains('close', $this->operations());
        $retried = $this->json($this->post('noImage', 'dialog/retry', self::A, $started['revision'], ['dialogRevision' => 1]), 200);
        self::assertSame('captured', $retried['dialogCapture']['state']); self::assertFalse($retried['captureLocked']);
        self::assertFalse($retried['dialogCapture']['pending']); self::assertSame(str_repeat('e', 48), $retried['retainedDialog']['id']);
        $candidate = $retried['retainedDialog'];
        $this->worker->state['session']['captureLocked'] = true;
        $this->worker->state['session']['dialogCapture'] = ['state' => 'failed', 'revision' => 3, 'armed' => true, 'pending' => true, 'attemptsRemaining' => 0];
        $dismissed = $this->json($this->post('noImage', 'dialog/dismiss', self::A, $started['revision'], ['dialogRevision' => 3]), 200);
        self::assertFalse($dismissed['dialogCapture']['pending']); self::assertFalse($dismissed['captureLocked']);
        self::assertSame($candidate, $dismissed['retainedDialog']);
        $this->worker->state['session']['captureLocked'] = true;
        $this->worker->state['session']['captureFinalized'] = true;
        foreach (['arm', 'retry', 'dismiss'] as $action) {
            self::assertSame(409, $this->post('noImage', 'dialog/'.$action, self::A, $started['revision'], ['dialogRevision' => 4])->getStatusCode());
        }
        self::assertSame($candidate, $this->panelStatus('noImage', self::A)['retainedDialog']);
        self::assertSame($before, $this->snapshot()); self::assertNull($this->worker->state['capture']);
    }

    public function testPreviewCandidateIdentityPreventsReadingAnotherCandidate(): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $this->retainDialog();
        $candidateId = str_repeat('a', 48);
        $this->worker->state['session']['retainedDialog']['id'] = $candidateId;
        $path = $this->path('noImage').'/dialog-frame?revision='.$started['revision'].'&candidateId=';
        $response = $this->request($path.$candidateId, 'GET', [], ['HTTP_X_LBB_TAB' => self::A]);
        self::assertSame(200, $response->getStatusCode());
        $call = end($this->worker->state['calls']);
        self::assertSame('generation='.$started['revision'].'&candidateId='.$candidateId, $call['query']);
        self::assertSame(409, $this->request($path.str_repeat('b', 48), 'GET', [], ['HTTP_X_LBB_TAB' => self::A])->getStatusCode());
        $calls = $this->worker->state['calls'];
        self::assertSame(400, $this->request($path.'invalid', 'GET', [], ['HTTP_X_LBB_TAB' => self::A])->getStatusCode());
        self::assertSame($calls, $this->worker->state['calls']);
    }

    #[DataProvider('dialogRaces')]
    public function testDialogMutationsPinRightsPolicyAndUrlBeforeAndAfterRpc(string $change, bool $during): void
    {
        $started = $this->json($this->post('noImage', 'lease/acquire', self::A, 0), 200);
        $this->retainDialog();
        $changeRights = function () use ($change): void {
            $db = $this->entityManager->getConnection();
            switch ($change) {
                case 'takeover':
                    self::getContainer()->get(InteractiveBrowserLeaseService::class)->takeover($this->id('noImage'), $this->lease()['owner_hash'], self::B, $this->lease()['generation']); break;
                case 'owner': $db->executeStatement('UPDATE interactive_browser_lease SET owner_hash = ?', [str_repeat('f', 64)]); break;
                case 'channel': $db->executeStatement('UPDATE interactive_browser_lease SET channel_id = ?', [self::B]); break;
                case 'expiry': $db->executeStatement('UPDATE interactive_browser_lease SET expires_at = ?', [time() - 1]); break;
                case 'closing': $db->executeStatement("UPDATE interactive_browser_lease SET state = 'closing'"); break;
                case 'url': $db->update('finding', ['url' => 'http://127.0.0.1/changed'], ['id' => $this->id('noImage')]); break;
                case 'policy': $db->update('finding', ['status' => 'discarded'], ['id' => $this->id('noImage')]); break;
                case 'worker': $db->executeStatement('UPDATE interactive_browser_lease SET worker_id = ?', [str_repeat('c', 48)]); break;
            }
        };
        if ($during) $this->worker->afterDialog = $changeRights; else $changeRights();
        $mutations = count($this->mutations());
        $response = $this->post('noImage', 'dialog/arm', self::A, $started['revision'], ['dialogRevision' => 0]);
        self::assertContains($response->getStatusCode(), [409, 410]);
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('dialogCapture', $payload); self::assertArrayNotHasKey('retainedDialog', $payload);
        self::assertCount($mutations + ($during ? 1 : 0), $this->mutations());
        self::assertNotContains('capture', $this->operations()); self::assertNotContains('close', $this->operations());
        self::assertSame(1, $this->worker->state['starts']); self::assertNull($this->worker->state['capture']);
    }

    public static function dialogRaces(): iterable
    {
        foreach (['takeover', 'owner', 'channel', 'expiry', 'closing', 'url', 'policy', 'worker'] as $change) {
            foreach ([false, true] as $during) {
                if ($change === 'worker' && !$during) continue;
                yield $change.($during ? ' during' : ' before') => [$change, $during];
            }
        }
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
        if ($operation === 'save') { $extra += ['expectedCandidateId' => '', 'expectedPocRevision' => '0', 'pocMode' => 'keep']; }
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
        foreach (['finding', 'finding_poc_revision', 'finding_assessment', 'finding_review_acknowledgement', 'finding_assessment_reset', 'retest_run', 'evidence', 'screenshot_job'] as $table) {
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
