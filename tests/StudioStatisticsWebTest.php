<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StudioStatisticsWebTest extends DatabaseTestCase
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

    public function testStatisticsAndStudioNavigationReadDataWithoutMutatingStoredState(): void
    {
        $finding = $this->finding('read-only');
        $before = $this->snapshot();
        foreach (['/', '/findings', '/findings/'.$finding->getId()] as $path) {
            $response = $this->request($path);
            self::assertSame(200, $response->getStatusCode());
            self::assertGreaterThanOrEqual(1, $this->xpath($response->getContent())->query('//nav[contains(@class,"studio-workspace-nav")]//a[@href="/statistics"]')->length, $path);
        }
        foreach (['', '?period=week&anchor=2026-10-03', '?period=month&anchor=2026-10-03', '?period=year&anchor=2026-10-03&granularity=day', '?period=all&granularity=month', '?period=custom&from=2026-10-01&to=2026-10-03&granularity=week'] as $query) {
            $response = $this->request('/statistics'.$query);
            self::assertSame(200, $response->getStatusCode(), $query);
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $data = $this->payload($response->getContent());
            self::assertSame('Europe/Berlin', $data['period']['timezone']);
            self::assertArrayHasKey('reported', $data['kpis']);
            self::assertArrayHasKey('contacted', $data['kpis']);
            self::assertArrayNotHasKey('sent', $data['kpis']);
            self::assertArrayHasKey('fixed', $data['kpis']);
            if ($query === '') self::assertSame('month', $data['period']['kind']);
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testDashboardEventAndCaseTldLinksOpenPreciselyTheirCountedFindings(): void
    {
        $first = $this->finding('first', 'alpha.test')->setNotifiedOwnerAt(new \DateTimeImmutable('2026-10-02T09:00:00'))
            ->setContactedAt(new \DateTimeImmutable('2026-09-30T09:00:00'));
        $second = $this->finding('second', 'alpha.test')->setSubmittedAt(new \DateTimeImmutable('2026-10-02T10:00:00'))
            ->setManualAssessment('confirmed', null, new \DateTimeImmutable('2026-10-02T12:00:00'));
        $archived = $this->finding('archived', 'beta.invalid')->setSubmittedAt(new \DateTimeImmutable('2026-10-03T10:00:00'))
            ->setContactedAt(new \DateTimeImmutable('2026-10-03T11:00:00'))
            ->setManualAssessment('discarded', null, new \DateTimeImmutable('2026-10-03T12:00:00'));
        $old = $this->finding('old', 'gamma.example')->setSubmittedAt(new \DateTimeImmutable('2026-09-01T09:00:00'))
            ->setNotifiedOwnerAt(new \DateTimeImmutable('2026-10-03T09:00:00'))
            ->setManualAssessment('confirmed', null, new \DateTimeImmutable('2026-09-01T12:00:00'));
        $this->entityManager->persist(new FindingAssessment($first, 'fixed', null, new \DateTimeImmutable('2026-10-03T09:00:00')));
        $this->entityManager->persist(new FindingAssessment($first, 'fixed', null, new \DateTimeImmutable('2026-10-03T10:00:00')));
        $this->entityManager->flush();
        $before = $this->snapshot();
        $data = $this->payload($this->request('/statistics?period=month&anchor=2026-10-03')->getContent());
        self::assertSame(3, $data['kpis']['reported']['count']);
        self::assertArrayNotHasKey('sent', $data['kpis']);
        self::assertSame(1, $data['kpis']['contacted']['count']);
        self::assertSame(1, $data['kpis']['fixed']['count']);
        foreach (['reported', 'fixed', 'confirmed', 'contacted'] as $event) {
            $kpi = $data['kpis'][$event];
            $response = $this->request($kpi['url']);
            self::assertSame(200, $response->getStatusCode(), $event);
            self::assertSame($kpi['count'], $this->listCount($response->getContent()), $event);
        }
        foreach ($data['series'] as $bucket) {
            foreach (['reported', 'contacted', 'fixed'] as $event) {
                $response = $this->request($bucket['urls'][$event]);
                self::assertSame(200, $response->getStatusCode());
                self::assertSame($bucket[$event], $this->listCount($response->getContent()), $bucket['date'].' '.$event);
            }
        }
        foreach ($data['tlds']['cases'] as $tld) {
            $response = $this->request($tld['url']);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame($tld['count'], $this->listCount($response->getContent()), $tld['key']);
        }
        foreach ([...$data['snapshot'], ...$data['aging']] as $card) {
            $response = $this->request($card['url']);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame($card['count'], $this->listCount($response->getContent()), $card['key']);
        }
        self::assertSame(2, array_sum(array_column($data['aging'], 'count')), 'Only contact markers exclude confirmed cases from open contact work');
        $reported = $this->request($data['kpis']['reported']['url']);
        $ids = [];
        foreach ($this->xpath($reported->getContent())->query('//*[@data-finding-id]') as $row) $ids[] = $row->getAttribute('data-finding-id');
        self::assertContains($archived->getId(), $ids, 'Activity includes retained archived cases');
        self::assertNotContains($old->getId(), $ids, 'Historical delivery markers do not change the ingest date');
        self::assertSame($before, $this->snapshot());
    }

    public function testInvalidStatisticsQueriesReturn400AndDoNotChangeData(): void
    {
        $this->finding('invalid');
        $before = $this->snapshot();
        foreach ([
            'period=decade', 'period[]=month', 'anchor=2026-02-30', 'anchor[]=2026-10-03',
            'granularity=hour', 'granularity[]=day', 'period=custom&from=2026-10-03&to=2026-10-01',
            'period=custom&from=2026-02-30&to=2026-10-03', 'period=custom&from[]=2026-10-01&to=2026-10-03',
            'period=custom&from=2026-10-01%00&to=2026-10-03', 'tld=.test%00', 'tld[]=ip',
            'heatmapMetric=unknown', 'tldMeasure=unknown', 'period=custom&from=2026-10-01',
            'tld=.-', 'tld=.test-', 'tld=.'.str_repeat('a', 64), 'anchor=0000-10-03', 'anchor=9999-10-03',
        ] as $query) {
            self::assertSame(400, $this->request('/statistics?'.$query)->getStatusCode(), $query);
        }
        self::assertSame(400, $this->request('/findings?scope=all&event=reported&from=2026-10-01%00&to=2026-10-03')->getStatusCode());
        self::assertSame($before, $this->snapshot());
    }

    public function testStatisticsJsonCannotCreateExecutableMarkupOrExposePrivateCaseFields(): void
    {
        $markup = '</script><script id="unsafe-statistics">window.__statisticsFixtureExecuted=true</script>';
        $finding = $this->finding('escaping')->setTitle($markup)->setPrivateNotes($markup)->setPayload($markup);
        $this->entityManager->flush();
        $response = $this->request('/statistics?period=month&anchor=2026-10-03');
        self::assertSame(200, $response->getStatusCode());
        $data = $this->payload($response->getContent());
        self::assertSame(1, $data['kpis']['reported']['count']);
        self::assertSame(0, $this->xpath($response->getContent())->query('//script[@id="unsafe-statistics"]')->length);
        self::assertStringNotContainsString($markup, $response->getContent());
        self::assertStringNotContainsString($finding->getUrl(), json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testMarkSentRequiresCsrfIsIdempotentAndPreservesIndependentDimensionsAndReturnContext(): void
    {
        $finding = $this->finding('mark-sent')->setManualAssessment('confirmed', null, new \DateTimeImmutable('2026-10-01T10:00:00'))
            ->setContactedAt(new \DateTimeImmutable('2026-10-02T09:00:00'));
        $this->entityManager->flush();
        $return = '/findings?scope=all&event=reported&from=2026-10-01&to=2026-10-03&pageSize=25';
        $detail = $this->request('/findings/'.$finding->getId().'?'.http_build_query(['return_to' => $return]));
        $xpath = $this->xpath($detail->getContent());
        $form = $xpath->query('//form[@action="/findings/'.$finding->getId().'/mark-sent"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        $parameters = [];
        foreach ($xpath->query('.//input[@name]', $form) as $input) $parameters[$input->getAttribute('name')] = $input->getAttribute('value');
        self::assertArrayNotHasKey('surface', $parameters);
        self::assertSame($return, $parameters['return_to']);
        $before = $this->snapshot();
        $route = '/findings/'.$finding->getId().'/mark-sent';
        self::assertSame(403, $this->request($route, 'POST', ['_token' => 'invalid'])->getStatusCode());
        self::assertSame(400, $this->request($route, 'POST', array_replace($parameters, ['return_to' => ['/findings']]))->getStatusCode());
        self::assertSame($before, $this->snapshot());
        $response = $this->request($route, 'POST', $parameters);
        self::assertTrue($response->isRedirection());
        self::assertSame('/findings/'.$finding->getId(), parse_url($response->headers->get('Location'), PHP_URL_PATH));
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertSame($return, $query['return_to']);
        $after = $this->snapshot();
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$finding->getId()]);
        self::assertNotNull($row['notified_owner_at']);
        self::assertSame($before['finding'][0]['contacted_at'], $row['contacted_at']);
        foreach (['status', 'manual_assessment', 'assessed_at', 'private_notes'] as $field) self::assertSame($before['finding'][0][$field], $row[$field]);
        self::assertSame($before['screenshot_job'], $after['screenshot_job']);
        self::assertSame($before['retest_run'], $after['retest_run']);
        self::assertSame($before['finding_assessment'], $after['finding_assessment']);
        self::assertSame($before['evidence'], $after['evidence']);
        self::assertTrue($this->request($route, 'POST', $parameters)->isRedirection());
        self::assertSame($after, $this->snapshot(), 'Repeating mark-sent must keep its first date and all stored state');
    }

    public function testMarkSentOfAnUncontactedCaseDoesNotInventContactOrAssessment(): void
    {
        $finding = $this->finding('uncontacted-send');
        $route = '/findings/'.$finding->getId().'/mark-sent';
        $detail = $this->request('/findings/'.$finding->getId());
        $token = $this->xpath($detail->getContent())->evaluate('string(//form[@action="'.$route.'"]//input[@name="_token"]/@value)');
        self::assertNotSame('', $token);
        self::assertTrue($this->request($route, 'POST', ['_token' => $token])->isRedirection());
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$finding->getId()]);
        self::assertNotNull($row['notified_owner_at']);
        self::assertNull($row['contacted_at']);
        self::assertNull($row['manual_assessment']);
        self::assertSame('new', $row['status']);
        self::assertSame(0, $this->entityManager->getRepository(FindingAssessment::class)->count([]));
    }

    private function finding(string $label, string $hostname = 'statistics.test'): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => $hostname]);
        if (!$domain instanceof Domain) {
            $domain = (new Domain())->setHostname($hostname)->setScheme('http');
            $this->entityManager->persist($domain);
        }
        $finding = (new Finding())->setDomain($domain)->setTitle($label)->setType('synthetic')->setSeverity('medium')
            ->setUrl('http://127.0.0.1/statistics-fixture/'.$label)->setSubmittedAt(new \DateTimeImmutable('2026-10-01T09:00:00'))
            ->setPrivateNotes('Private statistics fixture');
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

    private function payload(string $html): array
    {
        $node = $this->xpath($html)->query('//script[@id="statistics-data" and @type="application/json"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $node);

        return json_decode($node->textContent, true, flags: JSON_THROW_ON_ERROR);
    }

    private function listCount(string $html): int
    {
        $node = $this->xpath($html)->query('//*[@data-total-filtered]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $node);

        return (int) $node->getAttribute('data-total-filtered');
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['domain', 'finding', 'finding_assessment', 'retest_run', 'screenshot_job', 'evidence', 'setting'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $snapshot;
    }
}
