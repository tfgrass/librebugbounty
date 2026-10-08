<?php

namespace App\Tests;

use App\Command\RecheckCatchUpCommand;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\RecheckCatchUpService;
use Symfony\Component\Console\Tester\CommandTester;

final class RecheckCatchUpCommandTest extends DatabaseTestCase
{
    public function testDryRunOnlyPreviewsAndExecuteReschedules(): void
    {
        $domain = (new Domain())->setHostname('cmd.example')->setScheme('https');
        $this->entityManager->persist($domain);
        $stale = $this->finding($domain, 'stale', new \DateTimeImmutable('-30 days'));
        $stale->setNextDueAt(new \DateTimeImmutable('+14 days'));
        $this->entityManager->flush();
        $staleId = $stale->getId();

        $service = new RecheckCatchUpService($this->entityManager->getConnection());
        $command = new RecheckCatchUpCommand($service);
        $tester = new CommandTester($command);

        // Dry run: reports but changes nothing.
        $tester->execute(['--older-than' => '14d', '--workers' => '0']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        $slot = $this->entityManager->getConnection()->fetchOne('SELECT next_due_at FROM finding WHERE id = ?', [$staleId]);
        self::assertGreaterThan((new \DateTimeImmutable('+13 days'))->format('Y-m-d H:i:s'), $slot, 'Dry run must not reschedule.');

        // Execute: the waiting slot is pulled due immediately.
        $tester->execute(['--older-than' => '14d', '--workers' => '0', '--execute' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Rescheduled 1 finding', $tester->getDisplay());
        $slot = $this->entityManager->getConnection()->fetchOne('SELECT next_due_at FROM finding WHERE id = ?', [$staleId]);
        self::assertLessThanOrEqual((new \DateTimeImmutable())->format('Y-m-d H:i:s'), $slot);
    }

    private function finding(Domain $domain, string $title, \DateTimeImmutable $lastRetestedAt): Finding
    {
        $finding = (new Finding())
            ->setDomain($domain)
            ->setTitle($title)
            ->setType('stored-case')
            ->setSeverity('medium')
            ->setStatus('reported')
            ->setUrl('https://'.$domain->getHostname().'/'.$title)
            ->setMethod('GET');
        $finding->setLastRetestedAt($lastRetestedAt);
        $this->entityManager->persist($finding);

        return $finding;
    }
}
