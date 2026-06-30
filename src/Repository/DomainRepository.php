<?php

namespace App\Repository;

use App\Entity\Domain;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Domain>
 */
class DomainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Domain::class);
    }

    public function findOneByNormalizedHostname(string $hostname): ?Domain
    {
        return $this->findOneBy(['hostname' => $hostname]);
    }

    /**
     * @return list<Domain>
     */
    public function findAllOrdered(bool $authorizedOnly = false): array
    {
        $qb = $this->createQueryBuilder('d')
            ->orderBy('d.hostname', 'ASC');

        if ($authorizedOnly) {
            $qb->andWhere('d.authorized = true');
        }

        return $qb->getQuery()->getResult();
    }
}
