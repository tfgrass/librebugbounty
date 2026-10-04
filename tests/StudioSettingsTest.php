<?php

namespace App\Tests;

use App\AppInfo;
use App\Service\SettingsService;
use App\Service\UiTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StudioSettingsTest extends DatabaseTestCase
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
            self::assertSame('0', $xpath->evaluate('string(//select[@name="review_decision_delay_seconds"]/option[@selected]/@value)'));
            self::assertSame(3, $xpath->query('//select[@name="review_decision_delay_seconds"]/option')->length);
            self::assertSame(1, $xpath->query('//label[@for="settings-review-decision-delay"]')->length);
            self::assertSame('settings-review-decision-delay-hint', $xpath->evaluate('string(//select[@name="review_decision_delay_seconds"]/@aria-describedby)'));
            self::assertSame('10', $xpath->evaluate('string(//select[@name="inventory_page_size"]/option[@selected]/@value)'));
            self::assertSame('report', $xpath->evaluate('string(//select[@name="export_profile"]/option[@selected]/@value)'));
            self::assertSame('latest', $xpath->evaluate('string(//select[@name="export_screenshot_mode"]/option[@selected]/@value)'));
            self::assertSame(0, $xpath->query('//select[@name="inventory_page_size"]/option[@value="all"]')->length);
            self::assertSame(4, $xpath->query('//fieldset/legend')->length);
            self::assertSame(AppInfo::HOMEPAGE, $xpath->evaluate('string(//a[normalize-space(.)="'.AppInfo::AUTHOR.' ↗"]/@href)'));
            self::assertSame(AppInfo::OPENBUGBOUNTY_URL, $xpath->evaluate('string(//a[contains(normalize-space(.),"'.AppInfo::OPENBUGBOUNTY_PROFILE.'")]/@href)'));
            self::assertStringContainsString(AppInfo::VERSION, $xpath->evaluate('string(//body)'));
            self::assertStringContainsString(AppInfo::LICENSE, $xpath->evaluate('string(//body)'));
            self::assertSame(0, $xpath->query('//a[starts-with(@href,"/legacy")]')->length);
            self::assertSame(1, $xpath->query('//a[@href="/settings" and @aria-current="page" and contains(concat(" ", normalize-space(@class), " "), " studio-settings-link-active ")]')->length);
        }
        self::assertSame($before, $this->rows());
    }

    public static function aboutLocales(): iterable
    {
        yield 'German' => ['de'];
        yield 'English' => ['en'];
    }

    #[DataProvider('aboutLocales')]
    public function testNamedReleaseAndAboutHighlightsRenderInBothLanguagesWithoutChangingSettings(string $locale): void
    {
        self::getContainer()->set(UiTranslator::class, new UiTranslator($locale));
        $before = $this->rows();
        $response = $this->request('/settings');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $xpath = $this->xpath($response->getContent());
        self::assertSame($locale, $xpath->evaluate('string(/html/@lang)'));
        self::assertSame(AppInfo::RELEASE_NAME, trim($xpath->evaluate('string(//*[contains(concat(" ", normalize-space(@class), " "), " studio-brand-tag ")])')));
        self::assertStringContainsString(AppInfo::RELEASE_NAME, $xpath->evaluate('string(//title)'));
        self::assertSame(1, $xpath->query('//*[@id="about"]//*[contains(concat(" ", normalize-space(@class), " "), " studio-about-logo ")]')->length);
        self::assertSame(1, $xpath->query('//*[@id="about"]//*[@data-about-release]')->length);
        $release = $xpath->evaluate('string(//*[@data-about-release])');
        self::assertStringContainsString(AppInfo::NAME, $release);
        self::assertStringContainsString('v'.AppInfo::VERSION, $release);
        self::assertStringContainsString(AppInfo::RELEASE_NAME, $release);
        self::assertSame(5, $xpath->query('//*[@id="about"]//*[@data-release-notes]//li')->length);
        foreach ($xpath->query('//*[@data-release-notes]//li') as $highlight) {
            self::assertNotSame('', trim($highlight->textContent));
        }
        foreach ([AppInfo::HOMEPAGE, AppInfo::FLICKR_URL, AppInfo::OPENBUGBOUNTY_URL, AppInfo::REPOSITORY] as $href) {
            self::assertSame(1, $xpath->query('//*[@id="about"]//a[@href="'.$href.'" and @target="_blank" and contains(@rel,"noopener")]')->length);
        }
        self::assertSame(1, $xpath->query('//*[@id="about"]//a[@href="/docs/changelog" and not(@target)]')->length);
        foreach (['usage', 'readme', 'backup', 'changelog', 'roadmap'] as $page) {
            self::assertSame(1, $xpath->query('//*[@data-documentation-links]//a[@href="/docs/'.$page.'"]')->length);
        }
        self::assertStringContainsString(AppInfo::LICENSE, $xpath->evaluate('string(//*[@id="about"])'));
        self::assertStringContainsString('Proudly vibe-coded.', $xpath->evaluate('string(//*[@id="about"])'));
        self::assertSame('/settings', $xpath->evaluate('string(//form[@method="post"]/@action)'));
        self::assertSame(SettingsService::DEFAULTS['intake.default_payload'], $xpath->evaluate('string(//input[@name="default_payload"]/@value)'));
        self::assertSame($before, $this->rows());
    }

    public function testInvalidSubmissionRetainsEveryInputAndDoesNotPartiallySave(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $settings->save([
            'intake.default_payload' => 'ORIGINAL',
            'review.scan_timeout_ms' => '45000',
            'review.decision_delay_seconds' => '3',
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
                'review_decision_delay_seconds' => '5',
            ]);
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
            $xpath = $this->xpath($response->getContent());
            self::assertSame($payload, $xpath->evaluate('string(//input[@name="default_payload"]/@value)'));
            self::assertSame($timeout, $xpath->evaluate('string(//input[@name="review_timeout_ms"]/@value)'));
            self::assertSame('5', $xpath->evaluate('string(//select[@name="review_decision_delay_seconds"]/option[@selected]/@value)'));
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
        $settings = self::getContainer()->get(SettingsService::class);
        $settings->save([
            'review.decision_delay_seconds' => '3',
            'inventory.page_size' => '25',
            'export.default_profile' => 'urls',
            'export.screenshot_mode' => 'none',
        ]);
        $response = $this->request('/settings', 'POST', [
            'default_payload' => '  RELEASE-MARKER  ',
            'review_timeout_ms' => '120000',
        ]);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/settings', parse_url($response->headers->get('Location'), PHP_URL_PATH));
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertArrayHasKey('message', $query);
        self::assertSame('RELEASE-MARKER', $settings->getDefaultPayload());
        self::assertSame(120000, $settings->getReviewScanTimeoutMs());
        self::assertSame(3, $settings->getReviewDecisionDelaySeconds());
        self::assertSame('25', $settings->getInventoryPageSize());
        self::assertSame('urls', $settings->getExportProfile());
        self::assertSame('none', $settings->getExportScreenshotMode());
        self::assertSame(200, $this->request($response->headers->get('Location'))->getStatusCode());
    }

    public function testDecisionPauseCanBeEnabledChangedAndDisabled(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        foreach (['3', '5', '0'] as $value) {
            $response = $this->request('/settings', 'POST', [
                'default_payload' => 'RELEASE-MARKER',
                'review_timeout_ms' => '45000',
                'review_decision_delay_seconds' => $value,
            ]);

            self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
            self::assertSame((int) $value, $settings->getReviewDecisionDelaySeconds());
            $xpath = $this->xpath($this->request('/settings')->getContent());
            self::assertSame($value, $xpath->evaluate('string(//select[@name="review_decision_delay_seconds"]/option[@selected]/@value)'));
        }
    }

    public function testInvalidDecisionPauseDoesNotSaveAnySettings(): void
    {
        self::getContainer()->get(SettingsService::class)->save([
            'intake.default_payload' => 'ORIGINAL',
            'review.scan_timeout_ms' => '45000',
            'review.decision_delay_seconds' => '3',
        ]);
        $before = $this->rows();

        foreach (['', '-1', '2', '3.5', '3e0', '03', 'invalid'] as $value) {
            $response = $this->request('/settings', 'POST', [
                'default_payload' => 'CHANGED',
                'review_timeout_ms' => '60000',
                'review_decision_delay_seconds' => $value,
            ]);

            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
            $xpath = $this->xpath($response->getContent());
            self::assertSame('CHANGED', $xpath->evaluate('string(//input[@name="default_payload"]/@value)'));
            self::assertSame('60000', $xpath->evaluate('string(//input[@name="review_timeout_ms"]/@value)'));
            self::assertSame($value, $xpath->evaluate('string(//select[@name="review_decision_delay_seconds"]/option[@selected]/@value)'));
            self::assertSame(1, $xpath->query('//select[@name="review_decision_delay_seconds" and @aria-invalid="true"]')->length);
            self::assertStringContainsString('settings-review-decision-delay-error', $xpath->evaluate('string(//select[@name="review_decision_delay_seconds"]/@aria-describedby)'));
            self::assertSame($before, $this->rows());
        }

        foreach ([['3'], null] as $value) {
            $response = $this->request('/settings', 'POST', [
                'default_payload' => 'CHANGED',
                'review_timeout_ms' => '60000',
                'review_decision_delay_seconds' => $value,
            ]);
            self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
            self::assertSame($before, $this->rows());
        }
    }

    #[DataProvider('aboutLocales')]
    public function testAllPreferencesSaveTogetherAndRenderAfterReloadInBothLanguages(string $locale): void
    {
        self::getContainer()->set(UiTranslator::class, new UiTranslator($locale));
        foreach ([['10', 'report', 'latest'], ['25', 'state', 'basis'], ['50', 'urls', 'all'], ['100', 'report', 'none']] as [$size, $profile, $screenshots]) {
            $response = $this->request('/settings', 'POST', [
                'default_payload' => 'SAVED',
                'review_timeout_ms' => '60000',
                'review_decision_delay_seconds' => '5',
                'inventory_page_size' => $size,
                'export_profile' => $profile,
                'export_screenshot_mode' => $screenshots,
            ]);
            self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
            $saved = array_column($this->rows(), 'value', 'id');
            self::assertSame([
                'export.default_profile' => $profile,
                'export.screenshot_mode' => $screenshots,
                'intake.default_payload' => 'SAVED',
                'inventory.page_size' => $size,
                'review.decision_delay_seconds' => '5',
                'review.scan_timeout_ms' => '60000',
            ], $saved);
            $xpath = $this->xpath($this->request('/settings')->getContent());
            foreach (['inventory_page_size' => $size, 'export_profile' => $profile, 'export_screenshot_mode' => $screenshots] as $field => $value) {
                self::assertSame($value, $xpath->evaluate('string(//select[@name="'.$field.'"]/option[@selected]/@value)'));
                self::assertSame(1, $xpath->query('//select[@name="'.$field.'"]/option[@selected]')->length);
            }
            self::assertSame($locale === 'de' ? 'Fälle pro Seite' : 'Cases per page', trim($xpath->evaluate('string(//label[@for="settings-inventory-page-size"])')));
            self::assertSame($locale === 'de' ? 'Bevorzugte Exportvorlage' : 'Preferred export preset', trim($xpath->evaluate('string(//label[@for="settings-export-profile"])')));
        }
    }

    public function testInvalidPreferencesRetainAllInputsAndNeverPartiallySave(): void
    {
        self::getContainer()->get(SettingsService::class)->save(SettingsService::DEFAULTS);
        $before = $this->rows();
        $valid = [
            'default_payload' => 'CHANGED',
            'review_timeout_ms' => '60000',
            'review_decision_delay_seconds' => '3',
            'inventory_page_size' => '25',
            'export_profile' => 'state',
            'export_screenshot_mode' => 'none',
        ];
        foreach ([
            'inventory_page_size' => ['', 'all', '0', '13', '025', '25.0'],
            'export_profile' => ['', 'zip', 'REPORT'],
            'export_screenshot_mode' => ['', 'assessment', 'LATEST'],
        ] as $field => $invalidValues) {
            foreach ($invalidValues as $invalid) {
                $submitted = array_replace($valid, [$field => $invalid]);
                $response = $this->request('/settings', 'POST', $submitted);
                self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
                $xpath = $this->xpath($response->getContent());
                foreach (['inventory_page_size', 'export_profile', 'export_screenshot_mode'] as $input) {
                    self::assertSame($submitted[$input], $xpath->evaluate('string(//select[@name="'.$input.'"]/option[@selected]/@value)'));
                }
                self::assertSame('CHANGED', $xpath->evaluate('string(//input[@name="default_payload"]/@value)'));
                self::assertSame(1, $xpath->query('//select[@name="'.$field.'" and @aria-invalid="true"]')->length);
                $describedBy = $xpath->evaluate('string(//select[@name="'.$field.'"]/@aria-describedby)');
                foreach (explode(' ', $describedBy) as $id) {
                    self::assertSame(1, $xpath->query('//*[@id="'.$id.'"]')->length);
                }
                self::assertSame($before, $this->rows());
            }
            foreach ([null, ['25']] as $invalid) {
                self::assertSame(Response::HTTP_BAD_REQUEST, $this->request('/settings', 'POST', array_replace($valid, [$field => $invalid]))->getStatusCode());
                self::assertSame($before, $this->rows());
            }
        }
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
