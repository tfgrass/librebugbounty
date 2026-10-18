<?php

namespace App\Service;

use App\Dto\BrowserInteractiveSessionResult;
use App\Dto\BrowserScreenshotResult;
use App\Dto\InteractiveCaptureSaveResult;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\ScreenshotJob;
use App\Exception\InteractiveCaptureInvalidatedException;
use App\Value\EvidenceKind;
use App\Value\ScreenshotJobStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final class InteractiveCaptureService
{
    public function __construct(
        private readonly BrowserInteractiveClientInterface $client,
        private readonly EvidenceStorageInterface $storage,
        private readonly ManagerRegistry $doctrine,
        private readonly SettingsService $settings,
        private readonly FindingWorkPolicy $workPolicy,
    ) {
    }

    public function start(Finding $finding): BrowserInteractiveSessionResult
    {
        $this->assertAllowed($finding);
        $this->client->waitUntilReady();
        $this->assertAllowed($finding);

        return $this->client->startInteractive($finding->getUrl(), $this->settings->getReviewScanTimeoutMs());
    }

    /**
     * The controller supplies the binding recorded when the fresh context was
     * opened. Never infer the trigger from the latest job at save time.
     *
     * @param array{start_url: string, trigger_job_id?: ?string, trigger_evidence_id?: ?string,
     *     initial_challenge?: array<string, mixed>, initial_http_status?: ?int,
     *     finding_id?: string, start_url_hash?: string, trigger_hash?: string,
     *     created_at?: string|int, manual_action_count?: int} $context
     */
    public function save(Finding $finding, string $sessionId, array $context = []): InteractiveCaptureSaveResult
    {
        try {
            $saved = $this->persistCapture($finding, $sessionId, $context);
        } catch (InteractiveCaptureInvalidatedException $error) {
            // A changed binding cannot become valid again on this worker page.
            try {
                $this->client->closeInteractive($sessionId);
            } catch (\Throwable $closeError) {
                throw new InteractiveCaptureInvalidatedException($error->getMessage().' Worker session close also failed: '.$closeError->getMessage(), 0, $error);
            }
            throw $error;
        }

        // A capture/storage/DB failure leaves the non-destructive worker result
        // cached for another save attempt. Close only after a committed save.
        $closeError = null;
        try {
            $this->client->closeInteractive($sessionId);
        } catch (\Throwable $error) {
            $closeError = $error->getMessage();
        }
        return new InteractiveCaptureSaveResult($saved->job, $saved->evidence, $closeError === null, $closeError);
    }

    /** Capture/files occur outside the lease lock; evidence and closing commit together. */
    public function persistForLease(Finding $finding, string $sessionId, array $context, InteractiveBrowserLeaseService $lease, int $generation, ?string $expectedCandidateId): InteractiveCaptureSaveResult
    {
        $context['manual_entry'] = true;
        $context['input_method'] = 'vnc';
        $context['lease_generation'] = $generation;
        return $this->persistCapture($finding, $sessionId, $context, $lease, $generation, $expectedCandidateId);
    }

    private function persistCapture(Finding $finding, string $sessionId, array $context, ?InteractiveBrowserLeaseService $lease = null, ?int $generation = null, ?string $expectedCandidateId = null): InteractiveCaptureSaveResult
    {
        $manager = $this->entityManager();
        $connection = $manager->getConnection();
        if ($connection->isTransactionActive()) {
            throw new \LogicException('Interactive capture must own its persistence transaction.');
        }
        $this->assertAllowed($finding);
        $this->assertContext($finding, $context, $connection);

        $captureRequestedAt = new \DateTimeImmutable();
        $sessionStartedAt = $this->sessionStartedAt($context);
        $requestedAt = $sessionStartedAt ?? $captureRequestedAt;
        $job = (new ScreenshotJob())->setUrl($context['start_url'])
            ->setRequestedAt($requestedAt)->setStartedAt($requestedAt)->setAttempts(1);
        // A separate unit of work prevents flushing a pending assessment,
        // recheck, or queue mutation from the caller's entity manager.
        $writer = new EntityManager($connection, $manager->getConfiguration(), $manager->getEventManager());
        $storedPath = null;
        $ownsTransaction = false;
        try {
            if ($lease !== null) {
                if (!$this->client instanceof FencedBrowserInteractiveClientInterface || $generation === null) {
                    throw new \LogicException('A generation-aware browser client is required.');
                }
                $result = $this->client->captureFenced($sessionId, $generation, $expectedCandidateId);
            } else {
                $result = $this->client->captureInteractive($sessionId);
            }
            $binary = base64_decode($result->screenshotBase64, true);
            $image = $binary === false ? false : @getimagesizefromstring($binary);
            if ($image === false || $image[2] !== IMAGETYPE_PNG) {
                throw new \RuntimeException('Browser worker returned an invalid interactive screenshot image.');
            }

            $capturedAt = $result->capturedAt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            // A retained dialog frame can precede this save request. Without
            // the session timestamp, the worker's capture time is the earliest
            // truthful bound we have for this job's requested/started fields.
            if ($capturedAt < $requestedAt) {
                $job->setRequestedAt($capturedAt)->setStartedAt($capturedAt);
            }

            $this->assertAllowed($finding);
            $this->assertContext($finding, $context, $connection);
            $stored = $this->storage->storeContents($finding, $binary, 'interactive-'.$job->getId().'.png');
            $storedPath = $stored->relativePath;
            $boundFinding = $writer->getReference(Finding::class, $finding->getId());
            $evidence = (new Evidence())
                ->setFinding($boundFinding)
                ->setKind(EvidenceKind::SCREENSHOT)
                ->setFilePath($stored->relativePath)
                ->setSha256($stored->sha256);
            $job
                ->setFinding($boundFinding)
                ->setStatus(ScreenshotJobStatus::AVAILABLE)
                ->setCapturedAt($capturedAt)
                ->setScreenshotPath($stored->relativePath)
                ->setCaptureMetadata(array_replace($this->metadata($result, $context), ['sha256' => $stored->sha256]));

            $persist = function () use ($finding, $context, $connection, $writer, $job, $evidence): void {
                $this->assertAllowed($finding);
                $this->assertContext($finding, $context, $connection);
                $writer->persist($job);
                $writer->persist($evidence);
                $writer->flush();
                $job->setFinishedAt(new \DateTimeImmutable());
                $writer->flush();
            };
            if ($lease !== null && $generation !== null) {
                $lease->withLeaseLock($finding->getId(), $generation, function (Connection $lockedConnection) use ($persist, $connection): void {
                    if ($lockedConnection !== $connection) { throw new \LogicException('Lease and evidence must share one connection.'); }
                    $persist();
                });
            } else {
                $connection->beginTransaction();
                $ownsTransaction = true;
                $persist();
                $connection->commit();
                $ownsTransaction = false;
            }

            return new InteractiveCaptureSaveResult($job, $evidence);
        } catch (\Throwable $error) {
            try {
                if ($ownsTransaction && $connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                // Do not remove bytes if a commit succeeded but its outcome
                // was uncertain, or another persisted record references them.
                if ($storedPath !== null && !$this->fileIsReferenced($connection, $storedPath)) {
                    $this->storage->deleteFile($storedPath);
                }
            } catch (\Throwable $cleanupError) {
                $message = $error->getMessage().' Capture cleanup could not be confirmed; run app:artifacts:audit. '.$cleanupError->getMessage();
                // Cleanup failure must not turn a hard invalidation into a
                // retryable error and bypass the worker close in save().
                if ($error instanceof InteractiveCaptureInvalidatedException) {
                    throw new InteractiveCaptureInvalidatedException($message, 0, $error);
                }
                throw new \RuntimeException($message, 0, $error);
            }
            throw $error;
        } finally {
            $writer->close();
        }
    }

    private function assertAllowed(Finding $finding): void
    {
        if ($finding->isDiscarded()) {
            throw new InteractiveCaptureInvalidatedException('Verworfene Fälle werden im normalen Arbeiten ignoriert.');
        }
        try {
            $this->workPolicy->assertChecksAllowed($finding);
        } catch (\LogicException $error) {
            throw new InteractiveCaptureInvalidatedException($error->getMessage(), 0, $error);
        }
        $connection = $this->entityManager()->getConnection();
        $state = $connection->fetchAssociative('SELECT url, status, manual_assessment, '
            .FindingWorkPolicy::checksAllowedSql($connection, 'finding').' AS checks_allowed FROM finding WHERE id = ?', [$finding->getId()]);
        if ($state === false || $state['url'] !== $finding->getUrl()
            || $state['manual_assessment'] === 'discarded'
            || in_array($state['status'], ['discarded', 'duplicate'], true)
            || !(bool) $state['checks_allowed']) {
            throw new InteractiveCaptureInvalidatedException('Interactive capture finding changed, was discarded, or no longer permits checks.');
        }
    }

    private function assertContext(Finding $finding, array $context, Connection $connection): void
    {
        if (!is_string($context['start_url'] ?? null) || $context['start_url'] !== $finding->getUrl()) {
            throw new InteractiveCaptureInvalidatedException('Interactive capture requires the exact start_url recorded at session start.');
        }
        if (($context['finding_id'] ?? null) !== $finding->getId()
            || ($context['start_url_hash'] ?? null) !== hash('sha256', $context['start_url'])) {
            throw new InteractiveCaptureInvalidatedException('Interactive capture session binding does not match the finding.');
        }
        $jobId = $context['trigger_job_id'] ?? null;
        $evidenceId = $context['trigger_evidence_id'] ?? null;
        if (($context['manual_entry'] ?? false) !== true || $jobId !== null || $evidenceId !== null) {
            if (!is_string($jobId) || $jobId === '' || !is_string($evidenceId) || $evidenceId === '') {
                throw new InteractiveCaptureInvalidatedException('Interactive capture requires its triggering screenshot job and evidence.');
            }
            $job = $connection->fetchAssociative(
                'SELECT url, status, screenshot_path, capture_metadata FROM screenshot_job WHERE id = ? AND finding_id = ?', [$jobId, $finding->getId()]);
            $evidence = $connection->fetchAssociative(
                'SELECT file_path, sha256 FROM evidence WHERE id = ? AND finding_id = ? AND kind = ?', [$evidenceId, $finding->getId(), EvidenceKind::SCREENSHOT]);
            if ($job === false || $evidence === false || $job['url'] !== $context['start_url']
                || $job['status'] !== ScreenshotJobStatus::AVAILABLE
                || !is_string($job['screenshot_path']) || $job['screenshot_path'] === ''
                || $job['screenshot_path'] !== $evidence['file_path']
                || !$this->storage->exists($evidence['file_path'])) {
                throw new InteractiveCaptureInvalidatedException('Interactive capture trigger no longer matches an available screenshot for the start URL.');
            }
            $metadata = $job['capture_metadata'] === null ? [] : json_decode($job['capture_metadata'], true);
            if (!is_array($metadata) || (($context['manual_entry'] ?? false) !== true
                && (($metadata['challengeDetected'] ?? null) !== true || ($metadata['challengeCleared'] ?? null) !== false
                || $this->latestEligibleTriggerId($finding, $context['start_url'], $connection) !== $jobId))) {
                throw new InteractiveCaptureInvalidatedException('Interactive capture trigger is no longer the latest eligible unresolved challenge screenshot.');
            }
            $hash = hash('sha256', json_encode([
                $jobId, $job['url'], $job['status'], $job['screenshot_path'], $metadata,
                $evidenceId, $evidence['file_path'], $evidence['sha256'],
            ], JSON_THROW_ON_ERROR));
            if (($context['trigger_hash'] ?? null) !== $hash) {
                throw new InteractiveCaptureInvalidatedException('Interactive capture trigger changed since session start.');
            }
        }
        if (array_key_exists('initial_challenge', $context) && !is_array($context['initial_challenge'])) {
            throw new InteractiveCaptureInvalidatedException('Initial challenge inspection must be an array.');
        }
        if (isset($context['initial_http_status']) && (!is_int($context['initial_http_status']) || $context['initial_http_status'] < 100 || $context['initial_http_status'] > 599)) {
            throw new InteractiveCaptureInvalidatedException('Initial HTTP status must be an HTTP status code or null.');
        }
        if (array_key_exists('manual_action_count', $context)
            && (!is_int($context['manual_action_count']) || $context['manual_action_count'] < 0)) {
            throw new InteractiveCaptureInvalidatedException('Manual action count must be a nonnegative integer.');
        }
        if (array_key_exists('trigger_challenge', $context) && !is_array($context['trigger_challenge'])) {
            throw new InteractiveCaptureInvalidatedException('Trigger challenge metadata must be an array.');
        }
        try {
            $this->sessionStartedAt($context);
        } catch (\InvalidArgumentException $error) {
            throw new InteractiveCaptureInvalidatedException('Interactive capture session creation time is invalid.', 0, $error);
        }
    }

    private function latestEligibleTriggerId(Finding $finding, string $url, Connection $connection): ?string
    {
        // Decide which available job is newest before testing its challenge or
        // evidence. Otherwise a newer cleared/missing image revives an old trigger.
        $id = $connection->fetchOne('SELECT id FROM screenshot_job '
            .'WHERE finding_id = ? AND url = ? AND status = ? '
            .'ORDER BY requested_at DESC, id DESC LIMIT 1',
            [$finding->getId(), $url, ScreenshotJobStatus::AVAILABLE]);

        return is_string($id) ? $id : null;
    }

    private function sessionStartedAt(array $context): ?\DateTimeImmutable
    {
        $value = $context['created_at'] ?? null;
        if ($value === null) {
            return null;
        }
        try {
            $date = is_int($value) ? new \DateTimeImmutable('@'.$value)
                : (is_string($value) ? new \DateTimeImmutable($value) : null);
        } catch (\Exception) {
            $date = null;
        }
        if ($date === null) {
            throw new \InvalidArgumentException('Interactive capture session creation time is invalid.');
        }

        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private function metadata(BrowserScreenshotResult $result, array $context): array
    {
        $worker = $result->metadata;
        $initial = $context['initial_challenge'] ?? $worker['initialChallenge'] ?? [];
        $current = $worker['challenge'] ?? $worker['currentChallenge'] ?? [];
        $initialState = $this->challengeState($initial, $worker, 'initial');
        $currentState = $this->challengeState($current, $worker, 'current');
        $trigger = ['jobId' => $context['trigger_job_id'] ?? null, 'evidenceId' => $context['trigger_evidence_id'] ?? null];

        return array_replace($worker, [
            // Preserve conflicting/nullable worker fields verbatim as well as
            // the canonical app binding and top-level capture result.
            'workerMetadata' => $worker,
            'interactive' => true,
            'manual' => true,
            'inputMethod' => $context['input_method'] ?? 'bounded-input',
            'manualActionCount' => ($context['input_method'] ?? null) === 'vnc' ? null : ($context['manual_action_count'] ?? null),
            'manualInteraction' => ($context['input_method'] ?? null) === 'vnc' ? null : (($context['manual_action_count'] ?? 0) > 0),
            'saveGeneration' => $context['lease_generation'] ?? null,
            'freshContext' => true,
            'retryOf' => $trigger,
            'triggeredBy' => $trigger,
            'triggerJobId' => $trigger['jobId'],
            'triggerEvidenceId' => $trigger['evidenceId'],
            'triggerChallenge' => $context['trigger_challenge'] ?? null,
            'initialUrl' => $context['start_url'],
            'finalUrl' => $result->finalUrl ?? $worker['finalUrl'] ?? null,
            'initialHttpStatus' => array_key_exists('initial_http_status', $context) ? $context['initial_http_status'] : ($worker['initialHttpStatus'] ?? null),
            'currentHttpStatus' => $result->httpStatus ?? $worker['currentHttpStatus'] ?? null,
            'httpStatus' => $result->httpStatus,
            'initialChallengeState' => $initialState,
            'currentChallengeState' => $currentState,
            'initialChallengeDetected' => $initialState === 'unknown' ? null : $initialState === 'detected',
            'challengeDetected' => $currentState === 'unknown' ? null : $currentState === 'detected',
            'challengeCleared' => $currentState === 'unknown' ? null : $currentState === 'cleared',
            'initialChallenge' => $initial,
            'challenge' => $current,
            'dialogSeen' => $result->dialogSeen,
            'dialogType' => $result->dialogType,
            'dialogText' => $result->dialogText,
            'captureMethod' => $result->captureMethod ?? $worker['captureMethod'] ?? null,
        ]);
    }

    private function challengeState(mixed $inspection, array $worker, string $phase): string
    {
        $inspection = is_array($inspection) ? $inspection : [];
        $snapshot = $phase === 'initial' ? ($worker['initialChallengeSnapshot'] ?? []) : ($worker['challengeSnapshot'] ?? []);
        if (!empty($inspection['error']) || !empty($inspection['snapshot']['error']) || !empty($snapshot['error'])) {
            return 'unknown';
        }
        if (array_key_exists('active', $inspection) && !is_bool($inspection['active'])) {
            return 'unknown';
        }
        $state = $inspection['state'] ?? null;
        if ($state === null && !array_key_exists('active', $inspection)) {
            $state = $worker[$phase.'ChallengeState'] ?? null;
        }
        if (in_array($state, ['detected', 'cleared', 'unknown'], true)) {
            if ($state !== 'unknown' && isset($inspection['active']) && ($state === 'detected') !== $inspection['active']) {
                return 'unknown';
            }
            return $state;
        }
        if ($state !== null) {
            return 'unknown';
        }
        // Explicit null denotes failed/unknown inspection, never false.
        $detected = array_key_exists('active', $inspection) ? $inspection['active']
            : ($worker[$phase === 'initial' ? 'initialChallengeDetected' : 'challengeDetected'] ?? null);
        if (is_bool($detected)) {
            if ($phase === 'current' && !$detected && ($worker['challengeCleared'] ?? null) === false) {
                return 'unknown';
            }
            return $detected ? 'detected' : 'cleared';
        }

        return 'unknown';
    }

    private function fileIsReferenced(Connection $connection, string $path): bool
    {
        return (bool) $connection->fetchOne('SELECT 1 FROM evidence WHERE file_path = ? UNION ALL '
            .'SELECT 1 FROM screenshot_job WHERE screenshot_path = ? UNION ALL '
            .'SELECT 1 FROM retest_run WHERE screenshot_path = ? LIMIT 1', [$path, $path, $path]);
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->doctrine->getManagerForClass(ScreenshotJob::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Interactive screenshot evidence requires a Doctrine ORM entity manager.');
        }

        return $manager;
    }
}
