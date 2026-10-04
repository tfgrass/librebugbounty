<?php

namespace App\Service;

use App\Dto\ReviewNoticeView;
use Doctrine\DBAL\Connection;

/** The one policy for queue/detail/list hints, based solely on stored records. */
final class ReviewNoticeService
{
    public function __construct(private readonly Connection $connection, private readonly ReviewSchema $schema) {}

    public function available(): bool { return $this->schema->available(); }

    /** @param ?list<string> $ids @return array<string, ReviewNoticeView> Includes resolved manual cases with an empty observation list. */
    public function forFindings(?array $ids = null): array
    {
        if (!$this->available() || $ids === []) {
            return [];
        }
        $where = "f.manual_assessment IN ('confirmed', 'fixed') AND f.status NOT IN ('duplicate', 'discarded')";
        $parameters = [];
        if ($ids !== null) {
            $where .= ' AND f.id IN ('.implode(',', array_fill(0, count($ids), '?')).')';
            $parameters = $ids;
        }
        $findings = $this->connection->fetchAllAssociative(
            'SELECT f.id, f.manual_assessment, f.assessed_at, a.id AS decision_id, a.assessment AS decision_assessment, a.assessed_at AS decision_at, a.known_observation_ids, a.known_observation_states '
            .'FROM finding f LEFT JOIN finding_assessment a ON a.id = (SELECT latest.id FROM finding_assessment latest WHERE latest.finding_id = f.id AND '.AssessmentHistoryProjection::activeSql($this->connection, 'latest').' ORDER BY latest.assessed_at DESC, latest.id DESC LIMIT 1) WHERE '.$where,
            $parameters,
        );
        if ($findings === []) { return []; }
        $findingIds = array_column($findings, 'id');
        $placeholders = implode(',', array_fill(0, count($findingIds), '?'));
        $runsByFinding = [];
        // Group the sort by the indexed finding_id so SQLite does not sort all
        // large observation bodies together. Each finding keeps its existing
        // newest-first time/rowid order and raw SELECT * fingerprint format.
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM retest_run WHERE finding_id IN ('.$placeholders.') ORDER BY finding_id, COALESCE(finished_at, started_at) DESC, rowid DESC', $findingIds) as $run) {
            $runsByFinding[$run['finding_id']][] = $run;
        }
        $acksByFinding = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM finding_review_acknowledgement WHERE finding_id IN ('.$placeholders.') ORDER BY reviewed_at DESC, id DESC', $findingIds) as $ack) {
            $acksByFinding[$ack['finding_id']][$ack['decision_key']] ??= $ack;
        }
        $result = [];
        foreach ($findings as $finding) {
            $matchingHistory = $finding['decision_id'] !== null && $finding['decision_assessment'] === $finding['manual_assessment'] && $finding['decision_at'] === $finding['assessed_at'];
            $decisionKey = $matchingHistory ? 'history:'.$finding['decision_id'] : 'legacy:'.hash('sha256', json_encode([$finding['manual_assessment'], $finding['assessed_at']]));
            $ack = $acksByFinding[$finding['id']][$decisionKey] ?? null;
            $states = $ack !== null ? json_decode($ack['known_observation_states'], true, 512, JSON_THROW_ON_ERROR)
                : ($matchingHistory && $finding['known_observation_states'] !== null ? json_decode($finding['known_observation_states'], true, 512, JSON_THROW_ON_ERROR) : null);
            $knownIds = $matchingHistory ? json_decode($finding['known_observation_ids'], true, 512, JSON_THROW_ON_ERROR) : null;
            $triggers = [];
            foreach ($runsByFinding[$finding['id']] ?? [] as $run) {
                $reason = $this->reason($finding['manual_assessment'], $run['result']);
                if ($reason === null) { continue; }
                if ($states !== null) {
                    $unseen = !isset($states[$run['id']]) || $states[$run['id']] !== self::runFingerprint($run);
                } elseif ($knownIds !== null) {
                    // Old histories still have a reliable ID arrival boundary.
                    // Later edits can be recognized where a later timestamp was
                    // retained; no missing past result is fabricated for ties.
                    $unseen = !in_array($run['id'], $knownIds, true)
                        || ($finding['assessed_at'] !== null && max($run['finished_at'] ?? $run['started_at'], $run['updated_at']) > $finding['assessed_at']);
                } else {
                    // No history: equal-second provenance is ambiguous, so keep
                    // potentially new qualifying observations visible.
                    $unseen = $finding['assessed_at'] === null || max($run['finished_at'] ?? $run['started_at'], $run['updated_at']) >= $finding['assessed_at'];
                }
                if ($unseen) { $triggers[] = $run + ['reason' => $reason]; }
            }
            $result[$finding['id']] = new ReviewNoticeView($finding['id'], $finding['manual_assessment'], $decisionKey, $states !== null || $knownIds !== null, $ack === null ? null : new \DateTimeImmutable($ack['reviewed_at']), $triggers);
        }
        return $result;
    }

    public function get(string $id): ?ReviewNoticeView { return $this->forFindings([$id])[$id] ?? null; }

    public function states(string $id): array
    {
        $states = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM retest_run WHERE finding_id = ? ORDER BY rowid ASC', [$id]) as $run) {
            $states[$run['id']] = self::runFingerprint($run);
        }
        return $states;
    }

    public static function runFingerprint(array $run): string { return hash('sha256', json_encode($run, JSON_THROW_ON_ERROR)); }

    private function reason(string $assessment, string $result): ?string
    {
        if (in_array($result, ['inconclusive', 'error'], true)) { return $result; }
        return ($assessment === 'confirmed' && $result === 'fixed') || ($assessment === 'fixed' && $result === 'still_vulnerable') ? 'contradiction' : null;
    }
}
