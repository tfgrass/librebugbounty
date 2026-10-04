<?php

namespace App\Service;

final class UiTranslator
{
    public const DEFAULT_LOCALE = 'de';
    private const SUPPORTED_LOCALES = ['de', 'en'];

    /** @var array<string, string> */
    private array $messages;
    private string $locale;

    public function __construct(?string $locale = null)
    {
        $configured = $locale
            ?? (is_string($_SERVER['APP_LOCALE'] ?? null) ? $_SERVER['APP_LOCALE'] : null)
            ?? (is_string($_ENV['APP_LOCALE'] ?? null) ? $_ENV['APP_LOCALE'] : null)
            ?? (($environment = getenv('APP_LOCALE')) !== false ? $environment : null)
            ?? self::DEFAULT_LOCALE;
        $configured = strtolower(trim($configured));
        $this->locale = in_array($configured, self::SUPPORTED_LOCALES, true) ? $configured : self::DEFAULT_LOCALE;
        $catalog = require dirname(__DIR__, 2).'/translations/ui.'.$this->locale.'.php';
        if (!is_array($catalog)) {
            throw new \LogicException('The UI translation catalog must return an array.');
        }
        $this->messages = $catalog;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string, scalar|null> $parameters */
    public function trans(string $key, array $parameters = []): string
    {
        $message = $this->messages[$key] ?? $key;
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
        $format = $this->locale === 'de'
            ? 'd.m.Y · H:i'.($withSeconds ? ':s T' : '')
            : 'd/m/Y · H:i'.($withSeconds ? ':s T' : '');

        return $local->format($format);
    }

    public function formatDate(\DateTimeInterface|string $date): string
    {
        $value = is_string($date) ? new \DateTimeImmutable($date) : \DateTimeImmutable::createFromInterface($date);

        return $value->format($this->locale === 'de' ? 'd.m.Y' : 'd/m/Y');
    }

    public function formatNumber(int|float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, $this->locale === 'de' ? ',' : '.', $this->locale === 'de' ? '.' : ',');
    }

    public function browserCatalogJson(): string
    {
        return json_encode(
            ['locale' => $this->locale, 'messages' => $this->messages],
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
