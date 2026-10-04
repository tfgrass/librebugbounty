<?php

// Dedicated acceptance server only; never include from the application entrypoint.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = (string) getenv('STUDIO_BROWSER_ROOT');
if (!preg_match('~^/tmp/librebugbounty-studio-export-delete-[a-zA-Z0-9_-]+$~D', $root) || is_link($root)) {
    throw new RuntimeException('A fresh isolated STUDIO_BROWSER_ROOT for export/delete acceptance is required.');
}
foreach ([
    'APP_ENV' => 'dev',
    'APP_DEBUG' => '0',
    'APP_SECRET' => 'isolated-studio-export-delete-acceptance',
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
    mkdir($root.'/sessions', 0700);
    $kernel->boot();
    $manager = $kernel->getContainer()->get('doctrine')->getManager();
    if (($manager->getConnection()->getParams()['path'] ?? '') !== $root.'/database.sqlite') {
        throw new RuntimeException('Refusing to initialize a database outside the isolated root.');
    }
    (new Doctrine\ORM\Tools\SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
    $domains = [];
    foreach (['uncontacted.localhost', 'contacted.localhost', 'fixed.localhost', 'manual-fixed.localhost', 'archived.localhost', 'empty.localhost'] as $hostname) {
        $domains[$hostname] = (new App\Entity\Domain())->setHostname($hostname)->setScheme('http')->setAuthorized(true);
        $manager->persist($domains[$hostname]);
    }
    $fixtures = [];
    $add = static function (string $name, string $hostname, string $title) use ($manager, $domains, &$fixtures): App\Entity\Finding {
        $finding = (new App\Entity\Finding())->setDomain($domains[$hostname])->setTitle($title)->setType('synthetic')
            ->setUrl('http://'.$hostname.'/local-fixture/'.$name)->setSeverity('medium')
            ->setSubmittedAt(new DateTimeImmutable('2026-10-03T08:00:00+00:00'))
            ->setPrivateNotes('Nur lokale Export-/Lösch-Abnahme: '.$name);
        $manager->persist($finding);
        $fixtures[$name] = ['id' => $finding->getId(), 'hostname' => $hostname, 'url' => $finding->getUrl(), 'title' => $title];

        return $finding;
    };
    $deleteJs = $add('delete-js', 'uncontacted.localhost', 'Löschprobe mit JavaScript');
    $deleteNoJs = $add('delete-nojs', 'uncontacted.localhost', 'Löschprobe ohne JavaScript');
    $deleteJs->setManualAssessment('confirmed', null, new DateTimeImmutable('2026-10-03T08:02:00+00:00'));
    $deleteNoJs->setManualAssessment('confirmed', null, new DateTimeImmutable('2026-10-03T08:02:00+00:00'));
    $keep = $add('keep', 'uncontacted.localhost', 'Literal "; & <script>window.__exportFixtureExecuted=true</script> '.str_repeat('long-title-', 35));
    $keep->setManualAssessment('confirmed', null, new DateTimeImmutable('2026-10-03T08:02:00+00:00'));
    $keep->setPrivateNotes("Mehrzeilige lokale Notiz\nmit ; Semikolon und \"Anführungszeichen\".");
    $fixtures['keep']['notes'] = $keep->getPrivateNotes();
    $contacted = $add('contacted', 'contacted.localhost', 'Lokaler Kontaktzeitpunkt');
    $contacted->setContactedAt(new DateTimeImmutable('2026-10-03T08:02:00+00:00'));
    $rawFixed = $add('raw-fixed', 'fixed.localhost', 'Historischer Altstatus fixed');
    $rawFixed->setStatus('fixed');
    $manualFixed = $add('manual-fixed', 'manual-fixed.localhost', 'Aufgezeichnete manuelle Bewertung');
    $manualFixed->setManualAssessment('fixed', null, new DateTimeImmutable('2026-10-03T08:02:00+00:00'));
    $discarded = $add('discarded', 'archived.localhost', 'Nur im Archiv vorhandener Fall');
    $discarded->setManualAssessment('discarded', null, new DateTimeImmutable('2026-10-03T08:02:00+00:00'));
    $unknown = $add('unknown', 'uncontacted.localhost', 'Ohne manuelle Bewertung');
    $unknown->setStatus('verified');
    $manager->flush();

    foreach ([$deleteJs, $deleteNoJs, $keep] as $finding) {
        // Stored local PNG bytes; no capture worker or target page is invoked.
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=', true);
        $path = 'storage/artifacts/'.$finding->getId().'/local.png';
        mkdir($root.'/artifacts/'.$finding->getId(), 0700, true);
        file_put_contents($root.'/artifacts/'.$finding->getId().'/local.png', $contents);
        $evidence = (new App\Entity\Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($path)->setSha256(hash('sha256', $contents));
        $manager->persist($evidence);
        $run = (new App\Entity\RetestRun())->setFinding($finding)->setMode('browser')->setResult('inconclusive')
            ->setStartedAt(new DateTimeImmutable('2026-10-03T08:01:00+00:00'))->setFinishedAt(new DateTimeImmutable('2026-10-03T08:01:01+00:00'))
            ->setFinalUrl($finding->getUrl())->setObservedEvidence('Gespeicherte synthetische Beobachtung')->setScreenshotPath($path);
        $manager->persist($run);
        $job = (new App\Entity\ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus('available')->setScreenshotPath($path)
            ->setRequestedAt(new DateTimeImmutable('2026-10-03T08:01:00+00:00'))->setFinishedAt(new DateTimeImmutable('2026-10-03T08:01:02+00:00'))
            ->setCapturedAt(new DateTimeImmutable('2026-10-03T08:01:01+00:00'));
        $manager->persist($job);
        $assessment = new App\Entity\FindingAssessment($finding, 'confirmed', null, new DateTimeImmutable('2026-10-03T08:02:00+00:00'), $run->getId(), $evidence->getId(), ['fixture' => true]);
        $manager->persist($assessment);
        $acknowledgement = new App\Entity\FindingReviewAcknowledgement($finding, $assessment->getId(), 'confirmed', [], [], $run->getId(), $evidence->getId(), ['fixture' => true]);
        $manager->persist($acknowledgement);
    }
    $manager->flush();
    $fixtures['_expect'] = [
        'findings' => 8,
        'domains' => 6,
        'activeIds' => array_values(array_map(static fn (array $fixture): string => $fixture['id'], array_diff_key($fixtures, ['discarded' => true]))),
        'activeDomains' => ['contacted.localhost', 'fixed.localhost', 'manual-fixed.localhost', 'uncontacted.localhost'],
    ];
    file_put_contents($root.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
    echo 'Initialized isolated Studio export/delete fixtures at '.$root."\n";
    exit;
}

if (!is_file($root.'/database.sqlite') || !is_file($root.'/fixtures.json') || is_link($root.'/database.sqlite')) {
    throw new RuntimeException('Initialize isolated export/delete fixtures before serving requests.');
}
ini_set('session.save_path', $root.'/sessions');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
if ($path === '/__studio_export_delete_fixture' && $method === 'GET') {
    $database = new PDO('sqlite:'.$root.'/database.sqlite');
    $database->exec('PRAGMA query_only = ON');
    $snapshot = [];
    foreach (['domain', 'finding', 'screenshot_job', 'retest_run', 'evidence', 'finding_assessment', 'finding_review_acknowledgement'] as $table) {
        $snapshot[$table] = $database->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/artifacts', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && !$file->isLink()) {
            $files[substr($file->getPathname(), strlen($root.'/artifacts/'))] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($files);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['isolated' => true, 'fixtures' => json_decode(file_get_contents($root.'/fixtures.json'), true, flags: JSON_THROW_ON_ERROR), 'snapshot' => $snapshot, 'files' => $files], JSON_THROW_ON_ERROR);
    return;
}

// Only export/read and explicit local-fixture deletion paths are reachable.
// Technical actions, intake submission and all other mutations are rejected.
$read = $method === 'GET' && (preg_match('~^/(?:export(?:/download)?|findings(?:/[a-f0-9-]{36})?|artifacts/.+)$~D', $path) || $path === '/');
$delete = $method === 'POST' && preg_match('~^/findings/([a-f0-9-]{36})/delete$~D', $path, $matches);
if ($delete) {
    $fixtures = json_decode(file_get_contents($root.'/fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
    $delete = in_array($matches[1], [$fixtures['delete-js']['id'], $fixtures['delete-nojs']['id']], true) && ($_POST['surface'] ?? '') === 'studio';
}
$public = realpath(dirname(__DIR__, 2).'/public');
$file = realpath($public.'/'.$path);
if ($method === 'GET' && preg_match('~^/(?:css|js)/[a-zA-Z0-9._-]+$~D', $path) && $file !== false && str_starts_with($file, $public.'/') && is_file($file)) {
    return false;
}
if (!$read && !$delete) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'This isolated acceptance router permits only fixture reads, exports and confirmed-case deletion.';
    return;
}

$request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
