<?php

/**
 * Isolated P8 server: real Symfony controllers/storage, synthetic worker HTTP.
 * STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-review-novnc-<unique> php <this> init
 * STUDIO_BROWSER_ROOT=<same> php -S 127.0.0.1:8097 -t public <this>
 * No product route is implemented here. No target/worker network is used.
 */
require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\ScreenshotJob;
use App\Service\EvidenceStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StudioReviewNoVncWorkerFixture
{
    public const TOKEN = 'p8-isolated-worker-token';
    public const GATEWAY_TOKEN = 'p8-isolated-gateway-token';
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';
    public array $state = ['starts' => 0, 'generation' => 0, 'session' => null, 'capture' => null, 'calls' => []];
    /** Deterministic capture/lease race barrier; never a timing sleep. */
    public ?Closure $afterCapture = null;
    /** Read-response barriers for deterministic ownership and policy races. */
    public ?Closure $afterStatus = null;
    public ?Closure $afterDialogFrame = null;
    public ?Closure $afterDialog = null;
    /** Pause after PHP's persistence commit, before the worker close side effect. */
    public ?Closure $beforeClose = null;

    public function __construct(private readonly ?string $statePath = null)
    {
        if ($statePath !== null && is_file($statePath)) {
            $this->state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
        }
    }

    public static function httpClient(): MockHttpClient
    {
        return (new self(getenv('STUDIO_BROWSER_ROOT').'/worker.json'))->client();
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (parse_url($url, PHP_URL_HOST) !== '127.0.0.1') {
                throw new RuntimeException('P8 fixture refuses non-loopback transport: '.$url);
            }
            $path = parse_url($url, PHP_URL_PATH);
            $body = json_decode((string) ($options['body'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            $this->state['calls'][] = ['method' => $method, 'path' => $path, 'body' => $body, 'query' => parse_url($url, PHP_URL_QUERY)];
            if ($method === 'GET' && str_ends_with($path, '/dialog-frame')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $session = $this->state['session'];
                $status = ($query['generation'] ?? null) !== (string) $this->state['generation'] ? 409
                    : ($session === null || !str_contains($path, $session['id']) ? 410
                    : (empty($session['retainedDialog']) ? 404
                    : (isset($query['candidateId']) && $query['candidateId'] !== ($session['retainedDialog']['id'] ?? null) ? 409 : 200)));
                if ($this->afterDialogFrame !== null) {
                    $hook = $this->afterDialogFrame; $this->afterDialogFrame = null; $hook();
                }
                return new MockResponse($status === 200 ? base64_decode(self::PNG, true) : '{}', [
                    'http_code' => $status, 'response_headers' => ['content-type: '.($status === 200 ? 'image/png' : 'application/json')],
                ]);
            }
            [$status, $data] = $this->dispatch($method, $path, $body, $options['normalized_headers']['authorization'][0] ?? '');
            if ($this->statePath !== null) {
                file_put_contents($this->statePath, json_encode($this->state, JSON_THROW_ON_ERROR), LOCK_EX);
            }
            return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), [
                'http_code' => $status, 'response_headers' => ['content-type: application/json'],
            ]);
        });
    }

    private function dispatch(string $method, string $path, array $body, string $authorization): array
    {
        if ($method === 'GET' && $path === '/health') return [200, ['status' => 'ok', 'mode' => 'interactive']];
        $gateway = str_starts_with($path, '/vnc-control/');
        if ($authorization !== 'Authorization: Bearer '.($gateway ? self::GATEWAY_TOKEN : self::TOKEN)
            && $authorization !== 'authorization: Bearer '.($gateway ? self::GATEWAY_TOKEN : self::TOKEN)) {
            return [401, ['errorMessage' => 'Fixture service credential rejected']];
        }
        if ($method === 'POST' && $path === '/vnc-control/fence') {
            $generation = $body['generation'] ?? null;
            if (!is_int($generation) || $generation < 1 || $generation < $this->state['generation']) {
                return [409, ['fenced' => false, 'reason' => 'stale_generation']];
            }
            $this->state['generation'] = $generation;
            if ($this->state['session'] !== null) $this->state['session']['generation'] = $generation;
            return [200, ['fenced' => true, 'generation' => $generation, 'activeGeneration' => $generation]];
        }
        if ($method === 'GET' && $path === '/vnc-control/state') {
            return [200, ['generation' => $this->state['generation'], 'activeGeneration' => $this->state['generation'], 'sockets' => [], 'captureLocked' => $this->state['capture'] !== null]];
        }
        if ($method === 'POST' && $path === '/interactive-sessions') {
            if ($this->state['session'] !== null) return [409, ['errorMessage' => 'Fixture browser busy']];
            if (!is_int($body['generation'] ?? null) || $body['generation'] < 1) return [400, ['errorMessage' => 'Generation required']];
            if (parse_url($body['url'] ?? '', PHP_URL_HOST) !== '127.0.0.1') return [400, ['errorMessage' => 'Synthetic loopback targets only']];
            ++$this->state['starts'];
            $this->state['generation'] = $body['generation'];
            $this->state['capture'] = null;
            $this->state['session'] = [
                'id' => $body['sessionId'] ?? str_pad(dechex($this->state['starts']), 48, 'd', STR_PAD_LEFT),
                'generation' => $body['generation'], 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'), 'expiresAt' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 1200),
                'initialUrl' => $body['url'], 'finalUrl' => $body['url'].'#retained-fixture-context',
                'httpStatus' => 200, 'initialHttpStatus' => 200,
                'challenge' => ['state' => 'unknown', 'active' => null],
                'initialChallenge' => ['state' => 'unknown', 'active' => null],
                'dialogObservation' => [], 'captureLocked' => false, 'captureId' => null, 'captureGeneration' => null,
                'captureFinalized' => false,
                'urlCandidates' => [self::urlCandidate($body['url'], ['entry']), self::urlCandidate($body['url'].'#retained-fixture-context', ['current'])],
                'urlCandidatesTruncated' => false,
                'dialogCapture' => ['state' => 'waiting', 'revision' => 0, 'armed' => true, 'pending' => false, 'attemptsRemaining' => 2],
            ];
            return [201, $this->state['session']];
        }
        if (!preg_match('~^/interactive-sessions/([a-f0-9]{48})/(status|capture|close|dialog)$~D', $path, $match)) {
            throw new RuntimeException('Unexpected P8 transport operation: '.$method.' '.$path);
        }
        $session = $this->state['session'];
        if ($session === null || $match[1] !== $session['id']) return [410, ['errorMessage' => 'Fixture session gone']];
        if ($method === 'GET' && $match[2] === 'status') {
            if ($this->afterStatus !== null) {
                $hook = $this->afterStatus; $this->afterStatus = null; $hook();
            }
            return [200, $session];
        }
        if ($method !== 'POST') return [405, ['errorMessage' => 'POST required']];
        if (($body['generation'] ?? null) !== $this->state['generation']) return [409, ['errorMessage' => 'stale_generation']];
        if ($match[2] === 'dialog') {
            $dialog = $session['dialogCapture'];
            if (($session['captureFinalized'] ?? false) || ($body['dialogRevision'] ?? null) !== $dialog['revision']) {
                return [409, ['errorMessage' => 'Dialog capture changed or finalized']];
            }
            if (!in_array($body['action'] ?? null, ['arm', 'retry', 'dismiss'], true)) return [400, ['errorMessage' => 'Invalid dialog action']];
            if ($body['action'] === 'arm') {
                if ($dialog['pending']) return [409, ['errorMessage' => 'Dialog pending']];
                $dialog = ['state' => 'waiting', 'revision' => $dialog['revision'] + 1, 'armed' => true, 'pending' => false, 'attemptsRemaining' => 2];
            } elseif (!$dialog['pending']) {
                return [409, ['errorMessage' => 'No pending dialog']];
            } elseif ($body['action'] === 'dismiss') {
                $dialog = array_replace($dialog, ['state' => $dialog['attemptsRemaining'] === 0 ? 'exhausted' : (empty($session['retainedDialog']) ? 'failed' : 'captured'), 'revision' => $dialog['revision'] + 1, 'pending' => false, 'armed' => false]);
                $this->state['session']['captureLocked'] = false;
            } else {
                if ($dialog['attemptsRemaining'] < 1) return [409, ['errorMessage' => 'Dialog retry exhausted']];
                $this->state['session']['retainedDialog'] = [
                    'id' => str_repeat('e', 48), 'capturedAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
                    'detectedAt' => gmdate('Y-m-d\TH:i:s.000\Z'), 'pageUrl' => $session['finalUrl'],
                    'type' => 'alert', 'message' => 'Retried fixture dialog',
                ];
                $dialog = array_replace($dialog, ['state' => 'captured', 'revision' => $dialog['revision'] + 1, 'pending' => false, 'armed' => false, 'attemptsRemaining' => $dialog['attemptsRemaining'] - 1]);
                $this->state['session']['captureLocked'] = false;
            }
            $this->state['session']['dialogCapture'] = $dialog;
            $result = $this->state['session'];
            if ($this->afterDialog !== null) {
                $hook = $this->afterDialog; $this->afterDialog = null; $hook();
            }
            return [200, $result];
        }
        if ($match[2] === 'close') {
            if ($this->beforeClose !== null) {
                $hook = $this->beforeClose; $this->beforeClose = null; $hook();
            }
            $this->state['session'] = null;
            return [200, ['id' => $session['id'], 'closed' => true]];
        }
        if ($session['dialogCapture']['pending'] || $session['dialogCapture']['state'] === 'capturing') {
            return [409, ['errorMessage' => 'Pending dialog must be resolved before capture']];
        }
        if (!array_key_exists('expectedCandidateId', $body)
            || ($body['expectedCandidateId'] !== null && (!is_string($body['expectedCandidateId']) || preg_match('/^[a-f0-9]{48}$/D', $body['expectedCandidateId']) !== 1))) {
            return [400, ['errorMessage' => 'Explicit capture selection required']];
        }
        $selected = $this->state['capture'] === null ? ($session['retainedDialog']['id'] ?? null) : $this->state['capture']['metadata']['dialogCandidateId'];
        if ($body['expectedCandidateId'] !== $selected) {
            return [409, ['errorMessage' => 'Stale retained dialog candidate']];
        }
        if ($this->state['capture'] === null) {
            $this->state['capture'] = [
                'generation' => $this->state['generation'], 'screenshotBase64' => self::PNG, 'capturedAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
                'httpStatus' => 200, 'finalUrl' => $selected === null ? $session['finalUrl'] : $session['retainedDialog']['pageUrl'], 'captureMethod' => 'desktop',
                'dialogSeen' => false, 'metadata' => [
                    'inputMethod' => 'vnc', 'manualActionCount' => null, 'manualInteraction' => null,
                    'captureId' => 'p8-proof-'.$this->state['starts'], 'captureGeneration' => $this->state['generation'],
                    'dialogCandidateId' => $selected,
                    // Finalized proof retains the URLs visible at capture time,
                    // regardless of later fixture/session navigation.
                    'urlCandidates' => $selected === null ? ($session['urlCandidates'] ?? []) : ($session['retainedDialog']['urlCandidates'] ?? []),
                    'urlCandidatesTruncated' => $selected === null ? ($session['urlCandidatesTruncated'] ?? false) : ($session['retainedDialog']['urlCandidatesTruncated'] ?? false),
                    'challenge' => $session['challenge'], 'initialChallenge' => $session['initialChallenge'],
                ],
            ];
            $this->state['session']['captureLocked'] = true;
            $this->state['session']['captureFinalized'] = true;
            $this->state['session']['captureId'] = $this->state['capture']['metadata']['captureId'];
            $this->state['session']['captureGeneration'] = $this->state['capture']['metadata']['captureGeneration'];
        }
        $result = array_replace($this->state['capture'], ['generation' => $this->state['generation']]);
        if ($this->afterCapture !== null) {
            $hook = $this->afterCapture;
            $this->afterCapture = null;
            $hook();
        }
        return [200, $result];
    }

    public static function urlCandidate(string $url, array $roles = ['navigation']): array
    {
        return ['url' => $url, 'roles' => $roles, 'observedAt' => '2026-10-18T12:00:00.000Z', 'pageId' => 'p8-page-1',
            'pageType' => 'main', 'openerPageId' => null, 'navigationSequence' => 1];
    }

    /** Use the actual additive migration, exclusively on an isolated database. */
    public static function leaseSchema(Doctrine\DBAL\Connection $connection): void
    {
        $path = $connection->getParams()['path'] ?? '';
        if (!str_starts_with($path, '/tmp/librebugbounty-')) throw new RuntimeException('Isolated P8 database required.');
        require_once dirname(__DIR__, 2).'/migrations/Version20261017000000.php';
        $connection->executeStatement('DROP TABLE IF EXISTS interactive_browser_lease');
        $migration = new DoctrineMigrations\Version20261017000000($connection, new Psr\Log\NullLogger());
        $migration->up(new Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $sql) {
            $connection->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        }
    }

    /** Shared seed for PHPUnit and the browser server; images never leave disk. */
    public static function seed(EntityManagerInterface $manager, EvidenceStorageInterface $storage): array
    {
        $domain = (new Domain())->setHostname('127.0.0.1')->setScheme('http')->setAuthorized(true);
        $manager->persist($domain);
        $fixtures = [];
        foreach (['noImage', 'normal', 'other', 'missing', 'queued', 'failed', 'olderReadable', 'blocked', 'invalid'] as $name) {
            $finding = (new Finding())->setDomain($domain)->setTitle('P8 local noVNC '.$name)->setType('synthetic')
                ->setUrl($name === 'invalid' ? 'file:///p8-not-a-target' : 'http://127.0.0.1/echo?case='.$name)
                ->setMethod('GET')->setStatus('new')->setExpectedEvidence('P8-LOCAL-ONLY');
            $manager->persist($finding);
            $manager->flush();
            $fixtures[$name] = ['id' => $finding->getId(), 'url' => $finding->getUrl()];
            if (in_array($name, ['normal', 'missing', 'olderReadable'], true)) {
                $stored = $storage->storeContents($finding, base64_decode(self::PNG, true), 'p8-source.png');
                $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($stored->relativePath)->setSha256($stored->sha256);
                $job = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus('available')
                    ->setScreenshotPath($stored->relativePath)->setCapturedAt(new DateTimeImmutable())
                    ->setCaptureMetadata(['challengeDetected' => false, 'challengeCleared' => null]);
                $manager->persist($evidence); $manager->persist($job); $manager->flush();
                $fixtures[$name]['evidenceId'] = $evidence->getId();
                if ($name === 'missing') $storage->deleteFile($stored->relativePath);
            }
            if (in_array($name, ['queued', 'failed', 'olderReadable'], true)) {
                $manager->persist((new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())
                    ->setStatus($name === 'failed' ? 'error' : 'queued')->setRequestedAt(new DateTimeImmutable('+1 minute')));
            }
            if ($name === 'blocked') {
                $manager->getConnection()->insert('finding_follow_up', ['finding_id' => $finding->getId(), 'pursuit' => 'active', 'checks_blocked' => 1, 'contact_blocked' => 0, 'changed_at' => '2026-10-17 12:00:00']);
            }
        }
        $manager->flush();
        $previous = null;
        $index = 0;
        foreach ($fixtures as &$fixture) {
            $manager->getConnection()->update('finding', ['created_at' => sprintf('2026-10-17 08:%02d:00', $index++)], ['id' => $fixture['id']]);
            $fixture['reviewPath'] = '/review?images=all'.($previous === null ? '' : '&after='.$previous);
            $previous = $fixture['id'];
        }
        unset($fixture);
        return $fixtures;
    }
}

