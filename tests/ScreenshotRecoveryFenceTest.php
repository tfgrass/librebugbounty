<?php

namespace App\Tests;

use App\Dto\BrowserScreenshotResult;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\ScreenshotJob;
use App\Repository\ScreenshotJobRepository;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\ScreenshotOperationLock;
use App\Service\ScreenshotQueueService;
use App\Service\SettingsService;
use App\Value\ScreenshotJobStatus;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScreenshotRecoveryFenceTest extends DatabaseTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

    public function testQueuedCaptureStaysUnattemptedWhileDeferredAndRunsAfterResume(): void
    {
        $finding = $this->finding();
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::once())->method('waitUntilReady');
        $client->expects(self::once())->method('capture')->willReturn($this->captureResult($finding));
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $client);
        $queue = self::getContainer()->get(ScreenshotQueueService::class);
        $job = $queue->enqueue($finding)->job;
        $this->defer($finding);
        $before = $this->workflowSnapshot($finding);

        self::assertNull($queue->processNext());
        self::assertNull(self::getContainer()->get(ScreenshotJobRepository::class)->claimNext());
        $this->entityManager->refresh($job);
        self::assertSame(ScreenshotJobStatus::QUEUED, $job->getStatus());
        self::assertSame(0, $job->getAttempts());
        self::assertSame($before, $this->workflowSnapshot($finding));

        $this->resume($finding);
        $before = $this->workflowSnapshot($finding);
        $processed = $queue->processNext();
        self::assertSame(ScreenshotJobStatus::AVAILABLE, $processed?->getStatus());
        self::assertSame(2, $processed?->getCaptureMetadata()['captureDispositionRevision']);
        self::assertSame(1, $processed?->getAttempts());
        self::assertSame($before, $this->workflowSnapshot($finding));
    }

    public function testDeferralBetweenReadinessAndClaimDoesNotStartBrowserRequest(): void
    {
        $finding = $this->finding();
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::once())->method('waitUntilReady')->willReturnCallback(function () use ($finding): void { $this->defer($finding); });
        $client->expects(self::never())->method('capture');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $client);
        $queue = self::getContainer()->get(ScreenshotQueueService::class);
        $job = $queue->enqueue($finding)->job;

        self::assertNull($queue->processNext());
        $this->entityManager->refresh($job);
        self::assertSame(ScreenshotJobStatus::QUEUED, $job->getStatus());
        self::assertSame(0, $job->getAttempts());
        self::assertSame(0, $this->entityManager->getRepository(Evidence::class)->count([]));
    }

    #[DataProvider('inflightDispositionChanges')]
    public function testInflightResultCannotBecomeEvidenceAfterDispositionChanges(bool $resume): void
    {
        $finding = $this->finding();
        $afterChange = null;
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::once())->method('capture')->willReturnCallback(function () use ($finding, $resume, &$afterChange): BrowserScreenshotResult {
            $this->defer($finding);
            if ($resume) $this->resume($finding);
            $afterChange = $this->workflowSnapshot($finding);
            return $this->captureResult($finding);
        });
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $client);
        $queue = self::getContainer()->get(ScreenshotQueueService::class);
        $queue->enqueue($finding);

        $failed = $queue->processNext();

        self::assertSame(ScreenshotJobStatus::FAILED, $failed?->getStatus());
        self::assertStringContainsString('disposition changed', $failed?->getErrorMessage());
        self::assertNull($failed?->getActiveKey());
        self::assertSame(0, $this->entityManager->getRepository(Evidence::class)->count([]));
        self::assertSame([], self::getContainer()->get(EvidenceStorageInterface::class)->listPaths());
        self::assertSame($afterChange, $this->workflowSnapshot($finding));
    }

    public static function inflightDispositionChanges(): array
    {
        return ['deferred' => [false], 'deferred then resumed' => [true]];
    }

    public function testDeferralAfterFileStorageCleansImageAndLeavesWorkflowUntouched(): void
    {
        $finding = $this->finding();
        $realStorage = self::getContainer()->get(EvidenceStorageInterface::class);
        $afterChange = null;
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->expects(self::once())->method('storeContents')->willReturnCallback(function (Finding $owner, string $binary, string $filename) use ($finding, $realStorage, &$afterChange) {
            $stored = $realStorage->storeContents($owner, $binary, $filename);
            $this->defer($finding);
            $this->resume($finding);
            $afterChange = $this->workflowSnapshot($finding);
            return $stored;
        });
        $storage->expects(self::once())->method('deleteFile')->willReturnCallback($realStorage->deleteFile(...));
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::once())->method('capture')->willReturn($this->captureResult($finding));
        $queue = new ScreenshotQueueService(
            self::getContainer()->get(ManagerRegistry::class), $client, $storage,
            self::getContainer()->get(SettingsService::class), self::getContainer()->get(ScreenshotOperationLock::class),
        );
        $queue->enqueue($finding);

        self::assertSame(ScreenshotJobStatus::FAILED, $queue->processNext()?->getStatus());
        self::assertSame([], $realStorage->listPaths());
        self::assertSame(0, $this->entityManager->getRepository(Evidence::class)->count([]));
        self::assertSame($afterChange, $this->workflowSnapshot($finding));
    }

    private function finding(): Finding
    {
        $domain = (new Domain())->setHostname('capture-recovery.test')->setScheme('https');
        $finding = (new Finding())->setDomain($domain)->setTitle('Recovery screenshot')->setType('xss')->setUrl('https://capture-recovery.test/poc')
            ->setNextDueAt(new \DateTimeImmutable('2030-01-01'));
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        return $finding;
    }

    private function defer(Finding $finding): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO finding_review_disposition (finding_id, revision, state, reason, changed_at, retry_state, due_at) VALUES (?, 1, 'deferred', 'unreachable', '2026-10-18 10:00:00', 'pending', '2030-02-01 00:00:00')",
            [$finding->getId()],
        );
    }

    private function resume(Finding $finding): void
    {
        $this->entityManager->getConnection()->executeStatement("UPDATE finding_review_disposition SET state = 'active', revision = revision + 1, retry_state = 'cancelled' WHERE finding_id = ?", [$finding->getId()]);
    }

    private function workflowSnapshot(Finding $finding): array
    {
        $connection = $this->entityManager->getConnection();
        return [
            $connection->fetchAssociative('SELECT * FROM finding_review_disposition WHERE finding_id = ?', [$finding->getId()]),
            $connection->fetchAssociative('SELECT next_due_at, last_retested_at, manual_assessment, status FROM finding WHERE id = ?', [$finding->getId()]),
        ];
    }

    private function captureResult(Finding $finding): BrowserScreenshotResult
    {
        return new BrowserScreenshotResult(screenshotBase64: self::PNG, capturedAt: new \DateTimeImmutable('2026-10-18 10:00:00'), finalUrl: $finding->getPocUrl());
    }
}
