<?php

namespace App\Repository;

use App\Entity\Finding;
use App\Entity\FindingAssessment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FindingAssessment> */
class FindingAssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FindingAssessment::class);
    }

    /** @return list<FindingAssessment> */
    public function findRecentByFinding(Finding $finding, int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.finding = :finding')
            ->setParameter('finding', $finding)
            ->orderBy('a.assessedAt', 'DESC')
            // UUIDv7 preserves creation order when SQLite stores equal seconds.
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
