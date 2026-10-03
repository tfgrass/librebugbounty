<?php

namespace App\Tests;

use App\Dto\FindingReadFilter;
use App\Dto\FindingReadView;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Repository\FindingReadRepository;
use App\Repository\RetestRunRepository;

final class FindingReadRepositoryTest extends DatabaseTestCase
{
    public function testProjectionKeepsManualObservationLegacyAndContactSeparate(): void
    {
        $automatic = $this->finding('automatic')->setStatus('fixed')->setReviewState('manual_checking');
        $this->observation($automatic, 'fixed', '2026-10-02T12:00:00', '2026-10-02T12:01:00');
        $manual = $this->finding('manual')->setStatus('fixed')->setReviewState('confirmed_fixed')
            ->setManualAssessment('fixed', null, new \DateTimeImmutable('2026-10-02T11:00:00'))
            ->setContactedAt(new \DateTimeImmutable('2026-10-02T11:30:00'));
        $newRun = $this->observation($manual, 'inconclusive', '2026-10-02T12:00:00', '2026-10-02T12:01:00');
        $legacy = $this->finding('legacy')->setStatus('verified')->setReviewState('manually_checked');
        $timestampOnly = $this->finding('timestamp-only')->setLastRetestedAt(new \DateTimeImmutable('2026-10-02T13:00:00'));
        $this->entityManager->flush();

        $views = $this->byId($this->repository()->findPage(new FindingReadFilter()));
        self::assertInstanceOf(FindingReadView::class, $views[$manual->getId()]);
        self::assertNull($views[$automatic->getId()]->assessment);
        self::assertSame('fixed', $views[$automatic->getId()]->observationResult);
        self::assertSame('fixed', $views[$automatic->getId()]->legacyStatus);
        self::assertSame('fixed', $views[$manual->getId()]->assessment);
        self::assertSame('inconclusive', $views[$manual->getId()]->observationResult);
        self::assertSame($newRun->getId(), $views[$manual->getId()]->observationId);
        self::assertSame('browser', $views[$manual->getId()]->observationMode);
        self::assertEquals($manual->getAssessedAt(), $views[$manual->getId()]->assessedAt);
        self::assertEquals($manual->getContactedAt(), $views[$manual->getId()]->contactedAt);
        self::assertNull($views[$legacy->getId()]->assessment);
        self::assertNull($views[$legacy->getId()]->assessedAt);
        self::assertSame('manually_checked', $views[$legacy->getId()]->legacyReviewState);
        self::assertNull($views[$timestampOnly->getId()]->observationId);
        self::assertNull($views[$timestampOnly->getId()]->observationResult);
        self::assertNull($views[$timestampOnly->getId()]->observationAt);
        $this->assertMatches([$manual], new FindingReadFilter(assessment: 'fixed'));
        $this->assertMatches([$automatic], new FindingReadFilter(observation: 'fixed'));
        $this->assertMatches([$manual], new FindingReadFilter(assessment: 'fixed', observation: 'inconclusive', contact: 'yes'));
        $this->assertMatches([$legacy, $timestampOnly], new FindingReadFilter(assessment: 'unknown', observation: 'none'));
        $this->assertMatches([$automatic, $legacy, $timestampOnly], new FindingReadFilter(contact: 'no'));
    }

