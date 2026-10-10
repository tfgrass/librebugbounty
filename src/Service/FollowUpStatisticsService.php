<?php
namespace App\Service;

use App\Repository\StatisticsRepository;
use App\Value\HostnameTld;
use App\Value\PursuitStatus;
use Doctrine\DBAL\Connection;

/** Current retained cohort, not an invented report/response funnel. */
final class FollowUpStatisticsService
{
    public function __construct(private readonly StatisticsRepository $statistics, private readonly Connection $connection) {}
    public function get(string $tld = ''): array
    {
        $states = FindingWorkPolicy::available($this->connection) ? array_column($this->connection->fetchAllAssociative('SELECT finding_id, pursuit, reason FROM finding_follow_up'), null, 'finding_id') : [];
        $durations = [];
        $coverage = ['cohort' => 0, 'missingConfirmation' => 0, 'missingContact' => 0, 'invalidOrder' => 0];
        $reasons = array_fill_keys(array_keys(PursuitStatus::REASONS), 0);
        foreach ($this->statistics->caseFacts() as $fact) {
            if ($tld !== '' && HostnameTld::key($fact['hostname']) !== $tld) continue;
            $state = $states[$fact['id']] ?? [];
            if (($state['pursuit'] ?? '') === 'closed' && isset($reasons[$state['reason'] ?? ''])) ++$reasons[$state['reason']];
            if ($fact['confirmed_at'] === null && $fact['manual_assessment'] !== 'confirmed') continue;
            ++$coverage['cohort'];
            if ($fact['confirmed_at'] === null) { ++$coverage['missingConfirmation']; continue; }
            if ($fact['contacted_at'] === null) { ++$coverage['missingContact']; continue; }
            $seconds = (new \DateTimeImmutable($fact['contacted_at']))->getTimestamp() - (new \DateTimeImmutable($fact['confirmed_at']))->getTimestamp();
            if ($seconds < 0) { ++$coverage['invalidOrder']; continue; }
            $durations[] = $seconds / 86400;
        }
        sort($durations, SORT_NUMERIC);
        $n = count($durations);
        $median = $n === 0 ? null : ($durations[intdiv($n - 1, 2)] + $durations[intdiv($n, 2)]) / 2;
        $rows = [];
        foreach ($reasons as $reason => $count) $rows[] = ['reason' => $reason, 'label' => PursuitStatus::REASONS[$reason], 'count' => $count, 'url' => '/findings?'.http_build_query(['scope' => 'all', 'pursuit' => 'closed', 'closure_reason' => $reason] + ($tld === '' ? [] : ['tld' => $tld]), '', '&', PHP_QUERY_RFC3986)];
        return ['medianDays' => $median, 'sampleSize' => $n, 'coverage' => $coverage, 'reasons' => $rows, 'closed' => array_sum($reasons)];
    }
}
