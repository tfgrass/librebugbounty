<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\StatisticsPeriod;
use App\Dto\FindingReadFilter;
use App\Repository\StatisticsRepository;
use App\Value\HostnameTld;

final class StatisticsService
{
    private const ACTIVITY_METRICS = [
        'reported' => 'Gemeldet',
        'contacted' => 'Kontaktiert',
        'fixed' => 'Behoben',
    ];
    private const METRICS = self::ACTIVITY_METRICS + [
        'confirmed' => 'Bestätigt',
    ];
    private const MAX_BUCKETS = 730;

    public function __construct(private readonly StatisticsRepository $statistics)
    {
    }

    /** @param array<string, mixed> $query
     *  @return array<string, mixed>
     */
    public function get(array $query, ?\DateTimeImmutable $now = null): array
    {
        $filters = $this->filters($query);
        $sourceZone = new \DateTimeZone(date_default_timezone_get());
        $displayZone = new \DateTimeZone('Europe/Berlin');
        $parse = static fn (?string $date): ?\DateTimeImmutable => $date === null ? null : (new \DateTimeImmutable($date, $sourceZone))->setTimezone($displayZone);
        $first = $parse($this->statistics->firstActivityAt());
        $historyFrom = $parse($this->statistics->firstAssessmentAt());
        $period = StatisticsPeriod::fromQuery($query, $now ?? new \DateTimeImmutable(), $first);
        $comparison = $period->comparison();
        [$series, $granularity, $bucketMonths] = $this->buckets($period, $filters['tld']);
        $calendarYear = (int) $period->anchor->format('Y');
        $calendarStart = $period->anchor->setDate($calendarYear, 1, 1);
        $calendar = [];
        for ($date = $calendarStart; $date < $calendarStart->modify('+1 year'); $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $calendar[$key] = ['date' => $key] + array_fill_keys(array_keys(self::ACTIVITY_METRICS), 0) + [
                'urls' => $this->eventUrls($key, $key, $filters['tld']),
            ];
        }
        $counts = $currentComparison = $previousCounts = array_fill_keys(array_keys(self::METRICS), 0);
        $hosts = $comparisonHosts = $previousHosts = [];
        $tldCases = $tldHosts = $options = [];
        $snapshotCounts = ['active' => 0, 'confirmed' => 0, 'fixed' => 0, 'unknown' => 0, 'contacted' => 0];
        $agingCounts = ['recent' => 0, 'waiting' => 0, 'old' => 0];
        $totals = ['all' => 0, 'active' => 0, 'archived' => 0, 'duplicates' => 0];
        $legacyCounts = ['fixed_marker' => 0, 'checked_marker' => 0, 'fixed_status' => 0];
        $contactDatedCount = 0;
        $contactFrom = null;
        $coverage = [
            'firstActivityDate' => $first?->format('Y-m-d'),
            'assessmentHistoryFrom' => $historyFrom?->format('Y-m-d'),
            'fixedWithoutDateCount' => 0,
            'confirmedWithoutDateCount' => 0,
        ];
        $today = $period->now->setTime(0, 0);
        foreach ($this->statistics->caseFacts() as $fact) {
            $hostname = strtolower(rtrim((string) $fact['hostname'], '.'));
            $tld = HostnameTld::key($hostname);
            $options[$tld] = $this->tldLabel($tld);
            if ($filters['tld'] !== '' && $filters['tld'] !== $tld) {
                continue;
            }
            $events = [
                'reported' => $parse($fact['reported_at']),
                'contacted' => $parse($fact['contacted_at']),
                'confirmed' => $parse($fact['confirmed_at']),
                'fixed' => $parse($fact['fixed_at']),
            ];
            $totals['all']++;
            $totals[(bool) $fact['active'] ? 'active' : 'archived']++;
            $totals['duplicates'] += (int) $fact['duplicate'];
            $coverage['fixedWithoutDateCount'] += ($fact['manual_assessment'] === 'fixed' || $fact['status'] === 'fixed') && $events['fixed'] === null ? 1 : 0;
            $coverage['confirmedWithoutDateCount'] += $fact['manual_assessment'] === 'confirmed' && $events['confirmed'] === null ? 1 : 0;
            if ($events['contacted'] !== null) {
                $contactDatedCount++;
                $contactFrom = $contactFrom === null ? $events['contacted'] : min($contactFrom, $events['contacted']);
            }
            // Preserved legacy markers describe state, never a dated new judgment.
            // A case can have both a marker and a raw fixed status.
            if ($fact['manual_assessment'] === null) {
                $legacyCounts['fixed_marker'] += $fact['review_state'] === 'confirmed_fixed' ? 1 : 0;
                $legacyCounts['checked_marker'] += $fact['review_state'] === 'manually_checked' ? 1 : 0;
                $legacyCounts['fixed_status'] += $fact['status'] === 'fixed' ? 1 : 0;
            }
            foreach ($events as $metric => $date) {
                if ($date === null) {
                    continue;
                }
                if ($this->within($date, $period->from, $period->until)) {
                    $counts[$metric]++;
                    $index = $this->bucketIndex($series, $date->getTimestamp());
                    if ($index !== null && isset(self::ACTIVITY_METRICS[$metric])) {
                        $series[$index][$metric]++;
                    }
                    if ($metric === 'reported') {
                        $hosts[$hostname] = true;
                        $tldCases[$tld] = ($tldCases[$tld] ?? 0) + 1;
                        $tldHosts[$tld][$hostname] = true;
                    }
                }
                $day = $date->format('Y-m-d');
                if (isset($calendar[$day]) && isset(self::ACTIVITY_METRICS[$metric])) {
                    $calendar[$day][$metric]++;
                }
                if ($comparison !== null && $this->within($date, $comparison[0], $comparison[1])) {
                    $currentComparison[$metric]++;
                    if ($metric === 'reported') {
                        $comparisonHosts[$hostname] = true;
                    }
                }
                if ($comparison !== null && $this->within($date, $comparison[2], $comparison[3])) {
                    $previousCounts[$metric]++;
                    if ($metric === 'reported') {
                        $previousHosts[$hostname] = true;
                    }
                }
            }
            if (!(bool) $fact['active']) {
                continue;
            }
            $snapshotCounts['active']++;
            $assessment = $fact['manual_assessment'] ?? 'unknown';
            if (isset($snapshotCounts[$assessment])) {
                $snapshotCounts[$assessment]++;
            }
            $snapshotCounts['contacted'] += $events['contacted'] !== null ? 1 : 0;
            if ($assessment === 'confirmed' && $events['contacted'] === null && $events['reported'] !== null) {
                // Waiting age is the calendar age since Ingest, matching list links.
                $age = (int) $events['reported']->setTime(0, 0)->diff($today)->format('%r%a');
                $agingCounts[$age <= 7 ? 'recent' : ($age <= 30 ? 'waiting' : 'old')]++;
            }
        }
        ksort($options, SORT_STRING);
        $tldOptions = [['key' => '', 'label' => 'Alle TLDs']];
        foreach ($options as $key => $label) {
            $tldOptions[] = ['key' => $key, 'label' => $label];
        }
        // A valid selected suffix with no matches remains visible in the form.
        if ($filters['tld'] !== '' && !isset($options[$filters['tld']])) {
            $tldOptions[] = ['key' => $filters['tld'], 'label' => $this->tldLabel($filters['tld'])];
        }
        $from = $period->from->format('Y-m-d');
        $to = $period->until->modify('-1 day')->format('Y-m-d');
        $kpis = [];
        foreach (self::METRICS as $key => $label) {
            $kpis[$key] = $this->kpi($label, $counts[$key], $comparison === null ? null : $currentComparison[$key], $comparison === null ? null : $previousCounts[$key], $this->eventUrl($key, $from, $to, $filters['tld']));
        }
        $kpis['hosts'] = $this->kpi('Hosts', count($hosts), $comparison === null ? null : count($comparisonHosts), $comparison === null ? null : count($previousHosts), null);
        $snapshot = [];
        foreach (['confirmed' => 'Manuell bestätigt', 'fixed' => 'Manuell behoben', 'unknown' => 'Ohne aufgezeichnete Bewertung'] as $key => $label) {
            $snapshot[] = ['key' => $key, 'label' => $label, 'count' => $snapshotCounts[$key], 'url' => $this->listUrl(['scope' => 'active', 'assessment' => $key], $filters['tld'])];
        }
        $aging = [
            ['key' => 'recent', 'label' => 'Bis 7 Tage', 'count' => $agingCounts['recent'], 'url' => $this->listUrl(['scope' => 'active', 'assessment' => 'confirmed', 'contact' => 'no', 'event' => 'reported', 'from' => $today->modify('-7 days')->format('Y-m-d')], $filters['tld'])],
            ['key' => 'waiting', 'label' => '8–30 Tage', 'count' => $agingCounts['waiting'], 'url' => $this->listUrl(['scope' => 'active', 'assessment' => 'confirmed', 'contact' => 'no', 'event' => 'reported', 'from' => $today->modify('-30 days')->format('Y-m-d'), 'to' => $today->modify('-8 days')->format('Y-m-d')], $filters['tld'])],
            ['key' => 'old', 'label' => 'Über 30 Tage', 'count' => $agingCounts['old'], 'url' => $this->listUrl(['scope' => 'active', 'assessment' => 'confirmed', 'contact' => 'no', 'event' => 'reported', 'to' => $today->modify('-31 days')->format('Y-m-d')], $filters['tld'])],
        ];
        $history = [
            'contactDatedCount' => $contactDatedCount,
            'contactFrom' => $contactFrom?->format('Y-m-d'),
            'items' => [
                ['key' => 'fixed_marker', 'label' => 'Als behoben markiert', 'count' => $legacyCounts['fixed_marker'], 'url' => $this->listUrl(['scope' => 'all', 'assessment' => 'unknown', 'legacy_review' => 'confirmed_fixed'], $filters['tld'])],
                ['key' => 'checked_marker', 'label' => 'Als geprüft markiert', 'count' => $legacyCounts['checked_marker'], 'url' => $this->listUrl(['scope' => 'all', 'assessment' => 'unknown', 'legacy_review' => 'manually_checked'], $filters['tld'])],
                ['key' => 'fixed_status', 'label' => 'Historischer Status „fixed“', 'count' => $legacyCounts['fixed_status'], 'url' => $this->listUrl(['scope' => 'all', 'assessment' => 'unknown', 'legacy_status' => 'fixed'], $filters['tld'])],
            ],
            'hasUndated' => array_sum($legacyCounts) > 0,
        ];
        $notes = [];
        if ($granularity !== $period->granularity || $bucketMonths > 1) {
            $notes[] = 'Der lange Zeitraum wird für eine lesbare Darstellung in '.($bucketMonths > 1 ? $bucketMonths.'-Monatsgruppen' : ($granularity === 'week' ? 'Wochen' : 'Monaten')).' zusammengefasst.';
        }
        if ($coverage['fixedWithoutDateCount'] > 0 || $coverage['confirmedWithoutDateCount'] > 0) {
            $notes[] = 'Historische Statuswerte ohne aufgezeichnete manuelle Bewertung erhalten keinen erfundenen Bestätigungs- oder Behebungszeitpunkt.';
        }
        if ($historyFrom !== null && $period->from < $historyFrom) {
            $notes[] = 'Bewertungshistorie ist erst ab '.$historyFrom->format('d.m.Y').' im erhaltenen Bestand belegt.';
        }
        if ($contactDatedCount > 0) {
            $notes[] = 'Historische Kontakte erscheinen am gespeicherten Markierungsdatum. Frühere erneute Markierungen konnten diesen Zeitpunkt ersetzen; er belegt deshalb nicht immer den ersten Kontakt.';
        }
        if ($history['hasUndated']) {
            $notes[] = 'Historische Kennzeichnungen sind erhalten, ihre Bewertungsdaten sind unbekannt. Die Gruppen können sich überschneiden und werden nicht summiert oder in die datierten Bewertungskurven übernommen.';
        }
        foreach ($series as &$row) {
            unset($row['_from'], $row['_until']);
        }
        unset($row);
        return [
            'period' => [
                'kind' => $period->kind, 'anchor' => $period->anchor->format('Y-m-d'),
                'from' => $from, 'to' => $to, 'label' => $period->kind === 'all' ? 'Gesamter Zeitraum' : $period->from->format('d.m.Y').' – '.$period->until->modify('-1 day')->format('d.m.Y'),
                'granularity' => $granularity, 'requestedGranularity' => $period->granularity, 'bucketMonths' => $bucketMonths,
                'timezone' => 'Europe/Berlin', 'previousUrl' => $this->navigationUrl($period, false, $filters), 'nextUrl' => $this->navigationUrl($period, true, $filters),
                'ongoing' => $period->ongoing(), 'comparisonLabel' => $comparison === null ? null : $this->comparisonLabel($period, $comparison),
            ],
            'filters' => $filters, 'tldOptions' => $tldOptions,
            'kpis' => $kpis, 'series' => $series, 'calendarYear' => $calendarYear, 'calendar' => array_values($calendar),
            'tlds' => ['cases' => $this->tldSegments($tldCases, 'cases', $from, $to), 'hosts' => $this->tldSegments(array_map('count', $tldHosts), 'hosts', $from, $to), 'caseTotal' => $counts['reported'], 'hostTotal' => count($hosts)],
            'snapshot' => $snapshot, 'snapshotCounts' => $snapshotCounts, 'aging' => $aging, 'totals' => $totals, 'coverage' => $coverage, 'history' => $history, 'notes' => $notes,
        ];
    }

