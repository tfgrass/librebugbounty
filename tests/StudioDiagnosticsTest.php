<?php

namespace App\Tests;

use App\Entity\Finding;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingDetailService;
use App\Service\FindingProblemService;
use App\Service\ScreenshotComparisonService;
use App\Service\StoredImageInspector;
use App\Service\SettingsService;
use App\Tests\Support\StudioDiagnosticsFixture;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StudioDiagnosticsTest extends DatabaseTestCase
{
    private array $fixture;

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
        $this->fixture = StudioDiagnosticsFixture::seed($this->entityManager, self::getContainer()->get(EvidenceStorageInterface::class));
        $this->entityManager->clear();
    }

    public function testComparisonDefaultsToRecordedBasisAndNewestEvidenceEvenWhenUnavailable(): void
    {
        $before = $this->snapshot();
        $view = self::getContainer()->get(FindingDetailService::class)->get($this->fixture['affected']);
        $comparison = self::getContainer()->get(ScreenshotComparisonService::class)->get($view, []);
        self::assertSame($this->fixture['images']['basis'], $comparison->before['evidence']->getId());
        self::assertSame($this->fixture['images']['invalid'], $comparison->after['evidence']->getId());
        self::assertSame('invalid', $comparison->after['problem']);
        self::assertFalse($comparison->after['available']);
        self::assertSame($this->fixture['images']['basis'], $comparison->basisId());
        $xpath = $this->xpath($this->request('/findings/'.$this->fixture['affected']));
        self::assertSame(2, $xpath->query('//*[@data-comparison-side]')->length);
        self::assertSame(1, $xpath->query('//*[@data-comparison-side="before"]//*[@data-assessment-basis]')->length);
        self::assertSame(0, $xpath->query('//*[@data-comparison-side="after"]//img')->length);
        self::assertSame(1, $xpath->query('//*[@data-comparison-side="after"]//*[@data-comparison-missing and not(@hidden)]')->length);
        self::assertSame($before, $this->snapshot());
    }

    public function testExplicitSelectionIsNativeGetAndDoesNotPreselectAssessmentOrChangeExportBasis(): void
    {
        $before = $this->snapshot();
        $query = http_build_query(['compare_before' => $this->fixture['images']['newer'], 'compare_after' => $this->fixture['images']['latest'], 'return_to' => '/errors?kind=screenshot']);
        $xpath = $this->xpath($this->request('/findings/'.$this->fixture['affected'].'?'.$query));
        self::assertSame('get', $xpath->evaluate('string(//form[@data-comparison-form]/@method)'));
        self::assertSame($this->fixture['images']['newer'], $xpath->evaluate('string(//*[@data-comparison-side="before"]/@data-evidence-id)'));
        self::assertSame($this->fixture['images']['latest'], $xpath->evaluate('string(//*[@data-comparison-side="after"]/@data-evidence-id)'));
        self::assertStringContainsString('Screenshot-Auftrag', $xpath->evaluate('string(//*[@data-comparison-side="before"])'));
        self::assertSame(0, $xpath->query('//form[@id="assessment-form"]//option[@selected and @value!=""]')->length);
        self::assertSame(2, $xpath->query('//*[@data-comparison-side]//img[starts-with(@src, "/artifacts/")]')->length);
        $response = $this->request('/export/download?profile=report&screenshots=basis&format=zip');
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($response->getFile()->getPathname()));
        try {
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $record = array_values(array_filter($manifest['findings'], fn (array $f): bool => $f['url'] === $this->entityManager->find(Finding::class, $this->fixture['affected'])->getUrl()))[0];
            self::assertCount(1, $record['screenshots']);
            $basis = $this->entityManager->find(\App\Entity\Evidence::class, $this->fixture['images']['basis']);
            self::assertSame($basis->getSha256(), $record['screenshots'][0]['actualSha256']);
        } finally {
            $zip->close();
            @unlink($response->getFile()->getPathname());
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testInvalidAndForeignImageSelectionsAreRejectedWithoutWrites(): void
    {
        $before = $this->snapshot();
        foreach ([
            ['compare_before' => ['array']], ['compare_after' => 'https://outside.invalid/image.png'],
            ['compare_before' => $this->fixture['images']['basis'], 'compare_after' => $this->fixture['images']['basis']],
        ] as $query) self::assertSame(400, $this->request('/findings/'.$this->fixture['affected'].'?'.http_build_query($query))->getStatusCode());
        self::assertSame(400, $this->request('/findings/'.$this->fixture['archived'].'?compare_before='.$this->fixture['images']['basis'])->getStatusCode());
        self::assertSame($before, $this->snapshot());
    }

    public function testDefaultNewestImageUsesKnownCaptureTimeBeforeStorageTime(): void
    {
        $image = $this->entityManager->find(\App\Entity\Evidence::class, $this->fixture['images']['newer']);
        $finding = $this->entityManager->find(Finding::class, $this->fixture['affected']);
        $this->entityManager->persist((new \App\Entity\ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus('available')
            ->setRequestedAt(new \DateTimeImmutable('2026-10-10'))->setCapturedAt(new \DateTimeImmutable('2026-10-10'))->setScreenshotPath($image->getFilePath()));
        $this->entityManager->flush();
        $view = self::getContainer()->get(FindingDetailService::class)->get($finding->getId());
        $comparison = self::getContainer()->get(ScreenshotComparisonService::class)->get($view, []);
        self::assertSame($this->fixture['images']['basis'], $comparison->before['evidence']->getId());
        self::assertSame($this->fixture['images']['newer'], $comparison->after['evidence']->getId());
    }

    public function testMissingSelectedImageAndInsufficientEvidenceRemainExplicit(): void
    {
        $response = $this->request('/findings/'.$this->fixture['affected'].'?'.http_build_query(['compare_after' => $this->fixture['images']['missing']]));
        $xpath = $this->xpath($response);
        self::assertSame(0, $xpath->query('//*[@data-comparison-side="after"]//img')->length);
        self::assertSame(1, $xpath->query('//*[@data-comparison-side="after"]//*[@data-comparison-missing and not(@hidden)]')->length);
        $archived = $this->xpath($this->request('/findings/'.$this->fixture['archived']));
        self::assertSame(0, $archived->query('//form[@data-comparison-form]')->length);
        self::assertStringContainsString('mindestens zwei', $archived->evaluate('string(//*[@data-screenshot-comparison])'));
    }

    public function testUnknownOrResetDecisionDoesNotBorrowAnEarlierBasis(): void
    {
        $finding = $this->entityManager->find(Finding::class, $this->fixture['affected']);
        $this->entityManager->getConnection()->executeStatement('UPDATE finding SET manual_assessment = NULL, assessed_at = NULL WHERE id = ?', [$finding->getId()]);
        $this->entityManager->refresh($finding);
        $view = self::getContainer()->get(FindingDetailService::class)->get($finding->getId());
        self::assertNull(self::getContainer()->get(ScreenshotComparisonService::class)->get($view, [])->basisId());
        $finding->setManualAssessment('confirmed', null, new \DateTimeImmutable('2026-10-01 12:00:00'));
        $this->entityManager->flush();
        $view = self::getContainer()->get(FindingDetailService::class)->get($finding->getId());
        $cancelled = new \App\Dto\FindingDetailView($view->finding, $view->evidence, $view->runs, $view->screenshotJobs, $view->assessments, $view->screenshots, $view->assessmentState, cancelledAssessmentIds: [$this->fixture['assessment'] => 'reset']);
        self::assertNull(self::getContainer()->get(ScreenshotComparisonService::class)->get($cancelled, [])->basisId());
    }

    public function testOverviewDeduplicatesCasesAndOmitsRecoveredHistoricalFailures(): void
    {
        $before = $this->snapshot();
        $view = self::getContainer()->get(FindingProblemService::class)->get([]);
        self::assertSame(['all' => 3, 'screenshot' => 1, 'missing' => 2, 'technical' => 2], $view['counts']);
        self::assertSame(3, $view['total']);
        self::assertCount(3, array_unique(array_column($view['rows'], 'id')));
        self::assertNotContains($this->fixture['recovered'], array_column($view['rows'], 'id'));
        $affected = array_values(array_filter($view['rows'], fn (array $r): bool => $r['id'] === $this->fixture['affected']))[0];
        self::assertStringContainsString('failure 3', $affected['problems']['screenshot']['message']);
        self::assertStringContainsString('failure 2', $affected['problems']['technical']['message']);
        self::assertSame(2, $affected['problems']['missing']['count']);
        self::assertSame('invalid', $affected['problems']['missing']['reason']);
        $response = $this->request('/errors');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $xpath = $this->xpath($response);
        self::assertSame(3, $xpath->query('//*[@data-error-case]')->length);
        self::assertSame(0, $xpath->query('//*[@id="error-markup" or @id="untrusted"]')->length);
        self::assertSame(0, $xpath->query('//form[@method="post"] | //img')->length);
        self::assertSame($before, $this->snapshot());
    }

    public function testFiltersSearchAndPaginationRetainOneCasePerRow(): void
    {
        $service = self::getContainer()->get(FindingProblemService::class);
        foreach (['screenshot' => 1, 'missing' => 2, 'technical' => 2] as $kind => $count) {
            $view = $service->get(['kind' => $kind]);
            self::assertSame($count, $view['total']);
        }
        self::assertSame(1, $service->get(['q' => 'TECHNICAL ONLY'])['total']);
        self::assertSame(0, $service->get(['q' => '%no_match%'])['total']);
        foreach (range(1, 12) as $i) {
            $copy = $this->entityManager->find(Finding::class, $this->fixture['technical']);
            $new = (new Finding())->setDomain($copy->getDomain())->setTitle('Pagination '.$i)->setType('stored-case')->setUrl('https://diagnostics.invalid/page-'.$i);
            $this->entityManager->persist($new);
            $this->entityManager->persist((new \App\Entity\RetestRun())->setFinding($new)->setResult('error'));
        }
        $this->entityManager->flush();
        $first = $service->get(['pageSize' => '10']);
        $second = $service->get(['pageSize' => '10', 'page' => '2']);
        self::assertSame(15, $first['total']);
        self::assertCount(10, $first['rows']);
        self::assertCount(5, $second['rows']);
        self::assertSame([], array_values(array_intersect(array_column($first['rows'], 'id'), array_column($second['rows'], 'id'))));
        self::assertSame(2, $service->get(['page' => '999'])['page']);
    }

    public function testMalformedFiltersAndPostRequestsCannotTriggerWork(): void
    {
        $before = $this->snapshot();
        foreach (['kind=unknown', 'kind[]=missing', 'page=-1', 'pageSize=all', 'q[]=x'] as $query) self::assertSame(400, $this->request('/errors?'.$query)->getStatusCode());
        self::assertSame(405, $this->request('/errors', 'POST')->getStatusCode());
        self::assertSame($before, $this->snapshot());
    }

    public function testNewPendingAttemptsReplaceHistoricalErrorsButNotMissingFileProblems(): void
    {
        $finding = $this->entityManager->find(Finding::class, $this->fixture['affected']);
        $this->entityManager->persist((new \App\Entity\ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus('queued')->setActiveKey($finding->getId()));
        $this->entityManager->persist((new \App\Entity\RetestRun())->setFinding($finding)->setResult('pending'));
        $this->entityManager->flush();
        $service = self::getContainer()->get(FindingProblemService::class);
        self::assertSame(['screenshot' => 0, 'technical' => 1], $service->currentCounts());
        self::assertSame(['all' => 3, 'screenshot' => 0, 'missing' => 2, 'technical' => 1], $service->get([])['counts']);
    }

    public function testReadOnlyServicesNeverFlushPendingManagedChanges(): void
    {
        $before = $this->snapshot();
        $finding = $this->entityManager->find(Finding::class, $this->fixture['affected']);
        $finding->setPrivateNotes('Pending note, not saved');
        $view = self::getContainer()->get(FindingDetailService::class)->get($finding->getId());
        self::getContainer()->get(ScreenshotComparisonService::class)->get($view, []);
        self::getContainer()->get(FindingProblemService::class)->get([]);
        self::getContainer()->get(FindingProblemService::class)->currentCounts();
        self::assertSame($before, $this->snapshot());
    }

    public function testSettingsCountsUseLatestAttemptsWithoutReadingImageFiles(): void
    {
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->expects(self::never())->method('exists');
        $storage->expects(self::never())->method('read');
        $service = new FindingProblemService($this->entityManager->getConnection(), new StoredImageInspector($storage), self::getContainer()->get(SettingsService::class));
        self::assertSame(['screenshot' => 1, 'technical' => 2], $service->currentCounts());
        $xpath = $this->xpath($this->request('/settings'));
        self::assertSame('1', $xpath->evaluate('string(//a[@data-health-errors="screenshot"])'));
        self::assertSame('2', $xpath->evaluate('string(//a[@data-health-errors="technical"])'));
        foreach (['screenshot', 'technical', 'missing'] as $kind) self::assertSame('/errors?kind='.$kind, $xpath->evaluate('string(//a[@data-health-errors="'.$kind.'"]/@href)'));
    }

    public function testDetailPreservesSafeErrorListReturnContext(): void
    {
        $xpath = $this->xpath($this->request('/errors?kind=technical&q=fixture'));
        $link = $xpath->evaluate('string(//a[@data-error-detail]/@href)');
        self::assertNotSame('', $link);
        $detail = $this->xpath($this->request($link));
        self::assertStringContainsString('Zur Fehlerübersicht', $detail->evaluate('string(//a[contains(@class,"studio-detail-back")])'));
        self::assertStringStartsWith('/errors?', $detail->evaluate('string(//a[contains(@class,"studio-detail-back")]/@href)'));
        $unsafe = $this->xpath($this->request('/findings/'.$this->fixture['affected'].'?'.http_build_query(['return_to' => '//outside.invalid/errors'])));
        self::assertSame('/findings', $unsafe->evaluate('string(//a[contains(@class,"studio-detail-back")]/@href)'));
    }

    private function request(string $path, string $method = 'GET'): Response
    {
        return self::$kernel->handle(Request::create($path, $method));
    }

    private function xpath(Response $response): \DOMXPath
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        return new \DOMXPath($document);
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (['finding', 'finding_assessment', 'finding_review_acknowledgement', 'finding_assessment_reset', 'screenshot_job', 'retest_run', 'evidence', 'setting'] as $table) {
            $rows[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid');
        }
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        foreach ($storage->listPaths() as $path) $rows['files'][$path] = hash('sha256', $storage->read($path));
        return $rows;
    }
}
