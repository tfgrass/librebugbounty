<?php

namespace App\Service;

use App\Dto\BrowserScreenshotRequest;
use App\Dto\BrowserScreenshotResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PlaywrightBrowserScreenshotClient implements BrowserScreenshotClientInterface
{
    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly string $workerUrl,
        private readonly ?string $workerToken = null,
    ) {
    }

    public function capture(BrowserScreenshotRequest $request): BrowserScreenshotResult
    {
        $browserSeconds = max(1, (int) ceil($request->timeoutMs / 1000));
        $authorization = $this->authorizationHeader();
        $response = $this->httpClient->request('POST', rtrim($this->workerUrl, '/').'/screenshot', [
            'headers' => [
                'Authorization' => $authorization,
            ],
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

        return $this->screenshotResult($data);
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

    private function authorizationHeader(): string
    {
        if ($this->workerToken === null || trim($this->workerToken) === '') {
            throw new \RuntimeException('Browser screenshot requests require PLAYWRIGHT_WORKER_TOKEN.');
        }
        if (preg_match('/^[\x21-\x7e]+$/D', $this->workerToken) !== 1) {
            throw new \RuntimeException('PLAYWRIGHT_WORKER_TOKEN must contain only visible ASCII characters without spaces.');
        }

        return 'Bearer '.$this->workerToken;
    }

    protected function screenshotResult(array $data): BrowserScreenshotResult
    {
        return new BrowserScreenshotResult(
            screenshotBase64: $data['screenshotBase64'],
            capturedAt: $this->timestamp($data['capturedAt'] ?? null, 'capture timestamp'),
            httpStatus: isset($data['httpStatus']) ? (int) $data['httpStatus'] : null,
            finalUrl: isset($data['finalUrl']) ? (string) $data['finalUrl'] : null,
            dialogSeen: ($data['dialogSeen'] ?? false) === true,
            dialogType: isset($data['dialogType']) ? (string) $data['dialogType'] : null,
            dialogText: isset($data['dialogText']) ? (string) $data['dialogText'] : null,
            captureMethod: isset($data['captureMethod']) ? (string) $data['captureMethod'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    protected function timestamp(mixed $value, string $label = 'timestamp'): \DateTimeImmutable
    {
        $message = sprintf('Browser worker returned an invalid %s.', $label);
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) !== 1) {
            throw new \RuntimeException($message);
        }

        $timestamp = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new \DateTimeZone('UTC'));
        if ($timestamp === false || $timestamp->format('Y-m-d\TH:i:s.v\Z') !== $value) {
            throw new \RuntimeException($message);
        }

        return $timestamp;
    }
}
