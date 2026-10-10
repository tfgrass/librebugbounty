<?php

namespace App\Service;

use App\Value\FindingReadLabels;
use App\Value\FindingSeverity;
use App\Value\FindingStatus;
use App\Value\RetestResult;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Inventory preferences only: never loads, changes or schedules a finding. */
final class InventoryViewService
{
    private const PREFIX = 'inventory.saved_view.';
    public const MAX_NAME_LENGTH = 80;
    public const MAX_FILTER_BYTES = 8192;

    public function __construct(
        private readonly Connection $connection,
        private readonly FindingListService $lists,
        private readonly UiTranslator $i18n,
    ) {
    }

    /** @param array<string, mixed> $query @return array<string, string> */
    public function normalize(array $query): array
    {
        [$filter] = $this->lists->parse($query);
        $normalized = $this->lists->filterQuery($filter);
        // Exact matching has no effect without a domain. Paging, display size,
        // feedback and navigation context are intentionally not view filters.
        if ($filter->domain === '') {
            unset($normalized['exact_domain']);
        }
        return $normalized;
    }

    /** @param array<string, string> $query */
    public function url(array $query): string
    {
        return '/findings?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return list<array{id: string, name: string, query: ?array, url: ?string, description: string}> */
    public function all(): array
    {
        $views = [];
        // Individual rows avoid rewriting a shared collection when two tabs save.
        foreach ($this->connection->fetchAllAssociative('SELECT id, value FROM setting WHERE SUBSTR(id, 1, ?) = ? ORDER BY updated_at DESC, id DESC', [strlen(self::PREFIX), self::PREFIX]) as $row) {
            $id = substr($row['id'], strlen(self::PREFIX));
            if (!Uuid::isValid($id)) {
                continue;
            }
            $name = $this->i18n->trans('Nicht lesbare Ansicht');
            $query = null;
            try {
                $data = $this->decode($row['value']);
                $name = $this->name($data['name'] ?? null);
                $query = $this->normalize($data['filters']);
            } catch (\InvalidArgumentException|\JsonException) {
                // Keep damaged/unsupported preferences visible and deletable.
            }
            $views[] = ['id' => $id, 'name' => $name, 'query' => $query, 'url' => $query === null ? null : $this->url($query),
                'description' => $query === null ? $this->i18n->trans('Diese Ansicht kann nicht geöffnet werden. Bitte lösche sie und speichere die Filter erneut.') : $this->describe($query)];
        }

        return $views;
    }

    /** @param array<string, mixed> $query */
    public function create(mixed $name, array $query): string
    {
        $allowed = array_fill_keys([...array_keys($this->vocabulary()), 'legacyStatus', 'legacyBucket', 'status', 'bucket', 'exactDomain', 'page', 'pageSize', 'message', 'error', 'return_to'], true);
        if (array_diff_key($query, $allowed) !== []) {
            throw new \InvalidArgumentException('Ungültige Ansichtsangaben.');
        }
        $filters = $this->normalize($query);
        if (strlen(json_encode($filters, JSON_THROW_ON_ERROR)) > self::MAX_FILTER_BYTES) {
            throw new \InvalidArgumentException('Die Filter sind zu lang für eine gespeicherte Ansicht.');
        }
        $value = json_encode(['version' => 1, 'name' => $this->name($name), 'filters' => $filters], JSON_THROW_ON_ERROR);
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('setting', ['id' => self::PREFIX.$id, 'value' => $value, 'updated_at' => gmdate('Y-m-d H:i:s')]);

        return $id;
    }

    public function rename(string $id, mixed $name): void
    {
        $name = $this->name($name);
        $key = $this->key($id);
        $raw = $this->connection->fetchOne('SELECT value FROM setting WHERE id = ?', [$key]);
        if ($raw === false) {
            throw new \OutOfBoundsException('Ansicht nicht gefunden.');
        }
        try {
            $data = $this->decode($raw);
        } catch (\InvalidArgumentException|\JsonException) {
            throw new \InvalidArgumentException('Diese Ansicht kann nicht geöffnet werden. Bitte lösche sie und speichere die Filter erneut.');
        }
        $data['name'] = $name;
        // Filters are immutable: changing the applied filters never overwrites a
        // saved view. A concurrent deletion must not recreate the preference.
        if ($this->connection->update('setting', ['value' => json_encode($data, JSON_THROW_ON_ERROR), 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $key]) === 0) {
            throw new \OutOfBoundsException('Ansicht nicht gefunden.');
        }
    }

    public function delete(string $id): void
    {
        if ($this->connection->delete('setting', ['id' => $this->key($id)]) === 0) {
            throw new \OutOfBoundsException('Ansicht nicht gefunden.');
        }
    }

    /** Shared localized vocabulary for server-rendered and browser-local views. */
    public function vocabulary(): array
    {
        $fields = [
            'scope' => ['Bestand', ['active' => 'Aktiver Bestand', 'discarded' => 'Archiv: Verworfen', 'duplicates' => 'Archiv: Duplikate', 'all' => 'Alle Fälle']],
            'q' => ['Suche', null], 'domain' => ['Domain', null],
            'assessment' => ['Manuelle Bewertung', ['unknown' => FindingReadLabels::assessment(null), 'confirmed' => FindingReadLabels::assessment('confirmed'), 'fixed' => FindingReadLabels::assessment('fixed'), 'discarded' => FindingReadLabels::assessment('discarded')]],
            'observation' => ['Technische Beobachtung', array_combine([...RetestResult::values(), 'none'], array_map(static fn (string $value): string => FindingReadLabels::observation($value === 'none' ? null : $value), [...RetestResult::values(), 'none']))],
            'contact' => ['Kontakt', ['yes' => 'Kontaktiert', 'no' => 'Nicht kontaktiert']],
            'legacy_status' => ['Historischer Status', array_combine(FindingStatus::values(), FindingStatus::values())],
            'legacy_bucket' => ['Historische Gruppe', array_combine(['open', 'fixed', 'manual_review', 'unchecked'], ['open', 'fixed', 'manual_review', 'unchecked'])],
            'legacy_review' => ['Frühere Review-Markierung', ['confirmed_fixed' => 'Als behoben markiert', 'manually_checked' => 'Als geprüft markiert']],
            'type' => ['Typ', null], 'severity' => ['Schweregrad', array_combine(FindingSeverity::values(), FindingSeverity::values())],
            'exact_domain' => ['Domainvergleich', ['1' => 'Entspricht dem Domainfilter exakt']],
            'event' => ['Ereignis', ['reported' => 'Gemeldet (Ingest)', 'sent' => 'Erstmals versendet', 'contacted' => 'Als kontaktiert markiert', 'confirmed' => 'Erstmals manuell bestätigt', 'fixed' => 'Erstmals manuell behoben']],
            'from' => ['Vom Tag', null], 'to' => ['Bis einschließlich', null], 'tld' => ['TLD', null],
            'sent' => ['Versand', ['yes' => 'Versand erfasst', 'no' => 'Kein Versand erfasst']],
        ];
        $result = [];
        foreach ($fields as $key => [$label, $values]) {
            $result[$key] = ['label' => $this->i18n->trans($label), 'values' => $values === null ? null : (object) array_map($this->i18n->trans(...), $values)];
        }

        return $result;
    }

    /** @param array<string, string> $query */
    public function describe(array $query): string
    {
        $parts = [];
        foreach ($this->vocabulary() as $key => $field) {
            if (!isset($query[$key]) || ($key === 'scope' && $query[$key] === 'active' && count($query) > 1)) {
                continue;
            }
            $value = $field['values'] === null ? $query[$key] : ($field['values']->{$query[$key]} ?? $query[$key]);
            $parts[] = $field['label'].': '.$value;
        }

        return implode(' · ', $parts);
    }

    private function name(mixed $name): string
    {
        if (!is_string($name) || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
            throw new \InvalidArgumentException('Der Ansichtsname muss 1 bis 80 Zeichen lang sein und darf keine Steuerzeichen enthalten.');
        }
        $name = preg_replace('/^\s+|\s+$/u', '', $name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException('Der Ansichtsname muss 1 bis 80 Zeichen lang sein und darf keine Steuerzeichen enthalten.');
        }

        return $name;
    }

    private function decode(mixed $raw): array
    {
        $data = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['filters'] ?? null)) {
            throw new \InvalidArgumentException('Ungültige Ansichtsangaben.');
        }
        if (array_diff_key($data['filters'], $this->vocabulary()) !== []) {
            throw new \InvalidArgumentException('Ungültige Ansichtsangaben.');
        }

        return $data;
    }

    private function key(string $id): string
    {
        if (!Uuid::isValid($id)) {
            throw new \OutOfBoundsException('Ansicht nicht gefunden.');
        }

        return self::PREFIX.strtolower($id);
    }
}
