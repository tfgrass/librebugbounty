<?php

namespace App\Service;

use App\Dto\InteractiveCaptureSaveResult;
use App\Entity\Finding;
use Doctrine\DBAL\Connection;

/** Coordinates the retained desktop; leases, not PHP locks, own the session. */
final class InteractiveBrowserCoordinator
{
    public function __construct(
        private readonly InteractiveBrowserLeaseService $leases,
        private readonly FencedBrowserInteractiveClientInterface $browser,
        private readonly InteractiveGatewayControlClient $gateway,
        private readonly InteractiveCaptureService $captures,
        private readonly Connection $connection,
        private readonly SettingsService $settings,
    ) {}

    public function status(Finding $finding, string $owner, ?string $channel): array
    {
        $row = $this->leases->current();
        $payload = $this->view($row, $finding->getId(), $owner, $channel);
        try { $this->assertAllowed($finding->getId(), $finding->getUrl()); }
        catch (\Throwable) { return array_replace($payload, ['state' => 'revoked', 'reason' => 'checks_blocked', 'canTakeover' => false, 'reconnect' => false]); }
        if ($row['state'] === 'available' || ($row['finding_id'] ?? null) !== $finding->getId()) { return $payload; }
        if (!$this->bindingAllowed($row)) {
            return array_replace($payload, ['state' => 'revoked', 'reason' => 'binding_changed', 'canTakeover' => false, 'requiresEndStart' => true, 'reconnect' => false]);
        }
        if ($payload['state'] !== 'own_active') { return $payload; }
        try {
            $this->dialogPreviewRow($finding, $owner, $channel, $row['generation'], $row);
            $worker = $this->browser->interactiveStatus($row['worker_id']);
            if ($worker->generation !== null && $worker->generation !== $row['generation']) {
                return array_replace($payload, ['state' => 'activating', 'reconnect' => false]);
            }
            $this->dialogPreviewRow($finding, $owner, $channel, $row['generation'], $row);
            $payload['captureLocked'] = $worker->captureLocked;
            $payload['captureFinalized'] = $worker->captureFinalized;
            $payload['dialogObservation'] = $this->dialogView($worker->dialogObservation);
            $payload['retainedDialog'] = $this->retainedDialogView($worker->retainedDialog);
            $payload['dialogCapture'] = $this->dialogCaptureView($worker->dialogCapture);
        } catch (\Throwable $error) {
            $payload['state'] = in_array((int) $error->getCode(), [404, 410], true) ? 'expired' : 'worker_unavailable';
            $payload['reconnect'] = false;
        }
        return $payload;
    }

    /** No acquisition, capture, lease refresh or close, even on expiry/failure. */
    public function dialogPreview(Finding $finding, string $owner, string $channel, int $revision, ?string $candidateId = null): string
    {
        $row = $this->dialogPreviewRow($finding, $owner, $channel, $revision);
        $image = $this->browser->interactiveDialogFrame($row['worker_id'], $row['generation'], $candidateId);
        $this->dialogPreviewRow($finding, $owner, $channel, $revision, $row);
        return $image;
    }

    /** Dialog mutations retain the same finding, owner and browser generation. */
    public function dialog(Finding $finding, string $owner, string $channel, int $revision, string $action, int $dialogRevision): void
    {
        $row = $this->dialogPreviewRow($finding, $owner, $channel, $revision);
        $this->browser->dialogFenced($row['worker_id'], $row['generation'], $action, $dialogRevision);
        $this->dialogPreviewRow($finding, $owner, $channel, $revision, $row);
    }

    private function dialogPreviewRow(Finding $finding, string $owner, ?string $channel, int $revision, ?array $before = null): array
    {
        if ($revision < 1) { throw new \RuntimeException('Interactive revision required.', 400); }
        $row = $this->ownedRow($finding->getId(), $owner, $channel ?? '', $revision);
        if (($row['expires_at'] ?? 0) <= time()) { throw new \RuntimeException('Interactive session expired.', 410); }
        $this->assertAllowed($finding->getId(), $finding->getUrl());
        if (!$this->bindingAllowed($row) || !is_string($row['worker_id'] ?? null)
            || ($row['binding']['finding_id'] ?? null) !== $finding->getId()
            || ($row['binding']['start_url'] ?? null) !== $finding->getUrl()
            || ($before !== null && ($row['binding'] !== $before['binding'] || $row['worker_id'] !== $before['worker_id']))) {
            throw new \RuntimeException('Interactive binding changed.', 409);
        }
        return $row;
    }