    private function filters(array $query): array
    {
        foreach (['tld', 'heatmapMetric', 'tldMeasure'] as $field) {
            if (array_key_exists($field, $query) && !is_string($query[$field])) {
                throw new \InvalidArgumentException('Statistikfilter müssen einzelne Textwerte sein.');
            }
        }
        $filters = ['tld' => $query['tld'] ?? '', 'heatmapMetric' => $query['heatmapMetric'] ?? 'reported', 'tldMeasure' => $query['tldMeasure'] ?? 'cases'];
        // Keep old dashboard bookmarks usable without reinterpreting stored data.
        $filters['heatmapMetric'] = match ($filters['heatmapMetric']) {
            'sent' => 'contacted',
            'confirmed' => 'reported',
            default => $filters['heatmapMetric'],
        };
        new FindingReadFilter(scope: 'all', tld: $filters['tld']);
        if (!isset(self::ACTIVITY_METRICS[$filters['heatmapMetric']]) || !in_array($filters['tldMeasure'], ['cases', 'hosts'], true)) {
            throw new \InvalidArgumentException('Unbekannte Statistikdarstellung.');
        }

        return $filters;
    }

    private function within(\DateTimeImmutable $date, \DateTimeImmutable $from, \DateTimeImmutable $until): bool
    {
        return $date >= $from && $date < $until;
    }

