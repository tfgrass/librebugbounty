<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Read-only infrastructure checks. Never starts, fences or refreshes a session. */
final class InteractiveHealthService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient,
        private readonly string $workerUrl,
        private readonly ?string $gatewayToken = null,
    ) {
    }

    /** @return array{state: string, workerReachable: bool, gatewayReachable: bool, gatewayConfigured: bool, availability: string, expiresAt: ?\DateTimeImmutable} */
    public function snapshot(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $gatewayConfigured = $this->gatewayToken !== null && preg_match('/^[\x21-\x7e]+$/D', $this->gatewayToken) === 1;
        $reachable = ['worker' => false, 'gateway' => false];
        $pending = [];
        $checks = ['worker' => ['/health', []]];
        if ($gatewayConfigured) {
            $checks['gateway'] = ['/vnc-control/state', ['Authorization' => 'Bearer '.$this->gatewayToken]];
        }
        // Dispatch both requests before reading either response. The gateway
        // state endpoint only reports its control process, not a WSS/RFB probe.
        foreach ($checks as $kind => [$path, $headers]) {
            try {
                $pending[$kind] = $this->httpClient->request('GET', rtrim($this->workerUrl, '/').$path, [
                    'headers' => $headers, 'timeout' => 1.0, 'max_duration' => 1.0, 'max_redirects' => 0,
                ]);
            } catch (\Throwable) {
                // Missing sidecars or credentials cannot break the status view.
            }
        }
        foreach ($pending as $kind => $response) {
            try {
                $data = $response->toArray(false);
                $reachable[$kind] = $response->getStatusCode() === 200 && ($kind === 'worker'
                    ? ($data['ok'] ?? null) === true
                    : array_key_exists('activeGeneration', $data)
                        && ($data['activeGeneration'] === null || (is_int($data['activeGeneration']) && $data['activeGeneration'] >= 0))
                        && isset($data['sockets']) && is_array($data['sockets']) && array_is_list($data['sockets']));
            } catch (\Throwable) {
                $reachable[$kind] = false;
            } finally {
                $response->cancel();
            }
        }

        return [
            'state' => $reachable['worker'] && $reachable['gateway'] ? 'ok' : 'degraded',
            'workerReachable' => $reachable['worker'],
            'gatewayReachable' => $reachable['gateway'],
            'gatewayConfigured' => $gatewayConfigured,
        ] + $this->availability($now);
    }

    /** @return array{availability: string, expiresAt: ?\DateTimeImmutable} */
    private function availability(\DateTimeImmutable $now): array
    {
        try {
            // Select only operational fields. Do not expose finding/owner/channel
            // identifiers or call coordinator reconciliation on a diagnostic GET.
            $lease = $this->connection->fetchAssociative('SELECT state, expires_at FROM interactive_browser_lease WHERE singleton_id = 1');
            if ($lease === false) {
                return ['availability' => 'available', 'expiresAt' => null];
            }
            if ($lease['state'] === 'available') {
                return ['availability' => 'available', 'expiresAt' => null];
            }
            if (in_array($lease['state'], ['activating', 'active', 'closing'], true)) {
                $expiresAt = $lease['expires_at'] === null ? null : $now->setTimestamp((int) $lease['expires_at']);

                return ['availability' => $expiresAt !== null && $expiresAt <= $now ? 'expired' : 'busy', 'expiresAt' => $expiresAt];
            }
        } catch (\Throwable) {
            // An absent migration or unreadable lease is unknown, never free.
        }

        return ['availability' => 'unknown', 'expiresAt' => null];
    }
}
