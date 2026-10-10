<?php
namespace App\Tests;

use App\Dto\ContactDiscoveryResult;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\ContactDiscoveryProviderInterface;
use App\Service\ContactDiscoveryService;
use App\Service\ContactRouteService;
use App\Service\FindingService;
use App\Service\FindingWorkPolicy;
use App\Service\FollowUpService;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

final class ContactRouteTest extends DatabaseTestCase
{
    private ContactRouteService $routes;
    private Session $session;
    protected function setUp(): void
    {
        parent::setUp();
        $this->routes = self::getContainer()->get(ContactRouteService::class);
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testManualRouteCanBeReplacedButDoesNotChangeFactsOrHistoricalMarkers(): void
    {
        $finding = $this->finding();
        $finding->setManualAssessment('confirmed', null, new \DateTimeImmutable('2026-01-01'))->setPrivateNotes('Historical notes');
        $finding->setContactedAt(new \DateTimeImmutable('2026-01-02'))->setNextDueAt(new \DateTimeImmutable('2026-11-02'));
        $this->entityManager->flush();
        $before = $this->facts();
        $this->save($finding, ['destination' => 'mailto:security@route.invalid', 'person' => 'Team', 'source' => 'Impressum', 'notes' => "First line\nSecond line"]);
        $selected = $this->routes->state($finding)['selected'];
        self::assertSame('security@route.invalid', $selected['destination']);
        self::assertSame('Team', $selected['person']);
        self::assertSame('Impressum', $selected['source']);
        self::assertSame("First line\nSecond line", $selected['notes']);
        self::assertSame(['origin' => 'manual'], $selected['provenance']);
        $this->save($finding, ['channel' => 'web', 'destination' => 'https://route.invalid/contact?topic=security', 'notes' => '0']);
        self::assertSame('web', $this->routes->state($finding)['selected']['channel']);
        self::assertSame('0', $this->routes->state($finding)['selected']['notes']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM finding_contact_route'));
        self::assertSame($before, $this->facts());
    }

    public function testSuggestionSnapshotSurvivesRefreshFailureAndExpiry(): void
    {
        $finding = $this->finding();
        $provider = new class implements ContactDiscoveryProviderInterface {
            public int $calls = 0;
            public function id(): string { return 'fixture'; }
            public function label(): string { return 'Fixture'; }
            public function discover(string $hostname): ContactDiscoveryResult {
                ++$this->calls;
                return new ContactDiscoveryResult($this->calls === 3 ? 'error' : 'found', 'https://route.invalid/.well-known/security.txt', [['channel' => 'email', 'value' => 'mailto:contact'.$this->calls.'@route.invalid']], expires: '2099-01-01T00:00:00Z');
            }
        };
        $contacts = new ContactDiscoveryService($this->db(), self::getContainer()->get(FindingWorkPolicy::class), [$provider]);
        $contacts->discover($finding, 'fixture');
        $attempt = $contacts->history($finding)[0];
        $this->routes->save($finding, ['mode' => 'suggestion', 'revision' => 'none', 'attempt_id' => $attempt['id'], 'contact_index' => '0', 'destination' => 'forged@elsewhere.invalid', 'source' => 'Forged source']);
        $selected = $this->routes->state($finding);
        self::assertSame('contact1@route.invalid', $selected['selected']['destination']);
        self::assertSame('https://route.invalid/.well-known/security.txt', $selected['selected']['source']);
        self::assertSame($attempt['id'], $selected['selected']['provenance']['attempt_id']);
        self::assertSame($attempt['fetched_at'], $selected['selected']['provenance']['fetched_at']);
        for ($i = 0; $i < 2; ++$i) {
            $this->db()->executeStatement("UPDATE contact_discovery SET fetched_at = '2000-01-01' WHERE finding_id = ?", [$finding->getId()]);
            $contacts->discover($finding, 'fixture');
            self::assertSame($selected, $this->routes->state($finding));
        }
        self::assertSame(3, $provider->calls);
        $this->db()->update('finding_contact_route', ['provenance' => json_encode(['origin' => 'suggestion', 'attempt_id' => $attempt['id'], 'provider' => 'fixture', 'fetched_at' => $attempt['fetched_at'], 'expires' => '2000-01-01T00:00:00Z'])], ['finding_id' => $finding->getId()]);
        $response = $this->request('/findings/'.$finding->getId());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Die gewählte Quelle ist inzwischen abgelaufen.', $response->getContent());
        self::assertSame('contact1@route.invalid', $this->routes->state($finding)['selected']['destination']);
    }

    public function testInvalidAndForeignSuggestionsCannotReplaceSelection(): void
    {
        $finding = $this->finding(); $other = $this->finding('/other');
        $this->save($finding);
        $before = $this->routes->state($finding);
        $foreign = $this->attempt($other, 'found');
        foreach ([[$foreign, '0'], [$this->attempt($finding, 'expired'), '0'], [$this->attempt($finding, 'error'), '0'], [$this->attempt($finding, 'found', '2000-01-01T00:00:00Z'), '0'], [$this->attempt($finding, 'found'), '99'], [$this->attempt($finding, 'found'), '-1'], [$this->attempt($finding, 'found'), '0 OR 1']] as [$id, $index]) {
            try { $this->routes->save($finding, ['mode' => 'suggestion', 'revision' => $before['revision'], 'attempt_id' => $id, 'contact_index' => $index]); self::fail('Invalid suggestion accepted'); }
            catch (\InvalidArgumentException) {}
            self::assertSame($before, $this->routes->state($finding));
        }
    }

    public function testMalformedManualInputsCannotWrite(): void
    {
        $finding = $this->finding();
        foreach ([['destination' => 'bad'], ['destination' => "security@route.invalid\r\nBcc: other@route.invalid"], ['channel' => 'web', 'destination' => 'javascript:alert(1)'], ['channel' => 'web', 'destination' => 'https://user:password@route.invalid/form'], ['channel' => 'web', 'destination' => 'file:///etc/passwd'], ['channel' => 'smtp'], ['person' => []], ['source' => "\0"], ['notes' => str_repeat('a', 4001)], ['destination' => []], ['revision' => []], ['mode' => []], ['person' => "\xff"], ['notes' => "a\x01b"]] as $override) {
            try { $this->routes->save($finding, array_replace(['mode' => 'manual', 'revision' => 'none', 'channel' => 'email', 'destination' => 'security@route.invalid'], $override)); self::fail('Invalid manual input accepted'); }
            catch (\InvalidArgumentException) {}
            self::assertNull($this->routes->state($finding)['selected']);
        }
        foreach (['http://route.invalid/report', 'https://route.invalid/report'] as $url) {
            $this->save($finding, ['channel' => 'web', 'destination' => $url]);
            self::assertSame($url, $this->routes->state($finding)['selected']['destination']);
        }
    }

    public function testNativeFormsCsrfEscapingAndStaleTabs(): void
    {
        $finding = $this->finding(); $path = '/findings/'.$finding->getId();
        $before = $this->facts();
        $response = $this->request($path);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(405, $this->request($path.'/contact-route')->getStatusCode());
        self::assertSame(403, $this->request($path.'/contact-route', 'POST', ['_token' => 'wrong'])->getStatusCode());
        $post = $this->form($response) + ['channel' => 'email', 'destination' => 'security@route.invalid', 'person' => '<script>alert(1)</script>', 'source' => 'manual <img>', 'notes' => '<iframe>'];
        $response = $this->request($path.'/contact-route', 'POST', $post + ['return_to' => 'https://outside.invalid/']);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringStartsWith($path.'?message=', $response->headers->get('Location'));
        self::assertStringEndsWith('#kontakte', $response->headers->get('Location'));
        $chosen = $this->routes->state($finding);
        $response = $this->request($path.'/contact-route', 'POST', array_replace($post, ['destination' => 'new@route.invalid']));
        self::assertStringContainsString('?error=', $response->headers->get('Location'));
        self::assertSame($chosen, $this->routes->state($finding));
        $html = $this->request($path)->getContent();
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<iframe>', $html);
        self::assertSame($before, $this->facts());
    }

    public function testLocalContactBookkeepingIsAllowedWhenExternalWorkIsBlockedAndDeletionCleansIt(): void
    {
        $finding = $this->finding();
        $policy = self::getContainer()->get(FollowUpService::class);
        $policy->save($finding, ['scope' => 'domain', 'contact_blocked' => '1', 'checks_blocked' => '1']);
        $policy->save($finding, ['pursuit' => 'closed', 'reason' => 'declined']);
        $this->save($finding);
        self::assertTrue($policy->state($finding)['contactBlocked']);
        self::assertTrue($policy->state($finding)['checksBlocked']);
        $this->db()->executeStatement('PRAGMA foreign_keys = OFF');
        self::getContainer()->get(FindingService::class)->deleteFinding($finding);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM finding_contact_route'));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM domain_work_restriction'));
    }

