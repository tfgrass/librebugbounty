<?php

namespace App\Tests;

use App\Command\ScreenshotMissingCommand;
use App\Dto\BrowserRetestRequest;
use App\Dto\BrowserScreenshotRequest;
use App\Dto\BrowserScreenshotResult;
use App\Dto\RetestResultData;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Repository\ScreenshotJobRepository;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use App\Service\ScreenshotQueueService;
use App\Service\ResetService;
use App\Value\ReviewState;
use App\Value\ScreenshotJobStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Console\Tester\CommandTester;

final class ScreenshotQueueTest extends DatabaseTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

    public function testCaptureCreatesEvidenceWithoutChangingAssessmentOrRetestHistory(): void
    {
        $finding = $this->finding('neutral');
        $finding->setStatus('fixed')
            ->setReviewState(ReviewState::CONFIRMED_FIXED)
            ->setPrivateNotes('Manual note stays')
            ->setContactedAt(new \DateTimeImmutable('2026-01-02'))
            ->setLastRetestedAt(new \DateTimeImmutable('2026-01-03'));
        $this->entityManager->flush();
        $before = $this->findingSnapshot($finding);

        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::once())->method('capture')
            ->with(self::callback(fn (BrowserScreenshotRequest $request): bool => $request->url === $finding->getUrl()))
            ->willReturn(new BrowserScreenshotResult(
                screenshotBase64: self::PNG,
                capturedAt: new \DateTimeImmutable('2026-01-04T12:00:00+00:00'),
                httpStatus: 200,
                finalUrl: $finding->getUrl(),
                dialogSeen: false,
                captureMethod: 'desktop-page',
            ));
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $client);
        $queue = self::getContainer()->get(ScreenshotQueueService::class);

        $enqueued = $queue->enqueue($finding);
        self::assertTrue($enqueued->created);
        self::assertSame(ScreenshotJobStatus::QUEUED, $enqueued->job->getStatus());
        $same = $queue->enqueue($finding);
        self::assertFalse($same->created);
        self::assertSame($enqueued->job->getId(), $same->job->getId());

        $processed = $queue->processNext();
        self::assertInstanceOf(ScreenshotJob::class, $processed);
        self::assertSame(ScreenshotJobStatus::AVAILABLE, $processed->getStatus());
        self::assertSame((new \DateTimeImmutable('2026-01-04T12:00:00+00:00'))->getTimestamp(), $processed->getCapturedAt()?->getTimestamp());
        self::assertNotNull($processed->getScreenshotPath());
        self::assertTrue(self::getContainer()->get(EvidenceStorageInterface::class)->exists($processed->getScreenshotPath()));
        self::assertCount(1, $this->entityManager->getRepository(Evidence::class)->findBy(['finding' => $finding, 'kind' => 'screenshot']));
        self::assertCount(0, $this->entityManager->getRepository(RetestRun::class)->findBy(['finding' => $finding]));
        self::assertSame($before, $this->findingSnapshot($finding));

        $jobId = $processed->getId();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(ScreenshotJob::class, $jobId);
        self::assertSame((new \DateTimeImmutable('2026-01-04T12:00:00+00:00'))->getTimestamp(), $reloaded?->getCapturedAt()?->getTimestamp());
    }

    public function testFailureIsVisibleAndCanBeQueuedAgain(): void
    {
        $finding = $this->finding('failure');
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::once())->method('capture')->willThrowException(new \RuntimeException('Fixture capture failed'));
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $client);
        $queue = self::getContainer()->get(ScreenshotQueueService::class);

        $first = $queue->enqueue($finding)->job;
        $processed = $queue->processNext();
        self::assertSame($first->getId(), $processed?->getId());
        self::assertSame(ScreenshotJobStatus::FAILED, $processed?->getStatus());
        self::assertSame('Fixture capture failed', $processed?->getErrorMessage());
        self::assertNull($processed?->getActiveKey());
        self::assertCount(0, $this->entityManager->getRepository(Evidence::class)->findBy(['finding' => $finding]));

        $managedFinding = $this->entityManager->find(Finding::class, $finding->getId());
        $retry = $queue->enqueue($managedFinding);
        self::assertTrue($retry->created);
        self::assertNotSame($first->getId(), $retry->job->getId());
        self::assertSame(ScreenshotJobStatus::QUEUED, $retry->job->getStatus());
    }

    public function testInterruptedJobIsRecovered(): void
    {
        $finding = $this->finding('recover');
        $job = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())
            ->setStatus(ScreenshotJobStatus::RUNNING)->setActiveKey($finding->getId())
            ->setStartedAt(new \DateTimeImmutable('2026-01-01'))->setAttempts(1);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $repository = self::getContainer()->get(ScreenshotJobRepository::class);
        self::assertSame(1, $repository->recoverInterrupted());
        $recovered = $repository->find($job->getId());
        self::assertSame(ScreenshotJobStatus::QUEUED, $recovered->getStatus());
        self::assertNull($recovered->getStartedAt());
        self::assertStringContainsString('queued again', $recovered->getErrorMessage());
    }

    public function testRepeatedlyInterruptedJobFailsSoLaterWorkCanContinue(): void
    {
        $stuckFinding = $this->finding('stuck');
        $laterFinding = $this->finding('later');
        $stuck = (new ScreenshotJob())->setFinding($stuckFinding)->setUrl($stuckFinding->getUrl())
            ->setStatus(ScreenshotJobStatus::RUNNING)->setActiveKey($stuckFinding->getId())
            ->setStartedAt(new \DateTimeImmutable('2026-01-01'))->setAttempts(3);
        $later = (new ScreenshotJob())->setFinding($laterFinding)->setUrl($laterFinding->getUrl())
            ->setStatus(ScreenshotJobStatus::QUEUED)->setActiveKey($laterFinding->getId());
        $this->entityManager->persist($stuck);
        $this->entityManager->persist($later);
        $this->entityManager->flush();

        $repository = self::getContainer()->get(ScreenshotJobRepository::class);
        self::assertSame(1, $repository->failRepeatedlyInterrupted());
        self::assertSame(0, $repository->recoverInterrupted());
        self::assertSame(ScreenshotJobStatus::FAILED, $repository->find($stuck->getId())?->getStatus());
        self::assertNull($repository->find($stuck->getId())?->getActiveKey());
        self::assertSame($later->getId(), $repository->claimNext()?->getId());
    }

    public function testClaimsJobsInInsertionOrderWhenRequestedTimestampsAreEqual(): void
    {
        $findings = [
            $this->finding('fifo-first'),
            $this->finding('fifo-second'),
            $this->finding('fifo-third'),
        ];
        // Deliberately reverse lexical UUID order: an ID tie-breaker would claim the
        // third job first instead of preserving the order in which they were queued.
        $jobIds = [
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            '88888888-8888-4888-8888-888888888888',
            '00000000-0000-4000-8000-000000000000',
        ];
        $timestamp = '2026-01-01 12:00:00';
        $connection = $this->entityManager->getConnection();

        foreach ($findings as $index => $finding) {
            $connection->executeStatement(
                'INSERT INTO screenshot_job (id, finding_id, url, status, active_key, requested_at, attempts, created_at, updated_at) VALUES (:id, :finding, :url, :status, :active, :requested, 0, :created, :updated)',
                [
                    'id' => $jobIds[$index],
                    'finding' => $finding->getId(),
                    'url' => $finding->getUrl(),
                    'status' => ScreenshotJobStatus::QUEUED,
                    'active' => $finding->getId(),
                    'requested' => $timestamp,
                    'created' => $timestamp,
                    'updated' => $timestamp,
                ],
            );
        }

        $repository = self::getContainer()->get(ScreenshotJobRepository::class);

        self::assertSame($jobIds[0], $repository->claimNext()?->getId());
        self::assertSame($jobIds[1], $repository->claimNext()?->getId());
        self::assertSame($jobIds[2], $repository->claimNext()?->getId());
    }

    public function testUnavailableWorkerLeavesJobQueuedAndUnattempted(): void
    {
        $finding = $this->finding('not-ready');
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::once())->method('waitUntilReady')
            ->willThrowException(new \RuntimeException('Sidecar is starting'));
        $client->expects(self::never())->method('capture');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $client);
        $queue = self::getContainer()->get(ScreenshotQueueService::class);
        $job = $queue->enqueue($finding)->job;

        try {
            $queue->processNext();
            self::fail('Expected readiness failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('Sidecar is starting', $error->getMessage());
        }

        $this->entityManager->refresh($job);
        self::assertSame(ScreenshotJobStatus::QUEUED, $job->getStatus());
        self::assertSame(0, $job->getAttempts());
    }

    public function testDatabaseFailureRemovesFileRecordsFailureAndKeepsQueueUsable(): void
    {
        $finding = $this->finding('database-failure');
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::exactly(2))->method('capture')->willReturn(new BrowserScreenshotResult(
            screenshotBase64: self::PNG,
            capturedAt: new \DateTimeImmutable('2026-01-04T12:00:00+00:00'),
            finalUrl: $finding->getUrl(),
            captureMethod: 'desktop-page',
        ));
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $client);
        $queue = self::getContainer()->get(ScreenshotQueueService::class);
        $first = $queue->enqueue($finding)->job;
        $this->entityManager->getConnection()->executeStatement(
            "CREATE TRIGGER reject_screenshot_evidence BEFORE INSERT ON evidence WHEN NEW.kind = 'screenshot' BEGIN SELECT RAISE(ABORT, 'forced evidence failure'); END"
        );

        $failed = $queue->processNext();
        self::assertSame($first->getId(), $failed?->getId());
        self::assertSame(ScreenshotJobStatus::FAILED, $failed?->getStatus());
        self::assertStringContainsString('forced evidence failure', $failed?->getErrorMessage());
        self::assertSame([], self::getContainer()->get(EvidenceStorageInterface::class)->listPaths());

        $manager = self::getContainer()->get(ManagerRegistry::class)->getManagerForClass(ScreenshotJob::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertTrue($manager->isOpen());
        $manager->getConnection()->executeStatement('DROP TRIGGER reject_screenshot_evidence');

        $secondFinding = (new Finding())
            ->setDomain($manager->find(Domain::class, $finding->getDomain()->getId()))
            ->setTitle('after failure')->setType('other')->setUrl('http://database-failure.localhost/after')
            ->setMethod('GET')->setStatus('new');
        $manager->persist($secondFinding);
        $manager->flush();
        $second = $queue->enqueue($secondFinding)->job;
        self::assertSame(ScreenshotJobStatus::AVAILABLE, $queue->processNext()?->getStatus());
        self::assertSame(ScreenshotJobStatus::AVAILABLE, $manager->find(ScreenshotJob::class, $second->getId())?->getStatus());
    }

    public function testRepeatedIntakeCreatesOneFindingAndOneQueuedScreenshot(): void
    {
        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::once())->method('retest')->with(self::isInstanceOf(BrowserRetestRequest::class))
            ->willReturn(new RetestResultData(result: 'inconclusive'));
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);
        $screenshots = $this->createMock(BrowserScreenshotClientInterface::class);
        $screenshots->expects(self::never())->method('capture');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $screenshots);

        $url = 'http://queue.localhost/fixture';
        $first = $this->post('/findings', ['url' => $url, 'annotate' => 'Original note']);
        self::assertSame(302, $first->getStatusCode());
        $second = $this->post('/findings', ['url' => $url, 'annotate' => 'Must not replace']);
        self::assertSame(302, $second->getStatusCode());
        self::assertStringContainsString('URL%20not%20imported', $second->headers->get('Location'));

        $findings = $this->entityManager->getRepository(Finding::class)->findBy(['url' => $url]);
        self::assertCount(1, $findings);
        self::assertSame('Original note', $findings[0]->getPrivateNotes());
        self::assertCount(1, $this->entityManager->getRepository(ScreenshotJob::class)->findBy(['finding' => $findings[0]]));
    }

    public function testDuplicateIntakeRepairsAHistoricallyMissingScreenshotJob(): void
    {
        $domain = (new Domain())->setHostname('repair.localhost')->setScheme('http');
        $finding = (new Finding())
            ->setDomain($domain)
            ->setTitle('existing')
            ->setType('other')
            ->setUrl('http://repair.localhost/fixture')
            ->setMethod('GET')
            ->setStatus('fixed')
            ->setReviewState(ReviewState::CONFIRMED_FIXED)
            ->setPrivateNotes('Keep the original note');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        self::assertCount(0, $this->entityManager->getRepository(ScreenshotJob::class)->findAll());

        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);

        $response = $this->post('/findings', [
            'url' => $finding->getUrl(),
            'annotate' => 'Must not replace the original note',
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/findings/'.$finding->getId(), $response->headers->get('Location'));
        self::assertStringContainsString('URL%20not%20imported', $response->headers->get('Location'));
        self::assertCount(1, $this->entityManager->getRepository(Finding::class)->findBy(['url' => $finding->getUrl()]));
        $jobs = $this->entityManager->getRepository(ScreenshotJob::class)->findBy(['finding' => $finding]);
        self::assertCount(1, $jobs);
        self::assertSame(ScreenshotJobStatus::QUEUED, $jobs[0]->getStatus());
        self::assertSame('Keep the original note', $finding->getPrivateNotes());
        self::assertSame('fixed', $finding->getStatus());
        self::assertSame(ReviewState::CONFIRMED_FIXED, $finding->getReviewState());
    }

    public function testInitialFindingAndScreenshotJobAreCommittedAtomically(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(<<<'SQL'
CREATE TRIGGER reject_initial_screenshot_job
BEFORE INSERT ON screenshot_job
BEGIN
    SELECT RAISE(ABORT, 'simulated screenshot queue insert failure');
END
SQL);

        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);

        $url = 'http://atomic.localhost/fixture';
        $response = $this->post('/findings', ['url' => $url]);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('simulated%20screenshot%20queue%20insert%20failure', $response->headers->get('Location'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding WHERE url = :url', ['url' => $url]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
    }

    public function testHeadlessVerificationFailureDoesNotRollbackFindingOrScreenshotJob(): void
    {
        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::once())->method('retest')
            ->willThrowException(new \RuntimeException('simulated headless verification failure'));
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);

        $url = 'http://verification-failure.localhost/fixture';
        $response = $this->post('/findings', [
            'url' => $url,
            'annotate' => 'Keep after browser failure',
        ]);

        self::assertSame(302, $response->getStatusCode());
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertStringContainsString('stored for verification-failure.localhost', $query['message'] ?? '');
        self::assertStringContainsString('Screenshot queued', $query['message'] ?? '');
        self::assertStringContainsString('simulated headless verification failure', $query['error'] ?? '');

        $finding = $this->entityManager->getRepository(Finding::class)->findOneBy(['url' => $url]);
        self::assertInstanceOf(Finding::class, $finding);
        self::assertSame('Keep after browser failure', $finding->getPrivateNotes());
        self::assertCount(1, $this->entityManager->getRepository(ScreenshotJob::class)->findBy(['finding' => $finding]));
        self::assertCount(0, $this->entityManager->getRepository(RetestRun::class)->findBy(['finding' => $finding]));
    }

    public function testScreenshotCliOnlyQueuesAndPreservesFindingState(): void
    {
        $finding = $this->finding('cli-queue');
        $finding->setStatus('fixed')
            ->setReviewState(ReviewState::CONFIRMED_FIXED)
            ->setPrivateNotes('keep cli note')
            ->setLastRetestedAt(new \DateTimeImmutable('2026-02-03T12:00:00+00:00'));
        $this->entityManager->flush();
        $before = $this->findingSnapshot($finding);

        $tester = new CommandTester(self::getContainer()->get(ScreenshotMissingCommand::class));
        self::assertSame(0, $tester->execute(['--limit' => 1]));
        self::assertStringContainsString('Queued 1 screenshot', $tester->getDisplay());
        self::assertCount(1, $this->entityManager->getRepository(ScreenshotJob::class)->findBy(['finding' => $finding]));
        self::assertCount(0, $this->entityManager->getRepository(RetestRun::class)->findBy(['finding' => $finding]));
        self::assertSame($before, $this->findingSnapshot($finding));
    }

    public function testVerificationResetRemovesScreenshotJobsBeforeClearingArtifacts(): void
    {
        $finding = $this->finding('reset-queue');
        self::getContainer()->get(ScreenshotQueueService::class)->enqueue($finding);
        $reset = self::getContainer()->get(ResetService::class);
        self::assertSame(1, $reset->preview()['Screenshot jobs deleted']);

        $result = $reset->resetVerificationState();
        self::assertSame(1, $result->screenshotJobsDeleted);
        self::assertCount(0, $this->entityManager->getRepository(ScreenshotJob::class)->findAll());
    }

    public function testFindingDeleteExplicitlyRemovesAllDependentRows(): void
    {
        $finding = $this->finding('delete-queue');
        self::getContainer()->get(ScreenshotQueueService::class)->enqueue($finding);
        $terminal = (new ScreenshotJob())
            ->setFinding($finding)
            ->setUrl($finding->getUrl())
            ->setStatus(ScreenshotJobStatus::FAILED)
            ->setActiveKey(null)
            ->setFinishedAt(new \DateTimeImmutable());
        $evidence = (new Evidence())
            ->setFinding($finding)
            ->setKind('text')
            ->setValue('dependent evidence');
        $retestRun = (new RetestRun())
            ->setFinding($finding)
            ->setMode('browser')
            ->setResult('inconclusive');
        $this->entityManager->persist($terminal);
        $this->entityManager->persist($evidence);
        $this->entityManager->persist($retestRun);
        $this->entityManager->flush();
        self::assertSame(2, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM screenshot_job WHERE finding_id = :finding',
            ['finding' => $finding->getId()],
        ));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM evidence WHERE finding_id = :finding',
            ['finding' => $finding->getId()],
        ));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM retest_run WHERE finding_id = :finding',
            ['finding' => $finding->getId()],
        ));

        self::getContainer()->get(FindingService::class)->deleteFinding($finding);

        self::assertNull($this->entityManager->find(Finding::class, $finding->getId()));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM screenshot_job WHERE finding_id = :finding',
            ['finding' => $finding->getId()],
        ));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM evidence WHERE finding_id = :finding',
            ['finding' => $finding->getId()],
        ));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM retest_run WHERE finding_id = :finding',
            ['finding' => $finding->getId()],
        ));
    }

    public function testDeletedFindingCannotBeQueuedThroughAStaleEntityReference(): void
    {
        $finding = $this->finding('deleted-before-queue');
        $findingId = $finding->getId();
        self::getContainer()->get(FindingService::class)->deleteFinding($finding);

        try {
            self::getContainer()->get(ScreenshotQueueService::class)->enqueue($finding);
            self::fail('Expected the stale finding to be rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame('Finding no longer exists; screenshot job was not queued.', $error->getMessage());
        }

        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM screenshot_job WHERE finding_id = :finding',
            ['finding' => $findingId],
        ));
    }

    private function finding(string $label): Finding
    {
        $domain = new Domain();
        $domain->setHostname($label.'.localhost')->setScheme('http');
        $finding = new Finding();
        $finding->setDomain($domain)->setTitle($label)->setType('other')
            ->setUrl('http://'.$label.'.localhost/fixture')->setMethod('GET')->setStatus('new');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function findingSnapshot(Finding $finding): array
    {
        return [
            'status' => $finding->getStatus(),
            'reviewState' => $finding->getReviewState(),
            'lastRetestedAt' => $finding->getLastRetestedAt()?->format(DATE_ATOM),
            'notes' => $finding->getPrivateNotes(),
            'contactedAt' => $finding->getContactedAt()?->format(DATE_ATOM),
        ];
    }

    private function post(string $url, array $parameters): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create($url, 'POST', $parameters);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }
}
