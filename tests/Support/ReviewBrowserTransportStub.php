<?php

namespace App\Tests\Support;

use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserRetestTransportInterface;
use App\Value\RetestResult;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ReviewBrowserTransportStub implements BrowserRetestClientInterface, BrowserRetestTransportInterface
{
    /** @var list<string> */
    public array $browserCalls = [];

    /** @var list<bool> */
    public array $headlessCalls = [];

    /** @var list<bool> */
    public array $screenshotCalls = [];

    /** @var list<string> */
    private array $results;

    /** @var list<\Throwable|null> */
    private array $requestFailures;

    private int $index = 0;

    public function __construct(array $results, array $requestFailures = [])
    {
        $this->results = array_values($results);
        $this->requestFailures = array_values($requestFailures);
    }

    public function retest(BrowserRetestRequest $request): RetestResultData
    {
        return $this->decode($this->request($request));
    }

    public function request(BrowserRetestRequest $request): ResponseInterface
    {
        $this->browserCalls[] = $request->browser;
        $this->headlessCalls[] = $request->headless;
        $this->screenshotCalls[] = $request->screenshot;

        $requestFailure = $this->requestFailures[$this->index] ?? null;
        $result = $this->results[$this->index] ?? RetestResult::STILL_VULNERABLE;
        $this->index++;

        if ($requestFailure instanceof \Throwable) {
            throw $requestFailure;
        }

        return new class($request, $result) implements ResponseInterface {
            public function __construct(
                private readonly BrowserRetestRequest $request,
                private readonly string $result,
            ) {
            }

            public function getStatusCode(): int
            {
                return 200;
            }

            public function getHeaders(bool $throw = true): array
            {
                return [];
            }

            public function getContent(bool $throw = true): string
            {
                return json_encode([
                    'result' => $this->result,
                    'httpStatus' => 200,
                    'finalUrl' => $this->request->url,
                    'observedEvidence' => $this->request->expectedEvidence,
                    'dialogText' => $this->request->expectedEvidence,
                ]);
            }

            public function toArray(bool $throw = true): array
            {
                return json_decode($this->getContent($throw), true, flags: JSON_THROW_ON_ERROR);
            }

            public function cancel(): void
            {
            }

            public function getInfo(?string $type = null): mixed
            {
                return null;
            }
        };
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
        );
    }
}
