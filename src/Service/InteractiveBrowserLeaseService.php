<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Global SQLite lease authority. Owner/channel authorization and worker RPCs
 * belong to the caller; this service only serializes committed lease changes.
 *
 * @phpstan-type Lease array{generation: int, state: string, finding_id: ?string,
 *     owner_hash: ?string, channel_id: ?string, worker_id: ?string, binding: array,
 *     created_at: ?int, expires_at: ?int}
 */
final class InteractiveBrowserLeaseService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $ownerKey,
    ) {
    }

    public function ownerHash(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, $this->ownerKey);
    }

    /** @return Lease */
    public function current(): array
    {
        $row = $this->connection->fetchAssociative('SELECT generation, state, finding_id, owner_hash, channel_id, worker_id, binding, created_at, expires_at FROM interactive_browser_lease WHERE singleton_id = 1');
        if ($row === false) {
            return $this->available(0);
        }
        $row['generation'] = (int) $row['generation'];
        $row['binding'] = json_decode($row['binding'], true, 512, JSON_THROW_ON_ERROR);
        $row['created_at'] = $row['created_at'] === null ? null : (int) $row['created_at'];
        $row['expires_at'] = $row['expires_at'] === null ? null : (int) $row['expires_at'];

        return $row;
    }

    /** @return Lease */
    public function acquire(array $binding, string $owner, string $channel, ?int $expectedRevision = null): array
    {
        foreach (['finding_id', 'start_url', 'start_url_hash'] as $key) {
            if (!is_string($binding[$key] ?? null) || $binding[$key] === '') {
                throw new \InvalidArgumentException('Interactive browser binding requires '.$key.'.');
            }
        }
        $this->assertIdentity($owner, $channel);

        return $this->locked(function () use ($binding, $owner, $channel, $expectedRevision): array {
            $row = $this->current();
            $this->assertRevision($row, $expectedRevision);
            $this->assertState($row, ['available']);
            $next = array_replace($this->available($this->nextGeneration($row)), [
                'state' => 'activating', 'finding_id' => $binding['finding_id'],
                'owner_hash' => $owner, 'channel_id' => $channel, 'binding' => $binding,
            ]);

            return $this->write($row, $next);
        });
    }

    /** @return Lease */
    public function attach(int $generation, string $workerId, int $createdAt, int $expiresAt, array $workerContext = []): array
    {
        if ($workerId === '' || $createdAt < 0 || $expiresAt <= $createdAt) {
            throw new \InvalidArgumentException('Interactive worker identity and absolute lifetime are required.');
        }

        return $this->locked(function () use ($generation, $workerId, $createdAt, $expiresAt, $workerContext): array {
            $row = $this->current();
            $this->assertRevision($row, $generation);
            $this->assertState($row, ['activating']);
            foreach (['finding_id', 'start_url', 'start_url_hash'] as $key) {
                if (array_key_exists($key, $workerContext) && $workerContext[$key] !== $row['binding'][$key]) {
                    throw new \RuntimeException('Interactive finding binding changed.', 409);
                }
            }

            return $this->write($row, array_replace($row, [
                'worker_id' => $workerId, 'created_at' => $createdAt, 'expires_at' => $expiresAt,
                'binding' => array_replace($row['binding'], $workerContext),
            ]));
        });
    }

    /** @return Lease */
    public function activate(int $generation): array
    {
        return $this->locked(function () use ($generation): array {
            $row = $this->current();
            $this->assertRevision($row, $generation);
            $this->assertState($row, ['activating']);
            if ($row['worker_id'] === null) {
                throw new \RuntimeException('Interactive worker has not been attached.', 409);
            }

            return $this->write($row, array_replace($row, ['state' => 'active']));
        });
    }

    /** @return Lease */
    public function takeover(string $findingId, string $owner, string $channel, int $expectedRevision): array
    {
        $this->assertIdentity($owner, $channel);

        return $this->locked(function () use ($findingId, $owner, $channel, $expectedRevision): array {
            $row = $this->current();
            $this->assertRevision($row, $expectedRevision);
            $this->assertState($row, ['active']);
            $this->assertFinding($row, $findingId);

            return $this->write($row, array_replace($row, [
                'generation' => $this->nextGeneration($row), 'state' => 'activating',
                'owner_hash' => $owner, 'channel_id' => $channel,
            ]));
        });
    }

    /** @return Lease */
    public function beginClosing(int $generation): array
    {
        return $this->locked(function () use ($generation): array {
            $row = $this->current();
            $this->assertRevision($row, $generation);
            $this->assertState($row, ['active', 'activating']);

            return $this->write($row, array_replace($row, ['state' => 'closing']));
        });
    }

    public function clear(int $generation): void
    {
        $this->locked(function () use ($generation): void {
            $row = $this->current();
            $this->assertRevision($row, $generation);
            $this->assertState($row, ['active', 'activating', 'closing']);
            $this->write($row, $this->available($this->nextGeneration($row)));
        });
    }

    /**
     * The callback must use the supplied Connection (including any separate ORM
     * unit of work), and must not commit the outer transaction or do RPC/file IO.
     * ORM flushes may use nested savepoints; only this method commits the lease.
     *
     * @param callable(Connection, array): mixed $fn
     */
    public function withLeaseLock(string $findingId, int $generation, callable $fn): mixed
    {
        return $this->locked(function () use ($findingId, $generation, $fn): mixed {
            $row = $this->current();
            $this->assertRevision($row, $generation);
            $this->assertState($row, ['active']);
            $this->assertFinding($row, $findingId);
            $result = $fn($this->connection, $row);
            $this->write($row, array_replace($row, ['state' => 'closing']));

            return $result;
        });
    }

    private function locked(callable $fn): mixed
    {
        if ($this->connection->isTransactionActive() || !$this->connection->isAutoCommit()) {
            throw new \LogicException('Interactive browser lease must own its transaction.');
        }
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            throw new \LogicException('Interactive browser lease requires SQLite.');
        }

        $committing = false;
        try {
            // DBAL/PDO must own the real transaction too. A first WRITE (even
            // INSERT OR IGNORE of the existing singleton) obtains SQLite's
            // reserved writer lock BEFORE any read, like BEGIN IMMEDIATE.
            // Do not replace PDO's transaction using raw ROLLBACK/BEGIN: its
            // internal transaction flag can survive a busy/error path.
            $this->connection->beginTransaction();
            $this->connection->executeStatement("INSERT OR IGNORE INTO interactive_browser_lease (singleton_id, generation, state, binding) VALUES (1, 0, 'available', '[]')");
            $result = $fn();
            if ($this->connection->getTransactionNestingLevel() !== 1) {
                throw new \LogicException('Interactive browser callback changed transaction ownership.');
            }
            $committing = true;
            $this->connection->commit();
            $committing = false;

            return $result;
        } catch (\Throwable $error) {
            // Unwind any unfinished callback savepoints as well as our lock.
            $rolledBack = false;
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
                $rolledBack = true;
            }
            if ($committing && !$rolledBack) {
                // DBAL decrements nesting even if COMMIT failed with BUSY.
                // Roll back through PDO (not raw SQL) to reset BOTH the native
                // driver's flag and SQLite before this connection is reused.
                $native = $this->connection->getNativeConnection();
                if ($native instanceof \PDO) {
                    if ($native->inTransaction()) { $native->rollBack(); }
                } else {
                    $this->connection->executeStatement('ROLLBACK');
                }
            }
            if ($error instanceof LockWaitTimeoutException) {
                throw new \RuntimeException('Interactive browser lease is busy.', 409, $error);
            }
            throw $error;
        }
    }

    private function write(array $before, array $after): array
    {
        $changed = $this->connection->executeStatement(
            'UPDATE interactive_browser_lease SET generation = ?, state = ?, finding_id = ?, owner_hash = ?, channel_id = ?, worker_id = ?, binding = ?, created_at = ?, expires_at = ? WHERE singleton_id = 1 AND generation = ? AND state = ?',
            [$after['generation'], $after['state'], $after['finding_id'], $after['owner_hash'], $after['channel_id'], $after['worker_id'],
                json_encode($after['binding'], JSON_THROW_ON_ERROR), $after['created_at'], $after['expires_at'], $before['generation'], $before['state']],
        );
        if ($changed !== 1) {
            throw new \RuntimeException('Interactive browser lease changed.', 409);
        }

        return $after;
    }

    private function assertRevision(array $row, ?int $generation): void
    {
        if ($generation !== null && $row['generation'] !== $generation) {
            throw new \RuntimeException('Interactive browser lease revision changed.', 409);
        }
    }

    private function assertState(array $row, array $states): void
    {
        if (!in_array($row['state'], $states, true)) {
            throw new \RuntimeException('Interactive browser lease is busy or no longer in the required state.', 409);
        }
    }

    private function assertFinding(array $row, string $findingId): void
    {
        if ($row['finding_id'] !== $findingId) {
            throw new \RuntimeException('Interactive browser lease belongs to another finding.', 409);
        }
    }

    private function assertIdentity(string $owner, string $channel): void
    {
        if ($owner === '' || $channel === '') {
            throw new \InvalidArgumentException('Interactive browser owner and channel are required.');
        }
    }

    private function nextGeneration(array $row): int
    {
        if ($row['generation'] === PHP_INT_MAX) {
            throw new \RuntimeException('Interactive browser lease generation exhausted.', 409);
        }

        return $row['generation'] + 1;
    }

    private function available(int $generation): array
    {
        return ['generation' => $generation, 'state' => 'available', 'finding_id' => null,
            'owner_hash' => null, 'channel_id' => null, 'worker_id' => null, 'binding' => [],
            'created_at' => null, 'expires_at' => null];
    }
}
