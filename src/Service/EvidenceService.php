<?php

namespace App\Service;

use App\Entity\Evidence;
use App\Entity\Finding;
use App\Repository\EvidenceRepository;
use App\Value\EvidenceKind;
use Doctrine\ORM\EntityManagerInterface;

final class EvidenceService
{
    public function __construct(
        private readonly EvidenceRepository $evidenceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidationService $validation,
        private readonly EvidenceStorageInterface $storage,
    ) {
    }

    public function addEvidence(Finding $finding, string $kind, ?string $value = null, ?string $filePath = null): Evidence
    {
        $this->validation->assertEvidenceKind($kind);

        $stored = null;
        if ($filePath !== null) {
            $stored = $this->storage->storeFile($finding, $filePath);
        }

        $evidence = new Evidence();
        $evidence->setFinding($finding);
        $evidence->setKind($kind);
        $evidence->setValue($value);
        $evidence->setFilePath($stored?->relativePath);
        $evidence->setSha256($stored?->sha256);

        try {
            $this->entityManager->persist($evidence);
            $this->entityManager->flush();
        } catch (\Throwable $error) {
            if ($stored !== null) {
                try {
                    $this->storage->deleteFile($stored->relativePath);
                } catch (\Throwable) {
                    throw new \RuntimeException('Evidence metadata could not be saved; an unreferenced file may remain. Run app:artifacts:audit.', 0, $error);
                }
            }
            throw $error;
        }

        return $evidence;
    }

    public function clearEvidence(Finding $finding): void
    {
        foreach ($this->evidenceRepository->findBy(['finding' => $finding]) as $evidence) {
            $this->entityManager->remove($evidence);
            $finding->getEvidence()->removeElement($evidence);
        }

        $this->entityManager->flush();

        $this->storage->deleteForFinding($finding);
    }
}
