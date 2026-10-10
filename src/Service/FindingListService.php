<?php

namespace App\Service;

use App\Dto\FindingListView;
use App\Dto\FindingReadFilter;
use App\Repository\FindingReadRepository;
use App\Repository\ScreenshotJobRepository;

/** Shared read-only list semantics for Studio and retained historical data. */
final class FindingListService
{
    private const LIST_QUERY_FIELDS = [
        'q', 'domain', 'assessment', 'observation', 'contact', 'scope',
        'legacy_status', 'legacyStatus', 'legacy_bucket', 'legacyBucket',
        'status', 'bucket', 'type', 'severity', 'exact_domain', 'exactDomain',
        'page', 'pageSize', 'event', 'from', 'to', 'tld', 'sent', 'legacy_review', 'pursuit', 'closure_reason', 'contact_work', 'reminder',
    ];

    public function __construct(
        private readonly FindingReadRepository $findings,
        private readonly ScreenshotJobRepository $screenshotJobs,
        private readonly SettingsService $settings,
    ) {
    }

    /** @param array<string, mixed> $query */
    public function get(array $query, string $path): FindingListView
    {
        // The saved page size affects the inventory view only. Shared parsing
        // stays independent of presentation preferences for exports and links.
        if (!array_key_exists('pageSize', $query)) {
            $query['pageSize'] = $this->settings->getInventoryPageSize();
        }
        [$filter, $page, $pageSizeSelection] = $this->parse($query);
        $totalFiltered = $this->findings->count($filter);
        $pageSize = $pageSizeSelection === 'all' ? max(1, $totalFiltered) : (int) $pageSizeSelection;
        $totalPages = max(1, (int) ceil($totalFiltered / $pageSize));
        $page = min($page, $totalPages);

        $stats = [
            'active' => ['label' => 'Aktiver Bestand', 'filter' => new FindingReadFilter()],
            'confirmed' => ['label' => 'Manuell bestätigt', 'filter' => new FindingReadFilter(assessment: 'confirmed')],
            'fixed' => ['label' => 'Manuell behoben', 'filter' => new FindingReadFilter(assessment: 'fixed')],
            'unknown' => ['label' => 'Ohne aufgezeichnete manuelle Bewertung', 'filter' => new FindingReadFilter(assessment: 'unknown')],
            'inconclusive' => ['label' => 'Technisch uneindeutig', 'filter' => new FindingReadFilter(observation: 'inconclusive')],
            'unobserved' => ['label' => 'Ohne technische Beobachtung', 'filter' => new FindingReadFilter(observation: 'none')],
            'contacted' => ['label' => 'Kontaktiert', 'filter' => new FindingReadFilter(contact: 'yes')],
            'discarded' => ['label' => 'Archiv: Verworfen', 'filter' => new FindingReadFilter(scope: 'discarded')],
            'duplicates' => ['label' => 'Archiv: Duplikate', 'filter' => new FindingReadFilter(scope: 'duplicates')],
        ];
        $statCounts = $this->findings->globalCounts();
        $statViews = [];
        foreach ($stats as $name => $stat) {
            $statViews[$name] = [
                'label' => $stat['label'],
                'count' => $statCounts[$name],
                'url' => $path.'?'.http_build_query($this->filterQuery($stat['filter']) + ['pageSize' => $pageSizeSelection]),
            ];
        }

        return new FindingListView(
            findings: $this->findings->findPage($filter, $pageSize, ($page - 1) * $pageSize),
            filter: $filter,
            pagination: [
                'page' => $page,
                'pageSize' => $pageSizeSelection,
                'totalFiltered' => $totalFiltered,
                'totalPages' => $totalPages,
            ],
            stats: $statViews,
            screenshotStats: $this->screenshotJobs->inventoryCounts(),
            filterQuery: $this->filterQuery($filter),
            path: $path,
        );
    }

