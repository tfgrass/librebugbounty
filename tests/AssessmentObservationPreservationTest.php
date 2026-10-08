<?php

namespace App\Tests;

use App\Command\FindingAssessCommand;
use App\Dto\RetestResultData;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Repository\FindingRepository;
use App\Repository\RetestRunRepository;
use App\Service\BrowserRetestClientInterface;
use App\Service\DomainService;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use App\Service\RetestService;
use App\Service\ReviewService;
use App\Service\ScreenshotQueueService;
use App\Service\ValidationService;
use App\Tests\Support\ReviewBrowserTransportStub;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;

final class AssessmentObservationPreservationTest extends DatabaseTestCase
{
    public static function decisionsAndObservations(): iterable
    {
        foreach (['confirmed', 'fixed', 'discarded', 'legacy-confirmed', 'legacy-fixed'] as $decision) {
            foreach (['still_vulnerable', 'fixed', 'inconclusive', 'error'] as $result) {
                yield $decision.' / '.$result => [$decision, $result];
            }
        }
    }

    #[DataProvider('decisionsAndObservations')]
    public function testNewObservationKeepsDecisionContactNotesAndEvidence(string $decision, string $result): void
    {
        $finding = $this->finding();
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $stored = $storage->storeContents($finding, 'fixture image', 'old.png');
        $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($stored->relativePath);
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();

        if (str_starts_with($decision, 'legacy-')) {
            $finding->setStatus($decision === 'legacy-fixed' ? 'fixed' : 'verified');
            $finding->setReviewState($decision === 'legacy-fixed' ? 'confirmed_fixed' : 'manually_checked');
            $this->entityManager->flush();
        } else {
            self::getContainer()->get(FindingService::class)->assess($finding, $decision, $decision === 'discarded' ? 'duplicate' : null);
        }
        $before = $this->assessmentSnapshot($finding);
        $historyCount = $this->entityManager->getRepository(FindingAssessment::class)->count(['finding' => $finding]);
        $run = self::getContainer()->get(RetestService::class)->recordBrowserResult($finding, new RetestResultData(result: $result));
        $this->entityManager->clear();
        $loaded = $this->entityManager->find(Finding::class, $finding->getId());

        self::assertSame($before, $this->assessmentSnapshot($loaded));
        self::assertSame($historyCount, $this->entityManager->getRepository(FindingAssessment::class)->count(['finding' => $loaded]));
        self::assertNotNull($loaded->getLastRetestedAt());
        self::assertNotNull($this->entityManager->find(RetestRun::class, $run->getId()));
        self::assertSame('fixture image', $storage->read($stored->relativePath));
        self::assertNotNull($this->entityManager->find(Evidence::class, $evidence->getId()));
    }

    public function testLateObservationReloadsDecisionMadeInAnotherRequest(): void
    {
        $finding = $this->finding();
        $second = new EntityManager($this->entityManager->getConnection(), $this->entityManager->getConfiguration());
        $otherFinding = $second->find(Finding::class, $finding->getId());
        $service = new FindingService(
            self::getContainer()->get(DomainService::class),
            self::getContainer()->get(FindingRepository::class),
            $second,
            self::getContainer()->get(ValidationService::class),
            self::getContainer()->get(EvidenceStorageInterface::class),
        );
        $service->assess($otherFinding, 'discarded', 'duplicate');
        self::assertNull($finding->getManualAssessment(), 'The first request still holds a stale entity.');

        self::getContainer()->get(RetestService::class)->recordBrowserResult($finding, new RetestResultData(result: 'fixed'));
        $this->entityManager->refresh($finding);
        self::assertSame('discarded', $finding->getManualAssessment());
        self::assertSame('discarded', $finding->getStatus());
        self::assertSame('duplicate', $finding->getDiscardReason());
        self::assertSame(1, $this->entityManager->getRepository(FindingAssessment::class)->count(['finding' => $finding]));
    }

    public function testReviewAggregationKeepsManualAssessment(): void
    {
        $finding = $this->finding();
        self::getContainer()->get(FindingService::class)->assess($finding, 'confirmed');
        $before = $this->assessmentSnapshot($finding);
        $browser = new ReviewBrowserTransportStub(['fixed', 'inconclusive']);
        $review = new ReviewService(
            self::getContainer()->get(FindingRepository::class),
            self::getContainer()->get(RetestService::class), $browser, $this->entityManager,
        );
        $review->reviewFinding($finding);
        $this->entityManager->refresh($finding);
        self::assertSame($before, $this->assessmentSnapshot($finding));
        self::assertSame(2, $this->entityManager->getRepository(RetestRun::class)->count(['finding' => $finding]));
    }

    public function testNoStatusUpdateKeepsReviewMarkerAsWell(): void
    {
        $finding = $this->finding();
        self::getContainer()->get(RetestService::class)->recordBrowserResult(
            $finding, new RetestResultData(result: 'inconclusive'), noStatusUpdate: true,
        );
        $this->entityManager->refresh($finding);
        self::assertSame('new', $finding->getStatus());
        self::assertNull($finding->getReviewState());
    }

