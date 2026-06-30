<?php

namespace App\Tests\Support;

use App\Entity\Evidence;
use App\Entity\Finding;
use App\Repository\EvidenceRepository;

final class InMemoryEvidenceRepository extends EvidenceRepository
{
    /** @var array<string, Evidence> */
    private array $evidence = [];

    public function __construct()
    {
    }

    public function add(Evidence $evidence): void
    {
        $this->evidence[$evidence->getId()] = $evidence;
    }

    public function remove(Evidence $evidence): void
    {
        unset($this->evidence[$evidence->getId()]);
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $results = array_values($this->evidence);

        if (isset($criteria['finding']) && $criteria['finding'] instanceof Finding) {
            $finding = $criteria['finding'];
            $results = array_values(array_filter($results, static fn (Evidence $evidence): bool => $evidence->getFinding()->getId() === $finding->getId()));
        }

        return $results;
    }

    public function findAll(): array
    {
        return array_values($this->evidence);
    }
}
