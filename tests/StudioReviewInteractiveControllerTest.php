<?php

namespace App\Tests;

use App\Dto\BrowserInteractiveSessionResult;
use App\Dto\BrowserScreenshotResult;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\ScreenshotJob;
use App\Service\BrowserInteractiveClientInterface;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class StudioReviewInteractiveControllerTest extends DatabaseTestCase
{
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';
    private Session $session;
    private ReviewInteractiveBrowser $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
        $this->browser = new ReviewInteractiveBrowser();
        self::getContainer()->set(BrowserInteractiveClientInterface::class, $this->browser);
        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);
        $screenshot = $this->createMock(BrowserScreenshotClientInterface::class);
        $screenshot->expects(self::never())->method('capture');
        $screenshot->expects(self::never())->method('waitUntilReady');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $screenshot);
    }

    public function testFreshRetryFlowSavesDisplayEvidenceWithoutChoosingAnAssessmentBasis(): void
    {
        [$finding, $trigger, $image] = $this->fixture();
        $before = $this->neutralState();
        $review = $this->request('/review');
        $form = $this->xpath($review->getContent())->query('//form[contains(@action, "/interactive-session/start")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        $parameters = [];
        foreach ($form->getElementsByTagName('input') as $input) {
            $parameters[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $parameters['return_query'] .= '&return_to=https%3A%2F%2Fevil.invalid&assessment=confirmed&message=forged';
        $started = $this->request($this->path($finding, 'start'), 'POST', $parameters);
        self::assertSame(303, $started->getStatusCode());
        $record = $this->record($finding);
        self::assertSame($finding->getId(), $record['finding_id']);
        self::assertSame($finding->getUrl(), $record['start_url']);
        self::assertSame(hash('sha256', $finding->getUrl()), $record['start_url_hash']);
        self::assertSame($trigger->getId(), $record['trigger_job_id']);
        self::assertSame($image->getId(), $record['trigger_evidence_id']);
        self::assertSame(0, $record['manual_action_count']);
        self::assertSame($this->browser->createdAt->format('Y-m-d\TH:i:s.vP'), $record['created_at']);
        self::assertSame(429, $record['initial_http_status']);
        self::assertSame(['kind', 'images', 'trail', 'evidence', 'card'], array_keys($record['return_query']));

        $show = $this->request($started->headers->get('Location'));
        self::assertSame(200, $show->getStatusCode());
        $this->assertPrivate($show);
        self::assertStringNotContainsString($record['session_id'], $show->getContent());
        $frame = $this->request($this->path($finding, 'frame'));
        self::assertSame(200, $frame->getStatusCode());
        self::assertSame('image/png', $frame->headers->get('Content-Type'));
        self::assertSame(base64_decode(self::PNG, true), $frame->getContent());
        $this->assertPrivate($frame);
        $input = $this->post($finding, 'input', ['action' => 'click', 'click_x' => '123', 'click_y' => '456']);
        self::assertSame(303, $input->getStatusCode());
        self::assertSame(['action' => 'click', 'x' => 123, 'y' => 456], $this->browser->lastEvent);
        self::assertSame(1, $this->record($finding)['manual_action_count']);
        self::assertSame($before, $this->neutralState());

        $saved = $this->post($finding, 'save');
        self::assertSame(303, $saved->getStatusCode());
        self::assertNull($this->record($finding));
        self::assertSame(['ready', 'start', 'status', 'frame', 'input', 'capture', 'close'], array_column($this->browser->calls, 0));
        self::assertSame($before, $this->neutralState());
        parse_str(parse_url($saved->headers->get('Location'), PHP_URL_QUERY), $returned);
        self::assertSame($finding->getId(), $returned['card']);
        self::assertNotSame($image->getId(), $returned['evidence']);
        self::assertArrayNotHasKey('assessment', $returned);
        self::assertArrayNotHasKey('return_to', $returned);
        $evidence = $this->entityManager->find(Evidence::class, $returned['evidence']);
        self::assertSame($finding->getId(), $evidence->getFinding()->getId());
        $job = $this->entityManager->getRepository(ScreenshotJob::class)->findOneBy(['screenshotPath' => $evidence->getFilePath()]);
        self::assertSame($trigger->getId(), $job->getCaptureMetadata()['triggerJobId']);
        self::assertSame($image->getId(), $job->getCaptureMetadata()['triggerEvidenceId']);
        self::assertSame(429, $job->getCaptureMetadata()['initialHttpStatus']);
        self::assertSame(1, $job->getCaptureMetadata()['manualActionCount']);
        self::assertTrue($job->getCaptureMetadata()['manualInteraction']);
        self::assertLessThanOrEqual($job->getCapturedAt(), $job->getStartedAt());
        $review = $this->request($saved->headers->get('Location'));
        self::assertSame(200, $review->getStatusCode());
        $xpath = $this->xpath($review->getContent());
        self::assertSame($returned['evidence'], $xpath->evaluate('string(//input[@name="displayed_evidence_id"]/@value)'));
        self::assertSame(0, $xpath->query('//select[@name="evidence_id"]/option[@selected and @value!=""]')->length);
        self::assertSame($before, $this->neutralState());
        self::assertSame(410, $this->post($finding, 'save')->getStatusCode());
    }

    public function testCancelClosesWithoutPersistingAndUsesTheStoredReturnQuery(): void
    {
        [$finding] = $this->fixture();
        $before = $this->allCaptureState();
        self::assertSame(303, $this->post($finding, 'start')->getStatusCode());
        $response = $this->post($finding, 'cancel', ['return_query' => 'card=foreign&return_to=https://evil.invalid']);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringStartsWith('/review?', $response->headers->get('Location'));
        self::assertStringNotContainsString('foreign', $response->headers->get('Location'));
        self::assertNull($this->record($finding));
        self::assertSame(['ready', 'start', 'close'], array_column($this->browser->calls, 0));
        self::assertSame($before, $this->allCaptureState());
        self::assertSame(410, $this->post($finding, 'cancel')->getStatusCode());
    }

    public function testCrossFindingStartPreservesTheFirstPage(): void
    {
        [$first] = $this->fixture();
        [$second] = $this->fixture();
        self::assertSame(303, $this->post($first, 'start')->getStatusCode());
        $oldId = $this->record($first)['session_id'];
        self::assertSame(409, $this->post($second, 'start')->getStatusCode());
        self::assertSame($oldId, $this->record($first)['session_id']);
        self::assertNull($this->record($second));
        self::assertSame(['ready', 'start'], array_column($this->browser->calls, 0));
        self::assertSame(200, $this->request($this->path($first))->getStatusCode());
        self::assertSame(303, $this->post($first, 'start')->getStatusCode());
        self::assertSame(['ready', 'start', 'status', 'close', 'ready', 'start'], array_column($this->browser->calls, 0));
        self::assertCount(1, array_filter(array_keys($this->session->all()), static fn (string $key): bool => str_starts_with($key, 'review_interactive_')));
    }

    #[DataProvider('postActions')]
    public function testInvalidCsrfNeverProxiesOrCloses(string $action): void
    {
        [$finding] = $this->fixture();
        $this->post($finding, 'start');
        $record = $this->record($finding);
        $calls = $this->browser->calls;
        foreach ([[], ['_token' => 'invalid'], ['_token' => ['nested']]] as $parameters) {
            $response = $this->request($this->path($finding, $action), 'POST', $parameters);
            self::assertSame(403, $response->getStatusCode());
            $this->assertPrivate($response);
            self::assertSame($calls, $this->browser->calls);
            self::assertSame($record, $this->record($finding));
        }
    }

    public static function postActions(): iterable
    {
        foreach (['start', 'input', 'save', 'cancel'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('proxyActions')]
    public function testMissingExpiredMalformedAndForeignBrowserSessionsCannotProxy(string $action): void
    {
        [$finding] = $this->fixture();
        self::assertSame(410, $this->operate($finding, $action)->getStatusCode());
        self::assertSame([], $this->browser->calls);
        $this->post($finding, 'start');
        $owner = $this->session;
        $calls = $this->browser->calls;
        $this->session = new Session(new MockArraySessionStorage());
        self::assertSame(410, $this->operate($finding, $action)->getStatusCode());
        self::assertSame($calls, $this->browser->calls);
        $this->session = $owner;
        $record = $this->record($finding);
        $record['expires_at'] = time() - 1;
        $this->session->set('review_interactive_'.$finding->getId(), $record);
        self::assertSame(410, $this->operate($finding, $action)->getStatusCode());
        self::assertNull($this->record($finding));
        self::assertSame('close', $this->browser->calls[array_key_last($this->browser->calls)][0]);
        $calls = $this->browser->calls;
        $this->session->set('review_interactive_'.$finding->getId(), ['session_id' => 'http://playwright:3000/not-an-id']);
        self::assertSame(410, $this->operate($finding, $action)->getStatusCode());
        self::assertSame($calls, $this->browser->calls);
    }

    public static function proxyActions(): iterable
    {
        foreach (['show', 'frame', 'input', 'save', 'cancel'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('changedContexts')]
    public function testEveryProxyRevalidatesPersistedCaseAndTrigger(string $action, string $change, int $status): void
    {
        [$finding, $job, $evidence] = $this->fixture();
        $this->post($finding, 'start');
        $connection = $this->entityManager->getConnection();
        // Direct SQL deliberately leaves the ORM identity map stale.
        match ($change) {
            'url' => $connection->update('finding', ['url' => $finding->getUrl().'#changed'], ['id' => $finding->getId()]),
            'trigger' => $connection->update('screenshot_job', ['capture_metadata' => json_encode(['challengeDetected' => true, 'challengeCleared' => true])], ['id' => $job->getId()]),
            'hash' => $connection->update('evidence', ['sha256' => str_repeat('f', 64)], ['id' => $evidence->getId()]),
            'job_deleted' => $connection->delete('screenshot_job', ['id' => $job->getId()]),
            'evidence_deleted' => $connection->delete('evidence', ['id' => $evidence->getId()]),
            'discarded' => $connection->update('finding', ['status' => 'discarded'], ['id' => $finding->getId()]),
            'manual_discard' => $connection->update('finding', ['manual_assessment' => 'discarded'], ['id' => $finding->getId()]),
            'case_block' => $connection->insert('finding_follow_up', ['finding_id' => $finding->getId(), 'pursuit' => 'active', 'checks_blocked' => 1, 'contact_blocked' => 0, 'changed_at' => '2026-10-17 12:00:00']),
            'closed' => $connection->insert('finding_follow_up', ['finding_id' => $finding->getId(), 'pursuit' => 'closed', 'checks_blocked' => 0, 'contact_blocked' => 0, 'changed_at' => '2026-10-17 12:00:00']),
            'domain_block' => $connection->insert('domain_work_restriction', ['hostname' => $finding->getDomain()->getHostname(), 'checks_blocked' => 1, 'contact_blocked' => 0, 'changed_at' => '2026-10-17 12:00:00']),
        };
        $before = $this->allCaptureState();
        $this->browser->calls = [];
        $response = $this->operate($finding, $action);
        self::assertSame($status, $response->getStatusCode(), $response->getContent());
        $this->assertPrivate($response);
        self::assertSame(['close'], array_column($this->browser->calls, 0));
        self::assertNull($this->record($finding));
        self::assertSame($before, $this->allCaptureState());
    }

    public static function changedContexts(): iterable
    {
        foreach (self::proxyActions() as [$action]) {
            foreach (['url', 'trigger', 'hash', 'job_deleted', 'evidence_deleted', 'discarded', 'manual_discard', 'case_block', 'closed', 'domain_block'] as $change) {
                yield $action.' '.$change => [$action, $change, in_array($change, ['discarded', 'manual_discard', 'case_block', 'closed', 'domain_block'], true) ? 403 : 409];
            }
        }
    }

    #[DataProvider('unknownActions')]
    public function testUnknownOrDeletedFindingsUse404AndRemoveOwnedSessions(string $action, bool $malformed): void
    {
        [$finding] = $this->fixture();
        $id = $malformed ? 'not-a-uuid' : Uuid::v7()->toRfc4122();
        $response = $this->operateId($id, $action);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->browser->calls);
        $this->post($finding, 'start');
        $this->entityManager->getConnection()->delete('finding', ['id' => $finding->getId()]);
        $this->browser->calls = [];
        self::assertSame(404, $this->operate($finding, $action)->getStatusCode());
        self::assertSame(['close'], array_column($this->browser->calls, 0));
        self::assertNull($this->record($finding));
    }

    public static function unknownActions(): iterable
    {
        foreach (['start', 'show', 'frame', 'input', 'save', 'cancel'] as $action) {
            foreach ([true, false] as $malformed) {
                yield $action.' '.($malformed ? 'malformed' : 'unknown') => [$action, $malformed];
            }
        }
    }

    #[DataProvider('workerFailures')]
    public function testWorkerFailuresOnlyInvalidateWhenGone(string $action, int $code): void
    {
        [$finding] = $this->fixture();
        $this->post($finding, 'start');
        $this->browser->failure = [$action === 'show' ? 'status' : ($action === 'save' ? 'capture' : ($action === 'cancel' ? 'close' : $action)), $code];
        $response = $this->operate($finding, $action);
        self::assertSame($action === 'cancel' && in_array($code, [404, 410], true) ? 303
            : (in_array($code, [404, 410], true) ? 410 : 502), $response->getStatusCode());
        $this->assertPrivate($response);
        if (in_array($code, [404, 410], true)) {
            self::assertNull($this->record($finding));
        } else {
            self::assertNotNull($this->record($finding));
        }
        self::assertCount(1, $this->entityManager->getRepository(Evidence::class)->findAll());
        $this->browser->failure = null;
        self::assertSame(in_array($code, [404, 410], true) ? 410 : (in_array($action, ['show', 'frame'], true) ? 200 : 303),
            $this->operate($finding, $action)->getStatusCode());
    }

    public static function workerFailures(): iterable
    {
        foreach (self::proxyActions() as [$action]) {
            foreach ([404, 410, 0, 503] as $code) {
                yield $action.' '.$code => [$action, $code];
            }
        }
    }

    #[DataProvider('invalidStarts')]
    public function testStartRequiresAnEligibleTriggerAndSafeReturnContext(string $change, int $status): void
    {
        [$finding, $job, $evidence] = $this->fixture();
        $query = '';
        switch ($change) {
            case 'url': $finding->setUrl('file:///etc/passwd'); break;
            case 'discarded': $finding->setStatus('discarded'); break;
            case 'no_job': $this->entityManager->remove($job); break;
            case 'cleared': $job->setCaptureMetadata(['challengeDetected' => true, 'challengeCleared' => true]); break;
            case 'no_challenge': $job->setCaptureMetadata([]); break;
            case 'blocked':
                $this->entityManager->getConnection()->insert('domain_work_restriction', ['hostname' => $finding->getDomain()->getHostname(), 'checks_blocked' => 1, 'contact_blocked' => 0, 'changed_at' => '2026-10-17 12:00:00']);
                break;
            case 'foreign_evidence': [, , $other] = $this->fixture(); $query = 'evidence='.$other->getId(); break;
            case 'foreign_card': $query = 'card='.Uuid::v7()->toRfc4122(); break;
            case 'foreign_trail': $query = 'trail='.Uuid::v7()->toRfc4122(); break;
            case 'nested': $query = 'kind[]=all'; break;
            case 'filter': $query = 'images=bogus'; break;
        }
        $this->entityManager->flush();
        $response = $this->post($finding, 'start', ['return_query' => $query]);
        self::assertSame($status, $response->getStatusCode(), $response->getContent());
        self::assertSame([], $this->browser->calls);
        self::assertNull($this->record($finding));
    }

    public static function invalidStarts(): iterable
    {
        foreach (['url' => 409, 'discarded' => 403, 'no_job' => 409, 'cleared' => 409, 'no_challenge' => 409,
            'blocked' => 403, 'foreign_evidence' => 409, 'foreign_card' => 400, 'foreign_trail' => 409, 'nested' => 400, 'filter' => 400] as $change => $status) {
            yield $change => [$change, $status];
        }
    }

    #[DataProvider('newerTriggerStates')]
    public function testAnOlderSelectedChallengeCannotOverrideLatestAvailableJob(array $metadata, bool $readable): void
    {
        [$finding, , $olderEvidence] = $this->fixture();
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, base64_decode(self::PNG, true), 'newer.png');
        $newer = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus('available')
            ->setRequestedAt(new \DateTimeImmutable('+1 minute'))->setScreenshotPath($stored->relativePath)
            ->setCapturedAt(new \DateTimeImmutable())->setCaptureMetadata($metadata);
        $this->entityManager->persist($newer);
        if ($readable) {
            $this->entityManager->persist((new Evidence())->setFinding($finding)->setKind('screenshot')
                ->setFilePath($stored->relativePath)->setSha256($stored->sha256));
        }
        $this->entityManager->flush();
        foreach (['', 'evidence='.$olderEvidence->getId()] as $returnQuery) {
            $response = $this->post($finding, 'start', ['return_query' => $returnQuery]);
            self::assertSame(409, $response->getStatusCode(), $response->getContent());
            self::assertNull($this->record($finding));
        }
        self::assertSame([], $this->browser->calls);
    }

    public static function newerTriggerStates(): iterable
    {
        yield 'cleared' => [['challengeDetected' => true, 'challengeCleared' => true], true];
        yield 'unknown clearance' => [['challengeDetected' => true], true];
        yield 'unreadable evidence' => [['challengeDetected' => true, 'challengeCleared' => false], false];
    }

    #[DataProvider('hiddenStartStates')]
    public function testReviewHidesInteractiveStartWithoutReadableExplicitChallenge(string $state): void
    {
        [$finding, $job, $evidence] = $this->fixture();
        switch ($state) {
            case 'failed': $job->setStatus('failed'); break;
            case 'missing': self::getContainer()->get(EvidenceStorageInterface::class)->deleteFile($evidence->getFilePath()); break;
            case 'unknown': $job->setCaptureMetadata(['challengeDetected' => true]); break;
        }
        $this->entityManager->flush();
        $review = $this->request('/review');
        self::assertSame(200, $review->getStatusCode());
        self::assertSame(0, $this->xpath($review->getContent())->query('//form[contains(@action, "/interactive-session/start")]')->length);
        self::assertSame(409, $this->post($finding, 'start')->getStatusCode());
    }

    public static function hiddenStartStates(): iterable
    {
        yield 'failed only' => ['failed'];
        yield 'missing file' => ['missing'];
        yield 'unknown clearance' => ['unknown'];
    }

    public function testReviewStartFormTargetsLatestEvidenceWhileOlderImageIsSelected(): void
    {
        [$finding, , $older] = $this->fixture();
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, base64_decode(self::PNG, true), 'latest.png');
        $latestEvidence = (new Evidence())->setFinding($finding)->setKind('screenshot')
            ->setFilePath($stored->relativePath)->setSha256($stored->sha256);
        $latestJob = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus('available')
            ->setRequestedAt(new \DateTimeImmutable('+1 minute'))->setScreenshotPath($stored->relativePath)
            ->setCapturedAt(new \DateTimeImmutable())->setCaptureMetadata(['challengeDetected' => true, 'challengeCleared' => false]);
        $this->entityManager->persist($latestEvidence);
        $this->entityManager->persist($latestJob);
        $this->entityManager->flush();
        $review = $this->request('/review?evidence='.$older->getId());
        self::assertSame(200, $review->getStatusCode());
        $form = $this->xpath($review->getContent())->query('//form[contains(@action, "/interactive-session/start")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        parse_str($this->xpath($review->getContent())->evaluate('string(//form[contains(@action, "/interactive-session/start")]/input[@name="return_query"]/@value)'), $query);
        self::assertSame($latestEvidence->getId(), $query['evidence']);
        self::assertSame(409, $this->post($finding, 'start', ['return_query' => 'evidence='.$older->getId()])->getStatusCode());
    }

    #[DataProvider('invalidInputs')]
    public function testMalformedAndUnboundedInputsNeverReachWorker(array $event): void
    {
        [$finding] = $this->fixture();
        $this->post($finding, 'start');
        $calls = $this->browser->calls;
        $response = $this->post($finding, 'input', $event);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame($calls, $this->browser->calls);
        self::assertNotNull($this->record($finding));
    }

    public static function invalidInputs(): iterable
    {
        foreach ([[], ['action' => ['nested']], ['action' => 'eval'], ['action' => 'click'],
            ['action' => 'click', 'click_x' => '-1', 'click_y' => '1'], ['action' => 'click', 'click_x' => '1440', 'click_y' => '1'],
            ['action' => 'click', 'click_x' => '1', 'click_y' => '900'], ['action' => 'click', 'click_x' => '1.5', 'click_y' => '1'],
            ['action' => 'click', 'click_x' => ['1'], 'click_y' => '1'], ['action' => 'key', 'key' => 'F12'],
            ['action' => 'type', 'text' => ''], ['action' => 'type', 'text' => str_repeat('a', 10001)],
            ['action' => 'scroll', 'deltaY' => '2001']] as $index => $event) {
            yield (string) $index => [$event];
        }
    }

    public function testFailedPersistenceRetainsSessionForRetryWithoutDuplicateEvidence(): void
    {
        [$finding] = $this->fixture();
        $this->post($finding, 'start');
        // Failure after worker capture and local storage, during DB persistence.
        $this->entityManager->getConnection()->executeStatement("CREATE TRIGGER reject_interactive BEFORE INSERT ON evidence BEGIN SELECT RAISE(ABORT, 'persistence failed'); END");
        $before = $this->allCaptureState();
        $response = $this->post($finding, 'save');
        self::assertSame(502, $response->getStatusCode());
        self::assertFalse($response->isRedirect());
        self::assertNotNull($this->record($finding));
        self::assertSame($before, $this->allCaptureState());
        self::assertNotContains('close', array_column($this->browser->calls, 0));
        self::assertCount(1, self::getContainer()->get(EvidenceStorageInterface::class)->listPaths());
        $this->entityManager->getConnection()->executeStatement('DROP TRIGGER reject_interactive');
        self::assertSame(303, $this->post($finding, 'save')->getStatusCode());
        self::assertNull($this->record($finding));
        self::assertCount(2, $this->entityManager->getRepository(Evidence::class)->findAll());
        self::assertSame(410, $this->post($finding, 'save')->getStatusCode());
    }

    public function testCommittedSaveStillSucceedsIfWorkerCloseFails(): void
    {
        [$finding] = $this->fixture();
        $this->post($finding, 'start');
        $this->browser->failure = ['close', 503];
        $response = $this->post($finding, 'save');
        self::assertSame(303, $response->getStatusCode());
        self::assertNull($this->record($finding));
        self::assertCount(2, $this->entityManager->getRepository(Evidence::class)->findAll());
        self::assertSame(410, $this->post($finding, 'save')->getStatusCode());
    }

    public function testSaveWithoutInputDoesNotClaimManualInteraction(): void
    {
        [$finding] = $this->fixture();
        self::assertSame(303, $this->post($finding, 'start')->getStatusCode());
        self::assertSame(0, $this->record($finding)['manual_action_count']);
        self::assertSame(303, $this->post($finding, 'save')->getStatusCode());
        $jobs = $this->entityManager->getRepository(ScreenshotJob::class)->findBy(['finding' => $finding]);
        $saved = array_values(array_filter($jobs, static fn (ScreenshotJob $job): bool => ($job->getCaptureMetadata()['interactive'] ?? false) === true))[0];
        self::assertFalse($saved->getCaptureMetadata()['manualInteraction']);
        self::assertSame(410, $this->post($finding, 'save')->getStatusCode());
        self::assertCount(2, $this->entityManager->getRepository(Evidence::class)->findAll());
    }

    public function testFailedInputDoesNotIncrementManualActionCount(): void
    {
        [$finding] = $this->fixture();
        $this->post($finding, 'start');
        $this->browser->failure = ['input', 503];
        self::assertSame(502, $this->post($finding, 'input', ['action' => 'reload'])->getStatusCode());
        self::assertSame(0, $this->record($finding)['manual_action_count']);
        $this->browser->failure = null;
        self::assertSame(303, $this->post($finding, 'input', ['action' => 'reload'])->getStatusCode());
        self::assertSame(1, $this->record($finding)['manual_action_count']);
    }

    public function testCrossFindingStartDoesNotCloseEvenWhenCloseWouldFail(): void
    {
        [$first] = $this->fixture();
        [$second] = $this->fixture();
        $this->post($first, 'start');
        $this->browser->failure = ['close', 503];
        $this->browser->calls = [];
        self::assertSame(409, $this->post($second, 'start')->getStatusCode());
        self::assertSame([], $this->browser->calls);
        self::assertNull($this->record($second));
        self::assertNotNull($this->record($first));
        $this->browser->failure = null;
        self::assertSame(303, $this->post($first, 'cancel')->getStatusCode());
        self::assertSame(303, $this->post($second, 'start')->getStatusCode());
        self::assertSame(['close', 'ready', 'start'], array_column($this->browser->calls, 0));
    }

    #[DataProvider('validInputs')]
    public function testAllowlistedInputsAreProxiedWithBoundedValues(array $input, array $expected): void
    {
        [$finding] = $this->fixture();
        $this->post($finding, 'start');
        self::assertSame(303, $this->post($finding, 'input', $input)->getStatusCode());
        self::assertSame($expected, $this->browser->lastEvent);
    }

    public static function validInputs(): iterable
    {
        yield 'click edge' => [['action' => 'click', 'click_x' => '1439', 'click_y' => '899'], ['action' => 'click', 'x' => 1439, 'y' => 899]];
        yield 'text' => [['action' => 'type', 'text' => 'manual input'], ['action' => 'type', 'text' => 'manual input']];
        yield 'key' => [['action' => 'key', 'key' => 'Tab'], ['action' => 'key', 'key' => 'Tab']];
        yield 'scroll' => [['action' => 'scroll', 'deltaY' => '-2000'], ['action' => 'scroll', 'deltaY' => -2000]];
        yield 'reload' => [['action' => 'reload'], ['action' => 'reload']];
        yield 'back' => [['action' => 'back'], ['action' => 'back']];
    }

    #[DataProvider('proxyActions')]
    public function testARecordCopiedToAnotherFindingCannotProxy(string $action): void
    {
        [$first] = $this->fixture();
        [$second] = $this->fixture();
        $this->post($first, 'start');
        $this->session->set('review_interactive_'.$second->getId(), $this->record($first));
        $this->browser->calls = [];
        self::assertSame(409, $this->operate($second, $action)->getStatusCode());
        self::assertSame(['close'], array_column($this->browser->calls, 0));
        self::assertNull($this->record($second));
    }

    public function testSavePreservesAnExistingAssessmentAndItsExplicitBasis(): void
    {
        [$finding, , $evidence] = $this->fixture();
        self::getContainer()->get(\App\Service\FindingService::class)->assess($finding, 'confirmed', evidenceId: $evidence->getId());
        $before = $this->neutralState();
        self::assertSame(303, $this->post($finding, 'start')->getStatusCode());
        self::assertSame(303, $this->post($finding, 'save')->getStatusCode());
        self::assertSame($before, $this->neutralState());
        self::assertCount(1, $before['finding_assessment']);
        self::assertSame($evidence->getId(), $before['finding_assessment'][0]['evidence_id']);
    }

    public function testWorkerStartFailureIsSanitizedAndDoesNotLeaveAnActiveRecord(): void
    {
        [$finding] = $this->fixture();
        $this->browser->failure = ['start', 503];
        $before = $this->allCaptureState();
        $response = $this->post($finding, 'start');
        self::assertSame(502, $response->getStatusCode());
        $this->assertPrivate($response);
        self::assertNull($this->record($finding));
        self::assertSame($before, $this->allCaptureState());
    }

    public function testExpiredPreviousPageAllowsAnotherStart(): void
    {
        [$first] = $this->fixture();
        [$second] = $this->fixture();
        $this->post($first, 'start');
        $old = $this->record($first);
        $old['expires_at'] = time() - 1;
        $this->session->set('review_interactive_'.$first->getId(), $old);
        $this->browser->failure = ['close', 410];
        self::assertSame(303, $this->post($second, 'start')->getStatusCode());
        self::assertNull($this->record($first));
        self::assertNotNull($this->record($second));
    }

    #[DataProvider('returnActions')]
    public function testSaveAndCancelReturnToTheSameCardAfterNavigationTrailWasEvicted(string $action): void
    {
        [$finding] = $this->fixture();
        $this->post($finding, 'start');
        $this->session->remove('studio_review_trails');
        $response = $this->post($finding, $action);
        self::assertSame(303, $response->getStatusCode());
        $review = $this->request($response->headers->get('Location'));
        self::assertSame(200, $review->getStatusCode());
        self::assertSame($finding->getId(), $this->xpath($review->getContent())->evaluate('string(//*[@data-current-id]/@data-current-id)'));
    }

    public static function returnActions(): iterable
    {
        yield 'save' => ['save'];
        yield 'cancel' => ['cancel'];
    }

    /** @return array{Finding, ScreenshotJob, Evidence} */
    private function fixture(): array
    {
        $domain = (new Domain())->setHostname('case-'.Uuid::v7()->toRfc4122().'.invalid')->setScheme('https');
        $finding = (new Finding())->setDomain($domain)->setTitle('Interactive retry fixture')->setType('other')
            ->setSeverity('medium')->setUrl('https://'.$domain->getHostname().'/poc?exact=%2f')->setMethod('GET')->setStatus('new');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, base64_decode(self::PNG, true), 'trigger.png');
        $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($stored->relativePath)->setSha256($stored->sha256);
        $job = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus('available')
            ->setScreenshotPath($stored->relativePath)->setCapturedAt(new \DateTimeImmutable())
            ->setCaptureMetadata(['challengeDetected' => true, 'challengeCleared' => false]);
        $this->entityManager->persist($evidence);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return [$finding, $job, $evidence];
    }

    private function path(Finding $finding, string $action = 'show'): string
    {
        return $this->idPath($finding->getId(), $action);
    }

    private function idPath(string $id, string $action): string
    {
        return '/review/'.$id.'/interactive-session'.($action === 'show' ? '' : '/'.$action);
    }

    private function operate(Finding $finding, string $action): Response
    {
        return $this->operateId($finding->getId(), $action);
    }

    private function operateId(string $id, string $action): Response
    {
        return in_array($action, ['show', 'frame'], true) ? $this->request($this->idPath($id, $action))
            : $this->request($this->idPath($id, $action), 'POST', ['_token' => $this->token($id), 'action' => 'reload']);
    }

    private function post(Finding $finding, string $action, array $parameters = []): Response
    {
        return $this->request($this->path($finding, $action), 'POST', $parameters + ['_token' => $this->token($finding->getId())]);
    }

    private function token(string $id): string
    {
        $request = Request::create('/review');
        $request->setSession($this->session);
        $stack = self::getContainer()->get(RequestStack::class);
        $stack->push($request);
        try {
            return self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('review_interactive_'.$id)->getValue();
        } finally {
            $stack->pop();
        }
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function record(Finding $finding): ?array
    {
        return $this->session->get('review_interactive_'.$finding->getId());
    }

    private function neutralState(): array
    {
        return $this->tables(['finding', 'finding_assessment', 'retest_run', 'finding_review_acknowledgement', 'finding_assessment_reset']);
    }

    private function allCaptureState(): array
    {
        return $this->neutralState() + $this->tables(['evidence', 'screenshot_job']);
    }

    private function tables(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid');
        }

        return $result;
    }

    private function assertPrivate(Response $response): void
    {
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('playwright:3000', $response->getContent());
        self::assertStringNotContainsString(str_repeat('a', 48), $response->getContent());
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }
}

/** Worker double deliberately reports internal details in errors to test redaction. */
final class ReviewInteractiveBrowser implements BrowserInteractiveClientInterface
{
    public array $calls = [];
    public ?array $failure = null;
    public array $lastEvent = [];
    public ?\DateTimeImmutable $createdAt = null;
    private int $starts = 0;
    private ?BrowserInteractiveSessionResult $session = null;

    public function waitUntilReady(): void { $this->call('ready'); }

    public function startInteractive(string $url, int $timeoutMs): BrowserInteractiveSessionResult
    {
        $this->call('start', $url);
        $this->createdAt = new \DateTimeImmutable();
        $this->session = new BrowserInteractiveSessionResult(
            str_pad(dechex(++$this->starts), 48, 'a', STR_PAD_LEFT), $this->createdAt, new \DateTimeImmutable('+20 minutes'),
            $url, 403, ['active' => true, 'state' => 'detected'], ['active' => true, 'state' => 'detected'],
            initialHttpStatus: 429,
        );

        return $this->session;
    }

    public function interactiveStatus(string $sessionId): BrowserInteractiveSessionResult
    {
        $this->call('status', $sessionId);
        return $this->session;
    }

    public function interactiveInput(string $sessionId, array $event): BrowserInteractiveSessionResult
    {
        $this->call('input', $sessionId);
        $this->lastEvent = $event;
        return $this->session;
    }

    public function interactiveFrame(string $sessionId): string
    {
        $this->call('frame', $sessionId);
        return base64_decode(StudioReviewInteractiveControllerTest::PNG, true);
    }

    public function captureInteractive(string $sessionId): BrowserScreenshotResult
    {
        $this->call('capture', $sessionId);
        return new BrowserScreenshotResult(StudioReviewInteractiveControllerTest::PNG, new \DateTimeImmutable(), 200,
            $this->session->finalUrl, captureMethod: 'interactive-desktop-page', metadata: ['challenge' => ['active' => false, 'state' => 'cleared']]);
    }

    public function closeInteractive(string $sessionId): void { $this->call('close', $sessionId); }

    private function call(string $operation, string $argument = ''): void
    {
        $this->calls[] = [$operation, $argument];
        if (($this->failure[0] ?? null) === $operation) {
            throw new \RuntimeException('http://playwright:3000/interactive-sessions/'.str_repeat('a', 48).' internal failure', $this->failure[1]);
        }
    }
}
