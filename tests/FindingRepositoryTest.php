<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Value\EvidenceKind;

final class FindingRepositoryTest extends UnitTestCase
{
    public function testFindAllWithoutScreenshotEvidenceSkipsFindingsThatAlreadyHaveScreenshots(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domain = new Domain();
        $domain->setHostname('example.com');
        $domain->setScheme('https');
        $domain->setAuthorized(true);
        $entityManager->persist($domain);

        $withScreenshot = new Finding();
        $withScreenshot->setDomain($domain);
        $withScreenshot->setTitle('Has screenshot');
        $withScreenshot->setType(self::DEFAULT_FINDING_TYPE);
        $withScreenshot->setSeverity('medium');
        $withScreenshot->setStatus('verified');
        $withScreenshot->setUrl('https://example.com/a');
        $withScreenshot->setMethod('GET');
        $entityManager->persist($withScreenshot);

        $evidence = new Evidence();
        $evidence->setFinding($withScreenshot);
        $evidence->setKind(EvidenceKind::SCREENSHOT);
        $withScreenshot->getEvidence()->add($evidence);

        $withoutScreenshot = new Finding();
        $withoutScreenshot->setDomain($domain);
        $withoutScreenshot->setTitle('Needs screenshot');
        $withoutScreenshot->setType(self::DEFAULT_FINDING_TYPE);
        $withoutScreenshot->setSeverity('medium');
        $withoutScreenshot->setStatus('verified');
        $withoutScreenshot->setUrl('https://example.com/b');
        $withoutScreenshot->setMethod('GET');
        $entityManager->persist($withoutScreenshot);

        $results = $repos['findings']->findAllWithoutScreenshotEvidence();

        self::assertCount(1, $results);
        self::assertSame($withoutScreenshot->getId(), $results[0]->getId());
    }

    public function testFindPageByDomainAndStatusFiltersAndPaginates(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domainA = new Domain();
        $domainA->setHostname('alpha.example.com');
        $domainA->setScheme('https');
        $domainA->setAuthorized(true);
        $entityManager->persist($domainA);

        $domainB = new Domain();
        $domainB->setHostname('beta.example.com');
        $domainB->setScheme('https');
        $domainB->setAuthorized(true);
        $entityManager->persist($domainB);

        for ($index = 0; $index < 3; $index++) {
            $finding = new Finding();
            $finding->setDomain($domainA);
            $finding->setTitle('Alpha '.$index);
            $finding->setType(self::DEFAULT_FINDING_TYPE);
            $finding->setSeverity('medium');
            $finding->setStatus($index === 0 ? 'fixed' : 'verified');
            $finding->setUrl('https://alpha.example.com/'.$index);
            $finding->setMethod('GET');
            $finding->setSubmittedAt(new \DateTimeImmutable(sprintf('-%d days', 3 - $index)));
            $entityManager->persist($finding);
        }

        $other = new Finding();
        $other->setDomain($domainB);
        $other->setTitle('Beta');
        $other->setType(self::DEFAULT_FINDING_TYPE);
        $other->setSeverity('medium');
        $other->setStatus('verified');
        $other->setUrl('https://beta.example.com/');
        $other->setMethod('GET');
        $entityManager->persist($other);

        $results = $repos['findings']->findPageByDomainAndStatus('alpha', 'verified', 1, 0);

        self::assertCount(1, $results);
        self::assertSame('alpha.example.com', $results[0]->getDomain()->getHostname());
        self::assertSame('verified', $results[0]->getStatus());
        self::assertSame(2, $repos['findings']->countByDomainAndStatus('alpha', 'verified'));
    }
}