    private function kpi(string $label, int $count, ?int $comparisonCount, ?int $previousCount, ?string $url): array
    {
        $delta = $comparisonCount === null || $previousCount === null ? null : $comparisonCount - $previousCount;
        return ['label' => $label, 'count' => $count, 'comparisonCount' => $comparisonCount, 'previousCount' => $previousCount, 'delta' => $delta, 'deltaPercent' => $previousCount === null || $previousCount === 0 || $delta === null ? null : round(100 * $delta / $previousCount, 1), 'url' => $url];
    }

    private function eventUrl(string $metric, string $from, string $to, string $tld = ''): string
    {
        return $this->listUrl(['scope' => 'all', 'event' => $metric, 'from' => $from, 'to' => $to], $tld);
    }

    private function eventUrls(string $from, string $to, string $tld): array
    {
        $urls = [];
        foreach (self::ACTIVITY_METRICS as $metric => $_) {
            $urls[$metric] = $this->eventUrl($metric, $from, $to, $tld);
        }
        return $urls;
    }

    private function listUrl(array $query, string $tld): string
    {
        if ($tld !== '') {
            $query['tld'] = $tld;
        }
        return '/findings?'.http_build_query($query);
    }

    private function navigationUrl(StatisticsPeriod $period, bool $next, array $filters): ?string
    {
        $url = $period->navigationUrl($next);
        return $url === null ? null : $url.'&'.http_build_query($filters);
    }

