<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Per-worker liveness, written independently of the ORM unit of work. */
final class WorkerHeartbeatService
{
    public const RECHECK = 'recheck';
    public const SCREENSHOT = 'screenshot';
    private const MIN_TOUCH_SECONDS = 15;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function touch(string $kind, ?\DateTimeImmutable $at = null, string $workerId = 'default'): void
    {
        $this->write($kind, $workerId, $at ?? new \DateTimeImmutable(), false);
    }

    public function touchIfDue(string $kind, ?\DateTimeImmutable $at = null, string $workerId = 'default'): bool
    {
        return $this->write($kind, $workerId, $at ?? new \DateTimeImmutable(), true);
    }

    public function lastSeen(string $kind, string $workerId = 'default'): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT value FROM setting WHERE id = ?', [$this->key($kind, $workerId)]);
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    public function ageSeconds(string $kind, ?\DateTimeImmutable $now = null, string $workerId = 'default'): ?int
    {
        $seen = $this->lastSeen($kind, $workerId);
        return $seen === null ? null : max(0, ($now ?? new \DateTimeImmutable())->getTimestamp() - $seen->getTimestamp());
    }

    private function write(string $kind, string $workerId, \DateTimeImmutable $at, bool $rateLimited): bool
    {
        $at = $at->setTimezone(new \DateTimeZone('UTC'));
        // Atomic upsert also covers simultaneous first starts. No ORM flush,
        // cached Setting entity, or unrelated pending changes are involved.
        $sql = 'INSERT INTO setting (id, value, updated_at) VALUES (:id, :value, :updated)'
            .' ON CONFLICT (id) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at';
        $parameters = ['id' => $this->key($kind, $workerId), 'value' => $at->format(DATE_ATOM), 'updated' => $at->format('Y-m-d H:i:s')];
        if ($rateLimited) {
            $sql .= ' WHERE setting.value IS NULL OR setting.value <= :cutoff';
            $parameters['cutoff'] = $at->modify('-'.self::MIN_TOUCH_SECONDS.' seconds')->format(DATE_ATOM);
        }

        return $this->connection->executeStatement($sql, $parameters) > 0;
    }

    private function key(string $kind, string $workerId): string
    {
        if (!in_array($kind, [self::RECHECK, self::SCREENSHOT], true)
            || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $workerId) !== 1) {
            throw new \InvalidArgumentException('Invalid heartbeat worker identity.');
        }

        return 'worker.heartbeat.'.$kind.'.'.$workerId;
    }
}
