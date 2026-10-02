<?php

use App\Service\FindingService;
use App\Tests\Support\ConcurrentWorkerKernel;
use Doctrine\DBAL\Connection;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $startFile, $readyFile, $resultFile, $url, $note] = $argv;

try {
    $kernel = new ConcurrentWorkerKernel('test', false);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $connection = $container->get(Connection::class);
    $native = $connection->getNativeConnection();
    if (!$native instanceof \PDO || !method_exists($native, 'sqliteCreateFunction')) {
        throw new \RuntimeException('The concurrent intake fixture requires PDO SQLite.');
    }
    $native->sqliteCreateFunction('fixture_pause', static function (): int {
        usleep(500000);

        return 0;
    });
    file_put_contents($readyFile, 'ready');
    $deadline = microtime(true) + 10;
    while (!is_file($startFile)) {
        if (microtime(true) >= $deadline) {
            throw new \RuntimeException('Timed out waiting for the concurrent intake start signal.');
        }
        usleep(1000);
    }

    $result = $container->get(FindingService::class)->createIntakeFinding($url, privateNotes: $note);
    file_put_contents($resultFile, json_encode([
        'created' => $result->created,
        'id' => $result->finding->getId(),
    ], JSON_THROW_ON_ERROR));
    $kernel->shutdown();
    exit(0);
} catch (\Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'error' => $exception::class.': '.$exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
