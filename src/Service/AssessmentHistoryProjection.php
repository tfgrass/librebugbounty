<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Shared effective-history rule, readable before the additive migration. */
final class AssessmentHistoryProjection
{
    public static function available(Connection $connection): bool
    {
        return (int) $connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('finding_assessment_reset', 'finding_assessment_cancellation')") === 2;
    }

    public static function activeSql(Connection $connection, string $alias = 'a'): string
    {
        if (!preg_match('/^[a-z_]+$/D', $alias)) { throw new \InvalidArgumentException('Invalid assessment SQL alias.'); }

        return self::available($connection)
            ? 'NOT EXISTS (SELECT 1 FROM finding_assessment_cancellation cancelled WHERE cancelled.assessment_id = '.$alias.'.id)'
            : '1 = 1';
    }
}
