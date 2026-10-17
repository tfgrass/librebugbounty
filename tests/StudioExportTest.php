<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Repository\FindingReadRepository;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\StudioExportService;
use App\Service\StudioExportProfileService;
use App\Service\EvidenceStorageInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StudioExportTest extends DatabaseTestCase
{
    private Session $session;
    /** @var array<string, Domain> */
    private array $domains = [];

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

    public function testDefaultExportIsReadOnlyScopesDomainsAndOmitsPrivateNotesAndArtifactBytes(): void
    {
        $active = $this->finding('active')->setPrivateNotes('private case note');
        $this->domains['export.test']->setNotes('private domain note');
        $this->finding('archived', 'archive.invalid')->setManualAssessment('discarded', null, new \DateTimeImmutable());
        $this->finding('duplicate', 'duplicate.invalid')->setStatus('duplicate');
        $this->entityManager->persist((new Domain())->setHostname('empty.invalid'));
        $artifact = APP_TEST_ROOT.'/artifacts/'.$active->getId().'/kept image.png';
        mkdir(dirname($artifact), 0700, true);
        file_put_contents($artifact, 'private artifact bytes');
        $evidence = $this->evidence($active, 'storage/artifacts/'.$active->getId().'/kept image.png');
        $evidence->setValue('private evidence content');
        $this->entityManager->flush();
        $before = $this->snapshot();
        $hash = hash_file('sha256', $artifact);

        $preview = $this->request('/export');
        self::assertSame(200, $preview->getStatusCode());
        self::assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        self::assertStringContainsString('data-export-finding-count="1"', $preview->getContent());
        self::assertStringContainsString('data-export-domain-count="1"', $preview->getContent());
        self::assertStringContainsString('formaction="/export/download"', $preview->getContent());

        $response = $this->request('/export/download');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename="librebugbounty-findings-[0-9]{8}-[0-9]{6}\.json"$/', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $json = $this->body($response);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['schemaVersion']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $data['generatedAt']));
        self::assertSame(1, $data['findingCount']);
        self::assertSame(1, $data['domainCount']);
        self::assertSame(['export.test'], array_column($data['domains'], 'hostname'));
        self::assertSame([$active->getId()], array_column($data['findings'], 'id'));
        self::assertFalse($data['includePrivateNotes']);
        self::assertArrayNotHasKey('privateNotes', $data['findings'][0]);
        self::assertSame('/artifacts/'.$active->getId().'/kept%20image.png', $data['findings'][0]['evidence'][0]['artifactUrl']);
        self::assertSame($evidence->getId(), $data['findings'][0]['evidence'][0]['id']);
        foreach (['private case note', 'private domain note', 'private artifact bytes', 'private evidence content', APP_TEST_ROOT, 'retestRuns', 'assessments', 'filePath'] as $omitted) {
            self::assertStringNotContainsString($omitted, $json);
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame($hash, hash_file('sha256', $artifact));
        self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
    }

    public function testAllSelectedFindingsAndEvidenceAreExportedBeyondListAndReadBatchPages(): void
    {
        $ids = [];
        for ($i = 0; $i < 107; ++$i) {
            $finding = $this->finding('batch-'.$i, $i < 100 ? 'export.test' : 'second.test');
            $ids[] = $finding->getId();
        }
        for ($i = 0; $i < 107; ++$i) {
            $this->evidence($finding, null)->setSha256(str_repeat('a', 64));
        }
        $this->entityManager->flush();
        $before = $this->snapshot();
        $query = ['page' => '2', 'pageSize' => '10'];
        $view = self::getContainer()->get(StudioExportService::class)->get($query);
        self::assertSame(107, $view->findingCount);
        self::assertSame(2, $view->domainCount);
        self::assertStringNotContainsString('page', $view->downloadPath);
        $data = $this->download($query);
        self::assertSame(107, $data['findingCount']);
        self::assertCount(107, $data['findings']);
        self::assertSame(2, $data['domainCount']);
        $actual = array_column($data['findings'], 'id');
        sort($ids);
        sort($actual);
        self::assertSame($ids, $actual);
        $records = array_column($data['findings'], null, 'id');
        self::assertCount(107, $records[$finding->getId()]['evidence']);
        self::assertSame($before, $this->snapshot());
    }

    public function testModernManualAssessmentObservationContactAndSentRemainIndependent(): void
    {
        $finding = $this->finding('modern')
            ->setMethod('POST')->setRequestParams(['text' => 'stored & value', 'count' => 2])
            ->setPayload('stored marker')->setExpectedEvidence('expected marker')
            ->setReportUrl('https://report.invalid/case')
            ->setManualAssessment('confirmed', null, new \DateTimeImmutable('2026-10-01 09:00:00'))
            ->setContactedAt(new \DateTimeImmutable('2026-10-01 10:00:00'))
            ->setNotifiedOwnerAt(new \DateTimeImmutable('2026-10-02 11:00:00'))
            ->setStatus('fixed');
        $legacy = $this->finding('legacy')->setStatus('fixed')->setReviewState('confirmed_fixed')
            ->setLastRetestedAt(new \DateTimeImmutable('2026-09-01 08:00:00'));
        $this->observation($finding, 'error', '2026-10-02 12:00:00');
        $latest = $this->observation($finding, 'fixed', '2026-10-02 12:00:00')
            ->setHttpStatus(200)->setFinalUrl('https://final.invalid/stored')
            ->setObservedEvidence('saved observation')->setErrorMessage(null);
        $this->entityManager->persist(new FindingAssessment($finding, 'fixed', null, new \DateTimeImmutable('2026-09-30 08:00:00')));
        $this->entityManager->flush();

        $records = array_column($this->download()['findings'], null, 'id');
        $record = $records[$finding->getId()];
        self::assertSame('confirmed', $record['manualAssessment']['value']);
        self::assertSame((new \DateTimeImmutable('2026-10-01 09:00:00'))->format(DATE_ATOM), $record['manualAssessment']['assessedAt']);
        self::assertSame($latest->getId(), $record['latestObservation']['id']);
        self::assertSame('fixed', $record['latestObservation']['result']);
        self::assertSame('browser', $record['latestObservation']['mode']);
        self::assertSame((new \DateTimeImmutable('2026-10-02 12:00:00'))->format(DATE_ATOM), $record['latestObservation']['observedAt']);
        self::assertSame(200, $record['latestObservation']['httpStatus']);
        self::assertSame('saved observation', $record['latestObservation']['observedEvidence']);
        self::assertSame('https://final.invalid/stored', $record['latestObservation']['finalUrl']);
        self::assertSame((new \DateTimeImmutable('2026-10-01 10:00:00'))->format(DATE_ATOM), $record['contactedAt']);
        self::assertSame((new \DateTimeImmutable('2026-10-02 11:00:00'))->format(DATE_ATOM), $record['sentAt']);
        self::assertSame('fixed', $record['legacy']['status']);
        self::assertSame(['text' => 'stored & value', 'count' => 2], $record['requestParams']);
        self::assertSame('POST', $record['method']);
        self::assertSame('stored marker', $record['payload']);
        self::assertSame('expected marker', $record['expectedEvidence']);
        self::assertNull($records[$legacy->getId()]['manualAssessment']['value']);
        self::assertNull($records[$legacy->getId()]['manualAssessment']['assessedAt']);
        self::assertNull($records[$legacy->getId()]['latestObservation']);
        self::assertNull($records[$legacy->getId()]['contactedAt']);
        self::assertNull($records[$legacy->getId()]['sentAt']);
        self::assertArrayNotHasKey('assessments', $record);
        self::assertArrayNotHasKey('retestRuns', $record);
    }

    public function testEverySelectionUsesInventoryRulesAndUnknownDomainsRemainEmpty(): void
    {
        $manual = $this->finding('literal_%needle', 'alpha.test')->setManualAssessment('confirmed', null, new \DateTimeImmutable())
            ->setContactedAt(new \DateTimeImmutable())->setSubmittedAt(new \DateTimeImmutable('2026-10-02 10:00:00'));
        $this->observation($manual, 'error');
        $this->finding('legacy-fixed', 'beta.invalid')->setStatus('fixed')->setReviewState('confirmed_fixed')
            ->setNotifiedOwnerAt(new \DateTimeImmutable('2026-10-02 10:00:00'));
        $this->finding('discarded', 'archived.test')->setManualAssessment('discarded', null, new \DateTimeImmutable());
        $this->finding('duplicate', 'duplicates.test')->setStatus('duplicate');
        $this->entityManager->flush();
        $service = self::getContainer()->get(StudioExportService::class);
        $repository = self::getContainer()->get(FindingReadRepository::class);
        foreach ([
            [], ['q' => '_%needle'], ['domain' => 'ALPHA.TEST', 'exact_domain' => '1'],
            ['assessment' => 'confirmed', 'observation' => 'error', 'contact' => 'yes', 'sent' => 'no'],
            ['assessment' => 'unknown', 'observation' => 'none', 'sent' => 'yes'],
            ['scope' => 'all'], ['scope' => 'discarded'], ['scope' => 'duplicates'], ['status' => 'duplicate'],
            ['legacy_status' => 'fixed', 'legacy_review' => 'confirmed_fixed'],
            ['event' => 'sent', 'from' => '2026-10-02', 'to' => '2026-10-02'],
            ['event' => 'reported', 'from' => '2026-10-02', 'to' => '2026-10-02', 'tld' => '.test'],
            ['type' => 'stored-case', 'severity' => 'medium'], ['scope' => 'active', 'assessment' => 'discarded'],
            ['domain' => 'absent.invalid'], ['domain' => 'absent.invalid', 'exact_domain' => '1'],
        ] as $query) {
            $view = $service->get($query);
            $data = $this->download($query);
            $expected = array_map(static fn ($item): string => $item->id, $repository->findPage($view->filter, 100));
            self::assertSame($expected, array_column($data['findings'], 'id'), json_encode($query));
            self::assertSame($repository->count($view->filter), $view->findingCount);
            self::assertSame($view->findingCount, $data['findingCount']);
            self::assertSame($view->domainCount, $data['domainCount']);
            self::assertSame($view->filterQuery, $data['filters']);
            if (($query['domain'] ?? '') === 'absent.invalid') {
                self::assertSame(0, $data['findingCount']);
                self::assertSame(0, $data['domainCount']);
                self::assertSame([], $data['domains']);
                self::assertSame([], $data['findings']);
            }
        }
    }

    public function testPrivateNotesRequireExplicitOptInAndUnsafeStoredPathsDoNotEscape(): void
    {
        $finding = $this->finding('notes')->setPrivateNotes("Private Fallnotiz: \"quoted\"\nzweite Zeile");
        foreach ([APP_TEST_ROOT.'/secret.png', 'C:\\private\\secret.png', '../private.png', 'storage/artifacts/../private.png', null] as $path) {
            $this->evidence($finding, $path);
        }
        $this->entityManager->flush();
        foreach ([[], ['include_notes' => '0']] as $query) {
            $data = $this->download($query);
            self::assertFalse($data['includePrivateNotes']);
            self::assertArrayNotHasKey('privateNotes', $data['findings'][0]);
        }
        $data = $this->download(['include_notes' => '1']);
        self::assertTrue($data['includePrivateNotes']);
        self::assertSame($finding->getPrivateNotes(), $data['findings'][0]['privateNotes']);
        self::assertSame([null, null, null, null, null], array_column($data['findings'][0]['evidence'], 'artifactUrl'));
        self::assertStringNotContainsString(APP_TEST_ROOT, json_encode($data));
        $view = self::getContainer()->get(StudioExportService::class)->get(['include_notes' => '1']);
        self::assertTrue($view->includePrivateNotes);
        self::assertStringContainsString('include_notes=1', $view->downloadPath);
        self::assertStringNotContainsString('include_notes', $view->inventoryPath);
    }

    public function testMalformedFiltersFailBeforeStartingDownloadAndPostIsNotAnExportAction(): void
    {
        $this->finding('validation');
        $before = $this->snapshot();
        foreach ([
            'scope=archive', 'assessment=invented', 'observation=invented', 'contact=maybe', 'sent=maybe',
            'q%5B%5D=bad', 'exact_domain=2', 'page=0', 'pageSize=13', 'status=new&legacy_status=fixed',
            'from=2026-10-02', 'event=reported&from=2026-02-31', 'event=reported&from=2026-10-03&to=2026-10-02',
            'include_notes=yes', 'include_notes%5B%5D=1',
        ] as $query) {
            foreach (['/export', '/export/download'] as $path) {
                $response = $this->request($path.'?'.$query);
                self::assertSame(400, $response->getStatusCode(), $path.'?'.$query);
                self::assertFalse($response instanceof StreamedResponse);
                self::assertFalse($response->headers->has('Content-Disposition'));
            }
        }
        foreach (['/export', '/export/download'] as $path) {
            self::assertSame(405, $this->request($path, 'POST')->getStatusCode());
        }
        self::assertSame($before, $this->snapshot());
        self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
    }

    public function testInterruptedStreamingAlwaysClosesReadTransactionAndPreservesData(): void
    {
        $this->finding('interrupted');
        $before = $this->snapshot();
        $service = self::getContainer()->get(StudioExportService::class);
        [$filter, $includeNotes] = $service->parse([]);
        $called = false;
        try {
            $service->writeDownload($filter, $includeNotes, static function (string $part) use (&$called): void {
                $called = true;
                throw new \RuntimeException('Synthetic interrupted output');
            });
            self::fail('Interrupted output must propagate its exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Synthetic interrupted output', $exception->getMessage());
        }
        self::assertTrue($called);
        self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
        self::assertSame($before, $this->snapshot());
        self::assertSame(1, $this->download()['findingCount']);
    }

    public function testDialogObservationIsDistinctFromVisibleImageAndManualClearanceInExport(): void
    {
        $metadata = ['interactive' => true, 'initialChallengeState' => 'detected', 'currentChallengeState' => 'cleared',
            'manualInteraction' => true, 'manualActionCount' => 0, 'dialogSeen' => true,
            'dialogImageCaptured' => false, 'dialogText' => str_repeat('d', 12000),
            'dialogObservation' => ['detectedAt' => '2026-10-17T12:00:00Z', 'pageUrl' => 'https://fixture.invalid/',
                'screenshotBase64' => 'private-image-data', 'captureError' => 'private-worker-details']];
        $provenance = StudioExportProfileService::captureProvenance($metadata);
        self::assertFalse($provenance['manualInteraction']);
        self::assertFalse($provenance['dialogImageCaptured']);
        self::assertSame(10000, mb_strlen($provenance['dialogText']));
        self::assertSame('2026-10-17T12:00:00Z', $provenance['dialogDetectedAt']);
        self::assertStringNotContainsString('private-', json_encode($provenance));
        $description = StudioExportProfileService::captureDescription($provenance, 'de');
        self::assertStringNotContainsString('Nach manueller Bedienung', $description);
        self::assertStringContainsString('sichtbares Abbild ist nicht bestätigt', $description);

        $metadata['dialogImageCaptured'] = true;
        $metadata['dialogCapturedAt'] = '2026-10-17T12:00:01Z';
        $provenance = StudioExportProfileService::captureProvenance($metadata);
        self::assertSame($metadata['dialogCapturedAt'], $provenance['dialogCapturedAt']);
        self::assertStringContainsString('vor dem Schließen als Bild festgehalten', StudioExportProfileService::captureDescription($provenance, 'de'));
        self::assertStringNotContainsString('aktuelle Seite', StudioExportProfileService::captureDescription($provenance, 'de'));
    }

    public function testInteractiveStateProvenanceIsAdditiveAndOldV1RecordsRemainIdentical(): void
    {
        $finding = $this->finding('interactive-state')->setPrivateNotes('private notes')
            ->setManualAssessment('confirmed', null, new \DateTimeImmutable('2026-10-01'));
        $ordinary = $this->evidence($finding, $finding->getId().'/ordinary.png');
        $metadata = $this->interactiveMetadata($finding);
        $image = $this->interactiveImage($finding, $metadata);
        // Same bytes/path without screenshot kind must not inherit provenance.
        $otherKind = $this->evidence($finding, $image->getFilePath())->setKind('text');
        $unrelated = $this->finding('ordinary-case');
        $this->evidence($unrelated, $image->getFilePath());
        $this->observation($finding, 'inconclusive');
        $this->entityManager->flush();
        $before = $this->snapshot();
        $legacyService = self::getContainer()->get(StudioExportService::class);
        [$filter, $notes] = $legacyService->parse(['include_notes' => '1']);
        $legacy = '';
        $legacyService->writeDownload($filter, $notes, static function (string $part) use (&$legacy): void { $legacy .= $part; });
        $legacy = json_decode($legacy, true, 512, JSON_THROW_ON_ERROR);
        $state = $this->download(['include_notes' => '1']);
        $records = array_column($state['findings'], null, 'id');
        $evidence = array_column($records[$finding->getId()]['evidence'], null, 'id');
        self::assertArrayNotHasKey('captureProvenance', $evidence[$ordinary->getId()]);
        self::assertArrayNotHasKey('captureProvenance', $evidence[$otherKind->getId()]);
        self::assertArrayNotHasKey('captureProvenance', $records[$unrelated->getId()]['evidence'][0]);
        $provenance = $evidence[$image->getId()]['captureProvenance'];
        self::assertTrue($provenance['interactive']);
        self::assertTrue($provenance['manual']);
        self::assertSame('fresh-retry', $provenance['context']);
        foreach (['initialChallengeState', 'currentChallengeState', 'initialUrl', 'finalUrl', 'initialHttpStatus', 'currentHttpStatus', 'captureMethod', 'dialogSeen', 'dialogType', 'dialogText'] as $key) {
            self::assertSame($metadata[$key], $provenance[$key], $key);
        }
        self::assertSame($metadata['triggeredBy']['jobId'], $provenance['triggerJobId']);
        self::assertSame($metadata['triggeredBy']['evidenceId'], $provenance['triggerEvidenceId']);
        self::assertArrayNotHasKey('workerSessionId', $provenance);
        $custom = $this->download(['profile' => 'state', 'include_assessment' => '0']);
        $customRecords = array_column($custom['findings'], null, 'id');
        self::assertSame($provenance, array_column($customRecords[$finding->getId()]['evidence'], null, 'id')[$image->getId()]['captureProvenance']);
        self::assertArrayNotHasKey('manualAssessment', $customRecords[$finding->getId()]);
        foreach ($state['findings'] as &$record) {
            foreach ($record['evidence'] as &$item) {
                unset($item['captureProvenance']);
            }
            unset($item);
        }
        unset($record, $state['generatedAt'], $legacy['generatedAt']);
        self::assertSame($legacy, $state);
        self::assertSame($before, $this->snapshot());
    }

    public function testInteractiveReportRetainsCaptureChainWithoutInferringAnAssessmentBasis(): void
    {
        $finding = $this->finding('interactive-report')->setManualAssessment('confirmed', null, new \DateTimeImmutable('2026-10-01'));
        $metadata = $this->interactiveMetadata($finding);
        $image = $this->interactiveImage($finding, $metadata);
        $this->entityManager->flush();
        $before = $this->snapshot();
        foreach (['all', 'latest'] as $mode) {
            [$manifest, $report] = $this->report(['screenshots' => $mode]);
            $record = $manifest['findings'][0];
            self::assertFalse($record['hasKnownAssessmentImageBasis']);
            self::assertNull($record['screenshots'][0]['basisSource']);
            self::assertSame(StudioExportProfileService::captureProvenance($metadata), $record['screenshots'][0]['captureProvenance']);
            self::assertTrue($record['screenshots'][0]['available']);
            self::assertStringContainsString('Nach manueller Bedienung wurde bei der Aufnahme kein Schutzmerkmal erkannt.', $report);
            self::assertStringContainsString('frischen Chromium-Kontext', $report);
            self::assertStringContainsString('keine konkrete Bildgrundlage dokumentiert', $report);
            foreach ([$metadata['initialUrl'], $metadata['finalUrl'], 'trigger-job', 'trigger-evidence', 'initialHttpStatus', 'currentHttpStatus'] as $text) {
                self::assertStringContainsString($text, $report);
            }
            self::assertStringNotContainsString('private-worker-id', json_encode($manifest).$report);
        }
        [$manifest] = $this->report(['screenshots' => 'basis']);
        self::assertSame([], $manifest['findings'][0]['screenshots']);
        self::assertFalse($manifest['findings'][0]['hasKnownAssessmentImageBasis']);
        [$manifest] = $this->report(['screenshots' => 'none']);
        self::assertSame([], $manifest['findings'][0]['screenshots']);
        self::assertSame($before, $this->snapshot());

        // Only an explicit decision establishes this image as the assessment basis.
        $decisionAt = new \DateTimeImmutable('2026-10-03');
        $finding->setManualAssessment('confirmed', null, $decisionAt);
        $this->entityManager->persist(new FindingAssessment($finding, 'confirmed', null, $decisionAt, null, $image->getId()));
        $this->entityManager->flush();
        [$manifest] = $this->report(['screenshots' => 'basis']);
        self::assertTrue($manifest['findings'][0]['hasKnownAssessmentImageBasis']);
        self::assertSame('assessment', $manifest['findings'][0]['screenshots'][0]['basisSource']);
        self::assertSame(StudioExportProfileService::captureProvenance($metadata), $manifest['findings'][0]['screenshots'][0]['captureProvenance']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('interactiveProvenanceStates')]
    public function testPartialAndFailedInspectionsRemainTruthfulInStateAndReport(array $metadata, string $initial, string $current): void
    {
        $finding = $this->finding('partial-provenance');
        $this->interactiveImage($finding, ['interactive' => true] + $metadata);
        $this->entityManager->flush();
        $state = $this->download();
        $provenance = $state['findings'][0]['evidence'][0]['captureProvenance'];
        self::assertSame($initial, $provenance['initialChallengeState']);
        self::assertSame($current, $provenance['currentChallengeState']);
        self::assertNull($provenance['initialUrl']);
        self::assertNull($provenance['initialHttpStatus']);
        self::assertNull($provenance['currentHttpStatus']);
        [$manifest, $report] = $this->report(['screenshots' => 'all']);
        self::assertSame($provenance, $manifest['findings'][0]['screenshots'][0]['captureProvenance']);
        self::assertStringNotContainsString('Nach manueller Bedienung', $report);
    }

    public static function interactiveProvenanceStates(): iterable
    {
        yield 'missing' => [[], 'unknown', 'unknown'];
        yield 'draft cleared' => [['initialChallengeDetected' => true, 'challengeCleared' => true, 'httpStatus' => 403], 'detected', 'cleared'];
        yield 'draft protected' => [['initialChallengeDetected' => true, 'challengeDetected' => true], 'detected', 'detected'];
        yield 'draft false is inconclusive' => [['initialChallengeDetected' => false, 'challengeDetected' => false], 'unknown', 'unknown'];
        yield 'explicit unknown beats old clearance' => [['initialChallengeState' => 'detected', 'currentChallengeState' => 'unknown', 'challengeCleared' => true], 'detected', 'unknown'];
        yield 'inspection error beats cleared' => [['initialChallengeState' => 'detected', 'currentChallengeState' => 'cleared', 'challengeSnapshot' => ['error' => 'failed']], 'detected', 'unknown'];
        yield 'nested error cannot be hidden by outer snapshot' => [['currentChallengeState' => 'cleared', 'challengeSnapshot' => [], 'currentChallenge' => ['snapshot' => ['error' => 'failed']]], 'unknown', 'unknown'];
        yield 'null inspection is unknown' => [['currentChallengeState' => 'cleared', 'currentChallenge' => ['active' => null]], 'unknown', 'unknown'];
        yield 'conflicting inspection is unknown' => [['currentChallengeState' => 'cleared', 'currentChallenge' => ['state' => 'unknown']], 'unknown', 'unknown'];
        yield 'nested inspections' => [['initialChallenge' => ['active' => false], 'currentChallenge' => ['state' => 'unknown']], 'cleared', 'unknown'];
        yield 'malformed states' => [['initialChallengeState' => ['cleared'], 'currentChallengeState' => 'invalid'], 'unknown', 'unknown'];
    }

    public function testReportKeepsProvenanceWhenImageIsMissingAndTreatsStoredMarkupAsLiteral(): void
    {
        $finding = $this->finding('literal provenance');
        $metadata = $this->interactiveMetadata($finding);
        $metadata['finalUrl'] = "https://target.invalid/\n```\n<script>provenanceAttack()</script>\n````\n![image](https://untrusted.invalid/image)";
        $image = $this->interactiveImage($finding, $metadata);
        $this->entityManager->flush();
        self::getContainer()->get(EvidenceStorageInterface::class)->deleteFile($image->getFilePath());
        [$manifest, $report] = $this->report(['screenshots' => 'all']);
        $exported = $manifest['findings'][0]['screenshots'][0];
        self::assertFalse($exported['available']);
        self::assertSame($metadata['finalUrl'], $exported['captureProvenance']['finalUrl']);
        self::assertStringContainsString('`````text', $report);
        $outside = preg_replace('/^(`{3,})text\n[\s\S]*?^\1\n/m', '', $report);
        self::assertStringNotContainsString('<script>', $outside);
        self::assertStringNotContainsString('![image]', $outside);
    }

    private function interactiveMetadata(Finding $finding): array
    {
        return [
            'interactive' => true, 'initialChallengeState' => 'detected', 'currentChallengeState' => 'cleared',
            'manualActionCount' => 3, 'manualInteraction' => true,
            'initialUrl' => $finding->getUrl(), 'finalUrl' => 'https://target.invalid/final',
            'triggeredBy' => ['jobId' => 'trigger-job', 'evidenceId' => 'trigger-evidence'],
            'initialHttpStatus' => 403, 'currentHttpStatus' => 200,
            'captureMethod' => 'interactive-desktop-page', 'dialogSeen' => true,
            'dialogType' => 'alert', 'dialogText' => 'Stored dialog', 'workerSessionId' => 'private-worker-id',
        ];
    }

    private function interactiveImage(Finding $finding, array $metadata): Evidence
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=', true);
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, $png, 'interactive.png');
        $image = $this->evidence($finding, $stored->relativePath)->setSha256($stored->sha256);
        $job = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus('available')
            ->setCapturedAt(new \DateTimeImmutable('2026-10-02'))->setScreenshotPath(substr($stored->relativePath, strlen('storage/artifacts/')))->setCaptureMetadata($metadata);
        $this->entityManager->persist($job);

        return $image;
    }

    /** @return array{array, string} */
    private function report(array $query = []): array
    {
        $service = self::getContainer()->get(StudioExportProfileService::class);
        $path = $service->buildReportArchive($service->parse(['profile' => 'report'] + $query));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        try {
            return [json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR), $zip->getFromName('report.md')];
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    private function finding(string $label, string $hostname = 'export.test'): Finding
    {
        if (!isset($this->domains[$hostname])) {
            $this->domains[$hostname] = (new Domain())->setHostname($hostname)->setScheme('https');
            $this->entityManager->persist($this->domains[$hostname]);
        }
        $finding = (new Finding())->setDomain($this->domains[$hostname])->setTitle($label)
            ->setType('stored-case')->setSeverity('medium')->setUrl('https://'.$hostname.'/stored?case='.rawurlencode($label));
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function observation(Finding $finding, string $result, string $date = '2026-10-02 12:00:00'): RetestRun
    {
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult($result)
            ->setStartedAt(new \DateTimeImmutable($date))->setFinishedAt(new \DateTimeImmutable($date));
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function evidence(Finding $finding, ?string $path): Evidence
    {
        $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($path);
        $this->entityManager->persist($evidence);

        return $evidence;
    }

    private function download(array $query = []): array
    {
        $response = $this->request('/export/download'.($query === [] ? '' : '?'.http_build_query($query)));
        self::assertSame(200, $response->getStatusCode());

        return json_decode($this->body($response), true, 512, JSON_THROW_ON_ERROR);
    }

    private function body(Response $response): string
    {
        self::assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        try {
            $response->sendContent();

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function request(string $path, string $method = 'GET'): Response
    {
        $request = Request::create($path, $method);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['domain', 'finding', 'finding_assessment', 'finding_review_acknowledgement', 'evidence', 'retest_run', 'screenshot_job', 'setting'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $snapshot;
    }
}
