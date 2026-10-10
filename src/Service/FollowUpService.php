<?php
namespace App\Service;

use App\Entity\Finding;
use App\Value\PursuitStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class FollowUpService
{
    public const DEFAULT_CASE = ['pursuit' => 'active', 'reason' => null, 'contact_blocked' => false, 'checks_blocked' => false];
    public const DEFAULT_DOMAIN = ['contact_blocked' => false, 'checks_blocked' => false];
    public function __construct(private readonly Connection $connection) {}

    public function state(Finding $finding): array
    {
        return $this->connection->transactional(fn () => $this->readState($finding));
    }

    private function readState(Finding $finding): array
    {
        $available = FindingWorkPolicy::available($this->connection);
        $host = strtolower($finding->getDomain()->getHostname());
        $case = $available ? $this->connection->fetchAssociative('SELECT pursuit, reason, contact_blocked, checks_blocked, changed_at FROM finding_follow_up WHERE finding_id = ?', [$finding->getId()]) : false;
        $domain = $available ? $this->connection->fetchAssociative('SELECT contact_blocked, checks_blocked, changed_at FROM domain_work_restriction WHERE hostname = ?', [$host]) : false;
        $case = $case ?: self::DEFAULT_CASE;
        $domain = $domain ?: self::DEFAULT_DOMAIN;
        $case['contact_blocked'] = (bool) $case['contact_blocked']; $case['checks_blocked'] = (bool) $case['checks_blocked'];
        $domain['contact_blocked'] = (bool) $domain['contact_blocked']; $domain['checks_blocked'] = (bool) $domain['checks_blocked'];
        return ['available' => $available, 'case' => $case, 'domain' => $domain,
            'checksBlocked' => $case['pursuit'] === 'closed' || $case['checks_blocked'] || $domain['checks_blocked'],
            'revisions' => $available ? ['case' => $this->revision('case', $finding->getId()), 'domain' => $this->revision('domain', $host)] : [],
            'contactBlocked' => $case['pursuit'] === 'closed' || $case['contact_blocked'] || $domain['contact_blocked']];
    }

    /** Explicitly replaces only the selected scope; never resets another scope. */
    public function save(Finding $finding, array $input): void
    {
        if (!FindingWorkPolicy::available($this->connection)) throw new \LogicException('Bitte zuerst die Datenbankmigration für Nachverfolgung und Kontakte ausführen.');
        foreach (['scope', 'pursuit', 'reason', 'contact_blocked', 'checks_blocked', 'revision'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) throw new \InvalidArgumentException('Ungültige Angaben zur Nachverfolgung.');
        }
        $scope = $input['scope'] ?? 'case';
        if (!in_array($scope, ['case', 'domain'], true)) throw new \InvalidArgumentException('Ungültige Angaben zur Nachverfolgung.');
        foreach (['contact_blocked', 'checks_blocked'] as $flag) {
            if (!in_array($input[$flag] ?? '0', ['0', '1'], true)) throw new \InvalidArgumentException('Ungültige Angaben zur Nachverfolgung.');
        }
        $data = ['contact_blocked' => ($input['contact_blocked'] ?? '0') === '1', 'checks_blocked' => ($input['checks_blocked'] ?? '0') === '1'];
        if ($scope === 'case') {
            $pursuit = $input['pursuit'] ?? 'active';
            $reason = trim($input['reason'] ?? '');
            if (!in_array($pursuit, ['active', 'closed'], true) || ($reason !== '' && !isset(PursuitStatus::REASONS[$reason])) || ($pursuit === 'closed' && $reason === '')) throw new \InvalidArgumentException('Wähle einen gültigen Bearbeitungsstatus und beim Beenden einen Grund.');
            $data = ['pursuit' => $pursuit, 'reason' => $pursuit === 'closed' ? $reason : null] + $data;
            if ($reason === 'contact_refused' && $pursuit === 'closed') $data['contact_blocked'] = true;
            if ($reason === 'checks_refused' && $pursuit === 'closed') $data['checks_blocked'] = true;
        }
        $host = strtolower($finding->getDomain()->getHostname());
        $this->persist($scope, $scope === 'case' ? $finding->getId() : $host, $host, $finding->getId(), $data, $input['revision'] ?? null);
    }

    /** The last event is part of the revision: even change-and-revert invalidates an old tab. */
    public function revision(string $scope, string $key): string
    {
        $event = $this->connection->fetchOne('SELECT id FROM work_policy_event WHERE scope = ? AND '.($scope === 'case' ? 'finding_id' : 'hostname').' = ? ORDER BY rowid DESC LIMIT 1', [$scope, $key]);
        $state = $this->connection->fetchAssociative('SELECT * FROM '.($scope === 'case' ? 'finding_follow_up WHERE finding_id' : 'domain_work_restriction WHERE hostname').' = ?', [$key]);
        return hash('sha256', json_encode([$scope, $key, $state ?: null, $event ?: null], JSON_THROW_ON_ERROR));
    }

    public function liftDomain(string $hostname, string $field, string $revision): void
    {
        if (!FindingWorkPolicy::available($this->connection)) throw new \LogicException('Bitte zuerst die Datenbankmigration für Nachverfolgung und Kontakte ausführen.');
        if (!in_array($field, ['contact_blocked', 'checks_blocked'], true)) throw new \InvalidArgumentException('Ungültige Angaben zur Nachverfolgung.');
        $old = $this->connection->fetchAssociative('SELECT contact_blocked, checks_blocked FROM domain_work_restriction WHERE hostname = ?', [$hostname]);
        if (!$old) throw new \InvalidArgumentException('Domainsperre nicht gefunden.');
        $data = array_map(static fn ($v) => (bool) $v, $old);
        $data[$field] = false;
        $this->persist('domain', $hostname, $hostname, '', $data, $revision);
    }

    /** Targeted release keeps the closure reason and every other restriction intact. */
    public function liftCase(Finding $finding, string $field, string $revision): void
    {
        $state = $this->state($finding);
        if (!$state['available']) throw new \LogicException('Bitte zuerst die Datenbankmigration für Nachverfolgung und Kontakte ausführen.');
        if (!in_array($field, ['contact_blocked', 'checks_blocked', 'pursuit'], true)) throw new \InvalidArgumentException('Ungültige Angaben zur Nachverfolgung.');
        $data = $state['case'];
        unset($data['changed_at']);
        if ($field === 'pursuit') { $data['pursuit'] = 'active'; $data['reason'] = null; }
        else $data[$field] = false;
        $this->persist('case', $finding->getId(), strtolower($finding->getDomain()->getHostname()), $finding->getId(), $data, $revision);
    }

    private function persist(string $scope, string $keyValue, string $host, string $findingId, array $data, ?string $revision): void
    {
        $this->connection->transactional(function () use ($scope, $keyValue, $host, $findingId, $data, $revision): void {
            $table = $scope === 'case' ? 'finding_follow_up' : 'domain_work_restriction';
            $column = $scope === 'case' ? 'finding_id' : 'hostname';
            // SQLite write lock before checking revisions, including orphaned domains.
            $this->connection->executeStatement('UPDATE '.$table.' SET '.$column.' = '.$column.' WHERE '.$column.' = ?', [$keyValue]);
            if ($scope === 'case' && !$this->connection->fetchOne('SELECT id FROM finding WHERE id = ?', [$findingId])) throw new \InvalidArgumentException('Fall nicht gefunden.');
            if ($revision !== null && !hash_equals($this->revision($scope, $keyValue), $revision)) throw new \InvalidArgumentException('Die Sperren wurden inzwischen geändert. Bitte lade die Seite neu.');
            $old = $this->connection->fetchAssociative('SELECT '.implode(', ', array_keys($data)).' FROM '.$table.' WHERE '.$column.' = ?', [$keyValue]);
            $old = $old ?: ($scope === 'case' ? self::DEFAULT_CASE : self::DEFAULT_DOMAIN);
            foreach (['contact_blocked', 'checks_blocked'] as $flag) $old[$flag] = (bool) $old[$flag];
            if ($old === $data) return;
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $row = [$column => $keyValue] + $data + ['changed_at' => $now];
            $columns = array_keys($row);
            $sql = 'INSERT INTO '.$table.' ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).') ON CONFLICT('.$column.') DO UPDATE SET '.implode(', ', array_map(static fn ($column) => $column.' = excluded.'.$column, array_keys($data + ['changed_at' => $now])));
            $this->connection->executeStatement($sql, array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, array_values($row)));
            $this->connection->insert('work_policy_event', ['id' => Uuid::v7()->toRfc4122(), 'finding_id' => $findingId, 'hostname' => $host, 'scope' => $scope, 'previous_state' => json_encode($old, JSON_THROW_ON_ERROR), 'new_state' => json_encode($data, JSON_THROW_ON_ERROR), 'changed_at' => $now]);
            if (($data['pursuit'] ?? '') === 'closed' || $data['checks_blocked']) {
                $this->connection->executeStatement('UPDATE finding SET next_due_at = NULL WHERE '.($scope === 'case' ? 'id = ?' : 'domain_id IN (SELECT id FROM domain WHERE LOWER(hostname) = ?)'), [$scope === 'case' ? $findingId : $host]);
            }
        });
    }

    /** Includes retained domains without cases and independently blocked/closed cases. */
    public function restrictions(): array
    {
        return $this->connection->transactional(fn () => $this->readRestrictions());
    }

    private function readRestrictions(): array
    {
        if (!FindingWorkPolicy::available($this->connection)) return ['available' => false, 'domains' => [], 'cases' => [], 'history' => []];
        $domains = $this->connection->fetchAllAssociative('SELECT dw.*, (SELECT COUNT(*) FROM finding f INNER JOIN domain d ON d.id = f.domain_id WHERE LOWER(d.hostname) = dw.hostname) AS case_count FROM domain_work_restriction dw ORDER BY dw.hostname');
        foreach ($domains as &$row) $row['revision'] = $this->revision('domain', $row['hostname']);
        unset($row);
        $cases = $this->connection->fetchAllAssociative("SELECT fw.*, f.title, d.hostname FROM finding_follow_up fw INNER JOIN finding f ON f.id = fw.finding_id INNER JOIN domain d ON d.id = f.domain_id WHERE fw.contact_blocked = 1 OR fw.checks_blocked = 1 OR fw.pursuit = 'closed' ORDER BY d.hostname, f.id");
        foreach ($cases as &$row) $row['revision'] = $this->revision('case', $row['finding_id']);
        return ['available' => true, 'domains' => $domains, 'cases' => $cases,
            'history' => $this->connection->fetchAllAssociative('SELECT * FROM work_policy_event ORDER BY rowid DESC LIMIT 100')];
    }

    public function history(Finding $finding): array
    {
        if (!FindingWorkPolicy::available($this->connection)) return [];
        return $this->connection->fetchAllAssociative("SELECT scope, new_state, changed_at FROM work_policy_event WHERE (scope = 'case' AND finding_id = ?) OR (scope = 'domain' AND hostname = ?) ORDER BY changed_at DESC, rowid DESC LIMIT 20", [$finding->getId(), strtolower($finding->getDomain()->getHostname())]);
    }
}
