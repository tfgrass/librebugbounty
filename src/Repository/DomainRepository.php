<?php

namespace App\Repository;

use App\Entity\Domain;
use App\Value\FindingStatus;
use Doctrine\ORM\Query\Expr\Join;
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
            ->select('DISTINCT d')
            ->leftJoin('d.findings', 'activeFinding', Join::WITH, self::nonDiscardedCondition('activeFinding'))
            ->andWhere('activeFinding.id IS NOT NULL OR d.findings IS EMPTY')
            ->orderBy('d.hostname', 'ASC');
        $this->setDiscardedParameters($qb);

        if ($authorizedOnly) {
            $qb->andWhere('d.authorized = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Domain>
     */
    public function findAllWithoutContactedOrFixedFindings(bool $authorizedOnly = false): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('DISTINCT d')
            ->innerJoin('d.findings', 'activeFinding', Join::WITH, self::nonDiscardedCondition('activeFinding'))
            ->leftJoin('d.findings', 'f', Join::WITH, '('.self::nonDiscardedCondition('f').') AND (f.contactedAt IS NOT NULL OR f.status = :fixedStatus)')
            ->andWhere('f.id IS NULL')
            ->setParameter('fixedStatus', 'fixed')
            ->orderBy('d.hostname', 'ASC');
        $this->setDiscardedParameters($qb);

        if ($authorizedOnly) {
            $qb->andWhere('d.authorized = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Domain>
     */
    public function findAllWithContactedFindings(bool $authorizedOnly = false): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('DISTINCT d')
            ->innerJoin('d.findings', 'f', Join::WITH, 'f.contactedAt IS NOT NULL AND ('.self::nonDiscardedCondition('f').')')
            ->orderBy('d.hostname', 'ASC');
        $this->setDiscardedParameters($qb);

        if ($authorizedOnly) {
            $qb->andWhere('d.authorized = true');
        }

        return $qb->getQuery()->getResult();
    }

    private static function nonDiscardedCondition(string $alias): string
    {
        return sprintf('(%1$s.manualAssessment IS NULL OR %1$s.manualAssessment <> :discardedAssessment) AND %1$s.status NOT IN (:discardedStatuses)', $alias);
    }

    private function setDiscardedParameters(\Doctrine\ORM\QueryBuilder $qb): void
    {
        $qb->setParameter('discardedAssessment', 'discarded')
            ->setParameter('discardedStatuses', [FindingStatus::DUPLICATE, FindingStatus::DISCARDED]);
    }
}
