<?php

namespace App\Service;

use App\Dto\ResetResult;
use App\Entity\Evidence;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Repository\EvidenceRepository;
use App\Repository\FindingRepository;
use App\Repository\RetestRunRepository;
use App\Repository\ScreenshotJobRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ResetService
{
    public function __construct(
        private readonly FindingRepository $findings,
        private readonly EvidenceRepository $evidenceRepository,
        private readonly RetestRunRepository $retestRuns,
        private readonly FindingService $findingService,
        private readonly EntityManagerInterface $entityManager,
        private readonly EvidenceStorageInterface $storage,
        private readonly ?ScreenshotJobRepository $screenshotJobs = null,
        private readonly ?ScreenshotOperationLock $screenshotOperationLock = null,
    ) {
    }

    /** @return array<string, int> Read-only preview of the explicit reset's scope. */
    public function preview(): array
    {
        return [
            'Findings reset (not deleted)' => count($this->findings->findAllOrdered(PHP_INT_MAX)),
            'Evidence records deleted' => count($this->evidenceRepository->findAll()),
            'Run records deleted' => count($this->retestRuns->findAll()),
            'Screenshot jobs deleted' => count($this->screenshotJobs?->findAll() ?? []),
            'Files in configured artifact storage' => count($this->storage->listPaths()),
        ];
    }

    public function resetAll(): ResetResult
    {
        return $this->withScreenshotLock(fn (): ResetResult => $this->resetAllWithLock());
    }

    private function resetAllWithLock(): ResetResult
    {
        $result = new ResetResult();

        $this->removeScreenshotJobs($result);
        foreach ($this->evidenceRepository->findAll() as $evidence) {
            \assert($evidence instanceof Evidence);
            $this->entityManager->remove($evidence);
            $result->evidenceDeleted++;
        }

        foreach ($this->retestRuns->findAll() as $run) {
            \assert($run instanceof RetestRun);
            $this->entityManager->remove($run);
            $result->retestRunsDeleted++;
        }

        foreach ($this->findings->findAllOrdered(PHP_INT_MAX) as $finding) {
            $this->findingService->resetFreshStartState($finding);
            $result->findingsReset++;
        }

        $this->storage->clear();
        $result->artifactDirectoriesRemoved = 1;

        $this->entityManager->flush();

        return $result;
    }

    public function resetVerificationState(): ResetResult
    {
        return $this->withScreenshotLock(fn (): ResetResult => $this->resetVerificationStateWithLock());
    }

    private function resetVerificationStateWithLock(): ResetResult
    {
        $result = new ResetResult();

        $this->removeScreenshotJobs($result);
        foreach ($this->evidenceRepository->findAll() as $evidence) {
            \assert($evidence instanceof Evidence);
            $this->entityManager->remove($evidence);
            $result->evidenceDeleted++;
        }

        foreach ($this->retestRuns->findAll() as $run) {
            \assert($run instanceof RetestRun);
            $this->entityManager->remove($run);
            $result->retestRunsDeleted++;
        }

        foreach ($this->findings->findAllOrdered(PHP_INT_MAX) as $finding) {
            $this->findingService->resetVerificationState($finding);
            $result->findingsReset++;
        }

        $this->storage->clear();
        $result->artifactDirectoriesRemoved = 1;

        $this->entityManager->flush();

        return $result;
    }

    private function removeScreenshotJobs(ResetResult $result): void
    {
        foreach ($this->screenshotJobs?->findAll() ?? [] as $job) {
            \assert($job instanceof ScreenshotJob);
            $this->entityManager->remove($job);
            $result->screenshotJobsDeleted++;
        }
    }

    private function withScreenshotLock(callable $operation): ResetResult
    {
        if ($this->screenshotOperationLock === null) {
            return $operation();
        }

        return $this->screenshotOperationLock->synchronizedMaintenance($operation);
    }
}
