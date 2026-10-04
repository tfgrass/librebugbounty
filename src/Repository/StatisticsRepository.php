<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\AssessmentHistoryProjection;

use Doctrine\DBAL\Connection;

/** Scalar reads only: no entities, browser work, artifacts or writes. */
final class StatisticsRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{activity: ?string, assessment: ?string} */
    public function coverageDates(): array
    {
        // Each source is scanned once. Keep the stored timestamp strings intact:
        // the service owns conversion from the storage zone to Berlin dates.
        $row = $this->connection->fetchAssociative(
            'SELECT f.reported_at, f.contacted_at, a.assessed_at, a.fixed_at FROM ('
            .'SELECT MIN(COALESCE(submitted_at, created_at)) AS reported_at, MIN(contacted_at) AS contacted_at FROM finding'
            .') f CROSS JOIN ('
            .'SELECT MIN(a.assessed_at) AS assessed_at, MIN(CASE WHEN a.assessment = \'fixed\' THEN a.assessed_at END) AS fixed_at '
            .'FROM finding_assessment a INNER JOIN finding f ON f.id = a.finding_id WHERE '.AssessmentHistoryProjection::activeSql($this->connection)
            .') a',
        );
        $activity = array_filter([$row['reported_at'], $row['contacted_at'], $row['fixed_at']], is_string(...));

        return [
            'activity' => $activity === [] ? null : min($activity),
            'assessment' => is_string($row['assessed_at']) ? $row['assessed_at'] : null,
        ];
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
            .'FROM finding_assessment a WHERE '.AssessmentHistoryProjection::activeSql($this->connection).' GROUP BY finding_id) a ON a.finding_id = f.id',
        );
    }
}
