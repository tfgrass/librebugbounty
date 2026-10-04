<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/** Scalar reads only: no entities, browser work, artifacts or writes. */
final class StatisticsRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function firstActivityAt(): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT MIN(event_at) FROM ('
            .'SELECT COALESCE(submitted_at, created_at) AS event_at FROM finding '
            .'UNION ALL SELECT contacted_at FROM finding WHERE contacted_at IS NOT NULL '
            .'UNION ALL SELECT a.assessed_at FROM finding_assessment a INNER JOIN finding f ON f.id = a.finding_id WHERE a.assessment = \'fixed\''
            .')',
        );

        return is_string($value) ? $value : null;
    }

    public function firstAssessmentAt(): ?string
    {
        $value = $this->connection->fetchOne('SELECT MIN(a.assessed_at) FROM finding_assessment a INNER JOIN finding f ON f.id = a.finding_id');

        return is_string($value) ? $value : null;
    }

    /** @return iterable<array<string, mixed>> */
    public function caseFacts(): iterable
    {
        return $this->connection->iterateAssociative(
            'SELECT f.id, d.hostname, f.status, f.review_state, f.manual_assessment, f.discard_reason, '
            .'COALESCE(f.submitted_at, f.created_at) AS reported_at, '
            .'f.contacted_at, '
            .'a.confirmed_at, a.fixed_at, '
            .'CASE WHEN '.FindingReadRepository::ACTIVE.' THEN 1 ELSE 0 END AS active, '
            .'CASE WHEN '.FindingReadRepository::DUPLICATES.' THEN 1 ELSE 0 END AS duplicate '
            .'FROM finding f INNER JOIN domain d ON d.id = f.domain_id '
            .'LEFT JOIN (SELECT finding_id, '
            .'MIN(CASE WHEN assessment = \'confirmed\' THEN assessed_at END) AS confirmed_at, '
            .'MIN(CASE WHEN assessment = \'fixed\' THEN assessed_at END) AS fixed_at '
            .'FROM finding_assessment GROUP BY finding_id) a ON a.finding_id = f.id',
        );
    }
}
