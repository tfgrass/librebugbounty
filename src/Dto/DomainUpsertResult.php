<?php

namespace App\Dto;

use App\Entity\Domain;

final class DomainUpsertResult
{
    public function __construct(
        public readonly Domain $domain,
        public readonly bool $created,
        public readonly bool $updated,
    ) {
    }
}
