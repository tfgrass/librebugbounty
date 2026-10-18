<?php

namespace App\Tests;

use App\AppInfo;
use App\Service\ReleaseUpdateService;
use App\Service\UiTranslator;
use App\Tests\Support\ReleaseVersionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** Controller acceptance: About reads cached metadata; only a CSRF POST can check. */
final class StudioReleaseUpdateTest extends DatabaseTestCase
{
    private Session $session;
    private MockHttpClient $client;
    private ReleaseUpdateService $updates;
    private string $releaseCache;
    private int $releaseStatus = 200;
    private string $releaseTag;
    private string $locale = 'de';

    protected function usesGermanCopy(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->releaseTag = ReleaseVersionFixture::newerTag();
        $this->session = new Session(new MockArraySessionStorage());
        $this->releaseCache = APP_TEST_ROOT.'/controller-release-'.bin2hex(random_bytes(6));
        $this->client = new MockHttpClient(fn () => new MockResponse(json_encode([
            'tag_name' => $this->releaseTag,
            'draft' => false,
            'prerelease' => false,
            'html_url' => 'https://untrusted.example/ignored',
            'name' => '<script>remoteReleaseMustNeverRender()</script>',
        ], JSON_THROW_ON_ERROR), ['http_code' => $this->releaseStatus, 'response_headers' => ['content-type: application/json']]));
        $this->updates = new ReleaseUpdateService($this->client, $this->releaseCache);
        self::getContainer()->set(ReleaseUpdateService::class, $this->updates);
    }

    public function testAboutAndItsFragmentNeverCheckOrCreateReleaseCacheAndCheckRouteRejectsGet(): void
    {
        $before = $this->databaseRows();
        foreach (['/about', '/about?fragment=1'] as $path) {
            $response = $this->request($path);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $xpath = $this->xpath($response->getContent());
            self::assertSame(1, $xpath->query('//*[@data-release-update="unknown"]')->length);
            self::assertSame('/about/check-update', $xpath->evaluate('string(//form[@data-release-check-form]/@action)'));
            self::assertSame('post', $xpath->evaluate('string(//form[@data-release-check-form]/@method)'));
            self::assertNotSame('', $xpath->evaluate('string(//form[@data-release-check-form]//input[@name="_token"]/@value)'));
        }
        self::assertSame(405, $this->request('/about/check-update')->getStatusCode());
        self::assertSame(0, $this->client->getRequestsCount());
        self::assertDirectoryDoesNotExist($this->releaseCache, 'GET must not create or lock a release cache.');
        self::assertSame($before, $this->databaseRows());
    }

