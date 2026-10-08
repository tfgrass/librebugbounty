<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Repository\FindingRepository;

final class RecheckRepositoryTest extends DatabaseTestCase
{
    public function testFindDueForRecheckOrdersByDueDateAndIgnoresFutureSlots(): void
    {
        $domain = (new Domain())->setHostname('due.example')->setScheme('https');
        $this->entityManager->persist($domain);

        $future = $this->finding($domain, 'future', new \DateTimeImmutable('+2 days'));
        $oldest = $this->finding($domain, 'oldest', new \DateTimeImmutable('-40 days'));
        $recent = $this->finding($domain, 'recent', new \DateTimeImmutable('-1 day'));
        $unscheduled = $this->finding($domain, 'unscheduled', null);
        $this->entityManager->flush();

        $repository = self::getContainer()->get(FindingRepository::class);
        $due = $repository->findDueForRecheck(new \DateTimeImmutable());

        self::assertSame([$oldest->getId(), $recent->getId()], array_map(static fn (Finding $f): string => $f->getId(), $due));
    }

    private function finding(Domain $domain, string $title, ?\DateTimeImmutable $nextDueAt): Finding
    {
        $finding = (new Finding())
            ->setDomain($domain)
            ->setTitle($title)
            ->setType('stored-case')
            ->setSeverity('medium')
            ->setStatus('reported')
            ->setUrl('https://due.example/'.$title)
            ->setMethod('GET');
        $finding->setNextDueAt($nextDueAt);
        $this->entityManager->persist($finding);

        return $finding;
    }
}
