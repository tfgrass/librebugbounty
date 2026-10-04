<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StudioInventoryTest extends DatabaseTestCase
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

    public function testCanonicalSurfacesAndFormerStudioAliasesStayReadOnlyAndPreserveQueries(): void
    {
        $finding = $this->finding('canonical');
        $before = $this->snapshot();
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $paths = $storage->listPaths();
        foreach (['/', '/findings', '/findings/'.$finding->getId(), '/legacy', '/legacy/findings/'.$finding->getId(), '/legacy/settings'] as $path) {
            $response = $this->request($path);
            self::assertSame(200, $response->getStatusCode(), $path.' => '.$response->headers->get('Location'));
        }
        foreach ([
            '/studio' => '/',
            '/studio/' => '/',
            '/studio/findings' => '/findings',
            '/studio/findings/' => '/findings',
            '/studio/findings/'.$finding->getId() => '/findings/'.$finding->getId(),
            '/studio/findings/'.$finding->getId().'/' => '/findings/'.$finding->getId(),
            '/settings' => '/legacy/settings',
            '/legacy/' => '/legacy',
            '/legacy/findings/'.$finding->getId().'/' => '/legacy/findings/'.$finding->getId(),
            '/legacy/settings/' => '/legacy/settings',
        ] as $old => $canonical) {
            $query = 'message=fixture%20message&error=fixture%26diagnostic';
            $response = $this->request($old.'?'.$query);
            parse_str($query, $expectedQuery);
            $redirects = 0;
            do {
                self::assertContains($response->getStatusCode(), [301, 308], $old);
                $location = $response->headers->get('Location');
                self::assertContains(parse_url($location, PHP_URL_HOST), [null, 'localhost']);
                parse_str(parse_url($location, PHP_URL_QUERY), $actualQuery);
                self::assertEquals($expectedQuery, $actualQuery, $old);
                $response = $this->request($location);
                self::assertLessThanOrEqual(3, ++$redirects, $old.' redirect loop');
            } while ($response->isRedirection());
            self::assertSame($canonical, parse_url($location, PHP_URL_PATH), $old);
            self::assertSame(200, $response->getStatusCode(), $old);
        }
        foreach (['/operator-priority', '/operator-priority/', '/legacy/operator-priority', '/legacy/operator-priority/'] as $removedPath) {
            self::assertSame(404, $this->request($removedPath.'?days=14')->getStatusCode(), $removedPath);
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame($paths, $storage->listPaths());
    }

    public function testOldRootFilterBookmarksOpenTheInventoryWhileFeedbackKeepsTheIntake(): void
    {
        $finding = $this->finding('bookmark');
        foreach (['q=bookmark', 'domain=inventory.localhost', 'assessment=unknown', 'observation=none', 'contact=no', 'scope=all', 'legacy_status=new', 'legacyStatus=new', 'legacy_bucket=unchecked', 'legacyBucket=unchecked', 'status=new', 'bucket=unchecked', 'type=other', 'severity=high', 'exact_domain=1', 'exactDomain=1', 'page=1', 'pageSize=25'] as $query) {
            $response = $this->request('/?'.$query);
            self::assertContains($response->getStatusCode(), [301, 308]);
            self::assertSame('/findings', parse_url($response->headers->get('Location'), PHP_URL_PATH));
            self::assertSame($query, parse_url($response->headers->get('Location'), PHP_URL_QUERY));
            self::assertContains($finding->getId(), $this->ids($this->request($response->headers->get('Location'))->getContent()));
        }
        $response = $this->request('/?message=Stored&error=Fixture');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->xpath($response->getContent())->query('//form[@action="/findings"]//input[@name="url"]')->length);
        self::assertStringContainsString('Stored', $response->getContent());
        self::assertStringContainsString('Fixture', $response->getContent());
    }

    public function testStudioAndLegacyListsKeepIndependentFiltersArchivesAndEscaping(): void
    {
        $manual = $this->finding('manual');
        $auto = $this->finding('automatic')->setStatus('fixed')->setReviewState('manual_checking');
        $empty = $this->finding('empty');
        $duplicate = $this->finding('duplicate');
        $legacyDuplicate = $this->finding('old-duplicate')->setStatus('duplicate');
        $markup = '<span id="unsafe-title">Literal & "title"</span>';
        $manual->setTitle($markup);
        $this->observation($manual, 'inconclusive');
        $this->observation($auto, 'fixed');
        self::getContainer()->get(FindingService::class)->assess($manual, 'fixed');
        $manual->setContactedAt(new \DateTimeImmutable('2026-01-05'));
        self::getContainer()->get(FindingService::class)->assess($duplicate, 'discarded', 'duplicate');
        $this->entityManager->flush();
        $before = $this->snapshot();
        foreach ([
            '' => [$manual, $auto, $empty],
            'assessment=fixed&observation=inconclusive&contact=yes' => [$manual],
            'assessment=unknown&observation=fixed' => [$auto],
            'assessment=unknown&observation=none' => [$empty],
            'scope=duplicates' => [$duplicate, $legacyDuplicate],
            'status=duplicate' => [$duplicate, $legacyDuplicate],
            'scope=all' => [$manual, $auto, $empty, $duplicate, $legacyDuplicate],
            'scope=active&assessment=discarded' => [],
        ] as $query => $expected) {
            $studio = $this->request('/findings'.($query === '' ? '' : '?'.$query));
            $legacy = $this->request('/legacy'.($query === '' ? '' : '?'.$query));
            self::assertSame(200, $studio->getStatusCode());
            self::assertSame(200, $legacy->getStatusCode());
            $ids = array_map(static fn (Finding $case): string => $case->getId(), $expected);
            $this->assertIds($ids, $studio->getContent());
            $this->assertIds($ids, $legacy->getContent());
            self::assertSame(count($expected), $this->resultCount($studio->getContent()));
        }
        $html = $this->request('/findings')->getContent();
        $xpath = $this->xpath($html);
        self::assertSame(1, $xpath->query('//body[@data-studio-list]')->length);
        self::assertSame(0, $xpath->query('//*[@id="unsafe-title"]')->length);
        self::assertStringContainsString($markup, $xpath->evaluate('string(//body)'));
        self::assertStringContainsString('inconclusive', $xpath->evaluate('string(//*[@data-finding-id="'.$manual->getId().'"])'));
        self::assertStringContainsString('fixed', $xpath->evaluate('string(//*[@data-finding-id="'.$auto->getId().'"])'));
        self::assertStringNotContainsString('private inventory fixture', $html);
        self::assertSame(0, $xpath->query('//img')->length);
        self::assertSame($before, $this->snapshot());
    }

    public function testSearchCoversTitleHostnameAndFullUrlWithLiteralSqlWildcards(): void
    {
        $title = $this->finding('TitleToken');
        $host = $this->finding('host', 'unique-host.localhost');
        $url = $this->finding('url')->setUrl('http://inventory.localhost/path/UrlToken?argument=value');
        $percent = $this->finding('percent')->setTitle('Progress 100% complete');
        $underscore = $this->finding('underscore')->setTitle('Literal_under_score');
        $this->finding('decoy')->setTitle('Progress 1000 complete LiteralXunderXscore');
        $this->entityManager->flush();
        $before = $this->snapshot();
        foreach ([
            'titletOKEN' => [$title],
            'UNIQUE-HOST' => [$host],
            'urlTOKEN?argument=VALUE' => [$url],
            '100%' => [$percent],
            '_under_' => [$underscore],
            "' OR 1=1 --" => [],
            'no-such-fixture' => [],
        ] as $query => $expected) {
            foreach (['/findings', '/legacy'] as $path) {
                $response = $this->request($path.'?'.http_build_query(['q' => $query]));
                self::assertSame(200, $response->getStatusCode());
                $this->assertIds(array_map(static fn (Finding $case): string => $case->getId(), $expected), $response->getContent());
            }
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testPaginationAndDetailLinksCarryTheCompleteSelectedListContext(): void
    {
        $expected = [];
        for ($index = 0; $index < 12; $index++) {
            $finding = $this->finding('page-group-'.$index);
            self::getContainer()->get(FindingService::class)->assess($finding, 'fixed');
            $finding->setContactedAt(new \DateTimeImmutable('2026-01-05'));
            $this->observation($finding, 'inconclusive');
            $expected[] = $finding->getId();
        }
        $this->finding('page-group-excluded')->setSeverity('low');
        $this->entityManager->flush();
        $query = ['q' => 'page-group', 'scope' => 'active', 'domain' => 'inventory.localhost', 'exact_domain' => '1', 'assessment' => 'fixed', 'observation' => 'inconclusive', 'contact' => 'yes', 'legacy_status' => 'fixed', 'type' => 'other', 'severity' => 'high', 'pageSize' => '10'];
        $html = $this->request('/findings?'.http_build_query($query))->getContent();
        self::assertSame(12, $this->resultCount($html));
        self::assertCount(10, $this->ids($html));
        $next = $this->xpath($html)->query('//a[@data-page="next"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $next);
        parse_str(parse_url($next->getAttribute('href'), PHP_URL_QUERY), $nextQuery);
        self::assertEquals($query + ['page' => '2'], $nextQuery);
        $second = $this->request($next->getAttribute('href'))->getContent();
        self::assertCount(2, $this->ids($second));
        self::assertSame([], array_intersect($this->ids($html), $this->ids($second)));
        $this->assertIds($expected, $html.$second);
        foreach (['finding-filters', 'page-size-form'] as $formId) {
            $form = $this->xpath($html)->query('//form[@id="'.$formId.'"]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $form);
            self::assertSame('/findings', $form->getAttribute('action'));
            $submitted = $this->formQuery($html, $formId);
            foreach ($query as $name => $value) {
                self::assertSame($value, $submitted[$name], $formId.' '.$name);
            }
            $submitted['pageSize'] = 'all';
            $all = $this->request('/findings?'.http_build_query($submitted));
            self::assertSame(200, $all->getStatusCode());
            $this->assertIds($expected, $all->getContent());
        }
        $link = $this->xpath($second)->query('//a[starts-with(@href,"/findings/")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $link);
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $detailQuery);
        self::assertSame('/findings', parse_url($detailQuery['return_to'], PHP_URL_PATH));
        parse_str(parse_url($detailQuery['return_to'], PHP_URL_QUERY), $returnQuery);
        self::assertEquals($nextQuery, $returnQuery);
        $detail = $this->request($link->getAttribute('href'))->getContent();
        $detailLinks = [];
        foreach ($this->xpath($detail)->query('//a[@href]') as $anchor) {
            $detailLinks[] = $anchor->getAttribute('href');
        }
        self::assertContains($detailQuery['return_to'], $detailLinks);
    }

    public function testAssessmentContactAndNoteWritesPreserveOnlyValidatedStudioListContext(): void
    {
        $finding = $this->finding('return-context');
        $returnTo = '/findings?'.http_build_query(['scope' => 'all', 'q' => 'return & context', 'pageSize' => '25', 'page' => '2'], '', '&', PHP_QUERY_RFC3986);
        $tokens = [];
        foreach (['assessment' => ['assessment' => 'fixed'], 'mark-contacted' => [], 'notes' => ['notes' => 'Saved context note']] as $action => $fields) {
            $html = $this->request('/findings/'.$finding->getId().'?'.http_build_query(['return_to' => $returnTo]))->getContent();
            $xpath = $this->xpath($html);
            $form = $xpath->query('//form[@action="/findings/'.$finding->getId().'/'.$action.'"]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $form);
            self::assertSame($returnTo, $xpath->query('.//input[@name="return_to"]', $form)->item(0)->getAttribute('value'));
            $token = $xpath->query('.//input[@name="_token"]', $form)->item(0)->getAttribute('value');
            $tokens[$action] = $token;
            $response = $this->request('/findings/'.$finding->getId().'/'.$action, 'POST', $fields + ['_token' => $token, 'surface' => 'studio', 'return_to' => $returnTo]);
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/findings/'.$finding->getId(), parse_url($response->headers->get('Location'), PHP_URL_PATH));
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            self::assertSame($returnTo, $query['return_to']);
            self::assertArrayHasKey('message', $query);
        }
        $html = $this->request('/findings/'.$finding->getId())->getContent();
        $xpath = $this->xpath($html);
        $token = $xpath->query('//form[contains(@action,"/notes")]//input[@name="_token"]')->item(0)->getAttribute('value');
        foreach (['https://outside.invalid/findings', '//outside.invalid/findings', '/legacy', '/findings/other-case', '/findings?assessment=invalid', '/findings?q%5B%5D=bad', '/findings#fragment'] as $returnTo) {
            $response = $this->request('/findings/'.$finding->getId().'/notes', 'POST', ['_token' => $token, 'notes' => 'Safe destination', 'surface' => 'studio', 'return_to' => $returnTo]);
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/findings/'.$finding->getId(), parse_url($response->headers->get('Location'), PHP_URL_PATH));
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            self::assertArrayNotHasKey('return_to', $query, $returnTo);
        }
        $before = $this->snapshot();
        foreach (['assessment' => ['assessment' => 'confirmed'], 'mark-contacted' => [], 'notes' => ['notes' => 'Do not save']] as $action => $fields) {
            self::assertSame(400, $this->request('/findings/'.$finding->getId().'/'.$action, 'POST', $fields + ['_token' => $tokens[$action], 'surface' => 'studio', 'return_to' => ['/findings']])->getStatusCode());
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testBothListsRejectMalformedAndConflictingFiltersWithoutWrites(): void
    {
        $this->finding('invalid-query');
        $before = $this->snapshot();
        foreach (['q%5B%5D=bad', 'assessment=unknown-value', 'observation=unknown-value', 'contact=maybe', 'scope=archive', 'page=0', 'pageSize=13', 'status=new&legacy_status=fixed', 'exact_domain=1&exactDomain=0'] as $query) {
            foreach (['/findings', '/legacy'] as $path) {
                $response = $this->request($path.'?'.$query);
                self::assertSame(400, $response->getStatusCode(), $path.'?'.$query);
                self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
                self::assertSame($before, $this->snapshot());
            }
        }
    }

    public function testNativeIntakePostsKeepTheSelectedSurfaceAndCanonicalCaseLinks(): void
    {
        foreach (['/' => 'studio', '/legacy' => 'classic'] as $path => $surface) {
            $xpath = $this->xpath($this->request($path)->getContent());
            $form = $xpath->query('//form[@action="/findings"]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $form);
            $parameters = [
                '_token' => $xpath->query('.//input[@name="_token"]', $form)->item(0)->getAttribute('value'),
                'url' => 'http://native-'.$surface.'.localhost/fixture',
                'annotate' => 'Keep the original '.$surface.' note',
            ];
            $surfaceField = $xpath->query('.//input[@name="surface"]', $form)->item(0);
            if ($surface === 'studio') {
                self::assertInstanceOf(\DOMElement::class, $surfaceField);
                self::assertSame('studio', $surfaceField->getAttribute('value'));
                $parameters['surface'] = 'studio';
            }
            $stored = $this->request('/findings', 'POST', $parameters);
            self::assertSame(302, $stored->getStatusCode());
            $finding = $this->entityManager->getRepository(Finding::class)->findOneBy(['url' => $parameters['url']]);
            self::assertInstanceOf(Finding::class, $finding);
            self::assertSame($surface === 'studio' ? '/findings/'.$finding->getId() : '/legacy', parse_url($stored->headers->get('Location'), PHP_URL_PATH));
            $duplicate = $this->request('/findings', 'POST', array_replace($parameters, ['annotate' => 'Must not replace notes']));
            self::assertSame(302, $duplicate->getStatusCode());
            self::assertSame(($surface === 'studio' ? '/findings/' : '/legacy/findings/').$finding->getId(), parse_url($duplicate->headers->get('Location'), PHP_URL_PATH));
            $reloaded = $this->entityManager->find(Finding::class, $finding->getId());
            self::assertSame($parameters['annotate'], $reloaded->getPrivateNotes());
            self::assertSame(1, $this->entityManager->getRepository(\App\Entity\ScreenshotJob::class)->count(['finding' => $reloaded]));
            self::assertSame(0, $this->entityManager->getRepository(RetestRun::class)->count(['finding' => $reloaded]));
            self::assertSame(200, $this->request('/findings/'.$finding->getId())->getStatusCode());
        }
    }

    private function finding(string $label, string $hostname = 'inventory.localhost'): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => $hostname]);
        if (!$domain instanceof Domain) {
            $domain = (new Domain())->setHostname($hostname)->setScheme('http');
            $this->entityManager->persist($domain);
        }
        $finding = (new Finding())->setDomain($domain)->setTitle($label)->setType('other')->setSeverity('high')
            ->setUrl('http://'.$hostname.'/fixture/'.$label)->setMethod('GET')->setStatus('new')->setPrivateNotes('private inventory fixture');
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function observation(Finding $finding, string $result): void
    {
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult($result)
            ->setStartedAt(new \DateTimeImmutable('2026-01-03'))->setFinishedAt(new \DateTimeImmutable('2026-01-03'));
        $this->entityManager->persist($run);
        $this->entityManager->flush();
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

    private function ids(string $html): array
    {
        $ids = [];
        foreach ($this->xpath($html)->query('//*[@data-finding-id]') as $row) {
            $ids[] = $row->getAttribute('data-finding-id');
        }

        return $ids;
    }

    private function formQuery(string $html, string $id): array
    {
        $values = [];
        $xpath = $this->xpath($html);
        $form = $xpath->query('//form[@id="'.$id.'"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        foreach ($xpath->query('.//input[@name]', $form) as $input) {
            $values[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        foreach ($xpath->query('.//select[@name]', $form) as $select) {
            $option = $xpath->query('.//option[@selected]', $select)->item(0) ?? $xpath->query('.//option', $select)->item(0);
            $values[$select->getAttribute('name')] = $option->getAttribute('value');
        }

        return $values;
    }

    private function assertIds(array $expected, string $html): void
    {
        $actual = $this->ids($html);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
    }

    private function resultCount(string $html): int
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
