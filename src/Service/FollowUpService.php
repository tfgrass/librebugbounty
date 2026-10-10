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
            'contactBlocked' => $case['pursuit'] === 'closed' || $case['contact_blocked'] || $domain['contact_blocked']];
    }

    /** Explicitly replaces only the selected scope; never resets another scope. */
    public function save(Finding $finding, array $input): void
    {
        if (!FindingWorkPolicy::available($this->connection)) throw new \LogicException('Bitte zuerst die Datenbankmigration für Nachverfolgung und Kontakte ausführen.');
        foreach (['scope', 'pursuit', 'reason', 'contact_blocked', 'checks_blocked'] as $field) {
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
        $this->connection->transactional(function () use ($finding, $scope, $data, $host): void {
            // Take the write lock before reading the state used by the audit.
            $this->connection->executeStatement('UPDATE finding SET id = id WHERE id = ?', [$finding->getId()]);
            $old = $this->state($finding)[$scope];
            unset($old['changed_at']);
            if ($old === $data) return;
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $table = $scope === 'case' ? 'finding_follow_up' : 'domain_work_restriction';
            $key = $scope === 'case' ? ['finding_id' => $finding->getId()] : ['hostname' => $host];
            $row = $key + $data + ['changed_at' => $now];
            $columns = array_keys($row);
            $sql = 'INSERT INTO '.$table.' ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).') ON CONFLICT('.array_key_first($key).') DO UPDATE SET '.implode(', ', array_map(static fn ($column) => $column.' = excluded.'.$column, array_keys($data + ['changed_at' => $now])));
            $this->connection->executeStatement($sql, array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, array_values($row)));
            $this->connection->insert('work_policy_event', ['id' => Uuid::v7()->toRfc4122(), 'finding_id' => $finding->getId(), 'hostname' => $host, 'scope' => $scope, 'previous_state' => json_encode($old, JSON_THROW_ON_ERROR), 'new_state' => json_encode($data, JSON_THROW_ON_ERROR), 'changed_at' => $now]);
            if (($data['pursuit'] ?? '') === 'closed' || $data['checks_blocked']) {
                $this->connection->executeStatement('UPDATE finding SET next_due_at = NULL WHERE '.($scope === 'case' ? 'id = ?' : 'domain_id IN (SELECT id FROM domain WHERE LOWER(hostname) = ?)'), [$scope === 'case' ? $finding->getId() : $host]);
            }
        });
    }

    public function history(Finding $finding): array
    {
        if (!FindingWorkPolicy::available($this->connection)) return [];
        return $this->connection->fetchAllAssociative("SELECT scope, new_state, changed_at FROM work_policy_event WHERE (scope = 'case' AND finding_id = ?) OR (scope = 'domain' AND hostname = ?) ORDER BY changed_at DESC, rowid DESC LIMIT 20", [$finding->getId(), strtolower($finding->getDomain()->getHostname())]);
    }
}
