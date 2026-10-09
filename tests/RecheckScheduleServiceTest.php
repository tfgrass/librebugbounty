<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\RecheckScheduleService;
use App\Value\ReviewState;

final class RecheckScheduleServiceTest extends DatabaseTestCase
{
    public function testShorteningPullsFutureNormalSlotsForwardAndProtectsClaimsBackoffsAndReview(): void
    {
        $domain = (new Domain())->setHostname('shorten.example')->setScheme('https');
        $this->entityManager->persist($domain);

        $oldSchedule = $this->finding($domain, 'old-schedule', '-30 days');
        $oldSchedule->setNextDueAt(new \DateTimeImmutable('+13 days'));
        $errorBackoff = $this->finding($domain, 'error-backoff', '-2 days');
        $errorBackoff->setNextDueAt(new \DateTimeImmutable('+1 day'));
        $claimed = $this->finding($domain, 'claimed', '-30 days');
        $claimed->setNextDueAt(new \DateTimeImmutable('+24 hours'));
        $normalSchedule = $this->finding($domain, 'normal-schedule', '-5 days');
        $normalSchedule->setNextDueAt(new \DateTimeImmutable('+9 days'));
        $manualChecking = $this->finding($domain, 'manual-checking', '-30 days');
        $manualChecking->setNextDueAt(new \DateTimeImmutable('+13 days'));
        $manualChecking->setReviewState(ReviewState::MANUAL_CHECKING);
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        $before = $this->slots($errorBackoff, $claimed, $manualChecking);
        $service = new RecheckScheduleService($connection);

        $shortened = $service->applyIntervalChange(14, 7, $now = new \DateTimeImmutable());

        self::assertSame(2, $shortened, 'Both future normal slots move; claims, error backoffs and manual review do not.');
        self::assertLessThanOrEqual($now->format('Y-m-d H:i:s'), $this->slot($oldSchedule));
        self::assertSame((new \DateTimeImmutable('-5 days'))->modify('+7 days')->format('Y-m-d H:i:s'), substr($this->slot($normalSchedule), 0, 19));
        foreach ([$errorBackoff, $claimed, $manualChecking] as $finding) {
            self::assertSame($before[$finding->getTitle()], $this->slot($finding));
        }
    }

    public function testEnlargingTheIntervalNeverMovesExistingSlots(): void
    {
        $domain = (new Domain())->setHostname('enlarge.example')->setScheme('https');
        $this->entityManager->persist($domain);
        $finding = $this->finding($domain, 'due-soon', '-30 days');
        $finding->setNextDueAt(new \DateTimeImmutable('+2 days'));
        $this->entityManager->flush();

        $service = new RecheckScheduleService($this->entityManager->getConnection());
        self::assertSame(0, $service->applyIntervalChange(7, 30, new \DateTimeImmutable()));
    }

    private function finding(Domain $domain, string $title, string $lastRetestedModifier): Finding
    {
        $finding = (new Finding())
            ->setDomain($domain)
            ->setTitle($title)
            ->setType('stored-case')
            ->setSeverity('medium')
            ->setStatus('reported')
            ->setUrl('https://'.$domain->getHostname().'/'.$title)
            ->setMethod('GET');
        $finding->setLastRetestedAt(new \DateTimeImmutable($lastRetestedModifier));
        $this->entityManager->persist($finding);

        return $finding;
    }

    private function slot(Finding $finding): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne(
            'SELECT next_due_at FROM finding WHERE id = ?',
            [$finding->getId()],
        );
    }

    /** @return array<string, string> */
    private function slots(Finding ...$findings): array
    {
        $slots = [];
        foreach ($findings as $finding) {
            $slots[$finding->getTitle()] = $this->slot($finding);
        }

        return $slots;
    }
}
