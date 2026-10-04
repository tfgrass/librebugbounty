<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\FindingReviewAcknowledgement;
use App\Entity\RetestRun;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\StudioExportProfileService;
use App\Service\StudioExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StudioExportProfilesTest extends DatabaseTestCase
{
    private array $domains = [];

    protected function setUp(): void
    {
        parent::setUp();
        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);
        $capture = $this->createMock(BrowserScreenshotClientInterface::class);
        $capture->expects(self::never())->method('capture');
        $capture->expects(self::never())->method('waitUntilReady');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $capture);
    }

    public function testUrlListIsOnlyUrlAndTypeAcrossPagesAndRespectsArchiveSelection(): void
    {
        $expected = [];
        for ($i = 0; $i < 103; ++$i) {
            $finding = $this->finding('url-'.$i)->setPrivateNotes('never export this note');
            $expected[] = ['url' => $finding->getUrl(), 'type' => $finding->getType()];
        }
        $archived = $this->finding('archived')->setManualAssessment('discarded', null, new \DateTimeImmutable());
        $this->entityManager->flush();
        $before = $this->snapshot();
        $data = $this->jsonDownload(['profile' => 'urls', 'page' => '2', 'pageSize' => '10']);
        self::assertCount(103, $data);
        self::assertEqualsCanonicalizing($expected, $data);
        foreach ($data as $row) {
            self::assertSame(['url', 'type'], array_keys($row));
        }
        self::assertSame([['url' => $archived->getUrl(), 'type' => $archived->getType()]], $this->jsonDownload(['profile' => 'urls', 'scope' => 'discarded']));
        self::assertSame([], $this->jsonDownload(['profile' => 'urls', 'domain' => 'absent.invalid']));
        self::assertSame($before, $this->snapshot());
    }

    public function testDefaultStatePreservesV1AndConfiguredStateUsesV2AndOmitsGroups(): void
    {
        $finding = $this->finding('state')->setMethod('POST')->setRequestParams(['stored' => 'value'])
            ->setPayload('stored marker')->setExpectedEvidence('stored evidence')->setPrivateNotes('private note')
            ->setContactedAt(new \DateTimeImmutable())->setNotifiedOwnerAt(new \DateTimeImmutable());
        $this->assessment($finding);
        $this->entityManager->flush();
        $legacy = '';
        $service = self::getContainer()->get(StudioExportService::class);
        [$filter, $notes] = $service->parse([]);
        $service->writeDownload($filter, $notes, static function (string $part) use (&$legacy): void { $legacy .= $part; });
        $legacy = json_decode($legacy, true, 512, JSON_THROW_ON_ERROR);
        $default = $this->jsonDownload();
        unset($default['generatedAt'], $legacy['generatedAt']);
        self::assertSame($legacy, $default);
        self::assertSame(1, $default['schemaVersion']);
        self::assertArrayNotHasKey('options', $default);

        $custom = $this->jsonDownload([
            'profile' => 'state', 'include_request_data' => '0', 'include_assessment' => '0',
            'include_contact' => '0', 'include_notes' => '1',
        ]);
        self::assertSame(2, $custom['schemaVersion']);
        self::assertSame('state', $custom['exportProfile']);
        self::assertSame([
            'includeRequestData' => false, 'includeAssessment' => false,
            'includeContact' => false, 'includePrivateNotes' => true,
        ], $custom['options']);
        self::assertSame('private note', $custom['findings'][0]['privateNotes']);
        foreach (['method', 'requestParams', 'payload', 'expectedEvidence', 'manualAssessment', 'latestObservation', 'legacy', 'reportUrl', 'reportedAt', 'contactedAt', 'sentAt'] as $field) {
            self::assertArrayNotHasKey($field, $custom['findings'][0], $field);
        }
        self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
    }

    public function testReportIncludesOnlyTheDocumentedAssessmentScreenshotAndRemainsReadOnly(): void
    {
        $finding = $this->finding('basis')->setPrivateNotes('private note absent by default');
        $basis = $this->image($finding, 'basis.png');
        $this->image($finding, 'newer.png');
        $this->assessment($finding, $basis);
        $this->entityManager->flush();
        $before = $this->snapshot();
        $view = self::getContainer()->get(StudioExportProfileService::class)->get(['profile' => 'report']);
        self::assertSame('basis', $view->screenshotMode);
        self::assertSame(1, $view->screenshotCount);
        self::assertSame(0, $view->missingScreenshotCount);
        self::assertSame(0, $view->unknownBasisCount);
        [$manifest, $report, $entries] = $this->reportDownload();
        self::assertSame(1, $manifest['findingCount']);
        self::assertSame(1, $manifest['domainCount']);
        self::assertSame(['hostname', 'scheme'], array_keys($manifest['domains'][0]));
        self::assertArrayNotHasKey('filters', $manifest);
        $record = $manifest['findings'][0];
        foreach (['id', 'domainId', 'submittedAt', 'createdAt', 'updatedAt', 'discarded'] as $internalField) {
            self::assertArrayNotHasKey($internalField, $record);
        }
        self::assertArrayNotHasKey('privateNotes', $record);
        self::assertArrayNotHasKey('contactedAt', $record);
        self::assertSame('case-0001', $record['packageId']);
        self::assertSame(['image-001'], array_column($record['screenshots'], 'id'));
        $image = $record['screenshots'][0];
        self::assertSame('assessment', $image['basisSource']);
        self::assertTrue($image['available']);
        self::assertSame('image/png', $image['mimeType']);
        self::assertSame(strlen($this->png('basis.png')), $image['byteSize']);
        self::assertSame($basis->getSha256(), $image['actualSha256']);
        self::assertTrue($image['integrityMatchesStoredHash']);
        self::assertSame($this->png('basis.png'), $entries[$image['archivePath']]);
        self::assertCount(3, $entries);
        self::assertStringContainsString('Meldungspaket', $report);
        foreach (['storedPath', 'storage/artifacts/', APP_TEST_ROOT, 'private note absent by default'] as $omitted) {
            self::assertStringNotContainsString($omitted, json_encode($manifest).$report);
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testAcknowledgementBasisOverridesAssessmentButNewestEmptyAcknowledgementRestoresAssessment(): void
    {
        $finding = $this->finding('ack');
        $original = $this->image($finding, 'original.png');
        $reviewed = $this->image($finding, 'reviewed.png');
        $history = $this->assessment($finding, $original);
        $ack = new FindingReviewAcknowledgement($finding, 'history:'.$history->getId(), 'confirmed', [], [], null, $reviewed->getId());
        $this->entityManager->persist($ack);
        $this->entityManager->flush();
        [$manifest] = $this->reportDownload();
        self::assertSame($reviewed->getSha256(), $manifest['findings'][0]['screenshots'][0]['actualSha256']);
        self::assertSame('acknowledgement', $manifest['findings'][0]['screenshots'][0]['basisSource']);

        $newest = new FindingReviewAcknowledgement($finding, 'history:'.$history->getId(), 'confirmed', [], []);
        $this->entityManager->persist($newest);
        $unrelated = new FindingReviewAcknowledgement($finding, 'history:another-decision', 'confirmed', [], [], null, $reviewed->getId());
        $this->entityManager->persist($unrelated);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement('UPDATE finding_review_acknowledgement SET reviewed_at = ? WHERE id = ?', ['2026-10-05 12:00:00', $newest->getId()]);
        $this->entityManager->getConnection()->executeStatement('UPDATE finding_review_acknowledgement SET reviewed_at = ? WHERE id = ?', ['2026-10-06 12:00:00', $unrelated->getId()]);
        [$manifest] = $this->reportDownload();
        self::assertSame($original->getSha256(), $manifest['findings'][0]['screenshots'][0]['actualSha256']);
        self::assertSame('assessment', $manifest['findings'][0]['screenshots'][0]['basisSource']);
    }

    public function testMissingBasisFileIsReportedWithoutFallbackAndNoneIncludesNoImage(): void
    {
        $finding = $this->finding('missing');
        $basis = $this->image($finding, 'missing.png');
        $this->image($finding, 'available.png');
        $this->assessment($finding, $basis);
        $this->entityManager->flush();
        self::getContainer()->get(EvidenceStorageInterface::class)->deleteFile($basis->getFilePath());
        $view = self::getContainer()->get(StudioExportProfileService::class)->get(['profile' => 'report']);
        self::assertSame(0, $view->screenshotCount);
        self::assertSame(1, $view->missingScreenshotCount);
        self::assertSame(0, $view->unknownBasisCount);
        [$manifest, $report, $entries] = $this->reportDownload();
        $image = $manifest['findings'][0]['screenshots'][0];
        self::assertSame('image-001', $image['id']);
        self::assertFalse($image['available']);
        self::assertSame('missing_or_unreadable', $image['missingReason']);
        self::assertArrayNotHasKey('archivePath', $image);
        self::assertCount(2, $entries);
        self::assertStringContainsString('fehlt', $report);
        [$manifest, , $entries] = $this->reportDownload(['screenshots' => 'none']);
        self::assertSame([], $manifest['findings'][0]['screenshots']);
        self::assertCount(2, $entries);
    }

    public function testReportNotesOptInStaysLiteralEvenWithMarkdownFencesAndHtml(): void
    {
        $finding = $this->finding('unsafe title <img src="https://untrusted.invalid/title"> ![x](https://untrusted.invalid/title)');
        $notes = "<img src=\"https://untrusted.invalid/note\">\n```\n![external](https://untrusted.invalid/image)\n``````\n<script>untrusted()</script>";
        $finding->setPrivateNotes($notes)->setPayload("```\n<img src=\"https://untrusted.invalid/payload\">");
        $this->entityManager->flush();
        [$manifest, $report] = $this->reportDownload(['screenshots' => 'none']);
        self::assertArrayNotHasKey('privateNotes', $manifest['findings'][0]);
        self::assertStringNotContainsString('https://untrusted.invalid/note', $report);
        [$manifest, $report] = $this->reportDownload(['screenshots' => 'none', 'include_notes' => '1']);
        self::assertSame($notes, $manifest['findings'][0]['privateNotes']);
        self::assertStringContainsString('```````text', $report);
        $outside = $this->outsideCodeBlocks($report);
        self::assertStringNotContainsString('<img', $outside);
        self::assertStringNotContainsString('<script', $outside);
        self::assertStringNotContainsString('![external]', $outside);
        self::assertStringNotContainsString('https://untrusted.invalid/note', $outside);
        self::assertStringNotContainsString('https://untrusted.invalid/payload', $outside);
    }

    public function testCheapPreviewAndFinalDownloadExposeRejectedBytesAndForeignFindingPaths(): void
    {
        $finding = $this->finding('image validation');
        $valid = $this->image($finding, 'valid.png');
        $mismatch = $this->image($finding, 'wrong-hash.png')->setSha256(str_repeat('0', 64));
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, '<h1>Stored non-image bytes</h1>', 'not-an-image.png');
        $invalid = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($stored->relativePath)->setSha256($stored->sha256);
        $this->entityManager->persist($invalid);
        $foreignFinding = $this->finding('foreign', 'foreign.invalid');
        $foreignImage = $this->image($foreignFinding, 'foreign.png');
        $crossReference = (new Evidence())->setFinding($finding)->setKind('screenshot')
            ->setFilePath($foreignImage->getFilePath())->setSha256($foreignImage->getSha256());
        $this->entityManager->persist($crossReference);
        $this->entityManager->flush();
        $before = $this->snapshot();
        $query = ['profile' => 'report', 'domain' => 'exports.invalid', 'exact_domain' => '1', 'screenshots' => 'all'];
        $view = self::getContainer()->get(StudioExportProfileService::class)->get($query);
        // The GET preview checks safe paths/readability/size without reading
        // every image to validate its format and stored checksum.
        self::assertSame(3, $view->screenshotCount);
        self::assertSame(1, $view->missingScreenshotCount);
        [$manifest, $report, $entries] = $this->reportDownload($query);
        self::assertSame(1, $manifest['findingCount']);
        self::assertCount(4, $manifest['findings'][0]['screenshots']);
        $included = array_values(array_filter($manifest['findings'][0]['screenshots'], static fn (array $image): bool => $image['available']));
        self::assertCount(1, $included);
        self::assertSame($valid->getSha256(), $included[0]['actualSha256']);
        self::assertCount(3, $entries);
        self::assertEqualsCanonicalizing(['hash_mismatch', 'not_supported_image', 'invalid_stored_path'], array_column($manifest['findings'][0]['screenshots'], 'missingReason'));
        self::assertStringNotContainsString($foreignImage->getFilePath(), json_encode($manifest).$report);
        self::assertStringNotContainsString($foreignFinding->getId(), json_encode($manifest).$report);
        self::assertSame($before, $this->snapshot());
    }

    public function testFailedManifestEncodingRemovesTemporaryZipAndClosesReadTransaction(): void
    {
        $finding = $this->finding("Invalid UTF-8: \xb1");
        $this->assessment($finding, $this->image($finding, 'encoding-failure.png'));
        $this->entityManager->flush();
        $before = $this->snapshot();
        $temporaryFiles = glob(sys_get_temp_dir().'/librebugbounty-export-*');
        $service = self::getContainer()->get(StudioExportProfileService::class);
        try {
            $service->buildReportArchive($service->parse(['profile' => 'report']));
            self::fail('Malformed stored UTF-8 must not produce an incomplete report.');
        } catch (\JsonException) {
            self::assertSame($temporaryFiles, glob(sys_get_temp_dir().'/librebugbounty-export-*'));
            self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testChosenObservationAndContactGroupsAppearInManifestAndReadableReport(): void
    {
        $finding = $this->finding('readable detail')->setReportUrl('https://report.invalid/stored-case')
            ->setReportedAt(new \DateTimeImmutable('2026-10-01 10:15:00'))
            ->setContactedAt(new \DateTimeImmutable('2026-10-02 11:25:00'))
            ->setNotifiedOwnerAt(new \DateTimeImmutable('2026-10-03 12:35:00'));
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('error')
            ->setStartedAt(new \DateTimeImmutable('2026-10-04 13:45:00'))
            ->setFinishedAt(new \DateTimeImmutable('2026-10-04 13:45:01'))
            ->setHttpStatus(503)->setFinalUrl('https://final.invalid/stored-case')
            ->setObservedEvidence('Saved observed marker')->setErrorMessage('Saved technical error');
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        [$manifest, $report] = $this->reportDownload(['screenshots' => 'none', 'include_contact' => '1']);
        $record = $manifest['findings'][0];
        self::assertArrayNotHasKey('id', $record['latestObservation']);
        self::assertSame('error', $record['latestObservation']['result']);
        self::assertSame(503, $record['latestObservation']['httpStatus']);
        self::assertSame('Saved observed marker', $record['latestObservation']['observedEvidence']);
        self::assertSame('Saved technical error', $record['latestObservation']['errorMessage']);
        self::assertSame($finding->getContactedAt()->format(DATE_ATOM), $record['contactedAt']);
        self::assertSame($finding->getNotifiedOwnerAt()->format(DATE_ATOM), $record['sentAt']);
        foreach (['Saved observed marker', 'Saved technical error', 'https://final.invalid/stored-case', 'https://report.invalid/stored-case', 'HTTP-Status: 503', 'Als gemeldet erfasst', 'Als kontaktiert erfasst', 'Als versendet erfasst', 'T10:15:00', 'T11:25:00', 'T12:35:00'] as $included) {
            self::assertStringContainsString($included, $report);
        }
        self::assertStringNotContainsString($finding->getId(), json_encode($manifest).$report);
        self::assertStringNotContainsString($run->getId(), json_encode($manifest).$report);
    }

    private function finding(string $title, string $hostname = 'exports.invalid'): Finding
    {
        if (!isset($this->domains[$hostname])) {
            $this->domains[$hostname] = (new Domain())->setHostname($hostname)->setScheme('https');
            $this->entityManager->persist($this->domains[$hostname]);
        }
        $finding = (new Finding())->setDomain($this->domains[$hostname])->setTitle($title)->setType('stored-case')
            ->setSeverity('medium')->setUrl('https://'.$hostname.'/stored?case='.rawurlencode($title));
        $this->entityManager->persist($finding);

        return $finding;
    }

    private function image(Finding $finding, string $name): Evidence
    {
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, $this->png($name), $name);
        $image = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($stored->relativePath)->setSha256($stored->sha256);
        $this->entityManager->persist($image);

        return $image;
    }

    private function assessment(Finding $finding, ?Evidence $basis = null): FindingAssessment
    {
        $date = new \DateTimeImmutable('2026-10-04 10:00:00');
        $finding->setManualAssessment('confirmed', null, $date);
        $history = new FindingAssessment($finding, 'confirmed', null, $date, null, $basis?->getId());
        $this->entityManager->persist($history);

        return $history;
    }

    private function png(string $seed): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0))
            .$chunk('IDAT', gzcompress("\x00".substr(hash('sha256', $seed, true), 0, 3)."\xff"))
            .$chunk('IEND', '');
    }

    private function jsonDownload(array $query = []): array
    {
        $response = $this->request($query);
        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        try {
            $response->sendContent();

            return json_decode(ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
    }

    /** @return array{array, string, array<string, string>} */
    private function reportDownload(array $query = []): array
    {
        $response = $this->request(['profile' => 'report'] + $query);
        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $path = $response->getFile()->getPathname();
        self::assertSame(0600, fileperms($path) & 0777);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                self::assertStringNotContainsString('..', $name);
                self::assertFalse(str_starts_with($name, '/'));
                $entries[$name] = $zip->getFromIndex($i);
            }
            self::assertArrayHasKey('manifest.json', $entries);
            self::assertArrayHasKey('report.md', $entries);

            return [json_decode($entries['manifest.json'], true, 512, JSON_THROW_ON_ERROR), $entries['report.md'], $entries];
        } finally {
            $zip->close();
            $expectedBytes = file_get_contents($path);
            ob_start();
            try {
                $response->sendContent();
                self::assertSame($expectedBytes, ob_get_contents());
            } finally {
                ob_end_clean();
            }
            self::assertFileDoesNotExist($path);
            self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
        }
    }

    private function request(array $query): Response
    {
        $request = Request::create('/export/download?'.http_build_query($query));
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['domain', 'finding', 'finding_assessment', 'finding_review_acknowledgement', 'evidence', 'retest_run', 'screenshot_job', 'setting'] as $table) {
            $result[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        foreach ($storage->listPaths() as $path) {
            $result['files'][$path] = hash('sha256', $storage->read($path));
        }

        return $result;
    }

    private function outsideCodeBlocks(string $markdown): string
    {
        $length = 0;
        $outside = [];
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^(`{3,})(?:text)?$/', $line, $match)) {
                if ($length === 0) {
                    $length = strlen($match[1]);
                } elseif (strlen($match[1]) >= $length) {
                    $length = 0;
                }
            } elseif ($length === 0) {
                $outside[] = $line;
            }
        }
        self::assertSame(0, $length, 'Every report code block must close.');

        return implode("\n", $outside);
    }
}