    public function acquire(Finding $finding, string $owner, string $channel, ?int $revision): array
    {
        $this->assertAllowed($finding->getId(), $finding->getUrl());
        $row = $this->leases->current();
        if ($row['state'] === 'activating' && $this->owns($row, $owner, $channel) && $row['finding_id'] === $finding->getId()) {
            $this->assertRevision($row, $revision);
            try { return $this->finishActivation($row); }
            catch (\Throwable $error) {
                if (!in_array((int) $error->getCode(), [404, 410], true)) { throw $error; }
                $this->leases->clear($row['generation']);
                $row = $this->leases->current();
                $revision = $row['generation'];
            }
        }
        if ($row['state'] !== 'available') {
            $this->assertRevision($row, $revision);
            if (($row['expires_at'] ?? PHP_INT_MAX) <= time()) {
                $this->closeRow($row);
                $revision = $this->leases->current()['generation'];
            } elseif (is_string($row['worker_id'] ?? null)) {
                try { $this->browser->interactiveStatus($row['worker_id']); }
                catch (\Throwable $error) {
                    if (in_array((int) $error->getCode(), [404, 410], true)) {
                        $this->leases->clear($row['generation']);
                        $revision = $this->leases->current()['generation'];
                    } else { throw new \RuntimeException('Interactive worker unavailable.', 503, $error); }
                }
            }
        }
        $binding = [
            'finding_id' => $finding->getId(), 'start_url' => $finding->getUrl(),
            'start_url_hash' => hash('sha256', $finding->getUrl()), 'manual_entry' => true,
            'input_method' => 'vnc',
        ];
        $row = $this->leases->acquire($binding, $owner, $channel, $revision);
        $sessionId = bin2hex(random_bytes(24));
        $this->leases->attach($row['generation'], $sessionId, time(), time() + 1200);
        try {
            $worker = $this->browser->startFenced($finding->getUrl(), $this->settings->getReviewScanTimeoutMs(), $row['generation'], $sessionId);
            $row = $this->leases->attach($row['generation'], $worker->id, $worker->createdAt->getTimestamp(), $worker->expiresAt->getTimestamp(), [
                'created_at' => $worker->createdAt->format('Y-m-d\TH:i:s.vP'),
                'initial_challenge' => $worker->initialChallenge, 'initial_http_status' => $worker->initialHttpStatus,
            ]);
            return $this->finishActivation($row);
        } catch (\Throwable $error) {
            // Keep the reserved capability for recovery on an ambiguous network
            // result; never erase the only handle to a potentially live context.
            throw new \RuntimeException('Interactive browser start is pending or unavailable.', 503, $error);
        }
    }

    public function takeover(Finding $finding, string $owner, string $channel, int $revision): array
    {
        $this->assertAllowed($finding->getId(), $finding->getUrl());
        $row = $this->leases->current();
        if (!$this->bindingAllowed($row)) { throw new \RuntimeException('Interactive binding changed; restart required.', 409); }
        $row = $this->leases->takeover($finding->getId(), $owner, $channel, $revision);
        return $this->finishActivation($row);
    }

    public function release(Finding $finding, string $owner, string $channel, ?int $revision): void
    {
        $row = $this->ownedRow($finding->getId(), $owner, $channel, $revision, false);
        $this->closeRow($row);
    }

    public function endAndStart(Finding $finding, string $owner, string $channel, int $revision): array
    {
        $this->assertAllowed($finding->getId(), $finding->getUrl());
        $row = $this->leases->current();
        $this->assertRevision($row, $revision);
        if ($row['state'] === 'closing') { throw new \RuntimeException('Interactive session is closing.', 409); }
        if ($row['state'] !== 'available') { $this->closeRow($row); }
        return $this->acquire($finding, $owner, $channel, $this->leases->current()['generation']);
    }

