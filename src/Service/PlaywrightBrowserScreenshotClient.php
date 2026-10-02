<?php

namespace App\Service;

use App\Dto\BrowserScreenshotRequest;
use App\Dto\BrowserScreenshotResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlaywrightBrowserScreenshotClient implements BrowserScreenshotClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $workerUrl,
    ) {
    }

    public function capture(BrowserScreenshotRequest $request): BrowserScreenshotResult
    {
        $browserSeconds = max(1, (int) ceil($request->timeoutMs / 1000));
        $response = $this->httpClient->request('POST', rtrim($this->workerUrl, '/').'/screenshot', [
            'json' => [
                'url' => $request->url,
                'timeoutMs' => $request->timeoutMs,
                'settleMs' => $request->settleMs,
            ],
            'timeout' => max(90, $browserSeconds + 45),
            'max_duration' => max(120, $browserSeconds + 60),
        ]);
        $data = $response->toArray(false);
        if ($response->getStatusCode() >= 400 || !is_string($data['screenshotBase64'] ?? null) || $data['screenshotBase64'] === '') {
            throw new \RuntimeException((string) ($data['errorMessage'] ?? 'Browser worker did not return a screenshot.'));
        }

        $capturedAtValue = $data['capturedAt'] ?? null;
        if (!is_string($capturedAtValue) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $capturedAtValue) !== 1) {
            throw new \RuntimeException('Browser worker returned an invalid capture timestamp.');
        }
        try {
            $capturedAt = new \DateTimeImmutable($capturedAtValue);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Browser worker returned an invalid capture timestamp.', 0, $error);
        }

        return new BrowserScreenshotResult(
            screenshotBase64: $data['screenshotBase64'],
            capturedAt: $capturedAt,
            httpStatus: isset($data['httpStatus']) ? (int) $data['httpStatus'] : null,
            finalUrl: isset($data['finalUrl']) ? (string) $data['finalUrl'] : null,
            dialogSeen: ($data['dialogSeen'] ?? false) === true,
            dialogType: isset($data['dialogType']) ? (string) $data['dialogType'] : null,
            dialogText: isset($data['dialogText']) ? (string) $data['dialogText'] : null,
            captureMethod: isset($data['captureMethod']) ? (string) $data['captureMethod'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    public function waitUntilReady(): void
    {
        $deadline = microtime(true) + 30.0;
        $lastError = 'health check did not return HTTP 200';
        do {
            try {
                $response = $this->httpClient->request('GET', rtrim($this->workerUrl, '/').'/health', [
                    'timeout' => 2,
                    'max_duration' => 2,
                ]);
                if ($response->getStatusCode() === 200) {
                    return;
                }
                $lastError = sprintf('health check returned HTTP %d', $response->getStatusCode());
            } catch (\Throwable $error) {
                $lastError = $error->getMessage();
            }
            usleep(250000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('Browser worker is not ready: '.$lastError);
    }
}
