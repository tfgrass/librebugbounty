<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Service\FindingDetailService;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StudioReviewRecoveryTest extends DatabaseTestCase
{
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
        $browser = $this->createMock(BrowserRetestClientInterface::class);
        $browser->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $browser);
        $screenshots = $this->createMock(BrowserScreenshotClientInterface::class);
        $screenshots->expects(self::never())->method('capture');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $screenshots);
    }

    public function testDeferralLeavesJudgmentAndEvidenceIntactAndCanBeResumedFromDetail(): void
    {
        $finding = $this->finding();
        $page = $this->request('/review?images=all');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('data-review-open-recovery', $page->getContent());
        $form = $this->form($page, 'data-review-defer-form');
        $result = $this->request('/review/'.$finding->getId().'/defer', 'POST', $form + ['reason' => 'unreachable', 'note' => '504 gateway', 'schedule' => 'manual']);
        self::assertSame(303, $result->getStatusCode());
        self::assertStringStartsWith('/review?', $result->headers->get('Location'));
        $row = $this->disposition($finding);
        self::assertSame('deferred', $row['state']);
        self::assertSame('504 gateway', $row['note']);
        self::assertNull($row['due_at']);
        self::assertNull($row['retry_state']);
        self::assertNull($finding->getManualAssessment());
        self::assertSame('new', $finding->getStatus());
        self::assertStringNotContainsString('data-review-card ', $this->request('/review?images=all')->getContent());

        $detail = $this->request('/findings/'.$finding->getId());
        self::assertSame(200, $detail->getStatusCode());
        self::assertStringContainsString('data-review-recovery-state="deferred"', $detail->getContent());
        self::assertStringContainsString('504 gateway', $detail->getContent());
        $resume = $this->form($detail, 'data-review-resume-form');
        $result = $this->request('/review/'.$finding->getId().'/resume', 'POST', $resume);
        self::assertSame(303, $result->getStatusCode());
        self::assertSame('active', $this->disposition($finding)['state']);
        self::assertStringContainsString('data-current-id="'.$finding->getId().'"', $this->request('/review?images=all')->getContent());
    }

    public function testDeferralRejectsCsrfStaleContextAndMalformedDates(): void
    {
        $finding = $this->finding();
        $form = $this->form($this->request('/findings/'.$finding->getId()), 'data-review-defer-form');
        $path = '/review/'.$finding->getId().'/defer';
        $values = ['reason' => 'protection', 'schedule' => 'manual'];
        self::assertSame(403, $this->request($path, 'POST', array_replace($form + $values, ['_token' => 'invalid']))->getStatusCode());
        self::assertNull($this->disposition($finding));
        self::assertSame(400, $this->request($path, 'POST', array_replace($form + $values, ['reason' => ['other']]))->getStatusCode());
        self::assertSame(400, $this->request($path, 'POST', array_replace($form + $values, ['schedule' => 'date', 'due_date' => '2027-02-31']))->getStatusCode());
        self::assertNull($this->disposition($finding));
        self::assertSame(303, $this->request($path, 'POST', $form + $values)->getStatusCode());
        self::assertSame(409, $this->request($path, 'POST', $form + $values)->getStatusCode());
        self::assertSame(1, (int) $this->disposition($finding)['revision']);
    }

    public function testCalendarScheduleUsesBerlinTimeAndKeepsExactlyOnePendingAttempt(): void
    {
        $finding = $this->finding();
        $form = $this->form($this->request('/findings/'.$finding->getId()), 'data-review-defer-form');
        $day = (new \DateTimeImmutable('+10 days', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $response = $this->request('/review/'.$finding->getId().'/defer', 'POST', $form + ['reason' => 'evidence', 'schedule' => 'date', 'due_date' => $day]);
        self::assertSame(303, $response->getStatusCode());
        $expected = (new \DateTimeImmutable($day.' 09:00', new \DateTimeZone('Europe/Berlin')))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        self::assertSame($expected->format('Y-m-d H:i:s'), $this->disposition($finding)['due_at']);
        self::assertSame('pending', $this->disposition($finding)['retry_state']);
        self::assertStringContainsString('data-review-retry-state="pending"', $this->request('/findings/'.$finding->getId())->getContent());
    }

    public function testOnlyConfirmedSkipsCountAndThirdSkipPromptsOnceForTheSameState(): void
    {
        $finding = $this->finding();
        self::assertStringNotContainsString('data-review-recovery-prompt', $this->request('/review?images=all&prompt='.$finding->getId())->getContent());
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $page = $this->request('/review?images=all');
            // Reloads do not count, and the keyboard still has a plain Skip form.
            $this->request('/review?images=all');
            $form = $this->form($page, 'data-review-skip-form');
            $result = $this->request('/review/'.$finding->getId().'/skip', 'POST', $form);
            self::assertSame(303, $result->getStatusCode());
            $path = $result->headers->get('Location');
            self::assertSame($attempt === 3, str_contains($path, 'prompt='));
            self::assertSame(409, $this->request('/review/'.$finding->getId().'/skip', 'POST', $form)->getStatusCode());
            $next = $this->request($path);
            self::assertSame($attempt === 3, str_contains($next->getContent(), 'data-review-recovery-prompt'));
            self::assertNull($this->disposition($finding));
            self::assertNull($finding->getManualAssessment());
        }
        self::assertSame(3, (int) $this->entityManager->getConnection()->fetchOne('SELECT skip_count FROM finding_review_skip WHERE finding_id = ?', [$finding->getId()]));
    }

    public function testResumedManualJudgmentCanBeReviewedWithoutInventingANewObservation(): void
    {
        $finding = $this->finding()->setManualAssessment('confirmed', null, new \DateTimeImmutable('2026-10-01 12:00:00'));
        $this->entityManager->flush();
        $form = $this->form($this->request('/findings/'.$finding->getId()), 'data-review-defer-form');
        self::assertSame(303, $this->request('/review/'.$finding->getId().'/defer', 'POST', $form + ['reason' => 'protection', 'schedule' => 'manual'])->getStatusCode());
        $resume = $this->form($this->request('/findings/'.$finding->getId()), 'data-review-resume-form');
        self::assertSame(303, $this->request('/review/'.$finding->getId().'/resume', 'POST', $resume)->getStatusCode());
        $page = $this->request('/review?images=all');
        self::assertStringContainsString('data-review-resumed', $page->getContent());
        self::assertStringNotContainsString('data-review-notice>', $page->getContent());
        self::assertStringContainsString('data-review-keep', $page->getContent());
        $assessment = $this->form($page, 'data-review-form');
        self::assertSame(303, $this->request('/review/'.$finding->getId().'/assessment', 'POST', $assessment + ['assessment' => 'keep'])->getStatusCode());
        self::assertSame('reviewed', $this->disposition($finding)['state']);
        self::assertSame('confirmed', $finding->getManualAssessment());
        self::assertSame('2026-10-01 12:00:00', $finding->getAssessedAt()->format('Y-m-d H:i:s'));
        self::assertStringNotContainsString('data-review-card ', $this->request('/review?images=all')->getContent());
    }

    public function testHistoricalPocResultsRemainInHistoryWithoutDescribingTheCurrentPoc(): void
    {
        $finding = $this->finding();
        $old = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('fixed')->setStartedAt(new \DateTimeImmutable('2026-10-02'));
        $this->entityManager->persist($old);
        $finding->setPocUrl('http://localhost/recovery-new', 1);
        $this->entityManager->flush();
        $view = self::getContainer()->get(FindingDetailService::class)->get($finding->getId());
        self::assertCount(1, $view->runs);
        self::assertNull($view->assessmentState->latestRun);
        self::assertNull($view->assessmentState->latestAt);
        $current = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('error')->setStartedAt(new \DateTimeImmutable('2026-10-01'))
            ->setRawResult(['pocRevision' => 1, 'pocUrl' => $finding->getPocUrl()]);
        $this->entityManager->persist($current);
        $this->entityManager->flush();
        $view = self::getContainer()->get(FindingDetailService::class)->get($finding->getId());
        self::assertCount(2, $view->runs);
        self::assertSame($current->getId(), $view->assessmentState->latestRun->getId());
        for ($index = 0; $index < 20; ++$index) {
            $this->entityManager->persist((new RetestRun())->setFinding($finding)->setMode('browser')->setResult('fixed')->setStartedAt(new \DateTimeImmutable('2026-10-03')));
        }
        $this->entityManager->flush();
        $view = self::getContainer()->get(FindingDetailService::class)->get($finding->getId());
        self::assertCount(20, $view->runs);
        self::assertSame($current->getId(), $view->assessmentState->latestRun->getId(), 'Bounded history must not hide the current PoC observation behind old work');
    }

    private function finding(): Finding
    {
        $domain = (new Domain())->setHostname('localhost')->setScheme('http');
        $finding = (new Finding())->setDomain($domain)->setUrl('http://localhost/recovery')->setTitle('Recovery fixture')->setType('other')->setSeverity('medium')->setMethod('GET')->setStatus('new');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        return $finding;
    }

    private function disposition(Finding $finding): ?array
    {
        return $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM finding_review_disposition WHERE finding_id = ?', [$finding->getId()]) ?: null;
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        return $response;
    }

    private function form(Response $response, string $attribute): array
    {
        self::assertSame(200, $response->getStatusCode());
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $fields = [];
        foreach ((new \DOMXPath($document))->query('//form[@'.$attribute.']//input[@type="hidden"]') as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        self::assertNotEmpty($fields['_token'] ?? null);
        self::assertNotEmpty($fields['context_token'] ?? null);
        return $fields;
    }
}
