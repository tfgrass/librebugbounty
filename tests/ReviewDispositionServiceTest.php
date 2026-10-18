<?php
namespace App\Tests;

use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Service\BrowserRetestClientInterface;
use App\Service\ReviewDispositionService;
use App\Service\ReviewQueueService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ReviewDispositionServiceTest extends DatabaseTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';
    private ReviewDispositionService $service;
    private ReviewQueueService $queue;
    private object $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $request = Request::create('/review');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);
        $this->browser = new class implements BrowserRetestClientInterface {
            public int $requests = 0;
            public ?\Closure $callback = null;
            public function retest(BrowserRetestRequest $request): RetestResultData {
                ++$this->requests;
                return ($this->callback)($request);
            }
        };
        self::getContainer()->set(BrowserRetestClientInterface::class, $this->browser);
        $this->service = self::getContainer()->get(ReviewDispositionService::class);
        $this->queue = self::getContainer()->get(ReviewQueueService::class);
    }

    public function testDeferralLeavesAssessmentAndReminderAndRemovesNormalSupply(): void
    {
        $finding = $this->finding();
        $this->service->defer($finding->getId(), 'unreachable', '504 returned by target', null, $this->token($finding));
        self::assertTrue(ReviewDispositionService::isDeferred($this->entityManager->getConnection(), $finding->getId()));
        self::assertSame('unreachable', $this->service->get($finding->getId())['reason']);
        self::assertSame(0, $this->queue->get(['images' => 'all'])->counts['all']);
        self::assertSame([], self::getContainer()->get(FindingRepository::class)->findAllForBrowserRetest());
        self::assertSame([], self::getContainer()->get(FindingRepository::class)->findAllOrdered());
        self::assertNull($finding->getManualAssessment());
        $this->service->resume($finding->getId(), $this->token($finding));
        self::assertFalse(ReviewDispositionService::isDeferred($this->entityManager->getConnection(), $finding->getId()));
        self::assertSame(1, $this->queue->get(['images' => 'all'])->counts['all']);
    }

    public function testOnlyUniqueConfirmedSkipsCountAndPromptOncePerContext(): void
    {
        $finding = $this->finding();
        $token = $this->token($finding);
        self::assertFalse($this->service->recordSkip($finding->getId(), $token, str_repeat('a', 24)));
        self::assertFalse($this->service->recordSkip($finding->getId(), $token, str_repeat('a', 24)));
        self::assertFalse($this->service->recordSkip($finding->getId(), $token, str_repeat('b', 24)));
        self::assertTrue($this->service->recordSkip($finding->getId(), $token, str_repeat('c', 24)));
        self::assertFalse($this->service->recordSkip($finding->getId(), $token, str_repeat('d', 24)));
        self::assertSame(3, (int) $this->entityManager->getConnection()->fetchOne('SELECT skip_count FROM finding_review_skip'));
        $this->entityManager->getConnection()->executeStatement("UPDATE finding SET expected_evidence = 'new marker' WHERE id = ?", [$finding->getId()]);
        self::assertFalse($this->service->recordSkip($finding->getId(), $this->token($finding), str_repeat('e', 24)));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT skip_count FROM finding_review_skip'));
    }

    public function testStaleContextCannotDeferOrResumeANewDecision(): void
    {
        $finding = $this->finding();
        $token = $this->token($finding);
        $this->service->defer($finding->getId(), 'protection', null, null, $token);
        $this->expectException(\UnexpectedValueException::class);
        $this->service->resume($finding->getId(), $token);
    }

    public function testDueRecoveryRunsExactlyOnceAndFailedOutcomeStaysDeferred(): void
    {
        $finding = $this->due();
        $this->browser->callback = static function (BrowserRetestRequest $request): RetestResultData {
            self::assertTrue($request->screenshot);
            self::assertFalse($request->headless);
            return new RetestResultData('error', 504, errorMessage: 'Target HTTP 504');
        };
        self::assertSame('recovery-deferred', $this->service->processNext());
        self::assertSame('completed', $this->service->get($finding->getId())['retry_state']);
        self::assertSame('deferred', $this->service->get($finding->getId())['state']);
        self::assertSame('error', $this->service->get($finding->getId())['retry_result']);
        self::assertNull($this->service->processNext());
        self::assertSame(1, $this->browser->requests);
        $this->entityManager->refresh($finding);
        self::assertSame('new', $finding->getStatus());
        self::assertSame('manual_checking', $finding->getReviewState());
    }

    public function testMatchingDialogAndImageReopenReviewWithoutAutomaticAssessment(): void
    {
        $finding = $this->due();
        $this->browser->callback = static fn (): RetestResultData => new RetestResultData('still_vulnerable', 200, dialogText: 'marker', screenshotBase64: self::PNG, raw: ['screenshotCapturedFromDialog' => true, 'screenshotCaptureMethod' => 'desktop-dialog', 'dialogEvents' => [['type' => 'alert', 'message' => 'marker']]]);
        self::assertSame('recovery-review', $this->service->processNext());
        self::assertSame('active', $this->service->get($finding->getId())['state']);
        $this->entityManager->refresh($finding);
        self::assertSame('new', $finding->getStatus());
        self::assertNull($finding->getManualAssessment());
        self::assertSame('manual_checking', $finding->getReviewState());
        self::assertSame(1, $this->queue->get(['images' => 'all'])->counts['all']);
        self::assertNull($this->service->processNext());
    }

    public function testPageOnlyOrWrongMarkerCannotReopenReview(): void
    {
        foreach ([new RetestResultData('fixed', 200, screenshotBase64: self::PNG), new RetestResultData('still_vulnerable', 200, dialogText: 'another dialog', screenshotBase64: self::PNG)] as $result) {
            $finding = $this->due('case-'.bin2hex(random_bytes(4)).'.localhost');
            $this->browser->callback = static fn (): RetestResultData => $result;
            self::assertSame('recovery-deferred', $this->service->processNext());
            self::assertSame('deferred', $this->service->get($finding->getId())['state']);
        }
    }

    public function testCrashConsumesClaimAndDoesNotQueueAnotherAttempt(): void
    {
        $finding = $this->due();
        $claim = $this->service->claimNext();
        self::assertNotNull($claim);
        self::assertNull($this->service->claimNext());
        self::assertNull($this->service->claimNext(new \DateTimeImmutable('+11 minutes')));
        self::assertSame('interrupted', $this->service->get($finding->getId())['retry_state']);
        self::assertSame(0, $this->browser->requests);
    }

    public function testLateResultAfterPocChangeCannotResumeOrOverrideCurrentState(): void
    {
        $finding = $this->due();
        $this->browser->callback = function () use ($finding): RetestResultData {
            $this->entityManager->getConnection()->executeStatement('UPDATE finding SET poc_url = ?, poc_revision = poc_revision + 1 WHERE id = ?', ['http://recovery.localhost/new', $finding->getId()]);
            return new RetestResultData('still_vulnerable', 200, dialogText: 'marker', screenshotBase64: self::PNG, raw: ['screenshotCapturedFromDialog' => true, 'screenshotCaptureMethod' => 'desktop-dialog', 'dialogEvents' => [['type' => 'alert', 'message' => 'marker']]]);
        };
        self::assertSame('recovery-cancelled', $this->service->processNext());
        self::assertSame('deferred', $this->service->get($finding->getId())['state']);
        self::assertSame('cancelled', $this->service->get($finding->getId())['retry_state']);
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM retest_run'));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne("SELECT json_extract(raw_result, '$.staleFindingContext') FROM retest_run"));
    }

    public function testWorkRestrictionLeavesScheduledAttemptUnclaimed(): void
    {
        $finding = $this->due();
        $this->entityManager->getConnection()->insert('finding_follow_up', ['finding_id' => $finding->getId(), 'pursuit' => 'active', 'checks_blocked' => 1, 'contact_blocked' => 0, 'changed_at' => '2026-10-18 12:00:00']);
        self::assertNull($this->service->processNext());
        self::assertSame('pending', $this->service->get($finding->getId())['retry_state']);
        self::assertSame(0, $this->browser->requests);
    }

    public function testResumedManualCaseReturnsUntilAcknowledgedWithoutErasingJudgment(): void
    {
        $finding = $this->finding();
        $finding->setManualAssessment('confirmed', null, new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->service->defer($finding->getId(), 'protection', null, null, $this->token($finding));
        $this->service->resume($finding->getId(), $this->token($finding));
        self::assertSame(1, $this->queue->get(['images' => 'all'])->counts['all']);
        $this->queue->assess($finding->getId(), 'keep', null, null, null, $this->token($finding));
        self::assertSame('confirmed', $finding->getManualAssessment());
        self::assertSame('reviewed', $this->service->get($finding->getId())['state']);
        self::assertSame(0, $this->queue->get(['images' => 'all'])->counts['all']);
    }

    public function testRecoveryAndNormalClaimsShareDomainSpacing(): void
    {
        $first = $this->due('same.localhost');
        $domain = $first->getDomain();
        $second = (new Finding())->setDomain($domain)->setUrl('http://same.localhost/second')->setTitle('Second')->setType('reflected_xss')->setSeverity('medium');
        $second->setNextDueAt(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->persist($second);
        $this->entityManager->flush();
        $claim = $this->service->claimNext();
        self::assertNotNull($claim);
        $this->entityManager->refresh($second);
        self::assertGreaterThan(new \DateTimeImmutable('+50 minutes'), $second->getNextDueAt());
        self::assertNull(self::getContainer()->get(\App\Service\RecheckService::class)->claimNext());
    }

    public function testRecoveryDoesNotClaimARecentlyCheckedDomain(): void
    {
        $finding = $this->due();
        $finding->setLastRetestedAt(new \DateTimeImmutable('-5 minutes'));
        $this->entityManager->flush();
        self::assertNull($this->service->claimNext());
        self::assertSame('pending', $this->service->get($finding->getId())['retry_state']);
    }

    public function testDesktopFallbackWithoutDialogImageStaysDeferred(): void
    {
        $finding = $this->due();
        $this->browser->callback = static fn (): RetestResultData => new RetestResultData('still_vulnerable', 200, dialogText: 'marker', screenshotBase64: self::PNG, raw: ['screenshotCapturedFromDialog' => false, 'screenshotCaptureMethod' => 'desktop', 'screenshotCaptureError' => 'dialog capture failed']);
        self::assertSame('recovery-deferred', $this->service->processNext());
        self::assertSame('deferred', $this->service->get($finding->getId())['state']);
    }

    public function testIndependentWorkerProcessesCannotClaimTheSameAttempt(): void
    {
        if (!function_exists('proc_open')) self::markTestSkipped('proc_open is needed for independent claim workers.');
        $finding = $this->due();
        $directory = APP_TEST_ROOT.'/recovery-claims';
        mkdir($directory, 0700, true);
        $processes = [];
        try {
            foreach (['one', 'two'] as $identity) {
                $environment = getenv();
                $process = new \Symfony\Component\Process\Process([PHP_BINARY, dirname(__DIR__).'/tests/Support/review_recovery_claim_worker.php', $directory, $identity], dirname(__DIR__), array_replace($environment, [
                    'REVIEW_RECOVERY_CACHE_DIR' => $directory.'/cache-'.$identity,
                    'REVIEW_RECOVERY_LOG_DIR' => $directory.'/logs-'.$identity,
                ]));
                $process->start();
                $processes[$identity] = $process;
            }
            $deadline = microtime(true) + 20;
            while (!is_file($directory.'/ready-one') || !is_file($directory.'/ready-two')) {
                foreach ($processes as $process) self::assertTrue($process->isRunning(), $process->getOutput().$process->getErrorOutput());
                if (microtime(true) > $deadline) self::fail('Recovery workers did not reach the claim barrier.');
                usleep(10000);
            }
            touch($directory.'/release');
            $claims = [];
            foreach ($processes as $identity => $process) {
                self::assertSame(0, $process->wait(), $process->getOutput().$process->getErrorOutput());
                $claims[] = json_decode(file_get_contents($directory.'/result-'.$identity.'.json'), true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertCount(1, array_filter($claims));
            self::assertSame($finding->getId(), array_values(array_filter($claims))[0]['finding_id']);
            self::assertSame('running', $this->service->get($finding->getId())['retry_state']);
        } finally {
            touch($directory.'/release');
            foreach ($processes as $process) if ($process->isRunning()) $process->stop(1);
        }
    }

    public function testStaleOrdinaryRecheckCannotEnqueueNewScreenshotWork(): void
    {
        $finding = $this->finding();
        $finding->setNextDueAt(new \DateTimeImmutable('-1 day'));
        $this->entityManager->flush();
        $screenshots = $this->createMock(\App\Service\ScreenshotEnqueuerInterface::class);
        $screenshots->expects(self::never())->method('enqueue');
        $runner = new \App\Service\RecheckService(
            self::getContainer()->get(FindingRepository::class), self::getContainer()->get(\App\Service\RetestService::class),
            self::getContainer()->get(\App\Service\RecheckPolicy::class), $this->entityManager,
            self::getContainer()->get(\App\Repository\RetestRunRepository::class), $screenshots, $this->service,
        );
        $this->browser->callback = function () use ($finding): RetestResultData {
            $this->service->defer($finding->getId(), 'unreachable', null, null, $this->token($finding));
            return new RetestResultData('error', errorMessage: 'old result');
        };
        self::assertSame('skipped-stale-result', $runner->processNext());
        self::assertSame('deferred', $this->service->get($finding->getId())['state']);
        $this->entityManager->refresh($finding);
        self::assertNull($finding->getNextDueAt());
    }

    public function testResumedManualCaseAndNoticeAreOneQueueCard(): void
    {
        $finding = $this->finding();
        $finding->setManualAssessment('confirmed', null, new \DateTimeImmutable('-1 day'));
        $this->entityManager->flush();
        $run = (new \App\Entity\RetestRun())->setFinding($finding)->setMode('browser')->setResult('error')->setFinishedAt(new \DateTimeImmutable());
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->service->defer($finding->getId(), 'protection', null, null, $this->token($finding));
        $this->service->resume($finding->getId(), $this->token($finding));
        $view = $this->queue->get(['images' => 'all']);
        self::assertSame(1, $view->counts['all']);
        self::assertSame(1, $view->total);
        self::assertSame(1, $view->counts['changed']);
    }

    public function testSameSecondIdenticalHumanDecisionInvalidatesRecoveryResult(): void
    {
        $finding = $this->due();
        $finding->setManualAssessment('confirmed', null, new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->browser->callback = function () use ($finding): RetestResultData {
            // A new explicit decision can retain exactly the same compatibility
            // fields in SQLite's timestamp second. Its history identity differs.
            $this->entityManager->persist(new \App\Entity\FindingAssessment($finding, 'confirmed', null, $finding->getAssessedAt()));
            $this->entityManager->flush();
            return new RetestResultData('still_vulnerable', 200, dialogText: 'marker', screenshotBase64: self::PNG, raw: ['screenshotCapturedFromDialog' => true, 'screenshotCaptureMethod' => 'desktop-dialog', 'dialogEvents' => [['type' => 'alert', 'message' => 'marker']]]);
        };
        self::assertSame('recovery-cancelled', $this->service->processNext());
        self::assertSame('deferred', $this->service->get($finding->getId())['state']);
    }

    public function testLaterMarkerDialogCannotValidateAnEarlierUnrelatedDialogImage(): void
    {
        $finding = $this->due();
        $this->browser->callback = static fn (): RetestResultData => new RetestResultData('still_vulnerable', 200, dialogText: "hello\nmarker", screenshotBase64: self::PNG, raw: ['screenshotCapturedFromDialog' => true, 'screenshotCaptureMethod' => 'desktop-dialog', 'dialogEvents' => [['type' => 'alert', 'message' => 'hello'], ['type' => 'alert', 'message' => 'marker']]]);
        self::assertSame('recovery-deferred', $this->service->processNext());
        self::assertSame('deferred', $this->service->get($finding->getId())['state']);
    }

    private function finding(string $host = 'recovery.localhost'): Finding
    {
        $domain = (new Domain())->setHostname($host);
        $finding = (new Finding())->setDomain($domain)->setUrl('http://'.$host.'/poc')->setType('reflected_xss')->setTitle('Recovery case')->setSeverity('medium')->setExpectedEvidence('marker');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        return $finding;
    }
    private function due(string $host = 'recovery.localhost'): Finding
    {
        $finding = $this->finding($host);
        $finding->setReviewState('manual_checking');
        $this->entityManager->flush();
        $this->service->defer($finding->getId(), 'unreachable', 'try once', new \DateTimeImmutable('+1 day'), $this->token($finding));
        $this->entityManager->getConnection()->executeStatement('UPDATE finding_review_disposition SET due_at = ? WHERE finding_id = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $finding->getId()]);
        return $finding;
    }
    private function token(Finding $finding): string { return $this->queue->contextToken($finding->getId(), $this->queue->fingerprint($finding->getId())); }
}
