<?php
namespace App\Service;

use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;
use App\Entity\Finding;
use App\Value\RetestResult;
use App\Value\ReviewState;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Explicit workflow deferrals and at most one browser request per scheduled revision. */
final class ReviewDispositionService
{
    public const REASONS = ['unreachable', 'protection', 'poc_unreproducible', 'evidence', 'other'];
    private const CLAIM_SECONDS = 600;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReviewQueueService $queue,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly BrowserRetestClientInterface $browser,
        private readonly RetestService $retests,
    ) {}

    public static function available(Connection $connection): bool
    {
        return (bool) $connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'finding_review_disposition'");
    }

    public static function activeSql(Connection $connection, string $alias = 'f'): string
    {
        return self::available($connection) ? "NOT EXISTS (SELECT 1 FROM finding_review_disposition rd WHERE rd.finding_id = $alias.id AND rd.state = 'deferred')" : '1 = 1';
    }

    public static function isDeferred(Connection $connection, string $id): bool
    {
        return self::available($connection) && (bool) $connection->fetchOne("SELECT 1 FROM finding_review_disposition WHERE finding_id = ? AND state = 'deferred'", [$id]);
    }

    public function get(string $id): ?array
    {
        $connection = $this->entityManager->getConnection();
        if (!self::available($connection)) return null;
        return $connection->fetchAssociative('SELECT * FROM finding_review_disposition WHERE finding_id = ?', [$id]) ?: null;
    }

    public function defer(string $id, string $reason, ?string $note, ?\DateTimeImmutable $due, string $contextToken): void
    {
        $note = trim($note ?? '');
        if (!in_array($reason, self::REASONS, true) || mb_strlen($note) > 2000 || ($due !== null && $due <= new \DateTimeImmutable())) {
            throw new \InvalidArgumentException('Bitte einen gültigen Grund, höchstens 2000 Zeichen Notiz und einen zukünftigen Termin wählen.');
        }
        $this->mutate($id, $contextToken, function (Connection $connection) use ($id, $reason, $note, $due): void {
            $connection->executeStatement(
                "INSERT INTO finding_review_disposition (finding_id, revision, state, reason, note, due_at, changed_at, retry_state) VALUES (?, 1, 'deferred', ?, ?, ?, ?, ?) ON CONFLICT(finding_id) DO UPDATE SET revision = revision + 1, state = 'deferred', reason = excluded.reason, note = excluded.note, due_at = excluded.due_at, changed_at = excluded.changed_at, retry_state = excluded.retry_state, retry_token = NULL, retry_started_at = NULL, retry_finished_at = NULL, retry_result = NULL, retry_message = NULL, retry_run_id = NULL, retry_context = NULL",
                [$id, $reason, $note === '' ? null : $note, $due?->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'), (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $due === null ? null : 'pending'],
            );
            $connection->executeStatement('UPDATE finding SET next_due_at = NULL WHERE id = ?', [$id]);
        });
    }

    public function resume(string $id, string $contextToken): void
    {
        $this->mutate($id, $contextToken, function (Connection $connection) use ($id): void {
            if ($connection->executeStatement("UPDATE finding_review_disposition SET revision = revision + 1, state = 'active', due_at = NULL, changed_at = ?, retry_state = CASE WHEN retry_state IN ('pending', 'running') THEN 'cancelled' ELSE retry_state END, retry_token = NULL WHERE finding_id = ? AND state = 'deferred'", [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]) !== 1) {
                throw new \UnexpectedValueException('Der Fall ist nicht mehr zurückgestellt.');
            }
        });
    }

    /** Count only unique confirmed POST actions; a context gets at most one prompt. */
    public function recordSkip(string $id, string $contextToken, string $actionId): bool
    {
        if (!preg_match('/^[A-Za-z0-9_:-]{16,100}$/D', $actionId)) throw new \InvalidArgumentException('Ungültige Skip-Aktion.');
        return $this->mutate($id, $contextToken, function (Connection $connection) use ($id, $contextToken, $actionId): bool {
            $fingerprint = explode(':', $contextToken, 2)[0];
            $row = $connection->fetchAssociative('SELECT * FROM finding_review_skip WHERE finding_id = ? AND context_fingerprint = ?', [$id, $fingerprint]);
            if ($row && (bool) $row['prompted']) return false;
            $actions = $row ? json_decode($row['actions'], true, 512, JSON_THROW_ON_ERROR) : [];
            if (in_array($actionId, $actions, true)) return false;
            $actions[] = $actionId;
            $count = count($actions);
            $prompt = $count >= 3;
            $connection->executeStatement('INSERT INTO finding_review_skip (finding_id, context_fingerprint, actions, skip_count, prompted) VALUES (?, ?, ?, ?, ?) ON CONFLICT(finding_id, context_fingerprint) DO UPDATE SET actions = excluded.actions, skip_count = excluded.skip_count, prompted = excluded.prompted', [$id, $fingerprint, json_encode($actions, JSON_THROW_ON_ERROR), $count, (int) $prompt]);
            // A changed observation invalidates the old prompt context. Keep only
            // the current bounded counter rather than a lifetime action log.
            $connection->executeStatement('DELETE FROM finding_review_skip WHERE finding_id = ? AND context_fingerprint <> ?', [$id, $fingerprint]);
            return $prompt;
        });
    }

    private function mutate(string $id, string $contextToken, callable $action): mixed
    {
        $connection = $this->entityManager->getConnection();
        if (!self::available($connection)) throw new \LogicException('Bitte zuerst die Datenbank für Review-Zurückstellungen aktualisieren.');
        $connection->beginTransaction();
        try {
            if ($connection->executeStatement('UPDATE finding SET id = id WHERE id = ?', [$id]) !== 1) throw new \UnexpectedValueException('Der Fall ist nicht mehr vorhanden.');
            [$fingerprint, $token] = array_pad(explode(':', $contextToken, 2), 2, '');
            if (!hash_equals($this->queue->fingerprint($id), $fingerprint) || !$this->csrf->isTokenValid(new CsrfToken('review_context_'.$id.'_'.$fingerprint, $token))) {
                throw new \UnexpectedValueException('Der Fall hat sich inzwischen geändert. Bitte die aktuellen Daten erneut prüfen.');
            }
            $result = $action($connection);
            $connection->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($connection->isTransactionActive()) $connection->rollBack();
            throw $error;
        }
    }

    /** Called by the existing recheck fleet before ordinary stock work. */
    public function processNext(int $timeoutMs = 120000): ?string
    {
        // Native-dialog desktop capture shares one display at the browser
        // worker. Keep recovery requests serial across the local recheck fleet.
        $lock = fopen(sys_get_temp_dir().'/librebugbounty-review-recovery.lock', 'c+');
        if ($lock === false) throw new \RuntimeException('Recovery-Aufnahmesperre ist nicht verfügbar.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return null; }
        try { return $this->processClaimed($timeoutMs); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function processClaimed(int $timeoutMs): ?string
    {
        $claim = $this->claimNext();
        if ($claim === null) return null;
        $connection = $this->entityManager->getConnection();
        $finding = $this->entityManager->find(Finding::class, $claim['finding_id']);
        if (!$finding instanceof Finding) return 'recovery-removed';
        $this->entityManager->refresh($finding);
        $result = null;
        try {
            // Revalidate immediately before the external request. A claim is
            // consumed even if process death makes its outcome unknowable.
            if (!$this->claimCurrent($claim) || !$this->contextCurrent($claim) || !(new FindingWorkPolicy($connection))->checksAllowed($finding) || $finding->isDiscarded()) {
                return $this->cancelClaim($claim, 'Der Fall wurde vor dem Versuch geändert.');
            }
            $result = $this->browser->retest(new BrowserRetestRequest(
                url: $finding->getPocUrl(), expectedEvidence: $finding->getExpectedEvidence(),
                timeoutMs: min(120000, max(1000, $timeoutMs)), screenshot: true, headless: false,
            ));
        } catch (\Throwable $error) {
            $result = new RetestResultData(RetestResult::ERROR, errorMessage: $error->getMessage());
        }
        return $this->complete($claim, $finding, $result);
    }

    public function claimNext(?\DateTimeImmutable $now = null): ?array
    {
        $connection = $this->entityManager->getConnection();
        if (!self::available($connection)) return null;
        $now ??= new \DateTimeImmutable();
        $at = $now->format('Y-m-d H:i:s');
        $connection->beginTransaction();
        try {
            // Writer first: there is no deferred read-to-write lock upgrade.
            $connection->executeStatement('UPDATE finding_review_disposition SET revision = revision WHERE 1 = 0');
            $connection->executeStatement("UPDATE finding_review_disposition SET retry_state = 'interrupted', retry_finished_at = ?, retry_result = 'error', retry_message = 'Der einmalige Versuch wurde unterbrochen; bitte bei Bedarf einen neuen Termin wählen.', retry_token = NULL WHERE state = 'deferred' AND retry_state = 'running' AND retry_started_at <= ?", [$at, $now->modify('-'.self::CLAIM_SECONDS.' seconds')->format('Y-m-d H:i:s')]);
            [$low, $high] = RecheckPolicy::claimWindow($now);
            $claim = $connection->fetchAssociative(
                "SELECT rd.* FROM finding_review_disposition rd JOIN finding f ON f.id = rd.finding_id WHERE rd.state = 'deferred' AND rd.retry_state = 'pending' AND rd.due_at <= ? AND f.status NOT IN ('discarded','duplicate') AND (f.manual_assessment IS NULL OR f.manual_assessment <> 'discarded') AND ".FindingWorkPolicy::checksAllowedSql($connection)." AND NOT EXISTS (SELECT 1 FROM finding same WHERE same.domain_id = f.domain_id AND ((same.last_retested_at >= ?) OR (same.next_due_at > ? AND same.next_due_at <= ?))) AND NOT EXISTS (SELECT 1 FROM finding_review_disposition other JOIN finding other_f ON other_f.id = other.finding_id WHERE other_f.domain_id = f.domain_id AND other.retry_started_at >= ?) ORDER BY rd.due_at, rd.finding_id LIMIT 1",
                [$at, $now->modify('-'.RecheckPolicy::DOMAIN_MIN_INTERVAL_SECONDS.' seconds')->format('Y-m-d H:i:s'), $low->format('Y-m-d H:i:s'), $high->format('Y-m-d H:i:s'), $now->modify('-'.RecheckPolicy::DOMAIN_MIN_INTERVAL_SECONDS.' seconds')->format('Y-m-d H:i:s')],
            );
            if (!$claim) { $connection->commit(); return null; }
            $context = $this->findingContext($claim['finding_id']);
            $token = bin2hex(random_bytes(24));
            $connection->executeStatement("UPDATE finding_review_disposition SET retry_state = 'running', retry_token = ?, retry_started_at = ?, retry_context = ? WHERE finding_id = ? AND revision = ? AND retry_state = 'pending'", [$token, $at, json_encode($context, JSON_THROW_ON_ERROR), $claim['finding_id'], $claim['revision']]);
            // Share the existing domain spacing with ordinary rechecks.
            $connection->executeStatement('UPDATE finding SET next_due_at = ? WHERE domain_id = (SELECT domain_id FROM finding WHERE id = ?) AND next_due_at IS NOT NULL AND next_due_at < ?', [$now->modify('+'.RecheckPolicy::DOMAIN_MIN_INTERVAL_SECONDS.' seconds')->format('Y-m-d H:i:s'), $claim['finding_id'], $now->modify('+'.RecheckPolicy::DOMAIN_MIN_INTERVAL_SECONDS.' seconds')->format('Y-m-d H:i:s')]);
            $connection->commit();
            return $claim + ['claim_token' => $token, 'context' => $context];
        } catch (\Throwable $error) {
            if ($connection->isTransactionActive()) $connection->rollBack();
            throw $error;
        }
    }

    private function complete(array $claim, Finding $finding, RetestResultData $result): string
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $connection->executeStatement('UPDATE finding SET id = id WHERE id = ?', [$claim['finding_id']]);
            if (!$this->claimCurrent($claim) || !$this->contextCurrent($claim) || !(new FindingWorkPolicy($connection))->checksAllowed($finding)) {
                $historical = $this->retests->recordBrowserResult($finding, $result, true, true, $claim['context']['pocUrl'], $claim['context']['pocRevision'], ['staleRecovery' => true], recovery: true);
                $outcome = $this->cancelClaim($claim, 'Das verspätete Ergebnis wurde wegen einer zwischenzeitlichen Änderung nicht übernommen.', $result->result, $historical->getId());
                $connection->commit(); return $outcome;
            }
            $context = $claim['context'];
            $run = $this->retests->recordBrowserResult($finding, $result, true, true, $context['pocUrl'], $context['pocRevision'], recovery: true);
            $dialog = $result->raw['dialogEvents'][0]['message'] ?? null;
            $expected = $finding->getExpectedEvidence();
            $usable = $result->result === RetestResult::STILL_VULNERABLE
                && $result->errorMessage === null && ($result->httpStatus === null || $result->httpStatus < 400)
                && is_string($dialog) && $dialog !== '' && ($expected === null || $expected === '' || str_contains($dialog, $expected))
                && ($result->raw['screenshotCapturedFromDialog'] ?? false) === true
                && ($result->raw['screenshotCaptureMethod'] ?? null) === 'desktop-dialog'
                && empty($result->raw['screenshotCaptureError'])
                && $run->getScreenshotPath() !== null
                && @getimagesizefromstring(base64_decode($result->screenshotBase64 ?? '', true) ?: '') !== false;
            if ($usable) {
                $finding->setReviewState(ReviewState::MANUAL_CHECKING);
                $finding->setLastRetestedAt(new \DateTimeImmutable());
                $finding->setNextDueAt(null);
                $this->entityManager->flush();
            }
            $connection->executeStatement("UPDATE finding_review_disposition SET state = ?, retry_state = 'completed', retry_finished_at = ?, retry_result = ?, retry_message = ?, retry_run_id = ?, retry_token = NULL WHERE finding_id = ? AND revision = ? AND retry_token = ?", [$usable ? 'active' : 'deferred', (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $result->result, $usable ? 'Neuer Dialogbeleg liegt zur Sichtung bereit.' : ($result->errorMessage ?? 'Kein belastbarer Dialogbeleg; der Fall bleibt zurückgestellt.'), $run->getId(), $claim['finding_id'], $claim['revision'], $claim['claim_token']]);
            $connection->commit();
            return $usable ? 'recovery-review' : 'recovery-deferred';
        } catch (\Throwable $error) {
            if ($connection->isTransactionActive()) $connection->rollBack();
            // A persistence/storage failure consumes this one attempt too.
            $this->cancelClaim($claim, 'Der Versuch konnte nicht gespeichert werden: '.$error->getMessage());
            return 'recovery-error';
        }
    }

    private function claimCurrent(array $claim): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne("SELECT 1 FROM finding_review_disposition WHERE finding_id = ? AND revision = ? AND state = 'deferred' AND retry_state = 'running' AND retry_token = ?", [$claim['finding_id'], $claim['revision'], $claim['claim_token']]);
    }
    private function findingContext(string $id): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$id]);
        if (!$row) return [];
        // Scheduling slots and technical bookkeeping do not change the user's
        // decision context; every assessment/URL/marker change does.
        unset($row['next_due_at'], $row['last_retested_at'], $row['updated_at']);
        $assessmentId = $this->entityManager->getConnection()->fetchOne('SELECT id FROM finding_assessment WHERE finding_id = ? ORDER BY rowid DESC LIMIT 1', [$id]);
        return ['finding' => $row, 'assessmentId' => $assessmentId, 'pocUrl' => $row['poc_url'] ?? $row['url'], 'pocRevision' => (int) ($row['poc_revision'] ?? 0)];
    }
    private function contextCurrent(array $claim): bool { return $claim['context'] === $this->findingContext($claim['finding_id']); }
    private function cancelClaim(array $claim, string $message, string $result = 'error', ?string $runId = null): string
    {
        $this->entityManager->getConnection()->executeStatement("UPDATE finding_review_disposition SET retry_state = 'cancelled', retry_finished_at = ?, retry_result = ?, retry_message = ?, retry_run_id = ?, retry_token = NULL WHERE finding_id = ? AND revision = ? AND retry_token = ?", [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $result, $message, $runId, $claim['finding_id'], $claim['revision'], $claim['claim_token']]);
        return 'recovery-cancelled';
    }
}
