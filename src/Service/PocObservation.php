<?php
namespace App\Service;

use Doctrine\DBAL\Connection;

/** Historical observations are current only for the exact PoC revision they tested. */
final class PocObservation
{
    public static function currentSql(Connection $connection, string $findingAlias = 'f', string $runAlias = 'latest'): string
    {
        $columns = $connection->fetchAllAssociative('PRAGMA table_info(finding)');
        if (!in_array('poc_revision', array_column($columns, 'name'), true)) return '1 = 1';
        $raw = "CASE WHEN json_valid($runAlias.raw_result) THEN $runAlias.raw_result ELSE '{}' END";
        return "COALESCE(json_extract($raw, '$.stalePoc'), 0) = 0 AND COALESCE(json_extract($raw, '$.staleFindingContext'), 0) = 0"
            ." AND COALESCE(json_extract($raw, '$.pocRevision'), 0) = COALESCE($findingAlias.poc_revision, 0)"
            ." AND (json_extract($raw, '$.pocUrl') IS NULL OR json_extract($raw, '$.pocUrl') = COALESCE($findingAlias.poc_url, $findingAlias.url))";
    }

    public static function current(array $run, array $finding): bool
    {
        $raw = json_decode($run['raw_result'] ?? '{}', true) ?: [];
        return !($raw['stalePoc'] ?? false) && !($raw['staleFindingContext'] ?? false)
            && (int) ($raw['pocRevision'] ?? 0) === (int) ($finding['poc_revision'] ?? 0)
            && (!isset($raw['pocUrl']) || $raw['pocUrl'] === ($finding['poc_url'] ?? $finding['url']));
    }
}
