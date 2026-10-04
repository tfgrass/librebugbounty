<?php

declare(strict_types=1);

namespace App\Tests;

use App\Dto\StatisticsPeriod;
use App\Dto\FindingReadFilter;
use App\Repository\FindingReadRepository;
use App\Repository\StatisticsRepository;
use App\Service\StatisticsService;
use App\Service\FindingListService;
use Doctrine\DBAL\Connection;

final class StatisticsServiceTest extends DatabaseTestCase
{
    private string $sourceTimezone;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->sourceTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        date_default_timezone_set($this->sourceTimezone);
    }

    public function testActivityIncludesArchiveAndUsesRecordedContactsAndFirstManualJudgment(): void
    {
        $manual = $this->finding('one.example.de', '2026-10-01 10:00:00', ['manual_assessment' => 'fixed', 'status' => 'fixed', 'contacted_at' => '2026-10-03 11:00:00', 'notified_owner_at' => '2026-10-02 11:00:00']);
        $this->assessment($manual, 'confirmed', '2026-10-01 11:00:00');
        $this->assessment($manual, 'confirmed', '2026-10-02 10:00:00');
        $this->assessment($manual, 'fixed', '2026-10-02 12:00:00');
        $this->assessment($manual, 'fixed', '2026-10-03 12:00:00');
        $this->finding('archive.example.de', '2026-10-02 10:00:00', ['status' => 'duplicate', 'contacted_at' => '2026-10-02 13:00:00']);
        $technical = $this->finding('technical.example.test', '2026-10-03 10:00:00', ['status' => 'fixed', 'reported_at' => '2026-10-03 10:00:00']);
        $this->connection()->insert('retest_run', [
            'id' => 'technical-fixed-observation', 'finding_id' => $technical, 'mode' => 'browser', 'result' => 'fixed',
            'started_at' => '2026-10-03 10:00:00', 'finished_at' => '2026-10-03 10:01:00',
            'created_at' => '2026-10-03 10:00:00', 'updated_at' => '2026-10-03 10:01:00',
        ]);
        $before = $this->databaseSnapshot();

        $view = $this->service()->get(['period' => 'month', 'anchor' => '2026-10-03'], $this->now('2026-10-03 18:00:00'));
        self::assertSame(3, $view['kpis']['reported']['count']);
        self::assertArrayNotHasKey('sent', $view['kpis']);
        self::assertSame(2, $view['kpis']['contacted']['count']);
        self::assertSame(1, $view['kpis']['confirmed']['count']);
        self::assertSame(1, $view['kpis']['fixed']['count']);
        self::assertSame(2, $view['totals']['active']);
        self::assertSame(1, $view['totals']['archived']);
        self::assertSame(1, $view['snapshotCounts']['fixed']);
        self::assertSame(1, $view['snapshotCounts']['unknown']);
        self::assertArrayNotHasKey('sent', $view['snapshotCounts']);
        self::assertArrayNotHasKey('sentDatedCount', $view['coverage']);
        self::assertArrayNotHasKey('contactedWithoutSentCount', $view['coverage']);
        self::assertSame(1, $view['coverage']['fixedWithoutDateCount']);
        $series = array_column($view['series'], null, 'date');
        self::assertSame(1, $series['2026-10-02']['fixed']);
        self::assertSame(0, $series['2026-10-03']['fixed']);
        self::assertSame(1, $series['2026-10-02']['contacted']);
        self::assertSame(1, $series['2026-10-03']['contacted']);
        self::assertSame('/findings?scope=all&event=contacted&from=2026-10-01&to=2026-10-31', $view['kpis']['contacted']['url']);
        $calendar = array_column($view['calendar'], null, 'date');
        self::assertSame(1, $calendar['2026-10-02']['contacted']);
        self::assertSame(1, $calendar['2026-10-03']['contacted']);
        foreach ([$series['2026-10-02'], $calendar['2026-10-02']] as $row) {
            self::assertSame(['reported', 'contacted', 'fixed'], array_keys($row['urls']));
            self::assertArrayNotHasKey('sent', $row);
            self::assertArrayNotHasKey('confirmed', $row);
        }
        self::assertSame($before, $this->databaseSnapshot(), 'Statistics reads must leave every table unchanged.');
        self::assertIsString(json_encode($view, JSON_THROW_ON_ERROR));
    }

    public function testMidnightInclusiveDatesAndDstUseBerlinCalendarDays(): void
    {
        $this->finding('before.test', '2026-03-28 23:59:59');
        $this->finding('first.test', '2026-03-29 00:00:00');
        $this->finding('last.test', '2026-03-29 23:59:59');
        $this->finding('after.test', '2026-03-30 00:00:00');
        $view = $this->service()->get(['period' => 'custom', 'from' => '2026-03-29', 'to' => '2026-03-29'], $this->now('2026-10-03 18:00:00'));
        self::assertSame(2, $view['kpis']['reported']['count']);
        self::assertCount(1, $view['series']);
        self::assertSame(2, $view['series'][0]['reported']);
        $calendar = array_column($view['calendar'], null, 'date');
        self::assertSame(2, $calendar['2026-03-29']['reported']);
        self::assertSame(1, $calendar['2026-03-30']['reported']);
        self::assertSame('/findings?scope=all&event=reported&from=2026-03-29&to=2026-03-29', $calendar['2026-03-29']['urls']['reported']);

        $this->finding('fall-first.test', '2026-10-25 00:00:00');
        $this->finding('fall-last.test', '2026-10-25 23:59:59');
        $this->finding('fall-next.test', '2026-10-26 00:00:00');
        $fall = $this->service()->get(['period' => 'custom', 'from' => '2026-10-25', 'to' => '2026-10-25'], $this->now('2026-11-01'));
        self::assertSame(2, $fall['kpis']['reported']['count']);
    }

    public function testHistoricalDeliveryDatesDoNotExtendActivityRangeOrBecomeContacts(): void
    {
        $this->finding('delivery-history.test', '2026-10-01 10:00:00', ['notified_owner_at' => '2020-01-01 09:00:00']);
        $this->finding('contact-history.test', '2026-10-02 10:00:00', ['contacted_at' => '2026-09-20 09:00:00']);
        $before = $this->databaseSnapshot();
        $view = $this->service()->get(['period' => 'all', 'heatmapMetric' => 'sent'], $this->now('2026-10-03 18:00:00'));

        self::assertSame('2026-09-20', $view['period']['from']);
        self::assertSame('2026-09-20', $view['coverage']['firstActivityDate']);
        self::assertSame(1, $view['kpis']['contacted']['count']);
        self::assertSame('contacted', $view['filters']['heatmapMetric']);
        self::assertSame(1, $view['history']['contactDatedCount']);
        self::assertStringNotContainsString('versendet', implode(' ', $view['notes']));
        self::assertSame($before, $this->databaseSnapshot(), 'Historical delivery data stays stored independently of the dashboard.');

        $oldConfirmationBookmark = $this->service()->get(['heatmapMetric' => 'confirmed'], $this->now('2026-10-03'));
        self::assertSame('reported', $oldConfirmationBookmark['filters']['heatmapMetric']);
    }

    public function testNaiveTimestampsUseRuntimeSourceZoneAndFallbackCreatedAt(): void
    {
        date_default_timezone_set('UTC');
        $this->finding('utc.example.de', null, ['created_at' => '2026-10-02 22:00:00']);
        $this->finding('utc-before.example.de', '2026-10-02 21:59:59');
        $view = $this->service()->get(['period' => 'custom', 'from' => '2026-10-03', 'to' => '2026-10-03'], $this->now('2026-10-04'));
        self::assertSame(1, $view['kpis']['reported']['count']);
        self::assertSame('Europe/Berlin', $view['period']['timezone']);
        self::assertSame(1, $view['series'][0]['reported']);
    }

    public function testOngoingMonthComparesOnlyEqualElapsedPriorSpan(): void
    {
        $this->finding('current-first.test', '2026-10-01 11:00:00');
        $this->finding('current-second.test', '2026-10-03 11:00:00');
        $this->finding('previous-first.test', '2026-09-01 11:00:00');
        $this->finding('previous-late.test', '2026-09-20 11:00:00');
        $view = $this->service()->get(['anchor' => '2026-10-03'], $this->now('2026-10-03 12:00:00'));
        self::assertSame(2, $view['kpis']['reported']['count']);
        self::assertSame(2, $view['kpis']['reported']['comparisonCount']);
        self::assertSame(1, $view['kpis']['reported']['previousCount']);
        self::assertSame(100.0, $view['kpis']['reported']['deltaPercent']);
        self::assertStringContainsString('gleich lange Zeitspannen', $view['period']['comparisonLabel']);
        self::assertStringContainsString('anchor=2026-09-01', $view['period']['previousUrl']);
        self::assertStringContainsString('anchor=2026-11-01', $view['period']['nextUrl']);

        $period = StatisticsPeriod::fromQuery(['period' => 'month', 'anchor' => '2028-03-31'], $this->now('2028-03-31 12:00:00'));
        $comparison = $period->comparison();
        self::assertNotNull($comparison);
        self::assertSame($comparison[1]->getTimestamp() - $comparison[0]->getTimestamp(), $comparison[3]->getTimestamp() - $comparison[2]->getTimestamp());
        self::assertLessThanOrEqual($period->previousUntil, $comparison[3]);
    }

    public function testLeapYearIsoWeekAndLongRangesProduceUsefulBoundedCalendars(): void
    {
        $this->finding('ancient.test', '1990-01-01 12:00:00');
        $leap = $this->service()->get(['period' => 'year', 'anchor' => '2028-02-29'], $this->now('2028-03-01'));
        self::assertCount(366, $leap['calendar']);
        self::assertCount(366, $leap['series']);
        self::assertSame('day', $leap['period']['granularity']);
        $week = StatisticsPeriod::fromQuery(['period' => 'week', 'anchor' => '2026-01-01'], $this->now('2026-10-03'));
        self::assertSame('2025-12-29', $week->from->format('Y-m-d'));
        self::assertSame('2026-01-05', $week->until->format('Y-m-d'));
        $all = $this->service()->get(['period' => 'all'], $this->now('2026-10-03'));
        self::assertSame('1990-01-01', $all['period']['from']);
        self::assertLessThanOrEqual(730, count($all['series']));
        self::assertNull($all['period']['previousUrl']);
        self::assertNull($all['kpis']['reported']['delta']);
        $veryLong = $this->service()->get(['period' => 'custom', 'from' => '1000-01-01', 'to' => '9998-12-31'], $this->now('2026-10-03'));
        self::assertLessThanOrEqual(730, count($veryLong['series']));
        self::assertGreaterThan(1, $veryLong['period']['bucketMonths']);
    }

    public function testTldCasesHostsAndIpLocalCohortsRemainDistinct(): void
    {
        foreach (['same.example.de', 'same.example.de', 'another.example.de', 'site.co.uk', '127.0.0.1', '[::1]', 'localhost', 'work.local'] as $host) {
            $this->finding($host, '2026-10-02 12:00:00');
        }
        $view = $this->service()->get(['anchor' => '2026-10-03'], $this->now('2026-10-03'));
        self::assertSame(8, $view['tlds']['caseTotal']);
        self::assertSame(7, $view['tlds']['hostTotal']);
        self::assertNull($view['kpis']['hosts']['url']);
        $cases = array_column($view['tlds']['cases'], null, 'key');
        $hosts = array_column($view['tlds']['hosts'], null, 'key');
        self::assertSame(3, $cases['.de']['count']);
        self::assertSame(2, $hosts['.de']['count']);
        self::assertSame(2, $cases['ip']['count']);
        self::assertSame(2, $cases['local']['count']);
        self::assertSame(1, $cases['.uk']['count']);
        self::assertStringContainsString('tld=.de', $cases['.de']['url']);
        self::assertNull($hosts['.de']['url']);
        $filtered = $this->service()->get(['anchor' => '2026-10-03', 'tld' => '.de', 'heatmapMetric' => 'sent', 'tldMeasure' => 'hosts'], $this->now('2026-10-03'));
        self::assertSame(3, $filtered['kpis']['reported']['count']);
        self::assertSame(2, $filtered['kpis']['hosts']['count']);
        self::assertSame('contacted', $filtered['filters']['heatmapMetric'], 'Old sent heatmap bookmarks open the current contact calendar.');
        self::assertStringContainsString('tld=.de', $filtered['period']['previousUrl']);
        self::assertSame(3, $filtered['totals']['all']);
    }

    public function testTldRemainderAndUnknownHistoryDoNotCreateMisleadingLinks(): void
    {
        foreach (['de', 'com', 'net', 'org', 'info', 'uk', 'test'] as $suffix) {
            $this->finding('host.example.'.$suffix, '2026-10-02 12:00:00');
        }
        $view = $this->service()->get([], $this->now('2026-10-03'));
        self::assertCount(6, $view['tlds']['cases']);
        $other = $view['tlds']['cases'][5];
        self::assertSame('other', $other['key']);
        self::assertSame(2, $other['count']);
        self::assertNull($other['url']);
        self::assertNull($view['coverage']['assessmentHistoryFrom']);
        self::assertSame(0, $view['kpis']['fixed']['count']);
    }

    public function testSharedTldClassificationMatchesListCountsForEverySegment(): void
    {
        foreach (['target.example.de', 'UPPER.EXAMPLE.DE.', 'host.co.uk', 'localhost', 'work.local', 'work.localhost', '127.0.0.1', '999.0.0.1', '[::1]', 'target.example.test', 'example.1'] as $hostname) {
            $this->finding($hostname, '2026-10-02 12:00:00');
        }
        $view = $this->service()->get([], $this->now('2026-10-03'));
        $repository = new FindingReadRepository($this->connection());
        foreach ($view['tlds']['cases'] as $segment) {
            if ($segment['key'] === 'other') {
                continue;
            }
            $filter = new FindingReadFilter(scope: 'all', event: 'reported', from: $view['period']['from'], to: $view['period']['to'], tld: $segment['key']);
            self::assertSame($segment['count'], $repository->count($filter), $segment['key'].' must count the same cases on dashboard and list.');
        }
    }

    public function testCurrentConfirmedUncontactedAgingUsesCalendarIngestAgeAndIgnoresArchive(): void
    {
        foreach ([['2026-09-26', 'recent'], ['2026-09-25', 'waiting'], ['2026-09-03', 'waiting'], ['2026-09-02', 'old']] as [$date]) {
            $this->finding('waiting-'.$date.'.test', $date.' 23:59:59', ['manual_assessment' => 'confirmed']);
        }
        $this->finding('archived.test', '2026-09-01', ['manual_assessment' => 'confirmed', 'status' => 'duplicate']);
        $this->finding('contacted.test', '2026-09-01', ['manual_assessment' => 'confirmed', 'contacted_at' => '2026-10-01']);
        $this->finding('sent-only.test', '2026-09-01', ['manual_assessment' => 'confirmed', 'notified_owner_at' => '2026-10-01']);
        $view = $this->service()->get([], $this->now('2026-10-03 00:00:00'));
        $aging = array_column($view['aging'], null, 'key');
        self::assertSame(1, $aging['recent']['count']);
        self::assertSame(2, $aging['waiting']['count']);
        self::assertSame(2, $aging['old']['count'], 'Historical delivery markers no longer exclude uncontacted cases.');
        self::assertStringContainsString('from=2026-09-03&to=2026-09-25', $aging['waiting']['url']);
        self::assertStringContainsString('to=2026-09-02', $aging['old']['url']);
        self::assertStringContainsString('contact=no&event=reported', $aging['old']['url']);
        self::assertStringNotContainsString('sent=', $aging['old']['url']);
        $this->assertHistoryLinksMatch($view['aging']);
    }

    public function testPreservedLegacyMarkersRemainVisibleWithoutInventingJudgmentDates(): void
    {
        $this->finding('fixed-marker.example.de', '2026-07-01 12:00:00', ['status' => 'fixed', 'review_state' => 'confirmed_fixed', 'contacted_at' => '2026-07-09 22:09:28']);
        $this->finding('checked-marker.example.de', '2026-07-02 12:00:00', ['status' => 'verified', 'review_state' => 'manually_checked']);
        $new = $this->finding('new-manual.example.de', '2026-10-01 12:00:00', ['status' => 'fixed', 'manual_assessment' => 'fixed', 'review_state' => 'confirmed_fixed', 'assessed_at' => '2026-10-02 12:00:00']);
        $this->assessment($new, 'fixed', '2026-10-02 12:00:00');
        $this->finding('archived-marker.example.test', '2026-08-01 12:00:00', ['status' => 'duplicate', 'review_state' => 'confirmed_fixed', 'contacted_at' => '2026-08-20 12:00:00']);
        $this->finding('old-status.example.test', '2026-08-02 12:00:00', ['status' => 'fixed']);
        $before = $this->databaseSnapshot();
        $view = $this->service()->get(['period' => 'month', 'anchor' => '2026-10-03'], $this->now('2026-10-03'));
        $items = array_column($view['history']['items'], null, 'key');
        self::assertSame(2, $items['fixed_marker']['count']);
        self::assertSame(1, $items['checked_marker']['count']);
        self::assertSame(2, $items['fixed_status']['count']);
        self::assertTrue($view['history']['hasUndated']);
        self::assertSame(2, $view['history']['contactDatedCount']);
        self::assertSame('2026-07-09', $view['history']['contactFrom']);
        self::assertSame(0, $view['kpis']['contacted']['count'], 'The selected period stays separate from all retained historical contacts.');
        self::assertSame(1, $view['kpis']['fixed']['count'], 'Only the new dated manual history contributes to the fixed curve.');
        self::assertSame(0, $view['kpis']['confirmed']['count']);
        self::assertStringContainsString('überschneiden', implode(' ', $view['notes']));
        self::assertStringContainsString('nicht immer den ersten Kontakt', implode(' ', $view['notes']));
        $calendar = array_column($view['calendar'], null, 'date');
        self::assertSame(1, $calendar['2026-07-09']['contacted']);
        self::assertSame(0, $calendar['2026-07-09']['fixed']);
        $filtered = $this->service()->get(['period' => 'week', 'anchor' => '2026-10-03', 'tld' => '.de'], $this->now('2026-10-03'));
        $filteredItems = array_column($filtered['history']['items'], null, 'key');
        self::assertSame(1, $filteredItems['fixed_marker']['count']);
        self::assertSame(1, $filteredItems['fixed_status']['count']);
        self::assertSame(1, $filtered['history']['contactDatedCount']);
        $this->assertHistoryLinksMatch($view['history']['items']);
        $this->assertHistoryLinksMatch($filtered['history']['items']);
        self::assertSame($before, $this->databaseSnapshot());
    }

    public function testInvalidDatesRangesAndScalarFiltersFailBeforeReturningStatistics(): void
    {
        foreach ([
            ['period' => 'nonsense'], ['granularity' => 'hour'], ['anchor' => '2026-02-29'],
            ['anchor' => '2026-10-3'], ['anchor' => ['2026-10-03']], ['anchor' => null],
            ['period' => 'custom', 'from' => '2026-10-03'],
            ['period' => 'custom', 'from' => '2026-10-03', 'to' => '2026-10-02'],
            ['period' => 'month', 'from' => '2026-02-30'],
            ['tld' => '.de%'], ['tld' => '.--'], ['tld' => '.'.str_repeat('x', 64)], ['anchor' => '9999-01-01'],
            ['anchor' => "2026-10-03\0"], ['heatmapMetric' => 'hosts'], ['tldMeasure' => 'countries'],
            ['period' => 'week', 'anchor' => '9998-12-31'],
        ] as $query) {
            try {
                $this->service()->get($query, $this->now('2026-10-03'));
                self::fail('Expected validation failure for '.json_encode($query));
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testLegacyReviewQueryValidationAndPreservation(): void
    {
        $list = self::getContainer()->get(FindingListService::class);
        foreach (['confirmed_fixed', 'manually_checked'] as $marker) {
            $query = ['scope' => 'all', 'assessment' => 'unknown', 'legacy_review' => $marker];
            self::assertTrue($list->hasListQuery($query));
            [$filter] = $list->parse($query);
            self::assertSame($marker, $list->filterQuery($filter)['legacy_review']);
        }
        $this->expectException(\InvalidArgumentException::class);
        $list->parse(['legacy_review' => 'unrecognized_marker']);
    }

    private function finding(string $hostname, ?string $reportedAt, array $overrides = []): string
    {
        $domainId = 'domain-'.(++$this->sequence);
        $findingId = 'finding-'.$this->sequence;
        $existing = $this->connection()->fetchOne('SELECT id FROM domain WHERE hostname = ?', [$hostname]);
        if (is_string($existing)) {
            $domainId = $existing;
        } else {
            $this->connection()->insert('domain', ['id' => $domainId, 'hostname' => $hostname, 'scheme' => 'http', 'authorized' => 0, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        }
        $data = array_replace([
            'id' => $findingId, 'domain_id' => $domainId, 'title' => 'Synthetic statistics fixture', 'type' => 'fixture',
            'severity' => 'medium', 'status' => 'new', 'url' => 'http://'.$hostname.'/fixture-'.$this->sequence,
            'method' => 'GET', 'submitted_at' => $reportedAt, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
        ], $overrides);
        $this->connection()->insert('finding', $data);
        return $findingId;
    }

    private function assessment(string $findingId, string $assessment, string $at): void
    {
        $this->connection()->insert('finding_assessment', [
            'id' => 'assessment-'.(++$this->sequence), 'finding_id' => $findingId, 'assessment' => $assessment,
            'assessed_at' => $at, 'source' => 'manual', 'known_observation_ids' => '[]', 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function service(): StatisticsService
    {
        return new StatisticsService(new StatisticsRepository($this->connection()));
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private function now(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Berlin'));
    }

    private function databaseSnapshot(): array
    {
        $snapshot = [];
        foreach (['domain', 'finding', 'finding_assessment', 'retest_run', 'evidence', 'screenshot_job', 'setting'] as $table) {
            $snapshot[$table] = $this->connection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid');
        }
        return $snapshot;
    }

    private function assertHistoryLinksMatch(array $items): void
    {
        $list = self::getContainer()->get(FindingListService::class);
        $repository = new FindingReadRepository($this->connection());
        foreach ($items as $item) {
            parse_str(parse_url($item['url'], PHP_URL_QUERY) ?? '', $query);
            [$filter] = $list->parse($query);
            self::assertSame($item['count'], $repository->count($filter), $item['key'].' links to the exact retained legacy cohort.');
        }
    }
}
