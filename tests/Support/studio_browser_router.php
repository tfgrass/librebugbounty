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
] as $key => $value) {
    putenv($key.'='.$value);
    $_SERVER[$key] = $_ENV[$key] = $value;
}

$kernel = new class('dev', false) extends App\Kernel {
    public function getCacheDir(): string { return (string) getenv('STUDIO_BROWSER_ROOT').'/cache'; }
    public function getLogDir(): string { return (string) getenv('STUDIO_BROWSER_ROOT').'/logs'; }
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
