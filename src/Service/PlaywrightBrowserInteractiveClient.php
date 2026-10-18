<?php

namespace App\Service;

use App\Dto\BrowserInteractiveSessionResult;
use App\Dto\BrowserScreenshotResult;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class PlaywrightBrowserInteractiveClient extends PlaywrightBrowserScreenshotClient implements FencedBrowserInteractiveClientInterface
{
    public function __construct(
        HttpClientInterface $httpClient,
        string $workerUrl,
        private readonly ?string $workerToken = null,
    ) {
        parent::__construct($httpClient, $workerUrl);
    }

    public function startFenced(string $url, int $timeoutMs, int $generation, ?string $sessionId = null): BrowserInteractiveSessionResult
    {
        $this->assertGeneration($generation);
        $body = ['url' => $url, 'timeoutMs' => $timeoutMs, 'generation' => $generation];
        if ($sessionId !== null) {
            $this->interactivePath($sessionId, 'status');
            $body['sessionId'] = $sessionId;
        }
        return $this->sessionResult($this->json($this->request('POST', '/interactive-sessions', [
            'json' => $body,
            'timeout' => max(30, (int) ceil($timeoutMs / 1000) + 20),
            'max_duration' => max(45, (int) ceil($timeoutMs / 1000) + 30),
        ])), $sessionId);
    }

    public function captureFenced(string $sessionId, int $generation, ?string $expectedCandidateId): BrowserScreenshotResult
    {
        $this->assertGeneration($generation);
        if ($expectedCandidateId !== null && preg_match('/^[a-f0-9]{48}$/D', $expectedCandidateId) !== 1) {
            throw new \InvalidArgumentException('Invalid interactive dialog candidate.');
        }
        $data = $this->json($this->request('POST', $this->interactivePath($sessionId, 'capture'), [
            'json' => ['generation' => $generation, 'expectedCandidateId' => $expectedCandidateId], 'timeout' => 30, 'max_duration' => 35,
        ]));
        if (($data['generation'] ?? null) !== $generation) {
            throw new \RuntimeException('Interactive capture authorization changed.', 409);
        }
        if (!is_array($data['metadata'] ?? null) || !array_key_exists('dialogCandidateId', $data['metadata'])
            || $data['metadata']['dialogCandidateId'] !== $expectedCandidateId) {
            throw new \RuntimeException('Interactive capture candidate changed or was not confirmed.', 409);
        }
        if (!is_string($data['screenshotBase64'] ?? null) || $data['screenshotBase64'] === '') {
            throw new \RuntimeException('Browser worker did not return an interactive screenshot.');
        }
        return $this->screenshotResult($data);
    }

    public function dialogFenced(string $sessionId, int $generation, string $action, int $dialogRevision): BrowserInteractiveSessionResult
    {
        $this->assertGeneration($generation);
        if (!in_array($action, ['arm', 'retry', 'dismiss'], true) || $dialogRevision < 0) {
            throw new \InvalidArgumentException('Invalid interactive dialog action or revision.');
        }
        $data = $this->json($this->request('POST', $this->interactivePath($sessionId, 'dialog'), [
            'json' => ['generation' => $generation, 'action' => $action, 'dialogRevision' => $dialogRevision],
            'timeout' => 30, 'max_duration' => 35,
        ]));
        if (($data['generation'] ?? null) !== $generation) {
            throw new \RuntimeException('Interactive dialog authorization changed.', 409);
        }
        return $this->sessionResult($data, $sessionId);
    }

    public function closeFenced(string $sessionId, int $generation): void
    {
        $this->assertGeneration($generation);
        $response = $this->request('POST', $this->interactivePath($sessionId, 'close'), [
            'json' => ['generation' => $generation], 'timeout' => 20, 'max_duration' => 25,
        ]);
        if ($response->getStatusCode() === 204 && $response->getContent(false) === '') { return; }
        $data = $this->json($response);
        if (($data['closed'] ?? null) !== true || ($data['id'] ?? null) !== $sessionId) {
            throw new \RuntimeException('Browser worker did not confirm closure.');
        }
    }

    private function assertGeneration(int $generation): void
    {
        if ($generation < 1) { throw new \InvalidArgumentException('Invalid interactive generation.'); }
    }

    public function startInteractive(string $url, int $timeoutMs): BrowserInteractiveSessionResult
    {
        return $this->sessionResult($this->json($this->request('POST', '/interactive-sessions', [
            'json' => ['url' => $url, 'timeoutMs' => $timeoutMs],
            'timeout' => max(30, (int) ceil($timeoutMs / 1000) + 20),
            'max_duration' => max(45, (int) ceil($timeoutMs / 1000) + 30),
        ])));
    }

    public function interactiveStatus(string $sessionId): BrowserInteractiveSessionResult
    {
        return $this->sessionResult($this->json($this->request('GET', $this->interactivePath($sessionId, 'status'), [
            'timeout' => 20,
            'max_duration' => 25,
        ])), $sessionId);
    }

    public function interactiveInput(string $sessionId, array $event): BrowserInteractiveSessionResult
    {
        $navigation = in_array($event['action'] ?? null, ['reload', 'back'], true);

        return $this->sessionResult($this->json($this->request('POST', $this->interactivePath($sessionId, 'input'), [
            'json' => $event,
            'timeout' => $navigation ? 55 : 20,
            'max_duration' => $navigation ? 60 : 25,
        ])), $sessionId);
    }

    public function interactiveFrame(string $sessionId): string
    {
        $response = $this->request('GET', $this->interactivePath($sessionId, 'frame'), [
            'timeout' => 20,
            'max_duration' => 25,
        ]);
        $content = $response->getContent(false);
        $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
        if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'image/png' || !str_starts_with($content, "\x89PNG\r\n\x1a\n")) {
            throw new \RuntimeException('Browser worker returned an invalid PNG frame.');
        }

        return $content;
    }

    public function interactiveDialogFrame(string $sessionId, int $generation, ?string $candidateId = null): string
    {
        $this->assertGeneration($generation);
        if ($candidateId !== null && preg_match('/^[a-f0-9]{48}$/D', $candidateId) !== 1) {
            throw new \InvalidArgumentException('Invalid interactive dialog candidate.');
        }
        $query = ['generation' => $generation] + ($candidateId === null ? [] : ['candidateId' => $candidateId]);
        $response = $this->request('GET', $this->interactivePath($sessionId, 'dialog-frame').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), [
            'timeout' => 20, 'max_duration' => 25,
        ]);
        $content = $response->getContent(false);
        $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
        if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'image/png' || !str_starts_with($content, "\x89PNG\r\n\x1a\n")) {
            throw new \RuntimeException('Browser worker returned an invalid PNG frame.');
        }
        return $content;
    }

    public function captureInteractive(string $sessionId): BrowserScreenshotResult
    {
        $data = $this->json($this->request('POST', $this->interactivePath($sessionId, 'capture'), [
            'timeout' => 30,
            'max_duration' => 35,
        ]));
        if (!is_string($data['screenshotBase64'] ?? null) || $data['screenshotBase64'] === '') {
            throw new \RuntimeException('Browser worker did not return an interactive screenshot.');
        }
        $this->validateOptionalFields($data, [
            'httpStatus' => 'integer', 'finalUrl' => 'string', 'dialogSeen' => 'boolean',
            'dialogType' => 'string', 'dialogText' => 'string', 'captureMethod' => 'string', 'metadata' => 'array',
        ]);

        return $this->screenshotResult($data);
    }

    public function closeInteractive(string $sessionId): void
    {
        $response = $this->request('POST', $this->interactivePath($sessionId, 'close'), [
            'timeout' => 20,
            'max_duration' => 25,
        ]);
        if ($response->getStatusCode() === 204 && $response->getContent(false) === '') {
            return;
        }
        $data = $this->json($response);
        if (($data['closed'] ?? null) !== true || ($data['id'] ?? null) !== $sessionId) {
            throw new \RuntimeException('Browser worker did not confirm closure of the requested interactive session.');
        }
    }

    private function request(string $method, string $path, array $options): ResponseInterface
    {
        // Resolve lazily: an unconfigured interactive feature must not break the
        // independent screenshot queue or health probes when the container boots.
        if ($this->workerToken === null || trim($this->workerToken) === '') {
            throw new \RuntimeException('Interactive browser requests require PLAYWRIGHT_WORKER_TOKEN.');
        }
        if (preg_match('/^[\x21-\x7e]+$/D', $this->workerToken) !== 1) {
            throw new \RuntimeException('PLAYWRIGHT_WORKER_TOKEN must contain only visible ASCII characters without spaces.');
        }
        $options['headers']['Authorization'] = 'Bearer '.$this->workerToken;
        $options['max_redirects'] = 0;

        try {
            $response = $this->httpClient->request($method, rtrim($this->workerUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            // Consume here so lazy transport/body failures use the same exception
            // boundary. PNG data remains raw and is never passed to the JSON parser.
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $error) {
            throw new \RuntimeException('Browser worker request failed during transport.', 0, $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException($this->errorMessage($content, $status), $status);
        }

        return $response;
    }

    private function errorMessage(string $content, int $status): string
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($data) && is_string($data['errorMessage'] ?? null) && trim($data['errorMessage']) !== '') {
                return $data['errorMessage'];
            }
        } catch (\JsonException) {
        }

        return sprintf('Browser worker request failed with HTTP %d.', $status);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        try {
            $content = $response->getContent(false);
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Browser worker returned invalid JSON.', 0, $error);
        }
        if (!is_array($data) || !str_starts_with(ltrim($content), '{')) {
            throw new \RuntimeException('Browser worker returned an invalid JSON object.');
        }
        if (array_key_exists('errorMessage', $data) && $data['errorMessage'] !== null) {
            throw new \RuntimeException(
                is_string($data['errorMessage']) && trim($data['errorMessage']) !== ''
                    ? $data['errorMessage'] : 'Browser worker returned an invalid error response.',
                $response->getStatusCode(),
            );
        }

        return $data;
    }

    private function interactivePath(string $sessionId, string $operation): string
    {
        if (preg_match('/^[a-f0-9]{48}$/D', $sessionId) !== 1) {
            throw new \RuntimeException('Invalid interactive browser session ID.');
        }

        return '/interactive-sessions/'.$sessionId.'/'.$operation;
    }

    /** @param array<string, mixed> $data */
    private function sessionResult(array $data, ?string $expectedId = null): BrowserInteractiveSessionResult
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-f0-9]{48}$/D', $id) !== 1 || ($expectedId !== null && $id !== $expectedId)) {
            throw new \RuntimeException('Browser worker returned an invalid interactive session ID.');
        }
        $createdAt = $this->timestamp($data['createdAt'] ?? null, 'session creation timestamp');
        $expiresAt = $this->timestamp($data['expiresAt'] ?? null, 'session expiry timestamp');
        if ($expiresAt <= $createdAt) {
            throw new \RuntimeException('Browser worker returned an invalid session expiry timestamp.');
        }
        $this->validateOptionalFields($data, [
            'finalUrl' => 'string', 'httpStatus' => 'integer', 'challenge' => 'array',
            'initialChallenge' => 'array', 'navigationError' => 'string',
            'initialUrl' => 'string', 'initialHttpStatus' => 'integer', 'dialogObservation' => 'array', 'retainedDialog' => 'array',
            'captureFinalized' => 'boolean', 'dialogCapture' => 'array', 'urlCandidates' => 'array', 'urlCandidatesTruncated' => 'boolean',
        ]);
        if (isset($data['dialogCapture'])) {
            $dialog = $data['dialogCapture'];
            if (!in_array($dialog['state'] ?? null, ['waiting', 'capturing', 'captured', 'failed', 'exhausted'], true)
                || !is_int($dialog['revision'] ?? null) || $dialog['revision'] < 0
                || !is_bool($dialog['armed'] ?? null) || !is_bool($dialog['pending'] ?? null)
                || !is_int($dialog['attemptsRemaining'] ?? null) || $dialog['attemptsRemaining'] < 0) {
                throw new \RuntimeException('Browser worker returned an invalid dialogCapture.');
            }
        }
        foreach (['httpStatus', 'initialHttpStatus'] as $field) {
            if (isset($data[$field]) && ($data[$field] < 100 || $data[$field] > 599)) {
                throw new \RuntimeException('Browser worker returned an invalid '.$field.'.');
            }
        }

        return new BrowserInteractiveSessionResult(
            id: $id,
            createdAt: $createdAt,
            expiresAt: $expiresAt,
            finalUrl: $data['finalUrl'] ?? null,
            httpStatus: $data['httpStatus'] ?? null,
            challenge: $data['challenge'] ?? [],
            initialChallenge: $data['initialChallenge'] ?? [],
            navigationError: $data['navigationError'] ?? null,
            initialUrl: $data['initialUrl'] ?? null,
            initialHttpStatus: $data['initialHttpStatus'] ?? null,
            dialogObservation: $data['dialogObservation'] ?? [],
            generation: is_int($data['generation'] ?? null) ? $data['generation'] : null,
            captureLocked: ($data['captureLocked'] ?? false) === true,
            captureId: is_string($data['captureId'] ?? null) ? $data['captureId'] : null,
            captureGeneration: is_int($data['captureGeneration'] ?? null) ? $data['captureGeneration'] : null,
            retainedDialog: $data['retainedDialog'] ?? null,
            captureFinalized: $data['captureFinalized'] ?? false,
            dialogCapture: $data['dialogCapture'] ?? [],
            urlCandidates: $data['urlCandidates'] ?? [],
            urlCandidatesTruncated: $data['urlCandidatesTruncated'] ?? false,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $types
     */
    private function validateOptionalFields(array $data, array $types): void
    {
        foreach ($types as $field => $type) {
            if (isset($data[$field]) && gettype($data[$field]) !== $type) {
                throw new \RuntimeException(sprintf('Browser worker returned an invalid %s.', $field));
            }
        }
    }
}
