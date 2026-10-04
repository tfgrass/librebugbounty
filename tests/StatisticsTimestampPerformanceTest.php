<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\StatisticsRepository;
use App\Service\StatisticsService;
use PHPUnit\Framework\Attributes\DataProvider;

final class StatisticsTimestampPerformanceTest extends DatabaseTestCase
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
        try {
            parent::tearDown();
        } finally {
            date_default_timezone_set($this->sourceTimezone);
        }
    }

    #[DataProvider('sourceZoneDays')]
    public function testEventsUseBerlinDaysAcrossSourceZonesAndClockChanges(string $sourceZone, string $day, array $dates): void
    {
        date_default_timezone_set($sourceZone);
        foreach ($dates as $date) {
            $this->datedEvents($date);
        }

        $view = $this->statistics(['period' => 'custom', 'from' => $day, 'to' => $day], '2026-11-01');
        $calendar = array_column($view['calendar'], null, 'date');
        $before = $day === '2026-03-29' ? '2026-03-28' : '2026-10-24';
        $after = $day === '2026-03-29' ? '2026-03-30' : '2026-10-26';
        foreach (['reported', 'contacted', 'fixed', 'confirmed'] as $metric) {
            self::assertSame(4, $view['kpis'][$metric]['count'], $metric.' uses inclusive midnight and exclusive next midnight.');
            self::assertSame(1, $view['kpis'][$metric]['previousCount']);
        }
        foreach (['reported', 'contacted', 'fixed'] as $metric) {
            self::assertSame(4, $view['series'][0][$metric]);
            self::assertSame(1, $calendar[$before][$metric]);
            self::assertSame(4, $calendar[$day][$metric]);
            self::assertSame(1, $calendar[$after][$metric]);
        }
        self::assertSame($before, $view['coverage']['firstActivityDate']);
        self::assertSame($before, $view['coverage']['assessmentHistoryFrom']);
        self::assertSame($before, $view['history']['contactFrom']);
        self::assertSame(6, $view['history']['contactDatedCount']);
    }

    public static function sourceZoneDays(): iterable
    {
        yield 'Berlin spring, including nonexistent local time' => ['Europe/Berlin', '2026-03-29', [
            '2026-03-28 23:59:59', '2026-03-29 00:00:00', '2026-03-29 02:30:00',
            '2026-03-29 03:30:00', '2026-03-29 23:59:59', '2026-03-30 00:00:00',
        ]];
        yield 'UTC spring' => ['UTC', '2026-03-29', [
            '2026-03-28 22:59:59', '2026-03-28 23:00:00', '2026-03-29 00:30:00',
            '2026-03-29 01:30:00', '2026-03-29 21:59:59', '2026-03-29 22:00:00',
        ]];
        yield 'New York spring, with a different DST calendar' => ['America/New_York', '2026-03-29', [
            '2026-03-28 18:59:59', '2026-03-28 19:00:00', '2026-03-28 20:30:00',
            '2026-03-28 21:30:00', '2026-03-29 17:59:59', '2026-03-29 18:00:00',
        ]];
        yield 'Berlin autumn, including ambiguous local time' => ['Europe/Berlin', '2026-10-25', [
            '2026-10-24 23:59:59', '2026-10-25 00:00:00', '2026-10-25 02:30:00',
            '2026-10-25 02:30:00+02:00', '2026-10-25 23:59:59', '2026-10-26 00:00:00',
        ]];
        yield 'UTC autumn, both occurrences of Berlin 02:30' => ['UTC', '2026-10-25', [
            '2026-10-24 21:59:59', '2026-10-24 22:00:00', '2026-10-25 00:30:00',
            '2026-10-25 01:30:00', '2026-10-25 22:59:59', '2026-10-25 23:00:00',
        ]];
        yield 'New York autumn, before its own clock change' => ['America/New_York', '2026-10-25', [
            '2026-10-24 17:59:59', '2026-10-24 18:00:00', '2026-10-24 20:30:00',
            '2026-10-24 21:30:00', '2026-10-25 18:59:59', '2026-10-25 19:00:00',
        ]];
    }

    public function testOngoingComparisonExcludesExactEndAndDistinguishesRepeatedAutumnTimes(): void
    {
        foreach ([
            '2026-10-24 00:00:00', '2026-10-24 03:29:59', '2026-10-24 03:30:00',
            '2026-10-25 00:00:00', '2026-10-25 02:30:00+02:00', '2026-10-25 02:29:59+01:00',
            '2026-10-25 02:30:00', '2026-10-25 02:30:00+01:00',
        ] as $date) {
            $this->datedEvents($date);
        }

        $view = $this->statistics(['period' => 'custom', 'from' => '2026-10-25', 'to' => '2026-10-25'], '2026-10-25 02:30:00+01:00');
        foreach (['reported', 'contacted', 'fixed', 'confirmed', 'hosts'] as $metric) {
            self::assertSame(5, $view['kpis'][$metric]['count']);
            self::assertSame(3, $view['kpis'][$metric]['comparisonCount'], 'The first 02:30 precedes now; naive 02:30 denotes the second occurrence.');
            self::assertSame(2, $view['kpis'][$metric]['previousCount'], 'The prior equal elapsed interval ends at 03:30, exclusively.');
            self::assertSame(50.0, $view['kpis'][$metric]['deltaPercent']);
        }
    }

    public function testZeroAndNegativeEpochEventsRemainDated(): void
    {
        date_default_timezone_set('UTC');
        foreach ([
            '1969-12-30 23:00:00', '1969-12-31 22:59:59', '1969-12-31 23:00:00',
            '1969-12-31 23:59:59', '1970-01-01 00:00:00', '1970-01-01 23:00:00',
        ] as $date) {
            $this->datedEvents($date);
        }

        $view = $this->statistics(['period' => 'custom', 'from' => '1969-12-31', 'to' => '1970-01-01'], '1970-02-01');
        $series = array_column($view['series'], null, 'date');
        foreach (['reported', 'contacted', 'fixed', 'confirmed'] as $metric) {
            self::assertSame(5, $view['kpis'][$metric]['count']);
        }
        foreach (['reported', 'contacted', 'fixed'] as $metric) {
            self::assertSame(2, $series['1969-12-31'][$metric]);
            self::assertSame(3, $series['1970-01-01'][$metric]);
        }
        self::assertSame('1969-12-31', $view['coverage']['firstActivityDate']);
        self::assertSame('1969-12-31', $view['history']['contactFrom']);
        self::assertSame(6, $view['history']['contactDatedCount']);
    }

    public function testMissingEventsStayUndatedAndCreationIsOnlyAReportingFallback(): void
    {
        date_default_timezone_set('UTC');
        $this->finding(null, ['created_at' => '2026-10-02 22:00:00', 'manual_assessment' => 'confirmed']);
        $this->finding('2026-10-02 21:59:59', ['created_at' => '2026-10-03 12:00:00', 'manual_assessment' => 'fixed']);

        $view = $this->statistics(['period' => 'custom', 'from' => '2026-10-03', 'to' => '2026-10-03'], '2026-10-04');
        self::assertSame(1, $view['kpis']['reported']['count']);
        self::assertSame(1, $view['series'][0]['reported']);
        foreach (['contacted', 'fixed', 'confirmed'] as $metric) {
            self::assertSame(0, $view['kpis'][$metric]['count']);
        }
        self::assertNull($view['history']['contactFrom']);
        self::assertNull($view['coverage']['assessmentHistoryFrom']);
        self::assertSame(0, $view['history']['contactDatedCount']);
        self::assertSame(1, $view['coverage']['confirmedWithoutDateCount']);
        self::assertSame(1, $view['coverage']['fixedWithoutDateCount']);
    }

    #[DataProvider('agingDays')]
    public function testAgingUsesCalendarDaysAcrossDstAndIncludesFutureDatesAsRecent(string $now, array $dates): void
    {
        date_default_timezone_set('UTC');
        foreach ($dates as $date) {
            $this->finding($date, ['manual_assessment' => 'confirmed']);
        }

        $view = $this->statistics([], $now);
        self::assertSame(['recent' => 3, 'waiting' => 2, 'old' => 1], array_column($view['aging'], 'count', 'key'));
        self::assertSame(6, $view['snapshotCounts']['confirmed']);
    }

    public static function agingDays(): iterable
    {
        // Future, today, and exactly 7, 8, 30 and 31 Berlin calendar days old.
        yield '23-hour spring day' => ['2026-04-05 12:00:00', [
            '2026-04-05 22:00:00', '2026-04-04 22:00:00', '2026-03-28 23:00:00',
            '2026-03-28 22:59:59', '2026-03-05 23:00:00', '2026-03-05 22:59:59',
        ]];
        yield '25-hour autumn day' => ['2026-11-01 12:00:00', [
            '2026-11-01 23:00:00', '2026-10-31 23:00:00', '2026-10-24 22:00:00',
            '2026-10-24 21:59:59', '2026-10-01 22:00:00', '2026-10-01 21:59:59',
        ]];
    }

    private function finding(?string $reportedAt, array $overrides = []): string
    {
        $number = ++$this->sequence;
        $domainId = 'timestamp-domain-'.$number;
        $findingId = 'timestamp-finding-'.$number;
        $hostname = 'timestamp-'.$number.'.example.test';
        $connection = $this->entityManager->getConnection();
        $connection->insert('domain', [
            'id' => $domainId, 'hostname' => $hostname, 'scheme' => 'http', 'authorized' => 0,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $connection->insert('finding', array_replace([
            'id' => $findingId, 'domain_id' => $domainId, 'title' => 'Timestamp regression fixture', 'type' => 'fixture',
            'severity' => 'medium', 'status' => 'new', 'url' => 'http://'.$hostname.'/fixture', 'method' => 'GET',
            'submitted_at' => $reportedAt, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ], $overrides));

        return $findingId;
    }

    private function datedEvents(string $date): void
    {
        $findingId = $this->finding($date, ['contacted_at' => $date]);
        foreach (['confirmed', 'fixed'] as $assessment) {
            $this->entityManager->getConnection()->insert('finding_assessment', [
                'id' => $findingId.'-'.$assessment, 'finding_id' => $findingId, 'assessment' => $assessment,
                'assessed_at' => $date, 'source' => 'manual', 'known_observation_ids' => '[]', 'created_at' => $date, 'updated_at' => $date,
            ]);
        }
    }

    private function statistics(array $query, string $now): array
    {
        return (new StatisticsService(new StatisticsRepository($this->entityManager->getConnection())))
            ->get($query, new \DateTimeImmutable($now, new \DateTimeZone('Europe/Berlin')));
    }
}
