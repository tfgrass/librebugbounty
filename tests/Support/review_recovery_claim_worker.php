<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $fixtureDir, $identity] = $argv;
$kernel = new \App\Tests\Support\ReviewRecoveryWorkerKernel('test', false);
try {
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $container->get(\Doctrine\DBAL\Connection::class)->executeStatement('PRAGMA busy_timeout = 3000');
    $service = $container->get(\App\Service\ReviewDispositionService::class);
    touch($fixtureDir.'/ready-'.$identity);
    $deadline = microtime(true) + 10;
    while (!is_file($fixtureDir.'/release')) {
        if (microtime(true) > $deadline) throw new \RuntimeException('Claim barrier timed out.');
        usleep(1000);
    }
    file_put_contents($fixtureDir.'/result-'.$identity.'.json', json_encode($service->claimNext(), JSON_THROW_ON_ERROR));
} finally {
    $kernel->shutdown();
}
