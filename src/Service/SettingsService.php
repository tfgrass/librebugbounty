<?php

namespace App\Service;

use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;

final class SettingsService
{
    public const DEFAULTS = [
        'intake.default_payload' => 'OPENBUGBOUNTY',
        'intake.auto_verify_mode' => 'submit',
        'review.scan_timeout_ms' => '45000',
        'review.scan_concurrency' => '4',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getString(string $key, ?string $fallback = null): string
    {
        $value = $this->getRaw($key);
        if ($value === null || trim($value) === '') {
            return $fallback ?? self::DEFAULTS[$key] ?? '';
        }

        return $value;
    }

    public function getInt(string $key, int $fallback = 0): int
    {
        $value = trim($this->getString($key, (string) $fallback));
        if ($value === '' || !is_numeric($value)) {
            return $fallback;
        }

        return (int) $value;
    }

    public function getBool(string $key, bool $fallback = false): bool
    {
        $value = strtolower(trim($this->getString($key, $fallback ? '1' : '0')));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $settings = self::DEFAULTS;
        foreach ($this->entityManager->getRepository(Setting::class)->findAll() as $setting) {
            \assert($setting instanceof Setting);
            $settings[$setting->getId()] = $setting->getValue() ?? '';
        }

        return $settings;
    }

    /**
     * @param array<string, string|null> $values
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            $setting = $this->entityManager->find(Setting::class, (string) $key);
            if (!$setting instanceof Setting) {
                $setting = new Setting();
                $setting->setId((string) $key);
                $this->entityManager->persist($setting);
            }

            $setting->setValue($value !== null ? (string) $value : null);
        }

        $this->entityManager->flush();
    }

    public function getDefaultPayload(): string
    {
        return $this->getString('intake.default_payload', self::DEFAULTS['intake.default_payload']);
    }

    public function getAutoVerifyMode(): string
    {
        $mode = $this->getString('intake.auto_verify_mode', self::DEFAULTS['intake.auto_verify_mode']);

        return in_array($mode, ['submit', 'cron_only'], true) ? $mode : self::DEFAULTS['intake.auto_verify_mode'];
    }

    public function getReviewScanTimeoutMs(): int
    {
        return max(1000, $this->getInt('review.scan_timeout_ms', (int) self::DEFAULTS['review.scan_timeout_ms']));
    }

    public function getReviewScanConcurrency(): int
    {
        return max(1, $this->getInt('review.scan_concurrency', (int) self::DEFAULTS['review.scan_concurrency']));
    }

    private function getRaw(string $key): ?string
    {
        $setting = $this->entityManager->find(Setting::class, $key);
        if (!$setting instanceof Setting) {
            return null;
        }

        return $setting->getValue();
    }
}
