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
    'PLAYWRIGHT_WORKER_URL' => (string) (getenv('STUDIO_BROWSER_WORKER_BASE') ?: 'http://127.0.0.1:1'),
    'PLAYWRIGHT_INTERACTIVE_URL' => (string) (getenv('STUDIO_BROWSER_WORKER_BASE') ?: 'http://127.0.0.1:1'),
    'PLAYWRIGHT_WORKER_TOKEN' => (string) (getenv('STUDIO_BROWSER_WORKER_TOKEN') ?: ''),
    'PLAYWRIGHT_GATEWAY_TOKEN' => 'p8-isolated-gateway-token',
    'RETEST_DEFAULT_TIMEOUT_MS' => '1000',
] as $key => $value) {
    putenv($key.'='.$value);
    $_SERVER[$key] = $_ENV[$key] = $value;
}

function studio_worker_png(bool $target): string
{
    $width = 1440;
    $height = 900;
    $scanlines = '';
    for ($y = 0; $y < $height; $y++) {
        $scanlines .= "\0";
        for ($x = 0; $x < $width; $x++) {
            $color = $target ? [43, 102, 79] : [79, 55, 105];
            $band = intdiv($y, 90) % 2 === 0;
            if (!$target && $x > 620 && $x < 820 && $y > 400 && $y < 500) $color = [232, 176, 57];
            if ($target && $x > 590 && $x < 850 && $y > 390 && $y < 510) $color = $band ? [226, 238, 224] : [196, 222, 204];
            $scanlines .= chr($color[0]).chr($color[1]).chr($color[2]);
        }
    }
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($scanlines, 6)).$chunk('IEND', '');
}

function studio_worker_time(int $milliseconds): string
{
    return gmdate('Y-m-d\TH:i:s', intdiv($milliseconds, 1000)).sprintf('.%03dZ', $milliseconds % 1000);
}

/** @param array<string, mixed>|null $state */
function studio_worker_view(?array $state): array
{
    $initialUrl = (string) ($state['initialUrl'] ?? '');
    $cleared = ($state['cleared'] ?? false) === true;
    $challenge = $state['challenge'] ?? null;
    $initial = ['state' => 'detected', 'active' => true, 'snapshot' => ['title' => 'Synthetic browser protection']];
    $current = $challenge ?? ($cleared
        ? ['state' => 'cleared', 'active' => false, 'snapshot' => ['title' => 'Synthetic target page']]
        : $initial);
    $finalUrl = $initialUrl.($cleared ? '?interactive-target=1' : '#synthetic-protection');

    return [
        'id' => (string) ($state['id'] ?? ''),
        'createdAt' => studio_worker_time((int) ($state['createdAt'] ?? 0)),
        'expiresAt' => studio_worker_time((int) ($state['expiresAt'] ?? 0)),
        'initialUrl' => $initialUrl,
        'initialFinalUrl' => $initialUrl.'#synthetic-protection',
        'finalUrl' => $finalUrl,
        'httpStatus' => $cleared ? 200 : 403,
        'initialHttpStatus' => 403,
        'currentHttpStatus' => $cleared ? 200 : 403,
        'initialHttp' => ['url' => $initialUrl.'#synthetic-protection', 'status' => 403, 'cfMitigated' => 'challenge'],
        'currentHttp' => ['url' => $finalUrl, 'status' => $cleared ? 200 : 403, 'cfMitigated' => $cleared ? null : 'challenge'],
        'challenge' => $current,
        'initialChallenge' => $initial,
        'navigationError' => null,
    ];
}

