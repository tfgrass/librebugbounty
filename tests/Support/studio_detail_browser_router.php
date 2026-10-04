<?php

// Dedicated acceptance server only; never include from the application entrypoint.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = (string) getenv('STUDIO_BROWSER_ROOT');
if (!preg_match('~^/tmp/librebugbounty-studio-detail-[a-zA-Z0-9_-]+$~D', $root)) {
    throw new RuntimeException('A fresh isolated STUDIO_BROWSER_ROOT for Studio detail is required.');
}
foreach ([
    'APP_ENV' => 'dev',
    'APP_DEBUG' => '0',
    'APP_SECRET' => 'isolated-studio-detail-browser-acceptance',
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
    $domain = (new App\Entity\Domain())->setHostname('127.0.0.1')->setScheme('http')->setAuthorized(true);
    $manager->persist($domain);
    $fixtures = [];
    $longNotes = "Lokale Browser-Abnahme\n".str_repeat('unbroken-note-', 110)."\n<script>window.__fixtureExecuted = true</script>";
    foreach (['main', 'missing', 'empty', 'queued', 'running', 'failed', 'challenge'] as $name) {
        $finding = (new App\Entity\Finding())
            ->setDomain($domain)
            ->setTitle($name === 'main' ? 'Lokaler Fall · langer Inhalt & <Text>' : 'Lokaler Zustand: '.$name)
            ->setType('synthetic')
            ->setUrl('http://127.0.0.1/studio-detail-'.$name.($name === 'main' ? '/'.str_repeat('a', 850).'?marker=%3Cfixture%3E' : ''))
            ->setExpectedEvidence('LOCAL-STUDIO-DETAIL')
            ->setSubmittedAt(new DateTimeImmutable('2026-10-03T08:00:00+00:00'))
            ->setPrivateNotes($name === 'main' ? $longNotes : null);
        if ($name === 'main') {
            $finding->setStatus('verified')->setReviewState('manual_checking');
        }
        $manager->persist($finding);
        $fixtures[$name] = ['finding' => $finding, 'id' => $finding->getId(), 'url' => $finding->getUrl()];
    }
    $manager->flush();

    // A generated local raster fixture, with no screenshot worker or target visit.
    $png = static function (int $variant): string {
        $width = 960;
        $height = 540;
        $scanlines = '';
        for ($y = 0; $y < $height; $y++) {
            $scanlines .= "\0";
            for ($x = 0; $x < $width; $x++) {
                $color = $y < 52 ? [46, 56, 70] : [220, 228, 236];
                if ($y > 100 && $y < 170 && $x > 70 && $x < 850) $color = $variant === 1 ? [74, 129, 171] : [128, 106, 168];
                if ($y > 215 && $y < 460 && $x > 70 && $x < 520) $color = [190, 202, 216];
                if ($y > 215 && $y < 245 && $x > 570 && $x < 850) $color = [106, 122, 143];
                $scanlines .= chr($color[0]).chr($color[1]).chr($color[2]);
            }
        }
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($scanlines, 6))
            .$chunk('IEND', '');
    };
    $addImage = static function (App\Entity\Finding $finding, string $filename, ?string $contents) use ($manager, $root): App\Entity\Evidence {
        $relative = $finding->getId().'/'.$filename;
        if ($contents !== null) {
            if (!is_dir($root.'/artifacts/'.$finding->getId())) mkdir($root.'/artifacts/'.$finding->getId(), 0700, true);
            file_put_contents($root.'/artifacts/'.$relative, $contents);
        }
        $evidence = (new App\Entity\Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath('storage/artifacts/'.$relative);
        $manager->persist($evidence);

        return $evidence;
    };
    $main = $fixtures['main']['finding'];
    $oldImage = $addImage($main, 'older.png', $png(2));
    $missingImage = $addImage($main, 'missing.png', null);
    $latestImage = $addImage($main, 'latest image #? ü.png', $png(1));
    $noteEvidence = (new App\Entity\Evidence())->setFinding($main)->setKind('note')->setValue('Lokaler Beleg <script>window.__fixtureExecuted = true</script>');
    $manager->persist($noteEvidence);
    $run = (new App\Entity\RetestRun())->setFinding($main)->setMode('browser')->setResult('inconclusive')
        ->setStartedAt(new DateTimeImmutable('2026-10-03T08:01:00+00:00'))
        ->setFinishedAt(new DateTimeImmutable('2026-10-03T08:01:04+00:00'))
        ->setObservedEvidence('Nur lokaler uneindeutiger Fixture-Lauf')
        ->setFinalUrl($main->getUrl());
    $manager->persist($run);
    $mainJob = (new App\Entity\ScreenshotJob())->setFinding($main)->setUrl($main->getUrl())->setStatus('available')
        ->setRequestedAt(new DateTimeImmutable('2026-10-03T08:02:00+00:00'))
        ->setCapturedAt(new DateTimeImmutable('2026-10-03T08:02:08+00:00'))
        ->setFinishedAt(new DateTimeImmutable('2026-10-03T08:02:09+00:00'))
        ->setScreenshotPath($latestImage->getFilePath())
        ->setCaptureMetadata(['challengeDetected' => true, 'challengeCleared' => true, 'challengeWaitedMs' => 7000, 'dialogSeen' => true, 'dialogText' => 'LOCAL-STUDIO-DETAIL']);
    $manager->persist($mainJob);
    $singleMissing = $addImage($fixtures['missing']['finding'], 'gone.png', null);
    $challengeImage = $addImage($fixtures['challenge']['finding'], 'challenge.png', $png(2));
    foreach (['queued', 'running', 'failed', 'challenge'] as $name) {
        $finding = $fixtures[$name]['finding'];
        $status = $name === 'challenge' ? 'available' : $name;
        $job = (new App\Entity\ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus($status);
        if (in_array($status, ['queued', 'running'], true)) $job->setActiveKey($finding->getId());
        if ($status === 'running') $job->setStartedAt(new DateTimeImmutable('2026-10-03T08:03:00+00:00'));
        if ($status === 'failed') $job->setFinishedAt(new DateTimeImmutable('2026-10-03T08:03:00+00:00'))->setErrorMessage('Lokaler Aufnahmefehler: Zielseite nicht geladen.');
        if ($name === 'challenge') $job->setScreenshotPath($challengeImage->getFilePath())
            ->setCapturedAt(new DateTimeImmutable('2026-10-03T08:04:00+00:00'))
            ->setCaptureMetadata(['challengeDetected' => true, 'challengeCleared' => false, 'challengeWaitedMs' => 30000]);
        $manager->persist($job);
    }
    $manager->flush();
    foreach ([[$oldImage, '2026-10-03 08:00:01'], [$missingImage, '2026-10-03 08:00:02'], [$latestImage, '2026-10-03 08:00:03']] as [$evidence, $createdAt]) {
        $manager->getConnection()->executeStatement('UPDATE evidence SET created_at = ? WHERE id = ?', [$createdAt, $evidence->getId()]);
    }
    foreach ($fixtures as &$fixture) unset($fixture['finding']);
    unset($fixture);
    $fixtures['main'] += ['latestEvidenceId' => $latestImage->getId(), 'missingEvidenceId' => $missingImage->getId(), 'olderEvidenceId' => $oldImage->getId(), 'observationId' => $run->getId(), 'initialNotes' => $longNotes];
    file_put_contents($root.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
    echo 'Initialized isolated Studio detail fixtures at '.$root."\n";
    exit;
}

if (!is_file($root.'/database.sqlite') || !is_file($root.'/fixtures.json')) {
    throw new RuntimeException('Initialize the isolated Studio detail fixtures before serving requests.');
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__studio_detail_fixture') {
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