    public function testDiscardedCasesDoNotStartTechnicalWork(): void
    {
        $finding = $this->finding();
        self::getContainer()->get(FindingService::class)->assess($finding, 'discarded');
        $browser = $this->createMock(BrowserRetestClientInterface::class);
        $browser->expects(self::never())->method('retest');
        $service = new RetestService(
            $this->entityManager, self::getContainer()->get(RetestRunRepository::class), $browser,
            self::getContainer()->get(ValidationService::class), self::getContainer()->get(EvidenceStorageInterface::class),
            new \App\Service\RecheckPolicy(),
        );
        $reviewBrowser = new ReviewBrowserTransportStub(['error']);
        $review = new ReviewService(self::getContainer()->get(FindingRepository::class), $service, $reviewBrowser, $this->entityManager);
        $review->reviewFinding($finding);
        self::assertSame([], $reviewBrowser->browserCalls);
        self::assertSame(0, $this->entityManager->getRepository(RetestRun::class)->count(['finding' => $finding]));
        $this->expectException(\LogicException::class);
        $service->retest($finding);
    }

    public function testCliUsesSameAssessmentAndIndependentContactRules(): void
    {
        $finding = $this->finding();
        $tester = new CommandTester(self::getContainer()->get(FindingAssessCommand::class));
        self::assertSame(0, $tester->execute(['id' => $finding->getId(), 'assessment' => 'discarded', '--duplicate' => true]));
        self::assertSame('discarded', $finding->getManualAssessment());
        self::assertSame('duplicate', $finding->getDiscardReason());
        $contactBefore = $finding->getContactedAt();
        self::assertSame(0, $tester->execute(['id' => $finding->getId(), 'assessment' => 'contacted']));
        self::assertEquals($contactBefore, $finding->getContactedAt());
        self::assertSame(1, $this->entityManager->getRepository(FindingAssessment::class)->count(['finding' => $finding]));
        self::assertSame(2, $tester->execute(['id' => $finding->getId(), 'assessment' => 'confirmed', '--duplicate' => true]));
        $this->entityManager->refresh($finding);
        self::assertSame('discarded', $finding->getManualAssessment());
    }

    public function testDiscardedIntakeDoesNotCreateOrRepairScreenshotWork(): void
    {
        $finding = $this->finding();
        $service = self::getContainer()->get(FindingService::class);
        $service->assess($finding, 'discarded', 'duplicate');
        $before = $this->assessmentSnapshot($finding);
        $existing = $service->createFinding($finding->getUrl());
        self::assertSame($finding->getId(), $existing->getId());
        self::assertSame($before, $this->assessmentSnapshot($existing));
        self::assertSame(0, $this->entityManager->getRepository(ScreenshotJob::class)->count(['finding' => $finding]));

        foreach (['enqueue', 'ensureExists'] as $method) {
            try {
                self::getContainer()->get(ScreenshotQueueService::class)->$method($finding);
                self::fail('A discarded case must not create screenshot work.');
            } catch (\LogicException) {
                self::assertSame(0, $this->entityManager->getRepository(ScreenshotJob::class)->count(['finding' => $finding]));
            }
        }
    }

    public function testStaleDiscardedCaseIsReloadedBeforeStartingWork(): void
    {
        $finding = $this->finding();
        // Simulate a different request discarding a previously selected case.
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE finding SET manual_assessment = 'discarded', status = 'discarded' WHERE id = ?", [$finding->getId()],
        );
        self::assertFalse($finding->isDiscarded());
        $browser = $this->createMock(BrowserRetestClientInterface::class);
        $browser->expects(self::never())->method('retest');
        $service = new RetestService(
            $this->entityManager, self::getContainer()->get(RetestRunRepository::class), $browser,
            self::getContainer()->get(ValidationService::class), self::getContainer()->get(EvidenceStorageInterface::class),
            new \App\Service\RecheckPolicy(),
        );
        try {
            $service->retest($finding);
            self::fail('A stale discarded case must not start technical work.');
        } catch (\LogicException) {
            self::assertTrue($finding->isDiscarded());
        }
        self::assertSame(0, $this->entityManager->getRepository(RetestRun::class)->count(['finding' => $finding]));
    }

    private function finding(): Finding
    {
        $domain = (new Domain())->setHostname('fixture.localhost')->setScheme('http');
        $finding = (new Finding())->setDomain($domain)->setTitle('Local fixture')->setType('other')
            ->setUrl('http://fixture.localhost/case')->setStatus('new')->setPrivateNotes('preserved note')
            ->setContactedAt(new \DateTimeImmutable('2026-01-02'));
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function assessmentSnapshot(Finding $finding): array
    {
        return [
            $finding->getStatus(), $finding->getReviewState(), $finding->getManualAssessment(),
            $finding->getDiscardReason(), $finding->getAssessedAt()?->format(DATE_ATOM),
            $finding->getContactedAt()?->format(DATE_ATOM), $finding->getPrivateNotes(),
        ];
    }
}
