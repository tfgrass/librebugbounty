<?php

namespace App\Service;

use App\Dto\BrowserScreenshotRequest;
use App\Dto\ScreenshotEnqueueResult;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\ScreenshotJob;
use App\Repository\ScreenshotJobRepository;
use App\Value\EvidenceKind;
use App\Value\ScreenshotJobStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final class ScreenshotQueueService
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly BrowserScreenshotClientInterface $client,
        private readonly EvidenceStorageInterface $storage,
        private readonly SettingsService $settings,
        private readonly ScreenshotOperationLock $operationLock,
    ) {
    }

    public function enqueue(Finding $finding): ScreenshotEnqueueResult
    {
        return $this->operationLock->synchronizedQueueMutation(
            fn (): ScreenshotEnqueueResult => $this->enqueueWithLock($finding),
        );
    }

    /**
     * Restore the intake invariant for findings created before the persistent
     * queue existed, or by an interrupted older intake implementation.
     *
     * Unlike enqueue(), this does not start a new capture cycle when any job
     * (including a terminal one) is already part of the finding's history.
     */
    public function ensureExists(Finding $finding): ScreenshotEnqueueResult
    {
        return $this->operationLock->synchronizedQueueMutation(function () use ($finding): ScreenshotEnqueueResult {
            $this->assertNotDiscarded($finding);
            $existing = $this->jobs()->findOneBy(
                ['finding' => $finding],
                ['requestedAt' => 'DESC'],
            );
            if ($existing instanceof ScreenshotJob) {
                return new ScreenshotEnqueueResult($existing, false);
            }

            return $this->enqueueWithLock($finding);
        });
    }

    private function enqueueWithLock(Finding $finding): ScreenshotEnqueueResult
    {
        $this->assertNotDiscarded($finding);
        $connection = $this->entityManager()->getConnection();
        $findingExists = $connection->fetchOne(
            'SELECT 1 FROM finding WHERE id = :id',
            ['id' => $finding->getId()],
        );
        if ($findingExists === false) {
            throw new \RuntimeException('Finding no longer exists; screenshot job was not queued.');
        }

        $jobs = $this->jobs();
        $active = $jobs->findActiveForFinding($finding);
        if ($active instanceof ScreenshotJob) {
            return new ScreenshotEnqueueResult($active, false);
        }

        $candidate = new ScreenshotJob();
        $now = new \DateTimeImmutable();
        $inserted = $connection->executeStatement(
            'INSERT INTO screenshot_job (id, finding_id, url, status, active_key, requested_at, attempts, created_at, updated_at) '
            .'SELECT :id, id, :url, :status, id, :requested, 0, :created, :updated FROM finding WHERE id = :finding '
            ."AND (manual_assessment IS NULL OR manual_assessment <> 'discarded') AND status NOT IN ('discarded', 'duplicate') "
            .'ON CONFLICT(active_key) DO NOTHING',
            [
                'id' => $candidate->getId(),
                'finding' => $finding->getId(),
                'url' => $finding->getUrl(),
                'status' => ScreenshotJobStatus::QUEUED,
                'requested' => $now->format('Y-m-d H:i:s'),
                'created' => $now->format('Y-m-d H:i:s'),
                'updated' => $now->format('Y-m-d H:i:s'),
            ],
        );
        if ($inserted === 0) {
            $active = $this->jobs()->findOneBy(['activeKey' => $finding->getId()]);
            if ($active instanceof ScreenshotJob) {
                return new ScreenshotEnqueueResult($active, false);
            }

            if ($connection->fetchOne('SELECT 1 FROM finding WHERE id = :id', ['id' => $finding->getId()]) === false) {
                throw new \RuntimeException('Finding no longer exists; screenshot job was not queued.');
            }

            throw new \RuntimeException('Screenshot job could not be queued.');
        }

        $job = $this->jobs()->find($candidate->getId());
        if (!$job instanceof ScreenshotJob) {
            throw new \RuntimeException('Queued screenshot job could not be loaded.');
        }

        return new ScreenshotEnqueueResult($job, true);
    }

    private function assertNotDiscarded(Finding $finding): void
    {
        $state = $this->entityManager()->getConnection()->fetchAssociative(
            'SELECT manual_assessment, status FROM finding WHERE id = ?', [$finding->getId()],
        );
        if ($state !== false && ($state['manual_assessment'] === 'discarded' || in_array($state['status'], ['discarded', 'duplicate'], true))) {
            throw new \LogicException('Discarded findings are ignored; screenshot job was not queued.');
        }
    }

    public function processNext(): ?ScreenshotJob
    {
        return $this->operationLock->synchronized(fn (): ?ScreenshotJob => $this->processNextWithLock());
    }

    private function processNextWithLock(): ?ScreenshotJob
    {
        $jobs = $this->jobs();
        if ($jobs->countByStatus(ScreenshotJobStatus::QUEUED) === 0) {
            return null;
        }

        // A sidecar that is still starting must leave persisted work queued.
        // Once a job is claimed, capture failures are terminal and visible.
        $this->client->waitUntilReady();
        $job = $jobs->claimNext();
        if (!$job instanceof ScreenshotJob) {
            return null;
        }

        $storedPath = null;
        try {
            $result = $this->client->capture(new BrowserScreenshotRequest(
                url: $job->getUrl(),
                timeoutMs: $this->settings->getReviewScanTimeoutMs(),
            ));
            $binary = base64_decode($result->screenshotBase64, true);
            if ($binary === false || @getimagesizefromstring($binary) === false) {
                throw new \RuntimeException('Browser worker returned an invalid screenshot image.');
            }

            $stored = $this->storage->storeContents($job->getFinding(), $binary, 'capture-'.$job->getId().'.png');
            $storedPath = $stored->relativePath;
            $evidence = (new Evidence())
                ->setFinding($job->getFinding())
                ->setKind(EvidenceKind::SCREENSHOT)
                ->setFilePath($stored->relativePath)
                ->setSha256($stored->sha256);
            $capturedAt = $result->capturedAt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $job
                ->setStatus(ScreenshotJobStatus::AVAILABLE)
                ->setActiveKey(null)
                ->setCapturedAt($capturedAt)
                ->setFinishedAt(new \DateTimeImmutable())
                ->setErrorMessage(null)
                ->setScreenshotPath($stored->relativePath)
                ->setCaptureMetadata(array_merge($result->metadata, [
                    'httpStatus' => $result->httpStatus,
                    'finalUrl' => $result->finalUrl,
                    'dialogSeen' => $result->dialogSeen,
                    'dialogType' => $result->dialogType,
                    'dialogText' => $result->dialogText,
                    'captureMethod' => $result->captureMethod,
                ]));

            $entityManager = $this->entityManager();
            $entityManager->persist($evidence);
            $entityManager->flush();
        } catch (\Throwable $error) {
            $failureMessage = $error->getMessage();
            if ($storedPath !== null) {
                try {
                    $this->storage->deleteFile($storedPath);
                } catch (\Throwable) {
                    $failureMessage = 'Screenshot metadata could not be saved; an unreferenced file may remain. Run app:artifacts:audit. Cause: '.$failureMessage;
                }
            }
            $job = $this->recordFailure($job->getId(), $failureMessage, $error);
        }

        return $job;
    }

    private function recordFailure(string $jobId, string $message, \Throwable $cause): ScreenshotJob
    {
        $entityManager = $this->resetClosedEntityManager();
        $entityManager->clear();
        $job = $entityManager->find(ScreenshotJob::class, $jobId);
        if (!$job instanceof ScreenshotJob) {
            throw new \RuntimeException('Screenshot job disappeared while recording its failure.', 0, $cause);
        }

        $job
            ->setStatus(ScreenshotJobStatus::FAILED)
            ->setActiveKey(null)
            ->setFinishedAt(new \DateTimeImmutable())
            ->setErrorMessage($message);
        try {
            $entityManager->flush();
        } catch (\Throwable $recordingError) {
            throw new \RuntimeException(
                sprintf('Screenshot failed (%s), and its failure state could not be saved: %s', $message, $recordingError->getMessage()),
                0,
                $recordingError,
            );
        }

        return $job;
    }

    private function jobs(): ScreenshotJobRepository
    {
        $repository = $this->doctrine->getRepository(ScreenshotJob::class);
        if (!$repository instanceof ScreenshotJobRepository) {
            throw new \LogicException('Screenshot job repository is not available.');
        }

        return $repository;
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->doctrine->getManagerForClass(ScreenshotJob::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Screenshot jobs require a Doctrine ORM entity manager.');
        }

        return $manager;
    }

    private function resetClosedEntityManager(): EntityManagerInterface
    {
        $manager = $this->entityManager();
        if ($manager->isOpen()) {
            return $manager;
        }

        $manager = $this->doctrine->resetManager();
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Screenshot jobs require a Doctrine ORM entity manager.');
        }

        return $manager;
    }
}
