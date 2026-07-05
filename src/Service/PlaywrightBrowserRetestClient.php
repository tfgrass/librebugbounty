<?php

namespace App\Service;

use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class PlaywrightBrowserRetestClient implements BrowserRetestClientInterface, BrowserRetestTransportInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $workerUrl,
    ) {
    }

    public function retest(BrowserRetestRequest $request): RetestResultData
    {
        return $this->decode($this->request($request));
    }

    public function request(BrowserRetestRequest $request): ResponseInterface
    {
        $browserSeconds = max(1, (int) ceil($request->timeoutMs / 1000));
        $timeoutSeconds = max(300, ($browserSeconds * 4) + 120);
        $maxDurationSeconds = max(420, ($browserSeconds * 6) + 120);
        $previousSocketTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) ($maxDurationSeconds + 60));

        try {
            return $this->httpClient->request('POST', rtrim($this->workerUrl, '/').'/retest', [
                'json' => [
                    'url' => $request->url,
                    'expectedEvidence' => $request->expectedEvidence,
                    'timeoutMs' => $request->timeoutMs,
                    'screenshot' => $request->screenshot,
                    'headless' => $request->headless,
                    'browser' => $request->browser,
                ],
                'timeout' => $timeoutSeconds,
                'max_duration' => $maxDurationSeconds,
            ]);
        } finally {
            if ($previousSocketTimeout !== false) {
                ini_set('default_socket_timeout', (string) $previousSocketTimeout);
            }
        }
    }

    public function decode(ResponseInterface $response): RetestResultData
    {
        $data = $response->toArray(false);

        return new RetestResultData(
            result: (string) ($data['result'] ?? 'error'),
            httpStatus: isset($data['httpStatus']) ? (int) $data['httpStatus'] : null,
            finalUrl: $data['finalUrl'] ?? null,
            observedEvidence: $data['observedEvidence'] ?? null,
            dialogText: $data['dialogText'] ?? null,
            screenshotBase64: $data['screenshotBase64'] ?? null,
            errorMessage: $data['errorMessage'] ?? null,
            raw: $data['raw'] ?? [],
        );
    }
}
