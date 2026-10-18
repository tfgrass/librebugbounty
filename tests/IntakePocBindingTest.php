<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingProblemService;
use App\Service\IntakeStatusService;

/** Current badges must not inherit proof or errors from a previous PoC. */
final class IntakePocBindingTest extends DatabaseTestCase
{
    private const ORIGINAL = 'https://intake-poc.test/original';
    private const ACTIVE = 'https://intake-poc.test/selected';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

    public function testLatestBadgeUsesCurrentPocDespiteLaterHistoricalAndInvalidatedResults(): void
    {
        $finding = $this->finding()->setPocUrl(self::ACTIVE, 1);
        $current = $this->observation($finding, 'still_vulnerable', 1, self::ACTIVE, '10:00:00');
        $this->observation($finding, 'fixed', 0, self::ORIGINAL, '10:01:00', ['stalePoc' => true]);
        $this->observation($finding, 'error', 1, self::ACTIVE, '10:02:00', ['staleFindingContext' => true]);
        $this->entityManager->flush();
        $before = $this->snapshot();

        $status = self::getContainer()->get(IntakeStatusService::class)->status($finding);

        self::assertSame($current->getId(), $status['observation']['id']);
        self::assertSame('still_vulnerable', $status['observation']['result']);
        self::assertSame(self::ORIGINAL, $status['url']);
        self::assertSame($before, $this->snapshot());
    }

    public function testChangedPocHasNoCurrentStatusUntilItsOwnObservationAndScreenshotExist(): void
    {
        $finding = $this->finding();
        $this->observation($finding, 'fixed', 0, self::ORIGINAL, '10:00:00');
        $job = $this->job($finding, self::ORIGINAL, 'failed', '10:00:00');
        $finding->setPocUrl(self::ACTIVE, 1);
        $this->entityManager->flush();
        $before = $this->snapshot();

        $status = self::getContainer()->get(IntakeStatusService::class)->status($finding);

        self::assertNull($status['observation']);
        self::assertSame('none', $status['screenshot']['state']);
        self::assertNull($status['screenshot']['requestedAt']);
        self::assertNull($status['screenshot']['error']);
        self::assertSame('failed', $job->getStatus());
        self::assertSame($before, $this->snapshot());
    }

    public function testInteractiveImageCanProveSelectedPocWithoutRewritingItsSourceUrl(): void
    {
        $finding = $this->finding()->setPocUrl(self::ACTIVE, 1);
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)
            ->storeContents($finding, base64_decode(self::PNG, true), 'dialog.png');
        $saved = $this->job($finding, self::ORIGINAL, 'available', '10:00:00')
            ->setCaptureMetadata(['pocUrl' => self::ACTIVE, 'queuedPocRevision' => 1, 'finalUrl' => 'https://intake-poc.test/dialog'])
            ->setScreenshotPath($stored->relativePath)->setCapturedAt(new \DateTimeImmutable('2026-10-18 10:00:00'));
        $this->job($finding, self::ORIGINAL, 'failed', '10:01:00');
        $this->entityManager->flush();

        $status = self::getContainer()->get(IntakeStatusService::class)->status($finding);

        self::assertSame('available', $status['screenshot']['state']);
        self::assertSame($saved->getCapturedAt()->format(DATE_ATOM), $status['screenshot']['capturedAt']);
        self::assertSame(self::ORIGINAL, $saved->getUrl());
        self::assertSame(['screenshot' => 0, 'technical' => 0], self::getContainer()->get(FindingProblemService::class)->currentCounts());
    }

    public function testReturningToOriginalUrlDoesNotReviveItsOldRevision(): void
    {
        $finding = $this->finding();
        $this->observation($finding, 'error', 0, self::ORIGINAL, '10:00:00');
        $this->job($finding, self::ORIGINAL, 'failed', '10:00:00');
        $finding->setPocUrl(self::ORIGINAL, 2);
        $this->entityManager->flush();

        $status = self::getContainer()->get(IntakeStatusService::class)->status($finding);

        self::assertNull($status['observation']);
        self::assertSame('none', $status['screenshot']['state']);
        self::assertSame(['screenshot' => 0, 'technical' => 0], self::getContainer()->get(FindingProblemService::class)->currentCounts());
    }

    public function testProblemInboxUsesCurrentPocAndRetainsHistoricalMissingFileAudit(): void
    {
        $finding = $this->finding()->setPocUrl(self::ACTIVE, 1);
        $this->observation($finding, 'error', 0, self::ORIGINAL, '10:02:00');
        $this->observation($finding, 'error', 1, self::ACTIVE, '10:01:00', ['staleFindingContext' => true]);
        $this->observation($finding, 'still_vulnerable', 1, self::ACTIVE, '10:00:00');
        $this->job($finding, self::ORIGINAL, 'failed', '10:02:00');
        $this->job($finding, self::ACTIVE, 'queued', '10:00:00')->setCaptureMetadata(['queuedPocRevision' => 1]);
        $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath('old-poc-missing.png');
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();
        $service = self::getContainer()->get(FindingProblemService::class);
        $before = $this->snapshot();

        self::assertSame(['screenshot' => 0, 'technical' => 0], $service->currentCounts());
        $view = $service->get(['q' => '/selected']);
        self::assertSame(1, $view['total']);
        self::assertSame(self::ACTIVE, $view['rows'][0]['url']);
        self::assertSame(['missing'], array_keys($view['rows'][0]['problems']));
        self::assertSame($evidence->getId(), $view['rows'][0]['problems']['missing']['evidenceId']);
        self::assertSame(1, $service->get(['q' => '/original'])['total']);
        self::assertSame($before, $this->snapshot());
    }

    private function finding(): Finding
    {
        $domain = (new Domain())->setHostname('intake-poc.test')->setScheme('https');
        $finding = (new Finding())->setDomain($domain)->setTitle('PoC badge fixture')->setType('xss')->setUrl(self::ORIGINAL);
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        return $finding;
    }

    private function observation(Finding $finding, string $result, int $revision, string $url, string $time, array $flags = []): RetestRun
    {
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult($result)
            ->setStartedAt(new \DateTimeImmutable('2026-10-18 '.$time))->setFinishedAt(new \DateTimeImmutable('2026-10-18 '.$time))
            ->setRawResult(['pocUrl' => $url, 'pocRevision' => $revision] + $flags);
        $this->entityManager->persist($run);
        return $run;
    }

    private function job(Finding $finding, string $url, string $state, string $time): ScreenshotJob
    {
        $job = (new ScreenshotJob())->setFinding($finding)->setUrl($url)->setStatus($state)
            ->setRequestedAt(new \DateTimeImmutable('2026-10-18 '.$time));
        $this->entityManager->persist($job);
        return $job;
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['finding', 'retest_run', 'screenshot_job', 'evidence'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }
        return $snapshot;
    }
}
