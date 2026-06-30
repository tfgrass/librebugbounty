<?php

namespace App\Service;

use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlaywrightBrowserRetestClient implements BrowserRetestClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $workerUrl,
    ) {
    }

    public function retest(BrowserRetestRequest $request): RetestResultData
    {
        $timeoutSeconds = max(30, (int) ceil($request->timeoutMs / 1000) + 15);
        $maxDurationSeconds = max(60, (int) ceil($request->timeoutMs / 1000) + 30);

        $response = $this->httpClient->request('POST', rtrim($this->workerUrl, '/').'/retest', [
            'json' => [
                'url' => $request->url,
                'expectedEvidence' => $request->expectedEvidence,
                'timeoutMs' => $request->timeoutMs,
                'screenshot' => $request->screenshot,
                'browser' => $request->browser,
            ],
            'timeout' => $timeoutSeconds,
            'max_duration' => $maxDurationSeconds,
        ]);

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
