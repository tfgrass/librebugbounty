<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\RecheckCatchUpService;
use App\Value\ManualAssessment;
use App\Value\ReviewState;

final class RecheckCatchUpTest extends DatabaseTestCase
{
    public function testPreviewSeparatesDueWaitingAndClaimedFindings(): void
    {
        $domain = (new Domain())->setHostname('catchup.example')->setScheme('https');
        $this->entityManager->persist($domain);

        $due = $this->finding($domain, 'due', 'reported', new \DateTimeImmutable('-30 days'));
        $due->setNextDueAt(new \DateTimeImmutable('-30 days'));
        $waiting = $this->finding($domain, 'waiting', 'reported', new \DateTimeImmutable('-30 days'));
        $waiting->setNextDueAt(new \DateTimeImmutable('+14 days'));
        $fresh = $this->finding($domain, 'fresh', 'reported', new \DateTimeImmutable('-5 days'));
        $fresh->setNextDueAt(new \DateTimeImmutable('+23 days'));
        $claimed = $this->finding($domain, 'claimed', 'reported', new \DateTimeImmutable('-30 days'));
        $claimed->setNextDueAt(new \DateTimeImmutable('+24 hours'));
        $crashed = $this->finding($domain, 'crashed', 'reported', new \DateTimeImmutable('-30 days'));
        $crashed->setNextDueAt(new \DateTimeImmutable('+20 hours'));
        $fixed = $this->finding($domain, 'fixed', 'fixed', new \DateTimeImmutable('-30 days'));
        $this->entityManager->flush();

        $service = new RecheckCatchUpService($this->entityManager->getConnection());
        $preview = $service->preview(new \DateTimeImmutable('-14 days'), new \DateTimeImmutable());

        self::assertSame(4, $preview['eligible'], 'due + waiting + claimed + crashed claim are stale and in scope; fresh and fixed are not.');
        self::assertSame(1, $preview['alreadyDue']);
        self::assertSame(3, $preview['waiting']);
        self::assertSame(1, $preview['claimed'], 'Only the fresh 24-hour lease counts as claimed; the 20-hour slot is a crashed worker.');
    }

    public function testPullDueReschedulesWaitingSlotsButNeverTouchesClaims(): void
    {
        $domain = (new Domain())->setHostname('pull.example')->setScheme('https');
        $this->entityManager->persist($domain);

        $due = $this->finding($domain, 'due', 'reported', new \DateTimeImmutable('-30 days'));
        $due->setNextDueAt(new \DateTimeImmutable('-30 days'));
        $waiting = $this->finding($domain, 'waiting', 'verified', new \DateTimeImmutable('-30 days'));
        $waiting->setNextDueAt(new \DateTimeImmutable('+14 days'));
        $spaced = $this->finding($domain, 'spaced', 'wontfix', new \DateTimeImmutable('-30 days'));
        $spaced->setNextDueAt(new \DateTimeImmutable('+45 minutes'));
        $claimed = $this->finding($domain, 'claimed', 'reported', new \DateTimeImmutable('-30 days'));
        $claimed->setNextDueAt(new \DateTimeImmutable('+24 hours'));
        $crashed = $this->finding($domain, 'crashed', 'reported', new \DateTimeImmutable('-30 days'));
        $crashed->setNextDueAt(new \DateTimeImmutable('+20 hours'));
        $protected = $this->finding($domain, 'protected', 'reported', new \DateTimeImmutable('-30 days'));
        $protected->setNextDueAt(new \DateTimeImmutable('+14 days'));
        $protected->setManualAssessment(ManualAssessment::CONFIRMED, null, new \DateTimeImmutable());
        $reviewed = $this->finding($domain, 'reviewed', 'reported', new \DateTimeImmutable('-30 days'));
        $reviewed->setNextDueAt(new \DateTimeImmutable('+14 days'));
        $reviewed->setReviewState(ReviewState::MANUALLY_CHECKED);
        $discarded = $this->finding($domain, 'discarded', 'reported', new \DateTimeImmutable('-30 days'));
        $discarded->setNextDueAt(new \DateTimeImmutable('+14 days'));
        $discarded->setManualAssessment(ManualAssessment::DISCARDED, null, new \DateTimeImmutable());
        $fresh = $this->finding($domain, 'fresh', 'reported', new \DateTimeImmutable('-5 days'));
        $fresh->setNextDueAt(new \DateTimeImmutable('+23 days'));
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        $claimedBefore = $connection->fetchOne('SELECT next_due_at FROM finding WHERE id = ?', [$claimed->getId()]);

        $service = new RecheckCatchUpService($connection);
        $pulled = $service->pullDue(new \DateTimeImmutable('-14 days'), $now = new \DateTimeImmutable());

        self::assertSame(3, $pulled, 'The 14-day wait, the 45-minute spacing slot and the crashed 20-hour claim move to now; the live claim survives.');

        foreach ([$waiting, $spaced, $crashed] as $finding) {
            $slot = $connection->fetchOne('SELECT next_due_at FROM finding WHERE id = ?', [$finding->getId()]);
            self::assertLessThanOrEqual($now->format('Y-m-d H:i:s'), $slot);
        }

        // Due slots stay untouched (no rewrite needed), claims survive,
        // protected/reviewed/discarded/fresh findings keep their schedule.
        self::assertSame(
            (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s'),
            substr((string) $connection->fetchOne('SELECT next_due_at FROM finding WHERE id = ?', [$due->getId()]), 0, 19),
        );
        self::assertSame($claimedBefore, $connection->fetchOne('SELECT next_due_at FROM finding WHERE id = ?', [$claimed->getId()]));
        foreach ([$protected, $reviewed, $discarded, $fresh] as $finding) {
            $slot = $connection->fetchOne('SELECT next_due_at FROM finding WHERE id = ?', [$finding->getId()]);
            self::assertGreaterThan($now->modify('+1 hour')->format('Y-m-d H:i:s'), $slot);
        }
    }

    private function finding(Domain $domain, string $title, string $status, \DateTimeImmutable $lastRetestedAt): Finding
    {
        $finding = (new Finding())
            ->setDomain($domain)
            ->setTitle($title)
            ->setType('stored-case')
            ->setSeverity('medium')
            ->setStatus($status)
            ->setUrl('https://'.$domain->getHostname().'/'.$title)
            ->setMethod('GET');
        $finding->setLastRetestedAt($lastRetestedAt);
        $this->entityManager->persist($finding);

        return $finding;
    }
}