function studio_worker_json(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

function studio_worker_error(int $status, string $message): never
{
    studio_worker_json($status, ['errorMessage' => $message]);
}

function studio_worker_frame(string $path): never
{
    header('Content-Type: image/png');
    header('Content-Length: '.(string) filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
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
            ->setPrivateNotes($name === 'main' ? 'Synthetic private note retained across Review resets.' : null)
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
    file_put_contents($root.'/worker-challenge.png', studio_worker_png(false));
    file_put_contents($root.'/worker-target.png', studio_worker_png(true));
    file_put_contents($root.'/worker-state.json', '');
    file_put_contents($root.'/worker-state.lock', '');
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
if ($path === '/health' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    studio_worker_json(200, ['ok' => true]);
}
if (preg_match('~^/interactive-sessions(?:/([a-f0-9]{48})(?:/(status|frame|input|capture|close))?)?$~D', $path, $matches)) {
    $sessionId = $matches[1] ?? null;
    $operation = $matches[2] ?? 'status';
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $expectedMethod = $sessionId === null ? 'POST' : (in_array($operation, ['frame', 'status'], true) ? 'GET' : 'POST');
    if ($method !== $expectedMethod) studio_worker_error(405, 'Method not allowed.');
    if ($method === 'POST') {
        $expectedToken = (string) getenv('PLAYWRIGHT_WORKER_TOKEN');
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $suppliedToken = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        if ($expectedToken === '' || $suppliedToken === '' || !hash_equals($expectedToken, $suppliedToken)) {
            studio_worker_error(401, 'Invalid or missing worker token.');
        }
    }
    $payload = [];
    if ($method === 'POST') {
        $rawBody = (string) file_get_contents('php://input');
        if ($rawBody !== '') {
            try {
                $decoded = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                studio_worker_error(400, 'Malformed worker JSON body.');
            }
            if (!is_array($decoded)) studio_worker_error(400, 'Malformed worker JSON body.');
            $payload = $decoded;
        }
    }

    $lock = fopen($root.'/worker-state.lock', 'c');
    if ($lock === false) throw new RuntimeException('Interactive worker fixture lock is unavailable.');
    flock($lock, LOCK_EX);
    try {
        $state = is_file($root.'/worker-state.json') ? json_decode((string) file_get_contents($root.'/worker-state.json'), true) : null;
        if (!is_array($state)) $state = null;
        if ($state !== null && (int) ($state['expiresAt'] ?? 0) <= (int) round(microtime(true) * 1000)) {
            file_put_contents($root.'/worker-state.json', '');
            studio_worker_error(410, 'Interactive browser session expired.');
        }

        if ($sessionId === null) {
            $url = (string) ($payload['url'] ?? '');
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if (!in_array($scheme, ['http', 'https'], true) || strlen($url) > 8192) {
                studio_worker_error(400, 'Interactive url must be an HTTP(S) URL.');
            }
            if ($state !== null) studio_worker_error(503, 'Interactive browser capacity reached (maximum one session).');
            $now = (int) round(microtime(true) * 1000);
            $state = ['id' => bin2hex(random_bytes(24)), 'initialUrl' => $url, 'createdAt' => $now, 'expiresAt' => $now + 20 * 60 * 1000, 'cleared' => false, 'typed' => null, 'captureAt' => null, 'captureBase64' => null, 'challenge' => null];
            file_put_contents($root.'/worker-state.json', json_encode($state, JSON_THROW_ON_ERROR));
            studio_worker_json(201, studio_worker_view($state));
        }
        if (($state['id'] ?? null) !== $sessionId) studio_worker_error(404, 'Interactive browser session not found.');

        if ($operation === 'status') {
            studio_worker_json(200, studio_worker_view($state));
        }
        if ($operation === 'frame') {
            studio_worker_frame($root.(($state['cleared'] ?? false) === true ? '/worker-target.png' : '/worker-challenge.png'));
        }
        if ($operation === 'input') {
            if ($state['captureAt'] !== null) studio_worker_error(409, 'Session already captured; retry capture or close it.');
            $action = (string) ($payload['action'] ?? '');
            if (!in_array($action, ['click', 'type', 'key', 'scroll', 'reload', 'back'], true)) studio_worker_error(400, 'Unsupported interactive action.');
            if ($action === 'click' && (!is_int($payload['x'] ?? null) || !is_int($payload['y'] ?? null) || $payload['x'] < 0 || $payload['x'] > 1439 || $payload['y'] < 0 || $payload['y'] > 899)) studio_worker_error(400, 'Click coordinates must be within the 1440x900 viewport.');
            if ($action === 'click') $state['cleared'] = true;
            if ($action === 'type') $state['typed'] = mb_substr((string) ($payload['text'] ?? ''), 0, 100);
            $state['challenge'] = $state['cleared'] ? ['state' => 'cleared', 'active' => false, 'snapshot' => ['title' => 'Synthetic target page']] : ['state' => 'detected', 'active' => true, 'snapshot' => ['title' => 'Synthetic browser protection']];
            file_put_contents($root.'/worker-state.json', json_encode($state, JSON_THROW_ON_ERROR));
            studio_worker_json(200, studio_worker_view($state));
        }
        if ($operation === 'capture') {
            if ($state['captureAt'] === null) {
                $state['captureAt'] = (int) round(microtime(true) * 1000);
                $state['captureBase64'] = base64_encode((string) file_get_contents($root.'/worker-target.png'));
                file_put_contents($root.'/worker-state.json', json_encode($state, JSON_THROW_ON_ERROR));
            }
            $view = studio_worker_view($state);
            studio_worker_json(200, [
                'screenshotBase64' => $state['captureBase64'],
                'capturedAt' => studio_worker_time((int) $state['captureAt']),
                'finalUrl' => $view['finalUrl'],
                'httpStatus' => $view['currentHttpStatus'],
                'dialogSeen' => false,
                'dialogType' => null,
                'dialogText' => null,
                'captureMethod' => 'interactive-desktop-page',
                'metadata' => [
                    'interactive' => true,
                    'manual' => true,
                    'contextMode' => 'fresh-retry',
                    'initialUrl' => $view['initialUrl'],
                    'initialFinalUrl' => $view['initialFinalUrl'],
                    'finalUrl' => $view['finalUrl'],
                    'initialChallenge' => $view['initialChallenge'],
                    'currentChallenge' => $view['challenge'],
                    'initialChallengeState' => 'detected',
                    'currentChallengeState' => 'cleared',
                    'initialChallengeDetected' => true,
                    'challengeDetected' => false,
                    'challengeCleared' => true,
                    'initialHttpStatus' => 403,
                    'currentHttpStatus' => 200,
                    'initialHttp' => $view['initialHttp'],
                    'currentHttp' => $view['currentHttp'],
                    'browserName' => 'chromium',
                ],
            ]);
        }
        if ($operation === 'close') {
            file_put_contents($root.'/worker-state.json', '');
            studio_worker_json(200, ['closed' => true, 'id' => $sessionId]);
        }
        studio_worker_error(405, 'Method not allowed.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
if ($path === '/__studio_review_fixture') {
    $database = new PDO('sqlite:'.$root.'/database.sqlite');
    $snapshot = [];
    foreach (['domain', 'finding', 'screenshot_job', 'retest_run', 'evidence', 'finding_assessment', 'finding_review_acknowledgement', 'finding_assessment_reset', 'finding_assessment_cancellation'] as $table) {
        $order = $table === 'finding_assessment_cancellation' ? 'assessment_id' : 'id';
        $snapshot[$table] = $database->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll(PDO::FETCH_ASSOC);
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
// Preserve this acceptance fixture's historical German copy without deployment settings.
if (!$request->cookies->has('lbb_locale')) $request->cookies->set('lbb_locale', 'de');
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
