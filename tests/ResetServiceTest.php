<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\RetestRun;
use App\Service\DomainService;
use App\Service\FindingService;
use App\Service\ResetService;
use App\Service\ValidationService;
use App\Value\EvidenceKind;
use Symfony\Component\Filesystem\Filesystem;

final class ResetServiceTest extends UnitTestCase
{
    public function testResetServiceClearsEvidenceRunsArtifactsAndReturnsFindingsToFreshState(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domain = new Domain();
        $domain->setHostname('example.com');
        $domain->setScheme('https');
        $domain->setAuthorized(true);
        $entityManager->persist($domain);

        $findingService = new FindingService(
            new DomainService(
                $repos['domains'],
                $entityManager,
                new ValidationService($this->createValidator()),
            ),
            $repos['findings'],
            $entityManager,
            new ValidationService($this->createValidator()),
            new Filesystem(),
        );

        $finding = $findingService->createFinding(
            url: 'https://example.com/search?q=test',
            privateNotes: 'keep off',
        );
        $finding->setStatus('verified');
        $finding->setReviewState('manual_checking');

        $evidence = new Evidence();
        $evidence->setFinding($finding);
        $evidence->setKind(EvidenceKind::SCREENSHOT);
        $evidence->setFilePath('storage/artifacts/'.$finding->getId().'/shot.png');
        $evidence->setSha256('deadbeef');
        $entityManager->persist($evidence);

        $run = new RetestRun();
        $run->setFinding($finding);
        $run->setMode('browser');
        $run->setResult('fixed');
        $run->setStartedAt(new \DateTimeImmutable('-1 hour'));
        $run->setFinishedAt(new \DateTimeImmutable('-1 hour'));
        $entityManager->persist($run);

        $artifactDir = dirname(__DIR__, 1).'/storage/artifacts/'.$finding->getId();
        $testFilesystem = new Filesystem();
        if (is_dir($artifactDir)) {
            $testFilesystem->remove($artifactDir);
        }
        mkdir($artifactDir, 0775, true);
        file_put_contents($artifactDir.'/shot.png', 'binary');

        self::assertFileExists($artifactDir.'/shot.png');
        self::assertSame('verified', $finding->getStatus());
        self::assertSame('manual_checking', $finding->getReviewState());

        $service = new ResetService(
            $repos['findings'],
            $repos['evidence'],
            $repos['retestRuns'],
            $findingService,
            $entityManager,
            new Filesystem(),
        );

        $result = $service->resetAll();

        self::assertSame(1, $result->findingsReset);
        self::assertSame(1, $result->evidenceDeleted);
        self::assertSame(1, $result->retestRunsDeleted);
        self::assertSame(1, $result->artifactDirectoriesRemoved);
        self::assertSame('new', $finding->getStatus());
        self::assertNull($finding->getPrivateNotes());
        self::assertNull($finding->getReviewState());
        self::assertFileDoesNotExist($artifactDir.'/shot.png');
        self::assertDirectoryExists(dirname(__DIR__, 1).'/storage/artifacts');
        self::assertCount(0, $repos['evidence']->findAll());
        self::assertCount(0, $repos['retestRuns']->findAll());
    }

    public function testVerificationResetKeepsFindingMetadataButClearsVerificationState(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domain = new Domain();
        $domain->setHostname('example.com');
        $domain->setScheme('https');
        $domain->setAuthorized(true);
        $entityManager->persist($domain);

        $findingService = new FindingService(
            new DomainService(
                $repos['domains'],
                $entityManager,
                new ValidationService($this->createValidator()),
            ),
            $repos['findings'],
            $entityManager,
            new ValidationService($this->createValidator()),
            new Filesystem(),
        );

        $submittedAt = new \DateTimeImmutable('-2 days');
        $finding = $findingService->createFinding(
            url: 'https://example.com/search?q=test',
            privateNotes: 'keep this',
            submittedAt: $submittedAt,
            reportUrl: 'https://report.example.com/abc',
            reportedAt: new \DateTimeImmutable('-1 day'),
        );
        $finding->setStatus('fixed');
        $finding->setReviewState('confirmed_fixed');
        $finding->setLastRetestedAt(new \DateTimeImmutable('-1 hour'));

        $evidence = new Evidence();
        $evidence->setFinding($finding);
        $evidence->setKind(EvidenceKind::SCREENSHOT);
        $evidence->setFilePath('storage/artifacts/'.$finding->getId().'/shot.png');
        $evidence->setSha256('deadbeef');
        $entityManager->persist($evidence);

        $run = new RetestRun();
        $run->setFinding($finding);
        $run->setMode('browser');
        $run->setResult('fixed');
        $run->setStartedAt(new \DateTimeImmutable('-1 hour'));
        $run->setFinishedAt(new \DateTimeImmutable('-1 hour'));
        $entityManager->persist($run);

        $service = new ResetService(
            $repos['findings'],
            $repos['evidence'],
            $repos['retestRuns'],
            $findingService,
            $entityManager,
            new Filesystem(),
        );

        $result = $service->resetVerificationState();

        self::assertSame(1, $result->findingsReset);
        self::assertSame('new', $finding->getStatus());
        self::assertNull($finding->getReviewState());
        self::assertNull($finding->getLastRetestedAt());
        self::assertSame('keep this', $finding->getPrivateNotes());
        self::assertSame('https://report.example.com/abc', $finding->getReportUrl());
        self::assertSame($submittedAt->format(DATE_ATOM), $finding->getSubmittedAt()?->format(DATE_ATOM));
        self::assertCount(0, $repos['evidence']->findAll());
        self::assertCount(0, $repos['retestRuns']->findAll());
    }
}
