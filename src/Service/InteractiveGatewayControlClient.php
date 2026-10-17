<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class InteractiveGatewayControlClient
{
    public function __construct(private readonly HttpClientInterface $httpClient, private readonly string $workerUrl, private readonly ?string $gatewayToken = null) {}

    public function fence(int $generation): void
    {
        $data = $this->request('POST', '/vnc-control/fence', ['json' => ['generation' => $generation]]);
        if (($data['fenced'] ?? false) !== true || ($data['activeGeneration'] ?? null) !== $generation) {
            throw new \RuntimeException('Interactive fencing was not confirmed.', 503);
        }
    }

    public function state(): array { return $this->request('GET', '/vnc-control/state'); }

    private function request(string $method, string $path, array $options = []): array
    {
        if ($this->gatewayToken === null || preg_match('/^[\x21-\x7e]+$/D', $this->gatewayToken) !== 1) {
            throw new \RuntimeException('Interactive gateway credential is not configured.', 503);
        }
        $options += ['timeout' => 12, 'max_duration' => 15, 'max_redirects' => 0];
        $options['headers']['Authorization'] = 'Bearer '.$this->gatewayToken;
        try {
            $response = $this->httpClient->request($method, rtrim($this->workerUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Interactive gateway is unavailable.', 503, $error);
        }
        if ($status !== 200 || !is_array($data)) { throw new \RuntimeException('Interactive gateway rejected the operation.', $status === 409 ? 409 : 503); }
        return $data;
    }
}
