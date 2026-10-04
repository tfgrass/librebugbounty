<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StudioFindingTest extends DatabaseTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';
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

    public function testStudioDetailIsReadOnlyAndTheLegacyBookmarkRedirectsToIt(): void
    {
        $finding = $this->finding('shared-detail');
        $this->observation($finding);
        $this->job($finding, 'queued', '2026-01-03');
        $this->imageEvidence($finding);
        $before = $this->snapshot();
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $paths = $storage->listPaths();

        $studio = $this->request($this->studioPath($finding));
        self::assertSame(200, $studio->getStatusCode());
        self::assertStringContainsString('text/html', (string) $studio->headers->get('Content-Type'));
        $xpath = $this->xpath($studio->getContent());
        self::assertStringContainsString($finding->getUrl(), $xpath->evaluate('string(//body)'));
        self::assertStringContainsString('fixture private note', $xpath->evaluate('string(//body)'));
        self::assertStringContainsString('inconclusive', $xpath->evaluate('string(//body)'));
        self::assertSame(1, $xpath->query('//form[@action="/findings/'.$finding->getId().'/assessment"]')->length);
        self::assertStringContainsString('no-store', (string) $studio->headers->get('Cache-Control'));
        $legacy = $this->request('/legacy/findings/'.$finding->getId().'?message=fixture');
        self::assertSame(308, $legacy->getStatusCode());
        self::assertSame('/findings/'.$finding->getId().'?message=fixture', $legacy->headers->get('Location'));
        self::assertSame($before, $this->snapshot());
        self::assertSame($paths, $storage->listPaths());
    }

    public function testUnknownAndMalformedStudioIdsReturn404WithoutChangingData(): void
    {
        $this->finding('not-found');
        $before = $this->snapshot();
        foreach (['00000000-0000-4000-8000-000000000000', 'not-an-id', 'broken%3Cmarkup%3E'] as $id) {
            self::assertSame(404, $this->request('/findings/'.$id)->getStatusCode());
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testStoredMarkupAndFailedOrMissingCaptureDiagnosticsAreEscaped(): void
    {
        $finding = $this->finding('escaping');
        $markup = '<span id="stored-markup">untrusted & "quoted"</span>';
        $finding->setTitle($markup)->setPrivateNotes($markup)->setPayload($markup)->setExpectedEvidence($markup);
        $note = (new Evidence())->setFinding($finding)->setKind('note')->setValue($markup);
        $this->entityManager->persist($note);
        $run = $this->observation($finding);
        $run->setObservedEvidence($markup)->setErrorMessage($markup)->setRawResult(['screenshotCaptureError' => $markup]);
        $missing = $this->job($finding, 'available', '2026-01-02');
        $missing->setScreenshotPath('storage/artifacts/'.$finding->getId().'/missing.png');
        $missingEvidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($missing->getScreenshotPath());
        $this->entityManager->persist($missingEvidence);
        $failed = $this->job($finding, 'failed', '2026-01-03');
        $failed->setErrorMessage($markup)->setCaptureMetadata([
            'challengeDetected' => true, 'challengeCleared' => false, 'challengeWaitedMs' => 30000,
        ]);
        $this->entityManager->flush();
        $before = $this->snapshot();

        $response = $this->request($this->studioPath($finding));

        self::assertSame(200, $response->getStatusCode());
        $xpath = $this->xpath($response->getContent());
        self::assertSame(0, $xpath->query('//*[@id="stored-markup"]')->length);
        self::assertStringContainsString($markup, $xpath->evaluate('string(//body)'));
        self::assertSame($markup, $xpath->evaluate('string(//textarea[@name="notes"])'));
        self::assertStringContainsString('Bilddatei nicht verfügbar', $xpath->evaluate('string(//body)'));
        self::assertStringContainsString('Aufnahme fehlgeschlagen', $xpath->evaluate('string(//body)'));
        self::assertStringContainsString('30,0 Sekunden gewartet.', $xpath->evaluate('string(//body)'));
        self::assertSame(0, $xpath->query('//img[contains(@src, "missing.png")]')->length);
        self::assertSame($before, $this->snapshot());
    }

    public function testImageEvidenceRemainsAvailableEvenWhenTheNewestScreenshotAttemptFailed(): void
    {
        $finding = $this->finding('retained-image');
        $evidence = $this->imageEvidence($finding);
        $this->job($finding, 'failed', '2026-01-03')->setErrorMessage('Fixture browser timed out.');
        $this->entityManager->flush();
        $before = $this->snapshot();
        $paths = self::getContainer()->get(EvidenceStorageInterface::class)->listPaths();

        $html = $this->request($this->studioPath($finding))->getContent();
        $xpath = $this->xpath($html);
        $images = $xpath->query('//img');
        self::assertGreaterThanOrEqual(1, $images->length);
        $image = $this->request($images->item(0)->getAttribute('src'));
        self::assertSame(200, $image->getStatusCode());
        self::assertSame('image/png', $image->headers->get('Content-Type'));
        self::assertSame(base64_decode(self::PNG, true), $image->getContent());
        self::assertStringContainsString('Fixture browser timed out.', $xpath->evaluate('string(//body)'));
        self::assertNotNull($evidence->getFilePath());
        self::assertSame($before, $this->snapshot());
        self::assertSame($paths, self::getContainer()->get(EvidenceStorageInterface::class)->listPaths());
    }

    public function testStudioActionsCoverHistoricalInconclusiveAndProtectedDecisions(): void
    {
        $new = $this->finding('new');
        $legacy = $this->finding('legacy');
        $legacy->setStatus('fixed')->setReviewState('confirmed_fixed');
        $inconclusive = $this->finding('inconclusive');
        $inconclusive->setStatus('verified')->setReviewState('manual_checking');
        $this->observation($inconclusive);
        $fixed = $this->finding('fixed');
        $confirmed = $this->finding('confirmed');
        $duplicate = $this->finding('duplicate');
        $service = self::getContainer()->get(FindingService::class);
        $service->assess($fixed, 'fixed');
        $service->assess($confirmed, 'confirmed');
        $service->assess($duplicate, 'discarded', 'duplicate');
        $this->entityManager->flush();
        $before = $this->snapshot();
        foreach ([
            [$new, ['fixed', 'discarded']],
            [$legacy, ['confirmed', 'fixed', 'discarded']],
            [$inconclusive, ['confirmed', 'fixed', 'discarded']],
            [$fixed, ['confirmed', 'discarded']],
            [$confirmed, ['fixed', 'discarded']],
            [$duplicate, ['confirmed', 'fixed']],
        ] as [$finding, $expected]) {
            $studio = $this->request($this->studioPath($finding))->getContent();
            self::assertSame($expected, $this->assessmentActions($studio));
        }
        $legacyText = $this->xpath($this->request($this->studioPath($legacy))->getContent())->evaluate('string(//body)');
        self::assertStringContainsString('Historischer Bestand', $legacyText);
        self::assertStringContainsString('Entscheidungsgrundlage unbekannt', $legacyText);
        self::assertNull($this->reload($legacy)->getManualAssessment());
        self::assertNull($this->reload($legacy)->getAssessedAt());
        self::assertSame($before, $this->snapshot());
    }

    public function testLaterInconclusiveObservationReopensConfirmationAndBasisIsNeverPreselected(): void
    {
        $finding = $this->finding('later-observation');
        $this->observation($finding);
        $evidence = $this->imageEvidence($finding);
        self::getContainer()->get(FindingService::class)->assess($finding, 'confirmed');
        $studio = $this->request($this->studioPath($finding))->getContent();
        self::assertNotContains('confirmed', $this->assessmentActions($studio));
        $finding = $this->reload($finding);
        $laterAt = $finding->getAssessedAt()->modify('+1 minute');
        $later = $this->observation($finding);
        $later->setStartedAt($laterAt)->setFinishedAt($laterAt);
        $this->entityManager->flush();
        $before = $this->snapshot();
        $html = $this->request($this->studioPath($finding))->getContent();
        self::assertContains('confirmed', $this->assessmentActions($html));
        $xpath = $this->xpath($html);
        foreach (['observation_id', 'evidence_id'] as $name) {
            $select = $xpath->query('//select[@name="'.$name.'"]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $select);
            self::assertSame(0, $xpath->query('.//option[@selected and @value!=""]', $select)->length);
            self::assertSame('', $xpath->query('.//option', $select)->item(0)->getAttribute('value'));
        }
        self::assertSame(1, $xpath->query('//select[@name="observation_id"]/option[@value="'.$later->getId().'"]')->length);
        self::assertSame(1, $xpath->query('//select[@name="evidence_id"]/option[@value="'.$evidence->getId().'"]')->length);
        self::assertSame($before, $this->snapshot());
    }

    public function testStudioAssessmentContactAndNotesReturnToStudioAndRemainVisible(): void
    {
        $finding = $this->finding('writes');
        $this->observation($finding);
        $response = $this->submit($finding, 'assessment', ['assessment' => 'confirmed']);
        $this->assertStudioRedirect($response, $finding);
        $finding = $this->reload($finding);
        self::assertSame('confirmed', $finding->getManualAssessment());
        $history = $this->entityManager->getRepository(FindingAssessment::class)->findBy(['finding' => $finding]);
        self::assertCount(1, $history);
        self::assertNull($history[0]->getObservationId());
        self::assertNull($history[0]->getEvidenceId());
        self::assertNull($history[0]->getReferenceSnapshot());

        $contactToken = $this->token($this->request($this->studioPath($finding))->getContent(), 'mark-contacted');
        $response = $this->submit($finding, 'mark-contacted', ['return_to' => '/']);
        $this->assertStudioRedirect($response, $finding);
        $finding = $this->reload($finding);
        $firstContact = $finding->getContactedAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $firstContact);
        $this->assertStudioRedirect($this->request('/findings/'.$finding->getId().'/mark-contacted', 'POST', [
            '_token' => $contactToken,
        ]), $finding);
        self::assertSame($firstContact->format(DATE_ATOM), $this->reload($finding)->getContactedAt()->format(DATE_ATOM));

        $notes = "Updated fixture note\nSecond line & <em>literal markup</em>";
        $this->assertStudioRedirect($this->submit($finding, 'notes', ['notes' => $notes]), $finding);
        $finding = $this->reload($finding);
        self::assertSame($notes, $finding->getPrivateNotes());
        self::assertSame('confirmed', $finding->getManualAssessment());
        self::assertCount(1, $this->entityManager->getRepository(FindingAssessment::class)->findBy(['finding' => $finding]));
        $detail = $this->request($this->studioPath($finding));
        self::assertSame(200, $detail->getStatusCode());
        $detailText = $this->xpath($detail->getContent())->evaluate('string(//body)');
        self::assertStringContainsString('Befund bestätigt', $detailText);
        self::assertStringContainsString($notes, $detailText);
        self::assertStringContainsString('Kontaktiert', $detailText);
    }

    public function testInvalidCsrfAndNonScalarInputsNeverWrite(): void
    {
        $finding = $this->finding('invalid-writes');
        $other = $this->finding('csrf-other');
        $otherHtml = $this->request($this->studioPath($other))->getContent();
        $before = $this->snapshot();
        foreach (['assessment', 'mark-contacted', 'notes'] as $action) {
            foreach ([null, 'forged', $this->token($otherHtml, $action), ['not-a-token']] as $token) {
                $parameters = ['assessment' => 'fixed', 'notes' => 'do not store'];
                if ($token !== null) {
                    $parameters['_token'] = $token;
                }
                self::assertSame(403, $this->request('/findings/'.$finding->getId().'/'.$action, 'POST', $parameters)->getStatusCode());
                self::assertSame($before, $this->snapshot());
            }
        }
        foreach ([
            ['assessment', ['assessment' => ['fixed']]],
            ['assessment', ['assessment' => 'fixed', 'observation_id' => []]],
            ['assessment', ['assessment' => 'fixed', 'evidence_id' => []]],
            ['notes', ['notes' => ['do not store']]],
            ['notes', []],
            ['notes', ['notes' => 'do not store', 'surface' => ['studio']]],
            ['mark-contacted', ['surface' => ['studio']]],
        ] as [$action, $parameters]) {
            self::assertSame(400, $this->submit($finding, $action, $parameters)->getStatusCode());
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testLegacySurfaceIsNotRenderedAndCannotSelectAnotherReturnTarget(): void
    {
        $finding = $this->finding('return-surface');
        $html = $this->request($this->studioPath($finding))->getContent();
        foreach (['assessment', 'mark-contacted', 'notes'] as $action) {
            $form = $this->form($html, $action);
            self::assertSame(0, $this->xpath($form)->query('//input[@name="surface"]')->length);
        }
        foreach ([null, 'classic', 'https://outside.invalid/destination', '//outside.invalid', '/arbitrary/internal/path'] as $surface) {
            $parameters = ['_token' => $this->token($html, 'notes'), 'notes' => 'surface fixture'];
            if ($surface !== null) {
                $parameters['surface'] = $surface;
            }
            $response = $this->request('/findings/'.$finding->getId().'/notes', 'POST', $parameters);
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/findings/'.$finding->getId(), parse_url($response->headers->get('Location'), PHP_URL_PATH));
            self::assertContains(parse_url($response->headers->get('Location'), PHP_URL_HOST), [null, 'localhost']);
        }
        $response = $this->request('/findings/'.$finding->getId().'/assessment', 'POST', [
            '_token' => $this->token($html, 'assessment'), 'assessment' => 'fixed',
        ]);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/findings/'.$finding->getId(), parse_url($response->headers->get('Location'), PHP_URL_PATH));
    }

    public function testDiscardingAsDuplicateRetainsArtifactsNotesAndExplicitDecisionHistory(): void
    {
        $finding = $this->finding('discard-duplicate');
        $this->imageEvidence($finding);
        $this->observation($finding);
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $paths = $storage->listPaths();
        $response = $this->submit($finding, 'assessment', ['assessment' => 'discarded', 'discard_reason' => 'duplicate']);
        $this->assertStudioRedirect($response, $finding);
        $finding = $this->reload($finding);
        self::assertSame('discarded', $finding->getManualAssessment());
        self::assertSame('duplicate', $finding->getDiscardReason());
        self::assertSame('fixture private note', $finding->getPrivateNotes());
        self::assertCount(1, $this->entityManager->getRepository(FindingAssessment::class)->findBy(['finding' => $finding]));
        self::assertCount(1, $this->entityManager->getRepository(Evidence::class)->findBy(['finding' => $finding]));
        self::assertCount(1, $this->entityManager->getRepository(RetestRun::class)->findBy(['finding' => $finding]));
        $html = $this->request($this->studioPath($finding))->getContent();
        self::assertStringContainsString('Verworfen · Duplikat', $this->xpath($html)->evaluate('string(//body)'));
        self::assertSame(0, $this->xpath($html)->query('//form[@action="/findings/'.$finding->getId().'/retest" or @action="/findings/'.$finding->getId().'/screenshots"]')->length);
        self::assertSame($paths, $storage->listPaths());
    }

    private function finding(string $label): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'localhost']);
        if (!$domain instanceof Domain) {
            $domain = (new Domain())->setHostname('localhost')->setScheme('http');
            $this->entityManager->persist($domain);
        }
        $finding = (new Finding())->setDomain($domain)->setTitle($label)->setType('other')->setSeverity('medium')
            ->setUrl('http://localhost/fixture/'.$label)->setMethod('GET')->setStatus('new')->setPrivateNotes('fixture private note');
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function observation(Finding $finding): RetestRun
    {
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('inconclusive')
            ->setStartedAt(new \DateTimeImmutable('2026-01-02T12:00:00+00:00'))
            ->setFinishedAt(new \DateTimeImmutable('2026-01-02T12:01:00+00:00'));
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function job(Finding $finding, string $status, string $date): ScreenshotJob
    {
        $job = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus($status)
            ->setRequestedAt(new \DateTimeImmutable($date));
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    private function imageEvidence(Finding $finding): Evidence
    {
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, base64_decode(self::PNG, true), 'fixture.png');
        $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($stored->relativePath);
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();

        return $evidence;
    }

    private function studioPath(Finding $finding): string
    {
        return '/findings/'.$finding->getId();
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function submit(Finding $finding, string $action, array $parameters = []): Response
    {
        $html = $this->request($this->studioPath($finding))->getContent();

        return $this->request('/findings/'.$finding->getId().'/'.$action, 'POST', $parameters + [
            '_token' => $this->token($html, $action),
        ]);
    }

    private function form(string $html, string $action): string
    {
        $xpath = $this->xpath($html);
        $form = $xpath->query('//form[substring(@action, string-length(@action) - string-length("/'.$action.'") + 1) = "/'.$action.'"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);

        return $form->ownerDocument->saveHTML($form);
    }

    private function token(string $html, string $action): string
    {
        $field = $this->xpath($this->form($html, $action))->query('//input[@name="_token"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $field);
        self::assertNotSame('', $field->getAttribute('value'));

        return $field->getAttribute('value');
    }

    private function assessmentActions(string $html): array
    {
        $values = [];
        foreach ($this->xpath($html)->query('//button[@name="assessment"] | //input[@type="submit" and @name="assessment"]') as $button) {
            $values[] = $button->getAttribute('value');
        }

        return $values;
    }

    private function assertStudioRedirect(Response $response, Finding $finding): void
    {
        self::assertSame(302, $response->getStatusCode());
        self::assertSame($this->studioPath($finding), parse_url($response->headers->get('Location'), PHP_URL_PATH));
        self::assertContains(parse_url($response->headers->get('Location'), PHP_URL_HOST), [null, 'localhost']);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    private function reload(Finding $finding): Finding
    {
        $reloaded = $this->entityManager->find(Finding::class, $finding->getId());
        self::assertInstanceOf(Finding::class, $reloaded);

        return $reloaded;
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['domain', 'finding', 'finding_assessment', 'evidence', 'retest_run', 'screenshot_job', 'setting'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $snapshot;
    }
}
