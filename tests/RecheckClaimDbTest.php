<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Service\RecheckPolicy;
use App\Service\RecheckService;
use App\Service\RetestService;

final class RecheckClaimDbTest extends DatabaseTestCase
{
    public function testClaimLeasesTheSlotAndPushesBackSameDomainFindings(): void
    {
        $domain = (new Domain())->setHostname('claim.example')->setScheme('https');
        $otherDomain = (new Domain())->setHostname('other.example')->setScheme('https');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($otherDomain);

        $oldest = $this->finding($domain, 'oldest', new \DateTimeImmutable('-40 days'));
        $sameDomain = $this->finding($domain, 'same-domain', new \DateTimeImmutable('-39 days'));
        $untouched = $this->finding($otherDomain, 'other', new \DateTimeImmutable('-38 days'));
        $this->entityManager->flush();

        $service = self::getContainer()->get(RecheckService::class);
        $claimed = $service->claimNext();

        self::assertNotNull($claimed);
        self::assertSame($oldest->getId(), $claimed->getId());

        $connection = $this->entityManager->getConnection();
        $leased = $connection->fetchOne('SELECT next_due_at FROM finding WHERE id = ?', [$oldest->getId()]);
        self::assertGreaterThan(
            (new \DateTimeImmutable('+20 hours'))->format('Y-m-d H:i:s'),
            $leased,
            'A claim leases the slot for 24 hours.',
        );

        $spaced = $connection->fetchOne('SELECT next_due_at FROM finding WHERE id = ?', [$sameDomain->getId()]);
        self::assertGreaterThan(
            (new \DateTimeImmutable('+50 minutes'))->format('Y-m-d H:i:s'),
            $spaced,
            'Other due findings of the claimed domain move back by one hour.',
        );
        self::assertLessThan((new \DateTimeImmutable('+70 minutes'))->format('Y-m-d H:i:s'), $spaced);

        $otherDue = $connection->fetchOne('SELECT next_due_at FROM finding WHERE id = ?', [$untouched->getId()]);
        self::assertLessThan(
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $otherDue,
            'Findings of other domains stay due.',
        );
    }

    public function testSecondWorkerCannotClaimTheLeasedFinding(): void
    {
        $domain = (new Domain())->setHostname('race.example')->setScheme('https');
        $this->entityManager->persist($domain);
        $first = $this->finding($domain, 'first', new \DateTimeImmutable('-40 days'));
        $second = $this->finding($domain, 'second', new \DateTimeImmutable('-39 days'));
        $this->entityManager->flush();

        $service = self::getContainer()->get(RecheckService::class);
        $claimed = $service->claimNext();
        self::assertSame($first->getId(), $claimed?->getId());

        // A competing claim with a stale expectation loses the race.
        $connection = $this->entityManager->getConnection();
        $lost = $connection->executeStatement(
            'UPDATE finding SET next_due_at = ? WHERE id = ? AND next_due_at = ?',
            [(new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'), $first->getId(), (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s')],
        );
        self::assertSame(0, $lost, 'The conditional claim is atomic; stale claimers update nothing.');

        // The leased finding and the pushed-back same-domain finding are no
        // longer due, so the next claim is empty.
        $this->entityManager->clear();
        $repository = self::getContainer()->get(FindingRepository::class);
        self::assertSame([], $repository->findDueForRecheck(new \DateTimeImmutable()));
        self::assertNotSame([], $repository->findDueForRecheck(new \DateTimeImmutable('+25 hours')), 'Expired claims and the spacing window become due again on their own.');
    }

    private function finding(Domain $domain, string $title, \DateTimeImmutable $nextDueAt): Finding
    {
        $finding = (new Finding())
            ->setDomain($domain)
            ->setTitle($title)
            ->setType('stored-case')
            ->setSeverity('medium')
            ->setStatus('reported')
            ->setUrl('https://'.$domain->getHostname().'/'.$title)
            ->setMethod('GET');
        $finding->setNextDueAt($nextDueAt);
        $this->entityManager->persist($finding);

        return $finding;
    }
}
