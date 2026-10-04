<?php

namespace App\Tests\Support;

final class ConcurrentWorkerKernel extends \App\Kernel
{
    public function getCacheDir(): string
    {
        return (string) getenv('INTAKE_CONCURRENT_CACHE_DIR');
    }

    public function getLogDir(): string
    {
        return (string) getenv('INTAKE_CONCURRENT_LOG_DIR');
    }
}