    public function save(Finding $finding, string $owner, string $channel, int $revision, ?string $expectedCandidateId): InteractiveCaptureSaveResult
    {
        $row = $this->ownedRow($finding->getId(), $owner, $channel, $revision);
        $this->assertAllowed($finding->getId(), $row['binding']['start_url']);
        // No finally-close: losing a generation race must not destroy the new
        // owner's browser. File/RPC work is outside the final SQLite lock.
        $saved = $this->captures->persistForLease($finding, $row['worker_id'], $row['binding'], $this->leases, $row['generation'], $expectedCandidateId);
        $closed = true;
        $closeError = null;
        try { $this->closeRow($row); }
        catch (\Throwable) { $closed = false; $closeError = 'Interactive close remains pending.'; }
        return new InteractiveCaptureSaveResult($saved->job, $saved->evidence, $closed, $closeError);
    }

    /** Called by the private authorization endpoint after releasing PHP session. */
    public function authorize(string $findingId, string $owner, string $channel, ?int $generation): array
    {
        $row = $this->leases->current();
        if ($row['state'] !== 'active' || $row['finding_id'] !== $findingId || !$this->owns($row, $owner, $channel)
            || ($generation !== null && $generation !== $row['generation'])
            || ($row['expires_at'] ?? 0) <= time() || !$this->bindingAllowed($row)) {
            return ['decision' => 'deny', 'reason' => 'lease_invalid'];
        }
        return ['decision' => 'allow', 'generation' => $row['generation'], 'grantMs' => 1000];
    }

