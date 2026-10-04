<?php

namespace App\Tests;

use App\Service\UiTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class UiTranslatorTest extends TestCase
{
    public function testItSelectsGermanEnglishAndDefaultsToEnglishForUnsupportedLocales(): void
    {
        $german = new UiTranslator('de');
        $english = new UiTranslator('en');
        $unsupported = new UiTranslator('  fr-FR  ');

        self::assertSame('de', $german->locale());
        self::assertSame('Fälle', $german->trans('Fälle'));
        self::assertSame('en', $english->locale());
        self::assertSame('Cases', $english->trans('Fälle'));
        self::assertSame('en', $unsupported->locale());
        self::assertSame('Cases', $unsupported->trans('Fälle'));
        self::assertSame('en', (new UiTranslator())->locale());
    }

    public function testSharedTranslatorResolvesEveryRequestAndDoesNotKeepThePreviousBrowsersLanguage(): void
    {
        $stack = new RequestStack();
        $translator = new UiTranslator(null, $stack);
        foreach ([['de', 'Fälle', '1.234'], ['en', 'Cases', '1,234'], [null, 'Cases', '1,234'], ['de', 'Fälle', '1.234']] as [$cookie, $cases, $number]) {
            $request = Request::create('/', cookies: $cookie === null ? [] : ['lbb_locale' => $cookie]);
            $stack->push($request);
            try {
                self::assertSame($cookie === 'de' ? 'de' : 'en', $translator->locale());
                self::assertSame($cases, $translator->trans('Fälle'));
                self::assertSame($number, $translator->formatNumber(1234));
                $catalog = json_decode($translator->browserCatalogJson(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($translator->locale(), $catalog['locale']);
                self::assertSame($cases, $catalog['messages']['Fälle']);
            } finally {
                $stack->pop();
            }
        }
        self::assertSame('en', $translator->locale(), 'Console/no-request use returns the English default.');
    }

    public function testInvalidCookiesFallBackToEnglishAndExplicitTestLocaleStaysFixed(): void
    {
        $stack = new RequestStack();
        $translator = new UiTranslator(null, $stack);
        foreach (['fr', 'DE', ' de ', 'de-DE', '', ['de'], "de\0"] as $cookie) {
            $stack->push(Request::create('/', cookies: ['lbb_locale' => $cookie]));
            try {
                self::assertSame('en', $translator->locale());
                self::assertSame('Cases', $translator->trans('Fälle'));
                self::assertSame('de', (new UiTranslator('de', $stack))->locale(), 'Explicit locale overrides only support direct/unit-test use.');
            } finally {
                $stack->pop();
            }
        }
    }

    public function testNestedFragmentsKeepTheMainBrowserLanguage(): void
    {
        $stack = new RequestStack();
        $translator = new UiTranslator(null, $stack);
        $stack->push(Request::create('/', cookies: ['lbb_locale' => 'de']));
        self::assertSame('Fälle', $translator->trans('Fälle'));
        $stack->push(Request::create('/', cookies: ['lbb_locale' => 'en']));
        self::assertSame('Fälle', $translator->trans('Fälle'));
        $stack->pop();
        self::assertSame('Fälle', $translator->trans('Fälle'));
        $stack->pop();
        self::assertSame('Cases', $translator->trans('Fälle'));
    }

    public function testPreviousEnvironmentLocaleDoesNotChangeBrowserOrConsoleDefault(): void
    {
        $previous = getenv('APP_LOCALE');
        $server = $_SERVER;
        $env = $_ENV;
        try {
            putenv('APP_LOCALE=de');
            $_SERVER['APP_LOCALE'] = $_ENV['APP_LOCALE'] = 'de';
            self::assertSame('en', (new UiTranslator())->locale());
            $stack = new RequestStack();
            $stack->push(Request::create('/'));
            self::assertSame('en', (new UiTranslator(null, $stack))->locale());
        } finally {
            $previous === false ? putenv('APP_LOCALE') : putenv('APP_LOCALE='.$previous);
            $_SERVER = $server;
            $_ENV = $env;
        }
    }

    public function testItReplacesNamedPlaceholdersWithoutDroppingUnknownOnes(): void
    {
        $german = new UiTranslator('de');
        $english = new UiTranslator('en');

        self::assertSame('1.234 Fälle', $german->trans('{count} Fälle', ['count' => '1.234']));
        self::assertSame('1,234 cases', $english->trans('{count} Fälle', ['count' => '1,234']));
        self::assertSame('{count} cases', $english->trans('{count} Fälle'));
    }

    public function testNumberAndDateFormatsStayConsistentWithinEachLocale(): void
    {
        $german = new UiTranslator('de');
        $english = new UiTranslator('en');
        $instant = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');

        self::assertSame('1.234.567,89', $german->formatNumber(1234567.89, 2));
        self::assertSame('1,234,567.89', $english->formatNumber(1234567.89, 2));
        self::assertSame('02.01.2026', $german->formatDate('2026-01-02'));
        self::assertSame('02/01/2026', $english->formatDate('2026-01-02'));
        self::assertSame('02.01.2026 · 04:04', $german->formatDateTime($instant));
        self::assertSame('02/01/2026 · 04:04', $english->formatDateTime($instant));
        self::assertSame('02.01.2026 · 04:04:05 CET', $german->formatDateTime($instant, true));
        self::assertSame('02/01/2026 · 04:04:05 CET', $english->formatDateTime($instant, true));
        self::assertSame('Zeitpunkt unbekannt', $german->formatDateTime(null));
        self::assertSame('Time unknown', $english->formatDateTime(null));
    }

    public function testBrowserCatalogCarriesTheResolvedLocaleAndTranslations(): void
    {
        $catalog = json_decode((new UiTranslator('en'))->browserCatalogJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('en', $catalog['locale']);
        self::assertSame('Cases', $catalog['messages']['Fälle']);
        self::assertArrayHasKey('Zeitpunkt unbekannt', $catalog['messages']);
    }
}
