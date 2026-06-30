<?php

namespace App\Service;

use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;

interface BrowserRetestClientInterface
{
    public function retest(BrowserRetestRequest $request): RetestResultData;
}
