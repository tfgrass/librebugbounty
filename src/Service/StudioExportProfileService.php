<?php

namespace App\Service;

use App\Dto\FindingReadFilter;
use App\Dto\FindingReadView;
use App\Dto\StudioExportOptions;
use App\Dto\StudioExportView;
use App\Repository\FindingReadRepository;
use App\Value\FindingReadLabels;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Purpose-based exports layered on the stable current-state JSON v1 export. */
final class StudioExportProfileService
{
    public const CUSTOM_STATE_SCHEMA_VERSION = 2;
    private const PAGE_SIZE = 100;
    private const MAX_REPORT_IMAGE_BYTES = 25 * 1024 * 1024;
    private const MAX_REPORT_TOTAL_IMAGE_BYTES = 512 * 1024 * 1024;
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    private const IMAGE_EXTENSIONS = [
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    private readonly FindingReadRepository $findings;

    public function __construct(
        private readonly Connection $connection,
        private readonly FindingListService $list,
        private readonly EvidenceStorageInterface $storage,
        private readonly StudioExportService $stateExport,
        private readonly ReviewSchema $reviewSchema,
        private readonly UiTranslator $i18n,
        private readonly SettingsService $settings,
    ) {
        $this->findings = new FindingReadRepository($connection);
    }

    /** @param array<string, mixed> $query */
    public function get(array $query): StudioExportView
    {
        // Preferences initialize the editor only. The download parser keeps its
        // established defaults, and the editor's download path pins its choices.
        if (!array_key_exists('profile', $query)) {
            $query['profile'] = $this->settings->getExportProfile();
        }
        if (is_string($query['profile']) && trim($query['profile']) === 'report' && !array_key_exists('screenshots', $query)) {
            $query['screenshots'] = $this->settings->getExportScreenshotMode();
        }
        $options = $this->parse($query);
        [$selection, $images] = $this->connection->transactional(function () use ($options): array {
            return [
                $this->summarize($options->filter),
                $options->profile === 'report'
                    ? $this->imageSummary($options)
                    : ['available' => 0, 'missing' => 0, 'unknownBasis' => 0],
            ];
        });
        $filterQuery = $this->list->filterQuery($options->filter);
        $downloadQuery = $filterQuery + $options->query();

        return new StudioExportView(
            filter: $options->filter,
            filterQuery: $filterQuery,
            findingCount: $selection['findingCount'],
            domainCount: count($selection['domains']),
            includePrivateNotes: $options->includePrivateNotes,
            downloadPath: '/export/download?'.http_build_query($downloadQuery, '', '&', PHP_QUERY_RFC3986),
            inventoryPath: '/findings?'.http_build_query($filterQuery, '', '&', PHP_QUERY_RFC3986),
            profile: $options->profile,
            includeRequestData: $options->includeRequestData,
            includeAssessment: $options->includeAssessment,
            includeContact: $options->includeContact,
            screenshotMode: $options->screenshotMode,
            screenshotCount: $images['available'],
            missingScreenshotCount: $images['missing'],
            unknownBasisCount: $images['unknownBasis'],
            downloadFormat: $options->profile === 'report' ? 'zip' : 'json',
        );
    }

    /** @param array<string, mixed> $query */
    public function parse(array $query): StudioExportOptions
    {
        [$filter] = $this->list->parse($query);
        foreach (['profile', 'include_request_data', 'include_assessment', 'include_contact', 'include_notes', 'screenshots'] as $field) {
            if (array_key_exists($field, $query) && !is_string($query[$field])) {
                throw new \InvalidArgumentException('Exportangaben müssen einzelne Textwerte sein.');
            }
        }
        $profile = trim($query['profile'] ?? 'state');
        if (!in_array($profile, StudioExportOptions::PROFILES, true)) {
            throw new \InvalidArgumentException('Unbekannte Exportvorlage.');
        }
        $defaults = match ($profile) {
            'urls' => [false, false, false, 'none'],
            'report' => [true, true, false, 'basis'],
            default => [true, true, true, 'none'],
        };

        return new StudioExportOptions(
            filter: $filter,
            profile: $profile,
            includeRequestData: $this->boolOption($query, 'include_request_data', $defaults[0]),
            includeAssessment: $this->boolOption($query, 'include_assessment', $defaults[1]),
            includeContact: $this->boolOption($query, 'include_contact', $defaults[2]),
            includePrivateNotes: $this->boolOption($query, 'include_notes', false),
            screenshotMode: trim($query['screenshots'] ?? $defaults[3]),
        );
    }

    /** @param callable(string): void $write */
    public function writeJson(StudioExportOptions $options, callable $write): void
    {
        if ($options->profile === 'report') {
            throw new \InvalidArgumentException('Meldungspakete werden als ZIP erstellt.');
        }
        if ($options->profile === 'state' && $options->includeRequestData && $options->includeAssessment && $options->includeContact) {
            // Preserve the established JSON-v1 field contract and streaming behavior.
            $this->stateExport->writeDownload($options->filter, $options->includePrivateNotes, $write);

            return;
        }
        $this->connection->transactional(function () use ($options, $write): void {
            if ($options->profile === 'urls') {
                $write('[');
                $first = true;
                foreach ($this->pages($options->filter) as $page) {
                    foreach ($page as $finding) {
                        $write(($first ? '' : ',').$this->json(['url' => $finding->url, 'type' => $finding->type]));
                        $first = false;
                    }
                }
                $write(']');

                return;
            }
            $selection = $this->summarize($options->filter);
            $header = [
                'schemaVersion' => self::CUSTOM_STATE_SCHEMA_VERSION,
                'exportProfile' => 'state',
                'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'filters' => $this->list->filterQuery($options->filter),
                'includePrivateNotes' => $options->includePrivateNotes,
                'options' => [
                    'includeRequestData' => $options->includeRequestData,
                    'includeAssessment' => $options->includeAssessment,
                    'includeContact' => $options->includeContact,
                    'includePrivateNotes' => $options->includePrivateNotes,
                ],
                'findingCount' => $selection['findingCount'],
                'domainCount' => count($selection['domains']),
                'domains' => $selection['domains'],
            ];
            $write(substr($this->json($header), 0, -1).',"findings":[');
            $first = true;
            foreach ($this->pages($options->filter) as $page) {
                [$details, $observations] = $this->details($page, $options->includePrivateNotes);
                foreach ($page as $finding) {
                    $record = $this->record($finding, $details[$finding->id], $observations, $options);
                    $write(($first ? '' : ',').substr($this->json($record), 0, -1).',"evidence":[');
                    $first = false;
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

    /** Returns a private temporary ZIP path. Its response must delete it after sending. */
    public function buildReportArchive(StudioExportOptions $options): string
    {
        if ($options->profile !== 'report') {
            throw new \InvalidArgumentException('Nur Meldungspakete können als ZIP erstellt werden.');
        }
        $dataset = $this->connection->transactional(fn (): array => $this->reportDataset($options));
        $path = tempnam(sys_get_temp_dir(), 'librebugbounty-export-');
        if ($path === false) {
            throw new \RuntimeException('Temporäre Exportdatei konnte nicht angelegt werden.');
        }
        @chmod($path, 0600);
        $zip = null;
        $opened = false;
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Meldungspaket konnte nicht angelegt werden.');
            }
            $opened = true;
            $inspectedImageBytes = 0;
            foreach ($dataset['findings'] as &$finding) {
                foreach ($finding['screenshots'] as &$image) {
                    $storedPath = $image['storedPath'];
                    $selectionIssue = $image['selectionIssue'] ?? null;
                    unset($image['storedPath']);
                    unset($image['selectionIssue']);
                    $inspection = $this->inspectImage(
                        $finding['sourceFindingId'],
                        $storedPath,
                        $image['sha256'],
                        $selectionIssue,
                        self::MAX_REPORT_TOTAL_IMAGE_BYTES - $inspectedImageBytes,
                    );
                    $inspectedImageBytes += $inspection['bytesInspected'];
                    $image['actualSha256'] = $inspection['actualSha256'];
                    $image['integrityMatchesStoredHash'] = $inspection['integrityMatchesStoredHash'];
                    $image['byteSize'] = $inspection['byteSize'];
                    $image['mimeType'] = $inspection['mimeType'];
                    if ($inspection['reason'] !== null) {
                        if ($inspection['reason'] === 'invalid_stored_path') {
                            $image['sha256'] = null;
                        }
                        $image['available'] = false;
                        $image['missingReason'] = $inspection['reason'];
                        continue;
                    }
                    $archivePath = $this->archiveImagePath($finding['packageId'], $image['id'], (string) $inspection['extension']);
                    if (!$zip->addFromString($archivePath, $inspection['contents'])) {
                        throw new \RuntimeException('Bildbeleg konnte dem Meldungspaket nicht hinzugefügt werden.');
                    }
                    $image['available'] = true;
                    $image['archivePath'] = $archivePath;
                }
                unset($image);
                unset($finding['sourceFindingId']);
            }
            unset($finding);
            $manifest = [
                'packageVersion' => 1,
                'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'options' => $options->query(),
                'findingCount' => count($dataset['findings']),
                'domainCount' => count($dataset['domains']),
                'domains' => $dataset['domains'],
                'findings' => $dataset['findings'],
            ];
            if (!$zip->addFromString('manifest.json', json_encode($manifest, self::JSON_FLAGS | JSON_PRETTY_PRINT)."\n")
                || !$zip->addFromString('report.md', $this->reportMarkdown($manifest))) {
                throw new \RuntimeException('Bericht konnte dem Meldungspaket nicht hinzugefügt werden.');
            }
            if (!$zip->close()) {
                throw new \RuntimeException('Meldungspaket konnte nicht abgeschlossen werden.');
            }
            $opened = false;

            return $path;
        } catch (\Throwable $exception) {
            if ($opened && $zip instanceof \ZipArchive) {
                try {
                    $zip->close();
                } finally {
                    @unlink($path);
                }
            } else {
                @unlink($path);
            }
            throw $exception;
        }
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

    /** @param list<FindingReadView> $page @return array{array<string, array<string, mixed>>, array<string, array<string, mixed>>} */
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
    private function record(FindingReadView $finding, array $detail, array $observations, StudioExportOptions $options): array
    {
        $record = [
            'id' => $finding->id, 'domainId' => $detail['domain_id'], 'title' => $finding->title,
            'type' => $finding->type, 'severity' => $finding->severity, 'url' => $finding->url,
        ];
        if ($options->includeRequestData) {
            $record += [
                'method' => $detail['method'],
                'requestParams' => $detail['request_params'] === null ? null : json_decode($detail['request_params'], true, 512, JSON_THROW_ON_ERROR),
                'payload' => $detail['payload'], 'expectedEvidence' => $detail['expected_evidence'],
            ];
        }
        if ($options->includeContact) {
            $record += ['reportUrl' => $detail['report_url'], 'reportedAt' => $this->date($detail['reported_at'])];
        }
        $record += [
            'submittedAt' => $finding->submittedAt?->format(DATE_ATOM),
            'createdAt' => $finding->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->date($detail['updated_at']),
        ];
        if ($options->includeAssessment) {
            $observation = $finding->observationId === null ? null : ($observations[$finding->observationId] ?? null);
            $record += [
                'lastRetestedAt' => $this->date($detail['last_retested_at']),
                'manualAssessment' => [
                    'value' => $finding->assessment, 'discardReason' => $finding->discardReason,
                    'assessedAt' => $finding->assessedAt?->format(DATE_ATOM),
                ],
                'latestObservation' => $observation === null ? null : [
                    'id' => $finding->observationId, 'result' => $finding->observationResult,
                    'mode' => $finding->observationMode, 'observedAt' => $finding->observationAt?->format(DATE_ATOM),
                    'startedAt' => $this->date($observation['started_at']), 'finishedAt' => $this->date($observation['finished_at']),
                    'httpStatus' => $observation['http_status'] === null ? null : (int) $observation['http_status'],
                    'finalUrl' => $observation['final_url'], 'observedEvidence' => $observation['observed_evidence'],
                    'errorMessage' => $observation['error_message'],
                ],
            ];
        }
        if ($options->includeContact) {
            $record += ['contactedAt' => $finding->contactedAt?->format(DATE_ATOM), 'sentAt' => $finding->sentAt?->format(DATE_ATOM)];
        }
        $record['discarded'] = $finding->discarded;
        if ($options->profile === 'state' && $options->includeAssessment) {
            $record['legacy'] = ['status' => $finding->legacyStatus, 'reviewState' => $finding->legacyReviewState];
        }
        if ($options->includePrivateNotes) {
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
                'SELECT rowid AS cursor, id, kind, sha256, created_at, updated_at, file_path FROM evidence '
                .'WHERE finding_id = :finding'.($after === null ? '' : ' AND rowid > :after').' ORDER BY rowid LIMIT :limit',
                ['finding' => $findingId, 'limit' => self::PAGE_SIZE] + ($after === null ? [] : ['after' => $after]),
                ['limit' => ParameterType::INTEGER] + ($after === null ? [] : ['after' => ParameterType::INTEGER]),
            );
            foreach ($rows as $row) {
                $after = (int) $row['cursor'];
                yield [
                    'id' => $row['id'], 'kind' => $row['kind'], 'sha256' => $row['sha256'],
                    'createdAt' => $this->date($row['created_at']), 'updatedAt' => $this->date($row['updated_at']),
                    'artifactUrl' => $this->artifactUrl($row['file_path'], $findingId),
                ];
            }
        } while (count($rows) === self::PAGE_SIZE);
    }

    /** @return array{available: int, missing: int, unknownBasis: int} */
    private function imageSummary(StudioExportOptions $options): array
    {
        $summary = ['available' => 0, 'missing' => 0, 'unknownBasis' => 0];
        $plannedImageBytes = 0;
        foreach ($this->pages($options->filter) as $page) {
            $selection = $this->screenshotSelection(array_map(static fn (FindingReadView $item): string => $item->id, $page), $options->screenshotMode);
            $summary['unknownBasis'] += $selection['unknownBasis'];
            foreach ($selection['byFinding'] as $findingId => $images) {
                foreach ($images as $image) {
                    $inspection = $this->preflightImage(
                        $findingId,
                        $image['file_path'],
                        $image['selection_issue'] ?? null,
                        self::MAX_REPORT_TOTAL_IMAGE_BYTES - $plannedImageBytes,
                    );
                    if ($inspection['reason'] === null) {
                        $plannedImageBytes += $inspection['byteSize'];
                        ++$summary['available'];
                    } else {
                        ++$summary['missing'];
                    }
                }
            }
        }

        return $summary;
    }

    /** @return array{domains: list<array<string, mixed>>, findings: list<array<string, mixed>>} */
    private function reportDataset(StudioExportOptions $options): array
    {
        $summary = $this->summarize($options->filter);
        $findings = [];
        $showAssessmentProvenance = $options->includeAssessment || $options->screenshotMode === 'basis';
        $packageIndex = 0;
        foreach ($this->pages($options->filter) as $page) {
            [$details, $observations] = $this->details($page, $options->includePrivateNotes);
            $selection = $this->screenshotSelection(array_map(static fn (FindingReadView $item): string => $item->id, $page), $options->screenshotMode);
            foreach ($page as $finding) {
                $record = $this->record($finding, $details[$finding->id], $observations, $options);
                $record['packageId'] = 'case-'.str_pad((string) ++$packageIndex, 4, '0', STR_PAD_LEFT);
                $record['sourceFindingId'] = $record['id'];
                if (isset($record['latestObservation']) && is_array($record['latestObservation'])) {
                    unset($record['latestObservation']['id']);
                }
                unset(
                    $record['id'],
                    $record['domainId'],
                    $record['submittedAt'],
                    $record['createdAt'],
                    $record['updatedAt'],
                    $record['discarded'],
                );
                $record['screenshots'] = [];
                foreach ($selection['byFinding'][$finding->id] ?? [] as $imageIndex => $image) {
                    $item = [
                        'id' => 'image-'.str_pad((string) ($imageIndex + 1), 3, '0', STR_PAD_LEFT),
                        'selectionBasis' => $image['selection_basis'],
                        'sha256' => $image['sha256'], 'storedAt' => $this->date($image['created_at']),
                        'capturedAt' => $this->date($image['captured_at']), 'captureSource' => $image['capture_source'],
                        'storedPath' => $image['file_path'], 'selectionIssue' => $image['selection_issue'] ?? null,
                    ];
                    if ($showAssessmentProvenance) {
                        $item['basisSource'] = $image['basis_source'];
                    }
                    $record['screenshots'][] = $item;
                }
                if ($showAssessmentProvenance) {
                    $record['hasKnownAssessmentImageBasis'] = $selection['basisKnown'][$finding->id] ?? false;
                }
                $findings[] = $record;
            }
        }

        return [
            'domains' => array_map(static fn (array $domain): array => [
                'hostname' => $domain['hostname'], 'scheme' => $domain['scheme'],
            ], $summary['domains']),
            'findings' => $findings,
        ];
    }

    /** @param list<string> $findingIds @return array{byFinding: array<string, list<array<string, mixed>>>, basisKnown: array<string, bool>, unknownBasis: int} */
    private function screenshotSelection(array $findingIds, string $mode): array
    {
        $basis = $this->basisByFinding($findingIds);
        $basisIds = array_values(array_unique(array_filter(array_column($basis, 'evidenceId'))));
        $basisRows = $basisIds === [] ? [] : $this->connection->fetchAllAssociative(
            'SELECT id, finding_id, kind, sha256, created_at, updated_at, file_path FROM evidence WHERE id IN (:ids)',
            ['ids' => $basisIds], ['ids' => ArrayParameterType::STRING],
        );
        $basisRowsById = array_column($basisRows, null, 'id');
        $basisKnown = [];
        foreach ($findingIds as $id) {
            $evidenceId = $basis[$id]['evidenceId'] ?? null;
            $row = $evidenceId === null ? null : ($basisRowsById[$evidenceId] ?? null);
            $basisKnown[$id] = $row !== null && $row['finding_id'] === $id && $row['kind'] === 'screenshot';
        }
        $unknown = count(array_filter($basisKnown, static fn (bool $known): bool => !$known));
        if ($mode === 'none') {
            return ['byFinding' => [], 'basisKnown' => $basisKnown, 'unknownBasis' => $unknown];
        }
        $capture = $this->captureMetadata($findingIds);
        if ($mode === 'basis') {
            $byFinding = [];
            foreach ($basis as $findingId => $reference) {
                if ($reference['evidenceId'] === null) {
                    continue;
                }
                $row = $basisRowsById[$reference['evidenceId']] ?? null;
                if ($row === null || $row['finding_id'] !== $findingId) {
                    $row = [
                        'id' => $reference['evidenceId'], 'finding_id' => $findingId, 'kind' => 'screenshot',
                        'sha256' => null, 'created_at' => null, 'updated_at' => null, 'file_path' => null,
                        'selection_issue' => 'basis_evidence_missing',
                    ];
                } elseif ($row['kind'] !== 'screenshot') {
                    $row['file_path'] = null;
                    $row['sha256'] = null;
                    $row['created_at'] = null;
                    $row['updated_at'] = null;
                    $row['selection_issue'] = 'basis_not_image';
                }
                $byFinding[$findingId][] = $this->withCapture($row, $capture, 'basis', $reference['source']);
            }

            return ['byFinding' => $byFinding, 'basisKnown' => $basisKnown, 'unknownBasis' => $unknown];
        }
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, finding_id, kind, sha256, created_at, updated_at, file_path FROM evidence WHERE finding_id IN (:ids) AND kind = 'screenshot'",
            ['ids' => $findingIds], ['ids' => ArrayParameterType::STRING],
        );
        $byFinding = [];
        foreach ($rows as $row) {
            $basisSource = ($basis[$row['finding_id']]['evidenceId'] ?? null) === $row['id'] ? $basis[$row['finding_id']]['source'] : null;
            $byFinding[$row['finding_id']][] = $this->withCapture($row, $capture, $mode, $basisSource);
        }
        foreach ($byFinding as &$items) {
            usort($items, static function (array $a, array $b): int {
                return [$b['captured_at'] ?? $b['created_at'] ?? '', $b['id']] <=> [$a['captured_at'] ?? $a['created_at'] ?? '', $a['id']];
            });
            if ($mode === 'latest') {
                $items = array_slice($items, 0, 1);
            }
        }
        unset($items);

        return ['byFinding' => $byFinding, 'basisKnown' => $basisKnown, 'unknownBasis' => $unknown];
    }

    /** @param list<string> $findingIds @return array<string, array{evidenceId: ?string, source: ?string}> */
    private function basisByFinding(array $findingIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT f.id, f.manual_assessment, f.assessed_at, a.id AS decision_id, a.assessment AS decision_assessment, '
            .'a.assessed_at AS decision_at, a.evidence_id AS assessment_evidence_id FROM finding f '
            .'LEFT JOIN finding_assessment a ON a.id = (SELECT latest.id FROM finding_assessment latest WHERE latest.finding_id = f.id AND '.AssessmentHistoryProjection::activeSql($this->connection, 'latest').' '
            .'ORDER BY latest.assessed_at DESC, latest.id DESC LIMIT 1) WHERE f.id IN (:ids)',
            ['ids' => $findingIds], ['ids' => ArrayParameterType::STRING],
        );
        $acks = $this->reviewSchema->available()
            ? $this->connection->fetchAllAssociative(
                'SELECT finding_id, decision_key, evidence_id FROM finding_review_acknowledgement WHERE finding_id IN (:ids) '
                .'ORDER BY reviewed_at DESC, id DESC',
                ['ids' => $findingIds], ['ids' => ArrayParameterType::STRING],
            )
            : [];
        $acksByDecision = [];
        foreach ($acks as $ack) {
            // Keep the latest row, including its explicit absence of a new
            // image basis. An older acknowledgement must not override it.
            $acksByDecision[$ack['finding_id']][$ack['decision_key']] ??= $ack;
        }
        $result = array_fill_keys($findingIds, ['evidenceId' => null, 'source' => null]);
        foreach ($rows as $row) {
            $matches = $row['decision_id'] !== null && $row['manual_assessment'] !== null
                && $row['decision_assessment'] === $row['manual_assessment'] && $row['decision_at'] === $row['assessed_at'];
            if ($row['manual_assessment'] === null) {
                continue;
            }
            $decisionKey = $matches
                ? 'history:'.$row['decision_id']
                : 'legacy:'.hash('sha256', json_encode([$row['manual_assessment'], $row['assessed_at']]));
            $ackEvidence = $acksByDecision[$row['id']][$decisionKey]['evidence_id'] ?? null;
            if ($ackEvidence !== null && $ackEvidence !== '') {
                $result[$row['id']] = ['evidenceId' => $ackEvidence, 'source' => 'acknowledgement'];
            } elseif ($matches && $row['assessment_evidence_id'] !== null && $row['assessment_evidence_id'] !== '') {
                $result[$row['id']] = ['evidenceId' => $row['assessment_evidence_id'], 'source' => 'assessment'];
            }
        }

        return $result;
    }

    /** @param list<string> $findingIds @return array<string, array{capturedAt: ?string, source: ?string}> */
    private function captureMetadata(array $findingIds): array
    {
        $map = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT finding_id, screenshot_path, captured_at FROM screenshot_job WHERE finding_id IN (:ids) AND screenshot_path IS NOT NULL '
            .'ORDER BY captured_at DESC, id DESC', ['ids' => $findingIds], ['ids' => ArrayParameterType::STRING],
        ) as $row) {
            $map[$this->captureKey($row['finding_id'], $row['screenshot_path'])] ??= ['capturedAt' => $row['captured_at'], 'source' => 'screenshot_job'];
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT finding_id, screenshot_path, finished_at, started_at FROM retest_run WHERE finding_id IN (:ids) AND screenshot_path IS NOT NULL '
            .'ORDER BY COALESCE(finished_at, started_at) DESC, id DESC', ['ids' => $findingIds], ['ids' => ArrayParameterType::STRING],
        ) as $row) {
            $map[$this->captureKey($row['finding_id'], $row['screenshot_path'])] ??= ['capturedAt' => $row['finished_at'] ?? $row['started_at'], 'source' => 'retest_run'];
        }

