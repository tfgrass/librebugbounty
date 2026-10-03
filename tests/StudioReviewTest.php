<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingNavigation;
use App\Service\FindingService;
use App\Service\ReviewQueueService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StudioReviewTest extends DatabaseTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';
    private Session $session;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);
        $screenshot = $this->createMock(BrowserScreenshotClientInterface::class);
        $screenshot->expects(self::never())->method('capture');
        $screenshot->expects(self::never())->method('waitUntilReady');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $screenshot);
    }

    public function testQueueUsesLatestRecordedResultActualFilesAndExplicitManualDecisions(): void
    {
        $inconclusive = $this->finding('inconclusive');
        $this->observation($inconclusive, 'inconclusive');
        $this->image($inconclusive);
        $error = $this->finding('error');
        $this->observation($error, 'error');
        $missing = (new Evidence())->setFinding($error)->setKind('screenshot')->setFilePath('storage/artifacts/missing.png');
        $availableJob = (new ScreenshotJob())->setFinding($error)->setUrl($error->getUrl())->setStatus('available')->setScreenshotPath('storage/artifacts/missing.png');
        $this->entityManager->persist($missing);
        $this->entityManager->persist($availableJob);
        $this->entityManager->flush();
        $unchecked = $this->finding('unchecked');
        $this->image($unchecked);
        $this->finding('unchecked-no-image');
        $legacy = $this->finding('legacy-manual-marker')->setReviewState('manually_checked');
        $this->image($legacy);
        $legacyFixed = $this->finding('legacy-fixed-status')->setStatus('fixed')->setReviewState('confirmed_fixed');
        $this->image($legacyFixed);

        foreach (['still_vulnerable', 'fixed', 'pending'] as $result) {
            $excluded = $this->finding('automatic-'.$result);
            $this->observation($excluded, 'inconclusive');
            // Same-second insertion must win over random UUID ordering.
            $this->observation($excluded, $result);
            $this->image($excluded);
        }
        foreach (['confirmed', 'fixed', 'discarded'] as $assessment) {
            $excluded = $this->finding('manual-'.$assessment);
            $this->observation($excluded, 'inconclusive');
            $this->image($excluded);
            self::getContainer()->get(FindingService::class)->assess($excluded, $assessment);
        }
        foreach (['duplicate', 'discarded'] as $status) {
            $excluded = $this->finding('legacy-'.$status)->setStatus($status);
            $this->image($excluded);
        }
        $this->entityManager->flush();
        $before = $this->snapshot();
        $queue = self::getContainer()->get(ReviewQueueService::class);
        $view = $queue->get([]);
        self::assertSame(['all' => 6, 'inconclusive' => 1, 'error' => 1, 'unchecked' => 4, 'ready' => 4, 'missing' => 2, 'changed' => 0], $view->counts);
        self::assertSame(4, $view->total);
        self::assertSame($inconclusive->getId(), $view->detail->finding->getId());
        self::assertSame($error->getId(), $queue->get(['kind' => 'error', 'images' => 'all'])->detail->finding->getId());
        self::assertSame(2, $queue->get(['images' => 'missing'])->total);
        self::assertSame($unchecked->getId(), $queue->get(['kind' => 'unchecked'])->detail->finding->getId());
        self::assertSame($before, $this->snapshot());
    }

    public function testGetAndSkipAreNeutralAndPoCIsEscapedWithUnknownCaptureTime(): void
    {
        $first = $this->finding('first <tag>');
        $first->setMethod('POST')->setRequestParams(['input' => 'literal <mark>'])
            ->setPayload('stored fixture <payload>')->setExpectedEvidence('fixture marker');
        $this->image($first);
        $next = $this->finding('next');
        $this->image($next);
        $before = $this->snapshot();
        $paths = self::getContainer()->get(EvidenceStorageInterface::class)->listPaths();
        $response = $this->request('/review');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('POST', $response->getContent());
        self::assertStringContainsString('stored fixture &lt;payload&gt;', $response->getContent());
        self::assertStringContainsString('fixture marker', $response->getContent());
        $view = self::getContainer()->get(ReviewQueueService::class)->get([]);
        $skipped = $this->request($view->nextPath);
        self::assertStringContainsString('/review/'.$next->getId().'/assessment', $skipped->getContent());
        self::assertSame($before, $this->snapshot());
        self::assertSame($paths, self::getContainer()->get(EvidenceStorageInterface::class)->listPaths());
    }

    public function testSuccessfulAssessmentAndNextDoNotSkipARecordRemovedFromQueue(): void
    {
        $first = $this->finding('first');
        $this->image($first);
        $second = $this->finding('second');
        $this->image($second);
        $third = $this->finding('third');
        $this->image($third);
        $parameters = $this->form($this->request('/review')->getContent());
        $saved = $this->submit($first, $parameters + ['assessment' => 'confirmed']);
        self::assertSame(303, $saved->getStatusCode());
        self::assertStringContainsString('after='.$first->getId(), $saved->headers->get('Location'));
        self::assertStringContainsString('reviewed='.$first->getId(), $saved->headers->get('Location'));
        self::assertSame('confirmed', $this->state($first)['manual_assessment']);
        $history = $this->history($first);
        self::assertCount(1, $history);
        self::assertNull($history[0]['observation_id']);
        self::assertNull($history[0]['evidence_id']);
        $next = $this->request($saved->headers->get('Location'));
        self::assertStringContainsString('/review/'.$second->getId().'/assessment', $next->getContent());
        self::assertStringContainsString('/findings/'.$first->getId(), $next->getContent());
        $queue = self::getContainer()->get(ReviewQueueService::class);
        self::assertSame(2, $queue->get(['after' => $first->getId()])->remaining);

        $discarded = $this->submit($second, $this->form($next->getContent()) + ['assessment' => 'discarded', 'discard_reason' => 'duplicate']);
        self::assertSame(303, $discarded->getStatusCode());
        self::assertSame('duplicate', $this->state($second)['discard_reason']);
        $last = $this->request($discarded->headers->get('Location'));
        self::assertStringContainsString('/review/'.$third->getId().'/assessment', $last->getContent());
        self::assertSame('fixture private note', $this->state($first)['private_notes']);
        self::assertNull($this->state($first)['last_retested_at']);
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
    }

    public function testNotVulnerableStoresFixedManualJudgmentWithBasisAndAdvancesWithoutTechnicalChanges(): void
    {
        $finding = $this->finding('not-vulnerable')->setLastRetestedAt(new \DateTimeImmutable('2026-01-02 12:00:01'))
            ->setContactedAt(new \DateTimeImmutable('2026-01-03 09:00:00'));
        $image = $this->image($finding);
        $run = $this->observation($finding, 'inconclusive');
        $job = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus('available')
            ->setScreenshotPath($image->getFilePath())->setCapturedAt(new \DateTimeImmutable('2026-01-02 12:00:30'))
            ->setCaptureMetadata(['fixture' => true]);
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        $next = $this->finding('after-fixed');
        $this->image($next);
        $before = $this->snapshot();
        $parameters = $this->form($this->request('/review')->getContent());
        $response = $this->submit($finding, array_replace($parameters, [
            'assessment' => 'fixed', 'evidence_id' => $image->getId(), 'observation_id' => $run->getId(),
        ]));
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('after='.$finding->getId(), $response->headers->get('Location'));
        self::assertStringContainsString(rawurlencode('Not vulnerable bestätigt.'), $response->headers->get('Location'));
        $state = $this->state($finding);
        self::assertSame('fixed', $state['manual_assessment']);
        self::assertSame('fixed', $state['status']);
        self::assertSame('confirmed_fixed', $state['review_state']);
        self::assertNotNull($state['assessed_at']);
        $history = $this->history($finding);
        self::assertCount(1, $history);
        self::assertSame('fixed', $history[0]['assessment']);
        self::assertSame($run->getId(), $history[0]['observation_id']);
        self::assertSame($image->getId(), $history[0]['evidence_id']);
        self::assertSame('inconclusive', json_decode($history[0]['reference_snapshot'], true)['observation']['result']);
        $after = $this->snapshot();
        foreach (['evidence', 'retest_run', 'screenshot_job'] as $table) {
            self::assertSame($before[$table], $after[$table]);
        }
        $oldFinding = array_values(array_filter($before['finding'], static fn (array $row): bool => $row['id'] === $finding->getId()))[0];
        foreach (['url', 'private_notes', 'contacted_at', 'last_retested_at', 'notified_owner_at'] as $field) {
            self::assertSame($oldFinding[$field], $state[$field]);
        }
        $nextResponse = $this->request($response->headers->get('Location'));
        self::assertStringContainsString('/review/'.$next->getId().'/assessment', $nextResponse->getContent());
        self::assertNull($this->state($next)['manual_assessment']);
        self::assertSame(1, self::getContainer()->get(ReviewQueueService::class)->get([])->counts['all']);
        $inventory = $this->request('/findings?scope=active&assessment=fixed');
        self::assertSame(200, $inventory->getStatusCode());
        self::assertStringContainsString('/findings/'.$finding->getId(), $inventory->getContent());
    }

    public function testCsrfValidationAndInvalidInputsStayOnCurrentCardWithoutSaving(): void
    {
        $finding = $this->finding('validation');
        $this->image($finding);
        $parameters = $this->form($this->request('/review')->getContent());
        $before = $this->snapshot();
        foreach ([
            [403, ['_token' => 'wrong', 'assessment' => 'confirmed']],
            [400, ['assessment' => 'invented']],
            [400, ['assessment' => ['confirmed']]],
            [400, ['assessment' => 'discarded', 'discard_reason' => 'invented']],
            [400, ['assessment' => 'confirmed', 'discard_reason' => 'duplicate']],
            [400, ['assessment' => 'confirmed', 'kind' => ['all']]],
            [409, ['assessment' => 'confirmed', 'context_token' => 'forged']],
        ] as [$status, $override]) {
            $response = $this->submit($finding, array_replace($parameters, $override));
            self::assertSame($status, $response->getStatusCode());
            self::assertNull($response->headers->get('Location'));
            self::assertStringContainsString('/review/'.$finding->getId().'/assessment', $response->getContent());
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testChangedObservationAndConcurrentDecisionRejectOldForms(): void
    {
        $finding = $this->finding('changed-run');
        $this->image($finding);
        $parameters = $this->form($this->request('/review')->getContent());
        $this->observation($finding, 'inconclusive');
        $before = $this->snapshot();
        $stale = $this->submit($finding, $parameters + ['assessment' => 'fixed']);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame($before, $this->snapshot());
        $fresh = $this->form($stale->getContent());
        $saved = $this->submit($finding, $fresh + ['assessment' => 'fixed']);
        self::assertSame(303, $saved->getStatusCode());
        $beforeReplay = $this->snapshot();
        $replay = $this->submit($finding, $fresh + ['assessment' => 'discarded']);
        self::assertSame(409, $replay->getStatusCode());
        self::assertStringContainsString('/findings/'.$finding->getId(), $replay->getContent());
        self::assertSame($beforeReplay, $this->snapshot());
        self::assertCount(1, $this->history($finding));
    }

    public function testSameObservationIdEditedElsewhereIsRefreshedBeforeIssuingNewContext(): void
    {
        $finding = $this->finding('edited-observation');
        $this->image($finding);
        $run = $this->observation($finding, 'inconclusive');
        $queue = self::getContainer()->get(ReviewQueueService::class);
        $view = $queue->get([]);
        self::assertSame('inconclusive', $view->detail->assessmentState->latestRun->getResult());
        $oldForm = $this->form($this->request('/review')->getContent());
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE retest_run SET result = ?, observed_evidence = ?, error_message = ? WHERE id = ?',
            ['error', 'edited elsewhere', 'new external result', $run->getId()],
        );
        $freshView = $queue->get([]);
        self::assertSame('error', $freshView->detail->assessmentState->latestRun->getResult());
        self::assertSame('edited elsewhere', $freshView->detail->assessmentState->latestRun->getObservedEvidence());
        self::assertNotSame($view->stateFingerprint, $freshView->stateFingerprint);
        $before = $this->snapshot();
        $stale = $this->submit($finding, $oldForm + ['assessment' => 'confirmed']);
        self::assertSame(409, $stale->getStatusCode());
        self::assertStringContainsString('data-result="error"', $stale->getContent());
        self::assertSame($before, $this->snapshot());
        $saved = $this->submit($finding, $this->form($stale->getContent()) + ['assessment' => 'confirmed']);
        self::assertSame(303, $saved->getStatusCode());
        self::assertCount(1, $this->history($finding));
    }

    public function testExplicitBasisMustBelongToCaseAndDisplayedImageDoesNotChooseBasis(): void
    {
        $finding = $this->finding('basis');
        $firstImage = $this->image($finding);
        $secondImage = $this->image($finding);
        $run = $this->observation($finding, 'inconclusive');
        $other = $this->finding('other');
        $otherImage = $this->image($other);
        $otherRun = $this->observation($other, 'inconclusive');
        $parameters = $this->form($this->request('/review?evidence='.$secondImage->getId())->getContent());
        $parameters['displayed_evidence_id'] = $secondImage->getId();
        $before = $this->snapshot();
        foreach ([['evidence_id' => $otherImage->getId()], ['observation_id' => $otherRun->getId()]] as $foreign) {
            $response = $this->submit($finding, array_replace($parameters, $foreign, ['assessment' => 'confirmed']));
            self::assertSame(400, $response->getStatusCode());
            self::assertSame($secondImage->getId(), $this->form($response->getContent())['displayed_evidence_id']);
            self::assertSame($before, $this->snapshot());
        }
        $response = $this->submit($finding, array_replace($parameters, [
            'assessment' => 'confirmed', 'observation_id' => $run->getId(), 'evidence_id' => $firstImage->getId(),
        ]));
        self::assertSame(303, $response->getStatusCode());
        $history = $this->history($finding)[0];
        self::assertSame($firstImage->getId(), $history['evidence_id']);
        self::assertSame($run->getId(), $history['observation_id']);
        self::assertSame('inconclusive', json_decode($history['reference_snapshot'], true)['observation']['result']);
    }

    public function testDisappearedImageAndPersistenceFailureDoNotAdvance(): void
    {
        $finding = $this->finding('missing-after-render');
        $image = $this->image($finding);
        $parameters = $this->form($this->request('/review')->getContent());
        self::getContainer()->get(EvidenceStorageInterface::class)->deleteFile($image->getFilePath());
        $before = $this->snapshot();
        $response = $this->submit($finding, array_replace($parameters, ['assessment' => 'fixed', 'evidence_id' => $image->getId()]));
        self::assertSame(409, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
        self::assertStringContainsString('/review/'.$finding->getId().'/assessment', $response->getContent());
        self::assertSame($before, $this->snapshot());

        // A real SQLite failure during the atomic history write exercises the
        // closed-EntityManager fallback, rather than mocking the implementation.
        $parameters = $this->form($this->request('/review?images=all')->getContent());
        $this->entityManager->getConnection()->executeStatement("CREATE TRIGGER reject_review_history BEFORE INSERT ON finding_assessment BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $response = $this->submit($finding, array_replace($parameters, ['assessment' => 'fixed']));
        self::assertSame(500, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
        self::assertStringContainsString('/review/'.$finding->getId().'/assessment', $response->getContent());
        self::assertNull($this->state($finding)['manual_assessment']);
        self::assertSame([], $this->history($finding));
    }

    public function testEmptyAndInvalidSelectionsNeverCreateWork(): void
    {
        $before = $this->snapshot();
        self::assertSame(200, $this->request('/review')->getStatusCode());
        foreach (['kind=other', 'images=other', 'kind[]=all', 'after=broken', 'after=00000000-0000-4000-8000-000000000000', 'reviewed[]=broken'] as $query) {
            self::assertSame(400, $this->request('/review?'.$query)->getStatusCode());
        }
        self::assertSame(404, $this->request('/review/broken/assessment', 'POST')->getStatusCode());
        self::assertSame($before, $this->snapshot());
    }

    public function testReviewDetailAndNotesKeepValidatedReturnAndImageContext(): void
    {
        $finding = $this->finding('return');
        $image = $this->image($finding);
        $path = '/review?kind=unchecked&images=all&after='.$finding->getId().'&evidence='.$image->getId();
        $navigation = self::getContainer()->get(FindingNavigation::class);
        self::assertSame($path, $navigation->listReturnPath($path));
        foreach (['https://example.test/review', '//example.test/review', '/review?kind[]=all', '/review?after=broken', '/review?evidence=broken', '/review#fragment', '/review?images=other'] as $invalid) {
            self::assertNull($navigation->listReturnPath($invalid));
        }
        $detailPath = '/findings/'.$finding->getId().'?'.http_build_query(['return_to' => $path], '', '&', PHP_QUERY_RFC3986);
        $response = $this->request($detailPath);
        self::assertStringContainsString('Zur Review', $response->getContent());
        $xpath = $this->xpath($response->getContent());
        $token = $xpath->evaluate('string(//form[contains(@action,"/notes")]//input[@name="_token"]/@value)');
        $saved = $this->request('/findings/'.$finding->getId().'/notes', 'POST', [
            '_token' => $token, 'surface' => 'studio', 'return_to' => $path, 'notes' => 'updated via normal detail',
        ]);
        self::assertSame(302, $saved->getStatusCode());
        self::assertStringStartsWith($detailPath.'&message=', $saved->headers->get('Location'));
        $assessmentToken = $xpath->evaluate('string(//form[contains(@action,"/assessment")]//input[@name="_token"]/@value)');
        $assessed = $this->request('/findings/'.$finding->getId().'/assessment', 'POST', [
            '_token' => $assessmentToken, 'surface' => 'studio', 'return_to' => $path, 'assessment' => 'confirmed',
        ]);
        self::assertSame(302, $assessed->getStatusCode());
        self::assertStringStartsWith($detailPath.'&message=', $assessed->headers->get('Location'));
        self::assertCount(1, $this->history($finding));
    }

    private function finding(string $label): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'localhost']);
        if (!$domain instanceof Domain) {
            $domain = (new Domain())->setHostname('localhost')->setScheme('http');
            $this->entityManager->persist($domain);
        }
        $finding = (new Finding())->setDomain($domain)->setTitle($label)->setType('other')->setSeverity('medium')
            ->setUrl('http://localhost/fixture/'.rawurlencode($label))->setMethod('GET')->setStatus('new')->setPrivateNotes('fixture private note');
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement('UPDATE finding SET created_at = ? WHERE id = ?', [sprintf('2026-01-%02d 12:00:00', ++$this->sequence), $finding->getId()]);

        return $finding;
    }

    private function observation(Finding $finding, string $result): RetestRun
    {
        $run = (new RetestRun())->setFinding($finding)->setResult($result)->setMode('browser')
            ->setStartedAt(new \DateTimeImmutable('2026-01-02 12:00:00'))->setFinishedAt(new \DateTimeImmutable('2026-01-02 12:00:01'));
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function image(Finding $finding): Evidence
    {
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, base64_decode(self::PNG, true), 'fixture.png');
        $image = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($stored->relativePath);
        $this->entityManager->persist($image);
        $this->entityManager->flush();

        return $image;
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function submit(Finding $finding, array $parameters): Response
    {
        return $this->request('/review/'.$finding->getId().'/assessment', 'POST', $parameters);
    }

    private function form(string $html): array
    {
        $fields = [];
        foreach ($this->xpath($html)->query('//form[starts-with(@action,"/review/")]//input[@type="hidden"]') as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        self::assertNotEmpty($fields['_token'] ?? null);
        self::assertNotEmpty($fields['context_token'] ?? null);

        return $fields;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    private function state(Finding $finding): array
    {
        return $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$finding->getId()]);
    }

    private function history(Finding $finding): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM finding_assessment WHERE finding_id = ?', [$finding->getId()]);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['domain', 'finding', 'finding_assessment', 'evidence', 'retest_run', 'screenshot_job', 'setting'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $snapshot;
    }
}