    public function testLatestObservationUsesCompletionTimeInsertionTiesAndPendingFallback(): void
    {
        $finding = $this->finding('latest');
        $this->observation($finding, 'error', '2026-10-02T11:59:00', '2026-10-02T12:00:00', 'ffffffff-ffff-4fff-8fff-ffffffffffff');
        $laterInserted = $this->observation($finding, 'inconclusive', '2026-10-02T11:58:00', '2026-10-02T12:00:00', '00000000-0000-4000-8000-000000000001');
        $view = $this->repository()->findPage(new FindingReadFilter())[0];
        self::assertSame($laterInserted->getId(), $view->observationId, 'Random UUID ordering must not decide which equal-time run is latest.');
        self::assertSame('inconclusive', $view->observationResult);
        self::assertEquals(new \DateTimeImmutable('2026-10-02T12:00:00'), $view->observationAt);
        $this->assertMatches([$finding], new FindingReadFilter(observation: 'inconclusive'));
        $this->assertMatches([], new FindingReadFilter(observation: 'error'));

        // A later insertion of an older pending attempt does not replace a
        // completed observation with a more recent effective timestamp.
        $this->observation($finding, 'pending', '2026-10-02T11:00:00');
        self::assertSame($laterInserted->getId(), $this->repository()->findPage(new FindingReadFilter())[0]->observationId);
        $pending = $this->observation($finding, 'pending', '2026-10-02T12:01:00');
        $view = $this->repository()->findPage(new FindingReadFilter())[0];
        self::assertSame($pending->getId(), $view->observationId);
        self::assertEquals(new \DateTimeImmutable('2026-10-02T12:01:00'), $view->observationAt);
        $this->assertMatches([$finding], new FindingReadFilter(observation: 'pending'));

        $longRun = $this->observation($finding, 'still_vulnerable', '2026-10-02T10:00:00', '2026-10-02T12:02:00');
        $view = $this->repository()->findPage(new FindingReadFilter())[0];
        self::assertSame($longRun->getId(), $view->observationId);
        self::assertSame(
            self::getContainer()->get(RetestRunRepository::class)->findRecentByFinding($finding, 1)[0]->getId(),
            $view->observationId,
        );
    }

    public function testArchiveScopesIncludeOldStatusesAndExplicitDuplicateReasons(): void
    {
        $active = $this->finding('active');
        $wontfix = $this->finding('wontfix')->setStatus('wontfix');
        $manual = $this->finding('manual-discard')->setStatus('verified')
            ->setManualAssessment('discarded', null, new \DateTimeImmutable('2026-10-02'));
        $duplicate = $this->finding('manual-duplicate')->setStatus('verified')
            ->setManualAssessment('discarded', 'duplicate', new \DateTimeImmutable('2026-10-02'));
        $oldDuplicate = $this->finding('old-duplicate')->setStatus('duplicate');
        $oldDiscard = $this->finding('old-discard')->setStatus('discarded');
        $this->entityManager->flush();

        $this->assertMatches([$active, $wontfix], new FindingReadFilter());
        $this->assertMatches([$manual, $duplicate, $oldDuplicate, $oldDiscard], new FindingReadFilter(scope: 'discarded'));
        $this->assertMatches([$duplicate, $oldDuplicate], new FindingReadFilter(scope: 'duplicates'));
        $this->assertMatches([$active, $wontfix, $manual, $duplicate, $oldDuplicate, $oldDiscard], new FindingReadFilter(scope: 'all'));
        $this->assertMatches([$manual, $duplicate], new FindingReadFilter(assessment: 'discarded', scope: 'discarded'));
        $this->assertMatches([$oldDuplicate, $oldDiscard], new FindingReadFilter(assessment: 'unknown', scope: 'discarded'));
        $this->assertMatches([], new FindingReadFilter(legacyStatus: 'duplicate'));
        $this->assertMatches([$duplicate, $oldDuplicate], new FindingReadFilter(scope: 'all', legacyStatus: 'duplicate'));
        $this->assertMatches([$manual, $duplicate, $oldDuplicate, $oldDiscard], new FindingReadFilter(scope: 'all', legacyStatus: 'discarded'));

        foreach ($this->repository()->findPage(new FindingReadFilter(scope: 'all')) as $view) {
            self::assertSame(in_array($view->id, [$manual->getId(), $duplicate->getId(), $oldDuplicate->getId(), $oldDiscard->getId()], true), $view->discarded);
        }
    }

