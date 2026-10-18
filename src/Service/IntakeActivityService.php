<?php

namespace App\Service;

use App\Dto\FindingCreateResult;
use App\Entity\Finding;
use App\Repository\FindingRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** Small browser-intake ledger; polling, review and imports never touch it. */
final class IntakeActivityService
{
    public const IDLE_SECONDS = 1800;
    public const MINUTES = [5, 10, 15, 30, 60, 120];
    private const SCOPE_KEY = 'librebugbounty.intake.browser';

    public function __construct(
        private readonly Connection $connection,
        private readonly FindingRepository $findings,
        private readonly IntakeStatusService $status,
    ) {
    }

    public function browserId(Request $request, bool $create = false): ?string
    {
        if (!$request->hasSession()) {
            if ($create) { throw new \RuntimeException('Browser intake requires a session.'); }
            return null;
        }
        $scope = $request->getSession()->get(self::SCOPE_KEY);
        if (is_string($scope) && preg_match('/^[a-f0-9]{64}$/D', $scope) === 1) {
            return $scope;
        }
        if (!$create) { return null; }
        $scope = bin2hex(random_bytes(32));
        $request->getSession()->set(self::SCOPE_KEY, $scope);
        return $scope;
    }

    /**
     * The finding, initial screenshot job and contribution commit together.
     * FindingService invokes the recorder under its queue lock and transaction.
     *
     * @param callable(callable(FindingCreateResult): void): FindingCreateResult $create
     * @return array{result: FindingCreateResult, sessionId: string, generation: int}
     */
    public function capture(string $browserId, callable $create, ?\DateTimeImmutable $now = null): array
    {
        $fixedTimestamp = $now?->getTimestamp();
        $receipt = null;
        $result = $create(function (FindingCreateResult $result) use ($browserId, $fixedTimestamp, &$receipt): void {
            if (!$this->connection->isTransactionActive()) {
                throw new \LogicException('Intake attribution must share the finding transaction.');
            }
            $timestamp = $fixedTimestamp ?? time();
            $this->connection->executeStatement(
                'INSERT INTO intake_browser (id, current_session_id, generation) VALUES (?, NULL, 0) ON CONFLICT (id) DO NOTHING',
                [$browserId],
            );
            $browser = $this->browser($browserId);
            $session = $this->current($browser);
            if ($session === null || $session['ended_at'] !== null || $this->idle($session, $timestamp)) {
                $previous = null;
                if ($session !== null && $session['ended_at'] === null) {
                    $previous = $session['id'];
                    $this->connection->executeStatement(
                        'UPDATE intake_session SET ended_at = last_activity_at, end_reason = ? WHERE id = ?',
                        ['idle', $session['id']],
                    );
                }
                $sessionId = Uuid::v7()->toRfc4122();
                $this->connection->insert('intake_session', [
                    'id' => $sessionId, 'browser_id' => $browserId,
                    'started_at' => $timestamp, 'last_activity_at' => $timestamp,
                    'ended_at' => null, 'end_reason' => null, 'continuation_of' => $previous,
                ]);
            } else {
                $sessionId = $session['id'];
                $this->connection->executeStatement('UPDATE intake_session SET last_activity_at = ? WHERE id = ?', [$timestamp, $sessionId]);
            }
            if ($result->created) {
                $this->connection->insert('intake_session_entry', [
                    'finding_id' => $result->finding->getId(), 'session_id' => $sessionId,
                    'browser_id' => $browserId, 'recorded_at' => $timestamp,
                ]);
            }
            $this->connection->executeStatement(
                'UPDATE intake_browser SET current_session_id = ?, generation = generation + 1 WHERE id = ?',
                [$sessionId, $browserId],
            );
            $receipt = ['sessionId' => $sessionId, 'generation' => (int) $browser['generation'] + 1];
        });
        if ($receipt === null) { throw new \LogicException('Intake recorder was not called.'); }
        return ['result' => $result, ...$receipt];
    }

