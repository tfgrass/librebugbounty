<?php

namespace App\Service;

use App\Dto\ScreenshotEnqueueResult;
use App\Entity\Finding;

interface ScreenshotEnqueuerInterface
{
    public function enqueue(Finding $finding): ScreenshotEnqueueResult;
}
