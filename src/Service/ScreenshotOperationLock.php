<?php

namespace App\Service;

final class ScreenshotOperationLock
{
    public function synchronized(callable $operation): mixed
    {
        return $this->withLock('librebugbounty-screenshot-operation.lock', $operation);
    }

    public function synchronizedQueueMutation(callable $operation): mixed
    {
        return $this->withLock('librebugbounty-screenshot-queue-mutation.lock', $operation);
    }

    public function synchronizedMaintenance(callable $operation): mixed
    {
        return $this->synchronized(
            fn (): mixed => $this->synchronizedQueueMutation($operation),
        );
    }

    private function withLock(string $filename, callable $operation): mixed
    {
        $handle = fopen(sys_get_temp_dir().'/'.$filename, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a screenshot operation lock.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not acquire a screenshot operation lock.');
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
