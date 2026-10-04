<?php

namespace App\Tests;

use App\Dto\FindingReadFilter;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Repository\FindingReadRepository;
use App\Repository\StatisticsRepository;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingDetailService;
use App\Service\FindingService;
use App\Service\ResetService;
use App\Service\ReviewQueueService;
use App\Service\ReviewTrail;
use App\Service\StudioExportProfileService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ReviewBackTest extends DatabaseTestCase
{
    private Session $session;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);
        $screenshots = $this->createMock(BrowserScreenshotClientInterface::class);
        $screenshots->expects(self::never())->method('capture');
        $screenshots->expects(self::never())->method('waitUntilReady');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $screenshots);
    }

    public function testRepeatedBackIncludesSkipsResetsPriorJudgmentsAndPreservesEvidenceAndAudit(): void
    {
        $a = $this->finding('a');
        $aImage = $this->image($a, 'second.png');
        $b = $this->finding('b');
        $bImage = $this->image($b, 'second.png');
        self::getContainer()->get(FindingService::class)->assess($b, 'confirmed');
        self::getContainer()->get(FindingService::class)->assess($b, 'fixed', null, null, $bImage->getId());
        $this->observation($b, 'still_vulnerable');
        $c = $this->finding('c');
        $before = $this->snapshot();
        $html = $this->request('/review?evidence='.$aImage->getId())->getContent();
        $saved = $this->postForm($html, 'review-assessment-form', ['assessment' => 'confirmed', 'evidence_id' => $aImage->getId()]);
        self::assertSame(303, $saved->getStatusCode());
        $bPage = $this->request($saved->headers->get('Location'))->getContent();
        $skipped = $this->postForm($bPage, 'review-skip-form', ['displayed_evidence_id' => $bImage->getId()]);
        self::assertSame(303, $skipped->getStatusCode());
        $cPath = $skipped->headers->get('Location');
        $cPage = $this->request($cPath)->getContent();
        $written = $this->snapshot();
        self::assertSame($written, $this->snapshotAfterGet($cPath), 'Reload must never reset case data.');
        self::assertSame(405, $this->request('/review/back')->getStatusCode());
        self::assertSame($written, $this->snapshot());
        $backB = $this->postForm($cPage, 'review-back-form');
        self::assertSame(303, $backB->getStatusCode());
        self::assertStringContainsString('card='.$b->getId(), $backB->headers->get('Location'));
        self::assertStringContainsString('evidence='.$bImage->getId(), $backB->headers->get('Location'));
        self::assertNull($this->state($b)['manual_assessment']);
        self::assertSame('new', $this->state($b)['status']);
        self::assertNull($this->state($b)['review_state']);
        self::assertSame('confirmed', $this->state($a)['manual_assessment']);
        $afterB = $this->snapshot();
        self::assertSame(409, $this->postForm($cPage, 'review-back-form')->getStatusCode());
        self::assertSame($afterB, $this->snapshot(), 'Duplicate Back cannot cascade to A.');
        $returnedB = $this->request($backB->headers->get('Location'))->getContent();
        $backA = $this->postForm($returnedB, 'review-back-form');
        self::assertSame(303, $backA->getStatusCode());
        self::assertStringContainsString('card='.$a->getId(), $backA->headers->get('Location'));
        self::assertStringContainsString('evidence='.$aImage->getId(), $backA->headers->get('Location'));
        self::assertNull($this->state($a)['manual_assessment']);
        self::assertNull($this->state($c)['manual_assessment']);
        self::assertCount(2, $this->rows('finding_assessment_reset'));
        self::assertCount(3, $this->rows('finding_assessment_cancellation'));
        self::assertSame($written['finding_assessment'], $this->rows('finding_assessment'));
        foreach (['evidence', 'retest_run', 'screenshot_job', 'finding_review_acknowledgement'] as $table) {
            self::assertSame($before[$table], $this->rows($table));
        }
        foreach (['private_notes', 'contacted_at', 'notified_owner_at', 'last_retested_at'] as $field) {
            self::assertSame($before['finding'][0][$field], $this->state($a)[$field]);
        }
        $stats = iterator_to_array(self::getContainer()->get(StatisticsRepository::class)->caseFacts());
        foreach ($stats as $fact) { self::assertNull($fact['confirmed_at']); self::assertNull($fact['fixed_at']); }
        self::assertSame(0, self::getContainer()->get(FindingReadRepository::class)->count(new FindingReadFilter(event: 'fixed')));
        self::assertSame(0, self::getContainer()->get(FindingReadRepository::class)->count(new FindingReadFilter(event: 'confirmed')));
        $export = self::getContainer()->get(StudioExportProfileService::class)->get(['profile' => 'report', 'screenshots' => 'basis']);
        self::assertSame(0, $export->screenshotCount, 'Reset judgment cannot continue to provide an export basis.');
        $detail = self::getContainer()->get(FindingDetailService::class)->get($b->getId(), true);
        self::assertCount(1, $detail->reviewResets);
        self::assertSame('fixed', $detail->reviewResets[0]->getPreviousState()['manual_assessment']);
        self::assertArrayHasKey($detail->assessments[0]->getId(), $detail->cancelledAssessmentIds);
        $rendered = $this->request('/findings/'.$b->getId())->getContent();
        self::assertStringContainsString('data-review-reset=', $rendered);
        self::assertStringContainsString('data-cancelled-assessment=', $rendered);
    }

    public function testKeepBackReturnsExactCardOutsideChangedFilterAndAllowsFreshJudgment(): void
    {
        $a = $this->finding('changed');
        $image = $this->image($a, 'basis.png');
        self::getContainer()->get(FindingService::class)->assess($a, 'fixed', null, null, $image->getId());
        $this->observation($a, 'still_vulnerable');
        $html = $this->request('/review?kind=changed')->getContent();
        $kept = $this->postForm($html, 'review-assessment-form', ['assessment' => 'keep', 'evidence_id' => $image->getId()]);
        self::assertSame(303, $kept->getStatusCode());
        $end = $this->request($kept->headers->get('Location'))->getContent();
        $ack = $this->rows('finding_review_acknowledgement');
        self::assertCount(1, $ack);
        $back = $this->postForm($end, 'review-back-form');
        self::assertSame(303, $back->getStatusCode());
        self::assertStringContainsString('kind=changed', $back->headers->get('Location'));
        self::assertSame(0, self::getContainer()->get(ReviewQueueService::class)->get(['kind' => 'changed'])->total);
        $returned = $this->request($back->headers->get('Location'))->getContent();
        self::assertStringContainsString('/review/'.$a->getId().'/assessment', $returned);
        self::assertNull($this->state($a)['manual_assessment']);
        self::assertSame($ack, $this->rows('finding_review_acknowledgement'));
        $skipAgain = $this->postForm($returned, 'review-skip-form');
        self::assertSame(303, $skipAgain->getStatusCode());
        $freshAll = $this->request('/review')->getContent();
        self::assertStringContainsString('/review/'.$a->getId().'/assessment', $freshAll, 'A reset case remains reviewable in a fresh all supply even with a conclusive technical run.');
        self::assertSame(0, self::getContainer()->get(ReviewQueueService::class)->get(['kind' => 'unchecked'])->total, 'A conclusive run must not be relabeled as technically unchecked.');
        $again = $this->postForm($freshAll, 'review-assessment-form', ['assessment' => 'confirmed', 'evidence_id' => $image->getId()]);
        self::assertSame(303, $again->getStatusCode());
        self::assertSame('confirmed', $this->state($a)['manual_assessment']);
        $facts = iterator_to_array(self::getContainer()->get(StatisticsRepository::class)->caseFacts());
        self::assertNotNull($facts[0]['confirmed_at']);
        self::assertNull($facts[0]['fixed_at']);
        self::assertSame(1, self::getContainer()->get(StudioExportProfileService::class)->get(['profile' => 'report', 'screenshots' => 'basis'])->screenshotCount);
    }

    public function testCsrfConcurrentChangesAndOtherTabsCannotResetUnseenData(): void
    {
        $a = $this->finding('a');
        $this->finding('b');
        $first = $this->request('/review')->getContent();
        $otherTab = $this->request('/review')->getContent();
        self::assertNotSame($this->fields($first, 'review-assessment-form')[1]['trail_id'], $this->fields($otherTab, 'review-assessment-form')[1]['trail_id']);
        $saved = $this->postForm($first, 'review-assessment-form', ['assessment' => 'fixed']);
        $next = $this->request($saved->headers->get('Location'))->getContent();
        $before = $this->snapshot();
        self::assertSame(403, $this->postForm($next, 'review-back-form', ['_token' => 'wrong'])->getStatusCode());
        self::assertSame($before, $this->snapshot());
        $ownSession = $this->session;
        $this->session = new Session(new MockArraySessionStorage());
        self::assertSame(409, $this->postForm($next, 'review-back-form')->getStatusCode());
        $this->session = $ownSession;
        self::assertSame($before, $this->snapshot(), 'A trail cannot be replayed from another browser session.');
        self::assertSame(409, $this->postForm($otherTab, 'review-assessment-form', ['assessment' => 'confirmed'])->getStatusCode());
        $service = self::getContainer()->get(FindingService::class);
        $service->assess($service->getFindingOrFail($a->getId()), 'confirmed');
        $afterExternal = $this->snapshot();
        self::assertSame(409, $this->postForm($next, 'review-back-form')->getStatusCode());
        self::assertSame($afterExternal, $this->snapshot());
        self::assertSame('confirmed', $this->state($a)['manual_assessment']);
        self::assertCount(0, $this->rows('finding_assessment_reset'));
    }

    public function testDeletedPreviousStepDoesNotCascadeAndKeepsExactCurrentCard(): void
    {
        $a = $this->finding('a');
        $b = $this->finding('b');
        $c = $this->finding('c');
        $aPage = $this->request('/review')->getContent();
        $bRedirect = $this->postForm($aPage, 'review-skip-form');
        $bPage = $this->request($bRedirect->headers->get('Location'))->getContent();
        $cRedirect = $this->postForm($bPage, 'review-skip-form');
        $cPage = $this->request($cRedirect->headers->get('Location'))->getContent();
        $service = self::getContainer()->get(FindingService::class);
        $service->deleteFinding($service->getFindingOrFail($b->getId()));
        $beforeReload = $this->snapshot();
        $reloaded = $this->request($cRedirect->headers->get('Location'));
        self::assertSame(200, $reloaded->getStatusCode());
        self::assertStringContainsString('/review/'.$c->getId().'/assessment', $reloaded->getContent());
        self::assertSame($beforeReload, $this->snapshot());
        $back = $this->postForm($cPage, 'review-back-form');
        self::assertSame(303, $back->getStatusCode());
        self::assertStringContainsString('card='.$c->getId(), $back->headers->get('Location'));
        self::assertCount(0, $this->rows('finding_assessment_reset'));
        $stillC = $this->request($back->headers->get('Location'));
        self::assertSame(200, $stillC->getStatusCode());
        self::assertStringContainsString('/review/'.$c->getId().'/assessment', $stillC->getContent());
        $backA = $this->postForm($stillC->getContent(), 'review-back-form');
        self::assertSame(303, $backA->getStatusCode());
        self::assertStringContainsString('card='.$a->getId(), $backA->headers->get('Location'));
        self::assertCount(1, $this->rows('finding_assessment_reset'));
    }

    #[DataProvider('resetFailurePoints')]
    public function testResetSqlFailureRollsBackCaseAuditAndCancellationTogether(string $trigger): void
    {
        $a = $this->finding('a');
        $this->finding('b');
        $saved = $this->postForm($this->request('/review')->getContent(), 'review-assessment-form', ['assessment' => 'discarded']);
        $next = $this->request($saved->headers->get('Location'))->getContent();
        $before = $this->snapshot();
        $this->entityManager->getConnection()->executeStatement($trigger);
        self::assertSame(500, $this->postForm($next, 'review-back-form')->getStatusCode());
        self::assertSame($before, $this->snapshot());
        self::assertSame('discarded', $this->state($a)['manual_assessment']);
    }

    public static function resetFailurePoints(): array
    {
        return [
            'reset audit insert' => ["CREATE TRIGGER fail_review_reset BEFORE INSERT ON finding_assessment_reset BEGIN SELECT RAISE(ABORT, 'fixture reset failure'); END"],
            'cancellation insert' => ["CREATE TRIGGER fail_review_reset BEFORE INSERT ON finding_assessment_cancellation BEGIN SELECT RAISE(ABORT, 'fixture reset failure'); END"],
            'case reset update' => ["CREATE TRIGGER fail_review_reset BEFORE UPDATE ON finding WHEN NEW.manual_assessment IS NULL AND OLD.manual_assessment IS NOT NULL BEGIN SELECT RAISE(ABORT, 'fixture reset failure'); END"],
        ];
    }

    public function testTechnicalResetKeepsResetAuditAndDeletionCleansItWithoutForeignKeys(): void
    {
        $a = $this->finding('a');
        self::getContainer()->get(FindingService::class)->assess($a, 'fixed');
        $queue = self::getContainer()->get(ReviewQueueService::class);
        $signature = $queue->resetForReview($a->getId(), $queue->fingerprint($a->getId()), null);
        self::assertSame($queue->fingerprint($a->getId()), $signature);
        $resets = $this->rows('finding_assessment_reset');
        $cancellations = $this->rows('finding_assessment_cancellation');
        self::getContainer()->get(ResetService::class)->resetVerificationState();
        self::assertSame($resets, $this->rows('finding_assessment_reset'));
        self::assertSame($cancellations, $this->rows('finding_assessment_cancellation'));
        $this->entityManager->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        self::getContainer()->get(FindingService::class)->deleteFinding($a);
        self::assertSame([], $this->rows('finding_assessment_reset'));
        self::assertSame([], $this->rows('finding_assessment_cancellation'));
    }

    public function testSessionHistoryIsBoundedAndRestartGetsFreshTrail(): void
    {
        $trails = self::getContainer()->get(ReviewTrail::class);
        $trail = $trails->open($this->session, null);
        for ($i = 0; $i < 205; ++$i) { $trail = $trails->forward($this->session, $trail, ['id' => (string) $i]); }
        self::assertCount(200, $trail['entries']);
        self::assertTrue($trail['truncated']);
        self::assertSame('5', $trail['entries'][0]['id']);
        $this->finding('fresh');
        $html = $this->request('/review')->getContent();
        $trailId = $this->fields($html, 'review-assessment-form')[1]['trail_id'];
        self::assertSame('/review', self::getContainer()->get(ReviewQueueService::class)->get(['trail' => $trailId])->restartPath);
        for ($i = 0; $i < 21; ++$i) { $trails->open($this->session, null); }
        $this->expectException(\UnexpectedValueException::class);
        $trails->open($this->session, $trailId);
    }

    private function finding(string $label): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'review.example.test']);
        if (!$domain) { $domain = (new Domain())->setHostname('review.example.test'); $this->entityManager->persist($domain); }
        $finding = (new Finding())->setDomain($domain)->setTitle($label)->setType('other')->setUrl('https://review.example.test/'.$label)
            ->setPrivateNotes('preserved note')->setContactedAt(new \DateTimeImmutable('2026-01-01'));
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement('UPDATE finding SET created_at = ? WHERE id = ?', [sprintf('2026-01-%02d 12:00:00', ++$this->sequence), $finding->getId()]);
        $this->image($finding, 'first.png');
        return $finding;
    }

    private function image(Finding $finding, string $name): Evidence
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=', true);
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, $bytes, $name);
        $image = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($stored->relativePath)->setSha256(hash('sha256', $bytes));
        $this->entityManager->persist($image); $this->entityManager->flush();
        return $image;
    }

    private function observation(Finding $finding, string $result): void
    {
        $run = (new RetestRun())->setFinding($finding)->setResult($result)->setMode('browser')->setStartedAt(new \DateTimeImmutable('2026-01-01'));
        $this->entityManager->persist($run); $this->entityManager->flush();
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        return $response;
    }

    private function fields(string $html, string $id): array
    {
        $document = new \DOMDocument(); @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $form = $xpath->query('//form[@id="'.$id.'"]')->item(0);
        self::assertNotNull($form, 'Expected native form '.$id);
        $fields = [];
        foreach ($xpath->query('.//input[@type="hidden"]', $form) as $field) { $fields[$field->getAttribute('name')] = $field->getAttribute('value'); }
        return [$form->getAttribute('action'), $fields];
    }

    private function postForm(string $html, string $id, array $changes = []): Response
    {
        [$path, $fields] = $this->fields($html, $id);
        return $this->request($path, 'POST', array_replace($fields, $changes));
    }

    private function state(Finding $finding): array { return $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$finding->getId()]); }
    private function rows(string $table): array { return $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid'); }
    private function snapshotAfterGet(string $path): array { self::assertSame(200, $this->request($path)->getStatusCode()); return $this->snapshot(); }
    private function snapshot(): array
    {
        $result = [];
        foreach (['finding', 'finding_assessment', 'finding_review_acknowledgement', 'finding_assessment_reset', 'finding_assessment_cancellation', 'evidence', 'retest_run', 'screenshot_job'] as $table) { $result[$table] = $this->rows($table); }
        return $result;
    }
}