    public function assertAllowed(string $findingId, string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === '' || preg_match('/[\x00-\x20\x7f]/', str_replace(' ', '%20', $url))) {
            throw new \RuntimeException('Unsupported interactive URL.', 409);
        }
        $row = $this->connection->fetchAssociative('SELECT url, status, manual_assessment, '
            .FindingWorkPolicy::checksAllowedSql($this->connection, 'finding').' AS checks_allowed FROM finding WHERE id = ?', [$findingId]);
        if (!$row || $row['url'] !== $url || in_array($row['status'], ['discarded', 'duplicate'], true)
            || $row['manual_assessment'] === 'discarded' || !(bool) $row['checks_allowed']) {
            throw new \RuntimeException('Interactive checks are not allowed or the finding changed.', 409);
        }
    }

    private function bindingAllowed(array $row): bool
    {
        try {
            if (!is_string($row['finding_id'] ?? null) || !is_string($row['binding']['start_url'] ?? null)
                || ($row['binding']['start_url_hash'] ?? null) !== hash('sha256', $row['binding']['start_url'])) { return false; }
            $this->assertAllowed($row['finding_id'], $row['binding']['start_url']);
            return true;
        } catch (\Throwable) { return false; }
    }

    private function finishActivation(array $row): array
    {
        if (!$this->bindingAllowed($row)) { throw new \RuntimeException('Interactive binding changed.', 409); }
        if (!is_string($row['worker_id'] ?? null)) { throw new \RuntimeException('Interactive activation is pending.', 503); }
        $worker = $this->browser->interactiveStatus($row['worker_id']);
        if ($worker->expiresAt->getTimestamp() <= time()) { throw new \RuntimeException('Interactive session expired.', 410); }
        // Recover the actual timestamps after an ambiguous start response too.
        $row = $this->leases->attach($row['generation'], $worker->id, $worker->createdAt->getTimestamp(), $worker->expiresAt->getTimestamp(), [
            'created_at' => $worker->createdAt->format('Y-m-d\TH:i:s.vP'),
            'initial_challenge' => $worker->initialChallenge, 'initial_http_status' => $worker->initialHttpStatus,
        ]);
        $this->gateway->fence($row['generation']);
        return $this->leases->activate($row['generation']);
    }

    private function ownedRow(string $findingId, string $owner, string $channel, ?int $revision, bool $requireActive = true): array
    {
        $row = $this->leases->current();
        $this->assertRevision($row, $revision);
        if ($row['finding_id'] !== $findingId || !$this->owns($row, $owner, $channel)
            || ($requireActive && $row['state'] !== 'active') || $row['state'] === 'available') {
            throw new \RuntimeException('Interactive ownership was revoked.', 409);
        }
        return $row;
    }

    private function closeRow(array $row): void
    {
        $current = $this->leases->current();
        $this->assertRevision($current, $row['generation']);
        $row = $current['state'] === 'closing' ? $current : $this->leases->beginClosing($row['generation']);
        if (is_string($row['worker_id'] ?? null)) {
            try {
                $this->gateway->fence($row['generation']);
                $this->browser->closeFenced($row['worker_id'], $row['generation']);
            } catch (\Throwable $error) {
                if (!in_array((int) $error->getCode(), [404, 410], true)) { throw $error; }
            }
        }
        $this->leases->clear($row['generation']);
    }

    private function owns(array $row, string $owner, ?string $channel): bool
    {
        return $channel !== null && is_string($row['owner_hash'] ?? null) && is_string($row['channel_id'] ?? null)
            && hash_equals($row['owner_hash'], $owner) && hash_equals($row['channel_id'], $channel);
    }

    private function assertRevision(array $row, ?int $revision): void
    {
        if ($revision !== null && $revision !== $row['generation']) { throw new \RuntimeException('Stale interactive revision.', 409); }
    }

    private function view(array $row, string $findingId, string $owner, ?string $channel): array
    {
        $sameFinding = ($row['finding_id'] ?? null) === $findingId;
        $state = $row['state'];
        if ($state === 'active') { $state = $sameFinding && $this->owns($row, $owner, $channel) ? 'own_active' : 'busy'; }
        if ($row['state'] !== 'available' && ($row['expires_at'] ?? PHP_INT_MAX) <= time()) { $state = 'expired'; }
        return [
            'state' => $state, 'findingId' => $findingId, 'revision' => $row['generation'],
            'canTakeover' => $sameFinding && $row['state'] === 'active' && $state === 'busy',
            'requiresEndStart' => $row['state'] !== 'available' && !$sameFinding,
            'reconnect' => $state === 'own_active',
            'canRelease' => $row['state'] !== 'available' && $sameFinding && $this->owns($row, $owner, $channel),
            'canRetryActivation' => $row['state'] === 'activating' && $sameFinding && $this->owns($row, $owner, $channel),
            'expiresInSec' => $row['expires_at'] === null ? null : max(0, $row['expires_at'] - time()),
        ];
    }

    private function retainedDialogView(?array $dialog): ?array
    {
        if ($dialog === null) { return null; }
        $view = [];
        foreach (['capturedAt' => 64, 'detectedAt' => 64, 'pageUrl' => 10000, 'type' => 32, 'message' => 10000] as $key => $limit) {
            if (!is_string($dialog[$key] ?? null)) { return null; }
            $view[$key] = mb_substr($dialog[$key], 0, $limit);
        }
        if (is_string($dialog['id'] ?? null) && preg_match('/^[a-f0-9]{48}$/D', $dialog['id']) === 1) {
            $view['id'] = $dialog['id'];
        }
        return $view;
    }

    private function dialogCaptureView(array $dialog): array
    {
        if (!in_array($dialog['state'] ?? null, ['waiting', 'capturing', 'captured', 'failed', 'exhausted'], true)
            || !is_int($dialog['revision'] ?? null) || $dialog['revision'] < 0
            || !is_bool($dialog['armed'] ?? null) || !is_bool($dialog['pending'] ?? null)
            || !is_int($dialog['attemptsRemaining'] ?? null) || $dialog['attemptsRemaining'] < 0) { return []; }
        return array_intersect_key($dialog, array_flip(['state', 'revision', 'armed', 'pending', 'attemptsRemaining']));
    }

    private function dialogView(array $dialog): array
    {
        $view = [];
        foreach (['type' => 32, 'message' => 10000, 'detectedAt' => 64, 'capturedAt' => 64] as $key => $limit) {
            if (is_string($dialog[$key] ?? null)) { $view[$key] = mb_substr($dialog[$key], 0, $limit); }
        }
        foreach (['seen', 'dismissed', 'captureSucceeded'] as $key) {
            if (is_bool($dialog[$key] ?? null)) { $view[$key] = $dialog[$key]; }
        }
        return $view;
    }
}
