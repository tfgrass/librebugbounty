<?php

namespace App\Service;

use App\Dto\ResetResult;
use App\Entity\Evidence;
use App\Entity\RetestRun;
use App\Repository\EvidenceRepository;
use App\Repository\FindingRepository;
use App\Repository\RetestRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

final class ResetService
{
    public function __construct(
        private readonly FindingRepository $findings,
        private readonly EvidenceRepository $evidenceRepository,
        private readonly RetestRunRepository $retestRuns,
        private readonly FindingService $findingService,
        private readonly EntityManagerInterface $entityManager,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function resetAll(): ResetResult
    {
        $result = new ResetResult();

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

        $artifactRoot = dirname(__DIR__, 2).'/storage/artifacts';
        $this->filesystem->remove($artifactRoot);
        $this->filesystem->mkdir($artifactRoot);
        $result->artifactDirectoriesRemoved = 1;

        $this->entityManager->flush();

        return $result;
    }

    public function resetVerificationState(): ResetResult
    {
        $result = new ResetResult();

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

        $artifactRoot = dirname(__DIR__, 2).'/storage/artifacts';
        $this->filesystem->remove($artifactRoot);
        $this->filesystem->mkdir($artifactRoot);
        $result->artifactDirectoriesRemoved = 1;

        $this->entityManager->flush();

        return $result;
    }
}
