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

final class ScreenshotQueueService implements ScreenshotEnqueuerInterface
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
        $connection = $this->entityManager()->getConnection();
        return $connection->transactional(function () use ($connection, $finding): ScreenshotEnqueueResult {
            // PoC updates use a DB transaction rather than this process lock.
            // Reserve the writer before reading the active URL and revision.
            $connection->executeStatement('UPDATE finding SET id = id WHERE 1 = 0');
            return $this->enqueueCurrentPocWithLock($finding);
        });
    }

    private function enqueueCurrentPocWithLock(Finding $finding): ScreenshotEnqueueResult
    {
        $this->assertNotDiscarded($finding);
        $connection = $this->entityManager()->getConnection();
        $currentPoc = $connection->fetchAssociative(
            'SELECT COALESCE(poc_url, url) AS url, poc_revision FROM finding WHERE id = :id',
            ['id' => $finding->getId()],
        );
        if ($currentPoc === false) {
            throw new \RuntimeException('Finding no longer exists; screenshot job was not queued.');
        }

        $jobs = $this->jobs();
        $active = $jobs->findActiveForFinding($finding);
        if ($active instanceof ScreenshotJob) {
            if ($this->matchesPoc($active, $currentPoc)) {
                return new ScreenshotEnqueueResult($active, false);
            }

            // A previously queued/running request belongs to its original PoC.
            // Release its slot without rewriting its immutable source URL.
            $connection->executeStatement(
                'UPDATE screenshot_job SET status = :status, active_key = NULL, finished_at = :now, updated_at = :now, error_message = :error WHERE id = :id',
                [
                    'status' => ScreenshotJobStatus::FAILED,
                    'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'error' => 'The active PoC changed; request a screenshot for the current URL.',
                    'id' => $active->getId(),
                ],
            );
            $this->entityManager()->refresh($active);
        }

        $candidate = new ScreenshotJob();
        $now = new \DateTimeImmutable();
        $inserted = $connection->executeStatement(
            'INSERT INTO screenshot_job (id, finding_id, url, status, active_key, requested_at, attempts, created_at, updated_at, capture_metadata) '
            .'SELECT :id, id, COALESCE(poc_url, url), :status, id, :requested, 0, :created, :updated, :metadata FROM finding WHERE id = :finding '
            ."AND (manual_assessment IS NULL OR manual_assessment <> 'discarded') AND status NOT IN ('discarded', 'duplicate') "
            .'AND poc_revision = :revision AND COALESCE(poc_url, url) = :url '
            .'AND '.FindingWorkPolicy::checksAllowedSql($connection, 'finding').' '
            .'ON CONFLICT(active_key) DO NOTHING',
            [
                'id' => $candidate->getId(),
                'finding' => $finding->getId(),
                'url' => $currentPoc['url'],
                'revision' => (int) $currentPoc['poc_revision'],
                'metadata' => json_encode(['queuedPocRevision' => (int) $currentPoc['poc_revision']], JSON_THROW_ON_ERROR),
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
        (new FindingWorkPolicy($this->entityManager()->getConnection()))->assertChecksAllowed($finding);
        $state = $this->entityManager()->getConnection()->fetchAssociative(
            'SELECT manual_assessment, status FROM finding WHERE id = ?', [$finding->getId()],
        );
        if ($state !== false && ($state['manual_assessment'] === 'discarded' || in_array($state['status'], ['discarded', 'duplicate'], true))) {
            throw new \LogicException('Discarded findings are ignored; screenshot job was not queued.');
        }
    }

    public function processNext(): ?ScreenshotJob
    {
        return $this->processNextWithLock();
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
        // Keep only the short queue mutation under the global lock. The actual
        // browser capture may run for a long time and is handled by parallel
        // workers, each with its own headed Playwright/Xvfb sidecar.
        $job = $this->operationLock->synchronizedQueueMutation(fn (): ?ScreenshotJob => $jobs->claimNext());
        if (!$job instanceof ScreenshotJob) {
            return null;
        }

        $storedPath = null;
        try {
            $this->assertNotDiscarded($job->getFinding());
            $this->assertCurrentPoc($job);
            $this->assertCurrentReviewDisposition($job);
            $result = $this->client->capture(new BrowserScreenshotRequest(
                url: $job->getUrl(),
                timeoutMs: $this->settings->getReviewScanTimeoutMs(),
            ));
            $binary = base64_decode($result->screenshotBase64, true);
            if ($binary === false || @getimagesizefromstring($binary) === false) {
                throw new \RuntimeException('Browser worker returned an invalid screenshot image.');
            }

            $this->assertCurrentPoc($job);
            $this->assertCurrentReviewDisposition($job);

            $stored = $this->storage->storeContents($job->getFinding(), $binary, 'capture-'.$job->getId().'.png');
            $storedPath = $stored->relativePath;
            $this->operationLock->synchronizedQueueMutation(function () use ($job, $stored, $result): void {
                // A browser request can outlive a manual PoC update. Validate
                // inside a writer transaction, so a concurrent PoC update
                // cannot commit between this check and the evidence commit.
                $entityManager = $this->entityManager();
                $entityManager->getConnection()->transactional(function () use ($entityManager, $job, $stored, $result): void {
                    $entityManager->getConnection()->executeStatement('UPDATE finding SET id = id WHERE 1 = 0');
                    $this->assertNotDiscarded($job->getFinding());
                    $this->assertCurrentPoc($job);
                    $this->assertCurrentReviewDisposition($job);
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
                        ->setCaptureMetadata(array_merge($job->getCaptureMetadata() ?? [], $result->metadata, [
                            'queuedPocRevision' => $this->jobPocRevision($job),
                            'captureDispositionRevision' => (int) ($job->getCaptureMetadata()['captureDispositionRevision'] ?? 0),
                            'requestedUrl' => $job->getUrl(),
                            'sourceUrl' => $result->finalUrl,
                            'httpStatus' => $result->httpStatus,
                            'finalUrl' => $result->finalUrl,
                            'dialogSeen' => $result->dialogSeen,
                            'dialogType' => $result->dialogType,
                            'dialogText' => $result->dialogText,
                            'captureMethod' => $result->captureMethod,
                        ]));

                    $entityManager->persist($evidence);
                    $entityManager->flush();
                });
            });
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

    /** @param array<string, mixed> $currentPoc */
    private function matchesPoc(ScreenshotJob $job, array $currentPoc): bool
    {
        return $job->getUrl() === $currentPoc['url']
            && $this->jobPocRevision($job) === (int) $currentPoc['poc_revision'];
    }

    private function jobPocRevision(ScreenshotJob $job): int
    {
        // Pre-2.1.1 jobs belong to the unchanged original PoC (revision zero).
        return (int) ($job->getCaptureMetadata()['queuedPocRevision'] ?? 0);
    }

    private function assertCurrentPoc(ScreenshotJob $job): void
    {
        $currentPoc = $this->entityManager()->getConnection()->fetchAssociative(
            'SELECT COALESCE(poc_url, url) AS url, poc_revision FROM finding WHERE id = :id',
            ['id' => $job->getFinding()->getId()],
        );
        if ($currentPoc === false || !$this->matchesPoc($job, $currentPoc)) {
            throw new \LogicException('The active PoC changed; request a screenshot for the current URL.');
        }
    }

    private function assertCurrentReviewDisposition(ScreenshotJob $job): void
    {
        $connection = $this->entityManager()->getConnection();
        $disposition = ReviewDispositionService::available($connection)
            ? $connection->fetchAssociative('SELECT revision, state FROM finding_review_disposition WHERE finding_id = ?', [$job->getFinding()->getId()])
            : false;
        $revision = $disposition === false ? 0 : (int) $disposition['revision'];
        if (($disposition['state'] ?? null) === 'deferred'
            || $revision !== (int) ($job->getCaptureMetadata()['captureDispositionRevision'] ?? 0)) {
            throw new \LogicException('The review disposition changed during capture; request a new screenshot after resuming the finding.');
        }
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