    private function tldLabel(string $key): string
    {
        return match ($key) { 'ip' => 'IP-Adressen', 'local' => 'Lokale Hosts', default => $key };
    }

    private function tldSegments(array $counts, string $measure, string $from, string $to): array
    {
        $special = [];
        foreach (['ip', 'local'] as $key) {
            if (isset($counts[$key])) {
                $special[$key] = $counts[$key];
                unset($counts[$key]);
            }
        }
        uksort($counts, static fn (string $a, string $b): int => ($counts[$b] <=> $counts[$a]) ?: strcmp($a, $b));
        $top = array_slice($counts, 0, 5, true);
        $other = array_sum(array_slice($counts, 5, null, true));
        $segments = [];
        foreach ($top + $special as $key => $count) {
            $segments[] = ['key' => $key, 'label' => $this->tldLabel($key), 'count' => $count, 'url' => $measure === 'cases' ? $this->eventUrl('reported', $from, $to, $key) : null];
        }
        if ($other > 0) {
            $segments[] = ['key' => 'other', 'label' => 'Sonstige', 'count' => $other, 'url' => null];
        }
        return $segments;
    }

    private function buckets(StatisticsPeriod $period, string $tld): array
    {
        $days = (int) $period->from->diff($period->until)->days;
        $granularity = $period->granularity;
        if ($granularity === 'day' && $days > self::MAX_BUCKETS) {
            $granularity = 'week';
        }
        if ($granularity === 'week' && $days / 7 + 1 > self::MAX_BUCKETS) {
            $granularity = 'month';
        }
        $monthSpan = ((int) $period->until->format('Y') - (int) $period->from->format('Y')) * 12 + (int) $period->until->format('n') - (int) $period->from->format('n') + 1;
        $bucketMonths = $granularity === 'month' ? max(1, (int) ceil($monthSpan / self::MAX_BUCKETS)) : 1;
        $rows = [];
        for ($cursor = $period->from; $cursor < $period->until; $cursor = $until) {
            $until = match ($granularity) {
                'day' => $cursor->modify('+1 day'),
                'week' => $cursor->modify('+'.(8 - (int) $cursor->format('N')).' days'),
                'month' => $cursor->modify('first day of this month')->modify('+'.$bucketMonths.' months'),
            };
            $until = min($until, $period->until);
            $fromDate = $cursor->format('Y-m-d');
            $toDate = $until->modify('-1 day')->format('Y-m-d');
            $label = $granularity === 'day' ? $cursor->format('d.m.') : $cursor->format('d.m.').' – '.$until->modify('-1 day')->format('d.m.Y');
            $rows[] = ['date' => $fromDate, 'label' => $label, 'from' => $fromDate, 'to' => $toDate, '_from' => $cursor->getTimestamp(), '_until' => $until->getTimestamp()] + array_fill_keys(array_keys(self::ACTIVITY_METRICS), 0) + ['urls' => $this->eventUrls($fromDate, $toDate, $tld)];
        }
        return [$rows, $granularity, $bucketMonths];
    }

    private function bucketIndex(array $rows, int $timestamp): ?int
    {
        $low = 0;
        $high = count($rows) - 1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($timestamp < $rows[$mid]['_from']) {
                $high = $mid - 1;
            } elseif ($timestamp >= $rows[$mid]['_until']) {
                $low = $mid + 1;
            } else {
                return $mid;
            }
        }
        return null;
    }

    private function comparisonLabel(StatisticsPeriod $period, array $comparison): string
    {
        $format = $period->ongoing() ? 'd.m.Y H:i' : 'd.m.Y';
        return 'Vergleich: '.$comparison[0]->format($format).' bis '.$comparison[1]->format($format).' mit '.$comparison[2]->format($format).' bis '.$comparison[3]->format($format).($period->ongoing() ? ' (gleich lange Zeitspannen)' : ' (vorheriger Zeitraum)');
    }
}
