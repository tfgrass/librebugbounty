<?php

namespace App\Tests;

use App\Service\FindingService;
use App\Service\IntakeActivityConflict;
use App\Service\IntakeActivityService;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class IntakeActivityTest extends DatabaseTestCase
{
    private IntakeActivityService $activity;
    private FindingService $intake;
    private Session $session;
    private string $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activity = self::getContainer()->get(IntakeActivityService::class);
        $this->intake = self::getContainer()->get(FindingService::class);
        $this->session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($this->session);
        $this->browser = $this->activity->browserId($request, true);
    }

    public function testReadsDoNotStartOrRenewActivityAndGlobalLegacyIntakesStayOutsidePersonalSession(): void
    {
        $now = new \DateTimeImmutable('2026-10-18T12:00:00Z');
        $import = $this->intake->createFinding('https://import.example.test/old');
        $this->entityManager->getConnection()->executeStatement('UPDATE finding SET submitted_at = ? WHERE id = ?', ['2026-10-18 11:55:00', $import->getId()]);
        $before = $this->rows();
        $empty = $this->activity->snapshot($this->browser, now: $now);
        self::assertNull($empty['activeSession']);
        self::assertNull($empty['resumableSession']);
        self::assertSame(0, $empty['stats']['count']);
        self::assertSame($before, $this->rows());
        $global = $this->activity->snapshot($this->browser, 'today', 15, 'all', $now);
        self::assertSame(1, $global['stats']['count']);
        self::assertSame($import->getId(), $global['history']['findings'][0]['id']);

        $receipt = $this->capture('https://import.example.test/old', $now);
        self::assertFalse($receipt['result']->created);
        $active = $this->activity->snapshot($this->browser, now: $now);
        self::assertNotNull($active['activeSession']);
        self::assertSame(0, $active['activeSession']['count']);
        self::assertSame([], $active['history']['findings']);
        $unchanged = $this->rows();
        $this->activity->snapshot($this->browser, now: $now->modify('+20 minutes'));
        self::assertSame($unchanged, $this->rows());
    }

    public function testConfirmedProgressExceedsFiftyAndSurvivesDiscardAndFindingDeletion(): void
    {
        $now = new \DateTimeImmutable('2026-10-18T12:00:00Z');
        $first = null;
        for ($index = 0; $index < 61; ++$index) {
            $receipt = $this->capture('https://count.example.test/'.$index, $now->modify('+'.$index.' seconds'));
            $first ??= $receipt['result']->finding;
        }
        $this->capture('https://count.example.test/0', $now->modify('+2 minutes'));
        $this->intake->createFinding('https://count.example.test/import');
        $snapshot = $this->activity->snapshot($this->browser, now: $now->modify('+3 minutes'));
        self::assertSame(61, $snapshot['stats']['count']);
        self::assertSame(61, $snapshot['activeSession']['count']);
        self::assertCount(50, $snapshot['history']['findings']);
        self::assertNull($snapshot['stats']['averagePerWindow']);
        $this->intake->assess($first, 'discarded');
        self::assertSame(61, $this->activity->snapshot($this->browser, now: $now->modify('+3 minutes'))['stats']['count']);
        $this->intake->deleteFinding($first);
        $after = $this->activity->snapshot($this->browser, now: $now->modify('+3 minutes'));
        self::assertSame(61, $after['stats']['count']);
        self::assertNotContains($first->getId(), array_column($after['history']['findings'], 'id'));
    }

    public function testIdleSplitsAt150MinutesSinceLastIntakeAndResumeMergesOnlyThisBrowserWithGenerationFence(): void
    {
        $now = new \DateTimeImmutable('2026-10-18T12:00:00Z');
        $first = $this->capture('https://idle.example.test/a', $now);
        $this->capture('https://idle.example.test/a', $now->modify('+10 minutes'));
        $before = $this->rows();
        $beforeBoundary = $this->activity->snapshot($this->browser, now: $now->modify('+159 minutes 59 seconds'));
        self::assertSame($first['sessionId'], $beforeBoundary['activeSession']['id']);
        self::assertNull($beforeBoundary['resumableSession']);
        $idle = $this->activity->snapshot($this->browser, now: $now->modify('+160 minutes'));
        self::assertNull($idle['activeSession']);
        self::assertSame('idle', $idle['resumableSession']['state']);
        self::assertSame(600, $idle['resumableSession']['durationSeconds']);
        self::assertSame($before, $this->rows());
        $second = $this->capture('https://idle.example.test/b', $now->modify('+160 minutes'));
        self::assertNotSame($first['sessionId'], $second['sessionId']);
        $state = $this->activity->snapshot($this->browser, now: $now->modify('+161 minutes'));
        self::assertSame(1, $state['stats']['count']);
        self::assertSame($first['sessionId'], $state['resumableSession']['id']);
        $this->activity->change($this->browser, 'resume', $first['sessionId'], $second['sessionId'], $state['sessionGeneration'], $now->modify('+161 minutes'));
        $merged = $this->activity->snapshot($this->browser, now: $now->modify('+161 minutes'));
        self::assertSame($first['sessionId'], $merged['activeSession']['id']);
        self::assertSame(2, $merged['stats']['count']);
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM intake_session'));
        $this->expectException(IntakeActivityConflict::class);
        $this->activity->change($this->browser, 'end', $first['sessionId'], null, $first['generation'], $now->modify('+162 minutes'));
    }

    public function testIntakeBeforeIdleBoundaryRenewsTimerButSnapshotsAndReviewDoNot(): void
    {
        $now = new \DateTimeImmutable('2026-10-18T12:00:00Z');
        $first = $this->capture('https://renew.example.test/a', $now);
        $lastCapture = $now->modify('+149 minutes 59 seconds');
        $second = $this->capture('https://renew.example.test/b', $lastCapture);
        self::assertSame($first['sessionId'], $second['sessionId']);
        $before = $this->rows();

        $afterOriginalBoundary = $this->activity->snapshot($this->browser, now: $now->modify('+150 minutes'));
        self::assertSame($first['sessionId'], $afterOriginalBoundary['activeSession']['id']);
        self::assertSame(2, $afterOriginalBoundary['activeSession']['count']);
        $this->intake->assess($second['result']->finding, 'discarded');
        self::assertSame($before, $this->rows());

        $beforeRenewedBoundary = $this->activity->snapshot($this->browser, now: $lastCapture->modify('+149 minutes 59 seconds'));
        self::assertSame($first['sessionId'], $beforeRenewedBoundary['activeSession']['id']);
        $idle = $this->activity->snapshot($this->browser, now: $lastCapture->modify('+150 minutes'));
        self::assertNull($idle['activeSession']);
        self::assertSame($first['sessionId'], $idle['resumableSession']['id']);
        self::assertSame('idle', $idle['resumableSession']['state']);
        self::assertSame(8999, $idle['resumableSession']['durationSeconds']);
        self::assertSame($before, $this->rows());
    }

    public function testEndAndResumeSameIdRejectsOldTabAndBrowserCannotResumeSomeoneElsesSession(): void
    {
        $now = new \DateTimeImmutable('2026-10-18T12:00:00Z');
        $receipt = $this->capture('https://end.example.test/a', $now);
        $this->activity->change($this->browser, 'end', $receipt['sessionId'], null, $receipt['generation'], $now->modify('+1 minute'));
        $ended = $this->activity->snapshot($this->browser, now: $now->modify('+2 minutes'));
        self::assertNull($ended['activeSession']);
        self::assertSame(1, $ended['stats']['count']);
        self::assertSame(60, $ended['resumableSession']['durationSeconds']);
        $this->activity->change($this->browser, 'resume', $receipt['sessionId'], null, $ended['sessionGeneration'], $now->modify('+2 minutes'));
        try {
            $this->activity->change($this->browser, 'end', $receipt['sessionId'], null, $receipt['generation'], $now->modify('+3 minutes'));
            self::fail('Old tab must not end the resumed session with the same ID.');
        } catch (IntakeActivityConflict) {
            self::assertNotNull($this->activity->snapshot($this->browser, now: $now->modify('+3 minutes'))['activeSession']);
        }
        $this->expectException(IntakeActivityConflict::class);
        $this->activity->change(str_repeat('a', 64), 'resume', $receipt['sessionId'], null, 0, $now);
    }

    public function testSessionPaceUsesAdjacentWindowsAndFullSessionAverage(): void
    {
        $start = new \DateTimeImmutable('2026-10-18T12:00:00Z');
        foreach ([0, 899, 900, 1799] as $index => $seconds) {
            $this->capture('https://pace.example.test/'.$index, $start->modify('+'.$seconds.' seconds'));
        }
        $snapshot = $this->activity->snapshot($this->browser, now: $start->modify('+30 minutes'));
        self::assertSame(4, $snapshot['stats']['count']);
        self::assertSame(2, $snapshot['stats']['currentCount']);
        self::assertSame(2, $snapshot['stats']['previousCount']);
        self::assertSame(2.0, $snapshot['stats']['averagePerWindow']);
        self::assertSame(1800, $snapshot['stats']['durationSeconds']);
    }

    public function testAttributionFailureRollsBackFindingQueueAndSessionTogether(): void
    {
        $db = $this->entityManager->getConnection();
        $db->executeStatement("CREATE TRIGGER reject_intake_entry BEFORE INSERT ON intake_session_entry BEGIN SELECT RAISE(ABORT, 'private attribution error'); END");
        try {
            $this->capture('https://rollback.example.test/a', new \DateTimeImmutable());
            self::fail('Attribution trigger must reject the transaction.');
        } catch (\Doctrine\DBAL\Exception) {
            foreach (['finding', 'screenshot_job', 'intake_browser', 'intake_session', 'intake_session_entry'] as $table) {
                self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table), $table);
            }
        }
    }

    public function testBerlinTodayAndAdjacentWindowsIncludeGlobalImportsButExcludeFutureDates(): void
    {
        $now = new \DateTimeImmutable('2026-10-18T00:15:00+02:00');
        $times = ['2026-10-17 21:59:59', '2026-10-17 22:00:00', '2026-10-17 22:14:59', '2026-10-17 22:15:01'];
        foreach ($times as $index => $time) {
            $finding = $this->intake->createFinding('https://day.example.test/'.$index);
            $stored = (new \DateTimeImmutable($time.'Z'))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
            $this->entityManager->getConnection()->executeStatement('UPDATE finding SET submitted_at = ? WHERE id = ?', [$stored, $finding->getId()]);
        }
        $today = $this->activity->snapshot($this->browser, 'today', 15, 'all', $now);
        self::assertSame(2, $today['stats']['count']);
        self::assertSame(2, $today['stats']['currentCount']);
        self::assertSame(0, $today['stats']['previousCount']);
        self::assertSame(2.0, $today['stats']['averagePerWindow']);
        $window = $this->activity->snapshot($this->browser, 'window', 15, 'all', $now);
        self::assertSame(2, $window['stats']['count']);
        self::assertSame(1, $window['stats']['previousCount']);
        self::assertSame(0, $this->activity->snapshot($this->browser, now: $now)['stats']['count']);
    }

    #[DataProvider('sourceZoneDays')]
    public function testGlobalDaysParseStorageZoneAndExplicitOffsetsAcrossDst(string $zone, string $now, array $dates): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set($zone);
        try {
            foreach ($dates as $index => $date) {
                $finding = $this->intake->createFinding('https://dst.example.test/'.$index);
                $this->entityManager->getConnection()->executeStatement('UPDATE finding SET submitted_at = ? WHERE id = ?', [$date, $finding->getId()]);
            }
            $snapshot = $this->activity->snapshot($this->browser, 'today', 15, 'session', new \DateTimeImmutable($now));
            self::assertSame(4, $snapshot['stats']['count']);
            self::assertNull($snapshot['activeSession']);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public static function sourceZoneDays(): iterable
    {
        yield 'UTC autumn, repeated Berlin hour' => ['UTC', '2026-10-25T23:59:59+01:00', [
            '2026-10-24 21:59:59', '2026-10-24 22:00:00', '2026-10-25 00:30:00',
            '2026-10-25 01:30:00', '2026-10-25 22:59:59', '2026-10-25 23:00:00',
        ]];
        yield 'Berlin autumn, explicit offset' => ['Europe/Berlin', '2026-10-25T23:59:59+01:00', [
            '2026-10-24 23:59:59', '2026-10-25 00:00:00', '2026-10-25 02:30:00',
            '2026-10-25 02:30:00+02:00', '2026-10-25 23:59:59', '2026-10-26 00:00:00',
        ]];
        yield 'Berlin spring, nonexistent hour' => ['Europe/Berlin', '2026-03-29T23:59:59+02:00', [
            '2026-03-28 23:59:59', '2026-03-29 00:00:00', '2026-03-29 02:30:00',
            '2026-03-29 03:30:00', '2026-03-29 23:59:59', '2026-03-30 00:00:00',
        ]];
        yield 'New York spring, different changeover' => ['America/New_York', '2026-03-29T23:59:59+02:00', [
            '2026-03-28 18:59:59', '2026-03-28 19:00:00', '2026-03-28 20:30:00',
            '2026-03-28 21:30:00', '2026-03-29 17:59:59', '2026-03-29 18:00:00',
        ]];
    }

    public function testApiOptInCsrfReadOnlyAndStaleMutationContracts(): void
    {
        $home = $this->request('/');
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $home->getContent(), $match));
        $token = html_entity_decode($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $initial = $this->request('/api/intake/activity');
        self::assertSame(200, $initial->getStatusCode());
        self::assertStringContainsString('no-store', $initial->headers->get('Cache-Control'));
        self::assertNull($this->json($initial)['activeSession']);
        $badCsrf = $this->request('/api/findings', ['url' => 'https://api.example.test/csrf', 'browserIntake' => true]);
        self::assertSame(403, $badCsrf->getStatusCode());
        $invalid = $this->request('/api/findings', ['_token' => $token, 'url' => 'bad', 'browserIntake' => true]);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM intake_session'));
        $stored = $this->json($this->request('/api/findings', ['_token' => $token, 'url' => 'https://api.example.test/a', 'browserIntake' => true]));
        self::assertIsString($stored['intakeSessionId']);
        self::assertSame(1, $stored['intakeSessionGeneration']);
        $duplicate = $this->json($this->request('/api/findings', ['_token' => $token, 'url' => 'https://api.example.test/a', 'browserIntake' => '1']));
        self::assertSame('duplicate', $duplicate['outcome']);
        self::assertSame($stored['intakeSessionId'], $duplicate['intakeSessionId']);
        $unflagged = $this->json($this->request('/api/findings', ['_token' => $token, 'url' => 'https://api.example.test/import']));
        self::assertArrayNotHasKey('intakeSessionId', $unflagged);
        $state = $this->json($this->request('/api/intake/activity'));
        self::assertSame(1, $state['stats']['count']);
        $before = $this->rows();
        foreach (['period[]=session', 'minutes=99', 'history=bad'] as $query) {
            self::assertSame(422, $this->request('/api/intake/activity?'.$query)->getStatusCode());
        }
        self::assertSame($before, $this->rows());
        $action = ['_token' => $token, 'action' => 'end', 'sessionId' => $stored['intakeSessionId'], 'expectedSessionGeneration' => $state['sessionGeneration']];
        self::assertSame(403, $this->request('/api/intake/activity', [...$action, '_token' => 'forged'])->getStatusCode());
        self::assertSame(200, $this->request('/api/intake/activity', $action)->getStatusCode());
        self::assertSame(409, $this->request('/api/intake/activity', $action)->getStatusCode());
        $newBrowser = new Session(new MockArraySessionStorage());
        $this->session = $newBrowser;
        $other = $this->json($this->request('/api/intake/activity?history=all'));
        self::assertNull($other['activeSession']);
        self::assertSame(0, $other['stats']['count']);
        self::assertCount(2, $other['history']['findings']);
    }

    public function testMigrationMatchesMappingWithoutBackfillingHistoricalFindings(): void
    {
        $this->intake->createFinding('https://migration.example.test/old');
        $db = $this->entityManager->getConnection();
        $before = $db->fetchAllAssociative('SELECT rowid,* FROM finding');
        foreach (['intake_session_entry', 'intake_session', 'intake_browser'] as $table) { $db->executeStatement('DROP TABLE '.$table); }
        require_once dirname(__DIR__).'/migrations/Version20261018000000.php';
        $migration = new \DoctrineMigrations\Version20261018000000($db, new NullLogger());
        $migration->up(new Schema());
        $db->transactional(function () use ($migration, $db): void {
            foreach ($migration->getSql() as $sql) { $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes()); }
        });
        self::assertSame($before, $db->fetchAllAssociative('SELECT rowid,* FROM finding'));
        foreach (['intake_session_entry', 'intake_session', 'intake_browser'] as $table) { self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table)); }
        $diff = (new SchemaTool($this->entityManager))->getUpdateSchemaSql($this->entityManager->getMetadataFactory()->getAllMetadata());
        self::assertSame([], $diff, implode("\n", $diff));
        $this->expectException(\Doctrine\Migrations\Exception\AbortMigration::class);
        $migration->down(new Schema());
    }

    private function capture(string $url, \DateTimeImmutable $now): array
    {
        return $this->activity->capture($this->browser, fn (callable $recorder) => $this->intake->createIntakeFinding($url, onResult: $recorder), $now);
    }

    private function rows(): array
    {
        $result = [];
        foreach (['intake_browser', 'intake_session', 'intake_session_entry'] as $table) { $result[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table); }
        return $result;
    }

    private function request(string $path, ?array $payload = null): Response
    {
        $request = Request::create($path, $payload !== null ? 'POST' : 'GET', server: ['CONTENT_TYPE' => 'application/json'], content: $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : null);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        return $response;
    }

    private function json(Response $response): array
    {
        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
