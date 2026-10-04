<?php

namespace App\Tests;

use App\Dto\FindingReadFilter;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Repository\FindingReadRepository;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingDetailService;
use App\Service\FindingService;
use App\Service\ResetService;
use App\Service\ReviewNoticeService;
use App\Service\ReviewQueueService;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ReviewNoticesTest extends DatabaseTestCase
{
    private Session $session;
    private int $sequence = 0;
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);
        $capture = $this->createMock(BrowserScreenshotClientInterface::class);
        $capture->expects(self::never())->method('capture');
        $capture->expects(self::never())->method('waitUntilReady');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $capture);
    }

    public function testOnlyContradictionsInconclusiveAndErrorReopenActiveJudgments(): void
    {
        $policy = self::getContainer()->get(ReviewNoticeService::class);
        $service = self::getContainer()->get(FindingService::class);
        foreach (['confirmed', 'fixed', 'discarded'] as $judgment) {
            foreach (['still_vulnerable', 'fixed', 'inconclusive', 'error', 'pending'] as $result) {
                $finding = $this->finding($judgment.'-'.$result);
                $service->assess($finding, $judgment);
                $run = $this->observation($finding, $result);
                $expected = $judgment !== 'discarded' && (in_array($result, ['inconclusive', 'error'], true)
                    || ($judgment === 'confirmed' && $result === 'fixed') || ($judgment === 'fixed' && $result === 'still_vulnerable'));
                $notice = $policy->get($finding->getId());
                self::assertSame($expected, ($notice?->observations ?? []) !== [], $judgment.' / '.$result);
                $detail = self::getContainer()->get(FindingDetailService::class)->get($finding->getId(), true);
                self::assertSame($expected, $detail->assessmentState->newerObservation);
                self::assertSame($expected, $detail->assessmentState->needsConfirmation);
                self::assertSame($judgment, $this->state($finding)['manual_assessment']);
                if ($expected) { self::assertSame($run->getId(), $notice->observations[0]['id']); }
            }
        }
        $duplicate = $this->finding('legacy-duplicate')->setManualAssessment('confirmed', null, new \DateTimeImmutable())->setStatus('duplicate');
        $this->observation($duplicate, 'error');
        self::assertNull($policy->get($duplicate->getId()));
    }

    public function testEarlierUnresolvedContradictionSurvivesLaterAgreeingAndPendingRuns(): void
    {
        $finding = $this->finding('cumulative');
        $this->observation($finding, 'inconclusive', '2026-01-01 08:00:00');
        self::getContainer()->get(FindingService::class)->assess($finding, 'confirmed');
        $contradiction = $this->observation($finding, 'fixed', '2026-01-02 08:00:00');
        $error = $this->observation($finding, 'error', '2026-01-03 08:00:00');
        $latest = $this->observation($finding, 'still_vulnerable', '2026-01-04 08:00:00');
        $this->observation($finding, 'pending', '2026-01-05 08:00:00');
        $before = $this->snapshot();
        $view = self::getContainer()->get(ReviewQueueService::class)->get(['kind' => 'changed']);
        self::assertSame(1, $view->counts['changed']);
        self::assertSame(1, $view->total);
        self::assertTrue($view->notice);
        self::assertSame([$error->getId(), $contradiction->getId()], array_column($view->triggeringObservations, 'id'));
        self::assertSame(['error', 'contradiction'], array_column($view->triggeringObservations, 'reason'));
        self::assertNotContains($latest->getId(), array_column($view->triggeringObservations, 'id'));
        $list = self::getContainer()->get(FindingReadRepository::class)->findPage(new FindingReadFilter());
        self::assertTrue($list[0]->reviewNotice);
        self::assertSame(['error', 'contradiction'], $list[0]->noticeReasons);
        self::assertSame($before, $this->snapshot());
    }

    public function testKeepPreservesFindingHistoryAndTechnicalRecordsAndReopensOnNewIdsOrSameIdEdits(): void
    {
        $finding = $this->finding('keep');
        self::getContainer()->get(FindingService::class)->assess($finding, 'confirmed');
        $run = $this->observation($finding, 'fixed');
        $image = $this->entityManager->getRepository(Evidence::class)->findOneBy(['finding' => $finding]);
        $next = $this->finding('next');
        $before = $this->snapshot();
        $form = $this->form($this->request('/review?kind=changed')->getContent());
        $response = $this->submit($finding, array_replace($form, ['assessment' => 'keep', 'observation_id' => $run->getId(), 'evidence_id' => $image->getId()]));
        self::assertSame(303, $response->getStatusCode());
        foreach ($before as $table => $records) {
            if ($table !== 'finding_review_acknowledgement') { self::assertSame($records, $this->snapshot()[$table], $table); }
        }
        $acks = $this->acks($finding);
        self::assertCount(1, $acks);
        self::assertSame($run->getId(), $acks[0]['observation_id']);
        self::assertSame($image->getId(), $acks[0]['evidence_id']);
        self::assertSame([$run->getId()], json_decode($acks[0]['triggering_observation_ids'], true));
        $detail = self::getContainer()->get(FindingDetailService::class)->get($finding->getId(), true);
        self::assertFalse($detail->assessmentState->newerObservation);
        self::assertNotNull($detail->notice->lastAcknowledgedAt);
        $list = self::getContainer()->get(FindingReadRepository::class)->findPage(new FindingReadFilter(q: 'keep'));
        self::assertFalse($list[0]->reviewNotice);
        $policy = self::getContainer()->get(ReviewNoticeService::class);
        self::assertSame([], $policy->get($finding->getId())->observations);
        $this->entityManager->getConnection()->executeStatement('UPDATE retest_run SET observed_evidence = ? WHERE id = ?', ['edited after acknowledgement', $run->getId()]);
        self::assertSame([$run->getId()], array_column($policy->get($finding->getId())->observations, 'id'));
        $new = $this->observation($finding, 'error');
        self::assertCount(2, $policy->get($finding->getId())->observations);
        self::assertContains($new->getId(), array_column($policy->get($finding->getId())->observations, 'id'));
        self::assertSame('confirmed', $this->state($finding)['manual_assessment']);
        self::assertNull($this->state($next)['manual_assessment']);
    }

    public function testKeepRejectsUnseenArrivalForeignBasisReplayAndRealWriteFailureWithoutAdvancing(): void
    {
        $finding = $this->finding('reject-keep');
        $other = $this->finding('other');
        self::getContainer()->get(FindingService::class)->assess($finding, 'fixed');
        $run = $this->observation($finding, 'still_vulnerable');
        $foreign = $this->observation($other, 'error');
        $old = $this->form($this->request('/review?kind=changed')->getContent());
        $before = $this->snapshot();
        $invalid = $this->submit($finding, array_replace($old, ['assessment' => 'keep', 'observation_id' => $foreign->getId()]));
        self::assertSame(400, $invalid->getStatusCode());
        self::assertNull($invalid->headers->get('Location'));
        self::assertSame($before, $this->snapshot());
        $this->observation($finding, 'pending');
        $before = $this->snapshot();
        $stale = $this->submit($finding, $old + ['assessment' => 'keep']);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame($before, $this->snapshot());
        $fresh = $this->form($stale->getContent());
        self::assertSame(303, $this->submit($finding, $fresh + ['assessment' => 'keep'])->getStatusCode());
        $beforeReplay = $this->snapshot();
        self::assertSame(409, $this->submit($finding, $fresh + ['assessment' => 'keep'])->getStatusCode());
        self::assertSame($beforeReplay, $this->snapshot());
        $this->observation($finding, 'inconclusive');
        $fresh = $this->form($this->request('/review?kind=changed')->getContent());
        $beforeFailure = $this->snapshot();
        $this->entityManager->getConnection()->executeStatement("CREATE TRIGGER fail_review_ack BEFORE INSERT ON finding_review_acknowledgement BEGIN SELECT RAISE(ABORT, 'fixture acknowledgement failure'); END");
        $failed = $this->submit($finding, $fresh + ['assessment' => 'keep']);
        self::assertSame(500, $failed->getStatusCode());
        self::assertNull($failed->headers->get('Location'));
        self::assertStringContainsString('/review/'.$finding->getId().'/assessment', $failed->getContent());
        self::assertSame($beforeFailure, $this->snapshot());
        self::assertSame('fixed', $this->state($finding)['manual_assessment']);
        self::assertSame('still_vulnerable', $this->entityManager->getConnection()->fetchOne('SELECT result FROM retest_run WHERE id = ?', [$run->getId()]));
    }

    public function testSameValuedNewJudgmentSupersedesOldAcknowledgementAndStoresFreshBoundary(): void
    {
        $finding = $this->finding('new-boundary');
        $service = self::getContainer()->get(FindingService::class);
        $service->assess($finding, 'confirmed');
        $this->observation($finding, 'fixed');
        $form = $this->form($this->request('/review?kind=changed')->getContent());
        self::assertSame(303, $this->submit($finding, $form + ['assessment' => 'keep'])->getStatusCode());
        $oldAckKey = $this->acks($finding)[0]['decision_key'];
        $finding = $this->entityManager->find(Finding::class, $finding->getId());
        $service->assess($finding, 'confirmed');
        $policy = self::getContainer()->get(ReviewNoticeService::class);
        self::assertNotSame($oldAckKey, $policy->get($finding->getId())->decisionKey);
        self::assertNull($policy->get($finding->getId())->lastAcknowledgedAt);
        self::assertSame([], $policy->get($finding->getId())->observations);
        $later = $this->observation($finding, 'error');
        self::assertSame([$later->getId()], array_column($policy->get($finding->getId())->observations, 'id'));
        self::assertSame(2, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM finding_assessment WHERE finding_id = ?', [$finding->getId()]));
    }

    public function testLegacyDecisionProvenanceUsesKnownIdsOrConservativeTimestampWithoutInventingBasis(): void
    {
        $service = self::getContainer()->get(FindingService::class);
        $policy = self::getContainer()->get(ReviewNoticeService::class);
        $known = $this->finding('known-legacy');
        $oldRun = $this->observation($known, 'inconclusive', '2026-01-01 08:00:00');
        $this->setRunTimes($oldRun, '2026-01-01 08:00:00');
        $service->assess($known, 'confirmed');
        $this->entityManager->getConnection()->executeStatement('UPDATE finding_assessment SET known_observation_states = NULL WHERE finding_id = ?', [$known->getId()]);
        self::assertSame([], $policy->get($known->getId())->observations);
        $later = $this->observation($known, 'error', '2026-01-01 08:00:00');
        self::assertSame([$later->getId()], array_column($policy->get($known->getId())->observations, 'id'));
        $unknown = $this->finding('unknown-legacy')->setManualAssessment('fixed', null, new \DateTimeImmutable('2026-01-02 08:00:00'))->setStatus('fixed');
        $old = $this->observation($unknown, 'error', '2026-01-01 08:00:00');
        $this->setRunTimes($old, '2026-01-01 08:00:00');
        $sameSecond = $this->observation($unknown, 'inconclusive', '2026-01-02 08:00:00');
        $this->setRunTimes($sameSecond, '2026-01-02 08:00:00');
        $notice = $policy->get($unknown->getId());
        self::assertFalse($notice->baselineKnown);
        self::assertSame([$sameSecond->getId()], array_column($notice->observations, 'id'));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM finding_assessment WHERE finding_id = ?', [$unknown->getId()]));
    }

    public function testUnmigratedSchemaRetainsV1ReadsAndManualWritesAndHidesKeep(): void
    {
        $finding = $this->finding('unmigrated');
        $service = self::getContainer()->get(FindingService::class);
        $service->assess($finding, 'confirmed');
        $this->observation($finding, 'inconclusive');
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DROP TABLE finding_review_acknowledgement');
        $connection->executeStatement('ALTER TABLE finding_assessment DROP COLUMN known_observation_states');
        $this->entityManager->clear();
        $finding = $this->entityManager->find(Finding::class, $finding->getId());
        self::assertFalse(self::getContainer()->get(ReviewNoticeService::class)->available());
        $detail = self::getContainer()->get(FindingDetailService::class)->get($finding->getId(), true);
        self::assertCount(1, $detail->assessments);
        self::assertNull($detail->assessments[0]->getKnownObservationStates());
        self::assertSame(200, $this->request('/findings/'.$finding->getId())->getStatusCode());
        $review = $this->request('/review?kind=changed');
        self::assertSame(200, $review->getStatusCode());
        self::assertStringNotContainsString('value="keep"', $review->getContent());
        $finding = $this->entityManager->find(Finding::class, $finding->getId());
        $service->assess($finding, 'fixed');
        self::assertSame('fixed', $this->state($finding)['manual_assessment']);
        $detail = self::getContainer()->get(FindingDetailService::class)->get($finding->getId(), true);
        self::assertCount(2, $detail->assessments);
        self::assertNull($detail->assessments[0]->getReferenceSnapshot());
    }

    public function testDecisionBoundaryWriteFailureRollsBackDirectManualJudgmentAndHistory(): void
    {
        $finding = $this->finding('failed-baseline');
        $this->observation($finding, 'error');
        $before = $this->snapshot();
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("CREATE TRIGGER fail_decision_boundary BEFORE UPDATE OF known_observation_states ON finding_assessment BEGIN SELECT RAISE(ABORT, 'fixture boundary failure'); END");
        try {
            self::getContainer()->get(FindingService::class)->assess($finding, 'confirmed');
            self::fail('A failed observation boundary must not commit a manual judgment.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertSame($before, $this->snapshot());
            self::assertNull($this->state($finding)['manual_assessment']);
        }
    }

    public function testResetPreservesAckProvenanceAndFindingDeletionRemovesAckWithoutForeignKeys(): void
    {
        $finding = $this->finding('reset-ack');
        $service = self::getContainer()->get(FindingService::class);
        $service->assess($finding, 'confirmed');
        $run = $this->observation($finding, 'fixed');
        $form = $this->form($this->request('/review?kind=changed')->getContent());
        self::assertSame(303, $this->submit($finding, array_replace($form, ['assessment' => 'keep', 'observation_id' => $run->getId()]))->getStatusCode());
        $ack = $this->acks($finding);
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        self::getContainer()->get(ResetService::class)->resetVerificationState();
        self::assertSame($ack, $this->acks($finding));
        self::assertSame([], self::getContainer()->get(ReviewNoticeService::class)->get($finding->getId())->observations);
        $new = $this->observation($finding, 'error');
        self::assertSame([$new->getId()], array_column(self::getContainer()->get(ReviewNoticeService::class)->get($finding->getId())->observations, 'id'));
        self::assertSame('fixed', json_decode($ack[0]['reference_snapshot'], true)['observation']['result']);
        $service->deleteFinding($this->entityManager->find(Finding::class, $finding->getId()));
        self::assertSame([], $this->acks($finding));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding_assessment WHERE finding_id = ?', [$finding->getId()]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding WHERE id = ?', [$finding->getId()]));
    }

    public function testAdditiveNoticeMigrationMatchesCurrentMappingAndPreservesOriginalRows(): void
    {
        $finding = $this->finding('migration');
        $run = $this->observation($finding, 'inconclusive');
        self::getContainer()->get(FindingService::class)->assess($finding, 'confirmed', null, $run->getId());
        $connection = $this->entityManager->getConnection();
        // Represent the deployed pre-notice schema in this isolated database.
        $connection->executeStatement('DROP TABLE finding_review_acknowledgement');
        $connection->executeStatement('ALTER TABLE finding_assessment DROP COLUMN known_observation_states');
        $before = [];
        foreach (['finding', 'finding_assessment', 'retest_run', 'evidence', 'screenshot_job'] as $table) {
            $before[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }
        require_once dirname(__DIR__).'/migrations/Version20261003000000.php';
        $migration = new \DoctrineMigrations\Version20261003000000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) { $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes()); }
        foreach ($before as $table => $rows) {
            $after = $connection->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
            self::assertCount(count($rows), $after);
            foreach ($rows as $index => $row) { self::assertSame($row, array_intersect_key($after[$index], $row), $table); }
        }
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding_review_acknowledgement'));
        self::assertNull($connection->fetchOne('SELECT known_observation_states FROM finding_assessment WHERE finding_id = ?', [$finding->getId()]));
        $expected = (new SchemaTool($this->entityManager))->getSchemaFromMetadata($this->entityManager->getMetadataFactory()->getAllMetadata());
        $manager = $connection->createSchemaManager();
        foreach (['finding_assessment', 'finding_review_acknowledgement'] as $table) {
            self::assertTrue($manager->createComparator()->compareTables($expected->getTable($table), $manager->introspectTable($table))->isEmpty(), $table.' must exactly match current Doctrine metadata.');
        }
    }

    private function finding(string $label): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'localhost']);
        if (!$domain instanceof Domain) { $domain = (new Domain())->setHostname('localhost')->setScheme('http'); $this->entityManager->persist($domain); }
        $finding = (new Finding())->setDomain($domain)->setUrl('http://localhost/fixture/'.$label)->setTitle($label)->setType('other')->setSeverity('medium')->setMethod('GET')->setStatus('new')->setPrivateNotes('preserve fixture note');
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement('UPDATE finding SET created_at = ? WHERE id = ?', [sprintf('2026-01-%02d 12:00:00', ++$this->sequence), $finding->getId()]);
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, base64_decode(self::PNG, true), 'fixture.png');
        $this->entityManager->persist((new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($stored->relativePath));
        $this->entityManager->flush();
        return $finding;
    }

    private function observation(Finding $finding, string $result, string $date = '2026-01-01 12:00:00'): RetestRun
    {
        // Symfony resets Doctrine between synthetic HTTP requests; a following
        // fixture write must use this request's managed entity, like production.
        $finding = $this->entityManager->find(Finding::class, $finding->getId());
        $run = (new RetestRun())->setFinding($finding)->setResult($result)->setMode('browser')->setStartedAt(new \DateTimeImmutable($date));
        if ($result !== 'pending') { $run->setFinishedAt(new \DateTimeImmutable($date)); }
        $this->entityManager->persist($run); $this->entityManager->flush(); return $run;
    }

    private function setRunTimes(RetestRun $run, string $date): void
    {
        $this->entityManager->getConnection()->executeStatement('UPDATE retest_run SET created_at = ?, updated_at = ? WHERE id = ?', [$date, $date, $run->getId()]);
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters); $request->setSession($this->session);
        $response = self::$kernel->handle($request); self::$kernel->terminate($request, $response); return $response;
    }

    private function form(string $html): array
    {
        $document = new \DOMDocument(); @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $fields = [];
        foreach ((new \DOMXPath($document))->query('//form[@id="review-assessment-form"]//input[@type="hidden"]') as $input) { $fields[$input->getAttribute('name')] = $input->getAttribute('value'); }
        self::assertNotEmpty($fields['context_token'] ?? null); return $fields;
    }

    private function submit(Finding $finding, array $parameters): Response { return $this->request('/review/'.$finding->getId().'/assessment', 'POST', $parameters); }
    private function state(Finding $finding): array { return $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$finding->getId()]); }
    private function acks(Finding $finding): array { return $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM finding_review_acknowledgement WHERE finding_id = ? ORDER BY id', [$finding->getId()]); }
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['finding', 'finding_assessment', 'finding_review_acknowledgement', 'evidence', 'retest_run', 'screenshot_job'] as $table) { $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id'); }
        return $snapshot;
    }
}