    public function change(string $browserId, string $action, string $sessionId, ?string $expectedActiveSessionId, int $expectedGeneration, ?\DateTimeImmutable $now = null): void
    {
        $timestamp = ($now ?? new \DateTimeImmutable())->getTimestamp();
        $this->connection->transactional(function () use ($browserId, $action, $sessionId, $expectedActiveSessionId, $expectedGeneration, $timestamp): void {
            // Acquire the SQLite writer lock before checking the tab's binding.
            $this->connection->executeStatement('UPDATE intake_browser SET generation = generation WHERE id = ?', [$browserId]);
            $browser = $this->browser($browserId);
            if ($browser === null || (int) $browser['generation'] !== $expectedGeneration) { throw new IntakeActivityConflict(); }
            [$active, $resumable] = $this->sessions($browser, $timestamp);
            if ($action === 'end') {
                if ($active === null || $active['id'] !== $sessionId) { throw new IntakeActivityConflict(); }
                $this->connection->executeStatement('UPDATE intake_session SET ended_at = ?, end_reason = ? WHERE id = ?', [$timestamp, 'manual', $sessionId]);
            } elseif ($action === 'resume') {
                if (($active['id'] ?? null) !== $expectedActiveSessionId || $resumable === null || $resumable['id'] !== $sessionId) {
                    throw new IntakeActivityConflict();
                }
                $current = $this->current($browser);
                if ($current !== null && $current['id'] !== $sessionId) {
                    $this->connection->executeStatement('UPDATE intake_session_entry SET session_id = ? WHERE session_id = ? AND browser_id = ?', [$sessionId, $current['id'], $browserId]);
                    $this->connection->delete('intake_session', ['id' => $current['id'], 'browser_id' => $browserId]);
                }
                $this->connection->executeStatement('UPDATE intake_session SET ended_at = NULL, end_reason = NULL, last_activity_at = ? WHERE id = ? AND browser_id = ?', [$timestamp, $sessionId, $browserId]);
                $this->connection->executeStatement('UPDATE intake_browser SET current_session_id = ? WHERE id = ?', [$sessionId, $browserId]);
            } else {
                throw new \InvalidArgumentException('Unknown intake action.');
            }
            $this->connection->executeStatement('UPDATE intake_browser SET generation = generation + 1 WHERE id = ?', [$browserId]);
        });
    }

    public function snapshot(?string $browserId, string $period = 'session', int $minutes = 15, string $history = 'session', ?\DateTimeImmutable $now = null): array
    {
        if (!in_array($period, ['session', 'today', 'window'], true) || !in_array($minutes, self::MINUTES, true) || !in_array($history, ['session', 'all'], true)) {
            throw new \InvalidArgumentException('Invalid intake activity selection.');
        }
        $timestamp = ($now ?? new \DateTimeImmutable())->getTimestamp();
        // A read transaction keeps generation, counters and rows coherent;
        // nothing here creates a browser/session or renews its activity clock.
        return $this->connection->transactional(function () use ($browserId, $period, $minutes, $history, $timestamp): array {
            $browser = $browserId !== null ? $this->browser($browserId) : null;
            [$active, $resumable] = $this->sessions($browser, $timestamp);
            $display = $active ?? $resumable;
            return [
                'now' => $this->date($timestamp), 'timezone' => 'Europe/Berlin',
                'sessionGeneration' => (int) ($browser['generation'] ?? 0),
                'scopeId' => $browserId,
                'activeSession' => $active !== null ? $this->sessionView($active, $timestamp) : null,
                'resumableSession' => $resumable !== null ? $this->sessionView($resumable, $timestamp) : null,
                'stats' => $this->stats($browserId, $display, $period, $minutes, $timestamp),
                'history' => ['scope' => $history, 'findings' => $this->history($history, $display), 'limit' => 50],
            ];
        });
    }

    private function browser(string $id): ?array
    {
        return $this->connection->fetchAssociative('SELECT * FROM intake_browser WHERE id = ?', [$id]) ?: null;
    }

    private function current(?array $browser): ?array
    {
        if ($browser === null || $browser['current_session_id'] === null) { return null; }
        return $this->connection->fetchAssociative('SELECT * FROM intake_session WHERE id = ? AND browser_id = ?', [$browser['current_session_id'], $browser['id']]) ?: null;
    }

    /** @return array{?array, ?array} */
    private function sessions(?array $browser, int $timestamp): array
    {
        $current = $this->current($browser);
        if ($current === null) { return [null, null]; }
        if ($current['ended_at'] !== null || $this->idle($current, $timestamp)) { return [null, $current]; }
        $previous = $this->connection->fetchAssociative(
            'SELECT * FROM intake_session WHERE browser_id = ? AND id <> ? ORDER BY started_at DESC, rowid DESC LIMIT 1',
            [$browser['id'], $current['id']],
        ) ?: null;
        return [$current, $previous];
    }

    private function idle(array $session, int $timestamp): bool
    {
        return $timestamp - (int) $session['last_activity_at'] >= self::IDLE_SECONDS;
    }

    private function sessionView(array $session, int $timestamp): array
    {
        $idle = $session['ended_at'] === null && $this->idle($session, $timestamp);
        $end = $session['ended_at'] !== null ? (int) $session['ended_at'] : ($idle ? (int) $session['last_activity_at'] : $timestamp);
        return [
            'id' => $session['id'], 'startedAt' => $this->date((int) $session['started_at']),
            'lastActivityAt' => $this->date((int) $session['last_activity_at']),
            'endedAt' => $session['ended_at'] !== null || $idle ? $this->date($end) : null,
            'count' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM intake_session_entry WHERE session_id = ?', [$session['id']]),
            'durationSeconds' => max(0, $end - (int) $session['started_at']),
            'state' => $idle ? 'idle' : ($session['ended_at'] !== null ? 'ended' : 'active'),
        ];
    }

