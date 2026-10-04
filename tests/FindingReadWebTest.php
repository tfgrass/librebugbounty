<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FindingReadWebTest extends DatabaseTestCase
{
    public function testOverviewKeepsAssessmentObservationContactAndLegacyValuesIndependentAndReadOnly(): void
    {
        $cases = $this->mixedCases();
        $before = $this->snapshot();
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $paths = $storage->listPaths();
        $html = $this->get('/findings')->getContent();
        $this->sameIds([$cases['manual']->getId(), $cases['auto']->getId(), $cases['confirmed']->getId(), $cases['empty']->getId()], $this->ids($html));
        $manual = $this->row($html, $cases['manual']->getId());
        self::assertStringContainsString('Behoben', $manual);
        self::assertStringContainsString('Uneindeutig (inconclusive)', $manual);
        self::assertStringContainsString('Manuell', $manual);
        $auto = $this->row($html, $cases['auto']->getId());
        self::assertStringContainsString('Keine aufgezeichnete manuelle Bewertung', $auto);
        self::assertStringContainsString('Kein Nachweis (fixed)', $auto);
        self::assertStringContainsString('Historische Herkunft unklar', $auto);
        self::assertStringContainsString('manual_checking', $auto);
        self::assertStringContainsString('Kontaktiert', $this->row($html, $cases['confirmed']->getId()));
        self::assertStringNotContainsString('<img ', $html);
        self::assertStringNotContainsString('private fixture note', $html);
        self::assertStringNotContainsString('retained evidence fixture', $html);
        foreach ([
            '/findings?assessment=fixed' => ['manual'],
            '/findings?observation=fixed' => ['auto'],
            '/findings?assessment=unknown' => ['auto', 'empty'],
            '/findings?observation=none' => ['empty'],
            '/findings?observation=still_vulnerable&contact=yes' => ['confirmed'],
            '/findings?legacy_status=fixed&assessment=unknown' => ['auto'],
        ] as $url => $expected) {
            $this->sameIds(array_map(fn ($key) => $cases[$key]->getId(), $expected), $this->ids($this->get($url)->getContent()));
        }
        $detail = $this->get('/findings/'.$cases['auto']->getId())->getContent();
        self::assertSame(1, $this->xpath($detail)->query('//body[@data-studio-detail]')->length);
        self::assertStringContainsString('Kein Nachweis (fixed)', $detail);
        self::assertStringContainsString('Historischer Bestand · Herkunft und Entscheidungsgrundlage unbekannt', $detail);
        self::assertSame('unknown', $this->xpath($detail)->query('//*[@data-assessment]')->item(0)->getAttribute('data-assessment'));
        self::assertSame($before, $this->snapshot());
        self::assertSame($paths, $storage->listPaths());
    }

    public function testArchiveFiltersAndLegacyBookmarksRemainExplicitAndFindRetainedCases(): void
    {
        $cases = $this->mixedCases();
        foreach ([
            '/findings?scope=discarded' => ['discarded', 'duplicate', 'legacy_duplicate'],
            '/findings?scope=duplicates' => ['duplicate', 'legacy_duplicate'],
            '/findings?scope=all' => array_keys($cases),
            '/findings?scope=active&assessment=discarded' => [],
            '/findings?status=duplicate' => ['duplicate', 'legacy_duplicate'],
            '/findings?status=discarded' => ['discarded', 'duplicate', 'legacy_duplicate'],
            '/findings?status=fixed' => ['manual', 'auto'],
            '/findings?bucket=manual_review' => ['auto'],
        ] as $url => $expected) {
            $html = $this->get($url)->getContent();
            $this->sameIds(array_map(fn ($key) => $cases[$key]->getId(), $expected), $this->ids($html));
            if (str_contains($url, 'status=') || str_contains($url, 'bucket=')) {
                self::assertStringContainsString('Diagnosefilter aktiv:', $html);
            }
        }
        $html = $this->get('/findings?scope=duplicates')->getContent();
        self::assertStringContainsString('Verworfen · Duplikat', $this->row($html, $cases['duplicate']->getId()));
        self::assertStringContainsString('Keine aufgezeichnete manuelle Bewertung', $this->row($html, $cases['legacy_duplicate']->getId()));
        self::assertStringContainsString('Im Archiv · historische Kennzeichnung', $this->row($html, $cases['legacy_duplicate']->getId()));

        $query = 'scope=duplicates&pageSize=25&message=fixture%20message';
        $bookmark = $this->get('/legacy?'.$query, false);
        self::assertSame(Response::HTTP_PERMANENTLY_REDIRECT, $bookmark->getStatusCode());
        self::assertSame('/findings', parse_url($bookmark->headers->get('Location'), PHP_URL_PATH));
        parse_str($query, $expectedQuery);
        parse_str((string) parse_url($bookmark->headers->get('Location'), PHP_URL_QUERY), $actualQuery);
        self::assertEquals($expectedQuery, $actualQuery);

        $detailQuery = 'message=fixture%20message&return_to=%2Ffindings%3Fscope%3Dall';
        $detailBookmark = $this->get('/legacy/findings/'.$cases['manual']->getId().'?'.$detailQuery, false);
        self::assertSame(Response::HTTP_PERMANENTLY_REDIRECT, $detailBookmark->getStatusCode());
        self::assertSame('/findings/'.$cases['manual']->getId(), parse_url($detailBookmark->headers->get('Location'), PHP_URL_PATH));
        parse_str($detailQuery, $expectedDetailQuery);
        parse_str((string) parse_url($detailBookmark->headers->get('Location'), PHP_URL_QUERY), $actualDetailQuery);
        self::assertEquals($expectedDetailQuery, $actualDetailQuery);
    }

    public function testGlobalDashboardLinksOpenExactlyTheirCountsAndResetOtherFilters(): void
    {
        $this->mixedCases();
        $html = $this->get('/findings?scope=all&domain=absent.localhost&assessment=unknown&contact=no&observation=error&legacy_status=reported')->getContent();
        self::assertSame([], $this->ids($html));
        $expectedCounts = ['active' => 4, 'confirmed' => 1, 'fixed' => 1, 'unknown' => 2, 'inconclusive' => 1, 'unobserved' => 1, 'contacted' => 1, 'discarded' => 3, 'duplicates' => 2];
        $stats = $this->xpath($html)->query('//a[@data-stat]');
        self::assertCount(9, $stats);
        foreach ($stats as $stat) {
            $key = $stat->getAttribute('data-stat');
            self::assertSame($expectedCounts[$key], (int) $stat->getAttribute('data-count'));
            $link = $stat->getAttribute('href');
            parse_str(parse_url($link, PHP_URL_QUERY), $query);
            self::assertArrayNotHasKey('domain', $query);
            self::assertArrayNotHasKey('legacy_status', $query);
            self::assertLessThanOrEqual(2, count($query));
            $linked = $this->get($link)->getContent();
            self::assertSame($expectedCounts[$key], $this->resultCount($linked));
            self::assertCount($expectedCounts[$key], $this->ids($linked));
        }
    }

    public function testPaginationAndPageSizeSubmissionPreserveAllSelectedDimensions(): void
    {
        $expected = [];
        for ($i = 0; $i < 12; $i++) {
            $finding = $this->finding('page-'.$i);
            self::getContainer()->get(FindingService::class)->assess($finding, 'fixed');
            $finding->setContactedAt(new \DateTimeImmutable('2026-01-05'));
            $this->observation($finding, 'inconclusive', '2026-01-03');
            $expected[] = $finding->getId();
        }
        $this->finding('excluded')->setSeverity('low');
        $this->entityManager->flush();
        $before = $this->snapshot();
        $query = ['domain' => 'localhost', 'exact_domain' => '1', 'assessment' => 'fixed', 'observation' => 'inconclusive', 'contact' => 'yes', 'scope' => 'active', 'legacy_status' => 'fixed', 'type' => 'other', 'severity' => 'high', 'pageSize' => '10'];
        $html = $this->get('/findings?'.http_build_query($query))->getContent();
        self::assertSame(12, $this->resultCount($html));
        self::assertCount(10, $this->ids($html));
        $nextLink = $this->xpath($html)->query('//a[@data-page="next"]')->item(0)->getAttribute('href');
        parse_str(parse_url($nextLink, PHP_URL_QUERY), $nextQuery);
        self::assertEquals($query + ['page' => '2'], $nextQuery);
        $next = $this->get($nextLink)->getContent();
        self::assertCount(2, $this->ids($next));
        self::assertSame([], array_intersect($this->ids($html), $this->ids($next)));
        $this->sameIds($expected, [...$this->ids($html), ...$this->ids($next)]);
        $formQuery = $this->formQuery($html, 'page-size-form');
        $formQuery['pageSize'] = '25';
        foreach ($query as $name => $value) {
            self::assertSame($name === 'pageSize' ? '25' : $value, $formQuery[$name]);
        }
        $this->sameIds($expected, $this->ids($this->get('/findings?'.http_build_query($formQuery))->getContent()));
        $filterQuery = $this->formQuery($html, 'finding-filters');
        $filterQuery['pageSize'] = 'all';
        $this->sameIds($expected, $this->ids($this->get('/findings?'.http_build_query($filterQuery))->getContent()));
        self::assertSame($before, $this->snapshot());
    }

    public function testInvalidFiltersReturnPlainBadRequestWithoutChangingData(): void
    {
        $this->finding('invalid');
        $before = $this->snapshot();
        foreach (['assessment=unrecognised-text', 'observation=unrecognised', 'scope=missing', 'contact=maybe', 'status=unknown', 'bucket=invalid', 'assessment%5B%5D=fixed', 'pageSize=13', 'page=0', 'status=new&legacy_status=fixed'] as $query) {
            $response = $this->get('/findings?'.$query, false);
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
            self::assertSame($before, $this->snapshot());
        }
    }

    private function mixedCases(): array
    {
        $cases = [];
        foreach (['manual', 'auto', 'confirmed', 'empty', 'discarded', 'duplicate', 'legacy_duplicate'] as $key) {
            $cases[$key] = $this->finding($key);
        }
        $service = self::getContainer()->get(FindingService::class);
        $service->assess($cases['manual'], 'fixed');
        $service->assess($cases['confirmed'], 'confirmed');
        $service->assess($cases['discarded'], 'discarded');
        $service->assess($cases['duplicate'], 'discarded', 'duplicate');
        $cases['auto']->setStatus('fixed')->setReviewState('manual_checking');
        $cases['legacy_duplicate']->setStatus('duplicate');
        $cases['confirmed']->setContactedAt(new \DateTimeImmutable('2026-01-05'));
        $cases['empty']->setLastRetestedAt(new \DateTimeImmutable('2026-01-02'));
        $this->observation($cases['manual'], 'still_vulnerable', '2026-01-01');
        $this->observation($cases['manual'], 'inconclusive', '2026-01-03');
        $this->observation($cases['auto'], 'fixed', '2026-01-04');
        $this->observation($cases['confirmed'], 'still_vulnerable', '2026-01-02');
        $evidence = (new Evidence())->setFinding($cases['manual'])->setKind('note')->setValue('retained evidence fixture');
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();

        return $cases;
    }

    private function finding(string $label): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'localhost']);
        if (!$domain instanceof Domain) {
            $domain = (new Domain())->setHostname('localhost')->setScheme('http');
            $this->entityManager->persist($domain);
        }
        $finding = (new Finding())->setDomain($domain)->setTitle($label)->setType('other')->setSeverity('high')
            ->setUrl('http://localhost/fixture/'.$label)->setMethod('GET')->setStatus('new')->setPrivateNotes('private fixture note');
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function observation(Finding $finding, string $result, string $date): void
    {
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult($result)
            ->setStartedAt(new \DateTimeImmutable($date))->setFinishedAt(new \DateTimeImmutable($date));
        $this->entityManager->persist($run);
        $this->entityManager->flush();
    }

    private function get(string $url, bool $expectSuccess = true): Response
    {
        $request = Request::create($url);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        if ($expectSuccess) {
            self::assertSame(200, $response->getStatusCode());
        }

        return $response;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    private function ids(string $html): array
    {
        $ids = [];
        foreach ($this->xpath($html)->query('//*[@data-finding-id]') as $row) {
            $ids[] = $row->getAttribute('data-finding-id');
        }

        return $ids;
    }

    private function row(string $html, string $id): string
    {
        $row = $this->xpath($html)->query('//*[@data-finding-id="'.$id.'"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $row);

        return $row->textContent;
    }

    private function resultCount(string $html): int
    {
        return (int) $this->xpath($html)->query('//*[@data-total-filtered]')->item(0)->getAttribute('data-total-filtered');
    }

    private function formQuery(string $html, string $id): array
    {
        $values = [];
        $xpath = $this->xpath($html);
        foreach ($xpath->query('//form[@id="'.$id.'"]//input[@name]') as $input) {
            $values[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        foreach ($xpath->query('//form[@id="'.$id.'"]//select[@name]') as $select) {
            $option = $xpath->query('.//option[@selected]', $select)->item(0) ?? $xpath->query('.//option', $select)->item(0);
            $values[$select->getAttribute('name')] = $option->getAttribute('value');
        }

        return $values;
    }

    private function sameIds(array $expected, array $actual): void
    {
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
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
