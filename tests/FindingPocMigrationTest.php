<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;

final class FindingPocMigrationTest extends DatabaseTestCase
{
    public function testAdditiveMigrationPreservesOriginalAndStartsWithoutInventedHistory(): void
    {
        $domain = (new Domain())->setHostname('legacy.example');
        $finding = (new Finding())->setDomain($domain)->setType('xss')->setTitle('Original finding')
            ->setUrl('https://legacy.example/case?exact=%2f')->setPrivateNotes('Preserve this');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        $db = $this->entityManager->getConnection();
        $db->executeStatement('DROP TABLE finding_poc_revision');
        $db->executeStatement('ALTER TABLE finding DROP COLUMN poc_url');
        $db->executeStatement('ALTER TABLE finding DROP COLUMN poc_revision');
        $before = $db->fetchAllAssociative('SELECT rowid, * FROM finding');

        require_once dirname(__DIR__).'/migrations/Version20261018010000.php';
        $migration = new \DoctrineMigrations\Version20261018010000($db, new NullLogger());
        $migration->up(new Schema());
        $db->transactional(function () use ($migration, $db): void {
            foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        });
        $after = $db->fetchAllAssociative('SELECT rowid, * FROM finding');
        self::assertNull($after[0]['poc_url']);
        self::assertSame(0, (int) $after[0]['poc_revision']);
        unset($after[0]['poc_url'], $after[0]['poc_revision']);
        self::assertSame($before, $after);
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM finding_poc_revision'));
        $diff = (new SchemaTool($this->entityManager))->getUpdateSchemaSql($this->entityManager->getMetadataFactory()->getAllMetadata());
        self::assertSame([], $diff, implode("\n", $diff));
        $this->expectException(\Doctrine\Migrations\Exception\AbortMigration::class);
        $migration->down(new Schema());
    }
}
