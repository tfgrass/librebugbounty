<?php

namespace App\Service;

use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;
use Symfony\Contracts\HttpClient\ResponseInterface;

interface BrowserRetestTransportInterface
{
    public function request(BrowserRetestRequest $request): ResponseInterface;

    public function decode(ResponseInterface $response): RetestResultData;
}
