<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\InventoryViewService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

final class InventoryViewsTest extends DatabaseTestCase
{
    private Session $session;
    private InventoryViewService $views;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);
        $capture = $this->createMock(BrowserScreenshotClientInterface::class);
        $capture->expects(self::never())->method('capture');
        $capture->expects(self::never())->method('waitUntilReady');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $capture);
        $this->views = self::getContainer()->get(InventoryViewService::class);
    }

    public function testNormalizationRetainsEveryFilterButNotPagingOrFeedback(): void
    {
        $raw = ['q' => ' words ', 'domain' => 'views.invalid', 'exactDomain' => '1', 'assessment' => 'confirmed',
            'observation' => 'inconclusive', 'contact' => 'no', 'scope' => 'all', 'status' => 'new',
            'legacyBucket' => 'unchecked', 'legacy_review' => 'manually_checked', 'type' => 'other', 'severity' => 'high',
            'event' => 'contacted', 'from' => '2026-01-01', 'to' => '2026-10-10', 'tld' => '.DE', 'sent' => 'yes',
            'page' => '4', 'pageSize' => '100', 'message' => 'Never retain', 'return_to' => 'https://elsewhere.invalid'];
        $query = $this->views->normalize($raw);
        self::assertSame(['scope' => 'all', 'q' => 'words', 'domain' => 'views.invalid', 'assessment' => 'confirmed',
            'observation' => 'inconclusive', 'contact' => 'no', 'legacy_status' => 'new', 'legacy_bucket' => 'unchecked',
            'legacy_review' => 'manually_checked', 'type' => 'other', 'severity' => 'high', 'exact_domain' => '1',
            'event' => 'contacted', 'from' => '2026-01-01', 'to' => '2026-10-10', 'tld' => '.de', 'sent' => 'yes'], $query);
        self::assertSame($query, $this->views->normalize(array_reverse($query, true)));
        self::assertSame(['scope' => 'active'], $this->views->normalize(['exact_domain' => '1', 'q' => ' ', 'pageSize' => '25']));
        $id = $this->views->create('Full combination', $raw);
        $stored = $this->views->all()[0];
        self::assertSame($id, $stored['id']);
        self::assertSame($query, $stored['query']);
        self::assertSame(200, $this->request($stored['url'])->getStatusCode());
        self::assertStringNotContainsString('page', $stored['url']);
    }

    public function testNativeSaveRenameDeleteOnlyChangeTheirOwnPreference(): void
    {
        $this->finding('Alpha');
        $before = $this->caseSnapshot();
        $response = $this->request('/findings?q=Alpha&contact=no&pageSize=25&page=2&message=old');
        $form = $this->form($response, '//form[@data-view-create]');
        $form['name'] = '  Waiting for contact  ';
        self::assertSame(['scope' => 'active', 'q' => 'Alpha', 'contact' => 'no'], json_decode($form['filters'], true));
        $saved = $this->request('/inventory-views', 'POST', $form);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/findings', parse_url($saved->headers->get('Location'), PHP_URL_PATH));
        self::assertStringContainsString('no-store', $saved->headers->get('Cache-Control'));
        $row = $this->views->all()[0];
        self::assertSame('Waiting for contact', $row['name']);
        $otherId = $this->views->create('Other tab', ['contact' => 'yes']);
        $html = $this->request($saved->headers->get('Location'));
        $rename = $this->form($html, '//*[@data-saved-view="'.$row['id'].'"]//form[@data-view-rename]');
        $rename['name'] = 'Renamed';
        self::assertSame(303, $this->request('/inventory-views/'.$row['id'].'/rename', 'POST', $rename)->getStatusCode());
        $this->entityManager->clear();
        $rows = array_column($this->views->all(), null, 'id');
        self::assertSame('Renamed', $rows[$row['id']]['name']);
        self::assertSame($row['query'], $rows[$row['id']]['query']);
        self::assertSame('Other tab', $rows[$otherId]['name']);
        $delete = $this->form($html, '//*[@data-saved-view="'.$row['id'].'"]//form[@data-view-delete]');
        self::assertSame(303, $this->request('/inventory-views/'.$row['id'].'/delete', 'POST', $delete)->getStatusCode());
        self::assertSame([$otherId], array_column($this->views->all(), 'id'));
        self::assertSame($before, $this->caseSnapshot());
        self::assertSame(404, $this->request('/inventory-views/'.$row['id'].'/delete', 'POST', $delete)->getStatusCode());
        self::assertSame(404, $this->request('/inventory-views/'.$row['id'].'/rename', 'POST', $rename)->getStatusCode());
        self::assertSame([$otherId], array_column($this->views->all(), 'id'));
    }

    public function testOpeningSavedFiltersShowsLiveDataWithoutChangingSavedPreferences(): void
    {
        $this->finding('Alpha');
        $id = $this->views->create('Matching', ['q' => 'Alpha']);
        $url = $this->views->all()[0]['url'];
        self::assertSame('1', $this->xpath($this->request($url))->evaluate('string(//*[@data-total-filtered]/@data-total-filtered)'));
        $this->finding('Alpha newer');
        $before = $this->snapshot();
        self::assertSame('2', $this->xpath($this->request($url))->evaluate('string(//*[@data-total-filtered]/@data-total-filtered)'));
        foreach ([$url, '/findings?q=changed', '/findings?scope=all', '/findings?q=Alpha&pageSize=25&page=1'] as $path) {
            self::assertSame(200, $this->request($path)->getStatusCode());
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame(['scope' => 'active', 'q' => 'Alpha'], $this->views->all()[0]['query']);
        self::assertSame($id, $this->views->all()[0]['id']);
    }

    public function testPersistenceIsIndependentOfSessionAndDoesNotFlushPendingFindings(): void
    {
        $case = $this->finding('Retained');
        $before = $this->caseSnapshot();
        $case->setPrivateNotes('Not yet saved');
        $id = $this->views->create('Saved', ['contact' => 'no']);
        $this->views->rename($id, 'Renamed');
        $this->views->all();
        self::assertSame($before, $this->caseSnapshot());
        $this->session = new Session(new MockArraySessionStorage());
        self::assertSame(1, $this->xpath($this->request('/findings'))->query('//*[@data-saved-view="'.$id.'"]')->length);
        $this->views->delete($id);
        self::assertSame($before, $this->caseSnapshot());
    }

    public function testTokensAreRequiredAndBoundToActionAndView(): void
    {
        $id = $this->views->create('Protected', []);
        $page = $this->request('/findings');
        $create = $this->form($page, '//form[@data-view-create]');
        $delete = $this->form($page, '//form[@data-view-delete]');
        $before = $this->snapshot();
        foreach (['/inventory-views', '/inventory-views/'.$id.'/rename', '/inventory-views/'.$id.'/delete'] as $path) {
            self::assertSame(405, $this->request($path)->getStatusCode());
            foreach ([[], ['_token' => []], ['_token' => 'invalid']] as $form) {
                self::assertSame(403, $this->request($path, 'POST', $form)->getStatusCode());
            }
        }
        self::assertSame(403, $this->request('/inventory-views/'.$id.'/delete', 'POST', $create)->getStatusCode());
        self::assertSame(403, $this->request('/inventory-views/'.Uuid::v7()->toRfc4122().'/delete', 'POST', $delete)->getStatusCode());
        self::assertSame($before, $this->snapshot());
    }

    public function testInvalidNamesAndFiltersCannotWriteOrChangeCases(): void
    {
        $base = $this->form($this->request('/findings'), '//form[@data-view-create]');
        $base['name'] = 'Valid name';
        $before = $this->snapshot();
        $invalid = [['name' => ''], ['name' => " \u{2003}"], ['name' => str_repeat('ä', 81)], ['name' => "A\nB"], ['name' => ['name']],
            ['filters' => '{'], ['filters' => 'null'], ['filters' => 'true'], ['filters' => []], ['filters' => str_repeat('x', 16385)],
            ['filters' => '{"assessment":"unsupported"}'], ['filters' => '{"unknown_filter":"unsupported"}'], ['filters' => '{"q":[]}'], ['filters' => '{"from":"2026-02-30","event":"reported"}'],
            ['filters' => '{"from":"2026-01-01"}'], ['filters' => '{"status":"new","legacy_status":"fixed"}'], ['filters' => json_encode(['q' => str_repeat('a', 9000)])]];
        foreach ($invalid as $changes) {
            $response = $this->request('/inventory-views', 'POST', array_replace($base, $changes));
            self::assertSame(303, $response->getStatusCode());
            self::assertStringContainsString('error=', $response->headers->get('Location'));
        }
        self::assertSame($before, $this->snapshot());
        $id = $this->views->create(str_repeat('ä', 80), []);
        self::assertSame(str_repeat('ä', 80), $this->views->all()[0]['name']);
        $rename = $this->form($this->request('/findings'), '//form[@data-view-rename]');
        $rename['name'] = '';
        self::assertStringContainsString('error=', $this->request('/inventory-views/'.$id.'/rename', 'POST', $rename)->headers->get('Location'));
        self::assertSame(str_repeat('ä', 80), $this->views->all()[0]['name']);
    }

    public function testNamesAndSearchesAreLiteralAndReturnPathsStayInventoryLocal(): void
    {
        $literal = '<span id="untrusted-view">Text & "quotes"</span>';
        $id = $this->views->create($literal, ['q' => '</script><script id="untrusted-search">text</script>']);
        $page = $this->request('/findings');
        $xpath = $this->xpath($page);
        self::assertSame(0, $xpath->query('//*[@id="untrusted-view" or @id="untrusted-search"]')->length);
        self::assertSame($literal, $xpath->evaluate('string(//*[@data-saved-view="'.$id.'"]//a[@data-view-open])'));
        $form = $this->form($page, '//form[@data-view-rename]');
        foreach (['https://elsewhere.invalid', '//elsewhere.invalid', '/review?kind=all', '/errors?kind=technical', '/findings?q[]=bad', ['invalid']] as $return) {
            $form['return_to'] = $return;
            $response = $this->request('/inventory-views/'.$id.'/rename', 'POST', $form);
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/findings', parse_url($response->headers->get('Location'), PHP_URL_PATH));
            self::assertNull(parse_url($response->headers->get('Location'), PHP_URL_HOST));
        }
    }

    public function testRecentConfigurationExcludesPagingFeedbackAndPlainInventory(): void
    {
        $before = $this->snapshot();
        foreach (['/findings' => false, '/findings?q=alpha' => true, '/findings?scope=discarded' => true,
            '/findings?q=alpha&page=2' => false, '/findings?q=alpha&page=1&pageSize=25' => false,
            '/findings?q=alpha&message=done' => false, '/findings?q=alpha&error=failed' => false] as $path => $record) {
            $xpath = $this->xpath($this->request($path));
            $config = json_decode($xpath->evaluate('string(//script[@id="inventory-view-data"])'), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame($record, $config['record'], $path);
            self::assertArrayNotHasKey('page', $config['query']);
            self::assertArrayNotHasKey('pageSize', $config['query']);
            self::assertArrayNotHasKey('message', $config['query']);
            foreach ($xpath->query('//a[@data-scope]') as $link) {
                parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
                self::assertArrayNotHasKey('page', $query, 'Changing scope applies filters; it is not pagination.');
            }
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame(400, $this->request('/findings?assessment=unsupported')->getStatusCode());
        self::assertSame(400, $this->request('/findings?q=%FF')->getStatusCode());
    }

    public function testUnreadableSavedPreferencesStayVisibleAndDeletable(): void
    {
        $ids = [];
        foreach (['{', '{"version":2,"name":"Future","filters":{}}', '{"version":1,"name":"Old filter","filters":{"removed_field":"unknown"}}'] as $value) {
            $id = Uuid::v7()->toRfc4122();
            $ids[] = $id;
            $this->entityManager->getConnection()->insert('setting', ['id' => 'inventory.saved_view.'.$id, 'value' => $value, 'updated_at' => '2026-10-10 12:00:00']);
        }
        $this->entityManager->getConnection()->insert('setting', ['id' => 'inventory.savedXview.'.Uuid::v7()->toRfc4122(), 'value' => '{}', 'updated_at' => '2026-10-10 12:00:00']);
        $before = $this->snapshot();
        self::assertCount(3, $this->views->all());
        $page = $this->request('/findings');
        self::assertSame(0, $this->xpath($page)->query('//a[@data-view-open]')->length);
        self::assertSame(3, $this->xpath($page)->query('//form[@data-view-delete]')->length);
        self::assertSame($before, $this->snapshot());
        foreach ($ids as $id) {
            $form = $this->form($page, '//*[@data-saved-view="'.$id.'"]//form[@data-view-delete]');
            self::assertSame(303, $this->request('/inventory-views/'.$id.'/delete', 'POST', $form)->getStatusCode());
        }
        self::assertSame([], $this->views->all());
    }

    private function finding(string $title): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'views.invalid']);
        if (!$domain) {
            $domain = (new Domain())->setHostname('views.invalid');
            $this->entityManager->persist($domain);
        }
        $finding = (new Finding())->setDomain($domain)->setTitle($title)->setType('stored-case')->setUrl('https://views.invalid/'.rawurlencode($title));
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

    private function xpath(Response $response): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($document);
    }

    private function form(Response $response, string $selector): array
    {
        $xpath = $this->xpath($response);
        $form = $xpath->query($selector)->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        $values = [];
        foreach ($xpath->query('.//input[@name]', $form) as $input) {
            $values[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        return $values;
    }

    private function caseSnapshot(): array
    {
        $snapshot = [];
        foreach (['domain', 'finding', 'finding_assessment', 'finding_review_acknowledgement', 'finding_assessment_reset', 'retest_run', 'screenshot_job', 'evidence'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }
        return $snapshot;
    }

    private function snapshot(): array
    {
        return $this->caseSnapshot() + ['setting' => $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM setting ORDER BY id')];
    }
}
