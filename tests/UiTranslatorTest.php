<?php

namespace App\Tests;

use App\Service\UiTranslator;
use PHPUnit\Framework\TestCase;

final class UiTranslatorTest extends TestCase
{
    public function testItSelectsGermanEnglishAndFallsBackToGermanForUnsupportedLocales(): void
    {
        $german = new UiTranslator('de');
        $english = new UiTranslator('en');
        $unsupported = new UiTranslator('  fr-FR  ');

        self::assertSame('de', $german->locale());
        self::assertSame('Fälle', $german->trans('Fälle'));
        self::assertSame('en', $english->locale());
        self::assertSame('Cases', $english->trans('Fälle'));
        self::assertSame('de', $unsupported->locale());
        self::assertSame('Fälle', $unsupported->trans('Fälle'));
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
