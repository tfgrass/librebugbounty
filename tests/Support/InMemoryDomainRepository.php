<?php

namespace App\Tests\Support;

use App\Entity\Domain;
use App\Repository\DomainRepository;

final class InMemoryDomainRepository extends DomainRepository
{
    /** @var array<string, Domain> */
    private array $domains = [];

    public function __construct()
    {
    }

    public function add(Domain $domain): void
    {
        $this->domains[$domain->getHostname()] = $domain;
    }

    public function findOneByNormalizedHostname(string $hostname): ?Domain
    {
        return $this->domains[$hostname] ?? null;
    }

    public function findAllOrdered(bool $authorizedOnly = false): array
    {
        $domains = array_values($this->domains);
        usort($domains, static fn (Domain $a, Domain $b) => $a->getHostname() <=> $b->getHostname());

        if ($authorizedOnly) {
            $domains = array_values(array_filter($domains, static fn (Domain $domain) => $domain->isAuthorized()));
        }

        return $domains;
    }
}
