<?php

use App\Dto\FindingCreateResult;
use App\Service\FindingService;
use App\Service\IntakeActivityService;
use App\Tests\Support\ConcurrentWorkerKernel;
use Doctrine\DBAL\Connection;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $fixtureDir, $url, $browserId, $mode] = $argv;
$resultFile = $fixtureDir.'/result-'.$mode.'.json';

try {
    $kernel = new ConcurrentWorkerKernel('test', false);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $connection = $container->get(Connection::class);
    $connection->executeStatement('PRAGMA busy_timeout = 1000');

    if ($mode === 'held-writer') {
        $connection->transactional(function () use ($connection, $url, $fixtureDir): void {
            $connection->executeStatement('UPDATE finding SET title = ? WHERE url = ?', ['worker committed', $url]);
            touch($fixtureDir.'/writer-held');
            $deadline = microtime(true) + 10;
            while (!is_file($fixtureDir.'/release-writer')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting to release the independent database writer.');
                }
                usleep(1000);
            }
        });
        $kernel->shutdown();
        exit(0);
    }

    $findings = $container->get(FindingService::class);

    if ($mode === 'held-browser') {
        $receipt = $container->get(IntakeActivityService::class)->capture(
            $browserId,
            fn (callable $record): FindingCreateResult => $findings->createIntakeFinding(
                $url,
                onResult: function (FindingCreateResult $result) use ($record, $fixtureDir): void {
                    $record($result);
                    touch($fixtureDir.'/held');
                    $deadline = microtime(true) + 10;
                    while (!is_file($fixtureDir.'/release')) {
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('Timed out waiting to release the intake callback.');
                        }
                        usleep(1000);
                    }
                },
            ),
        );
        $result = $receipt['result'];
    } elseif ($mode === 'browser') {
        touch($fixtureDir.'/competing');
        $receipt = $container->get(IntakeActivityService::class)->capture(
            $browserId,
            fn (callable $record): FindingCreateResult => $findings->createIntakeFinding($url, onResult: $record),
        );
        $result = $receipt['result'];
    } elseif ($mode === 'legacy') {
        touch($fixtureDir.'/competing');
        $result = $findings->createIntakeFinding($url);
    } else {
        throw new InvalidArgumentException('Unknown concurrent intake fixture mode.');
    }

    file_put_contents($resultFile, json_encode([
        'created' => $result->created,
        'id' => $result->finding->getId(),
    ], JSON_THROW_ON_ERROR));
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    $message = $exception::class.': '.$exception->getMessage();
    file_put_contents($resultFile, json_encode(['error' => $message], JSON_THROW_ON_ERROR));
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
