<?php

namespace App\Repository;

use App\Entity\Finding;
use App\Entity\FindingAssessment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use App\Service\ReviewSchema;

/** @extends ServiceEntityRepository<FindingAssessment> */
class FindingAssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly ?ReviewSchema $reviewSchema = null)
    {
        parent::__construct($registry, FindingAssessment::class);
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        if ($this->schemaAvailable()) {
            return parent::findBy($criteria, $orderBy, $limit, $offset);
        }
        $metadata = $this->getEntityManager()->getClassMetadata(FindingAssessment::class);
        $where = [];
        $parameters = [];
        foreach ($criteria as $field => $value) {
            $column = $field === 'finding' ? 'finding_id' : $metadata->getColumnName($field);
            $where[] = 'a.'.$column.' = :'.$field;
            $parameters[$field] = $value instanceof Finding ? $value->getId() : $value;
        }
        $order = [];
        foreach ($orderBy ?? [] as $field => $direction) {
            $order[] = 'a.'.$metadata->getColumnName($field).' '.(strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC');
        }
        $sql = 'SELECT a.*, NULL AS known_observation_states FROM finding_assessment a'
            .($where === [] ? '' : ' WHERE '.implode(' AND ', $where))
            .($order === [] ? '' : ' ORDER BY '.implode(', ', $order));
        if ($limit !== null) { $sql .= ' LIMIT '.max(0, $limit).' OFFSET '.max(0, $offset ?? 0); }
        $mapping = new ResultSetMappingBuilder($this->getEntityManager());
        $mapping->addRootEntityFromClassMetadata(FindingAssessment::class, 'a');
        return $this->getEntityManager()->createNativeQuery($sql, $mapping)->setParameters($parameters)->setHint(Query::HINT_REFRESH, true)->getResult();
    }

    /** @return list<FindingAssessment> */
    public function findRecentByFinding(Finding $finding, int $limit = 20): array
    {
        if (!$this->schemaAvailable()) {
            return $this->findBy(['finding' => $finding], ['assessedAt' => 'DESC', 'id' => 'DESC'], $limit);
        }
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

    private function schemaAvailable(): bool
    {
        return ($this->reviewSchema ?? new ReviewSchema($this->getEntityManager()->getConnection()))->available();
    }
}
