<?php
namespace App\Tests\Support;

final class ReviewRecoveryWorkerKernel extends \App\Kernel
{
    public function getCacheDir(): string { return (string) getenv('REVIEW_RECOVERY_CACHE_DIR'); }
    public function getLogDir(): string { return (string) getenv('REVIEW_RECOVERY_LOG_DIR'); }
}