    public function testAllDimensionsAreCombinedAndCountsMatchEveryPage(): void
    {
        $domain = $this->domain('target.example.test');
        $wanted = $this->finding('wanted', $domain)->setStatus('fixed')->setSeverity('high')
            ->setManualAssessment('fixed', null, new \DateTimeImmutable('2026-10-02'))
            ->setContactedAt(new \DateTimeImmutable('2026-10-02'));
        $this->observation($wanted, 'inconclusive', '2026-10-02T12:00:00');
        $wrongContact = $this->finding('wrong-contact', $domain)->setStatus('fixed')->setSeverity('high')
            ->setManualAssessment('fixed', null, new \DateTimeImmutable('2026-10-02'));
        $this->observation($wrongContact, 'inconclusive', '2026-10-02T12:00:00');
        $wrongObservation = $this->finding('wrong-result', $domain)->setStatus('fixed')->setSeverity('high')
            ->setManualAssessment('fixed', null, new \DateTimeImmutable('2026-10-02'))
            ->setContactedAt(new \DateTimeImmutable('2026-10-02'));
        $this->observation($wrongObservation, 'error', '2026-10-02T12:00:00');
        $wrongAssessment = $this->finding('wrong-assessment', $domain)->setStatus('fixed')->setSeverity('high')
            ->setContactedAt(new \DateTimeImmutable('2026-10-02'));
        $this->observation($wrongAssessment, 'inconclusive', '2026-10-02T12:00:00');
        $wrongDomain = $this->finding('wrong-domain')->setStatus('fixed')->setSeverity('high')
            ->setManualAssessment('fixed', null, new \DateTimeImmutable('2026-10-02'))
            ->setContactedAt(new \DateTimeImmutable('2026-10-02'));
        $this->observation($wrongDomain, 'inconclusive', '2026-10-02T12:00:00');
        $wrongType = $this->finding('wrong-type', $domain)->setStatus('fixed')->setType('other')->setSeverity('high')
            ->setManualAssessment('fixed', null, new \DateTimeImmutable('2026-10-02'))
            ->setContactedAt(new \DateTimeImmutable('2026-10-02'));
        $this->observation($wrongType, 'inconclusive', '2026-10-02T12:00:00');
        $wrongSeverity = $this->finding('wrong-severity', $domain)->setStatus('fixed')->setSeverity('medium')
            ->setManualAssessment('fixed', null, new \DateTimeImmutable('2026-10-02'))
            ->setContactedAt(new \DateTimeImmutable('2026-10-02'));
        $this->observation($wrongSeverity, 'inconclusive', '2026-10-02T12:00:00');
        $this->entityManager->flush();
        $filter = new FindingReadFilter(
            domain: 'TARGET.EXAMPLE.TEST', assessment: 'fixed', observation: 'inconclusive', contact: 'yes',
            legacyStatus: 'fixed', legacyBucket: 'fixed', type: 'synthetic', severity: 'high', exactDomain: true,
        );
        $this->assertMatches([$wanted], $filter);
        self::assertSame([], $this->repository()->findPage($filter, 1, 1));

        $filter = new FindingReadFilter(domain: 'target', assessment: 'fixed', contact: 'yes');
        $this->assertMatches([$wanted, $wrongObservation, $wrongType, $wrongSeverity], $filter);
        $pagedIds = [];
        for ($offset = 0; $offset < $this->repository()->count($filter); $offset++) {
            $page = $this->repository()->findPage($filter, 1, $offset);
            self::assertCount(1, $page);
            $pagedIds[] = $page[0]->id;
        }
        self::assertCount(count(array_unique($pagedIds)), $pagedIds);
        self::assertSame(array_map(static fn (FindingReadView $view): string => $view->id, $this->repository()->findPage($filter)), $pagedIds);
        self::assertSame([], $this->repository()->findPage($filter, 1, count($pagedIds)));
    }

    public function testLegacyBucketsRetainTheirConditionsWithoutBecomingNewObservationFilters(): void
    {
        $timestampOnly = $this->finding('timestamp-only')->setStatus('verified')
            ->setLastRetestedAt(new \DateTimeImmutable('2026-10-02'));
        $unchecked = $this->finding('unchecked');
        $this->observation($unchecked, 'still_vulnerable', '2026-10-02T12:00:00');
        $manualReview = $this->finding('manual-review')->setReviewState('manual_checking')
            ->setLastRetestedAt(new \DateTimeImmutable('2026-10-02'));
        $this->entityManager->flush();

        $this->assertMatches([$timestampOnly], new FindingReadFilter(legacyBucket: 'open'));
        $this->assertMatches([$timestampOnly], new FindingReadFilter(observation: 'none', legacyBucket: 'open'));
        $this->assertMatches([$unchecked], new FindingReadFilter(legacyBucket: 'unchecked'));
        $this->assertMatches([$unchecked], new FindingReadFilter(observation: 'still_vulnerable', legacyBucket: 'unchecked'));
        $this->assertMatches([$manualReview], new FindingReadFilter(legacyBucket: 'manual_review'));
        $this->assertMatches([$timestampOnly, $manualReview], new FindingReadFilter(observation: 'none'));
        $this->assertMatches([], new FindingReadFilter(legacyStatus: 'fixed', legacyBucket: 'manual_review'));
    }