// Loading this support file from PHPUnit defines the transport/seed only.
if (PHP_SAPI !== 'cli-server' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

$root = (string) getenv('STUDIO_BROWSER_ROOT');
if (!preg_match('~^/tmp/librebugbounty-studio-review-novnc-[a-zA-Z0-9_-]+$~D', $root) || is_link($root)) {
    throw new RuntimeException('A fresh isolated /tmp/librebugbounty-studio-review-novnc-* root is required.');
}
foreach ([
    'APP_ENV' => 'test', 'APP_DEBUG' => '0', 'APP_SECRET' => 'isolated-p8-novnc-secret',
    'DATABASE_URL' => 'sqlite:///'.$root.'/database.sqlite', 'EVIDENCE_STORAGE_DIR' => $root.'/artifacts',
    'PLAYWRIGHT_WORKER_URL' => 'http://127.0.0.1:1', 'PLAYWRIGHT_INTERACTIVE_URL' => 'http://127.0.0.1:1',
    'PLAYWRIGHT_WORKER_TOKEN' => StudioReviewNoVncWorkerFixture::TOKEN,
    'PLAYWRIGHT_GATEWAY_TOKEN' => StudioReviewNoVncWorkerFixture::GATEWAY_TOKEN, 'RETEST_DEFAULT_TIMEOUT_MS' => '1000',
] as $key => $value) {
    putenv($key.'='.$value); $_SERVER[$key] = $_ENV[$key] = $value;
}
$kernel = new class('test', false) extends App\Kernel {
    public function getCacheDir(): string { return getenv('STUDIO_BROWSER_ROOT').'/cache'; }
    public function getLogDir(): string { return getenv('STUDIO_BROWSER_ROOT').'/logs'; }
    protected function configureContainer(Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->services()->set('http_client', MockHttpClient::class)
            ->factory([StudioReviewNoVncWorkerFixture::class, 'httpClient'])->public();
        // Native isolated sessions are needed for two real browser cookie jars.
        $container->extension('framework', ['session' => [
            'storage_factory_id' => 'session.storage.factory.native', 'save_path' => getenv('STUDIO_BROWSER_ROOT').'/sessions',
        ]]);
    }
};
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== 'init' || file_exists($root)) throw new RuntimeException('Use init once with a new root.');
    mkdir($root, 0700, true); mkdir($root.'/sessions', 0700);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $manager = $container->get(EntityManagerInterface::class);
    (new Doctrine\ORM\Tools\SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
    StudioReviewNoVncWorkerFixture::leaseSchema($manager->getConnection());
    $fixtures = StudioReviewNoVncWorkerFixture::seed($manager, $container->get(EvidenceStorageInterface::class));
    file_put_contents($root.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
    echo 'Initialized isolated P8 noVNC fixtures at '.$root."\n";
    exit;
}
if (!is_file($root.'/database.sqlite') || !is_file($root.'/fixtures.json')) throw new RuntimeException('Initialize the isolated P8 fixture first.');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__studio_review_novnc_fixture') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); return; }
    $database = new PDO('sqlite:'.$root.'/database.sqlite');
    $snapshot = [];
    foreach (['finding', 'finding_assessment', 'finding_review_acknowledgement', 'finding_assessment_reset', 'retest_run', 'evidence', 'screenshot_job'] as $table) {
        $snapshot[$table] = $database->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode(['isolated' => true, 'contract' => 'p8-novnc-dispatch',
        'fixtures' => json_decode(file_get_contents($root.'/fixtures.json'), true, flags: JSON_THROW_ON_ERROR),
        'snapshot' => $snapshot, 'worker' => (new StudioReviewNoVncWorkerFixture($root.'/worker.json'))->state,
    ], JSON_THROW_ON_ERROR);
    return;
}
// These targets are optional local fixtures for Main's later real-worker smoke.
if (in_array($path, ['/echo', '/challenge', '/dialog'], true)) {
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><title>P8 local target</title><h1>P8-LOCAL-ONLY</h1><input id="echo"><button id="act">Local action</button><output id="ledger">0</output><script>let seq=0;document.addEventListener("input",()=>document.querySelector("#ledger").value=String(++seq));document.querySelector("#act").onclick=()=>{document.body.dataset.cleared="true";document.querySelector("#ledger").value=String(++seq)};</script>';
    if ($path === '/dialog') echo '<script>document.querySelector("#act").onclick=()=>alert("P8-LOCAL-DIALOG")</script>';
    return;
}
$public = realpath(dirname(__DIR__, 2).'/public');
$file = realpath($public.'/'.$path);
if ($path !== '/' && $file !== false && str_starts_with($file, $public.'/') && is_file($file)) return false;
$request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
if (!$request->cookies->has('lbb_locale')) $request->cookies->set('lbb_locale', 'en');
$response = $kernel->handle($request); $response->send(); $kernel->terminate($request, $response);
