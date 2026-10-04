<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\SettingsService;
use App\Service\StudioExportProfileService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StudioExportPreferencesTest extends DatabaseTestCase
{
    private SettingsService $settings;
    private StudioExportProfileService $profiles;

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
        $this->settings = self::getContainer()->get(SettingsService::class);
        $this->profiles = self::getContainer()->get(StudioExportProfileService::class);
    }

    public function testFirstExportEditorUsesReportWithLatestImageAndNotesOff(): void
    {
        $this->fixture();
        $view = $this->profiles->get([]);
        self::assertSame('report', $view->profile);
        self::assertSame('latest', $view->screenshotMode);
        self::assertSame('zip', $view->downloadFormat);
        self::assertSame(1, $view->screenshotCount);
        self::assertFalse($view->includePrivateNotes);
        $query = $this->query($view->downloadPath);
        self::assertSame('report', $query['profile']);
        self::assertSame('latest', $query['screenshots']);
        self::assertSame('0', $query['include_notes']);
        $response = $this->request('/export');
        self::assertSame(200, $response->getStatusCode());
        $xpath = $this->xpath($response);
        self::assertSame('report', $xpath->evaluate('string(//form[@id="export-filters"]/input[@name="profile"]/@value)'));
        self::assertSame('latest', $xpath->evaluate('string(//select[@name="screenshots"]/option[@selected]/@value)'));
        self::assertSame(0.0, $xpath->evaluate('count(//input[@name="include_notes"][@type="checkbox"][@checked])'));
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM setting'));
    }

    public function testEachSavedProfileInitializesEditorAndJsonDoesNotInheritImages(): void
    {
        $this->fixture();
        foreach (['urls', 'state', 'report'] as $profile) {
            $this->preferences($profile, 'all');
            $view = $this->profiles->get([]);
            self::assertSame($profile, $view->profile);
            self::assertSame($profile === 'report' ? 'all' : 'none', $view->screenshotMode);
            self::assertSame($profile === 'report' ? 'zip' : 'json', $view->downloadFormat);
            self::assertSame($profile, $this->query($view->downloadPath)['profile']);
            self::assertFalse($view->includePrivateNotes);
            self::assertSame(200, $this->request('/export')->getStatusCode());
            $options = $this->profiles->parse($this->query($view->downloadPath));
            self::assertSame($profile, $options->profile);
            self::assertSame($view->screenshotMode, $options->screenshotMode);
        }
    }

    public function testEachSavedImageSelectionControlsPreviewAndDownloadedArchive(): void
    {
        [$basis, $latest] = $this->fixture();
        foreach (['basis' => 1, 'latest' => 1, 'all' => 2, 'none' => 0] as $mode => $count) {
            $this->preferences('report', $mode);
            $view = $this->profiles->get([]);
            self::assertSame($mode, $view->screenshotMode);
            self::assertSame($count, $view->screenshotCount);
            $manifest = $this->manifest($this->request($view->downloadPath));
            self::assertSame($mode, $manifest['options']['screenshots']);
            $images = $manifest['findings'][0]['screenshots'];
            self::assertCount($count, $images);
            if ($mode === 'basis' || $mode === 'latest') {
                self::assertSame(($mode === 'basis' ? $basis : $latest)->getSha256(), $images[0]['actualSha256']);
            }
            self::assertArrayNotHasKey('privateNotes', $manifest['findings'][0]);
        }
    }

    public function testExplicitEditorChoicesOverridePreferencesWithoutSavingThem(): void
    {
        $this->fixture();
        $this->preferences('urls', 'none');
        $before = $this->storedPreferences();
        $view = $this->profiles->get([
            'profile' => ' report ', 'screenshots' => ' all ', 'include_request_data' => '0',
            'include_assessment' => '0', 'include_contact' => '1', 'include_notes' => '1',
        ]);
        self::assertSame('report', $view->profile);
        self::assertSame('all', $view->screenshotMode);
        self::assertFalse($view->includeRequestData);
        self::assertFalse($view->includeAssessment);
        self::assertTrue($view->includeContact);
        self::assertTrue($view->includePrivateNotes);
        $manifest = $this->manifest($this->request($view->downloadPath));
        self::assertSame('private fixture note', $manifest['findings'][0]['privateNotes']);
        self::assertSame($before, $this->storedPreferences());
        self::assertFalse($this->profiles->get([])->includePrivateNotes);
    }

    public function testInvalidExplicitChoicesFailInsteadOfUsingSavedPreferences(): void
    {
        $this->preferences('report', 'latest');
        $before = $this->storedPreferences();
        foreach ([
            'profile=invalid', 'profile=', 'profile%5B%5D=report',
            'profile=report&screenshots=invalid', 'profile=report&screenshots=',
            'profile=report&screenshots%5B%5D=latest', 'profile=state&screenshots=all',
            'include_notes=maybe', 'include_notes%5B%5D=1',
        ] as $query) {
            foreach (['/export', '/export/download'] as $path) {
                $response = $this->request($path.'?'.$query);
                self::assertSame(400, $response->getStatusCode(), $path.'?'.$query);
                self::assertFalse($response->headers->has('Content-Disposition'));
            }
        }
        self::assertSame($before, $this->storedPreferences());
    }

    public function testDirectDownloadDefaultsKeepStateV1AndAssessmentBasis(): void
    {
        [$basis] = $this->fixture();
        $this->preferences('report', 'all');
        $options = $this->profiles->parse([]);
        self::assertSame('state', $options->profile);
        self::assertSame('none', $options->screenshotMode);
        $response = $this->request('/export/download');
        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        try {
            $response->sendContent();
            $state = json_decode(ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
        self::assertSame(1, $state['schemaVersion']);
        self::assertFalse($state['includePrivateNotes']);
        self::assertArrayNotHasKey('privateNotes', $state['findings'][0]);
        $manifest = $this->manifest($this->request('/export/download?profile=report'));
        self::assertSame('basis', $manifest['options']['screenshots']);
        self::assertCount(1, $manifest['findings'][0]['screenshots']);
        self::assertSame($basis->getSha256(), $manifest['findings'][0]['screenshots'][0]['actualSha256']);
    }

    public function testRenderedProfileLinksWorkWithoutScriptsAndResetPrivateNotes(): void
    {
        $this->fixture();
        $this->preferences('state', 'all');
        $response = $this->request('/export?profile=state&domain=export-preference.invalid&include_notes=1');
        self::assertSame(200, $response->getStatusCode());
        $xpath = $this->xpath($response);
        $reportPath = $xpath->evaluate('string(//a[@data-export-profile="report"]/@href)');
        self::assertSame('report', $this->query($reportPath)['profile']);
        self::assertSame('export-preference.invalid', $this->query($reportPath)['domain']);
        self::assertArrayNotHasKey('include_notes', $this->query($reportPath));
        $reportResponse = $this->request($reportPath);
        self::assertSame(200, $reportResponse->getStatusCode());
        $reportXPath = $this->xpath($reportResponse);
        self::assertSame('all', $reportXPath->evaluate('string(//select[@name="screenshots"]/option[@selected]/@value)'));
        self::assertSame(0.0, $reportXPath->evaluate('count(//input[@name="include_notes"][@type="checkbox"][@checked])'));
        self::assertSame('/export', $reportXPath->evaluate('string(//form[@id="export-filters"]/@action)'));
        self::assertSame('get', $reportXPath->evaluate('string(//form[@id="export-filters"]/@method)'));
        self::assertSame('/export/download', $reportXPath->evaluate('string(//button[@data-export-download]/@formaction)'));
        $changedResponse = $this->request('/export?profile=report&domain=export-preference.invalid&screenshots=none&include_notes=0');
        self::assertSame(200, $changedResponse->getStatusCode());
        self::assertSame('none', $this->xpath($changedResponse)->evaluate('string(//select[@name="screenshots"]/option[@selected]/@value)'));
        self::assertSame('state', $this->profiles->get([])->profile);
    }

    public function testPreparedDownloadKeepsEffectiveChoicesAfterPreferencesChange(): void
    {
        [, $latest] = $this->fixture();
        $this->preferences('report', 'latest');
        $view = $this->profiles->get([]);
        $this->preferences('urls', 'none');
        self::assertSame('urls', $this->profiles->get([])->profile);
        $manifest = $this->manifest($this->request($view->downloadPath));
        self::assertSame('report', $manifest['options']['profile']);
        self::assertSame('latest', $manifest['options']['screenshots']);
        self::assertSame('0', $manifest['options']['include_notes']);
        self::assertSame($latest->getSha256(), $manifest['findings'][0]['screenshots'][0]['actualSha256']);
        self::assertSame('urls', $this->settings->getExportProfile());
        self::assertSame('none', $this->settings->getExportScreenshotMode());
    }

    /** @return array{Evidence, Evidence} */
    private function fixture(): array
    {
        $domain = (new Domain())->setHostname('export-preference.invalid')->setScheme('https');
        $finding = (new Finding())->setDomain($domain)->setTitle('Saved export fixture')->setType('stored-case')
            ->setUrl('https://export-preference.invalid/stored')->setPrivateNotes('private fixture note');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $images = [];
        foreach (['basis', 'latest'] as $name) {
            $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
            $bytes = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0))
                .$chunk('IDAT', gzcompress("\x00".substr(hash('sha256', $name, true), 0, 3)."\xff")).$chunk('IEND', '');
            $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, $bytes, $name.'.png');
            $image = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($stored->relativePath)->setSha256($stored->sha256);
            $this->entityManager->persist($image);
            $images[] = $image;
        }
        $at = new \DateTimeImmutable('2026-10-01 12:00:00');
        $finding->setManualAssessment('confirmed', null, $at);
        $this->entityManager->persist(new FindingAssessment($finding, 'confirmed', null, $at, evidenceId: $images[0]->getId()));
        $this->entityManager->flush();
        foreach ($images as $index => $image) {
            $this->entityManager->getConnection()->executeStatement('UPDATE evidence SET created_at = ? WHERE id = ?', [
                $index === 0 ? '2026-10-01 12:00:00' : '2026-10-02 12:00:00', $image->getId(),
            ]);
        }

        return $images;
    }

    private function preferences(string $profile, string $screenshots): void
    {
        $this->settings->save(['export.default_profile' => $profile, 'export.screenshot_mode' => $screenshots]);
    }

    private function storedPreferences(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM setting ORDER BY id');
    }

    private function request(string $path): Response
    {
        $request = Request::create($path);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function query(string $path): array
    {
        parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $query);

        return $query;
    }

    private function xpath(Response $response): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());

        return new \DOMXPath($document);
    }

    private function manifest(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        $path = $response->getFile()->getPathname();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        try {
            return json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $zip->close();
            unlink($path);
        }
    }
}
