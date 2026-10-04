<?php

namespace App\Dto;

final readonly class StudioExportView
{
    /** @param array<string, string> $filterQuery */
    public function __construct(
        public FindingReadFilter $filter,
        public array $filterQuery,
        public int $findingCount,
        public int $domainCount,
        public bool $includePrivateNotes,
        public string $downloadPath,
        public string $inventoryPath,
    ) {
    }
}
