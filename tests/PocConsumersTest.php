<?php

namespace App\Tests;

use App\Command\FindingShowCommand;
use App\Dto\BrowserScreenshotRequest;
use App\Dto\BrowserScreenshotResult;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingPocRevision;
use App\Entity\ScreenshotJob;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\ExportService;
use App\Service\ScreenshotQueueService;
use App\Service\ScreenshotOperationLock;
use App\Service\SettingsService;
use App\Service\StudioExportService;
use App\Service\StudioExportProfileService;
use App\Value\ScreenshotJobStatus;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Component\Console\Tester\CommandTester;

final class PocConsumersTest extends DatabaseTestCase
{
    private const ORIGINAL = 'https://poc-consumers.test/start?x=%2527';
    private const ACTIVE = 'https://poc-consumers.test/fixed-path?x=%27';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

    public function testScreenshotQueueUsesPersistedActivePocEvenWithStaleManagedFinding(): void
    {
        $finding = $this->finding();
        $this->changePoc($finding, self::ACTIVE, 1);
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::once())->method('capture')
            ->with(self::callback(static fn (BrowserScreenshotRequest $request): bool => $request->url === self::ACTIVE))
            ->willReturn($this->captureResult('https://poc-consumers.test/redirected'));
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $client);
        $queue = self::getContainer()->get(ScreenshotQueueService::class);

        $job = $queue->enqueue($finding)->job;
        self::assertSame(self::ACTIVE, $job->getUrl());
        self::assertSame(1, $job->getCaptureMetadata()['queuedPocRevision']);
        self::assertSame(ScreenshotJobStatus::AVAILABLE, $queue->processNext()?->getStatus());
        self::assertSame(self::ACTIVE, $job->getCaptureMetadata()['requestedUrl']);
        self::assertSame('https://poc-consumers.test/redirected', $job->getCaptureMetadata()['sourceUrl']);
        self::assertSame('https://poc-consumers.test/redirected', $job->getCaptureMetadata()['finalUrl']);
        self::assertSame(self::ORIGINAL, $finding->getUrl());
        self::assertSame(self::ACTIVE, $this->entityManager->getConnection()->fetchOne('SELECT poc_url FROM finding WHERE id = ?', [$finding->getId()]));
    }

    public function testEnqueueReleasesOldActiveJobWithoutRelabelingItsSource(): void
    {
        $finding = $this->finding();
        $queue = self::getContainer()->get(ScreenshotQueueService::class);
        $old = $queue->enqueue($finding)->job;
        $this->changePoc($finding, self::ACTIVE, 1);

        $replacement = $queue->enqueue($finding);

        self::assertTrue($replacement->created);
        self::assertSame(self::ACTIVE, $replacement->job->getUrl());
        self::assertNotSame($old->getId(), $replacement->job->getId());
        self::assertSame(self::ORIGINAL, $old->getUrl());
        self::assertSame(ScreenshotJobStatus::FAILED, $old->getStatus());
        self::assertNull($old->getActiveKey());
        self::assertStringContainsString('PoC changed', $old->getErrorMessage());
    }

    public function testOldQueuedRequestIsRejectedBeforeContactingTargetEvenAfterUrlReturnsToOriginal(): void
    {
        $finding = $this->finding();
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::never())->method('capture');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $client);
        $queue = self::getContainer()->get(ScreenshotQueueService::class);
        $old = $queue->enqueue($finding)->job;
        // Matching URL alone is insufficient after an intervening revision.
        $this->changePoc($finding, self::ORIGINAL, 2);

        $failed = $queue->processNext();

        self::assertSame($old->getId(), $failed?->getId());
        self::assertSame(ScreenshotJobStatus::FAILED, $failed?->getStatus());
        self::assertSame(0, $this->entityManager->getRepository(Evidence::class)->count([]));
    }

    public function testPocChangedDuringCaptureCannotCreateCurrentEvidence(): void
    {
        $finding = $this->finding();
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::once())->method('capture')->willReturnCallback(function () use ($finding): BrowserScreenshotResult {
            $this->changePoc($finding, self::ACTIVE, 1);
            return $this->captureResult();
        });
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $client);
        $queue = self::getContainer()->get(ScreenshotQueueService::class);
        $queue->enqueue($finding);

        $failed = $queue->processNext();

        self::assertSame(ScreenshotJobStatus::FAILED, $failed?->getStatus());
        self::assertSame(self::ORIGINAL, $failed?->getUrl());
        self::assertSame(0, $this->entityManager->getRepository(Evidence::class)->count([]));
        self::assertSame([], self::getContainer()->get(EvidenceStorageInterface::class)->listPaths());
    }

    public function testPocChangedAfterFileStorageRemovesUncommittedImage(): void
    {
        $finding = $this->finding();
        $realStorage = self::getContainer()->get(EvidenceStorageInterface::class);
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->expects(self::once())->method('storeContents')->willReturnCallback(function (Finding $owner, string $binary, string $filename) use ($finding, $realStorage) {
            $stored = $realStorage->storeContents($owner, $binary, $filename);
            $this->changePoc($finding, self::ACTIVE, 1);
            return $stored;
        });
        $storage->expects(self::once())->method('deleteFile')->willReturnCallback($realStorage->deleteFile(...));
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::once())->method('capture')->willReturn($this->captureResult());
        $queue = new ScreenshotQueueService(
            self::getContainer()->get(ManagerRegistry::class),
            $client,
            $storage,
            self::getContainer()->get(SettingsService::class),
            self::getContainer()->get(ScreenshotOperationLock::class),
        );
        $queue->enqueue($finding);

        self::assertSame(ScreenshotJobStatus::FAILED, $queue->processNext()?->getStatus());
        self::assertSame(0, $this->entityManager->getRepository(Evidence::class)->count([]));
        self::assertSame([], $realStorage->listPaths());
    }

    public function testEvidenceCommitReservesWriterBeforeAnotherConnectionCanChangePoc(): void
    {
        $finding = $this->finding();
        $checked = false;
        $attemptUpdate = function () use ($finding, &$checked): void {
            $other = new \PDO('sqlite:'.APP_TEST_ROOT.'/database.sqlite');
            $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $other->exec('PRAGMA busy_timeout = 10');
            $other->beginTransaction();
            try {
                $statement = $other->prepare('UPDATE finding SET poc_url = ?, poc_revision = 1 WHERE id = ?');
                $statement->execute([self::ACTIVE, $finding->getId()]);
                self::fail('Another writer changed the PoC after its final check.');
            } catch (\PDOException $error) {
                self::assertStringContainsString('locked', $error->getMessage());
                $checked = true;
            } finally {
                $other->rollBack();
            }
        };
        $listener = new class($attemptUpdate) {
            public function __construct(private readonly \Closure $attemptUpdate) {}
            public function prePersist(LifecycleEventArgs $event): void
            {
                if ($event->getObject() instanceof Evidence) {
                    ($this->attemptUpdate)();
                }
            }
        };
        $this->entityManager->getEventManager()->addEventListener([Events::prePersist], $listener);
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::once())->method('capture')->willReturn($this->captureResult());
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $client);
        $queue = self::getContainer()->get(ScreenshotQueueService::class);
        $queue->enqueue($finding);

        self::assertSame(ScreenshotJobStatus::AVAILABLE, $queue->processNext()?->getStatus());
        self::assertTrue($checked);
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT poc_revision FROM finding WHERE id = ?', [$finding->getId()]));
    }

    public function testExportsAndCliExposeActivePocAlongsideOriginalAndHistory(): void
    {
        $finding = $this->finding();
        $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($finding->getId().'/source.png')->setSha256(str_repeat('a', 64));
        $job = (new ScreenshotJob())->setFinding($finding)->setUrl(self::ORIGINAL)->setScreenshotPath($evidence->getFilePath())
            ->setCapturedAt(new \DateTimeImmutable('2026-10-18 10:00:00'))->setCaptureMetadata(['finalUrl' => 'https://poc-consumers.test/dialog-source']);
        $history = new FindingPocRevision($finding, 1, self::ORIGINAL, self::ACTIVE, 'manual', $evidence, $job);
        foreach ([$evidence, $job, $history] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->changePoc($finding, self::ACTIVE, 1);
        $this->entityManager->refresh($finding);

        $export = self::getContainer()->get(ExportService::class)->export();
        $record = $export['findings'][0];
        self::assertSame(self::ACTIVE, $record['url']);
        self::assertSame(self::ORIGINAL, $record['originalUrl']);
        self::assertSame(1, $record['pocRevision']);
        self::assertSame(self::ORIGINAL, $record['pocHistory'][0]['previousUrl']);
        self::assertSame('https://poc-consumers.test/dialog-source', $record['pocHistory'][0]['sourceUrl']);

        $studioExport = self::getContainer()->get(StudioExportService::class);
        [$filter, $notes] = $studioExport->parse([]);
        $json = '';
        $studioExport->writeDownload($filter, $notes, static function (string $part) use (&$json): void { $json .= $part; });
        $record = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['findings'][0];
        self::assertSame(self::ACTIVE, $record['url']);
        self::assertSame(self::ORIGINAL, $record['originalUrl']);
        self::assertSame(1, $record['pocRevision']);

        $profiles = self::getContainer()->get(StudioExportProfileService::class);
        $json = '';
        $profiles->writeJson($profiles->parse(['profile' => 'urls']), static function (string $part) use (&$json): void { $json .= $part; });
        self::assertSame([['url' => self::ACTIVE, 'type' => 'xss']], json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $archive = $profiles->buildReportArchive($profiles->parse(['profile' => 'report', 'screenshots' => 'none']));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive));
        try {
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR)['findings'][0];
            self::assertSame(self::ACTIVE, $manifest['url']);
            self::assertSame(self::ORIGINAL, $manifest['originalUrl']);
            self::assertSame(1, $manifest['pocRevision']);
            self::assertStringContainsString(self::ACTIVE, $zip->getFromName('report.md'));
            self::assertStringContainsString(self::ORIGINAL, $zip->getFromName('report.md'));
        } finally {
            $zip->close();
            unlink($archive);
        }

        $command = new CommandTester(self::getContainer()->get(FindingShowCommand::class));
        self::assertSame(0, $command->execute(['finding-id' => $finding->getId()]));
        self::assertStringContainsString(self::ACTIVE, $command->getDisplay());
        self::assertStringContainsString(self::ORIGINAL, $command->getDisplay());
        self::assertSame(self::ORIGINAL, $job->getUrl());
    }

    private function finding(): Finding
    {
        $domain = (new Domain())->setHostname('poc-consumers.test')->setScheme('https');
        $finding = (new Finding())->setDomain($domain)->setTitle('PoC consumers')->setType('xss')->setUrl(self::ORIGINAL);
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        return $finding;
    }

    private function changePoc(Finding $finding, string $url, int $revision): void
    {
        $this->entityManager->getConnection()->executeStatement('UPDATE finding SET poc_url = ?, poc_revision = ? WHERE id = ?', [$url, $revision, $finding->getId()]);
    }

    private function captureResult(string $finalUrl = self::ORIGINAL): BrowserScreenshotResult
    {
        return new BrowserScreenshotResult(screenshotBase64: self::PNG, capturedAt: new \DateTimeImmutable('2026-10-18 10:00:00'), finalUrl: $finalUrl);
    }
}
