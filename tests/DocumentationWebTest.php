<?php

namespace App\Tests;

use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class DocumentationWebTest extends DatabaseTestCase
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
    }

    public function testDocumentationHasLocalizedNavigationAndEnglishContentsWithoutSettingsWrites(): void
    {
        $before = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM setting ORDER BY id');
        $redirect = $this->request('/docs');
        self::assertSame(302, $redirect->getStatusCode());
        self::assertSame('/docs/usage', $redirect->headers->get('Location'));
        foreach (['en', 'de'] as $locale) {
            foreach (['usage', 'readme', 'backup', 'changelog', 'roadmap'] as $slug) {
                $path = '/docs/'.$slug;
                $response = $this->request($path, $locale);
                self::assertSame(200, $response->getStatusCode(), $path);
                self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
                self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
                $xpath = $this->xpath($response->getContent());
                self::assertSame($locale, $xpath->evaluate('string(/html/@lang)'));
                self::assertSame('en', $xpath->evaluate('string(//article/@lang)'));
                self::assertSame($slug, $xpath->evaluate('string(//article/@data-documentation-page)'));
                self::assertSame(5, $xpath->query('//aside//nav/a')->length);
                self::assertSame($path, $xpath->evaluate('string(//aside//nav/a[@aria-current="page"]/@href)'));
                self::assertSame($locale === 'de' ? 'Dokumentation' : 'Documentation', trim($xpath->evaluate('string(//aside/h1)')));
                foreach (['de', 'en'] as $destinationLocale) {
                    $switch = $xpath->evaluate('string(//*[@data-language-switcher]//a[starts-with(@href,"/language/'.$destinationLocale.'?")]/@href)');
                    parse_str((string) parse_url($switch, PHP_URL_QUERY), $query);
                    self::assertSame($path, $query['return'] ?? null);
                }
            }
            $guide = $this->xpath($this->request('/docs/usage', $locale)->getContent());
            self::assertSame('LibreBugBounty user guide', trim($guide->evaluate('string(//article/h1)')));
            self::assertSame(1, $guide->query('//article/a[@href="/docs/readme#quick-start"]|//article//a[@href="/docs/readme#quick-start"]')->length);
        }
        self::assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM setting ORDER BY id'));
    }

    public function testOnlyPublishedDocumentsAndKnownScreenshotFilesCanBeRequested(): void
    {
        foreach (['review.png', 'inventory.png', 'finding-detail.png', 'statistics.png', 'intake.png', 'export.png', 'about.png'] as $image) {
            $response = $this->request('/docs/images/'.$image);
            self::assertSame(200, $response->getStatusCode(), $image);
            self::assertInstanceOf(BinaryFileResponse::class, $response);
            self::assertSame('image/png', $response->headers->get('Content-Type'));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertSame(realpath(dirname(__DIR__).'/docs/screenshots/'.$image), $response->getFile()->getRealPath());
        }
        foreach (['/docs/architecture', '/docs/README.md', '/docs/.env', '/docs/../architecture/design.md', '/docs/%2e%2e%2fREADME.md', '/docs/images/private.png', '/docs/images/../README.md', '/docs/images/%2e%2e%2fREADME.md', '/docs/images/database.sqlite'] as $path) {
            self::assertSame(404, $this->request($path)->getStatusCode(), $path);
        }
    }

    private function request(string $path, string $locale = 'en'): Response
    {
        $request = Request::create($path, cookies: [UiTranslator::COOKIE_NAME => $locale]);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }
}
