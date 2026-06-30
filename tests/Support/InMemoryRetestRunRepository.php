<?php

namespace App\Tests\Support;

use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Repository\RetestRunRepository;

final class InMemoryRetestRunRepository extends RetestRunRepository
{
    /** @var array<string, list<RetestRun>> */
    private array $runs = [];

    public function __construct()
    {
    }

    public function add(RetestRun $run): void
    {
        $findingId = $run->getFinding()->getId();
        $this->runs[$findingId][] = $run;
    }

    public function findRecentByFinding(Finding $finding, int $limit = 5): array
    {
        $runs = $this->runs[$finding->getId()] ?? [];
        usort($runs, static fn (RetestRun $a, RetestRun $b) => $b->getStartedAt() <=> $a->getStartedAt());

        return array_slice($runs, 0, $limit);
    }

    public function findAll(): array
    {
        $results = [];
        foreach ($this->runs as $runs) {
            foreach ($runs as $run) {
                $results[] = $run;
            }
        }

        return $results;
    }

    public function deleteByFinding(Finding $finding): int
    {
        $count = count($this->runs[$finding->getId()] ?? []);
        unset($this->runs[$finding->getId()]);

        return $count;
    }
}
