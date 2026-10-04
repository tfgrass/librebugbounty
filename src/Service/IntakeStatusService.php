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
    private readonly UiTranslator $i18n;

    public function __construct(
        private readonly Connection $connection,
        private readonly RetestRunRepository $runs,
        private readonly EvidenceStorageInterface $storage,
        ?UiTranslator $i18n = null,
    ) {
        $this->i18n = $i18n ?? new UiTranslator();
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
                'labelKey' => FindingReadLabels::observation($run->getResult()),
                'label' => $this->i18n->trans(FindingReadLabels::observation($run->getResult())),
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
            'labelKey' => 'Kein Screenshot-Auftrag gespeichert',
            'label' => $this->i18n->trans('Kein Screenshot-Auftrag gespeichert'),
            'requestedAt' => $job !== false ? $this->date($job['requested_at']) : null,
            'capturedAt' => $job !== false ? $this->date($job['captured_at']) : null,
            'error' => null,
            'errorKey' => null,
        ];
        if ($finding->isDiscarded()) {
            return array_replace($base, [
                'state' => 'discarded', 'labelKey' => 'Verworfen', 'label' => $this->i18n->trans('Verworfen'),
            ]);
        }
        if ($job === false) {
            // Older evidence may exist without a persistent screenshot job.
            // Unknown job history is never treated as a failed capture or reset.
            return $base;
        }

        return match ($job['status']) {
            ScreenshotJobStatus::QUEUED => array_replace($base, [
                'state' => 'queued', 'labelKey' => 'Screenshot in Warteschlange', 'label' => $this->i18n->trans('Screenshot in Warteschlange'),
            ]),
            ScreenshotJobStatus::RUNNING => array_replace($base, [
                'state' => 'running', 'labelKey' => 'Screenshot wird erstellt', 'label' => $this->i18n->trans('Screenshot wird erstellt'),
            ]),
            ScreenshotJobStatus::AVAILABLE => $this->availableScreenshot($base, $job['screenshot_path']),
            ScreenshotJobStatus::FAILED => $this->failedScreenshot($base, $job['error_message']),
            default => array_replace($base, [
                'state' => 'failed',
                'labelKey' => 'Screenshot-Status unbekannt',
                'label' => $this->i18n->trans('Screenshot-Status unbekannt'),
                'errorKey' => 'Der gespeicherte Screenshot-Auftrag hat keinen verständlichen Bearbeitungsstatus.',
                'error' => $this->i18n->trans('Der gespeicherte Screenshot-Auftrag hat keinen verständlichen Bearbeitungsstatus.'),
            ]),
        };
    }

    private function availableScreenshot(array $base, ?string $path): array
    {
        if ($path === null || $path === '' || !$this->storage->exists($path)) {
            return array_replace($base, [
                'state' => 'failed',
                'labelKey' => 'Screenshot-Datei fehlt',
                'label' => $this->i18n->trans('Screenshot-Datei fehlt'),
                'errorKey' => 'Die gespeicherte Screenshot-Datei fehlt oder ist nicht lesbar. Der vorhandene Belegverweis bleibt erhalten.',
                'error' => $this->i18n->trans('Die gespeicherte Screenshot-Datei fehlt oder ist nicht lesbar. Der vorhandene Belegverweis bleibt erhalten.'),
            ]);
        }

        return array_replace($base, [
            'state' => 'available', 'labelKey' => 'Screenshot verfügbar', 'label' => $this->i18n->trans('Screenshot verfügbar'),
        ]);
    }

    private function failedScreenshot(array $base, ?string $storedError): array
    {
        $hasStoredError = $storedError !== null && trim($storedError) !== '';
        $fallback = 'Für diesen Screenshot-Auftrag wurde kein Bild gespeichert.';

        return array_replace($base, [
            'state' => 'failed',
            'labelKey' => 'Screenshot fehlgeschlagen',
            'label' => $this->i18n->trans('Screenshot fehlgeschlagen'),
            'errorKey' => $hasStoredError ? null : $fallback,
            'error' => $hasStoredError ? $storedError : $this->i18n->trans($fallback),
        ]);
    }

    private function date(?string $value): ?string
    {
        return $value !== null ? (new \DateTimeImmutable($value))->format(DATE_ATOM) : null;
    }
}
