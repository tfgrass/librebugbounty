<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\InteractiveHealthService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class InteractiveHealthServiceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__).'/migrations/Version20261017000000.php';
        $migration = new \DoctrineMigrations\Version20261017000000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) {
            $this->connection->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        }
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testOnlyBoundedHealthAndAuthenticatedGatewayStateReadsAreSent(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];
            $gateway = str_ends_with($url, '/vnc-control/state');

            return new MockResponse($gateway ? '{"activeGeneration":null,"sockets":[]}' : '{"ok":true}');
        });
        $before = $this->rows();
        $service = new InteractiveHealthService($this->connection, $client, 'http://interactive:3000/', 'private-gateway-token');
        $snapshot = $service->snapshot();
        self::assertSame('ok', $snapshot['state']);
        self::assertTrue($snapshot['workerReachable']);
        self::assertTrue($snapshot['gatewayReachable']);
        self::assertSame('available', $snapshot['availability']);
        self::assertSame([['GET', 'http://interactive:3000/health'], ['GET', 'http://interactive:3000/vnc-control/state']], array_map(static fn (array $request): array => array_slice($request, 0, 2), $requests));
        foreach ($requests as [$method, $url, $options]) {
            self::assertSame(1.0, $options['timeout']);
            self::assertSame(1.0, $options['max_duration']);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(str_ends_with($url, '/vnc-control/state') ? ['Authorization: Bearer private-gateway-token'] : [], $options['normalized_headers']['authorization'] ?? []);
        }
        self::assertSame($before, $this->rows());
        self::assertFalse($this->connection->isTransactionActive());
        self::assertStringNotContainsString('private-gateway-token', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public static function failedChecks(): iterable
    {
        yield 'worker invalid JSON' => ['worker', 'invalid'];
        yield 'worker reports down' => ['worker', '{"ok":false}'];
        yield 'worker HTTP failure' => ['worker', '{"ok":true}', 503];
        yield 'worker redirect' => ['worker', '{"ok":true}', 302];
        yield 'gateway invalid JSON' => ['gateway', 'invalid'];
        yield 'gateway unrelated response' => ['gateway', '{"ok":true}'];
        yield 'gateway malformed generation' => ['gateway', '{"activeGeneration":"1","sockets":[]}'];
        yield 'gateway missing sockets' => ['gateway', '{"activeGeneration":1}'];
        yield 'gateway HTTP failure' => ['gateway', '{"activeGeneration":1,"sockets":[]}', 503];
        yield 'gateway transport failure' => ['gateway', 'transport'];
        yield 'gateway timeout' => ['gateway', 'timeout'];
    }

    #[DataProvider('failedChecks')]
    public function testFailuresRemainLocalToTheirService(string $failed, string $body, int $status = 200): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function (string $method, string $url) use ($failed, $body, $status, &$requests): MockResponse {
            ++$requests;
            $kind = str_ends_with($url, '/health') ? 'worker' : 'gateway';
            if ($kind !== $failed) {
                return new MockResponse($kind === 'worker' ? '{"ok":true}' : '{"activeGeneration":2,"sockets":[]}');
            }
            if ($body === 'transport') {
                throw new TransportException('secret diagnostic detail');
            }
            if ($body === 'timeout') {
                return new MockResponse((static function () { yield ''; })());
            }

            return new MockResponse($body, ['http_code' => $status]);
        });
        $snapshot = (new InteractiveHealthService($this->connection, $client, 'http://interactive:3000', 'token'))->snapshot();
        self::assertSame('degraded', $snapshot['state']);
        self::assertSame($failed !== 'worker', $snapshot['workerReachable']);
        self::assertSame($failed !== 'gateway', $snapshot['gatewayReachable']);
        self::assertSame('available', $snapshot['availability']);
        self::assertSame(2, $requests);
        self::assertStringNotContainsString('secret diagnostic detail', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public static function missingCredentials(): iterable
    {
        yield [null];
        yield [''];
        yield ["invalid\nheader"];
    }

    #[DataProvider('missingCredentials')]
    public function testMissingCredentialsAreReportedWithoutSendingGatewayRequest(?string $token): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('http://interactive:3000/health', $url);

            return new MockResponse('{"ok":true}');
        });
        $snapshot = (new InteractiveHealthService($this->connection, $client, 'http://interactive:3000', $token))->snapshot();
        self::assertFalse($snapshot['gatewayConfigured']);
        self::assertFalse($snapshot['gatewayReachable']);
        self::assertTrue($snapshot['workerReachable']);
        self::assertSame('degraded', $snapshot['state']);
        self::assertSame(1, $client->getRequestsCount());
    }

    public static function leaseStates(): iterable
    {
        yield 'available' => ['available', null, 'available'];
        yield 'activating before worker attachment' => ['activating', null, 'busy'];
        yield 'activating attached' => ['activating', 1300, 'busy'];
        yield 'active' => ['active', 1300, 'busy'];
        yield 'closing' => ['closing', 1300, 'busy'];
        yield 'expired active' => ['active', 999, 'expired'];
        yield 'expired exact boundary' => ['active', 1000, 'expired'];
        yield 'expired closing' => ['closing', 999, 'expired'];
    }

    #[DataProvider('leaseStates')]
    public function testAvailabilityIsSeparateFromTechnicalHealthAndNeverRefreshesOrCleansLease(string $state, ?int $expiry, string $availability): void
    {
        $this->connection->executeStatement('UPDATE interactive_browser_lease SET state = ?, expires_at = ?, owner_hash = ?, channel_id = ?, finding_id = ?', [$state, $expiry, 'private-owner', 'private-channel', 'private-finding']);
        $before = $this->rows();
        $client = new MockHttpClient(static fn (string $method, string $url) => new MockResponse(str_ends_with($url, '/health') ? '{"ok":true}' : '{"activeGeneration":4,"sockets":[]}'));
        $snapshot = (new InteractiveHealthService($this->connection, $client, 'http://interactive:3000', 'token'))->snapshot(new \DateTimeImmutable('@1000'));
        self::assertSame($availability, $snapshot['availability']);
        self::assertSame('ok', $snapshot['state'], 'Busy or expired is not a failed service check.');
        self::assertSame($state === 'available' ? null : $expiry, $snapshot['expiresAt']?->getTimestamp());
        self::assertSame($before, $this->rows());
        foreach (['private-owner', 'private-channel', 'private-finding'] as $secret) {
            self::assertStringNotContainsString($secret, json_encode($snapshot, JSON_THROW_ON_ERROR));
        }
    }

    public function testUninitializedLeaseIsFreeWithoutCreatingARowAndMissingTableIsUnknown(): void
    {
        $this->connection->executeStatement('DELETE FROM interactive_browser_lease');
        $client = new MockHttpClient(static fn (string $method, string $url) => new MockResponse(str_ends_with($url, '/health') ? '{"ok":true}' : '{"activeGeneration":null,"sockets":[]}'));
        $service = new InteractiveHealthService($this->connection, $client, 'http://interactive:3000', 'token');
        self::assertSame('available', $service->snapshot()['availability']);
        self::assertSame([], $this->rows());
        $this->connection->executeStatement('DROP TABLE interactive_browser_lease');
        self::assertSame('unknown', $service->snapshot()['availability']);
    }

    private function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM interactive_browser_lease');
    }
}
