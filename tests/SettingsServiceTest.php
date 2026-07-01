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
        self::assertSame('submit', $service->getAutoVerifyMode());
        self::assertSame(45000, $service->getReviewScanTimeoutMs());
        self::assertSame(4, $service->getReviewScanConcurrency());
    }

    public function testSettingsCanBeSavedAndReloaded(): void
    {
        $store = [];
        $repo = null;
        $service = new SettingsService($this->createEntityManager($store, $repo));
        $service->save([
            'intake.default_payload' => 'PAYLOAD123',
            'intake.auto_verify_mode' => 'cron_only',
            'review.scan_timeout_ms' => '30000',
            'review.scan_concurrency' => '7',
        ]);

        self::assertSame('PAYLOAD123', $service->getDefaultPayload());
        self::assertSame('cron_only', $service->getAutoVerifyMode());
        self::assertSame(30000, $service->getReviewScanTimeoutMs());
        self::assertSame(7, $service->getReviewScanConcurrency());
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
