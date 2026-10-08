<?php

namespace App\Service;

use App\Dto\ScreenshotEnqueueResult;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Value\EvidenceKind;

/** Backfills screenshot evidence without creating duplicate active jobs. */
final class MissingScreenshotQueueService
{
    public function __construct(
        private readonly FindingRepository $findings,
        private readonly EvidenceStorageInterface $storage,
        private readonly ScreenshotEnqueuerInterface $screenshots,
    ) {
    }

    /** @return list<Finding> */
    public function findMissing(?Domain $domain = null, ?string $status = null, int $limit = 1000): array
    {
        $missing = [];
        foreach ($this->findings->findAllForBrowserRetest($domain, $status, PHP_INT_MAX) as $finding) {
            if (!$this->hasReadableScreenshot($finding)) {
                $missing[] = $finding;
                if (count($missing) >= max(1, $limit)) {
                    break;
                }
            }
        }

        return $missing;
    }

    /** @return array{missing: int, queued: int, alreadyActive: int} */
    public function enqueueMissing(?Domain $domain = null, ?string $status = null, int $limit = 1000): array
    {
        $queued = 0;
        $alreadyActive = 0;
        $findings = $this->findMissing($domain, $status, $limit);
        foreach ($findings as $finding) {
            $result = $this->screenshots->enqueue($finding);
            $result->created ? $queued++ : $alreadyActive++;
        }

        return ['missing' => count($findings), 'queued' => $queued, 'alreadyActive' => $alreadyActive];
    }

    public function enqueueOne(Finding $finding): ScreenshotEnqueueResult
    {
        return $this->screenshots->enqueue($finding);
    }

    private function hasReadableScreenshot(Finding $finding): bool
    {
        foreach ($finding->getEvidence() as $evidence) {
            \assert($evidence instanceof Evidence);
            $path = $evidence->getFilePath();
            if ($evidence->getKind() === EvidenceKind::SCREENSHOT
                && $path !== null && $path !== '' && $this->storage->exists($path)) {
                return true;
            }
        }

        return false;
    }
}
