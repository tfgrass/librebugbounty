<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Bounded, read-only HTTP service check; does not start a browser or visit findings. */
final class BrowserHealthService
{
    public function __construct(private readonly HttpClientInterface $httpClient, private readonly WorkerHealthRegistry $registry)
    {
    }

    /** @return array<string, bool> */
    public function check(): array
    {
        $pending = [];
        $results = [];
        // Dispatch before consuming responses, so sidecar requests can overlap.
        foreach ($this->registry->browserUrls() as $url) {
            $results[$url] = false;
            try {
                $pending[$url] = $this->httpClient->request('GET', rtrim($url, '/').'/health', [
                    'timeout' => 1.0, 'max_duration' => 1.0, 'max_redirects' => 0,
                ]);
            } catch (\Throwable) {
                // One failed service must not break the Settings page.
            }
        }
        foreach ($pending as $url => $response) {
            try {
                $results[$url] = $response->getStatusCode() === 200 && ($response->toArray(false)['ok'] ?? null) === true;
            } catch (\Throwable) {
                $results[$url] = false;
            } finally {
                $response->cancel();
            }
        }

        return $results;
    }
}
