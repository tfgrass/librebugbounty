<?php

// Disposable README screenshot server. It is deliberately separate from the
// application entrypoint and refuses every root except its dedicated /tmp path.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = (string) getenv('README_DEMO_ROOT');
if (!preg_match('~^/tmp/librebugbounty-readme-demo-[a-zA-Z0-9_-]+$~D', $root)) {
    throw new RuntimeException('A fresh isolated README_DEMO_ROOT below /tmp is required.');
}
$projectStorage = realpath(dirname(__DIR__, 2).'/storage');
if ($projectStorage !== false && ($root === $projectStorage || str_starts_with($root.'/', $projectStorage.'/'))) {
    throw new RuntimeException('The README demo must never use application storage.');
}

foreach ([
    'APP_ENV' => 'dev',
    'APP_DEBUG' => '0',
    'APP_SECRET' => 'isolated-readme-screenshot-demo',
    'APP_LOCALE' => 'en',
    'DATABASE_URL' => 'sqlite:///'.$root.'/database.sqlite',
    'EVIDENCE_STORAGE_DIR' => $root.'/artifacts',
    'PLAYWRIGHT_WORKER_URL' => 'http://127.0.0.1:1',
    'RETEST_DEFAULT_TIMEOUT_MS' => '1000',
] as $key => $value) {
    putenv($key.'='.$value);
    $_SERVER[$key] = $_ENV[$key] = $value;
}

$kernel = new class('dev', false) extends App\Kernel {
    public function getCacheDir(): string { return (string) getenv('README_DEMO_ROOT').'/cache'; }
    public function getLogDir(): string { return (string) getenv('README_DEMO_ROOT').'/logs'; }
};

