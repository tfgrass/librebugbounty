<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScreenshotJob;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\UiTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class FirstStartWebTest extends DatabaseTestCase
{
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);
        $screenshot = $this->createMock(BrowserScreenshotClientInterface::class);
        $screenshot->expects(self::never())->method('capture');
        $screenshot->expects(self::never())->method('waitUntilReady');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $screenshot);
    }

    public static function locales(): iterable
    {
        yield 'German' => ['de'];
        yield 'English' => ['en'];
    }

    #[DataProvider('locales')]
    public function testEmptyWorkspaceShowsTheFirstActionAcrossPagesWithoutCreatingData(string $locale): void
    {
        self::getContainer()->set(UiTranslator::class, new UiTranslator($locale));
        $before = $this->snapshot();
        $intake = $this->xpath($this->request('/')->getContent());
        self::assertSame(1, $intake->query('//form[@id="intake-form"]//input[@name="url"]')->length);
        foreach (['/findings', '/statistics', '/review', '/review?images=all', '/export', '/export?scope=all', '/findings?q=not-present'] as $path) {
            $response = $this->request($path);
            $xpath = $this->xpath($response->getContent());
            self::assertSame($locale, $xpath->evaluate('string(/html/@lang)'), $path);
            self::assertSame(1, $xpath->query('//*[@data-first-start]')->length, $path);
            self::assertSame('/', $xpath->evaluate('string(//*[@data-first-start]//a[@data-first-start-cta]/@href)'), $path);
            self::assertStringContainsString($locale === 'de' ? 'URL erfassen' : 'Add URL', $xpath->evaluate('string(//*[@data-first-start]//a[@data-first-start-cta])'), $path);
            $heading = str_starts_with($path, '/statistics')
                ? ($locale === 'de' ? 'Deine Statistik beginnt mit der ersten URL' : 'Your statistics start with the first URL')
                : (str_starts_with($path, '/export')
                    ? ($locale === 'de' ? 'Noch keine Fälle zum Exportieren' : 'No cases to export yet')
                    : ($locale === 'de' ? 'Noch keine Fälle' : 'No cases yet'));
            self::assertStringContainsString($heading, $xpath->evaluate('string(//*[@data-first-start])'), $path);
        }
        self::assertSame($before, $this->snapshot(), 'First-start reads must not insert settings, jobs, findings or artifacts.');
    }

    #[DataProvider('locales')]
    public function testArchivedOnlyWorkspaceLinksToRetainedCasesInsteadOfPretendingToBeNew(string $locale): void
    {
        self::getContainer()->set(UiTranslator::class, new UiTranslator($locale));
        $finding = $this->finding('archived')->setManualAssessment('discarded', null, new \DateTimeImmutable('2026-10-04T11:00:00+02:00'));
        $this->entityManager->flush();
        $before = $this->snapshot();
        $xpath = $this->xpath($this->request('/findings')->getContent());
        self::assertSame(0, $xpath->query('//*[@data-first-start]')->length);
        self::assertSame(1, $xpath->query('//*[@data-list-empty-state="archived"]')->length);
        self::assertStringContainsString($locale === 'de' ? 'Keine aktiven Fälle' : 'No active cases', $xpath->evaluate('string(//*[@data-list-empty-state="archived"])'));
        self::assertSame(1, $xpath->query('//*[@data-list-empty-state="archived"]//a[@href="/findings?scope=discarded"]')->length);
        $archive = $this->xpath($this->request('/findings?scope=discarded')->getContent());
        self::assertSame(1, $archive->query('//*[@data-finding-id="'.$finding->getId().'"]')->length);
        foreach (['/statistics', '/export', '/review'] as $path) {
            self::assertSame(0, $this->xpath($this->request($path)->getContent())->query('//*[@data-first-start]')->length, $path);
        }
        self::assertSame($before, $this->snapshot());
    }

    #[DataProvider('locales')]
    public function testUnmatchedFilterOffersResetAndKeepsTheExistingWorkspace(string $locale): void
    {
        self::getContainer()->set(UiTranslator::class, new UiTranslator($locale));
        $finding = $this->finding('present');
        $before = $this->snapshot();
        foreach (['/findings?q=not-present', '/findings?assessment=confirmed', '/findings?scope=discarded'] as $path) {
            $xpath = $this->xpath($this->request($path)->getContent());
            self::assertSame(0, $xpath->query('//*[@data-first-start]')->length, $path);
            self::assertSame(1, $xpath->query('//*[@data-list-empty-state="filtered"]')->length, $path);
            self::assertSame(1, $xpath->query('//*[@data-list-empty-state="filtered"]//a[@href="/findings"]')->length, $path);
            self::assertStringContainsString($locale === 'de' ? 'Keine Fälle für diese Filter' : 'No cases match these filters', $xpath->evaluate('string(//*[@data-list-empty-state="filtered"])'), $path);
        }
        $reset = $this->xpath($this->request('/findings')->getContent());
        self::assertSame(1, $reset->query('//*[@data-finding-id="'.$finding->getId().'"]')->length);
        self::assertSame($before, $this->snapshot());
    }

    #[DataProvider('locales')]
    public function testPendingLocalScreenshotKeepsWaitingGuidanceAndDoesNotRunAWorker(string $locale): void
    {
        self::getContainer()->set(UiTranslator::class, new UiTranslator($locale));
        $finding = $this->finding('queued');
        $this->entityManager->persist((new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus('queued')->setActiveKey($finding->getId()));
        $this->entityManager->flush();
        $before = $this->snapshot();
        $review = $this->xpath($this->request('/review')->getContent());
        self::assertSame(0, $review->query('//*[@data-first-start]')->length);
        self::assertSame($locale === 'de' ? 'Noch keine bildbereiten Fälle.' : 'No cases with ready images yet.', trim($review->evaluate('string(//*[@id="review-empty-title"])')));
        self::assertSame(1, $review->query('//section[contains(@class,"review-empty")]//a[contains(@href,"images=all")]')->length);
        $withoutImage = $this->xpath($this->request('/review?images=all')->getContent());
        self::assertSame(1, $withoutImage->query('//*[@data-review-card and @data-current-id="'.$finding->getId().'"]')->length);
        self::assertStringContainsString($locale === 'de' ? 'Der Screenshot entsteht im Hintergrund.' : 'The screenshot is created in the background.', $withoutImage->evaluate('string(//body)'));
        foreach (['/findings', '/statistics', '/statistics?tld=.invalid', '/export', '/export?assessment=confirmed', '/findings/'.$finding->getId()] as $path) {
            self::assertSame(0, $this->xpath($this->request($path)->getContent())->query('//*[@data-first-start]')->length, $path);
        }
        self::assertSame($before, $this->snapshot(), 'Viewing the pending first case must neither consume nor enqueue screenshot work.');
    }

    private function finding(string $label): Finding
    {
        $domain = (new Domain())->setHostname('127.0.0.1')->setScheme('http')->setAuthorized(true);
        $finding = (new Finding())->setDomain($domain)->setTitle('Synthetic first-start '.$label)->setType('synthetic')
            ->setUrl('http://127.0.0.1/first-start/'.$label);
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function request(string $path): Response
    {
        $request = Request::create($path);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $path);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $path);

        return $response;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['domain', 'finding', 'screenshot_job', 'retest_run', 'evidence', 'finding_assessment', 'finding_review_acknowledgement', 'setting'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }
        $snapshot['artifacts'] = [];
        $artifacts = APP_TEST_ROOT.'/artifacts';
        if (is_dir($artifacts)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($artifacts, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) $snapshot['artifacts'][substr($file->getPathname(), strlen($artifacts) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($snapshot['artifacts']);

        return $snapshot;
    }
}
