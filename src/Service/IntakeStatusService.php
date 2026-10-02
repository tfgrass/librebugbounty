<?php

namespace App\Service;

use App\Entity\Finding;
use App\Repository\RetestRunRepository;
use App\Value\FindingReadLabels;
use App\Value\ScreenshotJobStatus;
use Doctrine\DBAL\Connection;

/** Describes persisted intake work without starting or changing any work. */
final class IntakeStatusService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RetestRunRepository $runs,
        private readonly EvidenceStorageInterface $storage,
    ) {
    }

    public function status(Finding $finding): array
    {
        $run = $this->runs->findRecentByFinding($finding, 1)[0] ?? null;

        return [
            'id' => $finding->getId(),
            'url' => $finding->getUrl(),
            'detailUrl' => '/findings/'.$finding->getId(),
            'discarded' => $finding->isDiscarded(),
            'assessment' => [
                'value' => $finding->getManualAssessment(),
                'reason' => $finding->getDiscardReason(),
                'assessedAt' => $finding->getAssessedAt()?->format(DATE_ATOM),
            ],
            'observation' => $run !== null ? [
                'id' => $run->getId(),
                'result' => $run->getResult(),
                'mode' => $run->getMode(),
                'observedAt' => ($run->getFinishedAt() ?? $run->getStartedAt())->format(DATE_ATOM),
                'label' => FindingReadLabels::observation($run->getResult()),
            ] : null,
            'screenshot' => $this->screenshot($finding),
            'contactedAt' => $finding->getContactedAt()?->format(DATE_ATOM),
        ];
    }

    private function screenshot(Finding $finding): array
    {
        $job = $this->connection->fetchAssociative(
            'SELECT status, requested_at, captured_at, screenshot_path, error_message '
            .'FROM screenshot_job WHERE finding_id = ? ORDER BY requested_at DESC, rowid DESC LIMIT 1',
            [$finding->getId()],
        );
        $base = [
            'state' => 'none',
            'label' => 'Kein Screenshot-Auftrag gespeichert',
            'requestedAt' => $job !== false ? $this->date($job['requested_at']) : null,
            'capturedAt' => $job !== false ? $this->date($job['captured_at']) : null,
            'error' => null,
        ];
        if ($finding->isDiscarded()) {
            return array_replace($base, ['state' => 'discarded', 'label' => 'Verworfen']);
        }
        if ($job === false) {
            // Older evidence may exist without a persistent screenshot job.
            // Unknown job history is never treated as a failed capture or reset.
            return $base;
        }

        return match ($job['status']) {
            ScreenshotJobStatus::QUEUED => array_replace($base, ['state' => 'queued', 'label' => 'Screenshot in Warteschlange']),
            ScreenshotJobStatus::RUNNING => array_replace($base, ['state' => 'running', 'label' => 'Screenshot wird erstellt']),
            ScreenshotJobStatus::AVAILABLE => $this->availableScreenshot($base, $job['screenshot_path']),
            ScreenshotJobStatus::FAILED => array_replace($base, [
                'state' => 'failed',
                'label' => 'Screenshot fehlgeschlagen',
                'error' => $job['error_message'] !== null && trim($job['error_message']) !== ''
                    ? $job['error_message'] : 'Für diesen Screenshot-Auftrag wurde kein Bild gespeichert.',
            ]),
            default => array_replace($base, [
                'state' => 'failed',
                'label' => 'Screenshot-Status unbekannt',
                'error' => 'Der gespeicherte Screenshot-Auftrag hat keinen verständlichen Bearbeitungsstatus.',
            ]),
        };
    }

    private function availableScreenshot(array $base, ?string $path): array
    {
        if ($path === null || $path === '' || !$this->storage->exists($path)) {
            return array_replace($base, [
                'state' => 'failed',
                'label' => 'Screenshot-Datei fehlt',
                'error' => 'Die gespeicherte Screenshot-Datei fehlt oder ist nicht lesbar. Der vorhandene Belegverweis bleibt erhalten.',
            ]);
        }

        return array_replace($base, ['state' => 'available', 'label' => 'Screenshot verfügbar']);
    }

    private function date(?string $value): ?string
    {
        return $value !== null ? (new \DateTimeImmutable($value))->format(DATE_ATOM) : null;
    }
}
