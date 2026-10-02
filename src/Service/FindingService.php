<?php

namespace App\Service;

use App\Dto\FindingCreateResult;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Repository\FindingRepository;
use App\Value\FindingSeverity;
use App\Value\FindingStatus;
use App\Value\ManualAssessment;
use App\Value\ReviewState;
use App\Value\ScreenshotJobStatus;
use Doctrine\ORM\EntityManagerInterface;

final class FindingService
{
    public function __construct(
        private readonly DomainService $domainService,
        private readonly FindingRepository $findings,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidationService $validation,
        private readonly EvidenceStorageInterface $storage,
        private readonly ?SettingsService $settings = null,
        private readonly ?ScreenshotOperationLock $screenshotOperationLock = null,
        private readonly ?ScreenshotQueueService $screenshotQueue = null,
    ) {
    }

    public function createFinding(
        string $url,
        ?string $hostname = null,
        ?string $title = null,
        ?string $type = null,
        string $severity = FindingSeverity::MEDIUM,
        string $method = 'GET',
        ?array $requestParams = null,
        ?string $payload = null,
        ?string $expectedEvidence = 'OPENBUGBOUNTY',
        ?string $privateNotes = null,
        ?string $reportUrl = null,
        ?\DateTimeImmutable $reportedAt = null,
        ?\DateTimeImmutable $submittedAt = null,
        ?\DateTimeImmutable $notifiedOwnerAt = null,
        string $status = FindingStatus::NEW,
        bool $allowUnauthorizedStore = false,
    ): Finding {
        return $this->createFindingResult(
            url: $url,
            hostname: $hostname,
            title: $title,
            type: $type,
            severity: $severity,
            method: $method,
            requestParams: $requestParams,
            payload: $payload,
            expectedEvidence: $expectedEvidence,
            privateNotes: $privateNotes,
            reportUrl: $reportUrl,
            reportedAt: $reportedAt,
            submittedAt: $submittedAt,
            notifiedOwnerAt: $notifiedOwnerAt,
            status: $status,
            allowUnauthorizedStore: $allowUnauthorizedStore,
        )->finding;
    }

    public function createIntakeFinding(
        string $url,
        ?string $expectedEvidence = null,
        ?string $privateNotes = null,
    ): FindingCreateResult {
        return $this->createFindingResult(
            url: $url,
            expectedEvidence: $expectedEvidence,
            privateNotes: $privateNotes,
        );
    }

    private function createFindingResult(
        string $url,
        ?string $hostname = null,
        ?string $title = null,
        ?string $type = null,
        string $severity = FindingSeverity::MEDIUM,
        string $method = 'GET',
        ?array $requestParams = null,
        ?string $payload = null,
        ?string $expectedEvidence = 'OPENBUGBOUNTY',
        ?string $privateNotes = null,
        ?string $reportUrl = null,
        ?\DateTimeImmutable $reportedAt = null,
        ?\DateTimeImmutable $submittedAt = null,
        ?\DateTimeImmutable $notifiedOwnerAt = null,
        string $status = FindingStatus::NEW,
        bool $allowUnauthorizedStore = false,
    ): FindingCreateResult {
        $url = trim($url);
        $this->validation->assertSeverity($severity);
        $this->validation->assertStatus($status);
        $this->validation->assertUrl($url);
        $this->validation->assertHttpMethod($method);

        $parsedUrl = parse_url($url);
        $resolvedHostname = $hostname !== null && $hostname !== ''
            ? $this->validation->normalizeHostname($hostname)
            : (isset($parsedUrl['host']) ? $this->validation->normalizeHostname((string) $parsedUrl['host']) : null);

        if ($resolvedHostname === null) {
            throw new \InvalidArgumentException('Unable to determine the domain hostname from the URL.');
        }

        $scheme = isset($parsedUrl['scheme']) && is_string($parsedUrl['scheme']) ? strtolower($parsedUrl['scheme']) : 'https';
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException(sprintf('Unsupported URL scheme "%s".', $scheme));
        }

