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
        self::assertSame('unknown', $snapshot['recheck']['state']);
        self::assertSame(0, $snapshot['recheck']['activeWorkers']);
        self::assertSame(4, $snapshot['recheck']['expectedWorkers']);
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

        self::assertFalse($snapshot['recheck']['active'], 'Ad-hoc worker IDs never replace expected lane signals.');
        self::assertSame(0, $snapshot['screenshot']['activeWorkers']);
    }

    public function testHeartbeatWritesAreRateLimitedPerKind(): void
    {
        $heartbeats = self::getContainer()->get(WorkerHeartbeatService::class);
        $now = new \DateTimeImmutable();

        $heartbeats->touch(WorkerHeartbeatService::RECHECK, $now);
        self::assertFalse($heartbeats->touchIfDue(WorkerHeartbeatService::RECHECK, $now->modify('+10 seconds')));
        self::assertTrue($heartbeats->touchIfDue(WorkerHeartbeatService::RECHECK, $now->modify('+20 seconds')));
        self::assertSame($now->modify('+20 seconds')->getTimestamp(), $heartbeats->lastSeen(WorkerHeartbeatService::RECHECK)?->getTimestamp());
        self::assertSame(20, $heartbeats->ageSeconds(WorkerHeartbeatService::RECHECK, $now->modify('+40 seconds')));
        self::assertNull($heartbeats->lastSeen(WorkerHeartbeatService::SCREENSHOT));
    }

    public function testOneFreshWorkerCannotMakeFourExpectedWorkersGreen(): void
    {
        $now = new \DateTimeImmutable('2026-10-10T12:00:00+02:00');
        $heartbeats = self::getContainer()->get(WorkerHeartbeatService::class);
        $heartbeats->touch(WorkerHeartbeatService::SCREENSHOT, $now, 'shot-1');
        $heartbeats->touch(WorkerHeartbeatService::SCREENSHOT, $now->modify('-6 minutes'), 'shot-2');
        $heartbeats->touch(WorkerHeartbeatService::SCREENSHOT, $now->modify('-7 minutes'), 'shot-3');
        $registry = self::getContainer()->get(\App\Service\WorkerHealthRegistry::class);
        $browsers = new \App\Service\BrowserHealthService(new \Symfony\Component\HttpClient\MockHttpClient(
            static fn () => new \Symfony\Component\HttpClient\Response\MockResponse('{"ok":true}')
        ), $registry);
        $health = new SystemHealthService($this->entityManager->getConnection(), $heartbeats,
            self::getContainer()->get(\App\Service\RecheckPolicy::class), $registry, $browsers, self::getContainer()->get(\App\Service\FindingProblemService::class));
        $group = $health->snapshot($now)['screenshot'];
        self::assertSame(1, $group['activeWorkers']);
        self::assertSame(4, $group['expectedWorkers']);
        self::assertSame('degraded', $group['state']);
        self::assertFalse($group['active']);
        self::assertSame(['ok', 'stale', 'stale', 'unknown'], array_column($group['workers'], 'state'));
        self::assertSame(4, $group['reachableBrowsers']);

        foreach (range(2, 4) as $id) $heartbeats->touch(WorkerHeartbeatService::SCREENSHOT, $now, 'shot-'.$id);
        self::assertTrue($health->snapshot($now)['screenshot']['active']);
        self::assertSame('stale', $health->snapshot($now->modify('+6 minutes'))['screenshot']['state']);

        $downBrowsers = new \App\Service\BrowserHealthService(new \Symfony\Component\HttpClient\MockHttpClient(
            static fn () => new \Symfony\Component\HttpClient\Response\MockResponse('{"ok":false}')
        ), $registry);
        $health = new SystemHealthService($this->entityManager->getConnection(), $heartbeats,
            self::getContainer()->get(\App\Service\RecheckPolicy::class), $registry, $downBrowsers, self::getContainer()->get(\App\Service\FindingProblemService::class));
        $group = $health->snapshot($now)['screenshot'];
        self::assertSame(4, $group['activeWorkers']);
        self::assertSame(0, $group['reachableBrowsers']);
        self::assertSame('degraded', $group['state']);
        self::assertFalse($group['active']);
    }

    public function testHeartbeatsReadOtherConnectionsAndNeverFlushPendingFindingChanges(): void
    {
        $domain = (new Domain())->setHostname('unflushed.example')->setScheme('https');
        $this->entityManager->persist($domain);
        $other = \Doctrine\DBAL\DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        $writer = new WorkerHeartbeatService($other);
        $reader = self::getContainer()->get(WorkerHeartbeatService::class);
        $now = new \DateTimeImmutable('2026-10-10T12:00:00Z');
        try {
            self::assertTrue($writer->touchIfDue(WorkerHeartbeatService::SCREENSHOT, $now, 'shot-1'));
            self::assertSame($now->getTimestamp(), $reader->lastSeen(WorkerHeartbeatService::SCREENSHOT, 'shot-1')?->getTimestamp());
            self::assertTrue($reader->touchIfDue(WorkerHeartbeatService::SCREENSHOT, $now, 'shot-2'));
            self::assertFalse($writer->touchIfDue(WorkerHeartbeatService::SCREENSHOT, $now->modify('+10 seconds'), 'shot-1'));
            self::assertTrue($writer->touchIfDue(WorkerHeartbeatService::SCREENSHOT, $now->modify('+15 seconds'), 'shot-1'));
            self::assertSame($now->modify('+15 seconds')->getTimestamp(), $reader->lastSeen(WorkerHeartbeatService::SCREENSHOT, 'shot-1')?->getTimestamp());
            self::assertSame(0, (int) $other->fetchOne('SELECT COUNT(*) FROM domain'));
        } finally {
            $other->close();
        }
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
