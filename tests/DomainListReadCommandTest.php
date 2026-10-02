<?php

namespace App\Tests;

use App\Command\DomainListCommand;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Repository\DomainRepository;
use App\Repository\FindingReadRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DomainListReadCommandTest extends DatabaseTestCase
{
    public function testDomainCountsKeepManualVerdictContactAndLegacyTechnicalStateSeparate(): void
    {
        $alpha = $this->domain('alpha.local', true);
        $similar = $this->domain('sub.alpha.local', false);
        $ignored = $this->domain('ignored.local', true);
        $legacyFixed = $this->finding($alpha, 'legacy-fixed', 'fixed', null, true);
        $confirmed = $this->finding($alpha, 'confirmed', 'verified', 'confirmed', false);
        $manualFixed = $this->finding($alpha, 'manual-fixed', 'fixed', 'fixed', true);
        $this->finding($alpha, 'unassessed', 'new', null, false);
        $this->finding($alpha, 'discarded-contacted', 'discarded', 'discarded', true);
        $this->finding($similar, 'confirmed', 'verified', 'confirmed', false);
        $this->finding($ignored, 'legacy-duplicate', 'duplicate', null, true);
        foreach ([[$legacyFixed, 'fixed'], [$confirmed, 'inconclusive'], [$manualFixed, 'still_vulnerable']] as [$finding, $result]) {
            $run = (new RetestRun())->setFinding($finding)->setResult($result)
                ->setStartedAt(new \DateTimeImmutable('2026-10-02 12:00:00'))
                ->setFinishedAt(new \DateTimeImmutable('2026-10-02 12:00:01'));
            $this->entityManager->persist($run);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();
        $tester = new CommandTester(new DomainListCommand(
            self::getContainer()->get(DomainRepository::class),
            self::getContainer()->get(FindingReadRepository::class),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $rows = $this->tableRows($tester->getDisplay());
        self::assertSame(['alpha.local', 'sub.alpha.local'], array_keys($rows), $tester->getDisplay());
        // Active, manually confirmed, manually fixed, no manual assessment,
        // contacted: contact can overlap any verdict, and raw fixed is not manual.
        self::assertSame(['alpha.local', 'https', 'yes', '4', '1', '1', '2', '2'], $rows['alpha.local']);
        self::assertSame(['sub.alpha.local', 'https', 'no', '1', '1', '0', '0', '0'], $rows['sub.alpha.local']);
        self::assertStringContainsString('no recorded manual assessment', $tester->getDisplay());
        self::assertSame([], $this->entityManager->getUnitOfWork()->getIdentityMap()[Finding::class] ?? []);

        self::assertSame(Command::SUCCESS, $tester->execute(['--authorized-only' => true]));
        self::assertSame(['alpha.local'], array_keys($this->tableRows($tester->getDisplay())));
    }

    /** @return array<string, list<string>> */
    private function tableRows(string $display): array
    {
        $rows = [];
        foreach (explode("\n", $display) as $line) {
            // SymfonyStyle renders this command as padded columns without pipes.
            $columns = preg_split('/\s{2,}/u', trim($line));
            if (count($columns) === 8 && str_ends_with($columns[0], '.local')) {
                $rows[$columns[0]] = $columns;
            }
        }

        return $rows;
    }

    private function domain(string $hostname, bool $authorized): Domain
    {
        $domain = (new Domain())->setHostname($hostname)->setScheme('https')->setAuthorized($authorized);
        $this->entityManager->persist($domain);

        return $domain;
    }

    private function finding(Domain $domain, string $suffix, string $status, ?string $assessment, bool $contacted): Finding
    {
        $finding = (new Finding())->setDomain($domain)->setTitle('Synthetic '.$suffix)
            ->setType('browser_documentation')->setStatus($status)
            ->setUrl('https://'.$domain->getHostname().'/'.$suffix);
        if ($assessment !== null) {
            $finding->setManualAssessment($assessment, null, new \DateTimeImmutable('2026-10-02 11:00:00'));
        }
        if ($contacted) {
            $finding->setContactedAt(new \DateTimeImmutable('2026-10-02 10:00:00'));
        }
        $this->entityManager->persist($finding);

        return $finding;
    }
}
