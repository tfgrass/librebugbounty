<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\UiTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StudioLanguageWebTest extends DatabaseTestCase
{
    private Session $session;

    protected function usesGermanCopy(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::getContainer()->set(UiTranslator::class, new UiTranslator(null, self::getContainer()->get(RequestStack::class)));
        $this->session = new Session(new MockArraySessionStorage());
        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);
        $screenshot = $this->createMock(BrowserScreenshotClientInterface::class);
        $screenshot->expects(self::never())->method('capture');
        $screenshot->expects(self::never())->method('waitUntilReady');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $screenshot);
    }

    public function testBrowserStartsInEnglishAndBothNativeLanguageLinksPreserveTheirPage(): void
    {
        $before = $this->snapshot();
        foreach (['/', '/findings?q=missing&scope=all&pageSize=25', '/review?images=all', '/statistics?period=year&anchor=2026-10-04', '/export?profile=urls&scope=all', '/settings'] as $path) {
            $response = $this->request($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            $xpath = $this->xpath($response->getContent());
            self::assertSame('en', $xpath->evaluate('string(/html/@lang)'), $path);
            self::assertSame(1, $xpath->query('//*[@data-language-switcher]')->length, $path);
            foreach (['de', 'en'] as $locale) {
                $link = $xpath->evaluate('string(//*[@data-language-switcher]//a[starts-with(@href,"/language/'.$locale.'?")]/@href)');
                self::assertNotSame('', $link, $path.' '.$locale);
                parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
                self::assertSame($path, $query['return'] ?? null, $path.' '.$locale);
            }
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testSwitchingSetsPersistentBrowserCookieAndKeepsFiltersAndDetailReturnContext(): void
    {
        $finding = $this->finding();
        $before = $this->snapshot();
        $inventory = '/findings?scope=all&q=language&contact=no&pageSize=25';
        $detail = '/findings/'.$finding->getId().'?'.http_build_query(['return_to' => $inventory]);
        foreach ([$inventory, $detail, '/settings#about'] as $return) {
            $response = $this->request('/language/de?'.http_build_query(['return' => $return]));
            self::assertSame(303, $response->getStatusCode());
            self::assertSame($return, $response->headers->get('Location'));
            $cookies = $response->headers->getCookies();
            self::assertCount(1, $cookies);
            $cookie = $cookies[0];
            self::assertSame('lbb_locale', $cookie->getName());
            self::assertSame('de', $cookie->getValue());
            self::assertSame('/', $cookie->getPath());
            self::assertNull($cookie->getDomain());
            self::assertTrue($cookie->isHttpOnly());
            self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
            self::assertGreaterThan(time() + 86400, $cookie->getExpiresTime());
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $translated = $this->xpath($this->request($return, ['lbb_locale' => 'de'])->getContent());
            self::assertSame('de', $translated->evaluate('string(/html/@lang)'));
        }
        $detailPage = $this->xpath($this->request($detail, ['lbb_locale' => 'de'])->getContent());
        self::assertSame($inventory, $detailPage->evaluate('string(//a[contains(concat(" ",normalize-space(@class)," ")," studio-detail-back ")]/@href)'));
        $english = $this->request('/language/en?'.http_build_query(['return' => $detail]), ['lbb_locale' => 'de']);
        self::assertSame(303, $english->getStatusCode());
        self::assertSame('en', $english->headers->getCookies()[0]->getValue());
        self::assertSame('en', $this->xpath($this->request($detail, ['lbb_locale' => 'en'])->getContent())->evaluate('string(/html/@lang)'));
        self::assertSame($before, $this->snapshot(), 'Language changes are browser preferences, not stored application settings or work.');
    }

    public function testSharedWebTranslatorDoesNotCarryOneBrowserPreferenceIntoAnother(): void
    {
        $before = $this->snapshot();
        foreach ([['de', 'Fälle'], ['en', 'Cases'], [null, 'Cases'], ['de', 'Fälle'], ['invalid', 'Cases']] as [$locale, $heading]) {
            $response = $this->request('/findings', $locale === null ? [] : ['lbb_locale' => $locale]);
            $xpath = $this->xpath($response->getContent());
            self::assertSame($locale === 'de' ? 'de' : 'en', $xpath->evaluate('string(/html/@lang)'));
            self::assertSame($heading, trim($xpath->evaluate('string(//h1)')));
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testLanguageSwitchFromAReviewPostErrorReturnsToTheGetCardWithoutResubmitting(): void
    {
        $finding = $this->finding();
        $reviewPath = '/review?kind=unchecked&images=all';
        $form = $this->xpath($this->request($reviewPath)->getContent());
        $parameters = [];
        foreach ($form->query('//form[contains(@action,"/assessment")]//input[@name]') as $input) {
            $parameters[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $parameters['assessment'] = 'confirmed';
        $parameters['_token'] = 'invalid';
        $before = $this->snapshot();
        $error = $this->request('/review/'.$finding->getId().'/assessment', [], 'POST', $parameters);
        self::assertSame(403, $error->getStatusCode());
        $switch = $this->xpath($error->getContent())->evaluate('string(//*[@data-language-switcher]//a[starts-with(@href,"/language/de?")]/@href)');
        parse_str((string) parse_url($switch, PHP_URL_QUERY), $query);
        self::assertStringStartsWith('/review?', $query['return'] ?? '');
        self::assertStringNotContainsString('/assessment', $query['return']);
        parse_str((string) parse_url($query['return'], PHP_URL_QUERY), $selection);
        self::assertSame('unchecked', $selection['kind'] ?? null);
        self::assertSame('all', $selection['images'] ?? null);
        $redirect = $this->request($switch);
        self::assertSame(303, $redirect->getStatusCode());
        $translated = $this->request($redirect->headers->get('Location'), ['lbb_locale' => 'de']);
        self::assertSame(200, $translated->getStatusCode());
        self::assertSame('de', $this->xpath($translated->getContent())->evaluate('string(/html/@lang)'));
        self::assertStringContainsString('/review/'.$finding->getId().'/assessment', $translated->getContent());
        self::assertSame($before, $this->snapshot());
    }

    public static function unsafeReturns(): iterable
    {
        foreach (['https://example.com/', 'http://example.com/', '//example.com/', '\\example.com', '/\\example.com', '/%2fexample.com', '/%5cexample.com', '/%252fexample.com', "/settings\r\nLocation: https://example.com", '/settings%0a', '/settings%250d', '/settings%00', ['array']] as $return) {
            yield [ $return ];
        }
    }

    #[DataProvider('unsafeReturns')]
    public function testLanguageRedirectRejectsExternalOrAmbiguousReturnLocations(string|array $return): void
    {
        $before = $this->snapshot();
        $response = $this->request('/language/de?'.http_build_query(['return' => $return]));
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->headers->get('Location'));
        self::assertSame('de', $response->headers->getCookies()[0]->getValue());
        self::assertSame($before, $this->snapshot());
    }

    public function testUnsupportedLanguagesReturn404WithoutChangingPreference(): void
    {
        $before = $this->snapshot();
        foreach (['fr', 'DE', 'english', 'de-en'] as $locale) {
            $response = $this->request('/language/'.$locale.'?return=%2Fsettings', ['lbb_locale' => 'de']);
            self::assertSame(404, $response->getStatusCode(), $locale);
            self::assertSame([], $response->headers->getCookies(), $locale);
        }
        self::assertSame($before, $this->snapshot());
    }

    private function request(string $path, array $cookies = [], string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters, cookies: $cookies);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function finding(): Finding
    {
        $domain = (new Domain())->setHostname('language.test')->setScheme('https');
        $finding = (new Finding())->setDomain($domain)->setTitle('language fixture')->setType('synthetic')->setUrl('https://language.test/local-only');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['domain', 'finding', 'screenshot_job', 'retest_run', 'evidence', 'finding_assessment', 'finding_review_acknowledgement', 'setting'] as $table) $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        $snapshot['artifacts'] = [];
        $root = APP_TEST_ROOT.'/artifacts';
        if (is_dir($root)) foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) $snapshot['artifacts'][substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
        }
        ksort($snapshot['artifacts']);

        return $snapshot;
    }
}
