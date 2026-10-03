<?php

// Dedicated acceptance server only; never include from the application entrypoint.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = (string) getenv('STUDIO_BROWSER_ROOT');
if (!preg_match('~^/tmp/librebugbounty-studio-review-[a-zA-Z0-9_-]+$~D', $root)) {
    throw new RuntimeException('A fresh isolated STUDIO_BROWSER_ROOT for Studio review is required.');
}
foreach ([
    'APP_ENV' => 'dev',
    'APP_DEBUG' => '0',
    'APP_SECRET' => 'isolated-studio-review-browser-acceptance',
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
    $findings = [];
    $names = ['main', 'readyError', 'readyUnchecked', 'readyNoJsFixed', 'readyNoJsConfirmed', 'readyTouchConfirm', 'noImage', 'missing', 'queued', 'confirmed', 'archived', 'duplicate', 'vulnerable', 'autoFixed', 'legacyManual'];
    foreach ($names as $index => $name) {
        $finding = (new App\Entity\Finding())->setDomain($domain)
            ->setTitle($name === 'main' ? 'Lokaler Review-Fall · <script>window.__reviewFixtureExecuted=true</script>' : 'Lokaler Review-Fall: '.$name)
            ->setType('synthetic')->setUrl('http://127.0.0.1/review-fixture/'.$name.($name === 'main' ? '/'.str_repeat('long-url-', 90).'?q=%3Cfixture%3E&literal=+%252F' : ''))
            ->setMethod($name === 'main' ? 'POST' : 'GET')
            ->setRequestParams($name === 'main' ? ['q' => 'encoded & <fixture>', 'nested' => ['page' => 2, 'space' => 'a+b c']] : null)
            ->setPayload($name === 'main' ? '<script>window.__reviewFixtureExecuted = true</script>\n& marker' : null)
            ->setExpectedEvidence('LOCAL-REVIEW-ONLY')
            ->setSubmittedAt(new DateTimeImmutable(sprintf('2026-10-03T08:%02d:00+00:00', $index)));
        if ($name === 'confirmed') $finding->setManualAssessment('confirmed', null, new DateTimeImmutable('2026-10-03T09:00:00+00:00'));
        if ($name === 'archived') $finding->setManualAssessment('discarded', null, new DateTimeImmutable('2026-10-03T09:00:00+00:00'));
        if ($name === 'duplicate') $finding->setStatus('duplicate');
        if ($name === 'legacyManual') $finding->setReviewState('manually_checked');
        $manager->persist($finding);
        $findings[$name] = $finding;
        $fixtures[$name] = ['id' => $finding->getId(), 'url' => $finding->getUrl()];
    }
    $manager->flush();

    // Generate a synthetic local raster directly. No worker, target visit or retest.
    $png = static function (int $variant): string {
        $width = 960;
        $height = 540;
        $scanlines = '';
        for ($y = 0; $y < $height; $y++) {
            $scanlines .= "\0";
            for ($x = 0; $x < $width; $x++) {
                $color = $y < 52 ? [46, 56, 70] : [224, 232, 239];
                if ($y > 100 && $y < 170 && $x > 70 && $x < 850) $color = $variant === 1 ? [74, 129, 171] : [128, 106, 168];
                if ($y > 215 && $y < 460 && $x > 70 && $x < 520) $color = [190, 202, 216];
                if ($y > 215 && $y < 245 && $x > 570 && $x < 850) $color = [106, 122, 143];
                $scanlines .= chr($color[0]).chr($color[1]).chr($color[2]);
            }
        }
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($scanlines, 6)).$chunk('IEND', '');
    };
    $addImage = static function (string $name, string $filename, ?string $contents) use ($manager, $root, $findings, &$fixtures): App\Entity\Evidence {
        $finding = $findings[$name];
        $relative = $finding->getId().'/'.$filename;
        if ($contents !== null) {
            if (!is_dir($root.'/artifacts/'.$finding->getId())) mkdir($root.'/artifacts/'.$finding->getId(), 0700, true);
            file_put_contents($root.'/artifacts/'.$relative, $contents);
        }
        $evidence = (new App\Entity\Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath('storage/artifacts/'.$relative);
        $manager->persist($evidence);
        $fixtures[$name]['evidenceIds'][] = $evidence->getId();
        return $evidence;
    };
    $oldImage = $addImage('main', 'older.png', $png(2));
    $missingImage = $addImage('main', 'missing.png', null);
    $latestImage = $addImage('main', 'latest image #? ü.png', $png(1));
    foreach (['readyError', 'readyUnchecked', 'readyNoJsFixed', 'readyNoJsConfirmed', 'readyTouchConfirm', 'confirmed', 'archived', 'duplicate', 'vulnerable', 'autoFixed', 'legacyManual'] as $name) {
        $addImage($name, 'local.png', $png(2));
    }
    $addImage('missing', 'gone.png', null);
    foreach (['main' => 'inconclusive', 'readyError' => 'error', 'readyNoJsFixed' => 'error', 'readyNoJsConfirmed' => 'inconclusive', 'noImage' => 'inconclusive', 'missing' => 'error', 'confirmed' => 'inconclusive', 'archived' => 'error', 'duplicate' => 'inconclusive', 'vulnerable' => 'still_vulnerable', 'autoFixed' => 'not_vulnerable'] as $name => $result) {
        $run = (new App\Entity\RetestRun())->setFinding($findings[$name])->setMode('browser')->setResult($result)
            ->setStartedAt(new DateTimeImmutable('2026-10-03T08:31:00+00:00'))
            ->setFinishedAt(new DateTimeImmutable('2026-10-03T08:31:04+00:00'))
            ->setObservedEvidence('Lokale gespeicherte Beobachtung <nur Text>')->setFinalUrl($findings[$name]->getUrl());
        if ($result === 'error') $run->setErrorMessage('Lokaler synthetischer Fehler <script>window.__reviewFixtureExecuted=true</script>');
        $manager->persist($run);
        $fixtures[$name]['observationId'] = $run->getId();
    }
    $olderRun = (new App\Entity\RetestRun())->setFinding($findings['vulnerable'])->setResult('inconclusive')
        ->setStartedAt(new DateTimeImmutable('2026-10-02T08:00:00+00:00'))->setFinishedAt(new DateTimeImmutable('2026-10-02T08:00:04+00:00'));
    $manager->persist($olderRun);
    $job = (new App\Entity\ScreenshotJob())->setFinding($findings['main'])->setUrl($findings['main']->getUrl())->setStatus('available')
        ->setRequestedAt(new DateTimeImmutable('2026-10-03T08:32:00+00:00'))->setCapturedAt(new DateTimeImmutable('2026-10-03T08:32:08+00:00'))
        ->setFinishedAt(new DateTimeImmutable('2026-10-03T08:32:09+00:00'))->setScreenshotPath($latestImage->getFilePath())
        ->setCaptureMetadata(['challengeDetected' => true, 'challengeCleared' => false, 'challengeWaitedMs' => 30000, 'dialogSeen' => true, 'dialogText' => 'LOCAL-REVIEW-ONLY']);
    $manager->persist($job);
    $manager->persist((new App\Entity\ScreenshotJob())->setFinding($findings['queued'])->setUrl($findings['queued']->getUrl())->setStatus('queued')->setActiveKey($findings['queued']->getId()));
    $manager->flush();
    // This baseline fixture represents an observation stored before its legacy
    // judgment. Lifecycle timestamps must agree with that history as well.
    $manager->getConnection()->executeStatement('UPDATE retest_run SET created_at = ?, updated_at = ? WHERE finding_id = ?', ['2026-10-03 08:31:04', '2026-10-03 08:31:04', $findings['confirmed']->getId()]);
    foreach ($names as $index => $name) {
        $manager->getConnection()->executeStatement('UPDATE finding SET created_at = ?, updated_at = ? WHERE id = ?', [sprintf('2026-10-03 08:%02d:00', $index), sprintf('2026-10-03 08:%02d:00', $index), $findings[$name]->getId()]);
    }
    foreach ([[$oldImage, '2026-10-03 08:30:01'], [$missingImage, '2026-10-03 08:30:02'], [$latestImage, '2026-10-03 08:30:03']] as [$evidence, $date]) {
        $manager->getConnection()->executeStatement('UPDATE evidence SET created_at = ? WHERE id = ?', [$date, $evidence->getId()]);
    }
    $fixtures['main'] += ['latestEvidenceId' => $latestImage->getId(), 'missingEvidenceId' => $missingImage->getId(), 'olderEvidenceId' => $oldImage->getId()];
    $fixtures['_expect'] = ['total' => count($names), 'ready' => 7, 'missing' => 3, 'all' => 10,
        'readyOrder' => array_map(static fn (string $name): string => $fixtures[$name]['id'], ['main', 'readyError', 'readyUnchecked', 'readyNoJsFixed', 'readyNoJsConfirmed', 'readyTouchConfirm', 'legacyManual']),
        'allOrder' => array_map(static fn (string $name): string => $fixtures[$name]['id'], ['main', 'readyError', 'readyUnchecked', 'readyNoJsFixed', 'readyNoJsConfirmed', 'readyTouchConfirm', 'noImage', 'missing', 'queued', 'legacyManual'])];
    file_put_contents($root.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
    echo 'Initialized isolated Studio review fixtures at '.$root."\n";
    exit;
}

if (!is_file($root.'/database.sqlite') || !is_file($root.'/fixtures.json')) {
    throw new RuntimeException('Initialize the isolated Studio review fixtures before serving requests.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__studio_review_fixture') {
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
if ($path !== '/' && $file !== false && str_starts_with($file, $public.'/') && is_file($file)) return false;
$request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