    public function testDomainSearchSupportsPartialAndExactComparisonWithoutChangingOtherDimensions(): void
    {
        $plain = $this->finding('plain', $this->domain('Example.test'));
        $sub = $this->finding('sub', $this->domain('sub.example.test'));
        $unrelated = $this->finding('unrelated', $this->domain('unrelated.other.test'));
        $this->assertMatches([$plain, $sub], new FindingReadFilter(domain: 'EXAMPLE.TEST'));
        $this->assertMatches([$plain], new FindingReadFilter(domain: ' EXAMPLE.TEST ', exactDomain: true));
        $this->assertMatches([$sub], new FindingReadFilter(domain: 'sub.example.test', exactDomain: true));
        $this->assertMatches([], new FindingReadFilter(domain: "example.test' OR 1=1 --"));
        $this->assertMatches([$plain, $sub, $unrelated], new FindingReadFilter());
    }

    public function testFreeTextSearchCombinesTitleHostnameAndFullUrlAndTreatsSqlWildcardsLiterally(): void
    {
        $title = $this->finding('title')->setTitle('Review NEEDLE in title');
        $host = $this->finding('host', $this->domain('needle.example.test'));
        $url = $this->finding('url')->setUrl('https://url.example.test/deep/path?value=Needle');
        $percent = $this->finding('percent')->setTitle('Progress 100%');
        $underscore = $this->finding('underscore')->setTitle('literal_under_score');
        $backslash = $this->finding('backslash')->setTitle('literal\\backslash');
        $this->finding('decoy')->setTitle('Progress 1000 literalXunderXscore literalXbackslash');
        $title->setContactedAt(new \DateTimeImmutable('2026-10-02'));
        $this->entityManager->flush();
        $before = $this->snapshot();
        $this->entityManager->clear();

        foreach ([
            ['NEEDLE', [$title, $host, $url]],
            ['100%', [$percent]],
            ['_under_', [$underscore]],
            ['\\backslash', [$backslash]],
            ["' OR 1=1 --", []],
        ] as [$query, $expected]) {
            $this->assertMatches($expected, new FindingReadFilter(q: $query));
        }
        $this->assertMatches([$title], new FindingReadFilter(q: 'needle', contact: 'yes'));
        $this->assertMatches([$host, $url], new FindingReadFilter(q: 'needle', contact: 'no'));
        $filter = new FindingReadFilter(q: 'needle');
        self::assertSame(3, $this->repository()->count($filter));
        $pages = [];
        for ($offset = 0; $offset < 3; $offset++) {
            $page = $this->repository()->findPage($filter, 1, $offset);
            self::assertCount(1, $page);
            $pages[] = $page[0]->id;
        }
        self::assertCount(3, array_unique($pages));
        self::assertSame([], $this->repository()->findPage($filter, 1, 3));
        self::assertSame([], $this->entityManager->getUnitOfWork()->getIdentityMap());
        self::assertSame($before, $this->snapshot());
    }

