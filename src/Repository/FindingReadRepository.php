<?php

namespace App\Repository;

use App\Dto\FindingReadFilter;
use App\Dto\FindingReadView;
use App\Value\HostnameTld;
use App\Service\ReviewNoticeService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** A scalar projection for UI and CLI, separate from technical work selection. */
final class FindingReadRepository
{
    private const BASE_FROM = ' FROM finding f INNER JOIN domain d ON d.id = f.domain_id';
    private const FROM = self::BASE_FROM
        .' LEFT JOIN retest_run r ON r.rowid = ('
        .'SELECT latest.rowid FROM retest_run latest WHERE latest.finding_id = f.id '
        .'ORDER BY COALESCE(latest.finished_at, latest.started_at) DESC, latest.rowid DESC LIMIT 1)';

    public const DISCARDED = "(f.manual_assessment = 'discarded' OR f.status IN ('duplicate', 'discarded'))";
    public const ACTIVE = "((f.manual_assessment IS NULL OR f.manual_assessment <> 'discarded') AND f.status NOT IN ('duplicate', 'discarded'))";
    public const DUPLICATES = "(f.status = 'duplicate' OR (f.manual_assessment = 'discarded' AND f.discard_reason = 'duplicate'))";

    public function __construct(private readonly Connection $connection, private readonly ?ReviewNoticeService $reviewNotices = null)
    {
    }

