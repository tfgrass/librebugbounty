<?php
namespace App\Tests;

use App\Dto\ContactDiscoveryResult;
use App\Dto\FindingReadFilter;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\ScreenshotJob;
use App\Repository\FindingRepository;
use App\Repository\FindingReadRepository;
use App\Repository\ScreenshotJobRepository;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\ContactDiscoveryProviderInterface;
use App\Service\ContactDiscoveryService;
use App\Service\FindingListService;
use App\Service\FindingService;
use App\Service\FindingWorkPolicy;
use App\Service\FollowUpService;
use App\Service\FollowUpStatisticsService;
use App\Service\InventoryViewService;
use App\Service\RecheckCatchUpService;
use App\Service\RecheckPolicy;
use App\Service\RecheckService;
use App\Service\RetestService;
use App\Service\ScreenshotQueueService;
use App\Service\StatisticsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class FollowUpTest extends DatabaseTestCase
{
    private FollowUpService $followUp;
    private Session $session;
    private ContactDiscoveryProviderInterface $provider;
    private ContactDiscoveryService $contacts;
    private ?\Closure $onReady = null;
    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
        $this->followUp = self::getContainer()->get(FollowUpService::class);
        $browser = $this->createMock(BrowserRetestClientInterface::class);
        $browser->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $browser);
        $capture = $this->createMock(BrowserScreenshotClientInterface::class);
        $capture->expects(self::never())->method('capture');
        $capture->method('waitUntilReady')->willReturnCallback(function (): void {
            if ($this->onReady === null) self::fail('Unexpected browser readiness call');
            ($this->onReady)();
        });
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $capture);
        $this->provider = new class implements ContactDiscoveryProviderInterface {
            public int $calls = 0;
            public bool $fail = false;
            public function id(): string { return 'fixture'; }
            public function label(): string { return 'Fixture'; }
            public function discover(string $hostname): ContactDiscoveryResult {
                ++$this->calls;
                if ($this->fail) throw new \RuntimeException('Secret transport details must not escape');
                return new ContactDiscoveryResult('found', 'https://'.$hostname.'/.well-known/security.txt',
                    [['channel' => 'web', 'value' => 'https://'.$hostname.'/report?x=<script>']], expires: '2099-10-10T00:00:00Z');
            }
        };
        $this->contacts = new ContactDiscoveryService($this->entityManager->getConnection(), self::getContainer()->get(FindingWorkPolicy::class), [$this->provider]);
        self::getContainer()->set(ContactDiscoveryService::class, $this->contacts);
    }

    public function testClosingIsIndependentAuditedAndIdempotent(): void
    {
        $f = $this->finding();
        $f->setManualAssessment('confirmed', null, new \DateTimeImmutable('2026-01-01'));
        $f->setPrivateNotes('Keep me')->setContactedAt(new \DateTimeImmutable('2026-02-01'));
        $this->entityManager->flush();
        $before = $this->fact($f);
        $this->followUp->save($f, ['pursuit' => 'closed', 'reason' => 'declined']);
        $after = $this->fact($f);
        $before['next_due_at'] = null;
        self::assertSame($before, $after);
        self::assertSame('closed', $this->followUp->state($f)['case']['pursuit']);
        self::assertTrue($this->followUp->state($f)['checksBlocked']);
        $this->followUp->save($f, ['pursuit' => 'closed', 'reason' => 'declined']);
        self::assertCount(1, $this->followUp->history($f));
        $this->followUp->save($f, ['pursuit' => 'active']);
        self::assertFalse($this->followUp->state($f)['checksBlocked']);
        self::assertCount(2, $this->followUp->history($f));
        self::assertSame('confirmed', $this->fact($f)['manual_assessment']);
    }

    public function testScopesAreIndependentAndRefusalReasonsSetExplicitFlags(): void
    {
        $f = $this->finding(); $other = $this->finding(); $sub = $this->finding('sub.policy.invalid');
        $this->followUp->save($f, ['pursuit' => 'closed', 'reason' => 'contact_refused']);
        self::assertTrue($this->followUp->state($f)['case']['contact_blocked']);
        self::assertFalse($this->followUp->state($other)['contactBlocked']);
        $this->followUp->save($f, ['scope' => 'domain', 'checks_blocked' => '1']);
        $this->followUp->save($f, ['pursuit' => 'active', 'contact_blocked' => '1']);
        self::assertTrue($this->followUp->state($f)['contactBlocked']);
        self::assertTrue($this->followUp->state($other)['checksBlocked']);
        self::assertFalse($this->followUp->state($sub)['checksBlocked']);
        self::assertNull($this->fact($other)['next_due_at']);
        $this->followUp->save($f, ['scope' => 'domain']);
        self::assertFalse($this->followUp->state($other)['checksBlocked']);
        self::assertTrue($this->followUp->state($f)['contactBlocked']);
        $this->followUp->save($f, ['pursuit' => 'closed', 'reason' => 'checks_refused']);
        self::assertTrue($this->followUp->state($f)['case']['checks_blocked']);
    }

    public function testDenyFiltersParkOldJobsAndAllBatchSelectorsExcludeRestrictedCases(): void
    {
        $blocked = $this->finding(); $allowed = $this->finding('allowed.invalid');
        $job = (new ScreenshotJob())->setFinding($blocked)->setUrl($blocked->getUrl())->setActiveKey($blocked->getId());
        $this->entityManager->persist($job); $this->entityManager->flush();
        $this->followUp->save($blocked, ['checks_blocked' => '1']);
        // Simulate an old worker writing a stale due date after the opt-out.
        $this->entityManager->getConnection()->executeStatement('UPDATE finding SET next_due_at = ? WHERE id = ?', ['2020-01-01', $blocked->getId()]);
        $repo = self::getContainer()->get(FindingRepository::class);
        foreach ([$repo->findDueForRetest(), $repo->findAllForBrowserRetest(), $repo->findAllWithoutScreenshotEvidence(), $repo->findDueForRecheck(new \DateTimeImmutable())] as $selection) {
            self::assertSame([$allowed->getId()], array_map(static fn ($f) => $f->getId(), $selection));
        }
        self::assertNull(self::getContainer()->get(ScreenshotQueueService::class)->processNext());
        self::assertSame('queued', $this->entityManager->getConnection()->fetchOne('SELECT status FROM screenshot_job WHERE id = ?', [$job->getId()]));
        self::assertSame(1, self::getContainer()->get(RecheckCatchUpService::class)->preview(new \DateTimeImmutable(), new \DateTimeImmutable())['eligible']);
        self::assertFalse(self::getContainer()->get(RecheckPolicy::class)->isInScope($blocked));
        $this->followUp->save($blocked, ['checks_blocked' => '0']);
        self::assertSame($job->getId(), self::getContainer()->get(ScreenshotJobRepository::class)->claimNext()?->getId());
    }

    public function testStaleEntitiesCannotBypassExecutionOrQueueGuards(): void
    {
        $f = $this->finding();
        $this->followUp->save($f, ['scope' => 'domain', 'checks_blocked' => '1']);
        foreach ([fn () => self::getContainer()->get(RetestService::class)->retest($f), fn () => self::getContainer()->get(ScreenshotQueueService::class)->enqueue($f)] as $work) {
            try { $work(); self::fail('Restricted external work must fail'); } catch (\LogicException $e) { self::assertStringContainsString('gesperrt', $e->getMessage()); }
        }
        self::assertNull(self::getContainer()->get(RecheckService::class)->claimNext());
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
    }

    public function testRestrictionDuringWorkerReadinessPreventsClaimAndCapture(): void
    {
        $f = $this->finding();
        $job = (new ScreenshotJob())->setFinding($f)->setUrl($f->getUrl())->setActiveKey($f->getId());
        $this->entityManager->persist($job); $this->entityManager->flush();
        $readyCalls = 0;
        $this->onReady = function () use ($f, &$readyCalls): void {
            ++$readyCalls;
            $this->followUp->save($f, ['checks_blocked' => '1']);
        };
        self::assertNull(self::getContainer()->get(ScreenshotQueueService::class)->processNext());
        self::assertSame('queued', $this->entityManager->getConnection()->fetchOne('SELECT status FROM screenshot_job WHERE id = ?', [$job->getId()]));
        self::assertSame(1, $readyCalls);
    }

    public function testReviewOfStoredEvidenceRemainsAvailableButTargetLinkIsHidden(): void
    {
        $f = $this->finding();
        $this->followUp->save($f, ['checks_blocked' => '1']);
        $response = $this->request('/review?images=all');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-review-checks-blocked', $response->getContent());
        self::assertStringNotContainsString('data-review-poc-open', $response->getContent());
        self::assertSame(1, self::getContainer()->get(\App\Service\ReviewQueueService::class)->get(['images' => 'all'])->total);
        $this->followUp->save($f, ['pursuit' => 'closed', 'reason' => 'not_relevant']);
        self::assertSame(0, self::getContainer()->get(\App\Service\ReviewQueueService::class)->get(['images' => 'all'])->total);
    }

    public function testProviderRegistryRejectsDuplicatesAndUnknownProviderDoesNotCreateAttempt(): void
    {
        $f = $this->finding();
        try { $this->contacts->discover($f, 'unknown'); self::fail('Unknown provider accepted'); } catch (\InvalidArgumentException) {}
        self::assertSame([], $this->contacts->history($f));
        self::assertSame(0, $this->provider->calls);
        $this->expectException(\LogicException::class);
        new ContactDiscoveryService($this->entityManager->getConnection(), self::getContainer()->get(FindingWorkPolicy::class), [$this->provider, $this->provider]);
    }

    public function testDomainOptOutSurvivesDeletionAndReimportButSubdomainsDoNotInheritIt(): void
    {
        $f = $this->finding();
        $this->contacts->discover($f, 'fixture');
        $this->followUp->save($f, ['pursuit' => 'closed', 'reason' => 'declined']);
        $this->followUp->save($f, ['scope' => 'domain', 'contact_blocked' => '1', 'checks_blocked' => '1']);
        self::getContainer()->get(FindingService::class)->deleteFinding($f);
        $db = $this->entityManager->getConnection();
        foreach (['finding_follow_up', 'contact_discovery'] as $table) self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table));
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM work_policy_event'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM domain_work_restriction'));
        self::assertTrue($this->followUp->state($this->finding())['checksBlocked']);
        self::assertFalse($this->followUp->state($this->finding('sub.policy.invalid'))['checksBlocked']);
    }

    public function testNativeFormsCsrfNoGetWritesAndSafeReturnContext(): void
    {
        $f = $this->finding(); $path = '/findings/'.$f->getId();
        $before = $this->fact($f);
        self::assertSame(200, $this->request($path)->getStatusCode());
        self::assertSame(0, $this->provider->calls);
        self::assertSame($before, $this->fact($f));
        foreach (['follow-up', 'contact-discovery'] as $action) {
            self::assertSame(405, $this->request($path.'/'.$action)->getStatusCode());
            self::assertSame(403, $this->request($path.'/'.$action, 'POST', ['_token' => 'wrong'])->getStatusCode());
        }
        $post = $this->form($this->request($path), 'case') + ['pursuit' => 'closed', 'reason' => 'declined', 'return_to' => 'https://elsewhere.invalid'];
        $response = $this->request($path.'/follow-up', 'POST', $post);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringStartsWith($path.'?message=', $response->headers->get('Location'));
        self::assertStringEndsWith('#nachverfolgung', $response->headers->get('Location'));
        $html = $this->request($path)->getContent();
        self::assertStringContainsString('data-contact-discovery-blocked', $html);
        self::assertStringNotContainsString('data-studio-retest-action', $html);
        self::assertStringNotContainsString('data-studio-screenshot-action', $html);
        self::assertSame(0, $this->provider->calls);
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM contact_discovery'));
    }

    public function testInvalidFormsDoNotPartiallyWrite(): void
    {
        $f = $this->finding();
        foreach ([['pursuit' => 'closed'], ['pursuit' => []], ['scope' => 'org'], ['checks_blocked' => 'yes'], ['reason' => '<script>'], ['contact_blocked' => ['1']]] as $input) {
            try { $this->followUp->save($f, $input); self::fail('Invalid input accepted'); } catch (\InvalidArgumentException) {}
        }
        self::assertSame([], $this->followUp->history($f));
    }

    public function testContactRequestsCoalesceKeepSourcesAndRetainSuccessfulAttemptsOnFailure(): void
    {
        $f = $this->finding();
        $path = '/findings/'.$f->getId();
        $page = $this->request($path);
        $doc = new \DOMDocument(); @$doc->loadHTML($page->getContent()); $xp = new \DOMXPath($doc);
        $token = $xp->evaluate('string(//form[@data-contact-discovery]//input[@name="_token"]/@value)');
        foreach ([1, 2] as $n) self::assertSame(303, $this->request($path.'/contact-discovery', 'POST', ['_token' => $token, 'provider' => 'fixture'])->getStatusCode());
        self::assertSame(1, $this->provider->calls);
        self::assertCount(1, $this->contacts->history($f));
        $html = $this->request($path)->getContent();
        self::assertStringContainsString('Formular oder Meldeportal', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('/report?x=<script>', $html);
        $this->entityManager->getConnection()->executeStatement("UPDATE contact_discovery SET fetched_at = '2020-01-01'");
        $this->provider->fail = true; $this->contacts->discover($f, 'fixture');
        $history = $this->contacts->history($f);
        self::assertSame(['error', 'found'], array_column(array_column($history, 'result'), 'status'));
        self::assertStringNotContainsString('Secret', json_encode($history));
    }

    public function testAllRestrictionsBlockDiscoveryAndLegacyDiscardDoesNotInventOptOut(): void
    {
        foreach ([['pursuit' => 'closed', 'reason' => 'not_relevant'], ['contact_blocked' => '1'], ['checks_blocked' => '1'], ['scope' => 'domain', 'contact_blocked' => '1']] as $n => $state) {
            $f = $this->finding('policy'.$n.'.invalid'); $this->followUp->save($f, $state);
            try { $this->contacts->discover($f, 'fixture'); self::fail('Denied lookup ran'); } catch (\LogicException) {}
        }
        $legacy = $this->finding('legacy.invalid')->setStatus('discarded'); $this->entityManager->flush();
        self::assertSame(FollowUpService::DEFAULT_CASE, $this->followUp->state($legacy)['case']);
        try { $this->contacts->discover($legacy, 'fixture'); self::fail('Discarded lookup ran'); } catch (\LogicException) {}
        self::assertSame(0, $this->provider->calls);
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM contact_discovery'));
    }

    public function testStatisticsCoverageMedianAndDrilldownsAgree(): void
    {
        $cases = [];
        foreach ([2, 4, null, -1, 'unknown'] as $days) {
            $f = $this->finding(); $cases[] = $f;
            $at = new \DateTimeImmutable('2026-01-01');
            $f->setManualAssessment('confirmed', null, $at);
            if ($days !== 'unknown') $this->entityManager->persist(new FindingAssessment($f, 'confirmed', null, $at));
            if (is_int($days)) $f->setContactedAt($at->modify(sprintf('%+d days', $days)));
        }
        $this->entityManager->flush();
        $this->followUp->save($cases[0], ['pursuit' => 'closed', 'reason' => 'declined']);
        $this->followUp->save($cases[2], ['contact_blocked' => '1']);
        $stats = self::getContainer()->get(FollowUpStatisticsService::class)->get('.invalid');
        self::assertEquals(3.0, $stats['medianDays']); self::assertSame(2, $stats['sampleSize']);
        self::assertSame(['cohort' => 5, 'missingConfirmation' => 1, 'missingContact' => 1, 'invalidOrder' => 1], $stats['coverage']);
        self::assertSame(1, $stats['closed']);
        foreach ($stats['reasons'] as $row) $this->assertDrilldown($row);
        $view = self::getContainer()->get(StatisticsService::class)->get(['anchor' => '2026-10-10']);
        foreach ($view['aging'] as $row) $this->assertDrilldown($row);
        self::assertSame(1, array_sum(array_column($view['aging'], 'count')));
        self::assertNull(self::getContainer()->get(FollowUpStatisticsService::class)->get('.org')['medianDays']);
        self::assertStringContainsString('data-follow-up-statistics', $this->request('/statistics?tld=.invalid')->getContent());
        $views = self::getContainer()->get(InventoryViewService::class);
        $query = ['scope' => 'all', 'pursuit' => 'closed', 'closure_reason' => 'declined', 'contact_work' => 'blocked'];
        $views->create('Closed cases', $query);
        self::assertSame($query, $views->all()[0]['query']);
        self::assertCount(1, self::getContainer()->get(FindingReadRepository::class)->findPage(new FindingReadFilter(scope: 'all', pursuit: 'closed')));
    }
    private function assertDrilldown(array $row): void
    {
        parse_str(parse_url($row['url'], PHP_URL_QUERY), $query);
        [$filter] = self::getContainer()->get(FindingListService::class)->parse($query);
        self::assertSame($row['count'], self::getContainer()->get(FindingReadRepository::class)->count($filter));
    }
    private function finding(string $host = 'policy.invalid'): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => $host]);
        if (!$domain) { $domain = (new Domain())->setHostname($host); $this->entityManager->persist($domain); }
        $f = (new Finding())->setDomain($domain)->setTitle('Synthetic policy case')->setType('stored-case')
            ->setUrl('https://'.$host.'/case/'.bin2hex(random_bytes(5)))->setSubmittedAt(new \DateTimeImmutable('2026-01-01'))->setNextDueAt(new \DateTimeImmutable('2026-01-02'));
        $this->entityManager->persist($f); $this->entityManager->flush(); return $f;
    }
    private function fact(Finding $f): array { return $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$f->getId()]); }
    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters); $request->setSession($this->session);
        $response = self::$kernel->handle($request); self::$kernel->terminate($request, $response); return $response;
    }
    private function form(Response $response, string $scope): array
    {
        $doc = new \DOMDocument(); @$doc->loadHTML($response->getContent()); $xp = new \DOMXPath($doc); $data = [];
        foreach ($xp->query('//form[@data-follow-up="'.$scope.'"]//input[@type="hidden"]') as $input) $data[$input->getAttribute('name')] = $input->getAttribute('value');
        return $data;
    }
}
