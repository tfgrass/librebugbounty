<?php

namespace App\Dto;

final class ImportResult
{
    public function __construct(
        public int $domainsCreated = 0,
        public int $findingsCreated = 0,
        public int $skipped = 0,
        public array $errors = [],
    ) {
    }
}
