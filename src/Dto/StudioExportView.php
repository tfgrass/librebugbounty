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
        public string $profile = 'state',
        public bool $includeRequestData = true,
        public bool $includeAssessment = true,
        public bool $includeContact = true,
        public string $screenshotMode = 'none',
        public int $screenshotCount = 0,
        public int $missingScreenshotCount = 0,
        public int $unknownBasisCount = 0,
        public string $downloadFormat = 'json',
    ) {
    }
}
