<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\ScreenshotJob;
use App\Service\EvidenceStorageInterface;
use App\Service\StudioExportProfileService;

final class StudioExportSafetyTest extends DatabaseTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

    private EvidenceStorageInterface $storage;

    /** @var array<string, Domain> */
    private array $domains = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = self::getContainer()->get(EvidenceStorageInterface::class);
    }

    public function testSelectedFindingCannotExportBytesFromAnUnselectedFindingsStoredPath(): void
    {
        $selected = $this->finding('selected-a', 'selected.test');
        $unselected = $this->finding('unselected-b', 'unselected.test');
        $unselectedBytes = $this->png()."\nUNFILTERED-B-SECRET";
        $stored = $this->storage->storeContents($unselected, $unselectedBytes, 'unselected-b.png');
        $this->evidence($unselected, 'screenshot', $stored->relativePath, $stored->sha256);
        $this->evidence($selected, 'screenshot', $stored->relativePath, $stored->sha256);
        $this->entityManager->flush();

        $archive = $this->report([
            'screenshots' => 'all',
            'domain' => 'selected.test',
            'exact_domain' => '1',
        ]);

        self::assertSame(1, $archive['manifest']['findingCount']);
        self::assertSame('selected-a', $archive['manifest']['findings'][0]['title']);
        self::assertSame('case-0001', $archive['manifest']['findings'][0]['packageId']);
        $image = $archive['manifest']['findings'][0]['screenshots'][0];
        self::assertSame('image-001', $image['id']);
        self::assertFalse($image['available']);
        self::assertSame('invalid_stored_path', $image['missingReason']);
        self::assertNull($image['sha256']);
        self::assertArrayNotHasKey('archivePath', $image);
        self::assertStringNotContainsString('UNFILTERED-B-SECRET', implode('', $archive['entries']));
        self::assertSame([], $this->screenshotEntries($archive['entries']));

        $filter = ['domain' => 'selected.test', 'exact_domain' => '1'];
        $stateV1 = $this->jsonExport($filter);
        self::assertSame(1, $stateV1['schemaVersion']);
        self::assertSame([$selected->getId()], array_column($stateV1['findings'], 'id'));
        self::assertNull($stateV1['findings'][0]['evidence'][0]['artifactUrl']);

        $stateV2 = $this->jsonExport($filter + [
            'profile' => 'state',
            'include_request_data' => '0',
            'include_assessment' => '0',
            'include_contact' => '0',
        ]);
        self::assertSame(2, $stateV2['schemaVersion']);
        self::assertSame([$selected->getId()], array_column($stateV2['findings'], 'id'));
        self::assertNull($stateV2['findings'][0]['evidence'][0]['artifactUrl']);
    }

    public function testStoredHashMismatchIsManifestedAndTheFileIsAbsentFromTheZip(): void
    {
        $finding = $this->finding('hash-mismatch');
        $bytes = $this->png()."\nHASH-MISMATCH-CONTENTS";
        $stored = $this->storage->storeContents($finding, $bytes, 'changed.png');
        $this->evidence($finding, 'screenshot', $stored->relativePath, str_repeat('0', 64));
        $this->entityManager->flush();

        $archive = $this->report(['screenshots' => 'all']);
        $image = $archive['manifest']['findings'][0]['screenshots'][0];

        self::assertSame('image-001', $image['id']);
        self::assertFalse($image['available']);
        self::assertSame('hash_mismatch', $image['missingReason']);
        self::assertSame(hash('sha256', $bytes), $image['actualSha256']);
        self::assertFalse($image['integrityMatchesStoredHash']);
        self::assertArrayNotHasKey('archivePath', $image);
        self::assertStringNotContainsString('HASH-MISMATCH-CONTENTS', implode('', $archive['entries']));
        self::assertSame([], $this->screenshotEntries($archive['entries']));
    }

    public function testPlainTextAndHtmlMarkedAsScreenshotsAreRejectedDespiteMatchingHashes(): void
    {
        $fixtures = [
            'plain text' => "plain text masquerading as an image\nPLAIN-TEXT-SECRET",
            'html' => '<!doctype html><title>HTML-SCREENSHOT-SECRET</title>',
        ];
        $expected = [];
        foreach ($fixtures as $label => $bytes) {
            $finding = $this->finding($label, str_replace(' ', '-', $label).'.test');
            $stored = $this->storage->storeContents($finding, $bytes, $label.'.png');
            $this->evidence($finding, 'screenshot', $stored->relativePath, $stored->sha256);
            $expected[$label] = $bytes;
        }
        $this->entityManager->flush();

        $archive = $this->report(['screenshots' => 'all']);
        $records = array_column($archive['manifest']['findings'], null, 'title');
        foreach ($expected as $label => $bytes) {
            $image = $records[$label]['screenshots'][0];
            self::assertSame('image-001', $image['id']);
            self::assertFalse($image['available']);
            self::assertSame('not_supported_image', $image['missingReason']);
            self::assertSame(hash('sha256', $bytes), $image['actualSha256']);
            self::assertTrue($image['integrityMatchesStoredHash']);
            self::assertNull($image['mimeType']);
            self::assertArrayNotHasKey('archivePath', $image);
        }
        self::assertStringNotContainsString('PLAIN-TEXT-SECRET', implode('', $archive['entries']));
        self::assertStringNotContainsString('HTML-SCREENSHOT-SECRET', implode('', $archive['entries']));
        self::assertSame([], $this->screenshotEntries($archive['entries']));
    }

    public function testInvalidAssessmentBasisDistinguishesWrongKindFromMissingEvidence(): void
    {
        $wrongKindFinding = $this->finding('basis-wrong-kind', 'wrong-kind.test');
        $wrongKindAt = new \DateTimeImmutable('2026-09-01 09:00:00');
        $wrongKindFinding->setManualAssessment('confirmed', null, $wrongKindAt);
        $stored = $this->storage->storeContents($wrongKindFinding, 'recorded note', 'basis.txt');
        $note = $this->evidence($wrongKindFinding, 'note', $stored->relativePath, $stored->sha256);
        $this->entityManager->persist(new FindingAssessment(
            $wrongKindFinding,
            'confirmed',
            null,
            $wrongKindAt,
            evidenceId: $note->getId(),
        ));

        $missingFinding = $this->finding('basis-missing', 'missing.test');
        $missingAt = new \DateTimeImmutable('2026-09-02 09:00:00');
        $missingId = '00000000-0000-4000-8000-000000000404';
        $missingFinding->setManualAssessment('fixed', null, $missingAt);
        $this->entityManager->persist(new FindingAssessment(
            $missingFinding,
            'fixed',
            null,
            $missingAt,
            evidenceId: $missingId,
        ));
        $this->entityManager->flush();

        $archive = $this->report(['screenshots' => 'basis']);
        $records = array_column($archive['manifest']['findings'], null, 'title');

        $wrongKind = $records['basis-wrong-kind'];
        self::assertFalse($wrongKind['hasKnownAssessmentImageBasis']);
        self::assertSame('image-001', $wrongKind['screenshots'][0]['id']);
        self::assertSame('assessment', $wrongKind['screenshots'][0]['basisSource']);
        self::assertSame('basis_not_image', $wrongKind['screenshots'][0]['missingReason']);

        $missing = $records['basis-missing'];
        self::assertFalse($missing['hasKnownAssessmentImageBasis']);
        self::assertSame('image-001', $missing['screenshots'][0]['id']);
        self::assertSame('assessment', $missing['screenshots'][0]['basisSource']);
        self::assertSame('basis_evidence_missing', $missing['screenshots'][0]['missingReason']);
        self::assertSame([], $this->screenshotEntries($archive['entries']));
    }

    public function testCaptureMetadataForTheSamePathIsScopedToItsFinding(): void
    {
        $first = $this->finding('capture-first', 'first.test');
        $second = $this->finding('capture-second', 'second.test');
        $sharedPath = 'storage/artifacts/shared/capture.png';
        $this->evidence($first, 'screenshot', $sharedPath, null);
        $this->evidence($second, 'screenshot', $sharedPath, null);
        $firstCapturedAt = new \DateTimeImmutable('2026-09-03 09:00:00');
        $secondCapturedAt = new \DateTimeImmutable('2026-09-04 10:00:00');
        $this->screenshotJob($first, $sharedPath, $firstCapturedAt);
        $this->screenshotJob($second, $sharedPath, $secondCapturedAt);
        $this->entityManager->flush();

        $archive = $this->report(['screenshots' => 'all']);
        $records = array_column($archive['manifest']['findings'], null, 'title');

        self::assertSame($firstCapturedAt->format(DATE_ATOM), $records['capture-first']['screenshots'][0]['capturedAt']);
        self::assertSame('screenshot_job', $records['capture-first']['screenshots'][0]['captureSource']);
        self::assertSame($secondCapturedAt->format(DATE_ATOM), $records['capture-second']['screenshots'][0]['capturedAt']);
        self::assertSame('screenshot_job', $records['capture-second']['screenshots'][0]['captureSource']);
        self::assertNotSame(
            $records['capture-first']['screenshots'][0]['capturedAt'],
            $records['capture-second']['screenshots'][0]['capturedAt'],
        );
    }

    public function testAssessmentBasisReportWorksWithoutReviewAcknowledgementTable(): void
    {
        $finding = $this->finding('assessment-fallback');
        $assessedAt = new \DateTimeImmutable('2026-09-05 11:00:00');
        $finding->setManualAssessment('confirmed', null, $assessedAt);
        $bytes = $this->png();
        $stored = $this->storage->storeContents($finding, $bytes, 'assessment.png');
        $evidence = $this->evidence($finding, 'screenshot', $stored->relativePath, $stored->sha256);
        $this->entityManager->persist(new FindingAssessment(
            $finding,
            'confirmed',
            null,
            $assessedAt,
            evidenceId: $evidence->getId(),
        ));
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        $schema = $connection->fetchAllAssociative(
            "SELECT type, sql FROM sqlite_master WHERE tbl_name = 'finding_review_acknowledgement' "
            ."AND sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name",
        );
        self::assertNotEmpty($schema);
        $connection->executeStatement('DROP TABLE finding_review_acknowledgement');

        try {
            self::assertFalse($connection->fetchOne(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'finding_review_acknowledgement'",
            ));
            $archive = $this->report(['screenshots' => 'basis']);
        } finally {
            foreach ($schema as $statement) {
                $connection->executeStatement($statement['sql']);
            }
        }

        $record = $archive['manifest']['findings'][0];
        $image = $record['screenshots'][0];
        self::assertTrue($record['hasKnownAssessmentImageBasis']);
        self::assertSame('image-001', $image['id']);
        self::assertSame('assessment', $image['basisSource']);
        self::assertTrue($image['available']);
        self::assertSame($bytes, $archive['entries'][$image['archivePath']]);
    }

    public function testLatestAndAllDoNotLeakAssessmentProvenanceWhenAssessmentIsExcluded(): void
    {
        $finding = $this->finding('no-assessment-leak');
        $assessedAt = new \DateTimeImmutable('2026-09-06 12:00:00');
        $finding->setManualAssessment('confirmed', null, $assessedAt);
        $stored = $this->storage->storeContents($finding, $this->png(), 'basis.png');
        $evidence = $this->evidence($finding, 'screenshot', $stored->relativePath, $stored->sha256);
        $this->entityManager->persist(new FindingAssessment(
            $finding,
            'confirmed',
            null,
            $assessedAt,
            evidenceId: $evidence->getId(),
        ));
        $this->entityManager->flush();

        foreach (['latest', 'all'] as $mode) {
            $archive = $this->report([
                'screenshots' => $mode,
                'include_assessment' => '0',
            ]);
            $record = $archive['manifest']['findings'][0];
            self::assertArrayNotHasKey('manualAssessment', $record, $mode);
            self::assertArrayNotHasKey('hasKnownAssessmentImageBasis', $record, $mode);
            self::assertArrayNotHasKey('basisSource', $record['screenshots'][0], $mode);
            self::assertTrue($record['screenshots'][0]['available'], $mode);
            self::assertStringNotContainsString('"manualAssessment"', $archive['entries']['manifest.json'], $mode);
            self::assertStringNotContainsString('"hasKnownAssessmentImageBasis"', $archive['entries']['manifest.json'], $mode);
            self::assertStringNotContainsString('"basisSource"', $archive['entries']['manifest.json'], $mode);
            self::assertStringNotContainsString('Manuelle Bewertung:', $archive['entries']['report.md'], $mode);
        }
    }

    private function finding(string $label, string $hostname = 'export.test'): Finding
    {
        if (!isset($this->domains[$hostname])) {
            $this->domains[$hostname] = (new Domain())->setHostname($hostname)->setScheme('https');
            $this->entityManager->persist($this->domains[$hostname]);
        }
        $finding = (new Finding())
            ->setDomain($this->domains[$hostname])
            ->setTitle($label)
            ->setType('stored-case')
            ->setSeverity('medium')
            ->setUrl('https://'.$hostname.'/case/'.rawurlencode($label));
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function evidence(Finding $finding, string $kind, ?string $path, ?string $sha256): Evidence
    {
        $evidence = (new Evidence())
            ->setFinding($finding)
            ->setKind($kind)
            ->setFilePath($path)
            ->setSha256($sha256);
        $this->entityManager->persist($evidence);

        return $evidence;
    }

    private function screenshotJob(Finding $finding, string $path, \DateTimeImmutable $capturedAt): ScreenshotJob
    {
        $job = (new ScreenshotJob())
            ->setFinding($finding)
            ->setUrl($finding->getUrl())
            ->setStatus('available')
            ->setCapturedAt($capturedAt)
            ->setFinishedAt($capturedAt)
            ->setScreenshotPath($path);
        $this->entityManager->persist($job);

        return $job;
    }

    /** @return array{manifest: array<string, mixed>, entries: array<string, string>} */
    private function report(array $query): array
    {
        $service = self::getContainer()->get(StudioExportProfileService::class);
        $options = $service->parse(['profile' => 'report'] + $query);
        $path = $service->buildReportArchive($options);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true);
        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index);
                $contents = $zip->getFromIndex($index);
                self::assertIsString($name);
                self::assertIsString($contents);
                $entries[$name] = $contents;
            }
        } finally {
            $zip->close();
            @unlink($path);
        }
        self::assertArrayHasKey('manifest.json', $entries);
        self::assertArrayHasKey('report.md', $entries);

        return [
            'manifest' => json_decode($entries['manifest.json'], true, 512, JSON_THROW_ON_ERROR),
            'entries' => $entries,
        ];
    }

    /** @return array<string, mixed> */
    private function jsonExport(array $query): array
    {
        $service = self::getContainer()->get(StudioExportProfileService::class);
        $options = $service->parse($query);
        $json = '';
        $service->writeJson($options, static function (string $part) use (&$json): void {
            $json .= $part;
        });

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $entries @return list<string> */
    private function screenshotEntries(array $entries): array
    {
        return array_values(array_filter(
            array_keys($entries),
            static fn (string $name): bool => str_starts_with($name, 'findings/'),
        ));
    }

    private function png(): string
    {
        $contents = base64_decode(self::PNG, true);
        self::assertIsString($contents);

        return $contents;
    }
}