if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== 'init' || file_exists($root)) {
        throw new RuntimeException('Use init exactly once with a new README_DEMO_ROOT.');
    }

    mkdir($root, 0700, true);
    $kernel->boot();
    $manager = $kernel->getContainer()->get('doctrine')->getManager();
    (new Doctrine\ORM\Tools\SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());

    $domains = [];
    foreach (['shop-demo.test', 'portal-demo.test', 'catalog.example', 'account.example', 'booking.test'] as $hostname) {
        $domain = (new App\Entity\Domain())
            ->setHostname($hostname)
            ->setScheme('https')
            ->setAuthorized(true)
            ->setVerificationMethod('synthetic-readme-demo')
            ->setVerificationNote('Reserved demo hostname; no external request is made.');
        $manager->persist($domain);
        $domains[$hostname] = $domain;
    }

    $fixtures = [];
    $findings = [];
    $sequence = 0;
    $addFinding = static function (
        string $key,
        string $hostname,
        string $title,
        string $path,
        string $submittedAt,
        string $severity = 'medium',
    ) use ($manager, $domains, &$fixtures, &$findings, &$sequence): App\Entity\Finding {
        $finding = (new App\Entity\Finding())
            ->setDomain($domains[$hostname])
            ->setTitle($title)
            ->setType('reflected-xss')
            ->setSeverity($severity)
            ->setUrl('https://'.$hostname.$path)
            ->setExpectedEvidence('LBB-DEMO-2048')
            ->setSubmittedAt(new DateTimeImmutable($submittedAt))
            ->setPrivateNotes('Synthetic README demo. No real organization, target, or research data.');
        $manager->persist($finding);
        $findings[$key] = $finding;
        $fixtures[$key] = ['id' => $finding->getId(), 'hostname' => $hostname];
        ++$sequence;

        return $finding;
    };

    $review = $addFinding(
        'review',
        'shop-demo.test',
        'Checkout search parameter reflection',
        '/search?q=%3Csvg%20onload%3Dalert%28%27LBB-DEMO-2048%27%29%3E',
        '2026-10-01T08:15:00+02:00',
        'high',
    )
        ->setPayload("<svg onload=alert('LBB-DEMO-2048')>")
        ->setRequestParams(['q' => "<svg onload=alert('LBB-DEMO-2048')>"])
        ->setPrivateNotes('Alert is visible in the stored screenshot. Confirm the execution context before deciding.');

    $detail = $addFinding(
        'detail',
        'portal-demo.test',
        'Profile preview reflects the display name',
        '/profile/preview?display=LBB-DEMO-2048',
        '2026-10-01T11:40:00+02:00',
        'high',
    )
        ->setStatus('verified')
        ->setPayload("\"><img src=x onerror=alert('LBB-DEMO-2048')>")
        ->setRequestParams(['display' => 'LBB-DEMO-2048'])
        ->setPrivateNotes('Confirmed manually from the retained browser image and the reflected response context.')
        ->setContactedAt(new DateTimeImmutable('2026-10-03T09:10:00+02:00'));

    $error = $addFinding('error', 'booking.test', 'Date picker callback needs review', '/offers?date=LBB-DEMO-2048', '2026-10-01T14:20:00+02:00');
    $confirmed = $addFinding('confirmed', 'catalog.example', 'Product filter reflection', '/products?filter=LBB-DEMO-2048', '2026-10-02T08:30:00+02:00', 'high')
        ->setStatus('verified')->setNotifiedOwnerAt(new DateTimeImmutable('2026-10-03T10:25:00+02:00'));
    $fixed = $addFinding('fixed', 'account.example', 'Account lookup parameter', '/lookup?user=LBB-DEMO-2048', '2026-10-02T10:05:00+02:00')
        ->setStatus('fixed')->setContactedAt(new DateTimeImmutable('2026-10-02T16:00:00+02:00'));
    $contacted = $addFinding('contacted', 'portal-demo.test', 'Help center language switch', '/help?lang=LBB-DEMO-2048', '2026-10-02T13:25:00+02:00')
        ->setStatus('verified')->setContactedAt(new DateTimeImmutable('2026-10-03T08:45:00+02:00'));
    $sent = $addFinding('sent', 'shop-demo.test', 'Coupon preview parameter', '/coupon?code=LBB-DEMO-2048', '2026-10-03T07:50:00+02:00')
        ->setStatus('reported')->setContactedAt(new DateTimeImmutable('2026-10-03T12:10:00+02:00'))
        ->setNotifiedOwnerAt(new DateTimeImmutable('2026-10-03T12:15:00+02:00'));
    $fixedTwo = $addFinding('fixed_two', 'booking.test', 'Destination label reflection', '/destinations?label=LBB-DEMO-2048', '2026-10-03T09:00:00+02:00')->setStatus('fixed');
    $confirmedTwo = $addFinding('confirmed_two', 'catalog.example', 'Comparison title reflection', '/compare?title=LBB-DEMO-2048', '2026-10-03T10:15:00+02:00', 'high')->setStatus('verified');
    $confirmedThree = $addFinding('confirmed_three', 'account.example', 'Invitation message preview', '/invite?message=LBB-DEMO-2048', '2026-10-03T14:05:00+02:00')->setStatus('verified');
    $fixedThree = $addFinding('fixed_three', 'shop-demo.test', 'Wishlist title parameter', '/wishlist?title=LBB-DEMO-2048', '2026-10-04T08:35:00+02:00')->setStatus('fixed');
    $confirmedFour = $addFinding('confirmed_four', 'portal-demo.test', 'Support subject reflection', '/support?subject=LBB-DEMO-2048', '2026-10-04T09:10:00+02:00')->setStatus('verified');
    $checked = $addFinding('checked', 'catalog.example', 'Category label requires no action', '/category?label=LBB-DEMO-2048', '2026-10-04T09:55:00+02:00');
    $older = $addFinding('older', 'booking.test', 'Archived September triage example', '/archive?query=LBB-DEMO-2048', '2026-09-28T10:00:00+02:00')->setStatus('reported')
        ->setNotifiedOwnerAt(new DateTimeImmutable('2026-10-01T09:00:00+02:00'));

    $assess = static function (App\Entity\Finding $finding, string $assessment, string $at) use ($manager): void {
        $date = new DateTimeImmutable($at);
        $finding->setManualAssessment($assessment, null, $date);
        $manager->persist(new App\Entity\FindingAssessment($finding, $assessment, null, $date));
    };
    $assess($detail, 'confirmed', '2026-10-02T09:15:00+02:00');
    $assess($confirmed, 'confirmed', '2026-10-02T09:30:00+02:00');
    $assess($fixed, 'fixed', '2026-10-03T08:10:00+02:00');
    $assess($contacted, 'confirmed', '2026-10-02T14:00:00+02:00');
    $assess($sent, 'confirmed', '2026-10-03T08:15:00+02:00');
    $assess($fixedTwo, 'fixed', '2026-10-03T11:30:00+02:00');
    $assess($confirmedTwo, 'confirmed', '2026-10-03T12:40:00+02:00');
    $assess($confirmedThree, 'confirmed', '2026-10-03T15:00:00+02:00');
    $assess($fixedThree, 'fixed', '2026-10-04T09:05:00+02:00');
    $assess($confirmedFour, 'confirmed', '2026-10-04T10:00:00+02:00');
    $assess($checked, 'fixed', '2026-10-04T10:20:00+02:00');
    $assess($older, 'confirmed', '2026-09-29T10:00:00+02:00');

    $addRun = static function (App\Entity\Finding $finding, string $result, string $at, ?string $errorMessage = null) use ($manager): App\Entity\RetestRun {
        $start = new DateTimeImmutable($at);
        $run = (new App\Entity\RetestRun())
            ->setFinding($finding)
            ->setMode('browser')
            ->setResult($result)
            ->setStartedAt($start)
            ->setFinishedAt($start->modify('+4 seconds'))
            ->setHttpStatus($errorMessage === null ? 200 : null)
            ->setFinalUrl($finding->getUrl())
            ->setObservedEvidence($errorMessage === null ? 'Synthetic browser observation for the README demo.' : null)
            ->setErrorMessage($errorMessage);
        $manager->persist($run);

        return $run;
    };

    $reviewRun = $addRun($review, 'inconclusive', '2026-10-01T08:20:00+02:00');
    $detailRun = $addRun($detail, 'still_vulnerable', '2026-10-03T08:00:00+02:00');
    $addRun($error, 'error', '2026-10-01T14:30:00+02:00', 'The synthetic browser session timed out before a stable result.');
    $addRun($confirmed, 'still_vulnerable', '2026-10-02T09:25:00+02:00');
    $addRun($fixed, 'fixed', '2026-10-03T08:05:00+02:00');
    $addRun($contacted, 'still_vulnerable', '2026-10-02T13:50:00+02:00');
    $addRun($sent, 'still_vulnerable', '2026-10-03T08:10:00+02:00');
    $addRun($fixedTwo, 'fixed', '2026-10-03T11:20:00+02:00');
    $addRun($confirmedTwo, 'still_vulnerable', '2026-10-03T12:30:00+02:00');
    $addRun($confirmedThree, 'still_vulnerable', '2026-10-03T14:50:00+02:00');
    $addRun($fixedThree, 'fixed', '2026-10-04T09:00:00+02:00');
    $addRun($confirmedFour, 'still_vulnerable', '2026-10-04T09:50:00+02:00');
    $addRun($checked, 'fixed', '2026-10-04T10:10:00+02:00');
    $addRun($older, 'still_vulnerable', '2026-09-29T09:50:00+02:00');

    // Generate a local raster without GD, a browser visit, or any external file.
    $png = static function (int $accent): string {
        $width = 1280;
        $height = 720;
        $rows = '';
        $palette = $accent === 1
            ? [[13, 18, 28], [29, 41, 57], [56, 189, 153], [244, 247, 251], [226, 53, 76]]
            : [[18, 18, 24], [38, 42, 55], [91, 124, 250], [246, 247, 252], [234, 86, 64]];
        for ($y = 0; $y < $height; ++$y) {
            $rows .= "\0";
            for ($x = 0; $x < $width; ++$x) {
                $color = $palette[3];
                if ($y < 58) $color = $palette[0];
                elseif ($y < 112) $color = $palette[1];
                elseif ($x < 215) $color = [234, 238, 245];
                elseif ($y > 158 && $y < 204 && $x > 268 && $x < 1115) $color = [255, 255, 255];
                elseif ($y > 250 && $y < 575 && $x > 265 && $x < 840) $color = [255, 255, 255];
                elseif ($y > 250 && $y < 575 && $x > 880 && $x < 1160) $color = [235, 240, 247];
                if ($y > 310 && $y < 524 && $x > 395 && $x < 1035) $color = $palette[0];
                if ($y > 326 && $y < 367 && $x > 395 && $x < 1035) $color = $palette[4];
                if ($y > 400 && $y < 419 && $x > 455 && $x < 975) $color = [255, 255, 255];
                if ($y > 445 && $y < 461 && $x > 490 && $x < 940) $color = $palette[2];
                if ($y > 482 && $y < 510 && $x > 558 && $x < 870) $color = $palette[2];
                if ($y > 74 && $y < 94 && $x > 262 && $x < 420) $color = $palette[2];
                $rows .= chr($color[0]).chr($color[1]).chr($color[2]);
            }
        }
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($rows, 7))
            .$chunk('IEND', '');
    };

    $addImage = static function (App\Entity\Finding $finding, string $filename, string $contents, string $capturedAt) use ($manager, $root): App\Entity\Evidence {
        $relative = $finding->getId().'/'.$filename;
        $directory = $root.'/artifacts/'.$finding->getId();
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        file_put_contents($root.'/artifacts/'.$relative, $contents);
        $path = 'storage/artifacts/'.$relative;
        $evidence = (new App\Entity\Evidence())
            ->setFinding($finding)
            ->setKind('screenshot')
            ->setFilePath($path)
            ->setSha256(hash('sha256', $contents));
        $manager->persist($evidence);
        $capture = new DateTimeImmutable($capturedAt);
        $job = (new App\Entity\ScreenshotJob())
            ->setFinding($finding)
            ->setUrl($finding->getUrl())
            ->setStatus('available')
            ->setRequestedAt($capture->modify('-12 seconds'))
            ->setStartedAt($capture->modify('-10 seconds'))
            ->setCapturedAt($capture)
            ->setFinishedAt($capture->modify('+1 second'))
            ->setScreenshotPath($path)
            ->setCaptureMetadata([
                'browserName' => 'chromium',
                'dialogSeen' => true,
                'dialogType' => 'alert',
                'dialogText' => 'LBB-DEMO-2048',
                'captureMethod' => 'desktop-dialog',
                'synthetic' => true,
            ]);
        $manager->persist($job);

        return $evidence;
    };

    $reviewImage = $addImage($review, 'synthetic-review.png', $png(1), '2026-10-01T08:20:08+02:00');
    $detailImage = $addImage($detail, 'synthetic-detail.png', $png(2), '2026-10-03T08:00:08+02:00');
    $reviewRun->setScreenshotPath($reviewImage->getFilePath());
    $detailRun->setScreenshotPath($detailImage->getFilePath());

    $manager->flush();

    $createdAt = new DateTimeImmutable('2026-10-01T06:00:00+00:00');
    $offset = 0;
    foreach ($findings as $finding) {
        $at = $createdAt->modify('+'.$offset.' minutes')->format('Y-m-d H:i:s');
        $manager->getConnection()->executeStatement(
            'UPDATE finding SET created_at = ?, updated_at = ? WHERE id = ?',
            [$at, $at, $finding->getId()],
        );
        ++$offset;
    }

    $fixtures['_meta'] = [
        'isolated' => true,
        'locale' => 'en',
        'reviewId' => $review->getId(),
        'detailId' => $detail->getId(),
        'findingCount' => count($findings),
        'reservedHostsOnly' => true,
    ];
    file_put_contents($root.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo 'Initialized isolated README demo at '.$root."\n";
    exit;
}

if (!is_file($root.'/database.sqlite') || !is_file($root.'/fixtures.json')) {
    throw new RuntimeException('Initialize the isolated README demo before serving requests.');
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__readme_demo_fixture') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode([
        'isolated' => true,
        'fixtures' => json_decode(file_get_contents($root.'/fixtures.json'), true, flags: JSON_THROW_ON_ERROR),
    ], JSON_THROW_ON_ERROR);
    return;
}

$public = realpath(dirname(__DIR__, 2).'/public');
$file = realpath($public.'/'.$path);
if ($path !== '/' && $file !== false && str_starts_with($file, $public.'/') && is_file($file)) {
    return false;
}

$request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
$response = $kernel->handle($request);
$response->headers->set('X-LibreBugBounty-Demo', 'isolated-readme-fixture');
$response->send();
$kernel->terminate($request, $response);
