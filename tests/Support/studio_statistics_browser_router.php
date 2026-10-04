<?php

// Dedicated, isolated acceptance server; never include from the application entrypoint.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = (string) getenv('STUDIO_BROWSER_ROOT');
if (!preg_match('~^/tmp/librebugbounty-studio-statistics-[a-zA-Z0-9_-]+$~D', $root)) {
    throw new RuntimeException('A fresh isolated STUDIO_BROWSER_ROOT for Studio statistics is required.');
}
foreach ([
    'APP_ENV' => 'dev',
    'APP_DEBUG' => '0',
    'APP_SECRET' => 'isolated-studio-statistics-browser-acceptance',
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

if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== 'init' || file_exists($root)) {
        throw new RuntimeException('Use init once with a new isolated root.');
    }
    mkdir($root, 0700, true);
    $kernel->boot();
    $manager = $kernel->getContainer()->get('doctrine')->getManager();
    (new Doctrine\ORM\Tools\SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
    $domains = [];
    foreach (['alpha.test', 'beta.test', 'fixture.invalid', 'fixture.example', 'localhost', '127.0.0.1'] as $hostname) {
        $domains[$hostname] = (new App\Entity\Domain())->setHostname($hostname)->setScheme('http')->setAuthorized(true);
        $manager->persist($domains[$hostname]);
    }
    $fixtures = [];
    $add = static function (string $name, string $hostname, string $date) use ($manager, $domains, &$fixtures): App\Entity\Finding {
        $finding = (new App\Entity\Finding())->setDomain($domains[$hostname])->setTitle('Synthetic statistics · '.$name)
            ->setType('synthetic')->setSeverity('medium')->setUrl('http://127.0.0.1/statistics-fixture/'.$name)
            ->setSubmittedAt(new DateTimeImmutable($date))->setPrivateNotes('Private isolated statistics fixture');
        $manager->persist($finding);
        $fixtures[$name] = ['id' => $finding->getId(), 'hostname' => $hostname, 'date' => substr($date, 0, 10)];

        return $finding;
    };
    $old = $add('old', 'alpha.test', '2026-09-28T09:00:00')->setNotifiedOwnerAt(new DateTimeImmutable('2026-10-01T09:00:00'));
    $a = $add('oct-a', 'alpha.test', '2026-10-01T09:00:00')->setNotifiedOwnerAt(new DateTimeImmutable('2026-10-02T09:00:00'))
        ->setContactedAt(new DateTimeImmutable('2026-09-30T09:00:00'))
        ->setManualAssessment('fixed', null, new DateTimeImmutable('2026-10-03T09:00:00'));
    $b = $add('oct-b', 'alpha.test', '2026-10-01T23:55:00')->setContactedAt(new DateTimeImmutable('2026-10-01T23:58:00'));
    $c = $add('oct-c', 'beta.test', '2026-10-02T00:05:00')->setNotifiedOwnerAt(new DateTimeImmutable('2026-10-03T09:00:00'))
        ->setManualAssessment('confirmed', null, new DateTimeImmutable('2026-10-02T09:00:00'));
    $discarded = $add('archived', 'fixture.invalid', '2026-10-02T12:00:00')
        ->setManualAssessment('discarded', null, new DateTimeImmutable('2026-10-02T13:00:00'));
    $duplicate = $add('duplicate', 'alpha.test', '2026-10-02T12:30:00')->setStatus('duplicate');
    $local = $add('local', 'localhost', '2026-10-03T10:00:00')->setContactedAt(new DateTimeImmutable('2026-10-03T11:00:00'));
    $ip = $add('ip', '127.0.0.1', '2026-10-03T10:10:00')->setManualAssessment('fixed', null, new DateTimeImmutable('2026-10-03T11:00:00'));
    $escaped = $add('escaped', 'fixture.example', '2026-10-03T10:20:00')
        ->setTitle('</script><script>window.__statisticsFixtureExecuted=true</script> " & <fixture>');
    $unsent = $add('unsent', 'beta.test', '2026-10-03T10:30:00')
        ->setManualAssessment('confirmed', null, new DateTimeImmutable('2026-10-03T11:00:00'));
    $previous = $add('previous-year', 'fixture.invalid', '2025-10-02T09:00:00')
        ->setContactedAt(new DateTimeImmutable('2025-10-10T09:00:00'))->setReviewState('manually_checked');
    $ancient = $add('ancient', 'fixture.example', '2024-05-01T09:00:00')
        ->setReviewState('confirmed_fixed')->setStatus('fixed');
    $spike = [];
    for ($index = 1; $index <= 498; $index++) {
        $spike[] = $add(sprintf('spike-%03d', $index), 'alpha.test', '2026-10-01T09:15:00');
    }
    foreach ([
        [$a, 'confirmed', '2026-10-01T10:00:00'], [$a, 'fixed', '2026-10-03T09:00:00'],
        [$a, 'fixed', '2026-10-03T10:00:00'], [$c, 'confirmed', '2026-10-02T09:00:00'],
        [$unsent, 'confirmed', '2026-10-03T11:00:00'],
        [$old, 'fixed', '2026-10-02T15:00:00'], [$discarded, 'discarded', '2026-10-02T13:00:00'],
    ] as [$finding, $assessment, $date]) {
        $manager->persist(new App\Entity\FindingAssessment($finding, $assessment, null, new DateTimeImmutable($date)));
    }
    $manager->flush();
    $fixtures['_expect'] = ['total' => 510, 'octoberReported' => 507, 'octoberContacted' => 2, 'openContactCount' => 2, 'octoberFixed' => 2,
        'octoberHosts' => 6, 'yearReported' => 508, 'allReported' => 510,
        'spikeDay' => '2026-10-01', 'spikeDayReported' => 500, 'spikeDayContacted' => 1,
        'historicalContacts' => 4, 'historicalContactFrom' => '2025-10-10',
        'octoberReportedIds' => array_map(static fn (App\Entity\Finding $f): string => $f->getId(), [$a, $b, $c, $discarded, $duplicate, $local, $ip, $escaped, $unsent, ...$spike])];
    file_put_contents($root.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
    echo 'Initialized isolated Studio statistics fixtures at '.$root."\n";
    exit;
}

if (!is_file($root.'/database.sqlite') || !is_file($root.'/fixtures.json')) {
    throw new RuntimeException('Initialize isolated Studio statistics fixtures before serving requests.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__studio_statistics_fixture') {
    $database = new PDO('sqlite:'.$root.'/database.sqlite');
    $snapshot = [];
    foreach (['domain', 'finding', 'screenshot_job', 'retest_run', 'evidence', 'finding_assessment', 'setting'] as $table) {
        $snapshot[$table] = $database->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['isolated' => true, 'fixtures' => json_decode(file_get_contents($root.'/fixtures.json'), true, flags: JSON_THROW_ON_ERROR), 'snapshot' => $snapshot], JSON_THROW_ON_ERROR);
    return;
}
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
