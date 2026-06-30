<?php

namespace App\Dto;

final class StoredEvidenceResult
{
    public function __construct(
        public readonly string $relativePath,
        public readonly string $sha256,
        public readonly ?string $originalFilename = null,
    ) {
    }
}
