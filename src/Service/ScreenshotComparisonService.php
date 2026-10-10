<?php

namespace App\Service;

use App\Dto\FindingDetailView;
use App\Dto\ScreenshotComparisonView;

/** Comparison is presentation only: it cannot select an assessment or export basis. */
final class ScreenshotComparisonService
{
    public function __construct(private readonly StoredImageInspector $images)
    {
    }

    /** @param array<string, mixed> $query */
    public function get(FindingDetailView $view, array $query): ScreenshotComparisonView
    {
        $shots = $view->screenshots;
        usort($shots, static fn (array $a, array $b): int => [
            ($b['capturedAt'] ?? $b['evidence']->getCreatedAt())->getTimestamp(), $b['evidence']->getId(),
        ] <=> [
            ($a['capturedAt'] ?? $a['evidence']->getCreatedAt())->getTimestamp(), $a['evidence']->getId(),
        ]);
        $byId = [];
        foreach ($shots as $shot) {
            $byId[$shot['evidence']->getId()] = $shot;
        }
        foreach (['compare_before', 'compare_after'] as $key) {
            if (array_key_exists($key, $query) && (!is_string($query[$key]) || !isset($byId[$query[$key]]))) {
                throw new \InvalidArgumentException('Der Vergleich benötigt Bildbelege dieses Falls.');
            }
        }
        $assessment = null;
        foreach ($view->assessments as $entry) {
            if (!isset($view->cancelledAssessmentIds[$entry->getId()])) {
                if ($view->finding->getManualAssessment() === $entry->getAssessment()
                    && $view->finding->getAssessedAt()?->format('Y-m-d H:i:s') === $entry->getAssessedAt()->format('Y-m-d H:i:s')) {
                    $assessment = $entry;
                }
                break;
            }
        }
        if (count($byId) < 2) {
            return new ScreenshotComparisonView(null, null, $assessment);
        }
        $ids = array_keys($byId);
        $basis = $assessment?->getEvidenceId();
        $before = $query['compare_before'] ?? (isset($byId[$basis ?? '']) ? $basis : $ids[1]);
        $after = $query['compare_after'] ?? array_values(array_diff($ids, [$before]))[0];
        if (!isset($query['compare_before']) && $before === $after) {
            $before = array_values(array_diff($ids, [$after]))[0];
        }
        if ($before === $after) {
            throw new \InvalidArgumentException('Für den Vergleich zwei unterschiedliche Bildbelege auswählen.');
        }
        $inspect = function (array $shot): array {
            $shot['problem'] = $this->images->problem($shot['evidence']->getFilePath());
            $shot['available'] = $shot['available'] && $shot['problem'] === null;
            return $shot;
        };

        return new ScreenshotComparisonView($inspect($byId[$before]), $inspect($byId[$after]), $assessment);
    }
}