    public function testAdditiveMigrationAndMissingSchemaGracefulFallback(): void
    {
        $finding = $this->finding(); $before = $this->facts();
        $this->db()->executeStatement('DROP TABLE finding_contact_route');
        self::assertFalse($this->routes->state($finding)['available']);
        self::assertSame(200, $this->request('/findings/'.$finding->getId())->getStatusCode());
        try { $this->save($finding); self::fail('Missing schema accepted'); } catch (\LogicException) {}
        require_once dirname(__DIR__).'/migrations/Version20261010010000.php';
        $migration = new \DoctrineMigrations\Version20261010010000($this->db(), new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) $this->db()->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        self::assertTrue($this->routes->state($finding)['available']);
        self::assertNull($this->routes->state($finding)['selected']);
        self::assertSame([], (new SchemaTool($this->entityManager))->getUpdateSchemaSql($this->entityManager->getMetadataFactory()->getAllMetadata()));
        self::assertSame($before, $this->facts());
        $this->expectException(\Doctrine\Migrations\Exception\AbortMigration::class);
        $migration->down(new Schema());
    }

    private function save(Finding $finding, array $override = []): void { $this->routes->save($finding, array_replace(['mode' => 'manual', 'revision' => $this->routes->state($finding)['revision'], 'channel' => 'email', 'destination' => 'security@route.invalid'], $override)); }
    private function finding(string $path = '/case'): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'route.invalid']);
        if (!$domain) { $domain = (new Domain())->setHostname('route.invalid'); $this->entityManager->persist($domain); }
        $finding = (new Finding())->setDomain($domain)->setType('stored-case')->setTitle('Contact fixture')->setUrl('https://route.invalid'.$path);
        $this->entityManager->persist($finding); $this->entityManager->flush(); return $finding;
    }
    private function attempt(Finding $finding, string $status, ?string $expires = '2099-01-01T00:00:00Z'): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->db()->insert('contact_discovery', ['id' => $id, 'finding_id' => $finding->getId(), 'provider' => 'fixture', 'fetched_at' => '2026-10-10 00:00:00', 'result' => json_encode(['status' => $status, 'source' => 'https://route.invalid/security.txt', 'contacts' => [['channel' => 'web', 'value' => 'https://route.invalid/report']], 'expires' => $expires])]);
        return $id;
    }
    private function db(): \Doctrine\DBAL\Connection { return $this->entityManager->getConnection(); }
    private function facts(): array
    {
        $rows = []; foreach (['finding', 'domain', 'finding_assessment', 'screenshot_job', 'evidence', 'retest_run'] as $table) $rows[$table] = $this->db()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid'); return $rows;
    }
    private function request(string $path, string $method = 'GET', array $input = []): Response
    {
        $request = Request::create($path, $method, $input); $request->setSession($this->session);
        $response = self::$kernel->handle($request); self::$kernel->terminate($request, $response); return $response;
    }
    private function form(Response $response): array
    {
        $doc = new \DOMDocument(); @$doc->loadHTML($response->getContent()); $xp = new \DOMXPath($doc); $data = [];
        self::assertSame(1, $xp->query('//form[@data-contact-route-manual]')->length);
        foreach ($xp->query('//form[@data-contact-route-manual]//input[@type="hidden"]') as $input) $data[$input->getAttribute('name')] = $input->getAttribute('value');
        return $data;
    }
}
