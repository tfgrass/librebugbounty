<?php

namespace App\Tests;

use App\AppInfo;
use App\Service\SettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StudioSettingsTest extends DatabaseTestCase
{
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testCanonicalAndCachedLegacySettingsPathsRenderTheSameStudioPageWithoutWrites(): void
    {
        $before = $this->rows();
        foreach (['/settings', '/legacy/settings'] as $path) {
            $response = $this->request($path);
            self::assertSame(Response::HTTP_OK, $response->getStatusCode());
            self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $xpath = $this->xpath($response->getContent());
            self::assertSame(1, $xpath->query('//body[@data-studio-settings]')->length);
            self::assertSame('/settings', $xpath->evaluate('string(//form[@method="post"]/@action)'));
            self::assertSame(SettingsService::DEFAULTS['intake.default_payload'], $xpath->evaluate('string(//input[@name="default_payload"]/@value)'));
            self::assertSame(SettingsService::DEFAULTS['review.scan_timeout_ms'], $xpath->evaluate('string(//input[@name="review_timeout_ms"]/@value)'));
            self::assertSame(AppInfo::HOMEPAGE, $xpath->evaluate('string(//a[normalize-space(.)="'.AppInfo::AUTHOR.' ↗"]/@href)'));
            self::assertSame(AppInfo::OPENBUGBOUNTY_URL, $xpath->evaluate('string(//a[contains(normalize-space(.),"'.AppInfo::OPENBUGBOUNTY_PROFILE.'")]/@href)'));
            self::assertStringContainsString(AppInfo::VERSION, $xpath->evaluate('string(//body)'));
            self::assertStringContainsString(AppInfo::LICENSE, $xpath->evaluate('string(//body)'));
            self::assertSame(0, $xpath->query('//a[starts-with(@href,"/legacy")]')->length);
            self::assertSame(1, $xpath->query('//a[@href="/settings" and @aria-current="page" and contains(concat(" ", normalize-space(@class), " "), " studio-settings-link-active ")]')->length);
        }
        self::assertSame($before, $this->rows());
    }

    public function testInvalidSubmissionRetainsEveryInputAndDoesNotPartiallySave(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $settings->save([
            'intake.default_payload' => 'ORIGINAL',
            'review.scan_timeout_ms' => '45000',
        ]);
        $before = $this->rows();

        foreach ([
            ['NEW-PAYLOAD', '999'],
            ['NEW-PAYLOAD', '120001'],
            ['NEW-PAYLOAD', '12.5'],
            ['', '60000'],
        ] as [$payload, $timeout]) {
            $response = $this->request('/settings', 'POST', [
                'default_payload' => $payload,
                'review_timeout_ms' => $timeout,
            ]);
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
            $xpath = $this->xpath($response->getContent());
            self::assertSame($payload, $xpath->evaluate('string(//input[@name="default_payload"]/@value)'));
            self::assertSame($timeout, $xpath->evaluate('string(//input[@name="review_timeout_ms"]/@value)'));
            self::assertGreaterThanOrEqual(1, $xpath->query('//*[@aria-invalid="true"]')->length);
            self::assertSame($before, $this->rows());
        }

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->request('/settings', 'POST', [
            'default_payload' => ['ARRAY'], 'review_timeout_ms' => '45000',
        ])->getStatusCode());
        self::assertSame($before, $this->rows());
    }

    public function testHistoricalTimeoutValuesAreDisplayedWithinTheEffectiveLimits(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);

        foreach ([['999', '1000'], ['120001', '120000']] as [$stored, $displayed]) {
            $settings->save(['review.scan_timeout_ms' => $stored]);
            $xpath = $this->xpath($this->request('/settings')->getContent());

            self::assertSame($displayed, $xpath->evaluate('string(//input[@name="review_timeout_ms"]/@value)'));
        }
    }

    public function testValidSubmissionSavesBothValuesTogetherAndUsesPostRedirectGet(): void
    {
        $response = $this->request('/settings', 'POST', [
            'default_payload' => '  RELEASE-MARKER  ',
            'review_timeout_ms' => '120000',
        ]);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/settings', parse_url($response->headers->get('Location'), PHP_URL_PATH));
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertArrayHasKey('message', $query);
        $settings = self::getContainer()->get(SettingsService::class);
        self::assertSame('RELEASE-MARKER', $settings->getDefaultPayload());
        self::assertSame(120000, $settings->getReviewScanTimeoutMs());
        self::assertSame(200, $this->request($response->headers->get('Location'))->getStatusCode());
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

    private function rows(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM setting ORDER BY id');
    }
}
