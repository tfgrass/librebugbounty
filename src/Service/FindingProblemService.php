<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/** One read-only row per case, independently of its number of historical jobs. */
final class FindingProblemService
{
    public const KINDS = ['all', 'screenshot', 'missing', 'technical'];

    public function __construct(
        private readonly Connection $connection,
        private readonly StoredImageInspector $images,
        private readonly SettingsService $settings,
    ) {
    }

    private function latestJoins(): string
    {
        return ' LEFT JOIN screenshot_job j ON j.id = (SELECT sj.id FROM screenshot_job sj WHERE sj.finding_id = f.id ORDER BY sj.requested_at DESC, sj.rowid DESC LIMIT 1)'
            .' LEFT JOIN retest_run r ON r.id = (SELECT rr.id FROM retest_run rr WHERE rr.finding_id = f.id ORDER BY COALESCE(rr.finished_at, rr.started_at) DESC, rr.rowid DESC LIMIT 1)';
    }

    /** Metadata only: no filesystem scan, browser request or ORM flush on Settings. */
    public function currentCounts(): array
    {
        $row = $this->connection->fetchAssociative("SELECT COALESCE(SUM(CASE WHEN j.status = 'failed' THEN 1 ELSE 0 END), 0) AS screenshot, COALESCE(SUM(CASE WHEN r.result = 'error' THEN 1 ELSE 0 END), 0) AS technical FROM finding f".$this->latestJoins());
        return ['screenshot' => (int) $row['screenshot'], 'technical' => (int) $row['technical']];
    }

    /** @param array<string, mixed> $query @return array{kind: string, q: string, page: int, pageSize: int} */
    public function parse(array $query): array
    {
        foreach (['kind', 'q', 'page', 'pageSize'] as $key) {
            if (array_key_exists($key, $query) && !is_string($query[$key])) {
                throw new \InvalidArgumentException('Filterangaben müssen einzelne Textwerte sein.');
            }
        }
        $kind = trim($query['kind'] ?? 'all');
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $size = $query['pageSize'] ?? (string) $this->settings->getInventoryPageSize();
        if (!in_array($kind, self::KINDS, true) || $page === false || !in_array($size, ['10', '25', '50', '100'], true)) {
            throw new \InvalidArgumentException('Ungültiger Fehlerfilter oder ungültige Seite.');
        }
        return ['kind' => $kind, 'q' => trim($query['q'] ?? ''), 'page' => $page, 'pageSize' => (int) $size];
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    public function get(array $query): array
    {
        $filter = $this->parse($query);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT f.id, f.url, f.title, f.manual_assessment, f.status, d.hostname, j.status AS job_status, j.error_message AS job_error, COALESCE(j.finished_at, j.started_at, j.requested_at) AS job_at, r.result AS run_result, r.error_message AS run_error, COALESCE(r.finished_at, r.started_at) AS run_at FROM finding f INNER JOIN domain d ON d.id = f.domain_id'.$this->latestJoins()
        );
        $byId = [];
        foreach ($rows as $row) {
            $row['problems'] = [];
            if ($row['job_status'] === 'failed') {
                $row['problems']['screenshot'] = ['message' => $row['job_error'], 'at' => $row['job_at']];
            }
            if ($row['run_result'] === 'error') {
                $row['problems']['technical'] = ['message' => $row['run_error'], 'at' => $row['run_at']];
            }
            $byId[$row['id']] = $row;
        }
        // Only this explicitly opened diagnostic page inspects stored image files.
        // Keep the newest affected evidence as the example, not one row per file.
        foreach ($this->connection->executeQuery("SELECT id, finding_id, file_path, created_at FROM evidence WHERE kind = 'screenshot' ORDER BY created_at DESC, rowid DESC")->iterateAssociative() as $evidence) {
            if (!isset($byId[$evidence['finding_id']])) continue;
            $problem = $this->images->problem($evidence['file_path']);
            if ($problem !== null) {
                $byId[$evidence['finding_id']]['problems']['missing'] ??= ['message' => null, 'at' => $evidence['created_at'], 'reason' => $problem, 'evidenceId' => $evidence['id'], 'count' => 0];
                ++$byId[$evidence['finding_id']]['problems']['missing']['count'];
            }
        }
        $counts = array_fill_keys(self::KINDS, 0);
        $selected = [];
        foreach ($byId as $row) {
            if ($row['problems'] === []) continue;
            ++$counts['all'];
            foreach (array_keys($row['problems']) as $kind) ++$counts[$kind];
            if ($filter['kind'] !== 'all' && !isset($row['problems'][$filter['kind']])) continue;
            if ($filter['q'] !== '' && !str_contains(mb_strtolower($row['hostname'].' '.$row['url'].' '.$row['title']), mb_strtolower($filter['q']))) continue;
            $row['latestAt'] = max(array_column($row['problems'], 'at'));
            $selected[] = $row;
        }
        usort($selected, static fn (array $a, array $b): int => [$b['latestAt'], $b['id']] <=> [$a['latestAt'], $a['id']]);
        $total = count($selected);
        $pages = max(1, (int) ceil($total / $filter['pageSize']));
        $filter['page'] = min($filter['page'], $pages);
        return $filter + ['rows' => array_slice($selected, ($filter['page'] - 1) * $filter['pageSize'], $filter['pageSize']), 'counts' => $counts, 'total' => $total, 'pages' => $pages, 'checkedAt' => new \DateTimeImmutable()];
    }
}
