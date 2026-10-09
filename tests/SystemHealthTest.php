<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScreenshotJob;
use App\Service\SystemHealthService;
use App\Service\WorkerHeartbeatService;
use App\Value\ScreenshotJobStatus;

final class SystemHealthTest extends DatabaseTestCase
{
    public function testSnapshotReportsWorkerLivenessAndQueueState(): void
    {
        $domain = (new Domain())->setHostname('health.example')->setScheme('https');
        $this->entityManager->persist($domain);

        $due = $this->finding($domain, 'due', 'reported');
        $due->setNextDueAt(new \DateTimeImmutable('-1 hour'));
        $scheduled = $this->finding($domain, 'scheduled', 'reported');
        $scheduled->setNextDueAt(new \DateTimeImmutable('+10 days'));
        $paused = $this->finding($domain, 'paused', 'reported');
        $paused->setNextDueAt(new \DateTimeImmutable('+5 days'));
        $paused->setReviewState(\App\Value\ReviewState::MANUAL_CHECKING);

        $queued = new ScreenshotJob();
        $queued->setFinding($due)->setUrl($due->getUrl())->setStatus(ScreenshotJobStatus::QUEUED);
        $queued->setActiveKey($due->getId());
        $failed = new ScreenshotJob();
        $failed->setFinding($scheduled)->setUrl($scheduled->getUrl())->setStatus(ScreenshotJobStatus::FAILED);
        $this->entityManager->persist($queued);
        $this->entityManager->persist($failed);
        $this->entityManager->flush();

        $health = self::getContainer()->get(SystemHealthService::class);
        $snapshot = $health->snapshot();

        self::assertFalse($snapshot['recheck']['active']);
        self::assertNull($snapshot['recheck']['ageSeconds']);
        self::assertSame(1, $snapshot['recheck']['dueNow']);
        self::assertSame(1, $snapshot['recheck']['scheduled']);
        self::assertSame(1, $snapshot['recheck']['pausedManual']);
        self::assertSame($due->getNextDueAt()->format('Y-m-d H:i:s'), $snapshot['recheck']['nextDueAt']?->format('Y-m-d H:i:s'));
        self::assertSame(14, $snapshot['recheck']['intervalDays']);
        self::assertSame(3, $snapshot['recheck']['errorBackoffDays']);
        self::assertSame(1, $snapshot['screenshot']['queued']);
        self::assertSame(1, $snapshot['screenshot']['failed']);
        self::assertSame(0, $snapshot['screenshot']['running']);

        self::getContainer()->get(WorkerHeartbeatService::class)->touch(WorkerHeartbeatService::RECHECK);
        self::getContainer()->get(WorkerHeartbeatService::class)->touch(WorkerHeartbeatService::SCREENSHOT);
        $snapshot = $health->snapshot();

        self::assertTrue($snapshot['recheck']['active']);
        self::assertTrue($snapshot['screenshot']['active']);
    }

    public function testHeartbeatWritesAreRateLimitedPerKind(): void
    {
        $heartbeats = self::getContainer()->get(WorkerHeartbeatService::class);
        $now = new \DateTimeImmutable();

        $heartbeats->touch(WorkerHeartbeatService::RECHECK, $now);
        self::assertFalse($heartbeats->touchIfDue(WorkerHeartbeatService::RECHECK, $now->modify('+10 seconds')));
        self::assertTrue($heartbeats->touchIfDue(WorkerHeartbeatService::RECHECK, $now->modify('+20 seconds')));
        self::assertSame($now->modify('+20 seconds')->format('Y-m-d H:i:s'), $heartbeats->lastSeen(WorkerHeartbeatService::RECHECK)?->format('Y-m-d H:i:s'));
        self::assertSame(20, $heartbeats->ageSeconds(WorkerHeartbeatService::RECHECK, $now->modify('+40 seconds')));
        self::assertNull($heartbeats->lastSeen(WorkerHeartbeatService::SCREENSHOT));
    }

    private function finding(Domain $domain, string $title, string $status): Finding
    {
        $finding = (new Finding())
            ->setDomain($domain)
            ->setTitle($title)
            ->setType('stored-case')
            ->setSeverity('medium')
            ->setStatus($status)
            ->setUrl('https://'.$domain->getHostname().'/'.$title)
            ->setMethod('GET');
        $this->entityManager->persist($finding);

        return $finding;
    }
}
