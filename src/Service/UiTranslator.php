<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

final class UiTranslator
{
    public const DEFAULT_LOCALE = 'en';
    public const COOKIE_NAME = 'lbb_locale';
    private const SUPPORTED_LOCALES = ['de', 'en'];

    /** @var array<string, array<string, string>> */
    private array $catalogs = [];
    private readonly ?string $fixedLocale;

    public function __construct(
        ?string $locale = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
        $this->fixedLocale = $locale === null ? null : self::normalizeLocale($locale);
    }

    public function locale(): string
    {
        if ($this->fixedLocale !== null) {
            return $this->fixedLocale;
        }

        $cookies = $this->requestStack?->getMainRequest()?->cookies->all() ?? [];
        $locale = $cookies[self::COOKIE_NAME] ?? null;

        return in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : self::DEFAULT_LOCALE;
    }

    /** @param array<string, scalar|null> $parameters */
    public function trans(string $key, array $parameters = []): string
    {
        $message = $this->messages()[$key] ?? $key;
        if ($parameters === []) {
            return $message;
        }

        $replacements = [];
        foreach ($parameters as $name => $value) {
            $replacements['{'.$name.'}'] = (string) ($value ?? '');
        }

        return strtr($message, $replacements);
    }

    public function formatDateTime(?\DateTimeInterface $at, bool $withSeconds = false): string
    {
        if ($at === null) {
            return $this->trans('Zeitpunkt unbekannt');
        }
        $local = \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $format = $this->locale() === 'de'
            ? 'd.m.Y · H:i'.($withSeconds ? ':s T' : '')
            : 'd/m/Y · H:i'.($withSeconds ? ':s T' : '');

        return $local->format($format);
    }

    public function formatDate(\DateTimeInterface|string $date): string
    {
        $value = is_string($date) ? new \DateTimeImmutable($date) : \DateTimeImmutable::createFromInterface($date);

        return $value->format($this->locale() === 'de' ? 'd.m.Y' : 'd/m/Y');
    }

    public function formatNumber(int|float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, $this->locale() === 'de' ? ',' : '.', $this->locale() === 'de' ? '.' : ',');
    }

    public function browserCatalogJson(): string
    {
        return json_encode(
            ['locale' => $this->locale(), 'messages' => $this->messages()],
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        $locale = $this->locale();
        if (!isset($this->catalogs[$locale])) {
            $catalog = require dirname(__DIR__, 2).'/translations/ui.'.$locale.'.php';
            if (!is_array($catalog)) {
                throw new \LogicException('The UI translation catalog must return an array.');
            }
            $this->catalogs[$locale] = $catalog;
        }

        return $this->catalogs[$locale];
    }

    private static function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));

        return in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : self::DEFAULT_LOCALE;
    }
}
