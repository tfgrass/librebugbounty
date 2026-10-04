<?php

namespace App\Dto;

use App\Entity\Finding;

final readonly class FindingCreateResult
{
    public function __construct(
        public Finding $finding,
        public bool $created,
    ) {
    }
}