    public function testManualCheckRejectsMissingInvalidAndNonScalarTokensWithoutSideEffects(): void
    {
        $before = $this->databaseRows();
        foreach ([[], ['_token' => 'invalid'], ['_token' => ['invalid']]] as $parameters) {
            $response = $this->request('/about/check-update?fragment=1', 'POST', $parameters);
            self::assertSame(403, $response->getStatusCode());
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        self::assertSame(0, $this->client->getRequestsCount());
        self::assertDirectoryDoesNotExist($this->releaseCache);
        self::assertSame($before, $this->databaseRows());
    }

    public function testManualCheckRedirectsWithoutJavaScriptAndRendersOnlyValidatedReleaseData(): void
    {
        $before = $this->databaseRows();
        $token = $this->token();
        $response = $this->request('/about/check-update', 'POST', ['_token' => $token]);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/about', $response->headers->get('Location'));
        self::assertSame(1, $this->client->getRequestsCount());
        $html = $this->request('/about')->getContent();
        $this->assertAvailableInstructions($html);
        self::assertStringNotContainsString('remoteReleaseMustNeverRender', $html);
        self::assertStringNotContainsString('untrusted.example', $html);
        self::assertSame($before, $this->databaseRows(), 'A release check must not mutate findings, settings or jobs.');

        $fragment = $this->request('/about/check-update?fragment=1', 'POST', ['_token' => $token]);
        self::assertSame(200, $fragment->getStatusCode());
        self::assertStringStartsWith('<section ', trim($fragment->getContent()));
        self::assertStringNotContainsString('<html', $fragment->getContent());
        $this->assertAvailableInstructions($fragment->getContent());
        self::assertSame(1, $this->client->getRequestsCount(), 'Repeated manual checks respect the cooldown.');
    }

    public function testOfflineManualCheckKeepsPreviouslyValidatedCandidateAndInstructionsVisible(): void
    {
        $this->updates->check(force: true, now: new \DateTimeImmutable('-61 seconds'));
        $this->releaseStatus = 503;
        $response = $this->request('/about/check-update?fragment=1', 'POST', ['_token' => $this->token()]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $this->client->getRequestsCount());
        self::assertSame('error', $this->xpath($response->getContent())->evaluate('string(//*[@data-release-update]/@data-release-update)'));
        $this->assertAvailableInstructions($response->getContent());
        self::assertStringContainsString('fehlgeschlagen', $response->getContent());
        self::assertTrue($this->updates->snapshot()['stale']);
    }

    public function testRejectedReleaseTagNeverBecomesACommandOrLink(): void
    {
        $this->releaseTag = 'v2.1.1;echo <script>unsafe()</script>';
        $response = $this->request('/about/check-update?fragment=1', 'POST', ['_token' => $this->token()]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('error', $this->xpath($response->getContent())->evaluate('string(//*[@data-release-update]/@data-release-update)'));
        self::assertSame(0, $this->xpath($response->getContent())->query('//details[contains(@class,"studio-about-update-instructions")]')->length);
        self::assertStringNotContainsString('unsafe()', $response->getContent());
        self::assertStringNotContainsString('git switch', $response->getContent());
    }

    public static function cachedStates(): iterable
    {
        foreach (['de', 'en'] as $locale) {
            foreach (['current' => 'v'.AppInfo::VERSION, 'ahead' => 'v2.0.0', 'stale' => ReleaseVersionFixture::newerTag()] as $state => $tag) {
                yield $locale.' '.$state => [$locale, $state, $tag];
            }
        }
    }

    #[DataProvider('cachedStates')]
    public function testCachedStatesRenderInBothLocalesWithoutAnotherHttpRequest(string $locale, string $state, string $tag): void
    {
        $this->locale = $locale;
        $this->releaseTag = $tag;
        $this->updates->check(now: new \DateTimeImmutable($state === 'stale' ? '-2 days' : 'now'));
        $cacheBefore = file_get_contents($this->releaseCache.'/release.json');
        foreach (['/about', '/about?fragment=1'] as $path) {
            $response = $this->request($path);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame($state, $this->xpath($response->getContent())->evaluate('string(//*[@data-release-update]/@data-release-update)'));
            self::assertStringContainsString($locale === 'de' ? 'Jetzt prüfen' : 'Check now', $response->getContent());
        }
        self::assertSame(1, $this->client->getRequestsCount());
        self::assertSame($cacheBefore, file_get_contents($this->releaseCache.'/release.json'), 'Reading stale data must never refresh it.');
    }

    private function assertAvailableInstructions(string $html): void
    {
        $xpath = $this->xpath($html);
        $link = $xpath->query('//*[contains(@class,"studio-about-update-available")]/a')->item(0);
        self::assertNotNull($link);
        self::assertSame(AppInfo::REPOSITORY.'/releases/tag/'.ReleaseVersionFixture::newerTag(), $link->getAttribute('href'));
        self::assertSame('_blank', $link->getAttribute('target'));
        self::assertSame('noopener noreferrer', $link->getAttribute('rel'));
        self::assertStringContainsString(ReleaseVersionFixture::newerVersion(), $link->textContent);
        self::assertSame("git status --short\ngit fetch --tags origin\ngit switch --detach refs/tags/".ReleaseVersionFixture::newerTag(), $xpath->evaluate('string(//details[contains(@class,"studio-about-update-instructions")]//code)'));
        self::assertSame(1, $xpath->query('//a[@href="/docs/readme#upgrade-an-existing-workspace"]')->length);
    }

    private function token(): string
    {
        return $this->xpath($this->request('/about')->getContent())->evaluate('string(//form[@data-release-check-form]//input[@name="_token"]/@value)');
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->cookies->set(UiTranslator::COOKIE_NAME, $this->locale);
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
        foreach (['setting', 'finding', 'screenshot_job', 'retest_run', 'evidence'] as $table) {
            $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid');
        }

        return $rows;
    }
}
