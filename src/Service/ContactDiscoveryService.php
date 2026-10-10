<?php
namespace App\Service;

use App\Entity\Finding;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

final class ContactDiscoveryService
{
    private array $providers = [];
    public function __construct(private readonly Connection $connection, private readonly FindingWorkPolicy $policy,
        #[AutowireIterator('app.contact_discovery_provider')] iterable $providers)
    {
        foreach ($providers as $provider) {
            if (!$provider instanceof ContactDiscoveryProviderInterface || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $provider->id()) || isset($this->providers[$provider->id()])) throw new \LogicException('Invalid or duplicate contact provider.');
            $this->providers[$provider->id()] = $provider;
        }
    }
    public function providers(): array { return array_map(static fn ($provider) => $provider->label(), $this->providers); }
    public function history(Finding $finding): array
    {
        if (!FindingWorkPolicy::available($this->connection)) return [];
        $rows = $this->connection->fetchAllAssociative('SELECT provider, result, fetched_at FROM contact_discovery WHERE finding_id = ? ORDER BY fetched_at DESC, rowid DESC LIMIT 10', [$finding->getId()]);
        foreach ($rows as &$row) $row['result'] = json_decode($row['result'], true, 32, JSON_THROW_ON_ERROR);
        return $rows;
    }
    public function discover(Finding $finding, string $providerId): void
    {
        $this->policy->assertDiscoveryAllowed($finding);
        $provider = $this->providers[$providerId] ?? throw new \InvalidArgumentException('Unbekannter Kontaktanbieter.');
        $id = Uuid::v7()->toRfc4122();
        $pending = ['status' => 'pending', 'source' => '', 'contacts' => [], 'policies' => [], 'languages' => [], 'expires' => null, 'warnings' => []];
        // A single write statement coalesces concurrent requests without a
        // read-then-write transaction upgrade in SQLite.
        $inserted = $this->connection->executeStatement(
            'INSERT INTO contact_discovery (id, finding_id, provider, result, fetched_at) '
            .'SELECT :id, :finding, :provider, :result, :now WHERE NOT EXISTS ('
            .'SELECT 1 FROM contact_discovery WHERE finding_id = :finding AND provider = :provider AND fetched_at >= :since)',
            ['id' => $id, 'finding' => $finding->getId(), 'provider' => $providerId,
                'result' => json_encode($pending, JSON_THROW_ON_ERROR),
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'since' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')],
        );
        if ($inserted === 0) return;
        try {
            $this->policy->assertDiscoveryAllowed($finding);
            $result = $provider->discover($finding->getDomain()->getHostname())->toArray();
        } catch (\Throwable) {
            $result = ['status' => 'error', 'source' => '', 'contacts' => [], 'policies' => [], 'languages' => [], 'expires' => null, 'warnings' => ['Kontaktdaten konnten nicht abgerufen werden.']];
        }
        $this->connection->update('contact_discovery', ['result' => json_encode($result, JSON_THROW_ON_ERROR)], ['id' => $id]);
    }
}
