<?php

// Dedicated PHP built-in server only; never include this from public/index.php.
// STUDIO_BROWSER_ROOT must identify a fresh /tmp/librebugbounty-studio-* directory.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = (string) getenv('STUDIO_BROWSER_ROOT');
if (!preg_match('~^/tmp/librebugbounty-studio-[a-zA-Z0-9_-]+$~D', $root)) {
    throw new RuntimeException('An isolated STUDIO_BROWSER_ROOT under /tmp is required.');
}
foreach ([
    'APP_ENV' => 'dev',
    'APP_DEBUG' => '0',
    'APP_SECRET' => 'isolated-studio-browser-acceptance',
    'DATABASE_URL' => 'sqlite:///'. $root.'/database.sqlite',
    'EVIDENCE_STORAGE_DIR' => $root.'/artifacts',
    'PLAYWRIGHT_WORKER_URL' => 'http://127.0.0.1:1',
    'RETEST_DEFAULT_TIMEOUT_MS' => '10000',
] as $key => $value) {
    putenv($key.'='.$value);
    $_SERVER[$key] = $_ENV[$key] = $value;
}

$kernel = new class('dev', false) extends App\Kernel {
    public function getCacheDir(): string { return (string) getenv('STUDIO_BROWSER_ROOT').'/cache'; }
    public function getLogDir(): string { return (string) getenv('STUDIO_BROWSER_ROOT').'/logs'; }
    protected function configureContainer(Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->services()->set(App\Tests\Support\StudioContactFixture::class);
        $container->services()->set(App\Service\ContactDiscoveryService::class)->autowire()
            ->arg('$providers', [Symfony\Component\DependencyInjection\Loader\Configurator\service(App\Tests\Support\StudioContactFixture::class)]);

        $container->services()->set('studio.health_http_client', Symfony\Component\HttpClient\MockHttpClient::class)
            ->factory([App\Tests\Support\StudioHealthFixture::class, 'httpClient']);
        $container->services()->set(App\Service\BrowserHealthService::class)->autowire()
            ->arg('$httpClient', Symfony\Component\DependencyInjection\Loader\Configurator\service('studio.health_http_client'));
    }
};

if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== 'init' || file_exists($root)) {
        throw new RuntimeException('Use init once with a new isolated root.');
    }
    mkdir($root, 0700, true);
    $kernel->boot();
    $manager = $kernel->getContainer()->get('doctrine')->getManager();
    (new Doctrine\ORM\Tools\SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
    echo 'Initialized '.$root."\n";
    exit;
}

if (!is_file($root.'/database.sqlite')) {
    throw new RuntimeException('Initialize the isolated browser database before serving requests.');
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/__studio_acceptance_diagnostics', '/__studio_acceptance_diagnostics_state'], true)) {
    $kernel->boot();
    $manager = $kernel->getContainer()->get('doctrine')->getManager();
    $storage = new App\Service\LocalEvidenceStorage($root.'/artifacts');
    if ($path === '/__studio_acceptance_diagnostics') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM finding') !== 0) {
            http_response_code(400);
            exit;
        }
        $result = App\Tests\Support\StudioDiagnosticsFixture::seed($manager, $storage);
    } else {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
        $rows = [];
        foreach (['finding', 'finding_assessment', 'finding_review_acknowledgement', 'finding_assessment_reset', 'screenshot_job', 'retest_run', 'evidence', 'setting'] as $table) {
            $rows[$table] = $manager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid');
        }
        foreach ($storage->listPaths() as $file) $rows['files'][$file] = hash('sha256', $storage->read($file));
        $result = ['fingerprint' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['isolated' => true, 'result' => $result], JSON_THROW_ON_ERROR);
    return;
}
if ($path === '/__studio_acceptance_health') {
    $scenario = $_POST['scenario'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($scenario, ['unknown', 'healthy', 'partial', 'stale', 'browser-down'], true)) {
        http_response_code(400);
        exit;
    }
    $database = new PDO('sqlite:'.$root.'/database.sqlite');
    $database->beginTransaction();
    $database->exec("DELETE FROM setting WHERE id LIKE 'worker.heartbeat.%'");
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $insert = $database->prepare('INSERT INTO setting (id, value, updated_at) VALUES (?, ?, ?)');
    foreach (['recheck' => 'recheck-', 'screenshot' => 'shot-'] as $kind => $prefix) {
        foreach (range(1, 4) as $id) {
            if ($scenario === 'unknown' || ($scenario === 'partial' && ($id === 4 || ($kind === 'recheck' && $id > 1)))) continue;
            $stale = $scenario === 'stale' || ($scenario === 'partial' && $id > 1);
            $at = $stale ? $now->modify($kind === 'recheck' ? '-11 minutes' : '-6 minutes') : $now;
            $insert->execute(['worker.heartbeat.'.$kind.'.'.$prefix.$id, $at->format(DATE_ATOM), $now->format('Y-m-d H:i:s')]);
        }
    }
    $database->commit();
    file_put_contents($root.'/health-scenario', $scenario);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['isolated' => true, 'scenario' => $scenario]);
    return;
}
if ($path === '/__studio_acceptance_counts') {
    $database = new PDO('sqlite:'.$root.'/database.sqlite');
    $counts = [];
    foreach (['finding', 'screenshot_job', 'retest_run', 'evidence'] as $table) {
        $counts[$table] = (int) $database->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }
    $counts['queued'] = (int) $database->query("SELECT COUNT(*) FROM screenshot_job WHERE status = 'queued'")->fetchColumn();
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['isolated' => true, 'counts' => $counts], JSON_THROW_ON_ERROR);
    return;
}

// The built-in server may serve assets only from this project's public directory.
$public = realpath(dirname(__DIR__, 2).'/public');
$file = realpath($public.'/'.$path);
if ($path !== '/' && $file !== false && str_starts_with($file, $public.'/') && is_file($file)) {
    return false;
}

$request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
// Preserve this acceptance fixture's historical German copy without deployment settings.
if (!$request->cookies->has('lbb_locale')) $request->cookies->set('lbb_locale', 'de');
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