        $createOrFind = function () use (
            $resolvedHostname,
            $scheme,
            $url,
            $title,
            $type,
            $severity,
            $status,
            $method,
            $requestParams,
            $payload,
            $expectedEvidence,
            $privateNotes,
            $reportUrl,
            $reportedAt,
            $submittedAt,
            $notifiedOwnerAt,
        ): FindingCreateResult {
            $domain = $this->domainService->upsertDomain($resolvedHostname, $scheme, true)->domain;
            $existing = $this->findings->findOneByDomainAndUrl($domain, $url);
            if ($existing instanceof Finding) {
                return new FindingCreateResult($existing, false);
            }

            $title ??= 'reflected_xss';
            $type ??= 'reflected_xss';
            $submittedAt ??= new \DateTimeImmutable();
            $expectedEvidence = $expectedEvidence !== null && trim($expectedEvidence) !== ''
                ? $expectedEvidence
                : $this->settings?->getDefaultPayload() ?? 'OPENBUGBOUNTY';

            $finding = new Finding();
            $finding->setDomain($domain);
            $finding->setTitle($title);
            $finding->setType($type);
            $finding->setSeverity($severity);
            $finding->setStatus($status);
            $finding->setUrl($url);
            $finding->setMethod($method);
            $finding->setRequestParams($requestParams);
            $finding->setPayload($payload);
            $finding->setExpectedEvidence($expectedEvidence);
            $finding->setPrivateNotes($privateNotes);
            $finding->setReportUrl($reportUrl);
            $finding->setReportedAt($reportedAt);
            $finding->setSubmittedAt($submittedAt);
            $finding->setNotifiedOwnerAt($notifiedOwnerAt);
            $finding->setReviewState(null);

            $initialScreenshotJob = (new ScreenshotJob())
                ->setFinding($finding)
                ->setUrl($finding->getUrl())
                ->setStatus(ScreenshotJobStatus::QUEUED)
                ->setActiveKey($finding->getId());

            // Doctrine commits both inserts in the same flush transaction. A
            // queue insert failure or process stop can therefore never leave a
            // newly committed finding without its durable screenshot work item.
            $this->entityManager->persist($finding);
            $this->entityManager->persist($initialScreenshotJob);
            $this->entityManager->flush();

            return new FindingCreateResult($finding, true);
        };

        $result = $this->screenshotOperationLock === null
            ? $createOrFind()
            : $this->screenshotOperationLock->synchronizedQueueMutation($createOrFind);

        // Older data and an interrupted pre-queue intake may not have a job.
        // Re-submitting the same URL repairs that invariant without creating a
        // second finding or replacing any of its fields. This happens after the
        // creation lock because ensureExists() acquires that lock itself.
        if (!$result->created && !$result->finding->isDiscarded()) {
            $this->screenshotQueue?->ensureExists($result->finding);
        }

