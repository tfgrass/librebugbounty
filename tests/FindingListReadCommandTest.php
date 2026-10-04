<?php

namespace App\Tests;

use App\Command\FindingListCommand;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\RetestRun;
use Symfony\Component\Console\Tester\CommandTester;

final class FindingListReadCommandTest extends DatabaseTestCase
{
    public function testListCombinesIndependentFiltersAndRetainsManualJudgment(): void
    {
        $manual = $this->finding('manual-fixed');
        $manual->setManualAssessment('fixed', null, new \DateTimeImmutable('2026-01-02'));
        $manual->setContactedAt(new \DateTimeImmutable('2026-02-03'))->setStatus('fixed');
        $this->observation($manual, 'inconclusive');
        $automatic = $this->finding('automatic-fixed');
        $automatic->setStatus('fixed');
        $this->observation($automatic, 'fixed');
        $discarded = $this->finding('discarded-fixed');
        $discarded->setManualAssessment('discarded', 'duplicate', new \DateTimeImmutable('2026-01-02'));
        $discarded->setStatus('discarded')->setContactedAt(new \DateTimeImmutable('2026-02-03'));
        $this->observation($discarded, 'inconclusive');
        $this->entityManager->flush();
        $before = $this->snapshot();

        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--assessment' => 'fixed', '--observation' => 'inconclusive', '--contact' => 'yes']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('manual-fixed', $display);
        self::assertStringNotContainsString('automatic-fixed', $display);
        self::assertStringNotContainsString('discarded-fixed', $display);
        self::assertStringContainsString('Behoben', $display);
        self::assertStringContainsString('Uneindeutig (inconclusive)', $display);
        self::assertStringContainsString('2026-02-03', $display);
        self::assertStringContainsString('Altstatus / Review', $display);

        self::assertSame(0, $tester->execute(['--assessment' => 'unknown', '--status' => 'fixed']));
        self::assertStringContainsString('automatic-fixed', $tester->getDisplay());
        self::assertStringNotContainsString('manual-fixed', $tester->getDisplay());
        self::assertStringContainsString('Keine aufgezeichnete manuelle Bewertung', $tester->getDisplay());
        self::assertStringContainsString('Kein Nachweis (fixed)', $tester->getDisplay());
        self::assertSame($before, $this->snapshot());
    }

    public function testArchiveIncludesLegacyDuplicatesWithoutInventingAssessment(): void
    {
        $legacy = $this->finding('legacy-duplicate');
        $legacy->setStatus('duplicate');
        $new = $this->finding('new-duplicate');
        $new->setManualAssessment('discarded', 'duplicate', new \DateTimeImmutable('2026-01-02'))->setStatus('discarded');
        $this->finding('active-fixture');
        $this->entityManager->flush();
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--status' => 'duplicate']));
        self::assertStringContainsString('legacy-duplicate', $tester->getDisplay());
        self::assertStringContainsString('new-duplicate', $tester->getDisplay());
        self::assertStringNotContainsString('active-fixture', $tester->getDisplay());
        self::assertStringContainsString('Keine aufgezeichnete manuelle Bewertung', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--scope' => 'duplicates', '--assessment' => 'unknown']));
        self::assertStringContainsString('legacy-duplicate', $tester->getDisplay());
        self::assertStringNotContainsString('new-duplicate', $tester->getDisplay());
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('active-fixture', $tester->getDisplay());
        self::assertStringNotContainsString('legacy-duplicate', $tester->getDisplay());
        self::assertStringNotContainsString('new-duplicate', $tester->getDisplay());
    }

    public function testInvalidDimensionIsRejectedBeforeListing(): void
    {
        $this->finding('active-fixture');
        $tester = $this->tester();
        self::assertSame(2, $tester->execute(['--contact' => 'maybe']));
        self::assertStringNotContainsString('active-fixture', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(self::getContainer()->get(FindingListCommand::class));
    }

    private function finding(string $label): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'localhost']);
        if ($domain === null) {
            $domain = (new Domain())->setHostname('localhost')->setScheme('http');
            $this->entityManager->persist($domain);
        }
        $finding = (new Finding())->setDomain($domain)->setTitle($label)->setType('other')
            ->setUrl('http://localhost/fixture/'.$label);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function observation(Finding $finding, string $result): void
    {
        $run = (new RetestRun())->setFinding($finding)->setMode('fixture')->setResult($result);
        $this->entityManager->persist($run);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['finding', 'retest_run', 'finding_assessment', 'screenshot_job'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $snapshot;
    }
}
