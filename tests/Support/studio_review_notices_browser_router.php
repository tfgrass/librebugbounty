<?php

// Dedicated notice acceptance server. Never include from the application entrypoint.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = (string) getenv('STUDIO_BROWSER_ROOT');
if (!preg_match('~^/tmp/librebugbounty-studio-review-notices-[a-zA-Z0-9_-]+$~D', $root)) {
    throw new RuntimeException('A fresh isolated STUDIO_BROWSER_ROOT for notice acceptance is required.');
}
foreach ([
    'APP_ENV' => 'dev', 'APP_DEBUG' => '0', 'APP_SECRET' => 'isolated-review-notices-browser-acceptance',
    'DATABASE_URL' => 'sqlite:///'.$root.'/database.sqlite', 'EVIDENCE_STORAGE_DIR' => $root.'/artifacts',
    'PLAYWRIGHT_WORKER_URL' => 'http://127.0.0.1:1', 'RETEST_DEFAULT_TIMEOUT_MS' => '1000',
] as $key => $value) {
    putenv($key.'='.$value);
    $_SERVER[$key] = $_ENV[$key] = $value;
}
$kernel = new class('dev', false) extends App\Kernel {
    public function getCacheDir(): string { return (string) getenv('STUDIO_BROWSER_ROOT').'/cache'; }
    public function getLogDir(): string { return (string) getenv('STUDIO_BROWSER_ROOT').'/logs'; }
};

$addRun = static function (Doctrine\ORM\EntityManagerInterface $manager, App\Entity\Finding $finding, string $result, DateTimeImmutable $at): App\Entity\RetestRun {
    $run = (new App\Entity\RetestRun())->setFinding($finding)->setMode('browser')->setResult($result)
        ->setStartedAt($at)->setFinishedAt($at->modify('+4 seconds'))->setFinalUrl($finding->getUrl())
        ->setObservedEvidence('LOCAL-NOTICE-ONLY · gespeicherter neutraler Beispieltext');
    if ($result === 'error') $run->setErrorMessage('Synthetischer lokaler Fehler <nur Text>');
    $manager->persist($run);
    $manager->flush();
    $manager->getConnection()->executeStatement('UPDATE retest_run SET created_at = ?, updated_at = ? WHERE id = ?', [$at->format('Y-m-d H:i:s'), $at->format('Y-m-d H:i:s'), $run->getId()]);
    return $run;
};