        return $result;
    }

    public function getFindingOrFail(string $id): Finding
    {
        $finding = $this->findings->find($id);
        if (!$finding instanceof Finding) {
            throw new \RuntimeException(sprintf('Finding "%s" does not exist.', $id));
        }

        return $finding;
    }

    public function deleteFinding(Finding $finding): void
    {
        $delete = function () use ($finding): void {
            // SQLite foreign-key enforcement is not guaranteed for every
            // existing Doctrine connection. Remove every dependent record
            // explicitly so deleting a finding cannot create new orphans.
            foreach ([FindingAssessment::class, ScreenshotJob::class, Evidence::class, RetestRun::class] as $entityClass) {
                foreach ($this->entityManager->getRepository($entityClass)->findBy(['finding' => $finding]) as $related) {
                    $this->entityManager->remove($related);
                }
            }
            $this->storage->deleteForFinding($finding);
            $this->entityManager->remove($finding);
            $this->entityManager->flush();
        };
        if ($this->screenshotOperationLock === null) {
            $delete();
            return;
        }

        $this->screenshotOperationLock->synchronizedMaintenance($delete);
    }

    public function markAsOpen(Finding $finding): void
    {
        if ($finding->hasProtectedAssessment() || $finding->isDiscarded()) {
            return;
        }
        $finding->setStatus(FindingStatus::NEW);
        $this->entityManager->flush();
    }

    public function markVulnerable(Finding $finding): void
    {
        $this->assess($finding, ManualAssessment::CONFIRMED);
    }

    public function markContacted(Finding $finding): void
    {
        if ($finding->getContactedAt() !== null) {
            return;
        }
        $finding->setContactedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function confirmFixed(Finding $finding): void
    {
        $this->assess($finding, ManualAssessment::FIXED);
    }

    public function discardFinding(Finding $finding, ?string $discardReason = null): void
    {
        $this->assess($finding, ManualAssessment::DISCARDED, $discardReason);
    }

    public function assess(
        Finding $finding,
        string $assessment,
        ?string $discardReason = null,
        ?string $observationId = null,
        ?string $evidenceId = null,
    ): void {
        ManualAssessment::validate($assessment, $discardReason);
        $snapshot = [];
        if ($observationId !== null) {
            $observation = $this->entityManager->find(RetestRun::class, $observationId);
            if (!$observation instanceof RetestRun || $observation->getFinding()->getId() !== $finding->getId()) {
                throw new \InvalidArgumentException('The selected observation does not belong to this finding.');
            }
            $snapshot['observation'] = [
                'id' => $observation->getId(),
                'mode' => $observation->getMode(),
                'result' => $observation->getResult(),
                'startedAt' => $observation->getStartedAt()->format(DATE_ATOM),
                'finishedAt' => $observation->getFinishedAt()?->format(DATE_ATOM),
                'screenshotPath' => $observation->getScreenshotPath(),
            ];
        }
        if ($evidenceId !== null) {
            $evidence = $this->entityManager->find(Evidence::class, $evidenceId);
            if (!$evidence instanceof Evidence || $evidence->getFinding()->getId() !== $finding->getId()) {
                throw new \InvalidArgumentException('The selected evidence does not belong to this finding.');
            }
            $snapshot['evidence'] = [
                'id' => $evidence->getId(),
                'kind' => $evidence->getKind(),
                'storedAt' => $evidence->getCreatedAt()->format(DATE_ATOM),
                'filePath' => $evidence->getFilePath(),
                'sha256' => $evidence->getSha256(),
            ];
        }

        // Record which observations existed at this action. This is a neutral
        // arrival boundary, not a claim that any of them was assessed. IDs stay
        // reliable when technical records are reset and SQLite reuses rowids.
        $knownObservationIds = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM retest_run WHERE finding_id = ? ORDER BY rowid ASC',
            [$finding->getId()],
        );
        $assessedAt = new \DateTimeImmutable();
        $history = new FindingAssessment(
            $finding, $assessment, $discardReason, $assessedAt,
            $observationId, $evidenceId, $snapshot !== [] ? $snapshot : null,
            $knownObservationIds,
        );
        $finding->setManualAssessment($assessment, $discardReason, $assessedAt);
        // Keep existing list/CLI fields compatible; only this explicit manual
        // action may replace a protected decision.
        $finding->setStatus(match ($assessment) {
            ManualAssessment::CONFIRMED => FindingStatus::VERIFIED,
            ManualAssessment::FIXED => FindingStatus::FIXED,
            ManualAssessment::DISCARDED => FindingStatus::DISCARDED,
        });
        $finding->setReviewState(match ($assessment) {
            ManualAssessment::CONFIRMED => ReviewState::MANUALLY_CHECKED,
            ManualAssessment::FIXED => ReviewState::CONFIRMED_FIXED,
            ManualAssessment::DISCARDED => null,
        });
        $this->entityManager->persist($history);
        // Doctrine's flush transaction writes current assessment and history
        // together; a failure cannot commit either half on its own.
        $this->entityManager->flush();
    }

    public function markManualChecking(Finding $finding): void
    {
        if ($finding->hasProtectedAssessment() || $finding->isDiscarded()) {
            return;
        }
        $finding->setReviewState(ReviewState::MANUAL_CHECKING);
        $this->entityManager->flush();
    }

    public function resetVerificationState(Finding $finding): void
    {
        if (!$finding->hasProtectedAssessment() && !$finding->isDiscarded()) {
            $finding->setStatus(FindingStatus::NEW);
            $finding->setReviewState(null);
        }
        $finding->setLastRetestedAt(null);
        $this->entityManager->flush();
    }

    public function resetFreshStartState(Finding $finding): void
    {
        if (!$finding->hasProtectedAssessment() && !$finding->isDiscarded()) {
            $finding->setStatus(FindingStatus::NEW);
            $finding->setReviewState(null);
        }
        $finding->setPrivateNotes(null);
        $finding->setReportedAt(null);
        $finding->setNotifiedOwnerAt(null);
        $finding->setContactedAt(null);
        $finding->setLastRetestedAt(null);
        $this->entityManager->flush();
    }
}
