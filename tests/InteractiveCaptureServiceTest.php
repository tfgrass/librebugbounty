<?php

namespace App\Tests;

use App\Dto\BrowserInteractiveSessionResult;
use App\Dto\BrowserScreenshotResult;
use App\Dto\StoredEvidenceResult;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\FindingFollowUp;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Exception\InteractiveCaptureInvalidatedException;
use App\Service\BrowserInteractiveClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingWorkPolicy;
use App\Service\InteractiveCaptureService;
use App\Service\SettingsService;
use App\Value\EvidenceKind;
use App\Value\ReviewState;
use App\Value\ScreenshotJobStatus;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

final class InteractiveCaptureServiceTest extends DatabaseTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';
    private const SESSION = '0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testSavePersistsNeutralEvidenceWithTimingAndFreshContextProvenance(): void
    {
        [$finding, $context, $trigger, $basis] = $this->fixture();
        $finding->setStatus('fixed')->setReviewState(ReviewState::CONFIRMED_FIXED)
            ->setManualAssessment('fixed', null, new \DateTimeImmutable('2026-01-01'))
            ->setNextDueAt(new \DateTimeImmutable('2026-12-01'))
            ->setLastRetestedAt(new \DateTimeImmutable('2026-01-02'));
        $run = (new RetestRun())->setFinding($finding)->setResult('not_vulnerable')->setFinishedAt(new \DateTimeImmutable('2026-01-02'));
        $this->entityManager->persist($run);
        // An unknown assessment basis must stay unknown; another explicit basis
        // must also remain attached to its original evidence.
        $this->entityManager->persist(new FindingAssessment($finding, 'fixed', null, new \DateTimeImmutable('2026-01-01')));
        $this->entityManager->persist(new FindingAssessment($finding, 'confirmed', null, new \DateTimeImmutable('2025-12-01'), evidenceId: $basis->getId()));
        $active = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())
            ->setStatus(ScreenshotJobStatus::RUNNING)->setActiveKey($finding->getId());
        $this->entityManager->persist($active);
        $this->entityManager->flush();
        $before = $this->neutralSnapshot();

        // The capture service must not flush unrelated pending caller changes.
        $finding->setPrivateNotes('Pending review edit')->setNextDueAt(new \DateTimeImmutable('2027-01-01'));
        $active->setAttempts(8);
        $this->entityManager->getConnection()->executeStatement("CREATE TRIGGER require_unfinished_insert BEFORE INSERT ON screenshot_job WHEN NEW.finished_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'finished before persistence'); END");
        $this->entityManager->getConnection()->executeStatement("CREATE TRIGGER require_evidence_before_finish BEFORE UPDATE OF finished_at ON screenshot_job WHEN NOT EXISTS (SELECT 1 FROM evidence WHERE file_path = NEW.screenshot_path) BEGIN SELECT RAISE(ABORT, 'finished without evidence'); END");

        $capturedAt = null;
        $captureBegan = null;
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::once())->method('captureInteractive')->with(self::SESSION)
            ->willReturnCallback(function () use (&$capturedAt, &$captureBegan): BrowserScreenshotResult {
                $captureBegan = new \DateTimeImmutable();
                $capturedAt = new \DateTimeImmutable('now', new \DateTimeZone('+07:00'));
                return new BrowserScreenshotResult(
                    self::PNG, $capturedAt, 200, 'https://target.example/redirected', true, 'alert', 'manual dialog', 'interactive-desktop-page',
                    ['custom' => ['kept' => true], 'initialUrl' => 'wrong worker URL', 'finalUrl' => 'worker nested URL',
                        'httpStatus' => 418, 'captureMethod' => 'worker nested method', 'sha256' => 'wrong hash',
                        'challenge' => ['state' => 'cleared', 'active' => false, 'snapshot' => ['title' => 'Target']],
                        'initialHttpStatus' => 503, 'interactive' => false],
                );
            });
        $client->expects(self::once())->method('closeInteractive')->with(self::SESSION)->willReturnCallback(function (): void {
            $db = $this->entityManager->getConnection();
            self::assertFalse($db->isTransactionActive());
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM evidence'));
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM screenshot_job WHERE finished_at IS NOT NULL'));
        });

        $saved = $this->service($client)->save($finding, self::SESSION, $context);

        self::assertSame($before, $this->neutralSnapshot($saved->job->getId()));
        self::assertSame('Pending review edit', $finding->getPrivateNotes());
        self::assertSame(8, $active->getAttempts());
        self::assertSame(ScreenshotJobStatus::AVAILABLE, $saved->job->getStatus());
        self::assertNull($saved->job->getActiveKey());
        self::assertSame(1, $saved->job->getAttempts());
        self::assertLessThanOrEqual($captureBegan, $saved->job->getRequestedAt());
        self::assertLessThanOrEqual($captureBegan, $saved->job->getStartedAt());
        self::assertGreaterThanOrEqual($saved->job->getRequestedAt(), $saved->job->getStartedAt());
        self::assertEquals($capturedAt, $saved->job->getCapturedAt());
        self::assertGreaterThanOrEqual($capturedAt, $saved->job->getFinishedAt());
        self::assertSame($saved->evidence->getFilePath(), $saved->job->getScreenshotPath());
        self::assertStringContainsString('interactive-', $saved->job->getScreenshotPath());
        self::assertSame(hash('sha256', base64_decode(self::PNG)), $saved->evidence->getSha256());
        self::assertSame(base64_decode(self::PNG), $this->storage()->read($saved->evidence->getFilePath()));
        self::assertTrue($saved->sessionClosed);
        self::assertNull($saved->sessionCloseError);
        $metadata = $saved->job->getCaptureMetadata();
        self::assertTrue($metadata['interactive']);
        self::assertTrue($metadata['manual']);
        self::assertTrue($metadata['freshContext']);
        self::assertSame($context['start_url'], $metadata['initialUrl']);
        self::assertSame($context['start_url'], $saved->job->getUrl());
        self::assertSame('https://target.example/redirected', $metadata['finalUrl']);
        self::assertSame(['jobId' => $trigger->getId(), 'evidenceId' => $basis->getId()], $metadata['retryOf']);
        self::assertSame($metadata['retryOf'], $metadata['triggeredBy']);
        self::assertSame(403, $metadata['initialHttpStatus']);
        self::assertSame(200, $metadata['currentHttpStatus']);
        self::assertSame('detected', $metadata['initialChallengeState']);
        self::assertSame('cleared', $metadata['currentChallengeState']);
        self::assertTrue($metadata['initialChallengeDetected']);
        self::assertFalse($metadata['challengeDetected']);
        self::assertTrue($metadata['challengeCleared']);
        self::assertSame(['kept' => true], $metadata['custom']);
        self::assertSame('wrong worker URL', $metadata['workerMetadata']['initialUrl']);
        self::assertSame(418, $metadata['workerMetadata']['httpStatus']);
        self::assertSame('worker nested method', $metadata['workerMetadata']['captureMethod']);
        self::assertTrue($metadata['dialogSeen']);
        self::assertSame('alert', $metadata['dialogType']);
        self::assertSame('manual dialog', $metadata['dialogText']);
        self::assertSame($saved->evidence->getSha256(), $metadata['sha256']);

        $this->entityManager->clear();
        $job = $this->entityManager->find(ScreenshotJob::class, $saved->job->getId());
        $evidence = $this->entityManager->find(Evidence::class, $saved->evidence->getId());
        self::assertSame($metadata, $job->getCaptureMetadata());
        self::assertSame($evidence->getFilePath(), $job->getScreenshotPath());
        self::assertSame($capturedAt->getTimestamp(), $job->getCapturedAt()->getTimestamp());
    }

    #[DataProvider('challengeCases')]
    public function testTriStateInspections(array $worker, array $initial, string $initialState, string $currentState): void
    {
        [$finding, $context] = $this->fixture();
        $context['initial_challenge'] = $initial;
        $client = $this->capturingClient($worker);
        $saved = $this->service($client)->save($finding, self::SESSION, $context);
        $metadata = $saved->job->getCaptureMetadata();
        self::assertSame($initialState, $metadata['initialChallengeState']);
        self::assertSame($currentState, $metadata['currentChallengeState']);
        self::assertSame($currentState === 'unknown' ? null : $currentState === 'cleared', $metadata['challengeCleared']);
        self::assertSame($currentState === 'unknown' ? null : $currentState === 'detected', $metadata['challengeDetected']);
        self::assertSame($initialState === 'unknown' ? null : $initialState === 'detected', $metadata['initialChallengeDetected']);
    }

    public static function challengeCases(): iterable
    {
        yield 'still detected' => [['challenge' => ['state' => 'detected', 'active' => true]], ['state' => 'detected', 'active' => true], 'detected', 'detected'];
        yield 'both cleared' => [['challenge' => ['state' => 'cleared', 'active' => false]], ['state' => 'cleared', 'active' => false], 'cleared', 'cleared'];
        yield 'unknown' => [['challenge' => ['state' => 'unknown', 'active' => null]], ['state' => 'unknown'], 'unknown', 'unknown'];
        yield 'missing' => [[], [], 'unknown', 'unknown'];
        yield 'snapshot error overrides false booleans' => [['challengeDetected' => false, 'challengeCleared' => true, 'challengeSnapshot' => ['error' => 'inspection failed']], ['active' => false, 'snapshot' => ['error' => 'failed']], 'unknown', 'unknown'];
        yield 'structured error overrides cleared state' => [['challenge' => ['state' => 'cleared', 'active' => false, 'error' => 'failed']], ['active' => null], 'unknown', 'unknown'];
        yield 'null is unknown despite cleared state' => [['challenge' => ['state' => 'cleared', 'active' => null]], ['state' => 'cleared', 'active' => null], 'unknown', 'unknown'];
        yield 'cleared flag alone proves nothing' => [['challengeCleared' => true], [], 'unknown', 'unknown'];
        yield 'legacy explicit booleans' => [['initialChallengeDetected' => true, 'challengeDetected' => false], [], 'detected', 'cleared'];
        yield 'collapsed inspection error stays unknown' => [['challengeDetected' => false, 'challengeCleared' => false], [], 'unknown', 'unknown'];
        yield 'contradictory inspection stays unknown' => [['challenge' => ['state' => 'cleared', 'active' => true]], ['state' => 'detected', 'active' => false], 'unknown', 'unknown'];
        yield 'recorded initial active beats worker fallback' => [['initialChallengeState' => 'cleared'], ['active' => true], 'detected', 'unknown'];
    }

    #[DataProvider('databaseFailureStages')]
    public function testDatabaseFailureRollsBackBothRecordsAndRemovesOnlyNewFile(string $triggerSql): void
    {
        [$finding, $context] = $this->fixture();
        $before = $this->neutralSnapshot();
        $files = $this->storage()->listPaths();
        $this->entityManager->getConnection()->executeStatement($triggerSql);
        $client = $this->capturingClient();
        $client->expects(self::never())->method('closeInteractive');

        try {
            $this->service($client)->save($finding, self::SESSION, $context);
            self::fail('A failed DB write must not return saved evidence.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('forced interactive failure', $error->getMessage());
        }
        self::assertSame($before, $this->neutralSnapshot());
        self::assertSame($files, $this->storage()->listPaths());
        self::assertSame(1, $this->entityManager->getRepository(Evidence::class)->count([]));
        self::assertSame(1, $this->entityManager->getRepository(ScreenshotJob::class)->count([]));
        self::assertTrue($this->entityManager->isOpen());
        // No delayed unit-of-work insert may appear on a subsequent flush.
        $this->entityManager->flush();
        self::assertSame(1, $this->entityManager->getRepository(Evidence::class)->count([]));
    }

    public static function databaseFailureStages(): iterable
    {
        yield 'job insert' => ["CREATE TRIGGER reject_interactive BEFORE INSERT ON screenshot_job BEGIN SELECT RAISE(ABORT, 'forced interactive failure'); END"];
        yield 'evidence insert' => ["CREATE TRIGGER reject_interactive BEFORE INSERT ON evidence BEGIN SELECT RAISE(ABORT, 'forced interactive failure'); END"];
        yield 'completion update' => ["CREATE TRIGGER reject_interactive BEFORE UPDATE OF finished_at ON screenshot_job BEGIN SELECT RAISE(ABORT, 'forced interactive failure'); END"];
    }

    public function testStorageFailureKeepsWorkerForRetryWithoutCreatingRecords(): void
    {
        [$finding, $context] = $this->fixture();
        $before = $this->neutralSnapshot();
        $client = $this->capturingClient();
        $client->expects(self::never())->method('closeInteractive');
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('exists')->willReturn(true);
        $storage->expects(self::once())->method('storeContents')->willThrowException(new \RuntimeException('storage unavailable'));
        $storage->expects(self::never())->method('deleteFile');
        try {
            $this->service($client, $storage)->save($finding, self::SESSION, $context);
            self::fail('Storage failure must not return evidence.');
        } catch (\RuntimeException $error) {
            self::assertSame('storage unavailable', $error->getMessage());
        }
        self::assertSame($before, $this->neutralSnapshot());
        self::assertSame(1, $this->entityManager->getRepository(Evidence::class)->count([]));
    }

    public function testFailedFileCleanupIsReportedAndWorkerIsKept(): void
    {
        [$finding, $context] = $this->fixture();
        $this->entityManager->getConnection()->executeStatement("CREATE TRIGGER reject_interactive BEFORE INSERT ON evidence BEGIN SELECT RAISE(ABORT, 'forced write failure'); END");
        $local = $this->storage();
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('exists')->willReturn(true);
        $storage->method('storeContents')->willReturnCallback(fn (Finding $finding, string $bytes, string $filename): StoredEvidenceResult => $local->storeContents($finding, $bytes, $filename));
        $storage->expects(self::once())->method('deleteFile')->willThrowException(new \RuntimeException('delete unavailable'));
        $client = $this->capturingClient();
        $client->expects(self::never())->method('closeInteractive');
        try {
            $this->service($client, $storage)->save($finding, self::SESSION, $context);
            self::fail('An incomplete cleanup must be reported.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('app:artifacts:audit', $error->getMessage());
            self::assertStringContainsString('delete unavailable', $error->getMessage());
            self::assertInstanceOf(\Doctrine\DBAL\Exception::class, $error->getPrevious());
        }
        self::assertCount(2, $local->listPaths());
        self::assertSame(1, $this->entityManager->getRepository(Evidence::class)->count([]));
    }

    public function testCleanupNeverDeletesAFileThatIsAlreadyReferenced(): void
    {
        [$finding, $context, , $basis] = $this->fixture();
        $this->entityManager->getConnection()->executeStatement("CREATE TRIGGER reject_interactive BEFORE INSERT ON evidence BEGIN SELECT RAISE(ABORT, 'forced write failure'); END");
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('exists')->willReturn(true);
        $storage->method('storeContents')->willReturn(new StoredEvidenceResult($basis->getFilePath(), $basis->getSha256()));
        $storage->expects(self::never())->method('deleteFile');
        $client = $this->capturingClient();
        $client->expects(self::never())->method('closeInteractive');
        try {
            $this->service($client, $storage)->save($finding, self::SESSION, $context);
            self::fail('A failed DB write must not return evidence.');
        } catch (\Doctrine\DBAL\Exception) {
        }
        self::assertTrue($this->storage()->exists($basis->getFilePath()));
    }

    public function testPolicyChangeDuringStorageRemovesUnreferencedFileBeforeClosing(): void
    {
        [$finding, $context] = $this->fixture();
        $local = $this->storage();
        $files = $local->listPaths();
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('exists')->willReturn(true);
        $storage->method('storeContents')->willReturnCallback(function (Finding $finding, string $bytes, string $filename) use ($local): StoredEvidenceResult {
            $stored = $local->storeContents($finding, $bytes, $filename);
            $this->deny($finding, 'blocked');
            return $stored;
        });
        $storage->expects(self::once())->method('deleteFile')->willReturnCallback(fn (string $path) => $local->deleteFile($path));
        $client = $this->capturingClient();
        $client->expects(self::once())->method('closeInteractive')->willReturnCallback(function () use ($local, $files): void {
            self::assertSame($files, $local->listPaths());
            self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
        });
        $this->expectException(InteractiveCaptureInvalidatedException::class);
        $this->service($client, $storage)->save($finding, self::SESSION, $context);
    }

    #[DataProvider('invalidationCloseOutcomes')]
    public function testHardInvalidationStillClosesWhenFileCleanupFails(bool $closeFails): void
    {
        [$finding, $context, , $basis] = $this->fixture();
        $local = $this->storage();
        $cleanupAttempted = false;
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('exists')->willReturn(true);
        $storage->expects(self::once())->method('storeContents')->willReturnCallback(function (Finding $finding, string $bytes, string $filename) use ($local): StoredEvidenceResult {
            $stored = $local->storeContents($finding, $bytes, $filename);
            $this->deny($finding, 'blocked');
            return $stored;
        });
        $storage->expects(self::once())->method('deleteFile')->willReturnCallback(function () use (&$cleanupAttempted): void {
            $cleanupAttempted = true;
            throw new \RuntimeException('delete unavailable');
        });
        $client = $this->capturingClient();
        $client->expects(self::once())->method('closeInteractive')->with(self::SESSION)
            ->willReturnCallback(function () use (&$cleanupAttempted, $closeFails): void {
                self::assertTrue($cleanupAttempted);
                self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
                if ($closeFails) {
                    throw new \RuntimeException('close unavailable');
                }
            });

        try {
            $this->service($client, $storage)->save($finding, self::SESSION, $context);
            self::fail('Hard invalidation must remain typed despite cleanup failure.');
        } catch (InteractiveCaptureInvalidatedException $error) {
            self::assertStringContainsString('app:artifacts:audit', $error->getMessage());
            self::assertStringContainsString('delete unavailable', $error->getMessage());
            self::assertInstanceOf(InteractiveCaptureInvalidatedException::class, $error->getPrevious());
            if ($closeFails) {
                self::assertStringContainsString('close unavailable', $error->getMessage());
            }
        }
        self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
        self::assertSame(1, $this->entityManager->getRepository(Evidence::class)->count([]));
        self::assertSame(1, $this->entityManager->getRepository(ScreenshotJob::class)->count([]));
        self::assertTrue($local->exists($basis->getFilePath()));
        self::assertCount(2, $local->listPaths());
    }

    public static function invalidationCloseOutcomes(): iterable
    {
        yield 'close succeeds' => [false];
        yield 'close fails' => [true];
    }

    #[DataProvider('invalidImages')]
    public function testInvalidImageKeepsWorkerWithoutStoring(string $image): void
    {
        [$finding, $context] = $this->fixture();
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::once())->method('captureInteractive')->willReturn(new BrowserScreenshotResult($image, new \DateTimeImmutable()));
        $client->expects(self::never())->method('closeInteractive');
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('exists')->willReturn(true);
        $storage->expects(self::never())->method('storeContents');
        $this->expectExceptionMessage('invalid interactive screenshot image');
        $this->service($client, $storage)->save($finding, self::SESSION, $context);
    }

    public static function invalidImages(): iterable
    {
        yield 'invalid base64' => ['!invalid'];
        yield 'text' => [base64_encode('not an image')];
        yield 'different image format' => ['R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'];
    }

    public function testCloseFailureAfterCommitStillReturnsSavedEvidenceAndExplicitCloseOutcome(): void
    {
        [$finding, $context] = $this->fixture();
        $client = $this->capturingClient();
        $client->expects(self::once())->method('closeInteractive')->willThrowException(new \RuntimeException('close unavailable'));
        $saved = $this->service($client)->save($finding, self::SESSION, $context);
        self::assertFalse($saved->sessionClosed);
        self::assertSame('close unavailable', $saved->sessionCloseError);
        self::assertTrue($this->storage()->exists($saved->evidence->getFilePath()));
        self::assertSame(2, $this->entityManager->getRepository(Evidence::class)->count([]));
        self::assertSame(2, $this->entityManager->getRepository(ScreenshotJob::class)->count([]));
    }

    public function testNullableCaptureFieldsPreserveWorkerDetailsWithoutInventingInitialHttpStatus(): void
    {
        [$finding, $context] = $this->fixture();
        unset($context['initial_http_status'], $context['initial_challenge']);
        $worker = ['finalUrl' => 'https://interactive.example/final', 'captureMethod' => 'interactive-desktop-page',
            'currentHttpStatus' => 204, 'initialChallenge' => ['state' => 'unknown'],
            'currentChallenge' => ['state' => 'unknown'], 'dialogObservation' => ['type' => 'confirm', 'dismissed' => true]];
        $client = $this->capturingClient($worker);
        $client->expects(self::once())->method('closeInteractive');
        $saved = $this->service($client)->save($finding, self::SESSION, $context);
        $metadata = $saved->job->getCaptureMetadata();
        self::assertSame($worker, $metadata['workerMetadata']);
        self::assertSame($worker['finalUrl'], $metadata['finalUrl']);
        self::assertSame($worker['captureMethod'], $metadata['captureMethod']);
        self::assertSame($worker['dialogObservation'], $metadata['dialogObservation']);
        self::assertNull($metadata['initialHttpStatus']);
        self::assertSame(204, $metadata['currentHttpStatus']);
        self::assertSame('unknown', $metadata['initialChallengeState']);
        self::assertSame('unknown', $metadata['currentChallengeState']);
    }

    public function testRetainedDialogFrameUsesItsActualCaptureTimeAndSessionStart(): void
    {
        [$finding, $context] = $this->fixture();
        $context['created_at'] = '2026-04-01T10:00:00+00:00';
        $context['manual_action_count'] = 2;
        $context['trigger_challenge'] = ['state' => 'detected', 'active' => true, 'provider' => 'test'];
        $capturedAt = new \DateTimeImmutable('2026-04-01T10:02:00+00:00');
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::once())->method('captureInteractive')->willReturn(new BrowserScreenshotResult(
            self::PNG, $capturedAt, captureMethod: 'desktop-dialog', metadata: [
                'dialogFrameCapturedAt' => $capturedAt->format(DATE_ATOM),
                'dialogObservation' => ['type' => 'alert', 'message' => 'retained'],
            ],
        ));
        $client->expects(self::once())->method('closeInteractive');

        $saved = $this->service($client)->save($finding, self::SESSION, $context);
        self::assertEquals(new \DateTimeImmutable($context['created_at']), $saved->job->getRequestedAt());
        self::assertEquals($saved->job->getRequestedAt(), $saved->job->getStartedAt());
        self::assertEquals($capturedAt, $saved->job->getCapturedAt());
        self::assertGreaterThan($saved->job->getCapturedAt(), $saved->job->getFinishedAt());
        $metadata = $saved->job->getCaptureMetadata();
        self::assertSame(2, $metadata['manualActionCount']);
        self::assertTrue($metadata['manualInteraction']);
        self::assertSame($context['trigger_challenge'], $metadata['triggerChallenge']);
        self::assertSame($capturedAt->format(DATE_ATOM), $metadata['dialogFrameCapturedAt']);
        self::assertSame('retained', $metadata['dialogObservation']['message']);
    }

    public function testRetryAfterStorageFailureReusesWorkerCaptureAndClosesAfterCommit(): void
    {
        [$finding, $context] = $this->fixture();
        $capturedAt = new \DateTimeImmutable('2026-04-01T10:02:00+00:00');
        $context['created_at'] = '2026-04-01T10:00:00+00:00';
        $result = new BrowserScreenshotResult(self::PNG, $capturedAt);
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::exactly(2))->method('captureInteractive')->with(self::SESSION)->willReturn($result);
        $client->expects(self::once())->method('closeInteractive')->with(self::SESSION);
        $local = $this->storage();
        $attempts = 0;
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('exists')->willReturnCallback(fn (string $path): bool => $local->exists($path));
        $storage->method('storeContents')->willReturnCallback(function (Finding $finding, string $bytes, string $filename) use ($local, &$attempts): StoredEvidenceResult {
            if (++$attempts === 1) {
                throw new \RuntimeException('storage temporarily unavailable');
            }
            return $local->storeContents($finding, $bytes, $filename);
        });
        $service = $this->service($client, $storage);
        try {
            $service->save($finding, self::SESSION, $context);
            self::fail('First save must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('storage temporarily unavailable', $error->getMessage());
        }
        self::assertSame(1, $this->entityManager->getRepository(Evidence::class)->count([]));
        $saved = $service->save($finding, self::SESSION, $context);
        self::assertTrue($saved->sessionClosed);
        self::assertEquals($capturedAt, $saved->job->getCapturedAt());
        self::assertSame(2, $this->entityManager->getRepository(Evidence::class)->count([]));
    }

    public function testRetryAfterDatabaseFailureCommitsSameCachedCapture(): void
    {
        [$finding, $context] = $this->fixture();
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("CREATE TRIGGER reject_interactive BEFORE INSERT ON evidence BEGIN SELECT RAISE(ABORT, 'temporary DB failure'); END");
        $files = $this->storage()->listPaths();
        $capturedAt = new \DateTimeImmutable('2026-04-01T10:02:00+00:00');
        $result = new BrowserScreenshotResult(self::PNG, $capturedAt);
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::exactly(2))->method('captureInteractive')->with(self::SESSION)->willReturn($result);
        $client->expects(self::once())->method('closeInteractive');
        $service = $this->service($client);
        try {
            $service->save($finding, self::SESSION, $context);
            self::fail('First DB write must fail.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('temporary DB failure', $error->getMessage());
        }
        self::assertSame($files, $this->storage()->listPaths());
        self::assertSame(1, $this->entityManager->getRepository(Evidence::class)->count([]));
        $connection->executeStatement('DROP TRIGGER reject_interactive');

        $saved = $service->save($finding, self::SESSION, $context);
        self::assertEquals($capturedAt, $saved->job->getCapturedAt());
        self::assertTrue($saved->sessionClosed);
        self::assertSame(2, $this->entityManager->getRepository(Evidence::class)->count([]));
        self::assertSame(2, $this->entityManager->getRepository(ScreenshotJob::class)->count([]));
    }

    #[DataProvider('absentWorkerStatuses')]
    public function testMissingWorkerDoesNotNeedAnotherCloseAttempt(int $status): void
    {
        [$finding, $context] = $this->fixture();
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::once())->method('captureInteractive')->willThrowException(new \RuntimeException('session absent', $status));
        $client->expects(self::never())->method('closeInteractive');
        $this->expectExceptionCode($status);
        $this->service($client)->save($finding, self::SESSION, $context);
    }

    public static function absentWorkerStatuses(): iterable
    {
        yield 'not found' => [404];
        yield 'expired' => [410];
    }

    #[DataProvider('newerAvailableTriggerMetadata')]
    public function testNewerAvailableTriggerInvalidatesOldBindingBeforeCapture(array $metadata): void
    {
        [$finding, $context, , $basis] = $this->fixture();
        $newer = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())
            ->setStatus(ScreenshotJobStatus::AVAILABLE)->setScreenshotPath($basis->getFilePath())
            ->setRequestedAt(new \DateTimeImmutable('+1 minute'))
            ->setCaptureMetadata($metadata);
        $this->entityManager->persist($newer);
        $this->entityManager->flush();
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::never())->method('captureInteractive');
        $client->expects(self::once())->method('closeInteractive');
        $this->expectException(InteractiveCaptureInvalidatedException::class);
        $this->service($client)->save($finding, self::SESSION, $context);
    }

    public static function newerAvailableTriggerMetadata(): iterable
    {
        yield 'unresolved' => [['challengeDetected' => true, 'challengeCleared' => false]];
        yield 'cleared' => [['challengeDetected' => false, 'challengeCleared' => true]];
        yield 'unknown' => [['challengeDetected' => true, 'challengeCleared' => null]];
        yield 'ordinary page' => [[]];
    }

    public function testCaptureFailureKeepsWorkerAndPreservesCause(): void
    {
        [$finding, $context] = $this->fixture();
        $cause = new \RuntimeException('capture unavailable');
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::once())->method('captureInteractive')->willThrowException($cause);
        $client->expects(self::never())->method('closeInteractive');
        try {
            $this->service($client)->save($finding, self::SESSION, $context);
            self::fail('A failed capture must not return evidence.');
        } catch (\RuntimeException $error) {
            self::assertSame($cause, $error);
        }
        self::assertSame(1, $this->entityManager->getRepository(Evidence::class)->count([]));
    }

    #[DataProvider('invalidContexts')]
    public function testInvalidBindingIsRejectedBeforeCaptureAndCloses(string $mutation): void
    {
        [$finding, $context] = $this->fixture();
        switch ($mutation) {
            case 'missing': $context = []; break;
            case 'url': $context['start_url'] .= '&changed=1'; break;
            case 'trigger': $context['trigger_job_id'] = 'missing'; break;
            case 'no trigger': unset($context['trigger_job_id'], $context['trigger_evidence_id']); break;
            case 'no evidence': unset($context['trigger_evidence_id']); break;
            case 'no hash': unset($context['trigger_hash']); break;
            case 'finding binding': $context['finding_id'] = 'another finding'; break;
            case 'start hash': $context['start_url_hash'] = 'changed'; break;
            case 'trigger hash': $context['trigger_hash'] = 'changed'; break;
            case 'foreign evidence':
                [, $foreign] = $this->fixture('other');
                $context['trigger_evidence_id'] = $foreign['trigger_evidence_id'];
                break;
            case 'changed path':
                $this->entityManager->getConnection()->executeStatement('UPDATE screenshot_job SET screenshot_path = ? WHERE id = ?', ['different.png', $context['trigger_job_id']]);
                break;
            case 'no longer available':
                $this->entityManager->getConnection()->executeStatement('UPDATE screenshot_job SET status = ? WHERE id = ?', [ScreenshotJobStatus::FAILED, $context['trigger_job_id']]);
                break;
            case 'challenge cleared':
                $this->entityManager->getConnection()->executeStatement('UPDATE screenshot_job SET capture_metadata = ? WHERE id = ?', ['{"challengeDetected":false,"challengeCleared":true}', $context['trigger_job_id']]);
                break;
        }
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::never())->method('captureInteractive');
        $client->expects(self::once())->method('closeInteractive')->with(self::SESSION);
        $this->expectException(InteractiveCaptureInvalidatedException::class);
        $this->service($client)->save($finding, self::SESSION, $context);
    }

    public static function invalidContexts(): iterable
    {
        foreach (['missing', 'url', 'trigger', 'no trigger', 'no evidence', 'no hash', 'foreign evidence', 'changed path', 'no longer available', 'challenge cleared', 'finding binding', 'start hash', 'trigger hash'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('deniedStates')]
    public function testStartAndSaveRespectPersistedPolicyDespiteStaleFinding(string $state): void
    {
        [$finding, $context] = $this->fixture();
        $this->deny($finding, $state);
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::never())->method('waitUntilReady');
        $client->expects(self::never())->method('startInteractive');
        $client->expects(self::never())->method('captureInteractive');
        $client->expects(self::once())->method('closeInteractive');
        $service = $this->service($client);
        try {
            $service->start($finding);
            self::fail('Denied findings must not start a worker session.');
        } catch (\LogicException) {
        }
        $this->expectException(InteractiveCaptureInvalidatedException::class);
        $service->save($finding, self::SESSION, $context);
    }

    public static function deniedStates(): iterable
    {
        foreach (['discarded', 'blocked', 'deleted', 'changed URL'] as $state) {
            yield $state => [$state];
        }
    }

    public function testPolicyIsRecheckedAfterReadinessBeforeStarting(): void
    {
        [$finding] = $this->fixture();
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::once())->method('waitUntilReady')->willReturnCallback(fn () => $this->deny($finding, 'blocked'));
        $client->expects(self::never())->method('startInteractive');
        $this->expectException(InteractiveCaptureInvalidatedException::class);
        $this->service($client)->start($finding);
    }

    public function testStartUsesExactUrlAndConfiguredTimeout(): void
    {
        [$finding] = $this->fixture();
        $result = new BrowserInteractiveSessionResult(self::SESSION, new \DateTimeImmutable(), new \DateTimeImmutable('+20 minutes'));
        self::getContainer()->get(SettingsService::class)->save(['review.scan_timeout_ms' => '17000']);
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::once())->method('waitUntilReady');
        $client->expects(self::once())->method('startInteractive')->with($finding->getUrl(), 17000)->willReturn($result);
        self::assertSame($result, $this->service($client)->start($finding));
    }

    #[DataProvider('changedDuringCapture')]
    public function testBindingAndPolicyAreRecheckedAfterCapture(string $state): void
    {
        [$finding, $context] = $this->fixture();
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::once())->method('captureInteractive')->willReturnCallback(function () use ($finding, $context, $state): BrowserScreenshotResult {
            if ($state === 'trigger deleted') {
                $this->entityManager->getConnection()->executeStatement('DELETE FROM screenshot_job WHERE id = ?', [$context['trigger_job_id']]);
            } elseif ($state === 'trigger changed') {
                $this->entityManager->getConnection()->executeStatement('UPDATE screenshot_job SET capture_metadata = ? WHERE id = ?', ['{"challengeDetected":false}', $context['trigger_job_id']]);
            } else {
                $this->deny($finding, $state);
            }
            return new BrowserScreenshotResult(self::PNG, new \DateTimeImmutable());
        });
        $client->expects(self::once())->method('closeInteractive');
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('exists')->willReturn(true);
        $storage->expects(self::never())->method('storeContents');
        $this->expectException(InteractiveCaptureInvalidatedException::class);
        $this->service($client, $storage)->save($finding, self::SESSION, $context);
    }

    public static function changedDuringCapture(): iterable
    {
        yield from self::deniedStates();
        yield 'trigger deleted' => ['trigger deleted'];
        yield 'trigger changed' => ['trigger changed'];
    }

    public function testCallerTransactionIsRejectedBeforeCapture(): void
    {
        [$finding, $context] = $this->fixture();
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::never())->method('captureInteractive');
        $client->expects(self::never())->method('closeInteractive');
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            try {
                $this->service($client)->save($finding, self::SESSION, $context);
                self::fail('An existing transaction must reject capture.');
            } catch (\LogicException $error) {
                self::assertNotInstanceOf(InteractiveCaptureInvalidatedException::class, $error);
                self::assertStringContainsString('must own its persistence transaction', $error->getMessage());
            }
        } finally {
            self::assertTrue($connection->isTransactionActive());
            $connection->rollBack();
        }
    }

    public function testBoundedRetryAcceptsProofAssociatedWithActivePocWithoutRelabelingItsEntryUrl(): void
    {
        [$finding, $context, $trigger, $basis] = $this->fixture('updated-poc');
        $original = $finding->getUrl();
        $active = 'https://updated-poc.example/repaired?x=%2F%2f';
        $finding->setPocUrl($active, 1);
        $trigger->setCaptureMetadata(['challengeDetected' => true, 'challengeCleared' => false,
            'pocUrl' => $active, 'queuedPocRevision' => 1]);
        $this->entityManager->flush();
        $context['start_url'] = $active;
        $context['start_url_hash'] = hash('sha256', $active);
        $context['poc_revision'] = 1;
        $context['trigger_hash'] = hash('sha256', json_encode([$trigger->getId(), $trigger->getUrl(), $trigger->getStatus(),
            $trigger->getScreenshotPath(), $trigger->getCaptureMetadata(), $basis->getId(), $basis->getFilePath(), $basis->getSha256()], JSON_THROW_ON_ERROR));

        $saved = $this->service($this->capturingClient())->save($finding, self::SESSION, $context);

        self::assertSame($original, $trigger->getUrl());
        self::assertSame($active, $saved->job->getUrl());
        self::assertSame(1, $saved->job->getCaptureMetadata()['queuedPocRevision']);
        self::assertSame($active, $finding->getPocUrl());
    }

    public function testNewerScreenshotFromAnotherPocRevisionDoesNotInvalidateCurrentBoundedTrigger(): void
    {
        [$finding, $context] = $this->fixture('other-revision');
        $later = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())
            ->setStatus(ScreenshotJobStatus::AVAILABLE)->setRequestedAt(new \DateTimeImmutable('+1 hour'))
            ->setCaptureMetadata(['queuedPocRevision' => 2, 'challengeDetected' => true, 'challengeCleared' => false]);
        $this->entityManager->persist($later);
        $this->entityManager->flush();

        $saved = $this->service($this->capturingClient())->save($finding, self::SESSION, $context);

        self::assertSame(ScreenshotJobStatus::AVAILABLE, $saved->job->getStatus());
        self::assertSame(0, $saved->job->getCaptureMetadata()['queuedPocRevision']);
    }

    private function fixture(string $name = 'interactive'): array
    {
        $domain = (new Domain())->setHostname($name.'.example');
        $finding = (new Finding())->setDomain($domain)->setTitle($name)->setType('reflected_xss')
            ->setUrl('https://'.$name.'.example/?q=%2F%2f#Exact');
        $stored = $this->storage()->storeContents($finding, base64_decode(self::PNG), 'original.png');
        $basis = (new Evidence())->setFinding($finding)->setKind(EvidenceKind::SCREENSHOT)->setFilePath($stored->relativePath)->setSha256($stored->sha256);
        $trigger = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())
            ->setStatus(ScreenshotJobStatus::AVAILABLE)->setScreenshotPath($stored->relativePath)
            ->setCaptureMetadata(['challengeDetected' => true, 'challengeCleared' => false]);
        foreach ([$domain, $finding, $basis, $trigger] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return [$finding, ['finding_id' => $finding->getId(), 'start_url' => $finding->getUrl(),
            'start_url_hash' => hash('sha256', $finding->getUrl()),
            'trigger_hash' => hash('sha256', json_encode([$trigger->getId(), $trigger->getUrl(), $trigger->getStatus(),
                $trigger->getScreenshotPath(), $trigger->getCaptureMetadata(), $basis->getId(), $basis->getFilePath(), $basis->getSha256()], JSON_THROW_ON_ERROR)),
            'trigger_job_id' => $trigger->getId(),
            'trigger_evidence_id' => $basis->getId(), 'initial_challenge' => ['state' => 'detected', 'active' => true],
            'initial_http_status' => 403], $trigger, $basis];
    }

    private function service(BrowserInteractiveClientInterface $client, ?EvidenceStorageInterface $storage = null): InteractiveCaptureService
    {
        return new InteractiveCaptureService($client, $storage ?? $this->storage(), self::getContainer()->get(ManagerRegistry::class),
            self::getContainer()->get(SettingsService::class), self::getContainer()->get(FindingWorkPolicy::class));
    }

    private function storage(): EvidenceStorageInterface
    {
        return self::getContainer()->get(EvidenceStorageInterface::class);
    }

    private function capturingClient(array $metadata = []): BrowserInteractiveClientInterface
    {
        $client = $this->createMock(BrowserInteractiveClientInterface::class);
        $client->expects(self::once())->method('captureInteractive')->with(self::SESSION)
            ->willReturn(new BrowserScreenshotResult(self::PNG, new \DateTimeImmutable(), metadata: $metadata));

        return $client;
    }

    private function neutralSnapshot(?string $excludeJobId = null): array
    {
        $db = $this->entityManager->getConnection();
        $snapshot = [];
        foreach (['finding', 'finding_assessment', 'finding_follow_up', 'retest_run', 'screenshot_job'] as $table) {
            $snapshot[$table] = $table === 'screenshot_job' && $excludeJobId !== null
                ? $db->fetchAllAssociative('SELECT * FROM screenshot_job WHERE id <> ? ORDER BY rowid', [$excludeJobId])
                : $db->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid');
        }
        // The service may only append its own job; existing jobs stay identical.
        return $snapshot;
    }

    private function deny(Finding $finding, string $state): void
    {
        $db = $this->entityManager->getConnection();
        match ($state) {
            'discarded' => $db->executeStatement("UPDATE finding SET manual_assessment = 'discarded' WHERE id = ?", [$finding->getId()]),
            'deleted' => $db->executeStatement('DELETE FROM finding WHERE id = ?', [$finding->getId()]),
            'changed URL' => $db->executeStatement('UPDATE finding SET url = ? WHERE id = ?', [$finding->getUrl().'&changed=1', $finding->getId()]),
            'blocked' => $this->block($finding),
        };
    }

    private function block(Finding $finding): void
    {
        $followUp = new FindingFollowUp();
        $followUp->finding = $finding;
        $followUp->checksBlocked = true;
        $followUp->changedAt = new \DateTimeImmutable();
        $this->entityManager->persist($followUp);
        $this->entityManager->flush();
    }
}
