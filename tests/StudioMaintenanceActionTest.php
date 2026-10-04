<?php

namespace App\Tests;

use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\FindingService;
use App\Service\SettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StudioMaintenanceActionTest extends DatabaseTestCase
{
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testDetailOffersTwoSeparateAccessibleActionsAndPreservesReviewContext(): void
    {
        $finding = $this->finding('forms');
        $before = $this->snapshot();
        $returnTo = '/review?kind=error&images=all';
        $response = $this->request('/findings/'.$finding->getId().'?'.http_build_query(['return_to' => $returnTo]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $xpath = $this->xpath($response->getContent());
        foreach (['screenshots' => 'data-studio-screenshot-action', 'retest' => 'data-studio-retest-action'] as $action => $hook) {
            $formPath = '//form[@'.$hook.' and @action="/findings/'.$finding->getId().'/'.$action.'"]';
            self::assertSame(1, $xpath->query($formPath)->length, $action);
            self::assertSame('post', $xpath->evaluate('string('.$formPath.'/@method)'));
            self::assertNotSame('', $xpath->evaluate('string('.$formPath.'/@aria-describedby)'));
            self::assertSame(0, $xpath->query($formPath.'//input[@name="surface"]')->length);
            self::assertSame($returnTo, $xpath->evaluate('string('.$formPath.'//input[@name="return_to"]/@value)'));
            self::assertSame(1, $xpath->query($formPath.'//button[@type="submit"]')->length);
        }
        self::assertSame($before, $this->snapshot());

        self::getContainer()->get(FindingService::class)->assess($finding, 'discarded');
        $discarded = $this->xpath($this->request('/findings/'.$finding->getId())->getContent());
        self::assertSame(0, $discarded->query('//form[@data-studio-screenshot-action or @data-studio-retest-action]')->length);
    }

    public function testScreenshotQueueAndActiveRetestRemainIndependentOperations(): void
    {
        self::getContainer()->get(SettingsService::class)->save(['review.scan_timeout_ms' => '73000']);
        $requests = [];
        $browser = $this->createMock(BrowserRetestClientInterface::class);
        $browser->expects(self::once())->method('retest')->willReturnCallback(
            static function (BrowserRetestRequest $request) use (&$requests): RetestResultData {
                $requests[] = $request;

                return new RetestResultData(
                    result: 'still_vulnerable',
                    httpStatus: 200,
                    finalUrl: $request->url,
                    observedEvidence: 'fixture marker',
                );
            },
        );
        self::getContainer()->set(BrowserRetestClientInterface::class, $browser);
        $screenshots = $this->createMock(BrowserScreenshotClientInterface::class);
        $screenshots->expects(self::never())->method('capture');
        $screenshots->expects(self::never())->method('waitUntilReady');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $screenshots);
        $finding = $this->finding('independent-actions');
        $returnTo = '/findings?scope=all&q=independent-actions';

        $queued = $this->request('/findings/'.$finding->getId().'/screenshots', 'POST', [
            'return_to' => $returnTo,
        ]);
        self::assertSame(Response::HTTP_FOUND, $queued->getStatusCode());
        $this->assertDetailRedirect($queued, $finding, $returnTo);
        self::assertCount(1, $this->entityManager->getRepository(ScreenshotJob::class)->findBy(['finding' => $finding]));
        self::assertCount(0, $this->entityManager->getRepository(RetestRun::class)->findBy(['finding' => $finding]));
        self::assertSame([], $requests);

        $retested = $this->request('/findings/'.$finding->getId().'/retest', 'POST', [
            'return_to' => $returnTo,
        ]);
        self::assertSame(Response::HTTP_FOUND, $retested->getStatusCode());
        $this->assertDetailRedirect($retested, $finding, $returnTo);
        self::assertCount(1, $this->entityManager->getRepository(ScreenshotJob::class)->findBy(['finding' => $finding]));
        self::assertCount(1, $this->entityManager->getRepository(RetestRun::class)->findBy(['finding' => $finding]));
        self::assertCount(1, $requests);
        self::assertSame(73000, $requests[0]->timeoutMs);
        self::assertFalse($requests[0]->screenshot);
        self::assertTrue($requests[0]->headless);
    }

    private function assertDetailRedirect(Response $response, Finding $finding, string $returnTo): void
    {
        self::assertSame('/findings/'.$finding->getId(), parse_url($response->headers->get('Location'), PHP_URL_PATH));
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertSame($returnTo, $query['return_to'] ?? null);
        self::assertArrayHasKey('message', $query);
    }

    private function finding(string $label): Finding
    {
        $domain = (new Domain())->setHostname('maintenance.localhost')->setScheme('http');
        $finding = (new Finding())->setDomain($domain)->setTitle($label)->setType('other')
            ->setUrl('http://maintenance.localhost/'.$label)->setMethod('GET')->setStatus('new')
            ->setExpectedEvidence('fixture marker');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

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
        foreach (['finding', 'finding_assessment', 'evidence', 'retest_run', 'screenshot_job'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $snapshot;
    }
}
