<?php

namespace App\Service;

use App\Dto\FindingReadFilter;
use App\Dto\FindingReadView;
use App\Dto\StudioExportView;
use App\Repository\FindingReadRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Stored case metadata only: no browser, artifact reads, or history export. */
final class StudioExportService
{
    public const SCHEMA_VERSION = 1;
    private const PAGE_SIZE = 100;
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private readonly FindingReadRepository $findings;

    public function __construct(
        private readonly Connection $connection,
        private readonly FindingListService $list,
    ) {
        // Reuse the inventory's scalar selection, without its optional review
        // notice projection: exporting a current state needs no run histories.
        $this->findings = new FindingReadRepository($connection);
    }

    /** @param array<string, mixed> $query */
    public function get(array $query): StudioExportView
    {
        [$filter, $includeNotes] = $this->parse($query);
        $selection = $this->connection->transactional(fn (): array => $this->summarize($filter));
        $filterQuery = $this->list->filterQuery($filter);
        $downloadQuery = $filterQuery + ($includeNotes ? ['include_notes' => '1'] : []);

        return new StudioExportView(
            filter: $filter,
            filterQuery: $filterQuery,
            findingCount: $selection['findingCount'],
            domainCount: count($selection['domains']),
            includePrivateNotes: $includeNotes,
            downloadPath: '/export/download?'.http_build_query($downloadQuery, '', '&', PHP_QUERY_RFC3986),
            inventoryPath: '/findings?'.http_build_query($filterQuery, '', '&', PHP_QUERY_RFC3986),
        );
    }

    /**
     * Validate before the streaming response sends headers. Paging is parsed
     * just as in inventory but never limits the complete selected export.
     *
     * @param array<string, mixed> $query
     * @return array{FindingReadFilter, bool}
     */
    public function parse(array $query): array
    {
        [$filter] = $this->list->parse($query);
        $notes = $query['include_notes'] ?? '0';
        if (!is_string($notes) || !in_array($notes, ['0', '1'], true)) {
            throw new \InvalidArgumentException('Private Fallnotizen müssen ausdrücklich mit 0 oder 1 gewählt werden.');
        }

        return [$filter, $notes === '1'];
    }

    /**
     * The read transaction binds counts and all pages to one SQLite snapshot.
     * DBAL closes it on success or rolls it back if reading/writing fails.
     * Neither findings nor evidence metadata are collected as one large array.
     *
     * @param callable(string): void $write
     */
    public function writeDownload(FindingReadFilter $filter, bool $includeNotes, callable $write): void
    {
        $this->connection->transactional(function () use ($filter, $includeNotes, $write): void {
            $selection = $this->summarize($filter);
            $header = [
                'schemaVersion' => self::SCHEMA_VERSION,
                'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'filters' => $this->list->filterQuery($filter),
                'includePrivateNotes' => $includeNotes,
                'findingCount' => $selection['findingCount'],
                'domainCount' => count($selection['domains']),
                'domains' => $selection['domains'],
            ];
            $write(substr($this->json($header), 0, -1).',"findings":[');
            $firstFinding = true;
            foreach ($this->pages($filter) as $page) {
                [$details, $observations] = $this->details($page, $includeNotes);
                foreach ($page as $finding) {
                    $record = $this->record($finding, $details[$finding->id], $observations, $includeNotes);
                    $write(($firstFinding ? '' : ',').substr($this->json($record), 0, -1).',"evidence":[');
                    $firstFinding = false;
                    $firstEvidence = true;
                    foreach ($this->evidence($finding->id) as $item) {
                        $write(($firstEvidence ? '' : ',').$this->json($item));
                        $firstEvidence = false;
                    }
                    $write(']}');
                }
            }
            $write(']}');
        });
    }

    /** @return array{findingCount: int, domains: list<array<string, mixed>>} */
    private function summarize(FindingReadFilter $filter): array
    {
        $count = 0;
        $domains = [];
        foreach ($this->pages($filter) as $page) {
            $count += count($page);
            $rows = $this->connection->fetchAllAssociative(
                'SELECT DISTINCT d.id, d.hostname, d.scheme, d.authorized FROM domain d '
                .'INNER JOIN finding f ON f.domain_id = d.id WHERE f.id IN (:ids)',
                ['ids' => array_map(static fn (FindingReadView $finding): string => $finding->id, $page)],
                ['ids' => ArrayParameterType::STRING],
            );
            foreach ($rows as $row) {
                $domains[$row['id']] = [
                    'id' => $row['id'], 'hostname' => $row['hostname'],
                    'scheme' => $row['scheme'], 'authorized' => (bool) $row['authorized'],
                ];
            }
        }
        $domains = array_values($domains);
        usort($domains, static fn (array $a, array $b): int => strcmp($a['hostname'], $b['hostname']));

        return ['findingCount' => $count, 'domains' => $domains];
    }

    /** @return \Generator<int, list<FindingReadView>> */
    private function pages(FindingReadFilter $filter): \Generator
    {
        for ($offset = 0; ; $offset += self::PAGE_SIZE) {
            $page = $this->findings->findPage($filter, self::PAGE_SIZE, $offset);
            if ($page === []) {
                return;
            }
            yield $page;
            if (count($page) < self::PAGE_SIZE) {
                return;
            }
        }
    }

