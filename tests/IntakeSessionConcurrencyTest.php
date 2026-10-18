<?php

namespace App\Tests;

use App\Service\FindingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

final class IntakeSessionConcurrencyTest extends DatabaseTestCase
{
    #[DataProvider('competingIntakes')]
    public function testBrowserContributionCommitsBeforeACompetingIntakeCanProceed(string $competingMode): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the cross-process intake test.');
        }

        $fixtureDir = APP_TEST_ROOT.'/intake-session-concurrency-'.$competingMode;
        mkdir($fixtureDir, 0700, true);
        $url = 'http://session-concurrency.localhost/'.$competingMode;
        $browserId = str_repeat('a', 64);
        $processes = [];

        try {
            $first = $this->worker($fixtureDir, $url, $browserId, 'held-browser');
            $processes[] = $first;
            $first->start();
            $this->waitForFile($fixtureDir.'/held', $first);

            // The callback has already written the ledger, but its transaction
            // is still open. The queue lock must span that whole transaction.
            $lock = fopen(sys_get_temp_dir().'/librebugbounty-screenshot-queue-mutation.lock', 'c+');
            self::assertIsResource($lock);
            try {
                $acquired = flock($lock, LOCK_EX | LOCK_NB);
                if ($acquired) {
                    flock($lock, LOCK_UN);
                }
                self::assertFalse($acquired, 'The intake callback released the queue lock before committing.');
            } finally {
                fclose($lock);
            }

            $second = $this->worker($fixtureDir, $url, $browserId, $competingMode);
            $processes[] = $second;
            $second->start();
            $this->waitForFile($fixtureDir.'/competing', $second);
            self::assertFileDoesNotExist($fixtureDir.'/result-'.$competingMode.'.json');

            // A separate connection cannot see any half-committed attribution.
            $connection = $this->entityManager->getConnection();
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding'));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM intake_session_entry'));
            touch($fixtureDir.'/release');

            $results = [];
            foreach (['held-browser' => $first, $competingMode => $second] as $mode => $process) {
                self::assertSame(0, $process->wait(), $process->getOutput().$process->getErrorOutput());
                $path = $fixtureDir.'/result-'.$mode.'.json';
                self::assertFileExists($path);
                $results[$mode] = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            }

            self::assertTrue($results['held-browser']['created']);
            self::assertFalse($results[$competingMode]['created']);
            self::assertSame($results['held-browser']['id'], $results[$competingMode]['id']);
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding'));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM intake_session_entry'));
            self::assertSame($browserId, $connection->fetchOne('SELECT browser_id FROM intake_session_entry'));
            self::assertSame($results['held-browser']['id'], $connection->fetchOne('SELECT finding_id FROM intake_session_entry'));
        } finally {
            // Failed assertions must not strand a lock holder or its competitor.
            touch($fixtureDir.'/release');
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    public static function competingIntakes(): iterable
    {
        yield 'legacy API or import' => ['legacy'];
        yield 'another browser intake' => ['browser'];
    }

    #[DataProvider('independentWriters')]
    public function testBrowserIntakeWaitsForAnIndependentDatabaseWriter(bool $duplicate, string $journalMode): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the cross-process intake test.');
        }

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('PRAGMA journal_mode = '.$journalMode);
        $fixtureDir = APP_TEST_ROOT.'/intake-independent-writer-'.($duplicate ? 'duplicate' : 'new').'-'.$journalMode;
        mkdir($fixtureDir, 0700, true);
        $seedUrl = 'http://session-concurrency.localhost/seed';
        $seed = self::getContainer()->get(FindingService::class)->createIntakeFinding($seedUrl, 'ORIGINAL', 'original notes')->finding;
        $url = $duplicate ? $seedUrl : 'http://session-concurrency.localhost/new';
        $browserId = str_repeat('b', 64);
        $processes = [];

        try {
            // This writer does not take the screenshot queue lock, like a
            // recheck or an independent settings/review request.
            $writer = $this->worker($fixtureDir, $seedUrl, $browserId, 'held-writer');
            $processes[] = $writer;
            $writer->start();
            $this->waitForFile($fixtureDir.'/writer-held', $writer);

            $browser = $this->worker($fixtureDir, $url, $browserId, 'browser');
            $processes[] = $browser;
            $browser->start();
            $this->waitForFile($fixtureDir.'/competing', $browser);
            // A deferred read-then-write transaction fails immediately with
            // SQLITE_BUSY here, even though its busy timeout is one second.
            usleep(200000);
            self::assertTrue($browser->isRunning(), $browser->getOutput().$browser->getErrorOutput());
            self::assertFileDoesNotExist($fixtureDir.'/result-browser.json');
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding'));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM intake_session_entry'));
            touch($fixtureDir.'/release-writer');

            self::assertSame(0, $writer->wait(), $writer->getOutput().$writer->getErrorOutput());
            self::assertSame(0, $browser->wait(), $browser->getOutput().$browser->getErrorOutput());
            $result = json_decode(file_get_contents($fixtureDir.'/result-browser.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(!$duplicate, $result['created']);
            self::assertSame($duplicate ? 1 : 2, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding'));
            self::assertSame($duplicate ? 1 : 2, (int) $connection->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
            self::assertSame($duplicate ? 0 : 1, (int) $connection->fetchOne('SELECT COUNT(*) FROM intake_session_entry'));
            self::assertSame('worker committed', $connection->fetchOne('SELECT title FROM finding WHERE id = ?', [$seed->getId()]));
            self::assertSame('ORIGINAL', $connection->fetchOne('SELECT expected_evidence FROM finding WHERE id = ?', [$seed->getId()]));
            self::assertSame('original notes', $connection->fetchOne('SELECT private_notes FROM finding WHERE id = ?', [$seed->getId()]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT generation FROM intake_browser WHERE id = ?', [$browserId]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM intake_session WHERE browser_id = ?', [$browserId]));
            if ($duplicate) {
                self::assertSame($seed->getId(), $result['id']);
            } else {
                self::assertSame($browserId, $connection->fetchOne('SELECT browser_id FROM intake_session_entry'));
                self::assertSame($result['id'], $connection->fetchOne('SELECT finding_id FROM intake_session_entry'));
            }
        } finally {
            touch($fixtureDir.'/release-writer');
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $connection->executeStatement('PRAGMA journal_mode = DELETE');
        }
    }

    public static function independentWriters(): iterable
    {
        yield 'new URL, rollback journal' => [false, 'DELETE'];
        yield 'duplicate, rollback journal' => [true, 'DELETE'];
        yield 'new URL, WAL' => [false, 'WAL'];
        yield 'duplicate, WAL' => [true, 'WAL'];
    }

    private function worker(string $fixtureDir, string $url, string $browserId, string $mode): Process
    {
        $environment = getenv();
        self::assertIsArray($environment);

        return new Process([
            PHP_BINARY,
            dirname(__DIR__).'/tests/Support/intake_session_concurrent_worker.php',
            $fixtureDir,
            $url,
            $browserId,
            $mode,
        ], dirname(__DIR__), array_replace($environment, [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            'APP_SECRET' => (string) ($_SERVER['APP_SECRET'] ?? 'test-secret'),
            'DATABASE_URL' => (string) $_SERVER['DATABASE_URL'],
            'EVIDENCE_STORAGE_DIR' => (string) $_SERVER['EVIDENCE_STORAGE_DIR'],
            'PLAYWRIGHT_WORKER_URL' => 'http://127.0.0.1:1',
            'INTAKE_CONCURRENT_CACHE_DIR' => $fixtureDir.'/cache',
            'INTAKE_CONCURRENT_LOG_DIR' => $fixtureDir.'/logs',
        ]), timeout: 15);
    }

    private function waitForFile(string $path, Process $process): void
    {
        $deadline = microtime(true) + 10;
        while (!is_file($path)) {
            if (!$process->isRunning()) {
                self::fail('Intake fixture stopped before signaling: '.$process->getOutput().$process->getErrorOutput());
            }
            if (microtime(true) >= $deadline) {
                self::fail('Timed out waiting for intake fixture signal '.$path);
            }
            usleep(1000);
        }
    }
}