    private function stats(?string $browserId, ?array $session, string $period, int $minutes, int $timestamp): array
    {
        $unit = $minutes * 60;
        $anchor = $timestamp;
        $where = 'browser_id = ?';
        $parameters = [$browserId ?? ''];
        if ($period === 'session') {
            $view = $session !== null ? $this->sessionView($session, $timestamp) : null;
            $anchor = $view !== null && $view['endedAt'] !== null ? (new \DateTimeImmutable($view['endedAt']))->getTimestamp() : $timestamp;
            $start = $session !== null ? (int) $session['started_at'] : $timestamp;
            $where .= ' AND session_id = ?';
            $parameters[] = $session['id'] ?? '';
        } elseif ($period === 'today') {
            $start = (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Europe/Berlin'))->setTime(0, 0)->getTimestamp();
        } else {
            $start = $timestamp - $unit;
        }
        // Window comparisons always use adjacent equal intervals. Session/day
        // choices clip at their own start rather than silently counting others.
        $clip = $period === 'window' ? 0 : $start;
        $currentStart = max($clip, $anchor - $unit);
        $previousStart = max($clip, $anchor - 2 * $unit);
        if ($period === 'session') {
            $count = $this->count($where, $parameters, $start, $anchor, true);
            $current = $this->count($where, $parameters, $currentStart, $anchor, true);
            $previous = $this->count($where, $parameters, $previousStart, $anchor - $unit, false);
        } else {
            [$count, $current, $previous] = $this->globalCounts($start, $currentStart, $previousStart, $anchor - $unit, $anchor);
        }
        $duration = max(0, $anchor - $start);
        return [
            'period' => $period, 'minutes' => $minutes, 'count' => $count,
            'currentCount' => $current, 'previousCount' => $previous,
            'averagePerWindow' => $duration >= $unit ? round($count * $unit / $duration, 1) : null,
            'durationSeconds' => $duration,
        ];
    }

    private function count(string $where, array $parameters, int $start, int $end, bool $inclusiveEnd): int
    {
        if ($start > $end) { return 0; }
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM intake_session_entry WHERE '.$where.' AND recorded_at >= ? AND recorded_at '.($inclusiveEnd ? '<=' : '<').' ?',
            [...$parameters, $start, $end],
        );
    }

    private function globalCounts(int $start, int $currentStart, int $previousStart, int $previousEnd, int $end): array
    {
        // Bound reads to nearby dates, then use the same source-zone parser as
        // StatisticsService. SQL's UTC interpretation of naive timestamps would
        // otherwise miscount Berlin storage and explicit offsets at DST changes.
        // Group repeated import timestamps and stream one scalar pass for all
        // counters instead of loading findings or scanning the entire history.
        $zone = new \DateTimeZone(date_default_timezone_get());
        $earliest = min($start, $currentStart, $previousStart);
        $from = gmdate('Y-m-d', $earliest - 2 * 86400);
        $until = gmdate('Y-m-d', $end + 2 * 86400);
        $count = $current = $previous = 0;
        foreach ($this->connection->iterateAssociative(
            'SELECT COALESCE(submitted_at, created_at) AS occurred_at, COUNT(*) AS amount FROM finding '
            .'WHERE SUBSTR(COALESCE(submitted_at, created_at), 1, 10) >= ? AND SUBSTR(COALESCE(submitted_at, created_at), 1, 10) <= ? '
            .'GROUP BY COALESCE(submitted_at, created_at)', [$from, $until],
        ) as $row) {
            try { $time = (new \DateTimeImmutable($row['occurred_at'], $zone))->getTimestamp(); }
            catch (\Exception) { continue; }
            $amount = (int) $row['amount'];
            if ($time >= $start && $time <= $end) { $count += $amount; }
            if ($time >= $currentStart && $time <= $end) { $current += $amount; }
            if ($time >= $previousStart && $time < $previousEnd) { $previous += $amount; }
        }
        return [$count, $current, $previous];
    }

    private function history(string $scope, ?array $session): array
    {
        // Global history follows the inventory's persisted-date ordering;
        // ordinary Doctrine timestamps share one storage format/source zone.
        $rows = $scope === 'all'
            ? $this->connection->fetchAllAssociative('SELECT id, COALESCE(submitted_at, created_at) AS recorded_at FROM finding ORDER BY COALESCE(submitted_at, created_at) DESC, rowid DESC LIMIT 50')
            : ($session !== null ? $this->connection->fetchAllAssociative('SELECT f.id, e.recorded_at FROM intake_session_entry e INNER JOIN finding f ON f.id = e.finding_id WHERE e.session_id = ? ORDER BY e.recorded_at DESC, e.rowid DESC LIMIT 50', [$session['id']]) : []);
        $result = [];
        foreach ($rows as $row) {
            $finding = $this->findings->find($row['id']);
            if (!$finding instanceof Finding) { continue; }
            $date = is_numeric($row['recorded_at']) ? (int) $row['recorded_at'] : (new \DateTimeImmutable($row['recorded_at']))->getTimestamp();
            $result[] = ['id' => $finding->getId(), 'url' => $finding->getUrl(), 'detailUrl' => '/findings/'.$finding->getId(), 'createdAt' => $this->date($date), 'status' => $this->status->status($finding)];
        }
        return $result;
    }

    private function date(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
