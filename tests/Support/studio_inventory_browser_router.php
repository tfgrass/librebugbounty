<?php

// Dedicated acceptance server only; never include from the application entrypoint.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = (string) getenv('STUDIO_BROWSER_ROOT');
if (!preg_match('~^/tmp/librebugbounty-studio-inventory-[a-zA-Z0-9_-]+$~D', $root)) {
    throw new RuntimeException('A fresh isolated STUDIO_BROWSER_ROOT for Studio inventory is required.');
}
foreach ([
    'APP_ENV' => 'dev',
    'APP_DEBUG' => '0',
    'APP_SECRET' => 'isolated-studio-inventory-browser-acceptance',
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
    foreach (['127.0.0.1', '127.0.0.2'] as $hostname) {
        $domains[$hostname] = (new App\Entity\Domain())->setHostname($hostname)->setScheme('http')->setAuthorized(true);
        $manager->persist($domains[$hostname]);
    }
    $fixtures = [];
    $order = 0;
    $add = static function (string $name, string $title, string $url, string $hostname = '127.0.0.1') use ($manager, $domains, &$fixtures, &$order): App\Entity\Finding {
        $finding = (new App\Entity\Finding())
            ->setDomain($domains[$hostname])->setTitle($title)->setType('synthetic')
            ->setUrl($url)->setSeverity('medium')
            ->setSubmittedAt(new DateTimeImmutable(sprintf('2026-10-03T08:%02d:00+00:00', $order++)))
            ->setPrivateNotes('Lokale Listen-Abnahme · '.$name);
        $manager->persist($finding);
        $fixtures[$name] = ['id' => $finding->getId(), 'url' => $url, 'title' => $title];

        return $finding;
    };
    $batch = [];
    for ($index = 1; $index <= 22; $index++) {
        $name = sprintf('batch-%02d', $index);
        $batch[] = $add($name, sprintf('Batch local %02d', $index), 'http://127.0.0.1/'.$name);
    }
    $confirmed = $add('confirmed', 'Bestätigt · Technik uneindeutig', 'http://127.0.0.1/confirmed')
        ->setManualAssessment('confirmed', null, new DateTimeImmutable('2026-10-03T08:00:00+00:00'));
    $fixed = $add('fixed', 'Behoben · technische Beobachtung getrennt', 'http://127.0.0.1/fixed')
        ->setManualAssessment('fixed', null, new DateTimeImmutable('2026-10-03T08:00:00+00:00'));
    $contacted = $add('contacted', 'Kontaktzeitpunkt vorhanden', 'http://127.0.0.2/contacted', '127.0.0.2')
        ->setContactedAt(new DateTimeImmutable('2026-10-03T08:00:00+00:00'));
    $literal = $add('literal', 'Literal %_! token', 'http://127.0.0.1/literal%_!segment');
    $escaped = $add('escaped', '<script>window.__listFixtureExecuted=true</script> '.str_repeat('long-title-', 17), 'http://127.0.0.1/'.str_repeat('long-url-', 90).'?marker=%3Cfixture%3E');
    $discarded = $add('discarded', 'Archivierter Fall', 'http://127.0.0.1/discarded')
        ->setManualAssessment('discarded', null, new DateTimeImmutable('2026-10-03T08:00:00+00:00'));
    $duplicate = $add('duplicate', 'Duplikat mit manueller Bewertung', 'http://127.0.0.1/duplicate')
        ->setManualAssessment('discarded', 'duplicate', new DateTimeImmutable('2026-10-03T08:00:00+00:00'));
    $legacyDuplicate = $add('legacy-duplicate', 'Historisches Duplikat', 'http://127.0.0.1/legacy-duplicate')->setStatus('duplicate');
    $legacyDiscarded = $add('legacy-discarded', 'Historisch verworfener Fall', 'http://127.0.0.1/legacy-discarded')->setStatus('discarded');
    foreach ([[$confirmed, 'inconclusive'], [$fixed, 'still_vulnerable']] as [$finding, $result]) {
        $manager->persist((new App\Entity\RetestRun())->setFinding($finding)->setMode('browser')->setResult($result)
            ->setStartedAt(new DateTimeImmutable('2026-10-03T08:01:00+00:00'))
            ->setFinishedAt(new DateTimeImmutable('2026-10-03T08:01:01+00:00'))
            ->setObservedEvidence('Gespeicherte lokale Beobachtung')->setFinalUrl($finding->getUrl()));
    }
    foreach (['queued', 'running', 'failed'] as $index => $status) {
        // The first case on page two already has an active screenshot job, so
        // duplicate intake tests its existing-case path without queuing work.
        $finding = $batch[$status === 'queued' ? 11 : $index];
        $job = (new App\Entity\ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus($status);
        if ($status !== 'failed') $job->setActiveKey($finding->getId());
        if ($status === 'running') $job->setStartedAt(new DateTimeImmutable('2026-10-03T08:03:00+00:00'));
        if ($status === 'failed') $job->setFinishedAt(new DateTimeImmutable('2026-10-03T08:03:00+00:00'))->setErrorMessage('Lokaler Aufnahmefehler');
        $manager->persist($job);
    }
    $manager->flush();
    $fixtures['_expect'] = [
        'total' => 31, 'active' => 27, 'discarded' => 4, 'duplicates' => 2,
        'batchNewestFirst' => array_reverse(array_map(static fn (App\Entity\Finding $finding): string => $finding->getId(), $batch)),
        'literalIds' => [$literal->getId()],
    ];
    file_put_contents($root.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
    echo 'Initialized isolated Studio inventory fixtures at '.$root."\n";
    exit;
}

if (!is_file($root.'/database.sqlite') || !is_file($root.'/fixtures.json')) {
    throw new RuntimeException('Initialize isolated Studio inventory fixtures before serving requests.');
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__studio_inventory_fixture') {
    $database = new PDO('sqlite:'.$root.'/database.sqlite');
    $snapshot = [];
    foreach (['domain', 'finding', 'screenshot_job', 'retest_run', 'evidence', 'finding_assessment'] as $table) {
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