    /**
     * @param list<FindingReadView> $page
     * @return array{array<string, array<string, mixed>>, array<string, array<string, mixed>>}
     */
    private function details(array $page, bool $includeNotes): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, domain_id, method, request_params, payload, expected_evidence, report_url, '
            .'reported_at, last_retested_at, updated_at'.($includeNotes ? ', private_notes' : '')
            .' FROM finding WHERE id IN (:ids)',
            ['ids' => array_map(static fn (FindingReadView $finding): string => $finding->id, $page)],
            ['ids' => ArrayParameterType::STRING],
        );
        $details = array_column($rows, null, 'id');
        $observationIds = array_values(array_filter(array_map(
            static fn (FindingReadView $finding): ?string => $finding->observationId,
            $page,
        )));
        $observations = $observationIds === [] ? [] : $this->connection->fetchAllAssociative(
            'SELECT id, http_status, final_url, observed_evidence, error_message, started_at, finished_at '
            .'FROM retest_run WHERE id IN (:ids)',
            ['ids' => $observationIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return [$details, array_column($observations, null, 'id')];
    }

    /** @param array<string, mixed> $detail @param array<string, array<string, mixed>> $observations */
    private function record(FindingReadView $finding, array $detail, array $observations, bool $includeNotes): array
    {
        $observation = $finding->observationId === null ? null : $observations[$finding->observationId];
        $record = [
            'id' => $finding->id,
            'domainId' => $detail['domain_id'],
            'title' => $finding->title,
            'type' => $finding->type,
            'severity' => $finding->severity,
            'url' => $finding->url,
            'method' => $detail['method'],
            'requestParams' => $detail['request_params'] === null ? null : json_decode($detail['request_params'], true, 512, JSON_THROW_ON_ERROR),
            'payload' => $detail['payload'],
            'expectedEvidence' => $detail['expected_evidence'],
            'reportUrl' => $detail['report_url'],
            'reportedAt' => $this->date($detail['reported_at']),
            'submittedAt' => $finding->submittedAt?->format(DATE_ATOM),
            'createdAt' => $finding->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->date($detail['updated_at']),
            'lastRetestedAt' => $this->date($detail['last_retested_at']),
            'manualAssessment' => [
                'value' => $finding->assessment,
                'discardReason' => $finding->discardReason,
                'assessedAt' => $finding->assessedAt?->format(DATE_ATOM),
            ],
            'latestObservation' => $observation === null ? null : [
                'id' => $finding->observationId,
                'result' => $finding->observationResult,
                'mode' => $finding->observationMode,
                'observedAt' => $finding->observationAt?->format(DATE_ATOM),
                'startedAt' => $this->date($observation['started_at']),
                'finishedAt' => $this->date($observation['finished_at']),
                'httpStatus' => $observation['http_status'] === null ? null : (int) $observation['http_status'],
                'finalUrl' => $observation['final_url'],
                'observedEvidence' => $observation['observed_evidence'],
                'errorMessage' => $observation['error_message'],
            ],
            'contactedAt' => $finding->contactedAt?->format(DATE_ATOM),
            'sentAt' => $finding->sentAt?->format(DATE_ATOM),
            'discarded' => $finding->discarded,
            'legacy' => ['status' => $finding->legacyStatus, 'reviewState' => $finding->legacyReviewState],
        ];
        if ($includeNotes) {
            $record['privateNotes'] = $detail['private_notes'];
        }

        return $record;
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function evidence(string $findingId): \Generator
    {
        $after = null;
        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT rowid AS cursor, id, kind, sha256, created_at, updated_at, file_path '
                .'FROM evidence WHERE finding_id = :finding'.($after === null ? '' : ' AND rowid > :after')
                .' ORDER BY rowid LIMIT :limit',
                ['finding' => $findingId, 'limit' => self::PAGE_SIZE] + ($after === null ? [] : ['after' => $after]),
                ['limit' => ParameterType::INTEGER] + ($after === null ? [] : ['after' => ParameterType::INTEGER]),
            );
            foreach ($rows as $row) {
                $after = (int) $row['cursor'];
                yield [
                    'id' => $row['id'],
                    'kind' => $row['kind'],
                    'sha256' => $row['sha256'],
                    'createdAt' => $this->date($row['created_at']),
                    'updatedAt' => $this->date($row['updated_at']),
                    'artifactUrl' => $this->artifactUrl($row['file_path']),
                ];
            }
        } while (count($rows) === self::PAGE_SIZE);
    }

    private function artifactUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, 'storage/artifacts/')) {
            $path = substr($path, strlen('storage/artifacts/'));
        }
        if ($path === '' || str_starts_with($path, '/') || preg_match('~(^|/)\.\.(?:/|$)|[\x00-\x1f\x7f]|^[a-zA-Z]:~', $path)) {
            return null;
        }

        return '/artifacts/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function date(?string $value): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable($value))->format(DATE_ATOM);
    }

    private function json(array $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }
}
