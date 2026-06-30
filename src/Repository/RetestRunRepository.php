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
        return $this->createQueryBuilder('r')
            ->andWhere('r.finding = :finding')
            ->setParameter('finding', $finding)
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
