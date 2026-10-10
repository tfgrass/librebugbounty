<?php
namespace App\Dto;

final readonly class ContactDiscoveryResult
{
    public function __construct(
        public string $status,
        public string $source,
        public array $contacts = [],
        public array $policies = [],
        public array $languages = [],
        public ?string $expires = null,
        public array $warnings = [],
    ) {
        if (!in_array($status, ['found', 'expired', 'not_found', 'invalid', 'error'], true)) throw new \InvalidArgumentException('Invalid contact discovery status.');
    }
    public function toArray(): array { return get_object_vars($this); }
}
