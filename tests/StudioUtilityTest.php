<?php

namespace App\Tests;

use App\AppInfo;
use App\Service\BrowserHealthService;
use App\Service\UiTranslator;
use App\Service\WorkerHeartbeatService;
use App\Service\WorkerHealthRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StudioUtilityTest extends DatabaseTestCase
{
    private Session $session;

    protected function usesGermanCopy(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testSettingsAndAboutNeverProbeHealthOnGetOrPostIncludingValidationErrors(): void
    {
        $client = $this->mockBrowsers();
        // Any attempted queue snapshot must also fail, even if browser checks
        // were accidentally removed from that snapshot in the future.
        $this->entityManager->getConnection()->executeStatement('DROP TABLE screenshot_job');

        foreach (['/settings', '/legacy/settings', '/about', '/about?fragment=1'] as $path) {
            self::assertSame(Response::HTTP_OK, $this->request($path)->getStatusCode(), $path);
        }
        foreach ([
            [[], Response::HTTP_BAD_REQUEST],
            [['default_payload' => '', 'review_timeout_ms' => '999'], Response::HTTP_UNPROCESSABLE_ENTITY],
            [['default_payload' => 'SAVED', 'review_timeout_ms' => '45000'], Response::HTTP_FOUND],
        ] as [$parameters, $status]) {
            self::assertSame($status, $this->request('/settings', 'POST', $parameters)->getStatusCode());
        }
        self::assertSame(0, $client->getRequestsCount());
    }

    public static function utilityLocales(): iterable
    {
        foreach (['de', 'en'] as $locale) {
            foreach (['about', 'status'] as $utility) {
                yield $utility.' '.$locale => [$utility, $locale];
            }
        }
    }

    #[DataProvider('utilityLocales')]
    public function testDirectPagesHaveShellWhileFragmentsOnlyContainUtilityContent(string $utility, string $locale): void
    {
        self::getContainer()->set(UiTranslator::class, new UiTranslator($locale));
        $client = $this->mockBrowsers();
        $before = $this->databaseRows();
        $direct = $this->request('/'.$utility);
        $fragment = $this->request('/'.$utility.'?fragment=1');
        foreach ([$direct, $fragment] as $response) {
            self::assertSame(Response::HTTP_OK, $response->getStatusCode());
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            self::assertStringContainsString('text/html', $response->headers->get('Content-Type'));
            $xpath = $this->xpath($response->getContent());
            self::assertSame(1, $xpath->query('//*[@data-utility-content="'.$utility.'" and @data-utility-title]')->length);
            self::assertNotSame('', $xpath->evaluate('string(//*[@data-utility-content]/@data-utility-title)'));
            self::assertSame(0, $xpath->query('//*[@data-restrictions] | //form[@action="/settings"]')->length);
        }
        $directXpath = $this->xpath($direct->getContent());
        self::assertSame($locale, $directXpath->evaluate('string(/html/@lang)'));
        self::assertSame(1, $directXpath->query('//body[@data-studio-settings]')->length);
        self::assertSame(1, $directXpath->query('//nav[contains(@class,"studio-workspace-nav")]')->length);
        self::assertSame(1, $directXpath->query('//link[@href="/css/studio-settings.css"]')->length);
        self::assertSame(
            $directXpath->evaluate('string(//*[@data-utility-content]/@data-utility-title)'),
            $directXpath->evaluate('string(//main//h1)'),
        );

        $html = $fragment->getContent();
        self::assertStringStartsWith('<section ', trim($html));
        foreach (['<!doctype', '<html', '<head>', '<body', '<main', '<script', '<link', 'studio-workspace-nav', 'studio-shell', 'data-language-switcher'] as $shellMarkup) {
            self::assertStringNotContainsString($shellMarkup, $html);
        }
        $xpath = $this->xpath($html);
        self::assertSame(1, $xpath->query('/html/body/*')->length, 'Fragment has exactly one root element.');
        if ($utility === 'about') {
            self::assertStringContainsString(AppInfo::NAME, $xpath->evaluate('string(//*[@data-utility-title]/@data-utility-title)'));
            self::assertStringContainsString(AppInfo::VERSION, $html);
            self::assertSame(0, $xpath->query('//*[@data-utility-warning or @data-health-kind]')->length);
            self::assertSame(0, $client->getRequestsCount());
        } else {
            self::assertSame(2, $xpath->query('//*[@data-health-kind]')->length);
            self::assertSame('/status', $xpath->evaluate('string(//a[contains(@class,"studio-health-refresh")]/@href)'));
            self::assertSame(0, $xpath->query('//*[@data-health-kind]//details')->length);
            self::assertSame(8, $xpath->query('//*[@data-health-worker and not(ancestor::details)]')->length);
            self::assertSame(4, $xpath->query('//meter[@data-health-meter and @aria-label]')->length);
            self::assertSame(2, $xpath->query('//*[@data-health-errors="technical" or @data-health-errors="screenshot"][not(ancestor::details)]')->length);
            $registry = self::getContainer()->get(WorkerHealthRegistry::class);
            self::assertSame(2 * count($registry->browserUrls()), $client->getRequestsCount());
        }
        self::assertSame($before, $this->databaseRows(), 'Both utility responses are read-only.');
    }

    public static function healthStates(): iterable
    {
        yield 'healthy' => ['ok', 4, 'now', true, 'false'];
        yield 'no signals' => ['unknown', 0, 'now', true, 'true'];
        yield 'expired signals' => ['stale', 4, '-11 minutes', true, 'true'];
        yield 'one fresh lane' => ['degraded', 1, 'now', true, 'true'];
        yield 'browser unavailable despite fresh workers' => ['degraded', 4, 'now', false, 'true'];
    }

    #[DataProvider('healthStates')]
    public function testStatusWarningUsesActualHealthGroupStates(string $state, int $workers, string $time, bool $browsersReachable, string $warning): void
    {
        $this->mockBrowsers($browsersReachable);
        $heartbeats = self::getContainer()->get(WorkerHeartbeatService::class);
        $registry = self::getContainer()->get(WorkerHealthRegistry::class);
        foreach ([WorkerHeartbeatService::RECHECK, WorkerHeartbeatService::SCREENSHOT] as $kind) {
            foreach (array_slice(array_keys($registry->forKind($kind)), 0, $workers) as $id) {
                $heartbeats->touch($kind, new \DateTimeImmutable($time), $id);
            }
        }
        $before = $this->databaseRows();
        foreach (['/status', '/status?fragment=1'] as $path) {
            $response = $this->request($path);
            self::assertSame(Response::HTTP_OK, $response->getStatusCode());
            $xpath = $this->xpath($response->getContent());
            self::assertSame($warning, $xpath->evaluate('string(//*[@data-utility-content]/@data-utility-warning)'));
            self::assertSame(2, $xpath->query('//*[@data-health-state="'.$state.'"]')->length);
            self::assertSame($warning, $xpath->evaluate('string(//*[@data-service-warning]/@data-service-warning)'));
            $active = $time === 'now' ? $workers : 0;
            self::assertSame(2, $xpath->query('//meter[@data-health-meter="workers" and @value="'.$active.'" and @max="4"]')->length);
            foreach (['recheck' => 1, 'screenshot' => 4] as $kind => $expected) {
                self::assertSame((string) $expected, $xpath->evaluate('string(//*[@data-health-kind="'.$kind.'"]//meter[@data-health-meter="browsers"]/@max)'));
                self::assertSame((string) ($browsersReachable ? $expected : 0), $xpath->evaluate('string(//*[@data-health-kind="'.$kind.'"]//meter[@data-health-meter="browsers"]/@value)'));
            }
        }
        self::assertSame($before, $this->databaseRows());
    }

    private function mockBrowsers(bool $reachable = true): MockHttpClient
    {
        $client = new MockHttpClient(static fn () => new MockResponse($reachable ? '{"ok":true}' : '{"ok":false}'));
        self::getContainer()->set(BrowserHealthService::class, new BrowserHealthService($client, self::getContainer()->get(WorkerHealthRegistry::class)));

        return $client;
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    private function databaseRows(): array
    {
        $connection = $this->entityManager->getConnection();
        $rows = [];
        foreach (['setting', 'finding', 'screenshot_job'] as $table) {
            $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $rows;
    }
}
