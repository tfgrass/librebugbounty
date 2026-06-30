<?php

namespace App\Service;

use App\Dto\StoredEvidenceResult;
use App\Entity\Finding;

interface EvidenceStorageInterface
{
    public function storeFile(Finding $finding, string $sourcePath, ?string $targetFilename = null): StoredEvidenceResult;
}
