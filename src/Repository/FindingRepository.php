<?php

namespace App\Repository;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Value\EvidenceKind;
use App\Value\FindingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Finding>
 */
class FindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Finding::class);
    }

    public function findOneByDomainAndUrl(Domain $domain, string $url): ?Finding
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.domain = :domain')
            ->andWhere('f.url = :url')
            ->setParameter('domain', $domain)
            ->setParameter('url', $url)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countAllFindings(): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<string> $statuses
     */
    public function countByStatuses(array $statuses): int
    {
        if ($statuses === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.status IN (:statuses)')
            ->setParameter('statuses', $statuses)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Finding>
     */
    public function findByDomainAndStatus(?Domain $domain = null, ?string $status = null, ?string $type = null, ?string $severity = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->addSelect('d')
            ->innerJoin('f.domain', 'd')
            ->orderBy('f.submittedAt', 'DESC')
            ->addOrderBy('f.createdAt', 'DESC');

        if ($domain) {
            $qb->andWhere('f.domain = :domain')->setParameter('domain', $domain);
        }

        if ($status) {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        if ($type) {
            $qb->andWhere('f.type = :type')->setParameter('type', $type);
        }

        if ($severity) {
            $qb->andWhere('f.severity = :severity')->setParameter('severity', $severity);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Finding>
     */
    public function findPageByDomainAndStatus(?string $domainQuery = null, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('f')
            ->addSelect('d')
            ->innerJoin('f.domain', 'd')
            ->orderBy('f.submittedAt', 'DESC')
            ->addOrderBy('f.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult(max(0, $offset));

        $domainQuery = $domainQuery !== null ? trim($domainQuery) : null;
        if ($domainQuery !== null && $domainQuery !== '') {
            $qb->andWhere('LOWER(d.hostname) LIKE :domainQuery')
                ->setParameter('domainQuery', '%'.strtolower($domainQuery).'%');
        }

        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function countByDomainAndStatus(?string $domainQuery = null, ?string $status = null): int
    {
        $qb = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->innerJoin('f.domain', 'd');

        $domainQuery = $domainQuery !== null ? trim($domainQuery) : null;
        if ($domainQuery !== null && $domainQuery !== '') {
            $qb->andWhere('LOWER(d.hostname) LIKE :domainQuery')
                ->setParameter('domainQuery', '%'.strtolower($domainQuery).'%');
        }

        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<Finding>
     */
    public function findOpenFindingsWithoutEvidence(int $limit = 20): array
    {
        return $this->createQueryBuilder('f')
            ->select('DISTINCT f')
            ->addSelect('d')
            ->innerJoin('f.domain', 'd')
            ->leftJoin('f.evidence', 'e')
            ->andWhere('f.status IN (:statuses)')
            ->andWhere('e.id IS NULL')
            ->setParameter('statuses', FindingStatus::openValues())
            ->setMaxResults($limit)
            ->orderBy('f.submittedAt', 'ASC')
            ->addOrderBy('f.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countOpenFindingsForDomain(Domain $domain): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.domain = :domain')
            ->andWhere('f.status IN (:statuses)')
            ->setParameter('domain', $domain)
            ->setParameter('statuses', FindingStatus::openValues())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Finding>
     */
    public function findDueForRetest(?\DateTimeImmutable $olderThan = null, ?Domain $domain = null, ?string $status = null, int $limit = 20): array
    {
        $olderThan ??= new \DateTimeImmutable('-30 days');

        $qb = $this->createQueryBuilder('f')
            ->addSelect('d')
            ->innerJoin('f.domain', 'd')
            ->andWhere('f.status IN (:statuses)')
            ->andWhere('(f.lastRetestedAt IS NULL OR f.lastRetestedAt <= :olderThan)')
            ->setParameter('statuses', FindingStatus::openValues())
            ->setParameter('olderThan', $olderThan)
            ->setMaxResults($limit)
            ->orderBy('f.lastRetestedAt', 'ASC')
            ->addOrderBy('f.submittedAt', 'ASC')
            ->addOrderBy('f.createdAt', 'ASC');

        if ($domain) {
            $qb->andWhere('f.domain = :domain')->setParameter('domain', $domain);
        }

        if ($status) {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Finding>
     */
    public function findAllForBrowserRetest(?Domain $domain = null, ?string $status = null, int $limit = 1000): array
    {
        $qb = $this->createQueryBuilder('f')
            ->addSelect('d')
            ->innerJoin('f.domain', 'd')
            ->orderBy('f.submittedAt', 'ASC')
            ->addOrderBy('f.createdAt', 'ASC')
            ->setMaxResults($limit);

        if ($domain) {
            $qb->andWhere('f.domain = :domain')->setParameter('domain', $domain);
        }

        if ($status) {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Finding>
     */
    public function findAllWithoutScreenshotEvidence(?Domain $domain = null, ?string $status = null, int $limit = 1000): array
    {
        $qb = $this->createQueryBuilder('f')
            ->distinct()
            ->addSelect('d')
            ->innerJoin('f.domain', 'd')
            ->leftJoin('f.evidence', 'e', Join::WITH, 'e.kind = :screenshotKind')
            ->andWhere('e.id IS NULL')
            ->setParameter('screenshotKind', EvidenceKind::SCREENSHOT)
            ->orderBy('f.submittedAt', 'ASC')
            ->addOrderBy('f.createdAt', 'ASC')
            ->setMaxResults($limit);

        if ($domain) {
            $qb->andWhere('f.domain = :domain')->setParameter('domain', $domain);
        }

        if ($status) {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Finding>
     */
    public function findAllOrdered(int $limit = 1000): array
    {
        return $this->createQueryBuilder('f')
            ->addSelect('d')
            ->innerJoin('f.domain', 'd')
            ->orderBy('f.submittedAt', 'ASC')
            ->addOrderBy('f.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
