<?php

namespace App\Tests;

use App\Service\DomainService;
use App\Service\FindingService;
use App\Service\ValidationService;
use Symfony\Component\Filesystem\Filesystem;

final class FindingServiceTest extends UnitTestCase
{
    public function testFindingCanBeStoredFromUrlOnly(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $service = new FindingService(
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

        $finding = $service->createFinding(
            url: 'https://example.com/search?q=test',
        );

        self::assertSame('example.com', $finding->getDomain()->getHostname());
        self::assertSame(self::DEFAULT_FINDING_TYPE, $finding->getTitle());
        self::assertSame(self::DEFAULT_FINDING_TYPE, $finding->getType());
        self::assertSame('OPENBUGBOUNTY', $finding->getExpectedEvidence());
        self::assertNotNull($finding->getSubmittedAt());
    }

    public function testFindingCanBeStoredForExistingDomain(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domainService = new DomainService(
            $repos['domains'],
            $entityManager,
            new ValidationService($this->createValidator()),
        );
        $domainService->upsertDomain('example.com', 'https', true);

        $service = new FindingService(
            $domainService,
            $repos['findings'],
            $entityManager,
            new ValidationService($this->createValidator()),
            new Filesystem(),
        );

        $finding = $service->createFinding(
            url: 'https://example.com/search?q=test',
            hostname: 'example.com',
            title: 'Reflected XSS',
            type: self::DEFAULT_FINDING_TYPE,
            severity: 'medium',
            payload: 'test-token',
            expectedEvidence: 'test-token',
            allowUnauthorizedStore: false,
        );

        self::assertSame('Reflected XSS', $finding->getTitle());
        self::assertSame('example.com', $finding->getDomain()->getHostname());
        self::assertCount(1, $repos['findings']->findByDomainAndStatus());
    }

    public function testDuplicateUrlReusesTheExistingFinding(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $service = new FindingService(
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

        $first = $service->createFinding(url: 'https://example.com/search?q=test');
        $second = $service->createFinding(url: 'https://example.com/search?q=test');

        self::assertSame($first->getId(), $second->getId());
        self::assertCount(1, $repos['findings']->findByDomainAndStatus());
    }

    public function testLeadingWhitespaceInUrlIsTrimmed(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $service = new FindingService(
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

        $finding = $service->createFinding(url: ' https://example.com/search?q=test');

        self::assertSame('https://example.com/search?q=test', $finding->getUrl());
    }

    public function testFindingDeletionRemovesStoredArtifactsAndFinding(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $service = new FindingService(
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

        $finding = $service->createFinding(url: 'https://example.com/search?q=test');

        $artifactDir = dirname(__DIR__, 1).'/storage/artifacts/'.$finding->getId();
        if (!is_dir($artifactDir)) {
            mkdir($artifactDir, 0775, true);
        }
        file_put_contents($artifactDir.'/preview.png', 'test');

        self::assertFileExists($artifactDir.'/preview.png');

        $service->deleteFinding($finding);

        self::assertCount(0, $repos['findings']->findByDomainAndStatus());
        self::assertFileDoesNotExist($artifactDir.'/preview.png');
        self::assertDirectoryDoesNotExist($artifactDir);
    }

    public function testFindingCanBeMarkedOpenAgain(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $service = new FindingService(
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

        $finding = $service->createFinding(url: 'https://example.com/search?q=test');
        $finding->setStatus('fixed');

        $service->markAsOpen($finding);

        self::assertSame('new', $finding->getStatus());
    }

    public function testFindingVerificationStateCanBeReset(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $service = new FindingService(
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

        $finding = $service->createFinding(url: 'https://example.com/search?q=test');
        $finding->setStatus('verified');
        $finding->setLastRetestedAt(new \DateTimeImmutable('-1 day'));

        $service->resetVerificationState($finding);

        self::assertSame('new', $finding->getStatus());
        self::assertNull($finding->getLastRetestedAt());
        self::assertNull($finding->getReviewState());
    }

    public function testFindingCanBeMarkedVulnerableForManualReview(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $service = new FindingService(
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

        $finding = $service->createFinding(url: 'https://example.com/search?q=test');

        $service->markVulnerable($finding);

        self::assertSame('verified', $finding->getStatus());
        self::assertSame('manual_checking', $finding->getReviewState());
    }

    public function testFindingCanBeConfirmedFixed(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $service = new FindingService(
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

        $finding = $service->createFinding(url: 'https://example.com/search?q=test');

        $service->confirmFixed($finding);

        self::assertSame('fixed', $finding->getStatus());
        self::assertSame('confirmed_fixed', $finding->getReviewState());
    }

    public function testFindingCanBeResetToFreshStart(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $service = new FindingService(
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

        $finding = $service->createFinding(url: 'https://example.com/search?q=test', privateNotes: 'old note');
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
        self::assertNull($finding->getReviewState());
    }
}
