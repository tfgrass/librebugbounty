<?php

namespace App\Tests;

use App\Command\DomainExportCommand;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Repository\DomainRepository;
use App\Repository\FindingRepository;
use App\Repository\ScreenshotJobRepository;
use App\Service\ExportService;
use App\Tests\Support\InMemoryFindingRepository;
use App\Tests\Support\InMemoryDomainRepository;
use App\Value\FindingStatus;
use App\Value\ScreenshotJobStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DiscardedFindingRepositoryTest extends DatabaseTestCase
{
    public function testNormalListsCountsAndLimitsIgnoreManualAndLegacyDiscards(): void
    {
        $domain = $this->domain('list.local');
        $older = $this->finding($domain, 'older', '2026-10-02', FindingStatus::VERIFIED);
        $newer = $this->finding($domain, 'newer', '2026-10-03', FindingStatus::VERIFIED);
        $manual = $this->finding($domain, 'manual', '2026-10-01', FindingStatus::VERIFIED, true);
        $manual->setReviewState('manual_checking');
        $duplicate = $this->finding($domain, 'duplicate', '2026-10-05', FindingStatus::DUPLICATE);
        $legacy = $this->finding($domain, 'legacy', '2026-10-04', FindingStatus::DISCARDED);
        foreach ([$older, $newer, $manual, $duplicate, $legacy] as $finding) {
            $finding->setLastRetestedAt(new \DateTimeImmutable('2026-08-01'));
        }
        $this->entityManager->flush();
        $repository = self::getContainer()->get(FindingRepository::class);

        self::assertSame(2, $repository->countAllFindings());
        self::assertSame(2, $repository->countByDomainAndStatus('list'));
        self::assertSame(2, $repository->countByStatuses([FindingStatus::VERIFIED]));
        self::assertSame(2, $repository->countByBucket('open'));
        self::assertSame(0, $repository->countByBucket('manual_review'));
        self::assertSame(2, $repository->countOpenFindingsForDomain($domain));
        self::assertSame($newer->getId(), $repository->findPageByDomainAndStatus('list', null, null, 1)[0]->getId());
        self::assertSame($older->getId(), $repository->findPageByDomainAndStatus('list', null, null, 1, 1)[0]->getId());
        self::assertSame([], $repository->findPageByDomainAndStatus('list', null, null, 1, 2));
        self::assertSame($older->getId(), $repository->findAllOrdered(1)[0]->getId());
        $this->assertFindingIds([$older, $newer], $repository->findByDomainAndStatus($domain));

        self::assertSame(3, $repository->countByDomainAndStatus(null, FindingStatus::DISCARDED));
        self::assertSame(3, $repository->countByStatuses([FindingStatus::DISCARDED]));
        self::assertSame(1, $repository->countByStatuses([FindingStatus::DUPLICATE]));
        self::assertSame(3, $repository->countByStatuses([FindingStatus::VERIFIED, FindingStatus::DUPLICATE]));
        $this->assertFindingIds([$manual, $duplicate, $legacy], $repository->findByDomainAndStatus(null, FindingStatus::DISCARDED));

        // The existing isolated doubles obey the same exclusion and archive contract.
        $memory = new InMemoryFindingRepository();
        foreach ([$older, $newer, $manual, $duplicate, $legacy] as $finding) {
            $memory->add($finding);
        }
        self::assertSame($repository->countAllFindings(), $memory->countAllFindings());
        self::assertSame($repository->countByStatuses([FindingStatus::VERIFIED, FindingStatus::DUPLICATE]), $memory->countByStatuses([FindingStatus::VERIFIED, FindingStatus::DUPLICATE]));
        self::assertSame($newer->getId(), $memory->findPageByDomainAndStatus('list', null, null, 1)[0]->getId());
        $this->assertFindingIds([$manual, $duplicate, $legacy], $memory->findByDomainAndStatus(null, FindingStatus::DISCARDED));
    }

    public function testWorkSelectionsExcludeDiscardsEvenWithAnExplicitArchivedStatus(): void
    {
        $domain = $this->domain('work.local');
        $discarded = $this->finding($domain, 'discarded', '2026-10-01', FindingStatus::VERIFIED, true);
        $duplicate = $this->finding($domain, 'duplicate', '2026-10-02', FindingStatus::DUPLICATE);
        $active = $this->finding($domain, 'active', '2026-10-03', FindingStatus::VERIFIED);
        $this->entityManager->flush();
        $repository = self::getContainer()->get(FindingRepository::class);

        self::assertSame(1, $repository->countUncheckedFindings());
        $this->assertFindingIds([$active], $repository->findAllForBrowserRetest(null, null, 1));
        $this->assertFindingIds([$active], $repository->findDueForRetest(null, null, null, 1));
        $this->assertFindingIds([$active], $repository->findOpenFindingsWithoutEvidence(1));
        $this->assertFindingIds([$active], $repository->findAllWithoutScreenshotEvidence(null, null, 1));
        $this->assertFindingIds([$active], $repository->findAllWithoutScreenshotEvidenceByStatuses(null, [FindingStatus::VERIFIED], 1));
        $this->assertFindingIds([$active], $repository->findForPriorityExport(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-04')));
        foreach ([FindingStatus::DUPLICATE, FindingStatus::DISCARDED] as $status) {
            self::assertSame([], $repository->findAllForBrowserRetest(null, $status));
            self::assertSame([], $repository->findDueForRetest(null, null, $status));
            self::assertSame([], $repository->findAllWithoutScreenshotEvidence(null, $status));
        }

        $memory = new InMemoryFindingRepository();
        foreach ([$discarded, $duplicate, $active] as $finding) {
            $memory->add($finding);
        }
        $this->assertFindingIds([$active], $memory->findAllWithoutScreenshotEvidenceByStatuses(null, [FindingStatus::VERIFIED], 1));
        self::assertSame([], $memory->findAllForBrowserRetest(null, FindingStatus::DUPLICATE));
    }

    public function testArchiveReadsAndNewObservationsRetainNotesEvidenceAndDuplicateReason(): void
    {
        $domain = $this->domain('archive.local');
        $finding = $this->finding($domain, 'duplicate', '2026-10-01', FindingStatus::DISCARDED, true);
        $finding->setManualAssessment('discarded', 'duplicate', new \DateTimeImmutable('2026-10-02'));
        $finding->setPrivateNotes('Synthetic note retained after discarding.');
        $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath('synthetic-retained.png');
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();

        // A later stored observation remains evidence, rather than a new manual verdict.
        $run = (new RetestRun())->setFinding($finding)->setResult('inconclusive')->setStartedAt(new \DateTimeImmutable('2026-10-03'));
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $findingId = $finding->getId();
        $evidenceId = $evidence->getId();
        $runId = $run->getId();
        $this->entityManager->clear();
        $repository = self::getContainer()->get(FindingRepository::class);

        self::assertSame([], $repository->findPageByDomainAndStatus());
        self::assertSame(0, $repository->countAllFindings());
        self::assertSame(1, $repository->countByDomainAndStatus(null, FindingStatus::DUPLICATE));
        self::assertSame(1, $repository->countByStatuses([FindingStatus::DUPLICATE]));
        $read = $repository->findByDomainAndStatus(null, FindingStatus::DUPLICATE)[0];
        self::assertSame($findingId, $read->getId());
        self::assertSame($read, $repository->findOneByDomainAndUrl($read->getDomain(), $read->getUrl()));
        self::assertTrue($read->isDiscarded());
        self::assertSame('discarded', $read->getManualAssessment());
        self::assertSame('duplicate', $read->getDiscardReason());
        self::assertSame('Synthetic note retained after discarding.', $read->getPrivateNotes());
        self::assertSame($evidenceId, $read->getEvidence()->first()->getId());
        self::assertSame($runId, $read->getRetestRuns()->first()->getId());
        self::assertSame('inconclusive', $read->getRetestRuns()->first()->getResult());
        self::assertSame([], $repository->findAllForBrowserRetest());
        self::assertSame(0, $repository->countAllFindings());

        $memory = new InMemoryFindingRepository();
        $memory->add($read);
        self::assertSame(0, $memory->countAllFindings());
        self::assertSame(1, $memory->countByStatuses([FindingStatus::DUPLICATE]));

        $export = self::getContainer()->get(ExportService::class);
        self::assertSame([], $export->export()['findings']);
        $archiveExport = $export->export(null, FindingStatus::DUPLICATE);
        self::assertSame($findingId, $archiveExport['findings'][0]['id']);
        self::assertSame('archive.local', $archiveExport['domains'][0]['hostname']);
    }

    public function testDomainSelectionsAndExportsIgnoreDiscardedCountsAndContactMarkers(): void
    {
        $mixed = $this->domain('active.local');
        $this->finding($mixed, 'active', '2026-10-02', FindingStatus::VERIFIED);
        $ignoredFixed = $this->finding($mixed, 'ignored-fixed', '2026-10-01', FindingStatus::FIXED, true);
        $ignoredFixed->setContactedAt(new \DateTimeImmutable('2026-10-02'));
        $ignoredDomain = $this->domain('ignored.local');
        $ignored = $this->finding($ignoredDomain, 'duplicate', '2026-10-01', FindingStatus::DUPLICATE);
        $ignored->setContactedAt(new \DateTimeImmutable('2026-10-02'));
        $contactedDomain = $this->domain('contacted.local');
        $contacted = $this->finding($contactedDomain, 'contacted', '2026-10-02', FindingStatus::VERIFIED);
        $contacted->setContactedAt(new \DateTimeImmutable('2026-10-02'));
        $this->entityManager->flush();
        $this->entityManager->clear();
        $repository = self::getContainer()->get(DomainRepository::class);

        self::assertSame(['active.local', 'contacted.local'], array_map(static fn (Domain $domain): string => $domain->getHostname(), $repository->findAllOrdered()));
        self::assertSame(['active.local'], array_map(static fn (Domain $domain): string => $domain->getHostname(), $repository->findAllWithoutContactedOrFixedFindings()));
        self::assertSame(['contacted.local'], array_map(static fn (Domain $domain): string => $domain->getHostname(), $repository->findAllWithContactedFindings()));
        self::assertSame(0, self::getContainer()->get(FindingRepository::class)->countByBucket('fixed'));

        $memory = new InMemoryDomainRepository();
        foreach (['active.local', 'ignored.local', 'contacted.local'] as $hostname) {
            $memory->add($repository->findOneByNormalizedHostname($hostname));
        }
        self::assertSame(['active.local', 'contacted.local'], array_map(static fn (Domain $domain): string => $domain->getHostname(), $memory->findAllOrdered()));
        self::assertSame(['active.local'], array_map(static fn (Domain $domain): string => $domain->getHostname(), $memory->findAllWithoutContactedOrFixedFindings()));
        self::assertSame(['contacted.local'], array_map(static fn (Domain $domain): string => $domain->getHostname(), $memory->findAllWithContactedFindings()));

        $tester = new CommandTester(new DomainExportCommand($repository));
        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        $rows = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('active.local', $rows[0]['hostname']);
        self::assertSame(1, $rows[0]['findings']);
        self::assertSame(Command::SUCCESS, $tester->execute(['--overview' => true]));
        self::assertStringContainsString('== Altstatus fixed (0) ==', $tester->getDisplay());
        self::assertStringContainsString('== marked contacted (1) ==', $tester->getDisplay());
        self::assertStringContainsString('== uncontacted (1) ==', $tester->getDisplay());
        self::assertStringNotContainsString('ignored.local', $tester->getDisplay());
    }

    public function testQueuedDiscardedJobsAreRetainedAndExcludedBeforeClaimingOrCounting(): void
    {
        $domain = $this->domain('queued.local');
        $manual = $this->finding($domain, 'manual', '2026-10-01', FindingStatus::VERIFIED);
        $duplicate = $this->finding($domain, 'duplicate', '2026-10-01', FindingStatus::DUPLICATE);
        $legacyDiscarded = $this->finding($domain, 'legacy-discarded', '2026-10-01', FindingStatus::DISCARDED);
        $active = $this->finding($domain, 'active', '2026-10-02', FindingStatus::VERIFIED);
        $jobs = [];
        foreach ([$manual, $duplicate, $legacyDiscarded, $active] as $index => $finding) {
            $job = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())
                ->setActiveKey($finding->getId())
                ->setRequestedAt(new \DateTimeImmutable(sprintf('2026-10-01 12:00:0%d', $index)));
            $this->entityManager->persist($job);
            $jobs[] = $job;
        }
        $this->entityManager->flush();

        // Simulate another request persisting a discard while this request still
        // has the old finding object: selection must use the current database state.
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('UPDATE finding SET manual_assessment = :assessment WHERE id = :id', ['assessment' => 'discarded', 'id' => $manual->getId()]);
        self::assertFalse($manual->isDiscarded());
        $before = [];
        foreach (array_slice($jobs, 0, 3) as $job) {
            $before[$job->getId()] = $connection->fetchAssociative('SELECT * FROM screenshot_job WHERE id = :id', ['id' => $job->getId()]);
        }
        $repository = self::getContainer()->get(ScreenshotJobRepository::class);

        self::assertSame(1, $repository->countByStatus(ScreenshotJobStatus::QUEUED));
        $claimed = $repository->claimNext();
        self::assertSame($jobs[3]->getId(), $claimed?->getId());
        self::assertSame(ScreenshotJobStatus::RUNNING, $claimed?->getStatus());
        self::assertSame(1, $claimed?->getAttempts());
        self::assertSame(0, $repository->countByStatus(ScreenshotJobStatus::QUEUED));
        self::assertSame(1, $repository->countByStatus(ScreenshotJobStatus::RUNNING));
        self::assertNull($repository->claimNext());
        self::assertSame(4, $repository->count([]));
        foreach (array_slice($jobs, 0, 3) as $job) {
            self::assertSame($before[$job->getId()], $connection->fetchAssociative('SELECT * FROM screenshot_job WHERE id = :id', ['id' => $job->getId()]));
            $history = $repository->findRecentByFinding($job->getFinding());
            self::assertSame($job->getId(), $history[0]->getId());
            self::assertSame(ScreenshotJobStatus::QUEUED, $history[0]->getStatus());
            self::assertSame(0, $history[0]->getAttempts());
            self::assertNull($history[0]->getStartedAt());
        }
    }

    private function domain(string $hostname): Domain
    {
        $domain = (new Domain())->setHostname($hostname)->setScheme('https');
        $this->entityManager->persist($domain);

        return $domain;
    }

    private function finding(Domain $domain, string $suffix, string $submittedAt, string $status, bool $discarded = false): Finding
    {
        $finding = (new Finding())
            ->setDomain($domain)
            ->setTitle('Synthetic '.$suffix)
            ->setType('browser_documentation')
            ->setStatus($status)
            ->setUrl('https://'.$domain->getHostname().'/'.$suffix)
            ->setSubmittedAt(new \DateTimeImmutable($submittedAt));
        if ($discarded) {
            $finding->setManualAssessment('discarded', null, new \DateTimeImmutable('2026-10-02'));
        }
        $domain->getFindings()->add($finding);
        $this->entityManager->persist($finding);

        return $finding;
    }

    /** @param list<Finding> $expected @param list<Finding> $actual */
    private function assertFindingIds(array $expected, array $actual): void
    {
        self::assertEqualsCanonicalizing(
            array_map(static fn (Finding $finding): string => $finding->getId(), $expected),
            array_map(static fn (Finding $finding): string => $finding->getId(), $actual),
        );
    }
}
