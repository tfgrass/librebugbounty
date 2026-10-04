<?php

namespace App\Repository;

use App\Entity\Finding;
use App\Entity\RetestRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RetestRun>
 */
class RetestRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RetestRun::class);
    }

    /**
     * @return list<RetestRun>
     */
    public function findRecentByFinding(Finding $finding, int $limit = 5): array
    {
        // SQLite datetimes have second precision. Persisted insertion order
        // resolves ties without treating random UUIDs as a time sequence.
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM retest_run WHERE finding_id = ? ORDER BY COALESCE(finished_at, started_at) DESC, rowid DESC LIMIT '.max(0, $limit),
            [$finding->getId()],
        );
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->findBy(['id' => $ids]) as $run) {
            $byId[$run->getId()] = $run;
        }

        return array_values(array_map(static fn (string $id): RetestRun => $byId[$id], $ids));
    }

    /** @param list<string> $knownObservationIds IDs recorded when the manual decision was made, not a claim of sighting. */
    public function hasObservationAfterAssessment(Finding $finding, array $knownObservationIds): bool
    {
        $currentIds = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM retest_run WHERE finding_id = ?', [$finding->getId()],
        );

        return array_diff($currentIds, $knownObservationIds) !== [];
    }

    public function deleteByFinding(Finding $finding): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.finding = :finding')
            ->setParameter('finding', $finding)
            ->getQuery()
            ->execute();
    }
}