if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== 'init' || file_exists($root)) throw new RuntimeException('Use init once with a new isolated root.');
    mkdir($root, 0700, true);
    $kernel->boot();
    $manager = $kernel->getContainer()->get('doctrine')->getManager();
    (new Doctrine\ORM\Tools\SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
    $domain = (new App\Entity\Domain())->setHostname('127.0.0.1')->setScheme('http')->setAuthorized(true);
    $manager->persist($domain);
    $names = ['main', 'inconclusive', 'error', 'fixedContradiction', 'noImage', 'noJs', 'shortcutFixed', 'shortcutConfirm', 'matchingConfirmed', 'matchingFixed', 'pending', 'known', 'unreviewed', 'archived', 'legacy'];
    $assessments = ['inconclusive' => 'fixed', 'fixedContradiction' => 'fixed', 'shortcutConfirm' => 'fixed', 'matchingFixed' => 'fixed', 'known' => 'fixed', 'archived' => 'discarded'];
    $fixtures = [];
    $findings = [];
    $assessmentAt = new DateTimeImmutable('2026-10-03T09:00:00+00:00');
    foreach ($names as $index => $name) {
        $assessment = $name === 'unreviewed' ? null : ($assessments[$name] ?? 'confirmed');
        $finding = (new App\Entity\Finding())->setDomain($domain)->setTitle('Lokaler Notice-Fall: '.$name.' <neutraler Text>')
            ->setType('synthetic')->setUrl('http://127.0.0.1/notice-fixture/'.$name.($name === 'main' ? '/'.str_repeat('local-review-', 60).'?label=neutral%20fixture&value=1' : ''))
            ->setMethod('GET')->setExpectedEvidence('LOCAL-NOTICE-ONLY')->setPrivateNotes('Unveränderte lokale Notiz & Text')
            ->setContactedAt(new DateTimeImmutable('2026-10-02T08:00:00+00:00'))
            ->setSubmittedAt(new DateTimeImmutable(sprintf('2026-10-03T08:%02d:00+00:00', $index)));
        if ($assessment !== null) {
            $finding->setManualAssessment($assessment, null, $assessmentAt)
                ->setStatus(['confirmed' => 'verified', 'fixed' => 'fixed', 'discarded' => 'discarded'][$assessment]);
        }
        $manager->persist($finding);
        $findings[$name] = $finding;
        $fixtures[$name] = ['id' => $finding->getId(), 'url' => $finding->getUrl(), 'assessment' => $assessment];
    }
    $manager->flush();

    // Synthetic raster generated locally; never visits a target or invokes a worker.
    $width = 960; $height = 540; $scanlines = '';
    for ($y = 0; $y < $height; ++$y) {
        $scanlines .= "\0";
        $color = $y < 54 ? "\x28\x35\x45" : ($y < 200 ? "\x5d\x89\xa8" : "\xc5\xd0\xdc");
        $scanlines .= str_repeat($color, $width);
    }
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($scanlines, 6)).$chunk('IEND', '');
    foreach ($names as $index => $name) {
        $finding = $findings[$name];
        $relative = $finding->getId().'/local neutral image.png';
        if ($name !== 'noImage') {
            mkdir($root.'/artifacts/'.$finding->getId(), 0700, true);
            file_put_contents($root.'/artifacts/'.$relative, $png);
        }
        $evidence = (new App\Entity\Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath('storage/artifacts/'.$relative);
        $manager->persist($evidence);
        $manager->flush();
        $fixtures[$name]['evidenceId'] = $evidence->getId();
        if ($fixtures[$name]['assessment'] !== null && $name !== 'legacy') {
            $baseResult = $name === 'known' ? 'inconclusive' : ($fixtures[$name]['assessment'] === 'fixed' ? 'fixed' : 'still_vulnerable');
            $baseRun = $addRun($manager, $finding, $baseResult, new DateTimeImmutable('2026-10-03T08:30:00+00:00'));
            $baseRow = $manager->getConnection()->fetchAssociative('SELECT * FROM retest_run WHERE id = ?', [$baseRun->getId()]);
            $states = [$baseRun->getId() => App\Service\ReviewNoticeService::runFingerprint($baseRow)];
            $history = new App\Entity\FindingAssessment($finding, $fixtures[$name]['assessment'], null, $assessmentAt, null, null, null, [$baseRun->getId()], $states);
            $manager->persist($history);
            $manager->flush();
            $manager->getConnection()->executeStatement('UPDATE finding_assessment SET known_observation_states = ? WHERE id = ?', [json_encode($states, JSON_THROW_ON_ERROR), $history->getId()]);
            $fixtures[$name]['baseObservationId'] = $baseRun->getId();
            $fixtures[$name]['assessmentId'] = $history->getId();
        }
        $result = match ($name) {
            'main', 'shortcutFixed' => 'fixed',
            'inconclusive', 'noImage' => 'inconclusive',
            'error', 'noJs', 'legacy', 'unreviewed', 'archived' => 'error',
            'fixedContradiction', 'shortcutConfirm', 'matchingConfirmed' => 'still_vulnerable',
            'matchingFixed' => 'fixed', 'pending' => 'pending', default => null,
        };
        if ($result !== null) {
            $run = $addRun($manager, $finding, $result, new DateTimeImmutable('2026-10-03T10:00:00+00:00'));
            $fixtures[$name]['observationId'] = $run->getId();
        }
        if ($name === 'main') {
            $matchingRun = $addRun($manager, $finding, 'still_vulnerable', new DateTimeImmutable('2026-10-03T11:00:00+00:00'));
            $fixtures[$name]['latestObservationId'] = $matchingRun->getId();
        }
        $manager->getConnection()->executeStatement('UPDATE finding SET created_at = ?, updated_at = ? WHERE id = ?', [sprintf('2026-10-03 08:%02d:00', $index), sprintf('2026-10-03 08:%02d:00', $index), $finding->getId()]);
    }
    $changedNames = ['main', 'inconclusive', 'error', 'fixedContradiction', 'noImage', 'noJs', 'shortcutFixed', 'shortcutConfirm', 'legacy'];
    $allNames = [...array_slice($changedNames, 0, -1), 'unreviewed', 'legacy'];
    $fixtures['_expect'] = ['total' => count($names), 'changed' => count($changedNames), 'readyChanged' => count($changedNames) - 1,
        'changedOrder' => array_map(static fn (string $name): string => $fixtures[$name]['id'], $changedNames),
        'readyChangedOrder' => array_map(static fn (string $name): string => $fixtures[$name]['id'], array_values(array_diff($changedNames, ['noImage']))),
        'allOrder' => array_map(static fn (string $name): string => $fixtures[$name]['id'], $allNames)];
    file_put_contents($root.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
    echo 'Initialized isolated notice fixtures at '.$root."\n";
    exit;
}

if (!is_file($root.'/database.sqlite') || !is_file($root.'/fixtures.json')) throw new RuntimeException('Initialize the isolated notice fixtures first.');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$fixtures = json_decode(file_get_contents($root.'/fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
if ($path === '/__studio_review_notices_fixture') {
    $database = new PDO('sqlite:'.$root.'/database.sqlite');
    $snapshot = [];
    foreach (['domain', 'finding', 'screenshot_job', 'retest_run', 'evidence', 'finding_assessment', 'finding_review_acknowledgement', 'setting'] as $table) {
        $snapshot[$table] = $database->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    $snapshot['artifacts'] = [];
    foreach (glob($root.'/artifacts/*/*') ?: [] as $file) $snapshot['artifacts'][substr($file, strlen($root.'/artifacts/'))] = hash_file('sha256', $file);
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode(['isolated' => true, 'fixtures' => $fixtures, 'snapshot' => $snapshot], JSON_THROW_ON_ERROR);
    return;
}
if ($path === '/__studio_review_notices_observation') {
    $name = $_POST['fixture'] ?? null; $result = $_POST['result'] ?? null;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($name) || !isset($fixtures[$name]['id']) || !is_string($result) || !in_array($result, ['fixed', 'still_vulnerable', 'inconclusive', 'error', 'pending'], true)) {
        http_response_code(400); echo 'Invalid isolated fixture update.'; return;
    }
    $kernel->boot();
    $manager = $kernel->getContainer()->get('doctrine')->getManager();
    $finding = $manager->find(App\Entity\Finding::class, $fixtures[$name]['id']);
    $run = $addRun($manager, $finding, $result, new DateTimeImmutable());
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode(['isolated' => true, 'observationId' => $run->getId()], JSON_THROW_ON_ERROR);
    return;
}
$public = realpath(dirname(__DIR__, 2).'/public');
$file = realpath($public.'/'.$path);
if ($path !== '/' && $file !== false && str_starts_with($file, $public.'/') && is_file($file)) return false;
$request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
// Preserve this acceptance fixture's historical German copy without deployment settings.
if (!$request->cookies->has('lbb_locale')) $request->cookies->set('lbb_locale', 'de');
$response = $kernel->handle($request); $response->send(); $kernel->terminate($request, $response);
