<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\RetestRun;
use App\Service\BrowserRetestClientInterface;
use App\Service\RetestService;
use App\Tests\Support\ReviewBrowserTransportStub;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class FindingAssessmentWebTest extends DatabaseTestCase
{
    private Session $session;
    private ReviewBrowserTransportStub $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
        $this->browser = new ReviewBrowserTransportStub(['inconclusive']);
        self::getContainer()->set(BrowserRetestClientInterface::class, $this->browser);
    }

    public function testInconclusiveVerifiedCaseCanBeConfirmedWithUnknownBasisAndReadOnlyDetail(): void
    {
        $finding = $this->finding('inconclusive');
        $finding->setStatus('verified')->setReviewState('manual_checking');
        $this->observation($finding);
        $before = $this->snapshot();
        $page = $this->request('/findings/'.$finding->getId());
        self::assertSame(200, $page->getStatusCode());
        $html = $page->getContent();
        self::assertStringContainsString('name="assessment" value="confirmed"', $html);
        self::assertStringContainsString('Ein uneindeutiges Ergebnis bedeutet keine Behebung.', $html);
        self::assertStringNotContainsString(' selected', $this->form($html, 'assessment'));
        self::assertSame($before, $this->snapshot());

        $response = $this->assess($finding, 'confirmed');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/findings/'.$finding->getId(), parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $finding = $this->reload($finding);
        self::assertSame('confirmed', $finding->getManualAssessment());
        self::assertNull($finding->getContactedAt());
        $history = $this->history($finding);
        self::assertCount(1, $history);
        self::assertSame('manual', $history[0]->getSource());
        self::assertNull($history[0]->getObservationId());
        self::assertNull($history[0]->getEvidenceId());
        self::assertNull($history[0]->getReferenceSnapshot());
        $updated = $this->request('/findings/'.$finding->getId())->getContent();
        self::assertStringContainsString('Befund bestätigt', $updated);
        self::assertStringContainsString('Unbekannt / keine konkrete Beobachtung', $updated);
        self::assertStringContainsString('Unbekannt / kein konkreter Beleg', $updated);
        self::assertStringNotContainsString('name="assessment" value="confirmed"', $updated);
        self::assertSame([], $this->browser->browserCalls);
    }

    public function testNewTechnicalObservationShowsDatedHintAndKeepsManualJudgment(): void
    {
        $finding = $this->finding('fixed');
        self::assertSame(302, $this->assess($finding, 'fixed')->getStatusCode());
        $finding = $this->reload($finding);
        $assessedAt = $finding->getAssessedAt();
        self::getContainer()->get(RetestService::class)->retest($finding);
        self::assertSame(['chromium'], $this->browser->browserCalls);
        self::assertSame('fixed', $finding->getManualAssessment());
        self::assertSame($assessedAt->format('Y-m-d H:i:s'), $finding->getAssessedAt()->format('Y-m-d H:i:s'));
        self::assertCount(1, $this->history($finding));
        $before = $this->snapshot();
        $html = $this->request('/findings/'.$finding->getId())->getContent();
        self::assertStringContainsString('<strong>Behoben</strong>', $html);
        self::assertStringContainsString('Technische Beobachtung', $html);
        self::assertStringContainsString('Neue Widersprüche, Unklarheiten oder Fehler sind noch nicht gesichtet.', $html);
        self::assertStringContainsString('Die Bewertung bleibt erhalten.', $html);
        self::assertStringContainsString('name="assessment" value="confirmed"', $html);
        self::assertStringNotContainsString('name="assessment" value="fixed"', $html);
        self::assertSame($before, $this->snapshot());
    }

    public function testDiscardedDuplicateRetainsNotesAndEvidenceAndBlocksNormalRechecks(): void
    {
        $finding = $this->finding('duplicate');
        $evidence = (new Evidence())->setFinding($finding)->setKind('note')->setValue('retained fixture evidence');
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();
        self::assertSame(302, $this->assess($finding, 'discarded', ['discard_reason' => 'duplicate'])->getStatusCode());
        $finding = $this->reload($finding);
        self::assertSame('discarded', $finding->getManualAssessment());
        self::assertSame('duplicate', $finding->getDiscardReason());
        $html = $this->request('/findings/'.$finding->getId())->getContent();
        self::assertStringContainsString('Verworfen · Duplikat', $html);
        self::assertStringContainsString('fixture private note', $html);
        self::assertStringContainsString('retained fixture evidence', $html);
        self::assertStringNotContainsString('/'.$finding->getId().'/retest', $html);
        self::assertStringNotContainsString('/'.$finding->getId().'/screenshots', $html);
        $before = $this->snapshot();
        self::assertSame(409, $this->request('/findings/'.$finding->getId().'/retest', 'POST')->getStatusCode());
        self::assertSame(409, $this->request('/findings/'.$finding->getId().'/screenshots', 'POST')->getStatusCode());
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->browser->browserCalls);
    }

    public function testContactTimestampIsIndependentAndNotReplacedByRepeatedSubmission(): void
    {
        $finding = $this->finding('contact');
        $this->assess($finding, 'fixed');
        $path = '/findings/'.$finding->getId().'/mark-contacted';
        $html = $this->request('/findings/'.$finding->getId())->getContent();
        $token = $this->token($html, 'mark-contacted');
        self::assertSame(302, $this->request($path, 'POST', ['_token' => $token])->getStatusCode());
        $finding = $this->reload($finding);
        $firstContact = $finding->getContactedAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $firstContact);
        self::assertSame(302, $this->request($path, 'POST', ['_token' => $token])->getStatusCode());
        $finding = $this->reload($finding);
        self::assertSame($firstContact->format(DATE_ATOM), $finding->getContactedAt()->format(DATE_ATOM));
        self::assertSame('fixed', $finding->getManualAssessment());
        self::assertCount(1, $this->history($finding));
        $html = $this->request('/findings/'.$finding->getId())->getContent();
        self::assertStringContainsString('Kontaktiert', $html);
        self::assertStringNotContainsString($path, $html);
    }

    public function testAssessmentAndContactPostsRejectInvalidTokensAndRemovedDecisionRoutesStayGone(): void
    {
        $finding = $this->finding('csrf');
        $other = $this->finding('csrf-other');
        $otherHtml = $this->request('/findings/'.$other->getId())->getContent();
        $otherToken = $this->token($otherHtml, 'assessment');
        $before = $this->snapshot();
        foreach (['assessment', 'mark-contacted'] as $action) {
            foreach ([[], ['_token' => 'forged'], ['_token' => $otherToken]] as $parameters) {
                $response = $this->request('/findings/'.$finding->getId().'/'.$action, 'POST', $parameters + ['assessment' => 'fixed']);
                self::assertSame(403, $response->getStatusCode());
            }
        }
        foreach (['mark-vulnerable', 'confirm-fixed'] as $removedAction) {
            self::assertSame(404, $this->request('/findings/'.$finding->getId().'/'.$removedAction, 'POST')->getStatusCode());
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->browser->browserCalls);
    }

    public function testChosenBasisIsStoredAndReferencesToAnotherCaseAreRejected(): void
    {
        $finding = $this->finding('references');
        $other = $this->finding('reference-other');
        $observation = $this->observation($finding);
        $otherObservation = $this->observation($other);
        $evidence = (new Evidence())->setFinding($finding)->setKind('note')->setValue('reviewed fixture');
        $otherEvidence = (new Evidence())->setFinding($other)->setKind('note')->setValue('another fixture');
        $this->entityManager->persist($evidence);
        $this->entityManager->persist($otherEvidence);
        $this->entityManager->flush();
        $before = $this->snapshot();
        foreach ([['observation_id' => $otherObservation->getId()], ['evidence_id' => $otherEvidence->getId()]] as $foreignReference) {
            self::assertSame(400, $this->assess($finding, 'confirmed', $foreignReference)->getStatusCode());
            self::assertSame($before, $this->snapshot());
        }
        self::assertSame(302, $this->assess($finding, 'confirmed', [
            'observation_id' => $observation->getId(), 'evidence_id' => $evidence->getId(),
        ])->getStatusCode());
        $history = $this->history($finding);
        self::assertCount(1, $history);
        self::assertSame($observation->getId(), $history[0]->getObservationId());
        self::assertSame($evidence->getId(), $history[0]->getEvidenceId());
        self::assertSame('inconclusive', $history[0]->getReferenceSnapshot()['observation']['result']);
        $html = $this->request('/findings/'.$finding->getId())->getContent();
        self::assertStringContainsString('Ablage:', $html);
        self::assertStringContainsString($observation->getId(), $html);
        self::assertStringContainsString($evidence->getId(), $html);
    }

    public function testInvalidAssessmentAndDiscardReasonDoNotChangeData(): void
    {
        $finding = $this->finding('invalid');
        $before = $this->snapshot();
        foreach ([['invented', []], ['discarded', ['discard_reason' => 'guessed']], ['fixed', ['observation_id' => 'missing']], ['fixed', ['evidence_id' => []]]] as [$assessment, $parameters]) {
            self::assertSame(400, $this->assess($finding, $assessment, $parameters)->getStatusCode());
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testLegacyAssessmentIsShownWithUnknownSourceAndNoInventedDecisionDate(): void
    {
        $finding = $this->finding('legacy');
        $finding->setStatus('fixed')->setReviewState('confirmed_fixed');
        $this->entityManager->flush();
        $before = $this->snapshot();
        $html = $this->request('/findings/'.$finding->getId())->getContent();
        self::assertStringContainsString('Historischer Bestand · Herkunft und Entscheidungsgrundlage unbekannt.', $html);
        self::assertStringContainsString('Noch keine Bewertungsänderung aufgezeichnet.', $html);
        self::assertStringNotContainsString('<p class="studio-detail-hint">Manuell · ', $html);
        self::assertNull($finding->getManualAssessment());
        self::assertNull($finding->getAssessedAt());
        self::assertCount(0, $this->history($finding));
        self::assertSame($before, $this->snapshot());
    }

    public function testManualFixedCanBeCorrectedAndLaterInconclusiveCanBeConfirmedAgain(): void
    {
        $finding = $this->finding('reassessment');
        $positiveRun = $this->observation($finding)->setResult('still_vulnerable');
        $this->entityManager->flush();
        self::assertSame(302, $this->assess($finding, 'fixed')->getStatusCode());
        $finding = $this->reload($finding);
        $html = $this->request('/findings/'.$finding->getId())->getContent();
        self::assertStringContainsString('name="assessment" value="confirmed"', $html);
        self::assertSame(302, $this->assess($finding, 'confirmed', ['observation_id' => $positiveRun->getId()])->getStatusCode());
        $finding = $this->reload($finding);
        self::assertSame('confirmed', $finding->getManualAssessment());
        self::assertCount(2, $this->history($finding));
        $html = $this->request('/findings/'.$finding->getId())->getContent();
        self::assertStringNotContainsString('name="assessment" value="confirmed"', $html);

        $finding = $this->reload($finding);
        $laterAt = $finding->getAssessedAt()->modify('+1 minute');
        $laterRun = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('inconclusive')
            ->setStartedAt($laterAt)->setFinishedAt($laterAt);
        $this->entityManager->persist($laterRun);
        $this->entityManager->flush();
        $html = $this->request('/findings/'.$finding->getId())->getContent();
        self::assertStringContainsString('name="assessment" value="confirmed"', $html);
        self::assertStringContainsString('Neue Widersprüche, Unklarheiten oder Fehler sind noch nicht gesichtet.', $html);
        self::assertSame(302, $this->assess($finding, 'confirmed', ['observation_id' => $laterRun->getId()])->getStatusCode());
        self::assertCount(3, $this->history($finding));
    }

    #[DataProvider('sameSecondPreviousResults')]
    public function testNewInconclusiveInSameStoredSecondCanBeConfirmedWithoutInventingReviewedBasis(string $previousResult): void
    {
        $finding = $this->finding('same-second');
        $previousRun = $this->observation($finding);
        self::assertSame(302, $this->assess($finding, 'confirmed')->getStatusCode());
        $finding = $this->reload($finding);
        $at = $finding->getAssessedAt();
        $previousRun = $this->entityManager->find(RetestRun::class, $previousRun->getId());
        $previousRun->setStartedAt($at)->setFinishedAt($at)->setResult($previousResult);
        $this->entityManager->flush();
        $html = $this->request('/findings/'.$finding->getId())->getContent();
        if ($previousResult === 'inconclusive') {
            // A qualifying same-ID edit after the decision now reopens review;
            // a matching positive result still creates no notice.
            self::assertStringContainsString('name="assessment" value="confirmed"', $html);
        } else {
            self::assertStringNotContainsString('name="assessment" value="confirmed"', $html);
        }

        $finding = $this->reload($finding);
        $newRun = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('inconclusive')
            ->setStartedAt($at)->setFinishedAt($at);
        $this->entityManager->persist($newRun);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $finding = $this->reload($finding);
        self::assertSame($at->format(DATE_ATOM), $this->entityManager->find(RetestRun::class, $newRun->getId())->getFinishedAt()->format(DATE_ATOM));
        $html = $this->request('/findings/'.$finding->getId())->getContent();
        self::assertStringContainsString('name="assessment" value="confirmed"', $html);
        self::assertStringContainsString('Neue Widersprüche, Unklarheiten oder Fehler sind noch nicht gesichtet.', $html);
        $history = $this->history($finding);
        self::assertCount(1, $history);
        self::assertNull($history[0]->getObservationId());
        self::assertNull($history[0]->getEvidenceId());
        self::assertNull($history[0]->getReferenceSnapshot());
        self::assertSame(302, $this->assess($finding, 'confirmed')->getStatusCode());
        self::assertCount(2, $this->history($finding));
        $html = $this->request('/findings/'.$finding->getId())->getContent();
        self::assertStringNotContainsString('name="assessment" value="confirmed"', $html);
    }

    public static function sameSecondPreviousResults(): array
    {
        return [
            'stored inconclusive edited after judgment' => ['inconclusive'],
            'older positive result with same start time' => ['still_vulnerable'],
        ];
    }

    private function finding(string $label): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'localhost']);
        if (!$domain instanceof Domain) {
            $domain = (new Domain())->setHostname('localhost')->setScheme('http')->setAuthorized(true);
            $this->entityManager->persist($domain);
        }
        $finding = (new Finding())->setDomain($domain)->setTitle($label)->setType('other')
            ->setUrl('http://localhost/fixture/'.$label)->setStatus('new')->setMethod('GET')
            ->setPrivateNotes('fixture private note');
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

    private function assess(Finding $finding, string $assessment, array $parameters = []): Response
    {
        $html = $this->request('/findings/'.$finding->getId())->getContent();

        return $this->request('/findings/'.$finding->getId().'/assessment', 'POST', $parameters + [
            '_token' => $this->token($html, 'assessment'),
            'assessment' => $assessment,
        ]);
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function token(string $html, string $action): string
    {
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $this->form($html, $action), $matches));

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function form(string $html, string $action): string
    {
        self::assertSame(1, preg_match('#<form[^>]+action="/findings/[^/]+/'.preg_quote($action, '#').'"[^>]*>(.*?)</form>#s', $html, $matches));

        return $matches[1];
    }

    /** @return list<FindingAssessment> */
    private function history(Finding $finding): array
    {
        return $this->entityManager->getRepository(FindingAssessment::class)->findBy(['finding' => $finding]);
    }

    private function reload(Finding $finding): Finding
    {
        // Symfony's Doctrine request listener clears managed objects between
        // requests; assertions must inspect the newly persisted instance.
        $reloaded = $this->entityManager->find(Finding::class, $finding->getId());
        self::assertInstanceOf(Finding::class, $reloaded);

        return $reloaded;
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
