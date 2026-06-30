<?php

namespace App\Service;

use App\Dto\ImportResult;
use App\Value\FindingStatus;
use Symfony\Component\Filesystem\Filesystem;

final class ImportService
{
    public function __construct(
        private readonly DomainService $domainService,
        private readonly FindingService $findingService,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function importTxt(string $filePath): ImportResult
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException(sprintf('Import file "%s" does not exist.', $filePath));
        }

        $result = new ImportResult();
        $lines = file($filePath, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $lineNumber => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $trimmed));
            if (count($parts) < 6) {
                $result->skipped++;
                $result->errors[] = sprintf('Line %d is malformed.', $lineNumber + 1);
                continue;
            }

            [$hostname, $type, $severity, $url, $payload, $expectedEvidence] = array_pad($parts, 6, null);

            try {
                $domainResult = $this->domainService->upsertDomain($hostname, 'https', true);
                if ($domainResult->created) {
                    $result->domainsCreated++;
                }

                $this->findingService->createFinding(
                    hostname: $hostname,
                    title: sprintf('%s from imported TXT list', $type),
                    type: $type,
                    severity: $severity,
                    url: $url,
                    method: 'GET',
                    requestParams: null,
                    payload: $payload,
                    expectedEvidence: $expectedEvidence,
                    status: FindingStatus::NEW,
                    allowUnauthorizedStore: true,
                );
                $result->findingsCreated++;
            } catch (\Throwable $exception) {
                $result->skipped++;
                $result->errors[] = sprintf('Line %d: %s', $lineNumber + 1, $exception->getMessage());
            }
        }

        return $result;
    }
}