    /** @return list<FindingReadView> */
    public function findPage(FindingReadFilter $filter, int $limit = 50, int $offset = 0): array
    {
        if ($limit < 1 || $offset < 0) {
            throw new \InvalidArgumentException('Page limit must be positive and offset must not be negative.');
        }
        [$where, $parameters] = $this->where($filter);
        $sql = 'SELECT f.id, d.hostname AS domain, f.title, f.url, f.type, f.severity, '
            .'f.status AS legacy_status, f.review_state AS legacy_review_state, '
            .'f.manual_assessment AS assessment, f.discard_reason, f.assessed_at, '
            .'f.submitted_at, f.created_at, f.contacted_at, f.notified_owner_at AS sent_at, '
            .'r.id AS observation_id, r.result AS observation_result, r.mode AS observation_mode, '
            .'COALESCE(r.finished_at, r.started_at) AS observation_at, '
            .'CASE WHEN '.self::DISCARDED.' THEN 1 ELSE 0 END AS discarded'
            .self::FROM.$where
            .' ORDER BY f.submitted_at DESC, f.created_at DESC, f.rowid DESC LIMIT :limit OFFSET :offset';
        $rows = $this->connection->fetchAllAssociative(
            $sql,
            $parameters + ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        $notices = $this->reviewNotices?->forFindings(array_column($rows, 'id')) ?? [];
        return array_map(fn (array $row): FindingReadView => $this->view($row, $notices[$row['id']] ?? null), $rows);
    }

    public function count(FindingReadFilter $filter): int
    {
        [$where, $parameters] = $this->where($filter);

        // A count without an observation predicate needs no per-case run sort.
        // The shared WHERE still has exactly the same filter semantics.
        $from = $filter->observation !== '' ? self::FROM : self::BASE_FROM;

        return (int) $this->connection->fetchOne('SELECT COUNT(*)'.$from.$where, $parameters);
    }

    /** @return array{string, array<string, string>} */
    private function where(FindingReadFilter $filter): array
    {
        $conditions = match ($filter->scope) {
            'active' => [self::ACTIVE],
            'discarded' => [self::DISCARDED],
            'duplicates' => [self::DUPLICATES],
            'all' => [],
        };
        $parameters = [];
        $domain = strtolower(trim($filter->domain));
        if ($domain !== '') {
            $conditions[] = $filter->exactDomain ? 'LOWER(d.hostname) = :domain' : 'LOWER(d.hostname) LIKE :domain';
            $parameters['domain'] = $filter->exactDomain ? $domain : '%'.$domain.'%';
        }
        $search = strtolower(trim($filter->q));
        if ($search !== '') {
            // Search terms are literal substrings, including URL punctuation.
            // The shared WHERE keeps page results and counts in agreement.
            $conditions[] = "(LOWER(d.hostname) LIKE :search ESCAPE '!' OR LOWER(f.title) LIKE :search ESCAPE '!' OR LOWER(f.url) LIKE :search ESCAPE '!')";
            $parameters['search'] = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        }
        if ($filter->assessment === 'unknown') {
            $conditions[] = 'f.manual_assessment IS NULL';
        } elseif ($filter->assessment !== '') {
            $conditions[] = 'f.manual_assessment = :assessment';
            $parameters['assessment'] = $filter->assessment;
        }
        if ($filter->observation === 'none') {
            $conditions[] = 'r.id IS NULL';
        } elseif ($filter->observation !== '') {
            $conditions[] = 'r.result = :observation';
            $parameters['observation'] = $filter->observation;
        }
        if ($filter->contact !== '') {
            $conditions[] = $filter->contact === 'yes' ? 'f.contacted_at IS NOT NULL' : 'f.contacted_at IS NULL';
        }
        if ($filter->sent !== '') {
            $conditions[] = $filter->sent === 'yes' ? 'f.notified_owner_at IS NOT NULL' : 'f.notified_owner_at IS NULL';
        }
        if ($filter->type !== '') {
            $conditions[] = 'f.type = :type';
            $parameters['type'] = $filter->type;
        }
        if ($filter->severity !== '') {
            $conditions[] = 'f.severity = :severity';
            $parameters['severity'] = $filter->severity;
        }
        if ($filter->event !== '') {
            $eventDate = match ($filter->event) {
                'reported' => 'COALESCE(f.submitted_at, f.created_at)',
                'sent' => 'f.notified_owner_at',
                'contacted' => 'f.contacted_at',
                'confirmed', 'fixed' => '(SELECT MIN(a.assessed_at) FROM finding_assessment a WHERE a.finding_id = f.id AND a.assessment = :event_assessment)',
            };
            if (in_array($filter->event, ['confirmed', 'fixed'], true)) {
                $parameters['event_assessment'] = $filter->event;
            }
            $conditions[] = $eventDate.' IS NOT NULL';
            $storageZone = new \DateTimeZone(date_default_timezone_get());
            if ($filter->from !== '') {
                $conditions[] = $eventDate.' >= :event_from';
                $parameters['event_from'] = FindingReadFilter::date($filter->from)->setTimezone($storageZone)->format('Y-m-d H:i:s');
            }
            if ($filter->to !== '') {
                $conditions[] = $eventDate.' < :event_until';
                $parameters['event_until'] = FindingReadFilter::date($filter->to)->modify('+1 day')->setTimezone($storageZone)->format('Y-m-d H:i:s');
            }
        }
        if ($filter->tld !== '') {
            [$tldCondition, $tldParameters] = HostnameTld::sqlCondition($filter->tld);
            $conditions[] = $tldCondition;
            $parameters += $tldParameters;
        }

        // Legacy compatibility is diagnostic. It never changes another filter
        // dimension or invents a manual decision from an old status or bucket.
        if ($filter->legacyStatus === 'discarded') {
            $conditions[] = self::DISCARDED;
        } elseif ($filter->legacyStatus === 'duplicate') {
            $conditions[] = self::DUPLICATES;
        } elseif ($filter->legacyStatus !== '') {
            $conditions[] = 'f.status = :legacy_status';
            $parameters['legacy_status'] = $filter->legacyStatus;
        }
        if ($filter->legacyBucket !== '') {
            $conditions[] = match ($filter->legacyBucket) {
                'open' => "(f.status IN ('new', 'verified', 'reported') AND (f.review_state IS NULL OR f.review_state = 'manually_checked') AND f.last_retested_at IS NOT NULL)",
                'fixed' => "f.status = 'fixed'",
                'manual_review' => "f.review_state = 'manual_checking'",
                'unchecked' => 'f.last_retested_at IS NULL',
            };
        }
        if ($filter->legacyReview !== '') {
            $conditions[] = 'f.review_state = :legacy_review';
            $parameters['legacy_review'] = $filter->legacyReview;
        }

        return [$conditions !== [] ? ' WHERE '.implode(' AND ', $conditions) : '', $parameters];
    }

    private function view(array $row, ?\App\Dto\ReviewNoticeView $notice = null): FindingReadView
    {
        return new FindingReadView(
            id: $row['id'],
            domain: $row['domain'],
            title: $row['title'],
            type: $row['type'],
            severity: $row['severity'],
            legacyStatus: $row['legacy_status'],
            legacyReviewState: $row['legacy_review_state'],
            assessment: $row['assessment'],
            discardReason: $row['discard_reason'],
            assessedAt: $this->date($row['assessed_at']),
            submittedAt: $this->date($row['submitted_at']),
            createdAt: new \DateTimeImmutable($row['created_at']),
            contactedAt: $this->date($row['contacted_at']),
            observationId: $row['observation_id'],
            observationResult: $row['observation_result'],
            observationMode: $row['observation_mode'],
            observationAt: $this->date($row['observation_at']),
            discarded: (bool) $row['discarded'],
            url: $row['url'],
            sentAt: $this->date($row['sent_at']),
            reviewNotice: ($notice?->observations ?? []) !== [],
            noticeReasons: array_values(array_unique(array_column($notice?->observations ?? [], 'reason'))),
        );
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        return $value !== null ? new \DateTimeImmutable($value) : null;
    }
}