    /** @return array<string, string> */
    public function filterQuery(FindingReadFilter $filter): array
    {
        return array_filter([
            'scope' => $filter->scope,
            'q' => $filter->q,
            'domain' => $filter->domain,
            'assessment' => $filter->assessment,
            'observation' => $filter->observation,
            'contact' => $filter->contact,
            'legacy_status' => $filter->legacyStatus,
            'legacy_bucket' => $filter->legacyBucket,
            'legacy_review' => $filter->legacyReview,
            'type' => $filter->type,
            'severity' => $filter->severity,
            'exact_domain' => $filter->exactDomain ? '1' : '',
            'event' => $filter->event,
            'from' => $filter->from,
            'to' => $filter->to,
            'tld' => $filter->tld,
            'sent' => $filter->sent,
            'pursuit' => $filter->pursuit,
            'closure_reason' => $filter->closureReason,
            'contact_work' => $filter->contactWork,
            'reminder' => $filter->reminder,
        ], static fn (string $value): bool => $value !== '');
    }

    /** @param array<string, mixed> $query */
    public function hasListQuery(array $query): bool
    {
        foreach (self::LIST_QUERY_FIELDS as $field) {
            if (array_key_exists($field, $query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{FindingReadFilter, int, string}
     */
    public function parse(array $query): array
    {
        foreach ([...self::LIST_QUERY_FIELDS, 'message', 'error'] as $field) {
            if (array_key_exists($field, $query) && (!is_string($query[$field]) || !mb_check_encoding($query[$field], 'UTF-8'))) {
                throw new \InvalidArgumentException('Filterangaben müssen einzelne Textwerte sein.');
            }
        }
        $get = static fn (string $name, string $default = ''): string => trim($query[$name] ?? $default);
        $pick = static function (array $names) use ($query, $get): string {
            $values = [];
            foreach ($names as $name) {
                if (array_key_exists($name, $query)) {
                    $values[] = $get($name);
                }
            }
            if (count(array_unique($values)) > 1) {
                throw new \InvalidArgumentException('Widersprüchliche Angaben für denselben Filter.');
            }

            return $values[0] ?? '';
        };
        $legacyStatus = $pick(['legacy_status', 'legacyStatus']);
        $legacyBucket = $pick(['legacy_bucket', 'legacyBucket', 'bucket']);
        $oldStatus = $get('status');
        if ($oldStatus !== '') {
            if (in_array($oldStatus, ['open', 'manual_review', 'unchecked'], true)) {
                if ($legacyBucket !== '' && $legacyBucket !== $oldStatus) {
                    throw new \InvalidArgumentException('Widersprüchliche Filter für die historische Gruppe.');
                }
                $legacyBucket = $oldStatus;
            } else {
                if ($legacyStatus !== '' && $legacyStatus !== $oldStatus) {
                    throw new \InvalidArgumentException('Widersprüchliche Filter für den historischen Status.');
                }
                $legacyStatus = $oldStatus;
            }
        }
        $scope = $get('scope', 'active');
        if (!array_key_exists('scope', $query)) {
            $scope = match ($legacyStatus) {
                'duplicate' => 'duplicates',
                'discarded' => 'discarded',
                default => 'active',
            };
        }
        $exactDomain = $pick(['exact_domain', 'exactDomain']);
        if (!in_array($exactDomain, ['', '0', '1'], true)) {
            throw new \InvalidArgumentException('Der exakte Domainfilter muss 0 oder 1 sein.');
        }
        $page = filter_var($get('page', '1'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pageSize = strtolower($get('pageSize', '10'));
        if ($page === false || !in_array($pageSize, ['10', '25', '50', '100', 'all'], true)) {
            throw new \InvalidArgumentException('Ungültige Seite oder Seitengröße.');
        }
        $filter = new FindingReadFilter(
            domain: $get('domain'),
            assessment: $get('assessment'),
            observation: $get('observation'),
            contact: $get('contact'),
            scope: $scope,
            legacyStatus: $legacyStatus,
            legacyBucket: $legacyBucket,
            type: $get('type'),
            severity: $get('severity'),
            exactDomain: $exactDomain === '1',
            q: $get('q'),
            event: $get('event'),
            from: $query['from'] ?? '',
            to: $query['to'] ?? '',
            tld: strtolower($get('tld')),
            sent: $get('sent'),
            legacyReview: $get('legacy_review'),
            pursuit: $get('pursuit'),
            closureReason: $get('closure_reason'),
            contactWork: $get('contact_work'),
            reminder: $get('reminder'),
        );

        return [$filter, $page, $pageSize];
    }
}
