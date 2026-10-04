<?php

namespace App\Tests;

use App\Dto\FindingReadFilter;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Repository\FindingReadRepository;
use App\Repository\ScreenshotJobRepository;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;

final class InventoryReadPerformanceTest extends DatabaseTestCase
{
    public function testAggregatedCountersKeepArchiveOverlapLatestTiesAndEmptyInventory(): void
    {
        $repository = self::getContainer()->get(FindingReadRepository::class);
        self::assertSame(array_fill_keys(['active', 'confirmed', 'fixed', 'unknown', 'inconclusive', 'unobserved', 'contacted', 'discarded', 'duplicates'], 0), $repository->globalCounts());
        $jobs = self::getContainer()->get(ScreenshotJobRepository::class);
        self::assertSame(['queued' => 0, 'running' => 0, 'failed' => 0], $jobs->inventoryCounts());

        $unknown = $this->finding('unknown');
        $confirmed = $this->finding('confirmed')->setManualAssessment('confirmed', null, new \DateTimeImmutable('2026-10-04'));
        $fixed = $this->finding('fixed')->setManualAssessment('fixed', null, new \DateTimeImmutable('2026-10-04'))->setContactedAt(new \DateTimeImmutable('2026-10-04'));
        $discarded = $this->finding('discarded')->setManualAssessment('discarded', 'duplicate', new \DateTimeImmutable('2026-10-04'));
        $legacyDiscarded = $this->finding('legacy-discarded')->setStatus('discarded');
        $legacyDuplicate = $this->finding('legacy-duplicate')->setStatus('duplicate')->setManualAssessment('confirmed', null, new \DateTimeImmutable('2026-10-04'))->setContactedAt(new \DateTimeImmutable('2026-10-04'));
        $wontfix = $this->finding('wontfix')->setStatus('wontfix');
        $this->entityManager->flush();
        foreach ([$unknown, $confirmed, $fixed, $discarded, $legacyDiscarded, $legacyDuplicate, $wontfix] as $finding) {
            foreach (['queued', 'running', 'failed', 'available'] as $status) {
                $this->entityManager->persist((new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus($status));
            }
        }
        // Equal timestamps must keep insertion order, not UUID or result order.
        foreach ([[$unknown, 'error'], [$unknown, 'inconclusive'], [$confirmed, 'fixed']] as [$finding, $result]) {
            $this->entityManager->persist((new RetestRun())->setFinding($finding)->setResult($result)->setStartedAt(new \DateTimeImmutable('2026-10-04T10:00:00')));
            $this->entityManager->flush();
        }
        $db = $this->entityManager->getConnection();
        $orphan = $db->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$confirmed->getId()]);
        $orphan['id'] = 'orphan';
        $orphan['domain_id'] = 'missing-domain';
        $db->insert('finding', $orphan);
        $before = $db->fetchAllAssociative('SELECT rowid, * FROM finding ORDER BY rowid');

        self::assertSame([
            'active' => 4, 'confirmed' => 1, 'fixed' => 1, 'unknown' => 2,
            'inconclusive' => 1, 'unobserved' => 2, 'contacted' => 1,
            'discarded' => 3, 'duplicates' => 2,
        ], $repository->globalCounts());
        foreach ([
            'active' => new FindingReadFilter(),
            'confirmed' => new FindingReadFilter(assessment: 'confirmed'),
            'fixed' => new FindingReadFilter(assessment: 'fixed'),
            'unknown' => new FindingReadFilter(assessment: 'unknown'),
            'inconclusive' => new FindingReadFilter(observation: 'inconclusive'),
            'unobserved' => new FindingReadFilter(observation: 'none'),
            'contacted' => new FindingReadFilter(contact: 'yes'),
            'discarded' => new FindingReadFilter(scope: 'discarded'),
            'duplicates' => new FindingReadFilter(scope: 'duplicates'),
        ] as $name => $filter) {
            self::assertSame($repository->count($filter), $repository->globalCounts()[$name], $name.' must match its linked list.');
        }
        self::assertSame(['queued' => 4, 'running' => 4, 'failed' => 4], $jobs->inventoryCounts());
        self::assertSame($before, $db->fetchAllAssociative('SELECT rowid, * FROM finding ORDER BY rowid'));
    }

    public function testOrderIndexMigrationPreservesNullDatesAndInsertionTiesWithoutSortingTheWholeInventory(): void
    {
        $db = $this->entityManager->getConnection();
        $db->executeStatement('DROP INDEX idx_finding_list_order');
        foreach (['first', 'second', 'third', 'undated'] as $name) {
            $finding = $this->finding($name);
            $db->update('finding', [
                'submitted_at' => $name === 'undated' ? null : '2026-10-04 10:00:00',
                'created_at' => '2026-10-04 09:00:00',
            ], ['id' => $finding->getId()]);
        }
        $repository = self::getContainer()->get(FindingReadRepository::class);
        $beforeRows = $db->fetchAllAssociative('SELECT rowid, * FROM finding ORDER BY rowid');
        $beforeIds = array_column($repository->findPage(new FindingReadFilter(), 100), 'id');

        require_once dirname(__DIR__).'/migrations/Version20261004000000.php';
        $migration = new \DoctrineMigrations\Version20261004000000($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertSame($beforeRows, $db->fetchAllAssociative('SELECT rowid, * FROM finding ORDER BY rowid'));
        self::assertSame($beforeIds, array_column($repository->findPage(new FindingReadFilter(), 100), 'id'));
        self::assertSame(array_slice($beforeIds, 1, 2), array_column($repository->findPage(new FindingReadFilter(), 2, 1), 'id'));
        $plan = implode(' ', array_column($db->fetchAllAssociative('EXPLAIN QUERY PLAN SELECT id FROM finding ORDER BY submitted_at DESC, created_at DESC, rowid DESC LIMIT 10'), 'detail'));
        self::assertStringContainsString('idx_finding_list_order', $plan);
        self::assertStringNotContainsString('TEMP B-TREE', $plan);
    }

    private function finding(string $name): Finding
    {
        $domain = (new Domain())->setHostname($name.'.example.test');
        $finding = (new Finding())->setDomain($domain)->setTitle($name)->setType('synthetic')->setUrl('https://'.$domain->getHostname().'/case');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }
}