        return $map;
    }

    /** @param array<string, mixed> $row @param array<string, array{capturedAt: ?string, source: ?string}> $capture */
    private function withCapture(array $row, array $capture, string $selectionBasis, ?string $basisSource): array
    {
        $metadata = $row['file_path'] === null ? null : ($capture[$this->captureKey($row['finding_id'], $row['file_path'])] ?? null);
        $row['captured_at'] = $metadata['capturedAt'] ?? null;
        $row['capture_source'] = $metadata['source'] ?? null;
        $row['selection_basis'] = $selectionBasis;
        $row['basis_source'] = $basisSource;

        return $row;
    }

    /** @param array<string, mixed> $query */
    private function boolOption(array $query, string $name, bool $default): bool
    {
        $value = $query[$name] ?? ($default ? '1' : '0');
        if (!is_string($value) || !in_array($value, ['0', '1'], true)) {
            throw new \InvalidArgumentException('Ungültige Exportoption.');
        }

        return $value === '1';
    }

    private function reportMarkdown(array $manifest): string
    {
        $t = fn (string $key): string => $this->i18n->trans($key);
        $lines = ['# '.$t('LibreBugBounty – Meldungspaket'), '', $t('Erstellt').': '.$manifest['generatedAt'], ''];
        foreach ($manifest['findings'] as $index => $finding) {
            $lines[] = '## '.($index + 1).'. '.$this->markdown((string) ($finding['title'] ?: $finding['type']));
            $lines[] = '';
            $lines[] = '- '.$t('Typ').': '.$this->markdown((string) $finding['type']);
            $lines[] = '- '.$t('Schweregrad').': '.$this->markdown((string) $finding['severity']);
            $lines[] = '- URL:';
            $lines[] = $this->indentedCode((string) $finding['url']);
            if (array_key_exists('method', $finding)) {
                $lines[] = '- '.$t('Methode').': '.$this->markdown((string) ($finding['method'] ?? ''));
                $lines[] = '- '.$t('Request-Parameter').':';
                $lines[] = $this->indentedCode(json_encode($finding['requestParams'], self::JSON_FLAGS | JSON_PRETTY_PRINT));
                $lines[] = '- '.$t('Kennzeichen / Payload').':';
                $lines[] = $this->indentedCode((string) ($finding['payload'] ?? ''));
                $lines[] = '- '.$t('Erwarteter Nachweis').':';
                $lines[] = $this->indentedCode((string) ($finding['expectedEvidence'] ?? ''));
            }
            if (isset($finding['manualAssessment'])) {
                $assessment = FindingReadLabels::assessment(
                    $finding['manualAssessment']['value'] ?? null,
                    $finding['manualAssessment']['discardReason'] ?? null,
                );
                $lines[] = '- '.$t('Manuelle Bewertung').': '.$this->markdown($t($assessment));
                if (($finding['manualAssessment']['assessedAt'] ?? null) !== null) {
                    $lines[] = '- '.$t('Bewertet am').': '.$this->markdown((string) $finding['manualAssessment']['assessedAt']);
                }
                if (($finding['manualAssessment']['discardReason'] ?? null) !== null) {
                    $reason = $finding['manualAssessment']['discardReason'] === 'duplicate'
                        ? $t('Duplikat')
                        : (string) $finding['manualAssessment']['discardReason'];
                    $lines[] = '- '.$t('Verwerfungsgrund').': '.$this->markdown($reason);
                }
                if (($finding['latestObservation'] ?? null) !== null) {
                    $observation = $finding['latestObservation'];
                    $observationLabel = FindingReadLabels::observation($observation['result'] ?? null);
                    $lines[] = '- '.$t('Letzte technische Beobachtung').': '.$this->markdown($t($observationLabel));
                    if (($observation['observedAt'] ?? null) !== null) {
                        $lines[] = '- '.$t('Beobachtet am').': '.$this->markdown((string) $observation['observedAt']);
                    }
                    if (($observation['httpStatus'] ?? null) !== null) {
                        $lines[] = '- '.$t('HTTP-Status').': '.$this->markdown((string) $observation['httpStatus']);
                    }
                    if (($observation['finalUrl'] ?? null) !== null) {
                        $lines[] = '- '.$t('Endgültige URL').':';
                        $lines[] = $this->indentedCode((string) $observation['finalUrl']);
                    }
                    if (($observation['observedEvidence'] ?? null) !== null) {
                        $lines[] = '- '.$t('Beobachteter Nachweis').':';
                        $lines[] = $this->indentedCode((string) $observation['observedEvidence']);
                    }
                    if (($observation['errorMessage'] ?? null) !== null) {
                        $lines[] = '- '.$t('Technischer Fehler').':';
                        $lines[] = $this->indentedCode((string) $observation['errorMessage']);
                    }
                }
            }
            if (array_key_exists('reportUrl', $finding)) {
                if ($finding['reportUrl'] !== null) {
                    $lines[] = '- '.$t('Gespeicherte Meldungs-URL').':';
                    $lines[] = $this->indentedCode((string) $finding['reportUrl']);
                }
                foreach (['reportedAt' => 'Als gemeldet erfasst', 'contactedAt' => 'Als kontaktiert erfasst', 'sentAt' => 'Als versendet erfasst'] as $field => $label) {
                    if (($finding[$field] ?? null) !== null) {
                        $lines[] = '- '.$t($label).': '.$this->markdown((string) $finding[$field]);
                    }
                }
            }
            if (array_key_exists('privateNotes', $finding)) {
                $lines[] = '- '.$t('Private Fallnotizen').':';
                $lines[] = $this->indentedCode((string) ($finding['privateNotes'] ?? ''));
            }
            $lines[] = '- '.$t('Bildbelege').':';
            if ($finding['screenshots'] === []) {
                $lines[] = '  - '.$t('Keine Bilddatei für die gewählte Bildauswahl.');
            }
            foreach ($finding['screenshots'] as $image) {
                $basisSource = match ($image['basisSource'] ?? null) {
                    'assessment' => $t('Bewertung'),
                    'acknowledgement' => $t('Spätere Sichtung'),
                    default => $image['basisSource'] ?? null,
                };
                $lines[] = isset($image['archivePath'])
                    ? '  - `'.$image['archivePath'].'`'.($basisSource === null ? '' : ' ('.$t('dokumentierte Grundlage').': '.$basisSource.')')
                    : '  - '.$t('Beleg').' '.$this->markdown((string) $image['id']).': '.$this->missingImageDescription((string) ($image['missingReason'] ?? 'missing_or_unreadable'));
            }
            if (array_key_exists('hasKnownAssessmentImageBasis', $finding) && !$finding['hasKnownAssessmentImageBasis']) {
                $lines[] = '  - '.$t('Für die aktuelle Bewertung ist keine konkrete Bildgrundlage dokumentiert.');
            }
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /** @return array{reason: ?string, byteSize: int} */
    private function preflightImage(string $findingId, ?string $storedPath, ?string $selectionIssue, int $remainingBytes): array
    {
        if ($selectionIssue !== null) {
            return ['reason' => $selectionIssue, 'byteSize' => 0];
        }
        if ($storedPath === null) {
            return ['reason' => 'missing_or_unreadable', 'byteSize' => 0];
        }
        if (!$this->belongsToFinding($storedPath, $findingId)) {
            return ['reason' => 'invalid_stored_path', 'byteSize' => 0];
        }
        $knownSize = $this->storage->size($storedPath);
        if ($knownSize === null) {
            return ['reason' => 'missing_or_unreadable', 'byteSize' => 0];
        }
        if ($knownSize > self::MAX_REPORT_IMAGE_BYTES) {
            return ['reason' => 'image_size_limit', 'byteSize' => $knownSize];
        }
        if ($knownSize > max(0, $remainingBytes)) {
            return ['reason' => 'archive_size_limit', 'byteSize' => $knownSize];
        }

        return ['reason' => null, 'byteSize' => $knownSize];
    }

    /**
     * @return array{contents: ?string, reason: ?string, actualSha256: ?string, integrityMatchesStoredHash: ?bool, byteSize: int, bytesInspected: int, mimeType: ?string, extension: ?string}
     */
    private function inspectImage(string $findingId, ?string $storedPath, ?string $storedSha256, ?string $selectionIssue, int $remainingBytes): array
    {
        $preflight = $this->preflightImage($findingId, $storedPath, $selectionIssue, $remainingBytes);
        $result = [
            'contents' => null, 'reason' => $preflight['reason'], 'actualSha256' => null,
            'integrityMatchesStoredHash' => null, 'byteSize' => $preflight['byteSize'],
            'bytesInspected' => 0, 'mimeType' => null, 'extension' => null,
        ];
        if ($preflight['reason'] !== null) {
            return $result;
        }
        try {
            $contents = $this->storage->read($storedPath);
        } catch (\Throwable) {
            $result['reason'] = 'missing_or_unreadable';

            return $result;
        }
        $result['byteSize'] = strlen($contents);
        $result['bytesInspected'] = $result['byteSize'];
        if ($result['byteSize'] > self::MAX_REPORT_IMAGE_BYTES) {
            $result['reason'] = 'image_size_limit';

            return $result;
        }
        if ($result['byteSize'] > max(0, $remainingBytes)) {
            $result['reason'] = 'archive_size_limit';

            return $result;
        }
        $result['actualSha256'] = hash('sha256', $contents);
        if ($storedSha256 !== null) {
            $result['integrityMatchesStoredHash'] = preg_match('/^[a-f0-9]{64}$/iD', $storedSha256) === 1
                && hash_equals(strtolower($storedSha256), $result['actualSha256']);
            if (!$result['integrityMatchesStoredHash']) {
                $result['reason'] = 'hash_mismatch';

                return $result;
            }
        }
        $image = @getimagesizefromstring($contents);
        $mime = is_array($image) ? strtolower((string) ($image['mime'] ?? '')) : '';
        if (!isset(self::IMAGE_EXTENSIONS[$mime])) {
            $result['reason'] = 'not_supported_image';

            return $result;
        }
        $result['contents'] = $contents;
        $result['mimeType'] = $mime;
        $result['extension'] = self::IMAGE_EXTENSIONS[$mime];

        return $result;
    }

    private function archiveImagePath(string $findingId, string $evidenceId, string $extension): string
    {
        $safeFinding = preg_replace('/[^A-Za-z0-9_-]/', '_', $findingId) ?: 'finding';
        $safeEvidence = preg_replace('/[^A-Za-z0-9_-]/', '_', $evidenceId) ?: 'evidence';

        return 'findings/'.$safeFinding.'/screenshots/'.$safeEvidence.'.'.$extension;
    }

    private function missingImageDescription(string $reason): string
    {
        return $this->i18n->trans(match ($reason) {
            'archive_size_limit' => 'nicht beigefügt, weil das Größenlimit des Pakets erreicht ist.',
            'basis_evidence_missing' => 'die gespeicherte Bewertungsgrundlage existiert nicht mehr.',
            'basis_not_image' => 'die gespeicherte Bewertungsgrundlage ist kein Screenshot.',
            'hash_mismatch' => 'nicht beigefügt, weil der Dateiinhalt nicht mehr zum gespeicherten Hash passt.',
            'image_size_limit' => 'nicht beigefügt, weil die Bilddatei größer als 25 MiB ist.',
            'invalid_stored_path' => 'nicht beigefügt, weil der Speicherpfad nicht zu diesem Fall gehört.',
            'not_supported_image' => 'nicht beigefügt, weil die Datei kein unterstütztes Bild ist.',
            default => 'die Bilddatei fehlt oder ist nicht lesbar.',
        });
    }

    private function markdown(string $value): string
    {
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace('/([\\\\`*_[\]{}()#+.!|>~-])/', '\\\\$1', $value) ?? $value;
    }

    private function indentedCode(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        preg_match_all('/`+/', $value, $runs);
        $longest = $runs[0] === [] ? 0 : max(array_map('strlen', $runs[0]));
        $fence = str_repeat('`', max(3, $longest + 1));

        // Start a standalone fenced block outside list indentation. Its fence
        // is longer than every stored run, so HTML and Markdown stay literal.
        return "\n".$fence."text\n".$value."\n".$fence."\n";
    }

    private function normalizedStoredPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        return str_starts_with($path, 'storage/artifacts/') ? substr($path, strlen('storage/artifacts/')) : $path;
    }

    private function belongsToFinding(string $path, string $findingId): bool
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($path, '/') || preg_match('~(^|/)(?:\.{1,2})(?:/|$)|//|[\x00-\x1f\x7f]|^[a-zA-Z]:~', $path)) {
            return false;
        }
        $normalized = $this->normalizedStoredPath($path);

        return str_starts_with($normalized, $findingId.'/') && strlen($normalized) > strlen($findingId) + 1;
    }

    private function captureKey(string $findingId, string $path): string
    {
        return $findingId."\0".$this->normalizedStoredPath($path);
    }

    private function artifactUrl(?string $path, string $findingId): ?string
    {
        if ($path === null || !$this->belongsToFinding($path, $findingId)) {
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
