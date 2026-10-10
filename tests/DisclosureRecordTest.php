<?php
namespace App\Tests;

use App\Dto\FindingReadFilter;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Repository\FindingReadRepository;
use App\Service\DisclosureRecordService;
use App\Service\FindingService;
use App\Service\InventoryViewService;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

final class DisclosureRecordTest extends DatabaseTestCase
{
    private DisclosureRecordService $records;
    private Session $session;
    protected function setUp(): void
    {
        parent::setUp(); $this->records = self::getContainer()->get(DisclosureRecordService::class);
        $this->session = new Session(new MockArraySessionStorage());
    }
    public function testActivitiesPreserveHistoricalFactsAndRepeatedSubmissionIsIdempotent(): void
    {
        $finding = $this->finding();
        $finding->setContactedAt(new \DateTimeImmutable('2026-01-01'))->setNotifiedOwnerAt(new \DateTimeImmutable('2026-01-02'))->setNextDueAt(new \DateTimeImmutable('2027-01-01'));
        $this->entityManager->flush(); $before = $this->facts();
        $input = $this->activity(['ticket' => 'SEC-1', 'comment' => "Report\nDetails"]);
        $this->records->addActivity($finding, $input); $this->records->addActivity($finding, $input);
        $this->records->addActivity($finding, $this->activity(['activity' => 'response', 'occurred_on' => '2026-02-02']));
        $this->records->addActivity($finding, $this->activity(['activity' => 'note', 'occurred_on' => '2026-02-01', 'channel' => 'internal', 'recipient' => 'Intern']));
        $history = $this->records->state($finding)['activities'];
        self::assertCount(3, $history); self::assertSame(['response', 'note', 'reported'], array_column($history, 'activity'));
        self::assertSame('SEC-1', $history[2]['ticket']); self::assertSame("Report\nDetails", $history[2]['comment']);
        try { $this->records->addActivity($finding, array_replace($input, ['comment' => 'different'])); self::fail('Conflicting retry accepted'); } catch (\InvalidArgumentException) {}
        try { $this->records->addActivity($this->finding('/other'), $input); self::fail('Foreign entry ID accepted'); } catch (\InvalidArgumentException) {}
        // Ignore only the additional fixture case, not the existing facts.
        $after = $this->facts(); self::assertSame($before['finding'][0], $after['finding'][0]);
        foreach (['finding_assessment', 'screenshot_job', 'evidence', 'retest_run'] as $table) self::assertSame($before[$table], $after[$table]);
    }
    public function testReminderReplacesCompletesAndReopensWithoutStaleTabOverwrites(): void
    {
        $finding = $this->finding(); $before = $this->facts();
        $this->saveReminder($finding, '2026-10-10', 'Wait for answer');
        $old = $this->records->state($finding);
        $this->saveReminder($finding, '2026-10-11', 'Call contact');
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM disclosure_reminder'));
        try { $this->records->reminder($finding, ['revision' => $old['revision'], 'action' => 'complete']); self::fail('Stale completion accepted'); } catch (\InvalidArgumentException) {}
        self::assertSame('Call contact', $this->records->state($finding)['reminder']['next_step']);
        $revision = $this->records->state($finding)['revision'];
        $this->records->reminder($finding, ['revision' => $revision, 'action' => 'complete']);
        self::assertNotNull($this->records->state($finding)['reminder']['completed_at']);
        $this->saveReminder($finding, '2026-10-10', 'Wait for answer');
        self::assertNull($this->records->state($finding)['reminder']['completed_at']);
        self::assertNotSame($old['revision'], $this->records->state($finding)['revision']);
        self::assertSame($before, $this->facts());
    }
    public function testTodayOverdueAndOpenFiltersAgreeAcrossCountPageAndSavedViews(): void
    {
        $today = DisclosureRecordService::today();
        $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $due = $this->finding('/today'); $late = $this->finding('/late'); $future = $this->finding('/future'); $done = $this->finding('/done'); $none = $this->finding('/none');
        foreach ([[$due, $today], [$late, $yesterday], [$future, $tomorrow], [$done, $today]] as [$finding, $day]) $this->saveReminder($finding, $day, 'Next');
        $this->records->reminder($done, ['action' => 'complete', 'revision' => $this->records->state($done)['revision']]);
        $repo = self::getContainer()->get(FindingReadRepository::class); $views = self::getContainer()->get(InventoryViewService::class);
        foreach (['today' => [$due->getId()], 'overdue' => [$late->getId()], 'open' => [$due->getId(), $late->getId(), $future->getId()]] as $value => $expected) {
            $filter = new FindingReadFilter(reminder: $value); $actual = array_map(fn ($f) => $f->id, $repo->findPage($filter));
            sort($expected); sort($actual); self::assertSame($expected, $actual); self::assertSame(count($expected), $repo->count($filter));
            $id = $views->create($value, ['reminder' => $value]);
            $view = array_values(array_filter($views->all(), fn ($v) => $v['id'] === $id))[0];
            self::assertSame(['scope' => 'active', 'reminder' => $value], $view['query']);
            self::assertSame(200, $this->request($view['url'])->getStatusCode());
            self::assertStringContainsString('name="reminder"', $this->request($view['url'])->getContent());
        }
        self::assertSame(400, $this->request('/findings?reminder=invalid')->getStatusCode());
        self::assertSame(400, $this->request('/findings?reminder[]=today')->getStatusCode());
    }
    public function testMalformedInputsNeverPartiallyWrite(): void
    {
        $finding = $this->finding();
        foreach ([['activity' => []], ['occurred_on' => '2026-02-30'], ['channel' => 'smtp'], ['recipient' => ''], ['ticket' => []], ['comment' => str_repeat('x', 4001)], ['recipient' => "\xff"], ['entry_id' => 'bad']] as $bad) {
            try { $this->records->addActivity($finding, $this->activity($bad)); self::fail('Invalid activity accepted'); } catch (\InvalidArgumentException) {}
        }
        foreach ([['due_on' => '2026-02-30'], ['next_step' => ''], ['revision' => []], ['next_step' => []], ['action' => 'delete'], ['action' => 'complete'], ['due_on' => []]] as $bad) {
            try { $this->records->reminder($finding, array_replace(['action' => 'save', 'revision' => 'none', 'due_on' => '2026-10-10', 'next_step' => 'Reply'], $bad)); self::fail('Invalid reminder accepted'); } catch (\InvalidArgumentException) {}
        }
        self::assertSame([], $this->records->state($finding)['activities']); self::assertNull($this->records->state($finding)['reminder']);
    }
    public function testNativeFormsCsrfSafeReturnEscapingAndNoGetWrites(): void
    {
        $finding = $this->finding(); $path = '/findings/'.$finding->getId(); $before = $this->facts();
        $response = $this->request($path); self::assertSame(200, $response->getStatusCode()); self::assertSame($before, $this->facts());
        self::assertSame(405, $this->request($path.'/disclosure-record')->getStatusCode());
        self::assertSame(403, $this->request($path.'/disclosure-record', 'POST', ['_token' => 'wrong'])->getStatusCode());
        $post = $this->form($response, 'data-disclosure-activity');
        $post = array_replace($this->activity(), $post, ['recipient' => '<script>Owner</script>', 'comment' => '<iframe>', 'return_to' => 'https://outside.invalid']);
        $saved = $this->request($path.'/disclosure-record', 'POST', $post);
        self::assertSame(303, $saved->getStatusCode()); self::assertStringStartsWith($path.'?message=', $saved->headers->get('Location')); self::assertStringEndsWith('#meldung', $saved->headers->get('Location'));
        $this->request($path.'/disclosure-record', 'POST', $post); self::assertCount(1, $this->records->state($finding)['activities']);
        $html = $this->request($path)->getContent(); self::assertStringContainsString('&lt;script&gt;Owner&lt;/script&gt;', $html); self::assertStringNotContainsString('<iframe>', $html);
        $form = $this->form($this->request($path), 'data-disclosure-reminder');
        $saved = $this->request($path.'/disclosure-record', 'POST', $form + ['due_on' => DisclosureRecordService::today(), 'next_step' => 'Answer']); self::assertSame(303, $saved->getStatusCode());
        self::assertSame($before, $this->facts());
    }
    public function testMigrationPreservesFactsMissingSchemaIsGracefulAndDeletionWorksWithoutForeignKeys(): void
    {
        $finding = $this->finding(); $before = $this->facts();
        foreach (['disclosure_activity', 'disclosure_reminder'] as $table) $this->db()->executeStatement('DROP TABLE '.$table);
        self::assertFalse($this->records->state($finding)['available']); self::assertSame(200, $this->request('/findings/'.$finding->getId())->getStatusCode());
        self::assertSame(0, self::getContainer()->get(FindingReadRepository::class)->count(new FindingReadFilter(reminder: 'today')));
        require_once dirname(__DIR__).'/migrations/Version20261010020000.php';
        $migration = new \DoctrineMigrations\Version20261010020000($this->db(), new NullLogger()); $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) $this->db()->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        self::assertSame([], (new SchemaTool($this->entityManager))->getUpdateSchemaSql($this->entityManager->getMetadataFactory()->getAllMetadata())); self::assertSame($before, $this->facts());
        $this->records->addActivity($finding, $this->activity()); $this->saveReminder($finding, DisclosureRecordService::today(), 'Next');
        $this->db()->executeStatement('PRAGMA foreign_keys = OFF'); self::getContainer()->get(FindingService::class)->deleteFinding($finding);
        foreach (['disclosure_activity', 'disclosure_reminder'] as $table) self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM '.$table));
        $this->expectException(\Doctrine\Migrations\Exception\AbortMigration::class); $migration->down(new Schema());
    }
    private function activity(array $overrides = []): array { return array_replace(['entry_id' => Uuid::v7()->toRfc4122(), 'activity' => 'reported', 'occurred_on' => '2026-01-01', 'channel' => 'email', 'recipient' => 'security@record.invalid'], $overrides); }
    private function saveReminder(Finding $f, string $day, string $step): void { $this->records->reminder($f, ['action' => 'save', 'revision' => $this->records->state($f)['revision'], 'due_on' => $day, 'next_step' => $step]); }
    private function finding(string $path = '/case'): Finding
    {
        $d = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'record.invalid']); if (!$d) { $d = (new Domain())->setHostname('record.invalid'); $this->entityManager->persist($d); }
        $f = (new Finding())->setDomain($d)->setType('stored-case')->setTitle('Record fixture')->setUrl('https://record.invalid'.$path); $this->entityManager->persist($f); $this->entityManager->flush(); return $f;
    }
    private function db(): \Doctrine\DBAL\Connection { return $this->entityManager->getConnection(); }
    private function facts(): array { $rows = []; foreach (['finding', 'finding_assessment', 'screenshot_job', 'evidence', 'retest_run'] as $t) $rows[$t] = $this->db()->fetchAllAssociative('SELECT * FROM '.$t.' ORDER BY rowid'); return $rows; }
    private function request(string $path, string $method = 'GET', array $input = []): Response { $r = Request::create($path, $method, $input); $r->setSession($this->session); $response = self::$kernel->handle($r); self::$kernel->terminate($r, $response); return $response; }
    private function form(Response $response, string $attribute): array { $d = new \DOMDocument(); @$d->loadHTML($response->getContent()); $xp = new \DOMXPath($d); $data = []; self::assertSame(1, $xp->query('//form[@'.$attribute.']')->length); foreach ($xp->query('//form[@'.$attribute.']//input[@type="hidden"]') as $i) $data[$i->getAttribute('name')] = $i->getAttribute('value'); return $data; }
}
