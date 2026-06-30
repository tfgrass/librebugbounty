<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Service\EvidenceService;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use App\Service\ValidationService;
use App\Value\EvidenceKind;
use Symfony\Component\Filesystem\Filesystem;

final class EvidenceServiceTest extends UnitTestCase
{
    public function testClearingEvidenceRemovesFilesAndDatabaseEntries(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domain = new Domain();
        $domain->setHostname('example.com');
        $domain->setScheme('https');
        $domain->setAuthorized(true);
        $entityManager->persist($domain);

        $finding = new Finding();
        $finding->setDomain($domain);
        $finding->setTitle('Stored finding');
        $finding->setType(self::DEFAULT_FINDING_TYPE);
        $finding->setSeverity('medium');
        $finding->setStatus('verified');
        $finding->setUrl('https://example.com/search?q=test');
        $finding->setMethod('GET');
        $entityManager->persist($finding);

        $evidence = new Evidence();
        $evidence->setFinding($finding);
        $evidence->setKind(EvidenceKind::SCREENSHOT);
        $evidence->setFilePath('storage/artifacts/'.$finding->getId().'/preview.png');
        $finding->getEvidence()->add($evidence);
        $entityManager->persist($evidence);

        $artifactDir = dirname(__DIR__, 1).'/storage/artifacts/'.$finding->getId();
        if (!is_dir($artifactDir)) {
            mkdir($artifactDir, 0775, true);
        }
        file_put_contents($artifactDir.'/preview.png', 'test');

        $service = new EvidenceService(
            $repos['evidence'],
            $entityManager,
            new ValidationService($this->createValidator()),
            new class implements EvidenceStorageInterface {
                public function storeFile(\App\Entity\Finding $finding, string $sourcePath, ?string $targetFilename = null): \App\Dto\StoredEvidenceResult
                {
                    return new \App\Dto\StoredEvidenceResult('storage/artifacts/mock', 'deadbeef');
                }
            },
        );

        $service->clearEvidence($finding);

        self::assertCount(0, $repos['evidence']->findBy(['finding' => $finding]));
        self::assertFileDoesNotExist($artifactDir.'/preview.png');
        self::assertDirectoryDoesNotExist($artifactDir);
        self::assertCount(0, $finding->getEvidence());
    }

    public function testFreshStartAlsoClearsRetestMetadataAndNotes(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domain = new Domain();
        $domain->setHostname('example.com');
        $domain->setScheme('https');
        $domain->setAuthorized(true);
        $entityManager->persist($domain);

        $service = new FindingService(
            new \App\Service\DomainService(
                $repos['domains'],
                $entityManager,
                new ValidationService($this->createValidator()),
            ),
            $repos['findings'],
            $entityManager,
            new ValidationService($this->createValidator()),
            new Filesystem(),
        );

        $finding = $service->createFinding(
            url: 'https://example.com/search?q=test',
            privateNotes: 'note',
        );
        $finding->setStatus('verified');
        $finding->setReportedAt(new \DateTimeImmutable('-2 days'));
        $finding->setNotifiedOwnerAt(new \DateTimeImmutable('-1 day'));
        $finding->setLastRetestedAt(new \DateTimeImmutable('-1 hour'));

        $service->resetFreshStartState($finding);

        self::assertSame('new', $finding->getStatus());
        self::assertNull($finding->getPrivateNotes());
        self::assertNull($finding->getReportedAt());
        self::assertNull($finding->getNotifiedOwnerAt());
        self::assertNull($finding->getLastRetestedAt());
    }
}
