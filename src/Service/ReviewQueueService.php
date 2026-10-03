<?php

namespace App\Service;

use App\Dto\ReviewQueueView;
use App\Entity\Evidence;
use App\Entity\RetestRun;
use App\Entity\FindingReviewAcknowledgement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Reads an unresolved review supply; GET never creates work or changes a case. */
final class ReviewQueueService
{
    private const CANDIDATES = <<<'SQL'
        SELECT f.id, f.created_at, r.result
        FROM finding f
        LEFT JOIN retest_run r ON r.rowid = (
            SELECT latest.rowid FROM retest_run latest
            WHERE latest.finding_id = f.id
            ORDER BY COALESCE(latest.finished_at, latest.started_at) DESC, latest.rowid DESC LIMIT 1
        )
        WHERE f.manual_assessment IS NULL AND f.status NOT IN ('duplicate', 'discarded')
          AND (r.id IS NULL OR r.result IN ('inconclusive', 'error'))
        SQL;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EvidenceStorageInterface $storage,
        private readonly FindingDetailService $detail,
        private readonly FindingService $findings,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly ReviewNoticeService $notices,
    ) {
    }

    /** @param array<string, mixed> $query */
    public function get(array $query, ?string $currentId = null): ReviewQueueView
    {
        [$kind, $images, $after] = $this->filters($query);
        $connection = $this->entityManager->getConnection();
        $lastReviewedId = $query['reviewed'] ?? null;
        if ($lastReviewedId !== null && (!is_string($lastReviewedId) || !Uuid::isValid($lastReviewedId))) {
            throw new \InvalidArgumentException('Ungültiger zuletzt bewerteter Fall.');
        }
        if ($lastReviewedId !== null && $connection->fetchOne('SELECT id FROM finding WHERE id = ?', [$lastReviewedId]) === false) {
            $lastReviewedId = null;
        }
        $candidates = $connection->fetchAllAssociative(self::CANDIDATES.' ORDER BY f.created_at ASC, f.id ASC');
        $noticesAvailable = $this->notices->available();
        $notices = $this->notices->forFindings();
        $noticeIds = array_keys(array_filter($notices, static fn ($notice): bool => $notice->observations !== []));
        if ($noticeIds !== []) {
            foreach ($connection->fetchAllAssociative('SELECT id, created_at FROM finding WHERE id IN ('.implode(',', array_fill(0, count($noticeIds), '?')).')', $noticeIds) as $candidate) {
                $candidates[] = $candidate + ['result' => 'changed'];
            }
            usort($candidates, static fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);
        }
        $ready = [];
        // All evidence paths are read together. Only the displayed case receives
        // its full entity/detail projection; job status cannot prove file presence.
        $candidateIds = array_column($candidates, 'id');
        $paths = $candidateIds === [] ? [] : $connection->fetchAllAssociative(
            'SELECT finding_id, file_path FROM evidence WHERE kind = ? AND file_path IS NOT NULL AND file_path <> ? AND finding_id IN ('.implode(',', array_fill(0, count($candidateIds), '?')).')',
            ['screenshot', '', ...$candidateIds],
        );
        foreach ($paths as $path) {
            if (!isset($ready[$path['finding_id']]) && $this->storage->exists($path['file_path'])) {
                $ready[$path['finding_id']] = true;
            }
        }

        $anchor = $after === null ? false : $connection->fetchAssociative('SELECT created_at, id FROM finding WHERE id = ?', [$after]);
        if ($after !== null && $anchor === false) {
            throw new \InvalidArgumentException('Der Ausgangsfall ist nicht mehr vorhanden. Den Review-Vorrat bitte neu öffnen.');
        }
        $counts = ['all' => 0, 'inconclusive' => 0, 'error' => 0, 'unchecked' => 0, 'ready' => 0, 'missing' => 0, 'changed' => 0];
        $filtered = [];
        $remaining = [];
        $eligible = false;
        foreach ($candidates as $candidate) {
            $candidateKind = $candidate['result'] ?? 'unchecked';
            $hasImage = isset($ready[$candidate['id']]);
            ++$counts['all'];
            ++$counts[$candidateKind];
            ++$counts[$hasImage ? 'ready' : 'missing'];
            if ($candidate['id'] === $currentId) {
                $eligible = true;
            }
            if (($kind !== 'all' && $kind !== $candidateKind)
                || ($images === 'ready' && !$hasImage) || ($images === 'missing' && $hasImage)
            ) {
                continue;
            }
            $filtered[] = $candidate;
            if ($anchor === false || [$candidate['created_at'], $candidate['id']] > [$anchor['created_at'], $anchor['id']]) {
                $remaining[] = $candidate;
            }
        }
        $id = $currentId ?? ($remaining[0]['id'] ?? null);
        // Capture the state before hydrating the card. An observation arriving
        // during rendering makes this token stale rather than authorizing a
        // decision about unseen data.
        $stateFingerprint = $id === null ? '' : $this->fingerprint($id);
        $currentDetail = $id === null ? null : $this->detail->get($id, true);
        $currentNotice = $currentDetail?->notice;
        $selectedEvidenceId = null;
        if ($currentDetail !== null) {
            foreach ($currentDetail->screenshots as $screenshot) {
                if ($screenshot['available']) {
                    $selectedEvidenceId ??= $screenshot['evidence']->getId();
                }
                if (is_string($query['evidence'] ?? null) && $query['evidence'] === $screenshot['evidence']->getId()) {
                    $selectedEvidenceId = $query['evidence'];
                    break;
                }
            }
            $firstScreenshot = $currentDetail->screenshots[0] ?? null;
            $selectedEvidenceId ??= $firstScreenshot === null ? null : $firstScreenshot['evidence']->getId();
        }

        return new ReviewQueueView(
            detail: $currentDetail,
            kind: $kind,
            images: $images,
            counts: $counts,
            total: count($filtered),
            remaining: count($remaining),
            nextPath: $this->path($kind, $images, $id ?? $after),
            restartPath: $this->path($kind, $images),
            currentPath: $this->path($kind, $images, $after, is_string($query['evidence'] ?? null) && $query['evidence'] === $selectedEvidenceId ? $selectedEvidenceId : null),
            after: $after,
            eligible: $currentId === null ? $currentDetail !== null : $eligible,
            lastReviewedId: $lastReviewedId,
            stateFingerprint: $stateFingerprint,
            selectedEvidenceId: $selectedEvidenceId,
            notice: ($currentNotice?->observations ?? []) !== [],
            triggeringObservations: $currentNotice?->observations ?? [],
            lastAcknowledgedAt: $currentNotice?->lastAcknowledgedAt,
            baselineKnown: $currentNotice?->baselineKnown ?? true,
            noticesAvailable: $noticesAvailable,
        );
    }

    /** @return array{string, string, ?string} */
    public function filters(array $query): array
    {
        $kind = $query['kind'] ?? 'all';
        $images = $query['images'] ?? 'ready';
        $after = $query['after'] ?? null;
        if (!is_string($kind) || !in_array($kind, ['all', 'inconclusive', 'error', 'unchecked', 'changed'], true)
            || !is_string($images) || !in_array($images, ['ready', 'all', 'missing'], true)
            || ($after !== null && (!is_string($after) || ($after !== '' && !Uuid::isValid($after))))
        ) {
            throw new \InvalidArgumentException('Ungültige Review-Auswahl.');
        }

        return [$kind, $images, $after === '' ? null : $after];
    }

    public function path(string $kind, string $images, ?string $after = null, ?string $evidence = null): string
    {
        $query = [];
        if ($kind !== 'all') {
            $query['kind'] = $kind;
        }
        if ($images !== 'ready') {
            $query['images'] = $images;
        }
        if ($after !== null) {
            $query['after'] = $after;
        }
        if ($evidence !== null) {
            $query['evidence'] = $evidence;
        }

        return '/review'.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function contextToken(string $id, string $fingerprint): string
    {
        // The CSRF manager signs this exact state with the current session. A
        // client cannot forge a fresh context after an intervening decision/run.
        return $fingerprint.':'.$this->csrf->getToken('review_context_'.$id.'_'.$fingerprint)->getValue();
    }

    /** @throws \UnexpectedValueException when the displayed state changed */
    public function assess(string $id, string $assessment, ?string $reason, ?string $observationId, ?string $evidenceId, string $contextToken): void
    {
        if (!in_array($assessment, ['confirmed', 'fixed', 'discarded', 'keep'], true) || !in_array($reason, [null, 'duplicate'], true)
            || ($assessment !== 'discarded' && $reason !== null)
        ) {
            throw new \InvalidArgumentException('Ungültige Bewertung oder ungültiger Verwerfungsgrund.');
        }
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            // Acquire SQLite's writer lock before reading the decision boundary.
            // Competing POSTs wait and then revalidate; a stale managed entity
            // must not silently replace the newly committed manual assessment.
            if ($connection->executeStatement('UPDATE finding SET id = id WHERE id = ?', [$id]) !== 1) {
                throw new \UnexpectedValueException('Der Fall ist nicht mehr vorhanden.');
            }
            [$fingerprint, $token] = array_pad(explode(':', $contextToken, 2), 2, '');
            if (!hash_equals($this->fingerprint($id), $fingerprint)
                || !$this->csrf->isTokenValid(new CsrfToken('review_context_'.$id.'_'.$fingerprint, $token))
                || ($connection->fetchOne('SELECT id FROM ('.self::CANDIDATES.') c WHERE c.id = ?', [$id]) === false && ($this->notices->get($id)?->observations ?? []) === [])
            ) {
                throw new \UnexpectedValueException('Der Fall oder seine letzte Beobachtung hat sich geändert. Bitte die angezeigten Daten erneut prüfen.');
            }
            $finding = $this->findings->getFindingOrFail($id);
            $this->entityManager->refresh($finding);
            if ($evidenceId !== null) {
                $evidence = $connection->fetchAssociative('SELECT kind, file_path FROM evidence WHERE id = ? AND finding_id = ?', [$evidenceId, $id]);
                if ($evidence === false) {
                    throw new \InvalidArgumentException('Der gewählte Beleg gehört nicht zu diesem Fall.');
                }
                if ($evidence['kind'] === 'screenshot' && ($evidence['file_path'] === null || !$this->storage->exists($evidence['file_path']))) {
                    throw new \UnexpectedValueException('Der gewählte Screenshot ist inzwischen nicht mehr lesbar. Bitte die Bewertungsgrundlage erneut prüfen.');
                }
            }
            if ($assessment === 'keep') {
                $notice = $this->notices->get($id);
                if ($notice === null || $notice->observations === []) {
                    throw new \InvalidArgumentException('Ein Hinweis kann nur für einen bereits manuell bewerteten Fall erledigt werden.');
                }
                $snapshot = [];
                if ($observationId !== null) {
                    $run = $this->entityManager->find(RetestRun::class, $observationId);
                    if (!$run instanceof RetestRun || $run->getFinding()->getId() !== $id) {
                        throw new \InvalidArgumentException('Die gewählte Beobachtung gehört nicht zu diesem Fall.');
                    }
                    $snapshot['observation'] = ['id' => $run->getId(), 'result' => $run->getResult(), 'mode' => $run->getMode(), 'startedAt' => $run->getStartedAt()->format(DATE_ATOM), 'finishedAt' => $run->getFinishedAt()?->format(DATE_ATOM), 'screenshotPath' => $run->getScreenshotPath()];
                }
                if ($evidenceId !== null) {
                    $item = $this->entityManager->find(Evidence::class, $evidenceId);
                    $snapshot['evidence'] = ['id' => $item->getId(), 'kind' => $item->getKind(), 'storedAt' => $item->getCreatedAt()->format(DATE_ATOM), 'filePath' => $item->getFilePath(), 'sha256' => $item->getSha256()];
                }
                $ack = new FindingReviewAcknowledgement($finding, $notice->decisionKey, $finding->getManualAssessment(), $this->notices->states($id), array_column($notice->observations, 'id'), $observationId, $evidenceId, $snapshot === [] ? null : $snapshot);
                $this->entityManager->persist($ack);
                $this->entityManager->flush();
            } else {
                $this->findings->assess($finding, $assessment, $reason, $observationId, $evidenceId);
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    private function fingerprint(string $id): string
    {
        $connection = $this->entityManager->getConnection();
        $finding = $connection->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$id]);
        $observations = $connection->fetchAllAssociative('SELECT * FROM retest_run WHERE finding_id = ? ORDER BY rowid ASC', [$id]);
        $decision = $connection->fetchAssociative('SELECT * FROM finding_assessment WHERE finding_id = ? ORDER BY assessed_at DESC, id DESC LIMIT 1', [$id]);
        $ack = $this->notices->available() ? $connection->fetchAssociative('SELECT * FROM finding_review_acknowledgement WHERE finding_id = ? ORDER BY reviewed_at DESC, id DESC LIMIT 1', [$id]) : false;

        return hash('sha256', json_encode([$finding, $observations, $decision, $ack], JSON_THROW_ON_ERROR));
    }
}
