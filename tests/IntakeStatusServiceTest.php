<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Repository\RetestRunRepository;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\IntakeStatusService;
use App\Value\FindingReadLabels;

final class IntakeStatusServiceTest extends DatabaseTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

    public function testAvailableScreenshotRequiresAnExistingReadableFileAndKeepsMissingReferences(): void
    {
        $finding = $this->finding('available');
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $stored = $storage->storeContents($finding, base64_decode(self::PNG, true), 'fixture.png');
        $job = $this->job($finding, 'available')->setScreenshotPath($stored->relativePath)
            ->setCapturedAt(new \DateTimeImmutable('2026-10-02T12:01:00'));
        $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($stored->relativePath);
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();
        $before = $this->snapshot();

        $status = $this->service()->status($finding);
        self::assertSame('available', $status['screenshot']['state']);
        self::assertSame('Screenshot verfügbar', $status['screenshot']['label']);
        self::assertSame($job->getCapturedAt()->format(DATE_ATOM), $status['screenshot']['capturedAt']);
        self::assertNull($status['screenshot']['error']);
        self::assertSame($before, $this->snapshot());

        $storage->deleteFile($stored->relativePath);
        $beforeMissingRead = $this->snapshot();
        $status = $this->service()->status($finding);
        self::assertSame('failed', $status['screenshot']['state']);
        self::assertSame('Screenshot-Datei fehlt', $status['screenshot']['label']);
        self::assertStringContainsString('Belegverweis bleibt erhalten', $status['screenshot']['error']);
        self::assertSame('available', $job->getStatus());
        self::assertSame($stored->relativePath, $job->getScreenshotPath());
        self::assertSame($beforeMissingRead, $this->snapshot());
        self::assertNotNull($this->entityManager->find(Evidence::class, $evidence->getId()));
    }

    public function testQueuedRunningAndFailedStatesAreDistinctFromTheFindingAssessment(): void
    {
        $finding = $this->finding('states')->setStatus('fixed')
            ->setManualAssessment('fixed', null, new \DateTimeImmutable('2026-10-02T11:00:00'))
            ->setReviewState('confirmed_fixed');
        $job = $this->job($finding, 'queued');
        $this->entityManager->flush();
        $status = $this->service()->status($finding);
        self::assertSame('queued', $status['screenshot']['state']);
        self::assertSame($job->getRequestedAt()->format(DATE_ATOM), $status['screenshot']['requestedAt']);
        self::assertSame('fixed', $status['assessment']['value']);
        $job->setStatus('running')->setStartedAt(new \DateTimeImmutable('2026-10-02T12:01:00'));
        $this->entityManager->flush();
        self::assertSame('running', $this->service()->status($finding)['screenshot']['state']);
        $job->setStatus('failed')->setErrorMessage('Synthetic capture error');
        $this->entityManager->flush();
        $before = $this->snapshot();
        $status = $this->service()->status($finding);
        self::assertSame('failed', $status['screenshot']['state']);
        self::assertSame('Synthetic capture error', $status['screenshot']['error']);
        self::assertSame('fixed', $status['assessment']['value']);
        self::assertSame($before, $this->snapshot());
    }

    public function testLatestScreenshotUsesRequestTimeThenInsertionOrderRatherThanRandomIds(): void
    {
        $finding = $this->finding('latest-job');
        $this->job($finding, 'failed', '2026-10-02T12:00:00', 'ffffffff-ffff-4fff-8fff-ffffffffffff');
        $newer = $this->job($finding, 'queued', '2026-10-02T12:00:00', '00000000-0000-4000-8000-000000000001');
        $this->job($finding, 'running', '2026-10-02T11:59:00');
        $status = $this->service()->status($finding);
        self::assertSame('queued', $status['screenshot']['state']);
        self::assertSame($newer->getRequestedAt()->format(DATE_ATOM), $status['screenshot']['requestedAt']);
        $later = $this->job($finding, 'failed', '2026-10-02T12:02:00')->setErrorMessage('Later stored failure');
        $this->entityManager->flush();
        $status = $this->service()->status($finding);
        self::assertSame('failed', $status['screenshot']['state']);
        self::assertSame('Later stored failure', $status['screenshot']['error']);
        self::assertSame($later->getRequestedAt()->format(DATE_ATOM), $status['screenshot']['requestedAt']);
    }

    public function testDiscardedAndLegacyDiscardedCasesAreIgnoredWithoutCheckingTheirFiles(): void
    {
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->expects(self::never())->method('exists');
        $service = new IntakeStatusService(
            $this->entityManager->getConnection(), self::getContainer()->get(RetestRunRepository::class), $storage,
        );
        foreach (['manual', 'duplicate', 'discarded'] as $kind) {
            $finding = $this->finding('ignored-'.$kind);
            if ($kind === 'manual') {
                $finding->setManualAssessment('discarded', 'duplicate', new \DateTimeImmutable('2026-10-02'));
            } else {
                $finding->setStatus($kind);
            }
            $this->job($finding, 'available')->setScreenshotPath('retained-missing.png');
            $this->entityManager->flush();
            $before = $this->snapshot();
            $status = $service->status($finding);
            self::assertTrue($status['discarded']);
            self::assertSame('discarded', $status['screenshot']['state']);
            self::assertSame('Verworfen', $status['screenshot']['label']);
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testOldEvidenceWithoutAJobDoesNotInventAQueueOrFailedCapture(): void
    {
        $finding = $this->finding('old-evidence')->setStatus('fixed')
            ->setReviewState('confirmed_fixed')->setLastRetestedAt(new \DateTimeImmutable('2026-10-02'));
        $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath('historical-missing.png');
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();
        $before = $this->snapshot();

        $status = $this->service()->status($finding);
        self::assertSame('none', $status['screenshot']['state']);
        self::assertSame('Kein Screenshot-Auftrag gespeichert', $status['screenshot']['label']);
        self::assertNull($status['screenshot']['requestedAt']);
        self::assertNull($status['screenshot']['error']);
        self::assertNull($status['observation']);
        self::assertNull($status['assessment']['value']);
        self::assertSame($before, $this->snapshot());
    }

    public function testTechnicalObservationIsIndependentUsesTheSharedLatestRuleAndNeverStartsWork(): void
    {
        $finding = $this->finding('independent')->setStatus('fixed')->setReviewState('confirmed_fixed')
            ->setManualAssessment('fixed', null, new \DateTimeImmutable('2026-10-02T11:00:00'))
            ->setContactedAt(new \DateTimeImmutable('2026-10-02T11:30:00'));
        $this->observation($finding, 'fixed', '2026-10-02T11:59:00', '2026-10-02T12:00:00', 'ffffffff-ffff-4fff-8fff-ffffffffffff');
        $latest = $this->observation($finding, 'inconclusive', '2026-10-02T11:58:00', '2026-10-02T12:00:00', '00000000-0000-4000-8000-000000000001');
        $browser = $this->createMock(BrowserRetestClientInterface::class);
        $browser->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $browser);
        $capture = $this->createMock(BrowserScreenshotClientInterface::class);
        $capture->expects(self::never())->method('capture');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $capture);
        $before = $this->snapshot();

        $status = $this->service()->status($finding);
        self::assertSame($finding->getId(), $status['id']);
        self::assertSame($finding->getUrl(), $status['url']);
        self::assertSame('/findings/'.$finding->getId(), $status['detailUrl']);
        self::assertFalse($status['discarded']);
        self::assertSame('fixed', $status['assessment']['value']);
        self::assertSame($finding->getAssessedAt()->format(DATE_ATOM), $status['assessment']['assessedAt']);
        self::assertNull($status['assessment']['reason']);
        self::assertSame($latest->getId(), $status['observation']['id']);
        self::assertSame('inconclusive', $status['observation']['result']);
        self::assertSame('browser', $status['observation']['mode']);
        self::assertSame($latest->getFinishedAt()->format(DATE_ATOM), $status['observation']['observedAt']);
        self::assertSame(FindingReadLabels::observation('inconclusive'), $status['observation']['label']);
        self::assertSame($finding->getContactedAt()->format(DATE_ATOM), $status['contactedAt']);
        self::assertSame($before, $this->snapshot());
    }

    private function service(): IntakeStatusService
    {
        return new IntakeStatusService(
            $this->entityManager->getConnection(),
            self::getContainer()->get(RetestRunRepository::class),
            self::getContainer()->get(EvidenceStorageInterface::class),
        );
    }

    private function finding(string $label): Finding
    {
        $domain = (new Domain())->setHostname($label.'.localhost')->setScheme('http');
        $finding = (new Finding())->setDomain($domain)->setTitle('Synthetic '.$label)->setType('synthetic')
            ->setUrl('http://'.$domain->getHostname().'/fixture')->setPrivateNotes('Keep this fixture note');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function job(Finding $finding, string $state, string $requested = '2026-10-02T12:00:00', ?string $id = null): ScreenshotJob
    {
        $job = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus($state)
            ->setRequestedAt(new \DateTimeImmutable($requested));
        if ($id !== null) {
            (new \ReflectionProperty(ScreenshotJob::class, 'id'))->setValue($job, $id);
        }
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    private function observation(Finding $finding, string $result, string $started, ?string $finished, ?string $id = null): RetestRun
    {
        $run = (new RetestRun())->setFinding($finding)->setResult($result)->setMode('browser')
            ->setStartedAt(new \DateTimeImmutable($started))
            ->setFinishedAt($finished !== null ? new \DateTimeImmutable($finished) : null);
        if ($id !== null) {
            (new \ReflectionProperty(RetestRun::class, 'id'))->setValue($run, $id);
        }
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['finding', 'finding_assessment', 'retest_run', 'screenshot_job', 'evidence'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $snapshot;
    }
}
