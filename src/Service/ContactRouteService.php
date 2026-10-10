<?php
namespace App\Service;

use App\Entity\Finding;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Local bookkeeping only. No network, messages, queue jobs or assessment changes. */
final class ContactRouteService
{
    public function __construct(private readonly Connection $connection) {}

    public static function available(Connection $connection): bool
    {
        return (bool) $connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'finding_contact_route'");
    }

    public function state(Finding $finding): array
    {
        if (!self::available($this->connection)) return ['available' => false, 'selected' => null, 'revision' => 'none'];
        $row = $this->connection->fetchAssociative('SELECT * FROM finding_contact_route WHERE finding_id = ?', [$finding->getId()]);
        if ($row) $row['provenance'] = json_decode($row['provenance'], true, 32, JSON_THROW_ON_ERROR);
        return ['available' => true, 'selected' => $row ?: null, 'revision' => $row['revision'] ?? 'none'];
    }

    public function save(Finding $finding, array $input): void
    {
        if (!self::available($this->connection)) throw new \LogicException('Bitte zuerst die Datenbankmigration für die Kontaktwahl ausführen.');
        foreach (['mode', 'revision', 'channel', 'destination', 'person', 'source', 'notes', 'attempt_id', 'contact_index'] as $field) {
            if (array_key_exists($field, $input) && !is_string($input[$field])) throw new \InvalidArgumentException('Ungültige Angaben zur Kontaktwahl.');
        }
        if (!is_string($input['revision'] ?? null)) throw new \InvalidArgumentException('Ungültige Formularrevision. Bitte lade die Seite neu.');
        $mode = $input['mode'] ?? '';
        if (!in_array($mode, ['manual', 'suggestion'], true)) throw new \InvalidArgumentException('Ungültige Angaben zur Kontaktwahl.');
        $person = $this->text($input['person'] ?? '', 255);
        $source = $this->text($input['source'] ?? '', 2048);
        $notes = $this->text($input['notes'] ?? '', 4000, true);
        $this->connection->transactional(function () use ($finding, $input, $mode, $person, $source, $notes): void {
            // Acquire the write lock even when no selected route exists yet.
            $this->connection->executeStatement('UPDATE finding SET id = id WHERE id = ?', [$finding->getId()]);
            if (!$this->connection->fetchOne('SELECT id FROM finding WHERE id = ?', [$finding->getId()])) throw new \InvalidArgumentException('Fall nicht gefunden.');
            $state = $this->state($finding);
            if (!hash_equals($state['revision'], $input['revision'])) throw new \InvalidArgumentException('Der Meldeweg wurde inzwischen geändert. Bitte lade die Seite neu.');
            if ($mode === 'suggestion') {
                $id = $input['attempt_id'] ?? '';
                $index = $input['contact_index'] ?? '';
                if (!Uuid::isValid($id) || !preg_match('/^(0|[1-9][0-9]{0,3})$/D', $index)) throw new \InvalidArgumentException('Kontaktvorschlag nicht verfügbar.');
                $attempt = $this->connection->fetchAssociative('SELECT id, provider, result, fetched_at FROM contact_discovery WHERE id = ? AND finding_id = ?', [$id, $finding->getId()]);
                if (!$attempt) throw new \InvalidArgumentException('Kontaktvorschlag nicht verfügbar.');
                $result = json_decode($attempt['result'], true, 32, JSON_THROW_ON_ERROR);
                $contact = $result['contacts'][(int) $index] ?? null;
                $expires = $result['expires'] ?? null;
                if (!self::suggestionCurrent($result) || !is_array($contact)) throw new \InvalidArgumentException('Kontaktvorschlag nicht verfügbar.');
                [$channel, $destination] = $this->destination($contact['channel'] ?? '', $contact['value'] ?? '');
                $source = $this->text($result['source'] ?? '', 2048);
                $provenance = ['origin' => 'suggestion', 'attempt_id' => $id, 'provider' => $attempt['provider'], 'fetched_at' => $attempt['fetched_at'], 'expires' => $expires];
            } else {
                [$channel, $destination] = $this->destination($input['channel'] ?? '', $input['destination'] ?? '');
                $provenance = ['origin' => 'manual'];
            }
            $data = ['channel' => $channel, 'destination' => $destination, 'person' => $person === '' ? null : $person, 'source' => $source === '' ? null : $source, 'notes' => $notes === '' ? null : $notes,
                'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR), 'revision' => Uuid::v7()->toRfc4122(), 'changed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')];
            $row = ['finding_id' => $finding->getId()] + $data;
            $columns = array_keys($row);
            $this->connection->executeStatement('INSERT INTO finding_contact_route ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).') ON CONFLICT(finding_id) DO UPDATE SET '.implode(', ', array_map(static fn ($key) => $key.' = excluded.'.$key, array_keys($data))), array_values($row));
        });
    }

    public static function suggestionCurrent(array $result): bool
    {
        if (($result['status'] ?? '') !== 'found') return false;
        $expires = $result['expires'] ?? null;
        if ($expires === null) return true;
        if (!is_string($expires) || $expires === '') return false;
        try { return new \DateTimeImmutable($expires) > new \DateTimeImmutable(); }
        catch (\Exception) { return false; }
    }

    private function destination(mixed $channel, mixed $value): array
    {
        $value = $this->text($value, 2048);
        if ($channel === 'email') {
            if (str_starts_with(strtolower($value), 'mailto:')) $value = substr($value, 7);
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Gib eine gültige E-Mail-Adresse oder eine HTTP(S)-Formular-/Portal-URL an.');
        } elseif ($channel === 'web') {
            $parts = parse_url($value);
            if (!filter_var($value, FILTER_VALIDATE_URL) || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\s\x00-\x1f\x7f]/u', $value)) throw new \InvalidArgumentException('Gib eine gültige E-Mail-Adresse oder eine HTTP(S)-Formular-/Portal-URL an.');
        } else throw new \InvalidArgumentException('Ungültige Angaben zur Kontaktwahl.');
        return [$channel, $value];
    }

    private function text(mixed $value, int $max, bool $multiline = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max || preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u' : '/[\x00-\x1f\x7f]/u', $value)) throw new \InvalidArgumentException('Ungültige Angaben zur Kontaktwahl.');
        return trim($value);
    }
}
