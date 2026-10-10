<?php
namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\FollowUpService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class RestrictionCentreTest extends DatabaseTestCase
{
    private Session $session;
    private FollowUpService $policy;
    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
        $this->policy = self::getContainer()->get(FollowUpService::class);
    }

    public function testOrphanedDomainIsVisibleAndCanBeReleasedWithoutRecreatingCases(): void
    {
        $finding = $this->finding();
        $this->policy->save($finding, ['scope' => 'domain', 'checks_blocked' => '1', 'contact_blocked' => '1']);
        $this->entityManager->remove($finding); $this->entityManager->flush();
        $before = $this->rows();
        $response = $this->request('/settings');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($before, $this->rows(), 'GET is read-only');
        self::assertStringContainsString('Verbleibende Fälle: 0', $response->getContent());
        $post = $this->form($response, 'domain', 'checks_blocked');
        self::assertSame(303, $this->request('/settings/restrictions', 'POST', $post)->getStatusCode());
        $state = $this->policy->restrictions();
        self::assertCount(1, $state['domains']);
        self::assertSame(0, (int) $state['domains'][0]['checks_blocked']);
        self::assertSame(1, (int) $state['domains'][0]['contact_blocked']);
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM finding'));
        self::assertCount(2, $state['history']);
        self::assertSame('', $state['history'][0]['finding_id']);
    }

    public function testTargetedCaseReleaseDoesNotReopenOrLiftDomainPolicy(): void
    {
        $finding = $this->finding();
        $this->policy->save($finding, ['pursuit' => 'closed', 'reason' => 'contact_refused', 'checks_blocked' => '1']);
        $this->policy->save($finding, ['scope' => 'domain', 'checks_blocked' => '1']);
        $post = $this->form($this->request('/settings'), 'case', 'contact_blocked');
        // Ignore tampering with unrelated hidden fields: release is targeted.
        $post['checks_blocked'] = '0'; $post['pursuit'] = 'active';
        self::assertSame(303, $this->request('/settings/restrictions', 'POST', $post)->getStatusCode());
        $state = $this->policy->state($finding);
        self::assertFalse($state['case']['contact_blocked']);
        self::assertSame('closed', $state['case']['pursuit']);
        self::assertSame('contact_refused', $state['case']['reason']);
        self::assertTrue($state['case']['checks_blocked']);
        self::assertTrue($state['domain']['checks_blocked']);
        $post = $this->form($this->request('/settings'), 'case', 'pursuit');
        $this->request('/settings/restrictions', 'POST', $post);
        self::assertSame('active', $this->policy->state($finding)['case']['pursuit']);
        self::assertTrue($this->policy->state($finding)['case']['checks_blocked']);
    }

    public function testOldTabCannotOverwriteAnotherCaseDomainChangeOrAChangeAndRevert(): void
    {
        $finding = $this->finding(); $other = $this->finding('central.invalid', '/other');
        $post = $this->detailForm($this->request('/findings/'.$finding->getId()), 'domain');
        $this->policy->save($other, ['scope' => 'domain', 'contact_blocked' => '1']);
        $this->policy->save($other, ['scope' => 'domain']); // Same flags as initial state, different revision.
        $before = $this->rows();
        $response = $this->request('/findings/'.$finding->getId().'/follow-up', 'POST', $post + ['contact_blocked' => '1']);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('?error=', $response->headers->get('Location'));
        self::assertSame($before, $this->rows());
        $post = $this->detailForm($this->request('/findings/'.$finding->getId()), 'case');
        $this->policy->save($finding, ['checks_blocked' => '1']);
        $before = $this->rows();
        $this->request('/findings/'.$finding->getId().'/follow-up', 'POST', $post + ['pursuit' => 'active']);
        self::assertSame($before, $this->rows());
    }

    public function testCentralStaleReleaseCsrfAndMalformedInputCannotWrite(): void
    {
        $finding = $this->finding();
        $this->policy->save($finding, ['scope' => 'domain', 'checks_blocked' => '1']);
        $post = $this->form($this->request('/settings'), 'domain', 'checks_blocked');
        $this->policy->save($finding, ['scope' => 'domain', 'checks_blocked' => '1', 'contact_blocked' => '1']);
        $before = $this->rows();
        self::assertSame(405, $this->request('/settings/restrictions')->getStatusCode());
        self::assertSame(403, $this->request('/settings/restrictions', 'POST', ['_token' => 'wrong'])->getStatusCode());
        foreach ([$post, array_replace($post, ['revision' => []]), array_replace($post, ['scope' => []]), array_replace($post, ['hostname' => []])] as $input) {
            $response = $this->request('/settings/restrictions', 'POST', $input);
            self::assertSame(303, $response->getStatusCode());
            self::assertStringContainsString('?error=', $response->headers->get('Location'));
            self::assertSame($before, $this->rows());
        }
        $detail = $this->detailForm($this->request('/findings/'.$finding->getId()), 'domain');
        unset($detail['revision']);
        $this->request('/findings/'.$finding->getId().'/follow-up', 'POST', $detail);
        self::assertSame($before, $this->rows());
    }

    public function testIdempotentSaveKeepsRevisionAndNewImportInheritsRemainingDomainRestriction(): void
    {
        $finding = $this->finding();
        $this->policy->save($finding, ['scope' => 'domain', 'contact_blocked' => '1']);
        $revision = $this->policy->state($finding)['revisions']['domain'];
        $this->policy->save($finding, ['scope' => 'domain', 'contact_blocked' => '1', 'revision' => $revision]);
        self::assertSame($revision, $this->policy->state($finding)['revisions']['domain']);
        self::assertTrue($this->policy->state($this->finding('central.invalid', '/new'))['contactBlocked']);
        self::assertFalse($this->policy->state($this->finding('sub.central.invalid'))['contactBlocked']);
    }

    private function finding(string $host = 'central.invalid', string $path = '/case'): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => $host]);
        if (!$domain) { $domain = (new Domain())->setHostname($host); $this->entityManager->persist($domain); }
        $finding = (new Finding())->setDomain($domain)->setType('stored-case')->setTitle('Central fixture')->setUrl('https://'.$host.$path);
        $this->entityManager->persist($finding); $this->entityManager->flush(); return $finding;
    }
    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters); $request->setSession($this->session);
        $response = self::$kernel->handle($request); self::$kernel->terminate($request, $response); return $response;
    }
    private function form(Response $response, string $scope, string $field): array
    {
        return $this->fields($response, '//li[@data-restriction-scope="'.$scope.'"]//form[@data-restriction-action="'.$field.'"]');
    }
    private function detailForm(Response $response, string $scope): array { return $this->fields($response, '//form[@data-follow-up="'.$scope.'"]'); }
    private function fields(Response $response, string $selector): array
    {
        $doc = new \DOMDocument(); @$doc->loadHTML($response->getContent()); $xp = new \DOMXPath($doc); $data = [];
        self::assertSame(1, $xp->query($selector)->length);
        foreach ($xp->query($selector.'//input[@type="hidden"]') as $input) $data[$input->getAttribute('name')] = $input->getAttribute('value');
        return $data;
    }
    private function rows(): array
    {
        $db = $this->entityManager->getConnection(); $rows = [];
        foreach (['finding', 'finding_follow_up', 'domain_work_restriction', 'work_policy_event'] as $table) $rows[$table] = $db->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid');
        return $rows;
    }
}
