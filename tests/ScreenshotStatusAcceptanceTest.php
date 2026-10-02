<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Service\BrowserRetestClientInterface;
use App\Service\EvidenceService;
use App\Service\EvidenceStorageInterface;
use App\Service\ScreenshotStatusService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ScreenshotStatusAcceptanceTest extends DatabaseTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testStoredCaptureFailuresAndUnknownHistoryRemainDistinctAndReadOnly(): void
    {
        $domain = (new Domain())->setHostname('localhost')->setScheme('http');
        $finding = (new Finding())->setDomain($domain)->setTitle('Capture history')->setType('other')
            ->setUrl('http://localhost/fixture/history')->setStatus('fixed')->setReviewState('confirmed_fixed')
            ->setPrivateNotes('Keep this manual decision.');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        $source = APP_TEST_ROOT.'/history.png';
        file_put_contents($source, base64_decode(self::PNG, true));
        $evidence = self::getContainer()->get(EvidenceService::class)->addEvidence($finding, 'screenshot', filePath: $source);
        $states = [
            ['path' => $evidence->getFilePath(), 'raw' => [], 'expected' => 'available'],
            ['path' => 'storage/artifacts/'.$finding->getId().'/missing.png', 'raw' => [], 'expected' => 'missing'],
            ['path' => null, 'raw' => [], 'expected' => 'unknown'],
            ['path' => null, 'raw' => ['screenshotRequested' => false], 'expected' => 'not_requested'],
            ['path' => null, 'raw' => ['screenshotRequested' => true], 'expected' => 'failed'],
            ['path' => null, 'raw' => ['screenshotCaptureError' => 'Capture tool unavailable <b>diagnostic</b>'], 'expected' => 'failed'],
        ];
        $runs = [];
        foreach ($states as $index => $state) {
            $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('inconclusive')
                ->setStartedAt(new \DateTimeImmutable('2026-01-01 00:00:0'.$index))
                ->setFinishedAt(new \DateTimeImmutable('2026-01-01 00:01:00'))
                ->setScreenshotPath($state['path'])->setRawResult($state['raw']);
            $this->entityManager->persist($run);
            $runs[] = $run;
        }
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();
        $before = [
            $connection->fetchAllAssociative('SELECT * FROM finding'),
            $connection->fetchAllAssociative('SELECT * FROM retest_run ORDER BY id'),
            $connection->fetchAllAssociative('SELECT * FROM evidence ORDER BY id'),
        ];
        $paths = self::getContainer()->get(EvidenceStorageInterface::class)->listPaths();
        foreach ($runs as $index => $run) {
            self::assertSame($states[$index]['expected'], self::getContainer()->get(ScreenshotStatusService::class)->forRun($run)->state);
        }
        $request = Request::create('/findings/'.$finding->getId());
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        self::assertSame(200, $response->getStatusCode());
        $html = $response->getContent();
        self::assertStringContainsString('Latest recorded run: Screenshot capture failed', $html);
        self::assertStringContainsString('Screenshot file missing or unavailable', $html);
        self::assertStringContainsString('Screenshot not requested', $html);
        self::assertStringContainsString('Requested screenshot was not stored', $html);
        self::assertStringContainsString('The historical record does not say whether a screenshot was requested.', $html);
        self::assertStringContainsString('Capture tool unavailable &lt;b&gt;diagnostic&lt;/b&gt;', $html);
        self::assertStringNotContainsString('Capture tool unavailable <b>diagnostic</b>', $html);
        self::assertStringContainsString('Stored:', $html);
        self::assertStringContainsString('<img ', $html);
        self::assertStringContainsString('Keep this manual decision.', $html);
        self::assertSame($before, [
            $connection->fetchAllAssociative('SELECT * FROM finding'),
            $connection->fetchAllAssociative('SELECT * FROM retest_run ORDER BY id'),
            $connection->fetchAllAssociative('SELECT * FROM evidence ORDER BY id'),
        ]);
        self::assertSame($paths, self::getContainer()->get(EvidenceStorageInterface::class)->listPaths());
    }

    public function testIntakeQueuesScreenshotWithoutStartingHeadlessVerification(): void
    {
        $browser = $this->createMock(BrowserRetestClientInterface::class);
        $browser->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $browser);
        $response = $this->request('/findings', 'POST', [
            '_token' => $this->intakeToken(),
            'url' => 'http://fixture.localhost/intake',
            'annotate' => 'Retain me',
        ]);
        self::assertSame(302, $response->getStatusCode());
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertArrayHasKey('message', $query, json_encode($query));
        self::assertStringContainsString('stored for fixture.localhost', $query['message']);
        self::assertStringContainsString('Screenshot queued', $query['message']);
        self::assertArrayNotHasKey('error', $query);
        self::assertStringNotContainsString('Fixture capture utility unavailable', $response->headers->get('Location'));
        $finding = $this->entityManager->getRepository(Finding::class)->findOneBy(['url' => 'http://fixture.localhost/intake']);
        self::assertInstanceOf(Finding::class, $finding);
        self::assertSame('Retain me', $finding->getPrivateNotes());
        self::assertCount(0, $this->entityManager->getRepository(RetestRun::class)->findBy(['finding' => $finding]));
        $job = $this->entityManager->getRepository(ScreenshotJob::class)->findOneBy(['finding' => $finding]);
        self::assertInstanceOf(ScreenshotJob::class, $job);
        self::assertSame('queued', $job->getStatus());
    }

    public function testAvailableImageDoesNotBecomeFailureBecauseOfEarlierCaptureAttempt(): void
    {
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->expects(self::once())->method('exists')->with('storage/artifacts/example.png')->willReturn(true);
        $run = (new RetestRun())->setScreenshotPath('storage/artifacts/example.png')
            ->setRawResult(['screenshotCaptureError' => 'First attempt failed']);
        $status = (new ScreenshotStatusService($storage))->forRun($run);
        self::assertSame('available', $status->state);
        self::assertFalse($status->isProblem());
        self::assertStringContainsString('First attempt failed', $status->detail);
    }

    public function testFindingPageExplainsDetectedAndUnresolvedBrowserProtection(): void
    {
        $domain = (new Domain())->setHostname('challenge.localhost')->setScheme('https');
        $finding = (new Finding())->setDomain($domain)->setTitle('Challenge metadata')->setType('other')
            ->setUrl('https://challenge.localhost/example');
        $cleared = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())
            ->setStatus('available')->setAttempts(1)->setCaptureMetadata([
                'challengeDetected' => true,
                'challengeCleared' => true,
                'challengeWaitedMs' => 4200,
            ]);
        $unresolved = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())
            ->setStatus('available')->setAttempts(1)->setCaptureMetadata([
                'challengeDetected' => true,
                'challengeCleared' => false,
                'challengeWaitedMs' => 30000,
            ]);
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->persist($cleared);
        $this->entityManager->persist($unresolved);
        $this->entityManager->flush();

        $html = $this->request('/findings/'.$finding->getId())->getContent();

        self::assertStringContainsString('Browser protection detected', $html);
        self::assertStringContainsString('cleared after 4.2 seconds', $html);
        self::assertStringContainsString('still active after 30.0 seconds', $html);
        self::assertStringContainsString('screenshot may show the protection page', $html);
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function intakeToken(): string
    {
        $html = $this->request('/')->getContent();
        self::assertSame(1, preg_match('#<form[^>]+action="/findings"[^>]*>(.*?)</form>#s', $html, $form));
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $form[1], $matches));

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
