<?php

namespace App\Tests;

use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Tests\Support\InMemoryDomainRepository;
use App\Tests\Support\InMemoryEvidenceRepository;
use App\Tests\Support\InMemoryFindingRepository;
use App\Tests\Support\InMemoryRetestRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

abstract class UnitTestCase extends TestCase
{
    protected const DEFAULT_FINDING_TYPE = 'reflected_xss';

    protected function createEntityManagerMock(array &$persisted = []): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $entityManager->method('remove')->willReturnCallback(static function (object $entity): void {
        });
        $entityManager->method('flush')->willReturnCallback(static function (): void {
        });

        return $entityManager;
    }

    protected function createValidator()
    {
        return Validation::createValidator();
    }

    protected function createRepositories(): array
    {
        return [
            'domains' => new InMemoryDomainRepository(),
            'evidence' => new InMemoryEvidenceRepository(),
            'findings' => new InMemoryFindingRepository(),
            'retestRuns' => new InMemoryRetestRunRepository(),
        ];
    }

    protected function wirePersistCallbacks(
        EntityManagerInterface $entityManager,
        InMemoryDomainRepository $domains,
        InMemoryEvidenceRepository $evidence,
        InMemoryFindingRepository $findings,
        InMemoryRetestRunRepository $runs,
    ): void
    {
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use ($domains, $evidence, $findings, $runs): void {
            if ($entity instanceof \App\Entity\Domain) {
                $domains->add($entity);
            } elseif ($entity instanceof Evidence) {
                $evidence->add($entity);
            } elseif ($entity instanceof Finding) {
                $findings->add($entity);
            } elseif ($entity instanceof RetestRun) {
                $runs->add($entity);
            }
        });
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use ($domains, $evidence, $findings, $runs): void {
            if ($entity instanceof Finding) {
                $findings->remove($entity);
            } elseif ($entity instanceof Evidence) {
                $evidence->remove($entity);
            } elseif ($entity instanceof RetestRun) {
                $runs->deleteByFinding($entity->getFinding());
            }
        });
    }
}
