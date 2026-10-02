<?php

namespace App\Tests;

use App\Entity\Finding;
use App\Entity\ScreenshotJob;
use App\Tests\Support\ConcurrentWorkerKernel;

final class ConcurrentIntakeTest extends DatabaseTestCase
{
    public function testParallelExactIntakesReturnOneCreationAndOneDuplicate(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the cross-process intake test.');
        }

        $fixtureDir = APP_TEST_ROOT.'/concurrent-intake';
        mkdir($fixtureDir, 0700, true);
        $cacheDir = $fixtureDir.'/cache';
        $logDir = $fixtureDir.'/logs';
        putenv('INTAKE_CONCURRENT_CACHE_DIR='.$cacheDir);
        putenv('INTAKE_CONCURRENT_LOG_DIR='.$logDir);

        // Warm one shared read-only container before starting both processes.
        $kernel = new ConcurrentWorkerKernel('test', false);
        $kernel->boot();
        $kernel->shutdown();

        $url = 'http://parallel-intake.localhost/fixture';
        $this->entityManager->getConnection()->executeStatement(<<<'SQL'
CREATE TRIGGER pause_parallel_finding_insert
BEFORE INSERT ON finding
WHEN NEW.url = 'http://parallel-intake.localhost/fixture'
BEGIN
    SELECT fixture_pause();
END
SQL);

        $startFile = $fixtureDir.'/start';
        $workers = [];
        for ($index = 0; $index < 2; ++$index) {
            $readyFile = $fixtureDir.'/ready-'.$index;
            $resultFile = $fixtureDir.'/result-'.$index.'.json';
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                dirname(__DIR__).'/tests/Support/concurrent_intake_worker.php',
                $startFile,
                $readyFile,
                $resultFile,
                $url,
                'note-'.$index,
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__), $this->workerEnvironment());
            self::assertIsResource($process);
            fclose($pipes[0]);
            $workers[] = compact('process', 'pipes', 'readyFile', 'resultFile');
        }

        $deadline = microtime(true) + 10;
        while (!is_file($workers[0]['readyFile']) || !is_file($workers[1]['readyFile'])) {
            if (microtime(true) >= $deadline) {
                self::fail('Concurrent intake workers did not become ready.');
            }
            usleep(1000);
        }
        touch($startFile);

        $results = [];
        foreach ($workers as $worker) {
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exitCode = proc_close($worker['process']);
            self::assertSame(0, $exitCode, trim($stdout."\n".$stderr));
            self::assertFileExists($worker['resultFile']);
            $results[] = json_decode(file_get_contents($worker['resultFile']), true, 512, JSON_THROW_ON_ERROR);
        }

        $created = array_column($results, 'created');
        sort($created);
        self::assertSame([false, true], $created);
        self::assertCount(1, array_unique(array_column($results, 'id')));

        $this->entityManager->clear();
        $findings = $this->entityManager->getRepository(Finding::class)->findBy(['url' => $url]);
        self::assertCount(1, $findings);
        self::assertCount(1, $this->entityManager->getRepository(ScreenshotJob::class)->findBy(['finding' => $findings[0]]));
    }

    /** @return array<string, string> */
    private function workerEnvironment(): array
    {
        $environment = getenv();
        self::assertIsArray($environment);

        return array_replace($environment, [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            'APP_SECRET' => (string) ($_SERVER['APP_SECRET'] ?? 'test-secret'),
            'DATABASE_URL' => (string) $_SERVER['DATABASE_URL'],
            'EVIDENCE_STORAGE_DIR' => (string) $_SERVER['EVIDENCE_STORAGE_DIR'],
            'PLAYWRIGHT_WORKER_URL' => 'http://127.0.0.1:1',
            'RETEST_DEFAULT_TIMEOUT_MS' => '10000',
            'INTAKE_CONCURRENT_CACHE_DIR' => (string) getenv('INTAKE_CONCURRENT_CACHE_DIR'),
            'INTAKE_CONCURRENT_LOG_DIR' => (string) getenv('INTAKE_CONCURRENT_LOG_DIR'),
        ]);
    }
}
