<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use App\Service\ScreenshotQueueService;
use App\Service\SettingsService;
use App\Tests\Support\ReviewBrowserTransportStub;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class WebMutationCsrfTest extends DatabaseTestCase
{
    private Session $session;
    private ReviewBrowserTransportStub $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
        $this->browser = new ReviewBrowserTransportStub(['inconclusive']);
        self::getContainer()->set(BrowserRetestClientInterface::class, $this->browser);
        $capture = $this->createMock(BrowserScreenshotClientInterface::class);
        $capture->expects(self::never())->method('waitUntilReady');
        $capture->expects(self::never())->method('capture');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $capture);
    }

    public function testEveryWebMutationRejectsMissingForgedAndMalformedTokensWithoutEffects(): void
    {
        $finding = $this->finding('all-mutations');
        $this->addArtifact($finding, 'retain fixture');
        self::getContainer()->get(SettingsService::class)->save(['intake.default_payload' => 'SAFE-MARKER']);
        $base = '/findings/'.$finding->getId();
        $mutations = [
            '/findings' => ['url' => 'http://localhost/fixture/new'],
            '/api/findings' => ['url' => 'http://localhost/fixture/api-new'],
            '/settings' => ['default_payload' => 'UPDATED-MARKER', 'review_timeout_ms' => '12000'],
            $base.'/retest' => [],
            $base.'/screenshots' => [],
            $base.'/delete' => [],
            $base.'/assessment' => ['assessment' => 'fixed'],
            $base.'/notes' => ['notes' => 'Changed note'],
            $base.'/mark-vulnerable' => [],
            $base.'/mark-contacted' => [],
            $base.'/mark-sent' => [],
            $base.'/confirm-fixed' => [],
        ];
        $before = $this->snapshot();
        foreach ($mutations as $path => $values) {
            foreach ([[], ['_token' => 'forged'], ['_token' => ''], ['_token' => ['nested']], ['_token' => 7], ['_token' => null]] as $token) {
                self::assertSame(403, $this->request($path, 'POST', $token + $values)->getStatusCode(), $path);
                self::assertSame($before, $this->snapshot(), $path.' must preserve data and artifacts');
            }
        }
        self::assertSame([], $this->browser->browserCalls);
    }

    public function testNewFindingActionsBindTokensToActionCaseAndSessionBeforeLookup(): void
    {
        $finding = $this->finding('token-owner');
        $other = $this->finding('other-case');
        $this->addArtifact($finding, 'retained evidence');
        $html = $this->request('/legacy/findings/'.$finding->getId())->getContent();
        $otherHtml = $this->request('/legacy/findings/'.$other->getId())->getContent();
        $foreignSession = new Session(new MockArraySessionStorage());
        $foreignHtml = $this->request('/legacy/findings/'.$finding->getId(), session: $foreignSession)->getContent();
        $settingsToken = $this->token($this->request('/legacy/settings')->getContent(), '/settings');
        $before = $this->snapshot();
        foreach (['retest', 'screenshots', 'delete'] as $action) {
            $path = '/findings/'.$finding->getId().'/'.$action;
            $foreignAction = $action === 'delete' ? 'retest' : 'delete';
            foreach ([
                $settingsToken,
                $this->token($html, '/findings/'.$finding->getId().'/'.$foreignAction),
                $this->token($otherHtml, '/findings/'.$other->getId().'/'.$action),
                $this->token($foreignHtml, $path),
            ] as $token) {
                self::assertSame(403, $this->request($path, 'POST', ['_token' => $token])->getStatusCode());
                self::assertSame($before, $this->snapshot());
            }
            // A rejected token must not first look up an invalid or unknown ID.
            self::assertSame(403, $this->request('/findings/not-a-case/'.$action, 'POST', ['_token' => 'forged'])->getStatusCode());
            self::assertSame($before, $this->snapshot());
        }
        self::assertSame([], $this->browser->browserCalls);
    }

    public function testSettingsUsesNativeScopedTokenAndRejectsForeignSessionsBeforeParsingValues(): void
    {
        $finding = $this->finding('settings-context');
        $this->addArtifact($finding, 'unchanged settings context');
        $html = $this->request('/legacy/settings')->getContent();
        $token = $this->token($html, '/settings');
        $foreign = new Session(new MockArraySessionStorage());
        $foreignToken = $this->token($this->request('/legacy/settings', session: $foreign)->getContent(), '/settings');
        $findingToken = $this->token($this->request('/legacy/findings/'.$finding->getId())->getContent(), '/findings/'.$finding->getId().'/delete');
        $before = $this->snapshot();
        foreach ([$foreignToken, $findingToken, ['invalid'], ''] as $rejected) {
            $response = $this->request('/settings', 'POST', [
                '_token' => $rejected,
                'default_payload' => ['must not parse'],
                'review_timeout_ms' => ['must not parse'],
            ]);
            self::assertSame(403, $response->getStatusCode());
            self::assertSame($before, $this->snapshot());
        }
        $response = $this->request('/settings', 'POST', [
            '_token' => $token, 'default_payload' => 'UPDATED-MARKER', 'review_timeout_ms' => '12345',
        ]);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('UPDATED-MARKER', self::getContainer()->get(SettingsService::class)->getDefaultPayload());
        self::assertSame(12345, self::getContainer()->get(SettingsService::class)->getReviewScanTimeoutMs());
        $after = $this->snapshot();
        unset($before['setting'], $after['setting']);
        self::assertSame($before, $after);
        self::assertSame([], $this->browser->browserCalls);
    }

    public function testNativeScreenshotTokenQueuesExactlyOneJobAndPreservesExistingData(): void
    {
        $finding = $this->finding('native-screenshot');
        $this->addArtifact($finding, 'earlier artifact');
        $path = '/findings/'.$finding->getId().'/screenshots';
        $token = $this->token($this->request('/legacy/findings/'.$finding->getId())->getContent(), $path);
        $before = $this->snapshot();
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            self::assertSame(302, $this->request($path, 'POST', ['_token' => $token])->getStatusCode());
        }
        $jobs = $this->entityManager->getRepository(ScreenshotJob::class)->findBy(['finding' => $finding]);
        self::assertCount(1, $jobs);
        self::assertSame('queued', $jobs[0]->getStatus());
        $after = $this->snapshot();
        unset($before['screenshot_job'], $after['screenshot_job']);
        self::assertSame($before, $after);
        self::assertSame([], $this->browser->browserCalls);
    }

    public function testNativeRetestTokenUsesOnlyMockedBrowserAndKeepsManualJudgment(): void
    {
        $finding = $this->finding('native-retest');
        $this->addArtifact($finding, 'earlier artifact');
        self::getContainer()->get(FindingService::class)->assess($finding, 'fixed');
        $path = '/findings/'.$finding->getId().'/retest';
        $token = $this->token($this->request('/legacy/findings/'.$finding->getId())->getContent(), $path);
        $before = $this->snapshot();
        self::assertSame(302, $this->request($path, 'POST', ['_token' => $token])->getStatusCode());
        self::assertSame(['chromium'], $this->browser->browserCalls);
        self::assertSame([false], $this->browser->screenshotCalls);
        self::assertCount(1, $this->entityManager->getRepository(RetestRun::class)->findBy(['finding' => $finding]));
        self::assertCount(1, $this->entityManager->getRepository(ScreenshotJob::class)->findBy(['finding' => $finding]));
        $after = $this->snapshot();
        self::assertSame($before['finding_assessment'], $after['finding_assessment']);
        $evidenceById = array_column($after['evidence'], null, 'id');
        foreach ($before['evidence'] as $evidence) {
            self::assertSame($evidence, $evidenceById[$evidence['id']], 'Rechecks retain previous evidence');
        }
        self::assertSame($before['artifacts'], $after['artifacts']);
        self::assertSame('fixed', $after['finding'][0]['manual_assessment']);
        self::assertSame($before['finding'][0]['private_notes'], $after['finding'][0]['private_notes']);
        self::assertSame($before['finding'][0]['contacted_at'], $after['finding'][0]['contacted_at']);
    }

    public function testNativeDeleteTokenDeletesOnlyItsFindingAndArtifacts(): void
    {
        $finding = $this->finding('native-delete');
        $other = $this->finding('retained-case');
        $deletedEvidence = $this->addArtifact($finding, 'remove this fixture');
        $retainedEvidence = $this->addArtifact($other, 'retain this fixture');
        self::getContainer()->get(ScreenshotQueueService::class)->enqueue($finding);
        $path = '/findings/'.$finding->getId().'/delete';
        $html = $this->request('/legacy/findings/'.$finding->getId())->getContent();
        $token = $this->token($html, $path);
        self::assertSame(302, $this->request($path, 'POST', ['_token' => $token])->getStatusCode());
        $connection = $this->entityManager->getConnection();
        self::assertFalse($connection->fetchOne('SELECT id FROM finding WHERE id = ?', [$finding->getId()]));
        self::assertSame($other->getId(), $connection->fetchOne('SELECT id FROM finding WHERE id = ?', [$other->getId()]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM screenshot_job WHERE finding_id = ?', [$finding->getId()]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM evidence WHERE finding_id = ?', [$finding->getId()]));
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        self::assertFalse($storage->exists($deletedEvidence->getFilePath()));
        self::assertTrue($storage->exists($retainedEvidence->getFilePath()));
        self::assertSame([], $this->browser->browserCalls);
    }

    private function finding(string $label): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'localhost']);
        if ($domain === null) {
            $domain = (new Domain())->setHostname('localhost')->setScheme('http');
            $this->entityManager->persist($domain);
        }
        $finding = (new Finding())->setDomain($domain)->setTitle($label)->setType('other')
            ->setUrl('http://localhost/fixture/'.$label)->setMethod('GET')->setExpectedEvidence('SAFE-MARKER')
            ->setPrivateNotes('Retained fixture note')->setContactedAt(new \DateTimeImmutable('2026-01-02'));
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        return $finding;
    }

    private function addArtifact(Finding $finding, string $contents): Evidence
    {
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, $contents, 'fixture.txt');
        $evidence = (new Evidence())->setFinding($finding)->setKind('note')->setValue('Retained note evidence')->setFilePath($stored->relativePath);
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();
        return $evidence;
    }

    private function request(string $path, string $method = 'GET', array $parameters = [], ?Session $session = null): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($session ?? $this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        return $response;
    }

    private function token(string $html, string $path): string
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $tokens = $xpath->query('//form[@method="post" and @action="'.$path.'"]//input[@name="_token"]');
        self::assertGreaterThan(0, $tokens->length, 'Native form must include a token for '.$path);
        return $tokens->item(0)->getAttribute('value');
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['domain', 'finding', 'finding_assessment', 'evidence', 'retest_run', 'screenshot_job', 'setting'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $snapshot['artifacts'] = [];
        foreach ($storage->listPaths() as $path) {
            $snapshot['artifacts'][$path] = hash('sha256', $storage->read($path));
        }
        return $snapshot;
    }
}
