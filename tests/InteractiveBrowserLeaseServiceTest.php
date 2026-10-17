<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Domain;
use App\Service\InteractiveBrowserLeaseService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InteractiveBrowserLeaseServiceTest extends TestCase
{
    private Connection $connection;
    private Connection $otherConnection;
    private InteractiveBrowserLeaseService $leases;
    private InteractiveBrowserLeaseService $otherLeases;
    private string $path;

    protected function setUp(): void
    {
        $path = tempnam(APP_TEST_ROOT, 'interactive-lease-');
        self::assertNotFalse($path);
        $this->path = $path;
        $this->connection = $this->connect();
        $this->otherConnection = $this->connect();
        $this->leases = new InteractiveBrowserLeaseService($this->connection, 'test-owner-key');
        $this->otherLeases = new InteractiveBrowserLeaseService($this->otherConnection, 'test-owner-key');
        $this->migrate();
    }

    protected function tearDown(): void
    {
        $this->otherConnection->close();
        $this->connection->close();
        foreach ([$this->path, $this->path.'-journal', $this->path.'-wal', $this->path.'-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testCurrentIsReadOnlyAndHasExactDefaultShapeEvenWithoutSeed(): void
    {
        $this->connection->executeStatement('DELETE FROM interactive_browser_lease');
        self::assertSame([
            'generation' => 0, 'state' => 'available', 'finding_id' => null,
            'owner_hash' => null, 'channel_id' => null, 'worker_id' => null,
            'binding' => [], 'created_at' => null, 'expires_at' => null,
        ], $this->leases->current());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM interactive_browser_lease'));
        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame(1, $this->leases->acquire($this->binding(), 'owner-a', 'tab-a', 0)['generation']);
    }

    public function testOwnerHashUsesTheApplicationKeyAndNeverPersistsTheRawSessionId(): void
    {
        $sessionId = 'private-cookie-session-id';
        $owner = $this->leases->ownerHash($sessionId);
        self::assertSame(hash_hmac('sha256', $sessionId, 'test-owner-key'), $owner);
        self::assertNotSame($owner, $this->leases->ownerHash('another-session'));
        self::assertNotSame($owner, (new InteractiveBrowserLeaseService($this->connection, 'other-key'))->ownerHash($sessionId));
        $this->leases->acquire($this->binding(), $owner, 'tab-a');
        self::assertSame($owner, $this->otherLeases->current()['owner_hash']);
        self::assertStringNotContainsString($sessionId, json_encode($this->connection->fetchAllAssociative('SELECT * FROM interactive_browser_lease'), JSON_THROW_ON_ERROR));
    }

    public function testLifecyclePersistsEpochAndFencesEveryOldMutationAfterReacquire(): void
    {
        $active = $this->active();
        self::assertSame(1, $active['generation']);
        $closing = $this->leases->beginClosing(1);
        self::assertSame('closing', $closing['state']);
        self::assertSame(1, $closing['generation']);
        $this->leases->clear(1);
        self::assertSame(2, $this->otherLeases->current()['generation']);
        self::assertSame('available', $this->otherLeases->current()['state']);
        foreach (['finding_id', 'owner_hash', 'channel_id', 'worker_id', 'created_at', 'expires_at'] as $key) {
            self::assertNull($this->leases->current()[$key]);
        }
        self::assertSame([], $this->leases->current()['binding']);

        $this->connection->close();
        self::assertSame(2, $this->leases->current()['generation']);
        $next = $this->otherLeases->acquire($this->binding('finding-b'), 'owner-b', 'tab-b', 2);
        self::assertSame(3, $next['generation']);
        foreach ([
            fn () => $this->leases->attach(1, 'old-worker', 100, 1300),
            fn () => $this->leases->activate(1),
            fn () => $this->leases->beginClosing(1),
            fn () => $this->leases->clear(1),
            fn () => $this->leases->takeover('finding-b', 'old-owner', 'old-tab', 1),
        ] as $operation) {
            $this->assertConflict($operation);
            self::assertSame($next, $this->leases->current());
        }
    }

    public function testAttachMergesContextAndTakeoverPreservesWorkerProofAndAbsoluteExpiry(): void
    {
        $context = ['initial_challenge' => ['state' => 'unknown'], 'captureId' => 'proof-original',
            'captureGeneration' => 1, 'captureLocked' => true, 'capturedAt' => 150];
        $before = $this->active($context);
        $taken = $this->otherLeases->takeover('finding-a', 'owner-b', 'tab-b', 1);
        self::assertSame(array_replace($before, ['generation' => 2, 'state' => 'activating', 'owner_hash' => 'owner-b', 'channel_id' => 'tab-b']), $taken);
        self::assertSame(array_replace($this->binding(), $context), $taken['binding']);
        $this->assertConflict(fn () => $this->leases->attach(2, 'worker-a', 100, 1300, ['finding_id' => 'finding-b']));
        $this->otherLeases->activate(2);
        $connected = $this->leases->current();
        self::assertSame('active', $connected['state']);
        self::assertSame(1300, $connected['expires_at']);
        self::assertSame($connected, $this->otherLeases->current());
        self::assertSame($connected, $this->otherLeases->current());
    }

    public function testAttachCanReplaceProvisionalStartTimesWithAuthoritativeWorkerContext(): void
    {
        $this->leases->acquire($this->binding(), 'owner-a', 'tab-a');
        $this->leases->attach(1, 'worker-a', 100, 1300);
        $attached = $this->leases->attach(1, 'worker-a', 102, 1302, ['initial_http_status' => 200]);
        self::assertSame('activating', $attached['state']);
        self::assertSame(102, $attached['created_at']);
        self::assertSame(1302, $attached['expires_at']);
        self::assertSame(200, $attached['binding']['initial_http_status']);
        self::assertSame('active', $this->leases->activate(1)['state']);
    }

    public function testSameCookieDifferentTabRequiresExplicitGenerationChangingTakeover(): void
    {
        $this->active();
        $this->assertConflict(fn () => $this->otherLeases->acquire($this->binding(), 'owner-a', 'tab-b', 1));
        $taken = $this->otherLeases->takeover('finding-a', 'owner-a', 'tab-b', 1);
        self::assertSame(2, $taken['generation']);
        self::assertSame('tab-b', $taken['channel_id']);
        self::assertSame('owner-a', $taken['owner_hash']);
    }

    public function testCompetingAcquireAndStaleFormsCannotOverwriteACommittedWinner(): void
    {
        $winner = $this->leases->acquire($this->binding(), 'owner-a', 'tab-a', 0);
        $this->assertConflict(fn () => $this->otherLeases->acquire($this->binding(), 'owner-b', 'tab-b', 0));
        $this->assertConflict(fn () => $this->otherLeases->acquire($this->binding(), 'owner-b', 'tab-b'));
        self::assertSame($winner, $this->otherLeases->current());
        $this->leases->clear(1);
        $this->assertConflict(fn () => $this->otherLeases->acquire($this->binding(), 'owner-b', 'tab-b', 0));
        self::assertSame(2, $this->leases->current()['generation']);
    }

    public function testCompetingTakeoverCannotUseAStaleRevisionAfterTheWinnerActivates(): void
    {
        $this->active();
        $this->otherLeases->takeover('finding-a', 'owner-b', 'tab-b', 1);
        $this->otherLeases->activate(2);
        $winner = $this->otherLeases->current();
        $this->assertConflict(fn () => $this->leases->takeover('finding-a', 'owner-c', 'tab-c', 1));
        self::assertSame($winner, $this->leases->current());
    }

    public function testDifferentFindingMustCloseAndClearBeforeStarting(): void
    {
        $this->active();
        $this->assertConflict(fn () => $this->otherLeases->takeover('finding-b', 'owner-b', 'tab-b', 1));
        $this->assertConflict(fn () => $this->otherLeases->acquire($this->binding('finding-b'), 'owner-b', 'tab-b', 1));
        $this->leases->beginClosing(1);
        $this->assertConflict(fn () => $this->otherLeases->acquire($this->binding('finding-b'), 'owner-b', 'tab-b', 1));
        $this->leases->clear(1);
        $next = $this->otherLeases->acquire($this->binding('finding-b'), 'owner-b', 'tab-b', 2);
        self::assertSame(3, $next['generation']);
        self::assertSame('finding-b', $next['finding_id']);
    }

    #[DataProvider('leaseStates')]
    public function testClearReconcilesOnlyTheCurrentEpoch(string $state): void
    {
        $this->leases->acquire($this->binding(), 'owner-a', 'tab-a');
        if ($state === 'active') {
            $this->leases->attach(1, 'worker-a', 100, 1300);
            $this->leases->activate(1);
        } elseif ($state === 'closing') {
            $this->leases->beginClosing(1);
        }
        $this->assertConflict(fn () => $this->otherLeases->clear(0));
        $this->leases->clear(1);
        self::assertSame(2, $this->leases->current()['generation']);
        $this->assertConflict(fn () => $this->otherLeases->clear(2));
    }

    public static function leaseStates(): iterable
    {
        yield 'activating' => ['activating'];
        yield 'active' => ['active'];
        yield 'closing' => ['closing'];
    }

    public function testInvalidStateTransitionsLeaveTheLeaseUntouched(): void
    {
        $this->assertConflict(fn () => $this->leases->activate(0));
        $this->assertConflict(fn () => $this->leases->beginClosing(0));
        $activating = $this->leases->acquire($this->binding(), 'owner-a', 'tab-a');
        $this->assertConflict(fn () => $this->leases->activate(1));
        $this->assertConflict(fn () => $this->leases->takeover('finding-a', 'owner-b', 'tab-b', 1));
        self::assertSame($activating, $this->leases->current());
        $this->leases->beginClosing(1);
        $closing = $this->leases->current();
        $this->assertConflict(fn () => $this->leases->attach(1, 'worker-a', 100, 1300));
        $this->assertConflict(fn () => $this->leases->activate(1));
        $this->assertConflict(fn () => $this->leases->beginClosing(1));
        self::assertSame($closing, $this->leases->current());
    }

    public function testWithLeaseLockCommitsEvidenceAndClosingTogetherAndBlocksLateTakeover(): void
    {
        $active = $this->active();
        $this->proofTable();
        $result = $this->leases->withLeaseLock('finding-a', 1, function (Connection $db, array $row) use ($active): object {
            self::assertSame($this->connection, $db);
            self::assertSame($active, $row);
            self::assertTrue($db->isTransactionActive());
            self::assertSame(1, $db->getTransactionNestingLevel());
            $db->insert('lease_test_evidence', ['id' => 'proof']);
            self::assertSame(0, (int) $this->otherConnection->fetchOne('SELECT COUNT(*) FROM lease_test_evidence'));
            self::assertSame('active', $this->otherLeases->current()['state']);
            $this->assertConflict(fn () => $this->otherLeases->takeover('finding-a', 'owner-b', 'tab-b', 1));
            self::assertFalse($this->otherConnection->isTransactionActive());
            self::assertFalse($this->otherConnection->getNativeConnection()->inTransaction());

            return (object) ['saved' => true];
        });
        self::assertTrue($result->saved);
        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame(1, (int) $this->otherConnection->fetchOne('SELECT COUNT(*) FROM lease_test_evidence'));
        self::assertSame('closing', $this->otherLeases->current()['state']);
        $this->assertConflict(fn () => $this->otherLeases->takeover('finding-a', 'owner-b', 'tab-b', 1));
        $this->assertConflict(fn () => $this->otherLeases->acquire($this->binding(), 'owner-b', 'tab-b', 1));
    }

    public function testTakeoverBeforePersistenceRejectsOldSaveWithoutCallingCallback(): void
    {
        $this->active();
        $winner = $this->otherLeases->takeover('finding-a', 'owner-b', 'tab-b', 1);
        $this->assertConflict(fn () => $this->leases->withLeaseLock('finding-a', 1, static function (): void {
            self::fail('A revoked saver must not persist evidence.');
        }));
        self::assertSame($winner, $this->leases->current());
        $this->assertConflict(fn () => $this->leases->beginClosing(1));
        $this->assertConflict(fn () => $this->leases->clear(1));
    }

    public function testWrongFindingAndActivatingLeaseCannotSave(): void
    {
        $this->active();
        $never = static function (): void { self::fail('Unauthorized callback ran.'); };
        $this->assertConflict(fn () => $this->leases->withLeaseLock('finding-b', 1, $never));
        $this->otherLeases->takeover('finding-a', 'owner-b', 'tab-b', 1);
        $this->assertConflict(fn () => $this->leases->withLeaseLock('finding-a', 2, $never));
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testCallbackFailureRollsBackEvidenceAndLeavesTheActiveContextRetryable(): void
    {
        $before = $this->active();
        $this->proofTable();
        $failure = new \RuntimeException('Evidence persistence failed.');
        try {
            $this->leases->withLeaseLock('finding-a', 1, static function (Connection $db) use ($failure): void {
                $db->insert('lease_test_evidence', ['id' => 'proof']);
                throw $failure;
            });
            self::fail('Callback failure must escape.');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertSame($before, $this->otherLeases->current());
        self::assertSame(0, (int) $this->otherConnection->fetchOne('SELECT COUNT(*) FROM lease_test_evidence'));
        self::assertFalse($this->connection->isTransactionActive());
        $this->leases->withLeaseLock('finding-a', 1, static fn (Connection $db) => $db->insert('lease_test_evidence', ['id' => 'retry']));
        self::assertSame('closing', $this->otherLeases->current()['state']);
    }

    public function testClosingWriteFailureAlsoRollsBackTheCallbackWrites(): void
    {
        $before = $this->active();
        $this->proofTable();
        $this->connection->executeStatement("CREATE TRIGGER reject_closing BEFORE UPDATE OF state ON interactive_browser_lease WHEN NEW.state = 'closing' BEGIN SELECT RAISE(ABORT, 'closing failed'); END");
        try {
            $this->leases->withLeaseLock('finding-a', 1, static fn (Connection $db) => $db->insert('lease_test_evidence', ['id' => 'proof']));
            self::fail('Closing failure must escape.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('closing failed', $error->getMessage());
        }
        self::assertSame($before, $this->otherLeases->current());
        self::assertSame(0, (int) $this->otherConnection->fetchOne('SELECT COUNT(*) FROM lease_test_evidence'));
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testBusyCommitRollsBackThePhysicalTransactionAndAllowsRetry(): void
    {
        $before = $this->active();
        $this->proofTable();
        try {
            $this->assertConflict(function (): void {
                $this->leases->withLeaseLock('finding-a', 1, function (Connection $db): void {
                    $db->insert('lease_test_evidence', ['id' => 'proof']);
                    $this->otherConnection->beginTransaction();
                    self::assertSame('active', $this->otherLeases->current()['state']);
                });
            });
            self::assertFalse($this->connection->isTransactionActive());
            self::assertFalse($this->connection->getNativeConnection()->inTransaction());
        } finally {
            if ($this->otherConnection->isTransactionActive()) {
                $this->otherConnection->rollBack();
            }
        }
        self::assertSame($before, $this->otherLeases->current());
        self::assertSame(0, (int) $this->otherConnection->fetchOne('SELECT COUNT(*) FROM lease_test_evidence'));
        $this->leases->withLeaseLock('finding-a', 1, static fn (Connection $db) => $db->insert('lease_test_evidence', ['id' => 'retry']));
        self::assertSame('closing', $this->otherLeases->current()['state']);
        self::assertSame('retry', $this->otherConnection->fetchOne('SELECT id FROM lease_test_evidence'));
    }

    #[DataProvider('callbackOutcomes')]
    public function testSeparateOrmUnitOfWorkCannotCommitTheOuterLeaseLock(bool $fail): void
    {
        $before = $this->active();
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__).'/src/Entity'], true);
        $caller = new EntityManager($this->connection, $config);
        $writer = new EntityManager($this->connection, $config);
        (new SchemaTool($writer))->createSchema([$writer->getClassMetadata(Domain::class)]);
        $pending = (new Domain())->setHostname('unrelated-pending.invalid');
        $caller->persist($pending);
        $failure = new \RuntimeException('Abort after ORM flush.');
        try {
            $this->leases->withLeaseLock('finding-a', 1, function (Connection $db) use ($writer, $fail, $failure): void {
                $domain = (new Domain())->setHostname('synthetic-proof.invalid');
                $writer->persist($domain);
                $writer->flush();
                $domain->setNotes('second flush');
                $writer->flush();
                self::assertSame(1, $db->getTransactionNestingLevel());
                self::assertSame(0, (int) $this->otherConnection->fetchOne('SELECT COUNT(*) FROM domain'));
                $this->assertConflict(fn () => $this->otherLeases->takeover('finding-a', 'owner-b', 'tab-b', 1));
                if ($fail) {
                    throw $failure;
                }
            });
            self::assertFalse($fail, 'Expected rollback failure.');
        } catch (\RuntimeException $error) {
            self::assertTrue($fail);
            self::assertSame($failure, $error);
        } finally {
            $writer->close();
            $caller->close();
        }
        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame($fail ? 0 : 1, (int) $this->otherConnection->fetchOne('SELECT COUNT(*) FROM domain'));
        self::assertSame(0, (int) $this->otherConnection->fetchOne('SELECT COUNT(*) FROM domain WHERE hostname = ?', [$pending->getHostname()]));
        self::assertSame($fail ? $before : array_replace($before, ['state' => 'closing']), $this->otherLeases->current());
    }

    public static function callbackOutcomes(): iterable
    {
        yield 'commit' => [false];
        yield 'rollback' => [true];
    }

    public function testExistingTransactionIsRejectedWithoutCommittingCallerWork(): void
    {
        $this->proofTable();
        $this->connection->beginTransaction();
        $this->connection->insert('lease_test_evidence', ['id' => 'caller']);
        try {
            $this->leases->acquire($this->binding(), 'owner-a', 'tab-a');
            self::fail('Lease must own its transaction.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('own its transaction', $error->getMessage());
        }
        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        self::assertSame(0, (int) $this->otherConnection->fetchOne('SELECT COUNT(*) FROM lease_test_evidence'));
        $this->connection->rollBack();
        self::assertSame('available', $this->leases->current()['state']);
    }

    public function testBusyImmediateLockReturns409AndConnectionRemainsUsable(): void
    {
        $this->connection->executeStatement('BEGIN IMMEDIATE');
        try {
            $this->assertConflict(fn () => $this->otherLeases->acquire($this->binding(), 'owner-b', 'tab-b', 0));
            self::assertFalse($this->otherConnection->isTransactionActive());
            self::assertFalse($this->otherConnection->getNativeConnection()->inTransaction());
        } finally {
            $this->connection->executeStatement('ROLLBACK');
        }
        self::assertSame(1, $this->otherLeases->acquire($this->binding(), 'owner-b', 'tab-b', 0)['generation']);
    }

    public function testGenerationExhaustionFailsClosedWithoutEpochReset(): void
    {
        $this->connection->executeStatement('UPDATE interactive_browser_lease SET generation = ?', [PHP_INT_MAX]);
        $this->assertConflict(fn () => $this->leases->acquire($this->binding(), 'owner-a', 'tab-a', PHP_INT_MAX));
        self::assertSame(PHP_INT_MAX, $this->leases->current()['generation']);
        self::assertSame('available', $this->leases->current()['state']);
    }

    public function testMigrationIsAdditiveSingletonAndCannotResetEpochThroughDown(): void
    {
        $this->connection->executeStatement('DROP TABLE interactive_browser_lease');
        $this->proofTable();
        $this->connection->insert('lease_test_evidence', ['id' => 'existing']);
        $this->migrate();
        self::assertSame('existing', $this->connection->fetchOne('SELECT id FROM lease_test_evidence'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM interactive_browser_lease'));
        try {
            $this->connection->executeStatement("INSERT INTO interactive_browser_lease (singleton_id, generation, state, binding) VALUES (1, 0, 'available', '[]')");
            self::fail('Singleton must be unique.');
        } catch (UniqueConstraintViolationException) {
        }
        try {
            $this->connection->executeStatement("INSERT INTO interactive_browser_lease (singleton_id, generation, state, binding) VALUES (2, 0, 'available', '[]')");
            self::fail('A second singleton key must be rejected.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('CHECK constraint failed', $error->getMessage());
        }
        $migration = new \DoctrineMigrations\Version20261017000000($this->connection, new NullLogger());
        $this->expectException(AbortMigration::class);
        $migration->down(new Schema());
    }

    private function connect(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->path]);
        $connection->executeStatement('PRAGMA busy_timeout = 0');

        return $connection;
    }

    private function migrate(): void
    {
        require_once dirname(__DIR__).'/migrations/Version20261017000000.php';
        $migration = new \DoctrineMigrations\Version20261017000000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) {
            $this->connection->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        }
    }

    private function binding(string $findingId = 'finding-a'): array
    {
        return ['finding_id' => $findingId, 'start_url' => 'https://fixture.invalid/',
            'start_url_hash' => hash('sha256', 'https://fixture.invalid/')];
    }

    private function active(array $context = []): array
    {
        $this->leases->acquire($this->binding(), 'owner-a', 'tab-a', 0);
        $attached = $this->leases->attach(1, 'worker-a', 100, 1300, $context);
        self::assertSame('activating', $attached['state']);

        return $this->leases->activate(1);
    }

    private function proofTable(): void
    {
        $this->connection->executeStatement('CREATE TABLE lease_test_evidence (id VARCHAR(36) PRIMARY KEY)');
    }

    private function assertConflict(callable $operation): void
    {
        try {
            $operation();
        } catch (\RuntimeException $error) {
            self::assertSame(409, $error->getCode(), $error->getMessage());

            return;
        }
        self::fail('Expected RuntimeException with code 409.');
    }
}
