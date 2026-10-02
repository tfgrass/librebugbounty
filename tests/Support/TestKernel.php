<?php

namespace App\Tests\Support;

final class TestKernel extends \App\Kernel
{
    public function getCacheDir(): string
    {
        return APP_TEST_ROOT.'/cache';
    }

    public function getLogDir(): string
    {
        return APP_TEST_ROOT.'/logs';
    }
}
