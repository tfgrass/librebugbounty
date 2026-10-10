<?php
namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\FindingWorkPolicy;
use App\Service\FollowUpService;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;

final class FollowUpMigrationTest extends DatabaseTestCase
{
    public function testAdditiveMigrationMatchesMappingAndDoesNotInferLegacyOptOuts(): void
    {
        $domain = (new Domain())->setHostname('legacy.invalid');
        $case = (new Finding())->setDomain($domain)->setType('stored')->setTitle('Legacy')->setUrl('https://legacy.invalid')->setStatus('discarded');
        $this->entityManager->persist($domain); $this->entityManager->persist($case); $this->entityManager->flush();
        $db = $this->entityManager->getConnection();
        $before = $db->fetchAllAssociative('SELECT rowid, * FROM finding');
        foreach (['contact_discovery', 'finding_follow_up', 'domain_work_restriction', 'work_policy_event'] as $table) $db->executeStatement('DROP TABLE '.$table);
        self::assertFalse(FindingWorkPolicy::available($db));
        self::assertFalse(self::getContainer()->get(FollowUpService::class)->state($case)['available']);
        require_once dirname(__DIR__).'/migrations/Version20261010000000.php';
        $migration = new \DoctrineMigrations\Version20261010000000($db, new NullLogger());
        $migration->up(new Schema());
        $db->transactional(function () use ($migration, $db) {
            foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        });
        self::assertTrue(FindingWorkPolicy::available($db));
        self::assertSame($before, $db->fetchAllAssociative('SELECT rowid, * FROM finding'));
        foreach (['contact_discovery', 'finding_follow_up', 'domain_work_restriction', 'work_policy_event'] as $table) self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table));
        $schemaTool = new SchemaTool($this->entityManager);
        $diff = $schemaTool->getUpdateSchemaSql($this->entityManager->getMetadataFactory()->getAllMetadata());
        self::assertSame([], $diff, implode("\n", $diff));
        self::assertSame(FollowUpService::DEFAULT_CASE, self::getContainer()->get(FollowUpService::class)->state($case)['case']);
        $this->expectException(\Doctrine\Migrations\Exception\AbortMigration::class);
        $migration->down(new Schema());
    }
}