    public function testUnknownFilterValuesAndInvalidPageBoundsAreRejected(): void
    {
        foreach ([
            ['assessment' => 'null'], ['assessment' => 'verified'], ['observation' => 'unchecked'],
            ['contact' => 'contacted'], ['scope' => 'archive'], ['legacyStatus' => 'invented'],
            ['legacyBucket' => 'confirmed'], ['severity' => 'invented'],
        ] as $arguments) {
            try {
                new FindingReadFilter(...$arguments);
                self::fail('An unsupported read filter was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([[0, 0], [-1, 0], [1, -1]] as [$limit, $offset]) {
            try {
                $this->repository()->findPage(new FindingReadFilter(), $limit, $offset);
                self::fail('Invalid page bounds were accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testScalarReadsDoNotHydrateEntitiesOrChangeData(): void
    {
        $finding = $this->finding('read-only')->setPrivateNotes('Keep private note unchanged');
        $this->observation($finding, 'inconclusive', '2026-10-02T12:00:00');
        $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath('retained-missing.png');
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();
        $before = $this->snapshot();
        $this->entityManager->clear();

        self::assertSame(1, $this->repository()->count(new FindingReadFilter()));
        self::assertCount(1, $this->repository()->findPage(new FindingReadFilter(observation: 'inconclusive')));
        self::assertSame([], $this->entityManager->getUnitOfWork()->getIdentityMap());
        self::assertSame($before, $this->snapshot());
    }

    public function testLargeSyntheticInventoryRemainsPaginatedWithoutEvidenceHydration(): void
    {
        $seed = $this->finding('bulk');
        $connection = $this->entityManager->getConnection();
        $values = $connection->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$seed->getId()]);
        $connection->transactional(static function () use ($connection, $values): void {
            for ($index = 1; $index < 5500; $index++) {
                $row = $values;
                $row['id'] = 'bulk-case-'.str_pad((string) $index, 26, '0', STR_PAD_LEFT);
                $row['url'] = 'https://bulk.example.test/case/'.$index;
                $row['title'] = 'Synthetic case '.$index;
                $connection->insert('finding', $row);
            }
        });
        $this->entityManager->clear();
        $filter = new FindingReadFilter(observation: 'none');
        self::assertSame(5500, $this->repository()->count($filter));
        $first = $this->repository()->findPage($filter, 25);
        $second = $this->repository()->findPage($filter, 25, 25);
        self::assertCount(25, $first);
        self::assertCount(25, $second);
        self::assertSame([], array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        self::assertCount(1, $this->repository()->findPage($filter, 25, 5499));
        self::assertSame([], $this->repository()->findPage($filter, 25, 5500));
        self::assertSame([], $this->entityManager->getUnitOfWork()->getIdentityMap());
    }

    private function repository(): FindingReadRepository
    {
        return self::getContainer()->get(FindingReadRepository::class);
    }

    /** @param list<Finding> $expected */
    private function assertMatches(array $expected, FindingReadFilter $filter): void
    {
        $expectedIds = array_map(static fn (Finding $finding): string => $finding->getId(), $expected);
        $actualIds = array_map(static fn (FindingReadView $view): string => $view->id, $this->repository()->findPage($filter));
        sort($expectedIds);
        sort($actualIds);
        self::assertSame($expectedIds, $actualIds);
        self::assertSame(count($expectedIds), $this->repository()->count($filter));
    }

    /** @param list<FindingReadView> $views
     *  @return array<string, FindingReadView>
     */
    private function byId(array $views): array
    {
        $byId = [];
        foreach ($views as $view) {
            $byId[$view->id] = $view;
        }

        return $byId;
    }

    private function domain(string $hostname): Domain
    {
        $domain = (new Domain())->setHostname($hostname);
        $this->entityManager->persist($domain);
        $this->entityManager->flush();

        return $domain;
    }

    private function finding(string $name, ?Domain $domain = null): Finding
    {
        $domain ??= $this->domain($name.'.example.test');
        $finding = (new Finding())->setDomain($domain)->setTitle('Synthetic '.$name)->setType('synthetic')
            ->setUrl('https://'.$domain->getHostname().'/case/'.$name)
            ->setSubmittedAt(new \DateTimeImmutable('2026-10-02T09:00:00'));
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function observation(Finding $finding, string $result, string $started, ?string $finished = null, ?string $id = null): RetestRun
    {
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult($result)
            ->setStartedAt(new \DateTimeImmutable($started))
            ->setFinishedAt($finished !== null ? new \DateTimeImmutable($finished) : null);
        if ($id !== null) {
            (new \ReflectionProperty(RetestRun::class, 'id'))->setValue($run, $id);
        }
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['finding', 'domain', 'retest_run', 'evidence'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $snapshot;
    }
}
