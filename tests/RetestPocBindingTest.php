<?php

namespace App\Tests;

use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Repository\FindingRepository;
use App\Repository\RetestRunRepository;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserRetestTransportInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\RecheckPolicy;
use App\Service\RetestService;
use App\Service\ReviewService;
use App\Service\ValidationService;
use App\Value\RetestResult;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RetestPocBindingTest extends DatabaseTestCase
{
    public function testRetestUsesActivePocAndRecordsItsRevision(): void
    {
        $finding = $this->finding();
        $this->changePoc($finding, 'http://fixture.localhost/selected', 1);
        $this->entityManager->refresh($finding);
        $client = $this->createMock(BrowserRetestClientInterface::class);
        $client->expects(self::once())->method('retest')->with(self::callback(static fn (BrowserRetestRequest $request): bool => $request->url === 'http://fixture.localhost/selected'))
            ->willReturn(new RetestResultData(RetestResult::STILL_VULNERABLE));

        $run = $this->service($client)->retest($finding);

        self::assertSame('http://fixture.localhost/original', $finding->getUrl());
        self::assertSame('http://fixture.localhost/selected', $run->getRawResult()['pocUrl']);
        self::assertSame(1, $run->getRawResult()['pocRevision']);
        self::assertFalse($run->getRawResult()['stalePoc']);
        self::assertSame('verified', $finding->getStatus());
        self::assertNotNull($finding->getLastRetestedAt());
    }

    public function testLateRetestOfOldPocIsHistoryWithoutCurrentProofOrSchedule(): void
    {
        $finding = $this->finding();
        $nextDue = $finding->getNextDueAt();
        $client = $this->createMock(BrowserRetestClientInterface::class);
        $client->method('retest')->willReturnCallback(function (BrowserRetestRequest $request) use ($finding): RetestResultData {
            self::assertSame($finding->getUrl(), $request->url);
            $this->changePoc($finding, 'http://fixture.localhost/new-poc', 1);
            return new RetestResultData(RetestResult::FIXED, dialogText: 'old dialog', screenshotBase64: base64_encode('old image'));
        });

        $run = $this->service($client)->retest($finding, screenshot: true);
        $this->entityManager->refresh($finding);

        self::assertTrue($run->getRawResult()['stalePoc']);
        self::assertSame(0, $run->getRawResult()['pocRevision']);
        self::assertSame('new', $finding->getStatus());
        self::assertNull($finding->getLastRetestedAt());
        self::assertEquals($nextDue, $finding->getNextDueAt());
        self::assertNull($run->getScreenshotPath());
        self::assertSame(0, $this->entityManager->getRepository(Evidence::class)->count(['finding' => $finding]));
        self::assertNotNull($this->entityManager->find(RetestRun::class, $run->getId()));
    }

    public function testSameUrlWithNewRevisionCannotReceiveAnOldResult(): void
    {
        $finding = $this->finding();
        $service = $this->service($this->createMock(BrowserRetestClientInterface::class));
        $context = $service->captureContext($finding);
        $this->changePoc($finding, $finding->getUrl(), 2);

        $run = $service->recordBrowserResult($finding, new RetestResultData(RetestResult::FIXED),
            expectedPocUrl: $context['active_poc_url'], expectedPocRevision: $context['poc_revision'], expectedFindingContext: $context);

        self::assertTrue($run->getRawResult()['stalePoc']);
        self::assertSame('new', $finding->getStatus());
        self::assertNull($finding->getLastRetestedAt());
    }

    public function testMarkerChangeWhileRetestingDoesNotApplyAnOldObservation(): void
    {
        $finding = $this->finding();
        $client = $this->createMock(BrowserRetestClientInterface::class);
        $client->method('retest')->willReturnCallback(function () use ($finding): RetestResultData {
            $this->entityManager->getConnection()->executeStatement('UPDATE finding SET expected_evidence = ? WHERE id = ?', ['new marker', $finding->getId()]);
            return new RetestResultData(RetestResult::FIXED);
        });

        $run = $this->service($client)->retest($finding);

        self::assertFalse($run->getRawResult()['stalePoc']);
        self::assertTrue($run->getRawResult()['staleFindingContext']);
        self::assertSame('new', $finding->getStatus());
        self::assertNull($finding->getLastRetestedAt());
    }

    public function testRecoveryRecordsObservationWithoutOverridingReviewOrSchedule(): void
    {
        $finding = $this->finding();
        $finding->setReviewState('manual_checking');
        $this->entityManager->flush();
        $nextDue = $finding->getNextDueAt();
        $service = $this->service($this->createMock(BrowserRetestClientInterface::class));
        $context = $service->captureContext($finding);

        $run = $service->recordBrowserResult($finding, new RetestResultData(RetestResult::FIXED),
            expectedPocUrl: $context['active_poc_url'], expectedPocRevision: $context['poc_revision'], expectedFindingContext: $context, recovery: true);

        self::assertSame('new', $finding->getStatus());
        self::assertSame('manual_checking', $finding->getReviewState());
        self::assertNull($finding->getLastRetestedAt());
        self::assertEquals($nextDue, $finding->getNextDueAt());
        self::assertTrue($run->getRawResult()['recovery']);
        self::assertFalse($run->getRawResult()['stalePoc']);
    }

    public function testReviewAggregationDoesNotApplyResultsAfterPocChange(): void
    {
        $finding = $this->finding();
        $this->changePoc($finding, 'http://fixture.localhost/first-poc', 1);
        $this->entityManager->refresh($finding);
        $calls = [];
        $transport = $this->createMock(BrowserRetestTransportInterface::class);
        $transport->method('request')->willReturnCallback(function (BrowserRetestRequest $request) use ($finding, &$calls): ResponseInterface {
            $calls[] = $request->url;
            if (count($calls) === 1) {
                $this->changePoc($finding, 'http://fixture.localhost/second-poc', 2);
            }
            return MockResponse::fromRequest('POST', 'http://worker.localhost/retest', [], new MockResponse('{}'));
        });
        $transport->method('decode')->willReturn(new RetestResultData(RetestResult::FIXED));
        $review = new ReviewService(self::getContainer()->get(FindingRepository::class),
            $this->service($this->createMock(BrowserRetestClientInterface::class)), $transport, $this->entityManager);

        $review->reviewFinding($finding);

        self::assertSame(['http://fixture.localhost/first-poc', 'http://fixture.localhost/first-poc'], $calls);
        self::assertSame('new', $finding->getStatus());
        self::assertNull($finding->getReviewState());
        self::assertNull($finding->getLastRetestedAt());
        $runs = $this->entityManager->getRepository(RetestRun::class)->findBy(['finding' => $finding]);
        self::assertCount(2, $runs);
        foreach ($runs as $run) {
            self::assertTrue($run->getRawResult()['stalePoc']);
            self::assertSame(1, $run->getRawResult()['pocRevision']);
        }
    }

    public function testDeferredCasesAreSkippedByOrdinaryReviewAndRetest(): void
    {
        $finding = $this->finding();
        $this->defer($finding);
        $client = $this->createMock(BrowserRetestClientInterface::class);
        $client->expects(self::never())->method('retest');
        $transport = $this->createMock(BrowserRetestTransportInterface::class);
        $transport->expects(self::never())->method('request');
        $service = $this->service($client);
        $review = new ReviewService(self::getContainer()->get(FindingRepository::class), $service, $transport, $this->entityManager);

        self::assertSame([], $review->getPendingFindings());
        $review->reviewFinding($finding);
        self::assertSame(0, $this->entityManager->getRepository(RetestRun::class)->count(['finding' => $finding]));
        $this->expectException(\LogicException::class);
        $service->retest($finding);
    }

    public function testDeferralDuringRetestPreservesTheDeferralAndItsSchedule(): void
    {
        $finding = $this->finding();
        $client = $this->createMock(BrowserRetestClientInterface::class);
        $client->method('retest')->willReturnCallback(function () use ($finding): RetestResultData {
            $this->defer($finding);
            return new RetestResultData(RetestResult::STILL_VULNERABLE, dialogText: 'marker', screenshotBase64: base64_encode('old image'));
        });

        $run = $this->service($client)->retest($finding);

        self::assertTrue($run->getRawResult()['staleFindingContext']);
        self::assertSame('new', $finding->getStatus());
        self::assertNull($finding->getLastRetestedAt());
        self::assertNull($finding->getNextDueAt());
        self::assertNull($run->getScreenshotPath());
        self::assertSame('deferred', $this->entityManager->getConnection()->fetchOne('SELECT state FROM finding_review_disposition WHERE finding_id = ?', [$finding->getId()]));
    }

    public function testDeferringAndResumingInvalidatesAnAlreadyRunningRetest(): void
    {
        $finding = $this->finding();
        $client = $this->createMock(BrowserRetestClientInterface::class);
        $client->method('retest')->willReturnCallback(function () use ($finding): RetestResultData {
            $this->defer($finding);
            $this->entityManager->getConnection()->executeStatement(
                "UPDATE finding_review_disposition SET state = 'active', revision = revision + 1 WHERE finding_id = ?", [$finding->getId()],
            );
            return new RetestResultData(RetestResult::STILL_VULNERABLE, dialogText: 'marker', screenshotBase64: base64_encode('old image'));
        });

        $run = $this->service($client)->retest($finding);

        self::assertTrue($run->getRawResult()['staleFindingContext']);
        self::assertFalse($run->getRawResult()['stalePoc']);
        self::assertSame('new', $finding->getStatus());
        self::assertNull($finding->getReviewState());
        self::assertNull($finding->getLastRetestedAt());
        self::assertNull($finding->getNextDueAt());
        self::assertNull($run->getScreenshotPath());
        self::assertSame(0, $this->entityManager->getRepository(Evidence::class)->count(['finding' => $finding]));
        self::assertSame('active', $this->entityManager->getConnection()->fetchOne('SELECT state FROM finding_review_disposition WHERE finding_id = ?', [$finding->getId()]));
        self::assertNotNull($this->entityManager->find(RetestRun::class, $run->getId()));
    }

    public function testChecksBlockedDuringRetestCannotBecomeCurrentProof(): void
    {
        $finding = $this->finding();
        $client = $this->createMock(BrowserRetestClientInterface::class);
        $client->method('retest')->willReturnCallback(function () use ($finding): RetestResultData {
            $this->entityManager->getConnection()->executeStatement(
                "INSERT INTO domain_work_restriction (hostname, contact_blocked, checks_blocked, changed_at) VALUES (?, 0, 1, ?)",
                [$finding->getDomain()->getHostname(), (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            );
            return new RetestResultData(RetestResult::FIXED);
        });

        $run = $this->service($client)->retest($finding);

        self::assertTrue($run->getRawResult()['staleFindingContext']);
        self::assertSame('new', $finding->getStatus());
        self::assertNull($finding->getLastRetestedAt());
    }

    private function defer(Finding $finding): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            "INSERT INTO finding_review_disposition (finding_id, revision, state, reason, changed_at) VALUES (?, 1, 'deferred', 'unreachable', ?)",
            [$finding->getId(), (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
        $connection->executeStatement('UPDATE finding SET next_due_at = NULL WHERE id = ?', [$finding->getId()]);
    }

    private function finding(): Finding
    {
        $domain = (new Domain())->setHostname('fixture.localhost')->setScheme('http');
        $finding = (new Finding())->setDomain($domain)->setTitle('PoC fixture')->setType('other')->setStatus('new')
            ->setUrl('http://fixture.localhost/original')->setExpectedEvidence('marker')->setNextDueAt(new \DateTimeImmutable('2030-01-02'));
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        return $finding;
    }

    private function changePoc(Finding $finding, string $url, int $revision): void
    {
        $this->entityManager->getConnection()->executeStatement('UPDATE finding SET poc_url = ?, poc_revision = ? WHERE id = ?', [$url, $revision, $finding->getId()]);
    }

    private function service(BrowserRetestClientInterface $client): RetestService
    {
        return new RetestService($this->entityManager, self::getContainer()->get(RetestRunRepository::class), $client,
            self::getContainer()->get(ValidationService::class), self::getContainer()->get(EvidenceStorageInterface::class), new RecheckPolicy());
    }
}
