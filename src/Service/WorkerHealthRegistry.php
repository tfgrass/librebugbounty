<?php

namespace App\Service;

/** Expected worker IDs and their configured browser services, never target URLs. */
final class WorkerHealthRegistry
{
    /** @param array<string, array<string, string>> $workers */
    public function __construct(private readonly array $workers)
    {
    }

    /** @return array<string, string> */
    public function forKind(string $kind): array
    {
        return $this->workers[$kind] ?? [];
    }

    /** @return list<string> */
    public function browserUrls(): array
    {
        return array_values(array_unique(array_merge(...array_values($this->workers))));
    }
}
