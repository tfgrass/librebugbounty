<?php

namespace App\Tests;

use App\Dto\FindingReadFilter;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Repository\FindingReadRepository;
use App\Service\FindingListService;
use App\Service\InventoryViewService;
use App\Service\StudioExportService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class InventoryReviewDispositionTest extends DatabaseTestCase
{
    public function testDeferredSelectionKeepsListCountExportAndSavedViewsInAgreement(): void
    {
        $ordinary = $this->finding('ordinary');
        $deferred = $this->finding('deferred');
        $pastDue = $this->finding('past-due');
        $resumed = $this->finding('resumed');
        $archived = $this->finding('archived')->setManualAssessment('discarded', null, new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->disposition($deferred, 'deferred', '2099-01-02 09:00:00');
        $this->disposition($pastDue, 'deferred', '2020-01-02 09:00:00');
        $this->disposition($resumed, 'active', '2020-01-02 09:00:00');
        $this->disposition($archived, 'deferred', null);

        $repository = self::getContainer()->get(FindingReadRepository::class);
        $lists = self::getContainer()->get(FindingListService::class);
        [$filter] = $lists->parse(['review' => 'deferred']);
        self::assertSame(4, $repository->count(new FindingReadFilter()));
        self::assertSame(2, $repository->count($filter));
        $rows = $repository->findPage($filter);
        self::assertEqualsCanonicalizing([$deferred->getId(), $pastDue->getId()], array_column($rows, 'id'));
        foreach ($rows as $row) {
            self::assertTrue($row->reviewDeferred);
            self::assertNotNull($row->reviewDeferredUntil);
        }
        self::assertSame(2, $repository->count(new FindingReadFilter(review: 'open')));
        self::assertSame(3, $repository->count(new FindingReadFilter(scope: 'all', review: 'deferred')));
        self::assertSame(4, $repository->count(new FindingReadFilter(review: 'all')));
        self::assertFalse($repository->findPage(new FindingReadFilter(q: 'ordinary'))[0]->reviewDeferred);

        $exports = self::getContainer()->get(StudioExportService::class);
        self::assertSame(2, $exports->get(['review' => 'deferred'])->findingCount);
        $json = '';
        $exports->writeDownload($filter, false, static function (string $part) use (&$json): void { $json .= $part; });
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $data['findingCount']);
        self::assertSame('deferred', $data['filters']['review']);
        self::assertEqualsCanonicalizing([$deferred->getId(), $pastDue->getId()], array_column($data['findings'], 'id'));

        $views = self::getContainer()->get(InventoryViewService::class);
        $id = $views->create('Deferred work', ['review' => 'deferred', 'page' => '2']);
        $saved = array_values(array_filter($views->all(), static fn (array $view): bool => $view['id'] === $id))[0];
        self::assertSame(['scope' => 'active', 'review' => 'deferred'], $saved['query']);
        self::assertStringContainsString('review=deferred', $saved['url']);
        self::assertTrue($lists->hasListQuery(['review' => 'deferred']));
    }

    public function testAbsentDispositionSchemaMeansNoDeferredCasesAndDoesNotBlockInventory(): void
    {
        $finding = $this->finding('legacy');
        $this->entityManager->getConnection()->executeStatement('DROP TABLE finding_review_disposition');
        $repository = self::getContainer()->get(FindingReadRepository::class);
        self::assertSame(1, $repository->count(new FindingReadFilter(review: 'open')));
        self::assertSame(0, $repository->count(new FindingReadFilter(review: 'deferred')));
        $row = $repository->findPage(new FindingReadFilter())[0];
        self::assertSame($finding->getId(), $row->id);
        self::assertFalse($row->reviewDeferred);
        self::assertNull($row->reviewDeferredUntil);
    }

    public function testInventoryShowsDeferredBadgeAndPreservesFilterForExport(): void
    {
        $finding = $this->finding('visible');
        $this->disposition($finding, 'deferred', '2099-01-02 09:00:00');
        $this->finding('hidden');
        $request = Request::create('/findings?review=deferred');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        self::assertSame(200, $response->getStatusCode());
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//*[@data-finding-id]')->length);
        self::assertSame(1, $xpath->query('//*[@data-review-deferred]')->length);
        self::assertSame(1, $xpath->query('//select[@name="review"]/option[@value="deferred" and @selected]')->length);
        self::assertStringContainsString('review=deferred', $xpath->query('//a[@data-export-selection]')->item(0)->getAttribute('href'));
        self::assertSame(1, $xpath->query('//details[contains(@class,"studio-list-additional") and @open]')->length);
    }

    public function testCurrentPocIsDisplayedAndSearchableWhileOriginalRemainsAvailable(): void
    {
        $finding = $this->finding('original');
        $current = 'https://poc.invalid/new-target';
        $this->entityManager->getConnection()->update('finding', ['poc_url' => $current], ['id' => $finding->getId()]);
        $repository = self::getContainer()->get(FindingReadRepository::class);
        $row = $repository->findPage(new FindingReadFilter(q: 'new-target'))[0];
        self::assertSame($current, $row->url);
        self::assertSame($finding->getUrl(), $row->originalUrl);
        self::assertSame(1, $repository->count(new FindingReadFilter(q: 'new-target')));
        self::assertSame(1, $repository->count(new FindingReadFilter(q: '/case/original')));
    }

    public function testMalformedReviewFilterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(FindingListService::class)->parse(['review' => ['deferred']]);
    }

    public function testOnlyObservationsForTheCurrentPocContributeToReadProjectionAndCounts(): void
    {
        $finding = $this->finding('revision');
        $staleOnly = $this->finding('stale-only');
        $connection = $this->entityManager->getConnection();
        foreach ([$finding, $staleOnly] as $item) {
            $connection->update('finding', ['poc_url' => 'https://poc.invalid/changed', 'poc_revision' => 1], ['id' => $item->getId()]);
            $this->observation($item, 'fixed', '2026-10-18 12:00:00', null);
        }
        $current = $this->observation($finding, 'inconclusive', '2026-10-18 10:00:00', ['pocRevision' => 1, 'pocUrl' => 'https://poc.invalid/changed']);
        $this->observation($finding, 'fixed', '2026-10-18 13:00:00', ['pocRevision' => 1, 'pocUrl' => 'https://poc.invalid/changed', 'staleFindingContext' => true]);
        $repository = self::getContainer()->get(FindingReadRepository::class);
        $rows = array_column($repository->findPage(new FindingReadFilter()), null, 'id');
        self::assertSame($current->getId(), $rows[$finding->getId()]->observationId);
        self::assertNull($rows[$staleOnly->getId()]->observationId);
        self::assertSame(0, $repository->count(new FindingReadFilter(observation: 'fixed')));
        self::assertSame(1, $repository->count(new FindingReadFilter(observation: 'inconclusive')));
        self::assertSame(1, $repository->count(new FindingReadFilter(observation: 'none')));
        self::assertSame(1, $repository->globalCounts()['inconclusive']);
        self::assertSame(1, $repository->globalCounts()['unobserved']);
    }

    private function observation(Finding $finding, string $result, string $startedAt, ?array $raw): RetestRun
    {
        $run = (new RetestRun())->setFinding($finding)->setResult($result)->setStartedAt(new \DateTimeImmutable($startedAt))->setRawResult($raw);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        return $run;
    }

    private function finding(string $name): Finding
    {
        $domain = (new Domain())->setHostname($name.'.inventory.invalid');
        $finding = (new Finding())->setDomain($domain)->setTitle($name)->setType('other')->setUrl('https://'.$domain->getHostname().'/case/'.$name);
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function disposition(Finding $finding, string $state, ?string $due): void
    {
        $this->entityManager->getConnection()->insert('finding_review_disposition', [
            'finding_id' => $finding->getId(), 'revision' => 1, 'state' => $state,
            'reason' => 'unreachable', 'due_at' => $due, 'changed_at' => '2026-10-18 09:00:00',
        ]);
    }
}
