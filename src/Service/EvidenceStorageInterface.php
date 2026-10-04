<?php

namespace App\Service;

use App\Dto\StoredEvidenceResult;
use App\Entity\Finding;

interface EvidenceStorageInterface
{
    public function storeFile(Finding $finding, string $sourcePath, ?string $targetFilename = null): StoredEvidenceResult;

    public function storeContents(Finding $finding, string $contents, string $filename): StoredEvidenceResult;

    public function exists(string $path): bool;

    /** Returns the current byte size, or null when the path is invalid or unreadable. */
    public function size(string $path): ?int;

    public function read(string $path): string;

    public function deleteFile(string $path): void;

    public function deleteForFinding(Finding $finding): void;

    public function clear(): void;

    /** @return list<string> Logical paths, including the legacy storage/artifacts/ prefix. */
    public function listPaths(): array;
}
