<?php

namespace App\Tests;

use App\Entity\Setting;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

final class SettingsServiceTest extends TestCase
{
    public function testDefaultsAreAvailableWhenUnset(): void
    {
        $store = [];
        $repo = null;
        $service = new SettingsService($this->createEntityManager($store, $repo));

        self::assertSame('OPENBUGBOUNTY', $service->getDefaultPayload());
        self::assertSame(45000, $service->getReviewScanTimeoutMs());
        self::assertSame(0, $service->getReviewDecisionDelaySeconds());
        self::assertSame('10', $service->getInventoryPageSize());
        self::assertSame('report', $service->getExportProfile());
        self::assertSame('latest', $service->getExportScreenshotMode());
    }

    public function testSettingsCanBeSavedAndReloaded(): void
    {
        $store = [];
        $repo = null;
        $service = new SettingsService($this->createEntityManager($store, $repo));
        $service->save([
            'intake.default_payload' => 'PAYLOAD123',
            'review.scan_timeout_ms' => '30000',
            'review.decision_delay_seconds' => '3',
            'inventory.page_size' => '25',
            'export.default_profile' => 'state',
            'export.screenshot_mode' => 'none',
        ]);

        self::assertSame('PAYLOAD123', $service->getDefaultPayload());
        self::assertSame(30000, $service->getReviewScanTimeoutMs());
        self::assertSame(3, $service->getReviewDecisionDelaySeconds());
        self::assertSame('25', $service->getInventoryPageSize());
        self::assertSame('state', $service->getExportProfile());
        self::assertSame('none', $service->getExportScreenshotMode());
    }

    public function testScreenshotTimeoutIsAlwaysClampedToTheWorkerLimits(): void
    {
        $store = [];
        $repo = null;
        $service = new SettingsService($this->createEntityManager($store, $repo));

        $service->save(['review.scan_timeout_ms' => '999']);
        self::assertSame(1000, $service->getReviewScanTimeoutMs());
        $service->save(['review.scan_timeout_ms' => '120001']);
        self::assertSame(120000, $service->getReviewScanTimeoutMs());
    }

    public function testDecisionPauseOnlyUsesSupportedDurations(): void
    {
        $store = [];
        $repo = null;
        $service = new SettingsService($this->createEntityManager($store, $repo));

        foreach (['0', '3', '5'] as $value) {
            $service->save(['review.decision_delay_seconds' => $value]);
            self::assertSame((int) $value, $service->getReviewDecisionDelaySeconds());
        }
        foreach ([null, '', '2', '-1', '3.5', '3e0', '03', 'invalid'] as $value) {
            $service->save(['review.decision_delay_seconds' => $value]);
            self::assertSame(0, $service->getReviewDecisionDelaySeconds());
        }
    }

    public function testInvalidStoredPreferencesFallBackWithoutRepairingTheDatabase(): void
    {
        $store = [];
        $repo = null;
        $service = new SettingsService($this->createEntityManager($store, $repo));
        foreach ([
            [null, null, null],
            ['', '', ''],
            ['all', 'zip', 'assessment'],
            ['025', 'REPORT', 'LATEST'],
            ['25.0', 'unknown', 'unknown'],
        ] as [$pageSize, $profile, $screenshots]) {
            $service->save([
                'inventory.page_size' => $pageSize,
                'export.default_profile' => $profile,
                'export.screenshot_mode' => $screenshots,
            ]);
            self::assertSame('10', $service->getInventoryPageSize());
            self::assertSame('report', $service->getExportProfile());
            self::assertSame('latest', $service->getExportScreenshotMode());
            self::assertSame($pageSize, $store['inventory.page_size']->getValue());
            self::assertSame($profile, $store['export.default_profile']->getValue());
            self::assertSame($screenshots, $store['export.screenshot_mode']->getValue());
        }
    }

    /**
     * @param array<string, Setting> $store
     */
    private function createEntityManager(array &$store, ?ObjectRepository &$repo = null): EntityManagerInterface
    {
        $repo = new class($store) implements ObjectRepository {
            public function __construct(private array &$store)
            {
            }

            public function find(mixed $id): ?object
            {
                return $this->store[(string) $id] ?? null;
            }

            public function findAll(): array
            {
                return array_values($this->store);
            }

            public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array
            {
                return array_values($this->store);
            }

            public function findOneBy(array $criteria): ?object
            {
                return null;
            }

            public function getClassName(): string
            {
                return Setting::class;
            }
        };

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturnCallback(static function (string $class, mixed $id) use (&$store) {
            return $class === Setting::class ? ($store[(string) $id] ?? null) : null;
        });
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class) => $repo);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$store): void {
            if ($entity instanceof Setting) {
                $store[$entity->getId()] = $entity;
            }
        });
        $entityManager->method('flush')->willReturnCallback(static function (): void {
        });

        return $entityManager;
    }
}
