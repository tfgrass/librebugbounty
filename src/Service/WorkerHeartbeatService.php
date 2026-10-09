<?php

namespace App\Service;

use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Best-effort liveness signal for the background workers. All worker
 * processes of one kind share one heartbeat row; the settings page only
 * needs to know whether that kind is alive, not which process wrote last.
 */
final class WorkerHeartbeatService
{
    public const RECHECK = 'recheck';
    public const SCREENSHOT = 'screenshot';

    private const SETTING_PREFIX = 'worker.heartbeat.';
    private const MIN_TOUCH_SECONDS = 15;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function touch(string $kind, ?\DateTimeImmutable $at = null): void
    {
        $id = self::SETTING_PREFIX.$kind;
        $setting = $this->entityManager->find(Setting::class, $id);
        if (!$setting instanceof Setting) {
            $setting = new Setting();
            $setting->setId($id);
            $this->entityManager->persist($setting);
        }

        $setting->setValue(($at ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->entityManager->flush();
    }

    /**
     * Touch the shared heartbeat only when the stored signal is older than
     * the write limit. With several workers sharing one row this also keeps
     * SQLite write contention negligible.
     */
    public function touchIfDue(string $kind, ?\DateTimeImmutable $at = null): bool
    {
        $at ??= new \DateTimeImmutable();
        $age = $this->ageSeconds($kind, $at);
        if ($age !== null && $age < self::MIN_TOUCH_SECONDS) {
            return false;
        }

        $this->touch($kind, $at);

        return true;
    }

    public function lastSeen(string $kind): ?\DateTimeImmutable
    {
        $setting = $this->entityManager->find(Setting::class, self::SETTING_PREFIX.$kind);
        $value = $setting?->getValue();
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    public function ageSeconds(string $kind, ?\DateTimeImmutable $now = null): ?int
    {
        $seen = $this->lastSeen($kind);
        if ($seen === null) {
            return null;
        }

        return max(0, ($now ?? new \DateTimeImmutable())->getTimestamp() - $seen->getTimestamp());
    }
}
