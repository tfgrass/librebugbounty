<?php
namespace App\Service;

use App\Dto\FindingReadFilter;
use App\Entity\Finding;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Human-entered bookkeeping only: no network, jobs or legacy contact-marker updates. */
final class DisclosureRecordService
{
    public const ACTIVITIES = ['reported' => 'Meldung abgegeben', 'response' => 'Antwort erhalten', 'note' => 'Notiz'];
    public const CHANNELS = ['email' => 'E-Mail', 'web' => 'Formular oder Meldeportal', 'phone' => 'Telefon', 'internal' => 'Intern', 'other' => 'Anderer Kanal'];
    public function __construct(private readonly Connection $connection) {}
    public static function available(Connection $connection): bool
    {
        return (int) $connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('disclosure_activity', 'disclosure_reminder')") === 2;
    }
    public static function today(): string { return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'); }
    public function state(Finding $finding): array
    {
        if (!self::available($this->connection)) return ['available' => false, 'activities' => [], 'reminder' => null, 'revision' => 'none'];
        $reminder = $this->connection->fetchAssociative('SELECT * FROM disclosure_reminder WHERE finding_id = ?', [$finding->getId()]);
        return ['available' => true, 'activities' => $this->connection->fetchAllAssociative('SELECT * FROM disclosure_activity WHERE finding_id = ? ORDER BY occurred_on DESC, rowid DESC', [$finding->getId()]), 'reminder' => $reminder ?: null, 'revision' => $reminder['revision'] ?? 'none'];
    }
    public function addActivity(Finding $finding, array $input): void
    {
        $this->assertAvailable();
        $id = $this->text($input['entry_id'] ?? '', 36);
        $activity = $this->text($input['activity'] ?? '', 16);
        $channel = $this->text($input['channel'] ?? '', 16);
        $day = $this->day($input['occurred_on'] ?? '');
        $recipient = $this->text($input['recipient'] ?? '', 2048);
        $ticket = $this->text($input['ticket'] ?? '', 255);
        $comment = $this->text($input['comment'] ?? '', 4000, true);
        if (!Uuid::isValid($id) || !isset(self::ACTIVITIES[$activity], self::CHANNELS[$channel]) || $recipient === '') throw new \InvalidArgumentException('Wähle Aktivität, Datum, Kanal und Empfänger.');
        $data = ['finding_id' => $finding->getId(), 'activity' => $activity, 'occurred_on' => $day, 'channel' => $channel, 'recipient' => $recipient, 'ticket' => $ticket === '' ? null : $ticket, 'comment' => $comment === '' ? null : $comment];
        $this->connection->transactional(function () use ($finding, $id, $data): void {
            $this->lock($finding);
            $existing = $this->connection->fetchAssociative('SELECT finding_id, activity, occurred_on, channel, recipient, ticket, comment FROM disclosure_activity WHERE id = ?', [$id]);
            if ($existing) {
                if ($existing !== $data) throw new \InvalidArgumentException('Dieser Verlaufseintrag wurde bereits gespeichert. Bitte lade die Seite neu.');
                return; // Repeated submission is not a second activity.
            }
            $this->connection->insert('disclosure_activity', ['id' => $id] + $data + ['recorded_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        });
    }
    public function reminder(Finding $finding, array $input): void
    {
        $this->assertAvailable();
        $revision = $this->text($input['revision'] ?? '', 36);
        $action = $this->text($input['action'] ?? '', 16);
        if (!in_array($action, ['save', 'complete'], true) || $revision === '') throw new \InvalidArgumentException('Ungültige Angaben zur Wiedervorlage.');
        $day = $action === 'save' ? $this->day($input['due_on'] ?? '') : null;
        $step = $action === 'save' ? $this->text($input['next_step'] ?? '', 255) : null;
        if ($action === 'save' && $step === '') throw new \InvalidArgumentException('Gib einen nächsten Schritt für die Wiedervorlage an.');
        $this->connection->transactional(function () use ($finding, $revision, $action, $day, $step): void {
            $this->lock($finding);
            $old = $this->connection->fetchAssociative('SELECT * FROM disclosure_reminder WHERE finding_id = ?', [$finding->getId()]);
            if (!hash_equals($old['revision'] ?? 'none', $revision)) throw new \InvalidArgumentException('Die Wiedervorlage wurde inzwischen geändert. Bitte lade die Seite neu.');
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            if ($action === 'complete') {
                if (!$old || $old['completed_at'] !== null) throw new \InvalidArgumentException('Keine offene Wiedervorlage vorhanden.');
                $this->connection->update('disclosure_reminder', ['completed_at' => $now, 'changed_at' => $now, 'revision' => Uuid::v7()->toRfc4122()], ['finding_id' => $finding->getId()]);
                return;
            }
            $data = ['due_on' => $day, 'next_step' => $step, 'completed_at' => null, 'changed_at' => $now, 'revision' => Uuid::v7()->toRfc4122()];
            if ($old) $this->connection->update('disclosure_reminder', $data, ['finding_id' => $finding->getId()]);
            else $this->connection->insert('disclosure_reminder', ['finding_id' => $finding->getId()] + $data);
        });
    }
    private function assertAvailable(): void
    {
        if (!self::available($this->connection)) throw new \LogicException('Bitte zuerst die Datenbankmigration für die Meldeakte ausführen.');
    }
    private function lock(Finding $finding): void
    {
        $this->connection->executeStatement('UPDATE finding SET id = id WHERE id = ?', [$finding->getId()]);
        if (!$this->connection->fetchOne('SELECT id FROM finding WHERE id = ?', [$finding->getId()])) throw new \InvalidArgumentException('Fall nicht gefunden.');
    }
    private function day(mixed $value): string
    {
        if (!is_string($value)) throw new \InvalidArgumentException('Ungültige Angaben zur Meldeakte.');
        FindingReadFilter::date($value); return $value;
    }
    private function text(mixed $value, int $max, bool $multiline = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max || preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u' : '/[\x00-\x1f\x7f]/u', $value)) throw new \InvalidArgumentException('Ungültige Angaben zur Meldeakte.');
        return trim($value);
    }
}
