<?php

// Dedicated isolated acceptance server; never include from the application entrypoint.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = (string) getenv('STUDIO_BROWSER_ROOT');
if (!preg_match('~^/tmp/librebugbounty-studio-first-start-[a-zA-Z0-9_-]+$~D', $root)) {
    throw new RuntimeException('A fresh isolated STUDIO_BROWSER_ROOT for first-start acceptance is required.');
}
$initializing = PHP_SAPI === 'cli';
if ($initializing) {
    if (($argv[1] ?? '') !== 'init' || file_exists($root) || is_link($root)) {
        throw new RuntimeException('Use init once with a new isolated root.');
    }
    $scenario = (string) (getenv('STUDIO_FIRST_START_SCENARIO') ?: 'empty');
    $locale = (string) (getenv('APP_LOCALE') ?: 'de');
} else {
    if (realpath($root) !== $root || !is_file($root.'/fixtures.json') || !is_file($root.'/database.sqlite')) {
        throw new RuntimeException('Initialize the isolated first-start fixtures before serving requests.');
    }
    $fixtures = json_decode(file_get_contents($root.'/fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
    $scenario = $fixtures['scenario'];
    $locale = $fixtures['locale'];
}
if (!in_array($scenario, ['empty', 'queued', 'archived'], true) || !in_array($locale, ['de', 'en'], true)) {
    throw new RuntimeException('Use the empty, queued or archived scenario and de or en locale.');
}
foreach ([
    'APP_ENV' => 'dev',
    'APP_DEBUG' => '0',
    'APP_LOCALE' => $locale,
    'APP_SECRET' => 'isolated-studio-first-start-browser-acceptance',
    'DATABASE_URL' => 'sqlite:///'.$root.'/database.sqlite',
    'EVIDENCE_STORAGE_DIR' => $root.'/artifacts',
    'PLAYWRIGHT_WORKER_URL' => 'http://127.0.0.1:1',
    'RETEST_DEFAULT_TIMEOUT_MS' => '1000',
] as $key => $value) {
    putenv($key.'='.$value);
    $_SERVER[$key] = $_ENV[$key] = $value;
}

$kernel = new class('dev', false) extends App\Kernel {
    public function getCacheDir(): string { return (string) getenv('STUDIO_BROWSER_ROOT').'/cache'; }
    public function getLogDir(): string { return (string) getenv('STUDIO_BROWSER_ROOT').'/logs'; }
};

if ($initializing) {
    mkdir($root, 0700, true);
    $kernel->boot();
    $manager = $kernel->getContainer()->get('doctrine')->getManager();
    (new Doctrine\ORM\Tools\SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
    $fixtures = ['scenario' => $scenario, 'locale' => $locale, 'finding' => null];
    if ($scenario !== 'empty') {
        $domain = (new App\Entity\Domain())->setHostname('127.0.0.1')->setScheme('http')->setAuthorized(true);
        $finding = (new App\Entity\Finding())->setDomain($domain)->setTitle('Synthetic first-start '.$scenario)
            ->setType('synthetic')->setUrl('http://127.0.0.1/first-start/'.$scenario)
            ->setSubmittedAt(new DateTimeImmutable('2026-10-04T10:00:00+02:00'));
        $manager->persist($domain);
        $manager->persist($finding);
        if ($scenario === 'archived') $finding->setManualAssessment('discarded', null, new DateTimeImmutable('2026-10-04T11:00:00+02:00'));
        if ($scenario === 'queued') {
            $manager->persist((new App\Entity\ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())
                ->setStatus('queued')->setActiveKey($finding->getId()));
        }
        $manager->flush();
        $fixtures['finding'] = ['id' => $finding->getId(), 'url' => $finding->getUrl()];
    }
    file_put_contents($root.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
    echo 'Initialized isolated first-start '.$scenario.' / '.$locale.' fixtures at '.$root."\n";
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__studio_first_start_fixture') {
    $database = new PDO('sqlite:'.$root.'/database.sqlite');
    $snapshot = [];
    foreach (['domain', 'finding', 'screenshot_job', 'retest_run', 'evidence', 'finding_assessment', 'finding_review_acknowledgement', 'setting'] as $table) {
        $snapshot[$table] = $database->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    $snapshot['artifacts'] = [];
    if (is_dir($root.'/artifacts')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/artifacts', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) $snapshot['artifacts'][substr($file->getPathname(), strlen($root.'/artifacts/'))] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($snapshot['artifacts']);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['isolated' => true, 'fixtures' => $fixtures, 'snapshot' => $snapshot], JSON_THROW_ON_ERROR);
    return;
}
$public = realpath(dirname(__DIR__, 2).'/public');
$file = realpath($public.'/'.$path);
if ($path !== '/' && $file !== false && str_starts_with($file, $public.'/') && is_file($file)) return false;
$request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
