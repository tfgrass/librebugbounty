<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\RetestRun;
use App\Repository\FindingAssessmentRepository;
use App\Repository\RetestRunRepository;
use App\Service\FindingService;
use App\Value\ManualAssessment;
use App\Value\ReviewState;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;

final class FindingAssessmentTest extends DatabaseTestCase
{
    public function testManualDecisionsAndTheirKnownReferencesPersistTogether(): void
    {
        $finding = $this->finding('history');
        $contactedAt = new \DateTimeImmutable('2026-09-01T12:00:00+00:00');
        $finding->setReviewState(ReviewState::MANUAL_CHECKING)
            ->setPrivateNotes('Keep this note')
            ->setContactedAt($contactedAt);
        $run = $this->observation($finding);
        $evidence = $this->evidence($finding);
        $service = self::getContainer()->get(FindingService::class);

        $service->assess($finding, ManualAssessment::CONFIRMED, null, $run->getId(), $evidence->getId());
        self::assertSame('verified', $finding->getStatus());
        self::assertSame(ReviewState::MANUALLY_CHECKED, $finding->getReviewState());
        self::assertTrue($finding->hasProtectedAssessment());
        self::assertNotNull($finding->getNextDueAt(), 'Completing manual review restores the normal recheck schedule.');
        $history = $this->history($finding);
        self::assertCount(1, $history);
        self::assertSame($finding->getAssessedAt(), $history[0]->getAssessedAt());
        self::assertSame('manual', $history[0]->getSource());
        self::assertSame($run->getId(), $history[0]->getObservationId());
        self::assertSame($evidence->getId(), $history[0]->getEvidenceId());
        self::assertSame('inconclusive', $history[0]->getReferenceSnapshot()['observation']['result']);
        self::assertSame('screenshot', $history[0]->getReferenceSnapshot()['evidence']['kind']);
        $snapshot = $history[0]->getReferenceSnapshot();

        $service->confirmFixed($finding);
        $service->discardFinding($finding, ManualAssessment::DUPLICATE);
        self::assertSame($contactedAt, $finding->getContactedAt());
        self::assertSame('Keep this note', $finding->getPrivateNotes());
        self::assertCount(1, $this->entityManager->getRepository(Evidence::class)->findBy(['finding' => $finding]));

        $id = $finding->getId();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Finding::class, $id);
        self::assertSame(ManualAssessment::DISCARDED, $reloaded->getManualAssessment());
        self::assertSame(ManualAssessment::DUPLICATE, $reloaded->getDiscardReason());
        self::assertSame('discarded', $reloaded->getStatus());
        self::assertTrue($reloaded->isDiscarded());
        $history = $this->history($reloaded);
        self::assertSame(['discarded', 'fixed', 'confirmed'], array_map(static fn (FindingAssessment $item): string => $item->getAssessment(), $history));
        self::assertSame($snapshot, $history[2]->getReferenceSnapshot());
        self::assertNull($history[1]->getObservationId());
        self::assertNull($history[1]->getEvidenceId());
        self::assertNull($history[1]->getReferenceSnapshot());
    }

    public function testUnknownAndForeignReferencesCannotPartiallyChangeAnAssessment(): void
    {
        $finding = $this->finding('valid');
        $other = $this->finding('foreign');
        $validRun = $this->observation($finding);
        $foreignRun = $this->observation($other);
        $foreignEvidence = $this->evidence($other);
        $service = self::getContainer()->get(FindingService::class);
        $service->confirmFixed($finding);
        $before = $finding->getAssessedAt();

        foreach ([
            ['unsupported', null, null, null],
            ['confirmed', 'duplicate', null, null],
            ['discarded', 'unsupported', null, null],
            ['confirmed', null, 'missing-observation', null],
            ['confirmed', null, $foreignRun->getId(), null],
            ['confirmed', null, $validRun->getId(), 'missing-evidence'],
            ['confirmed', null, $validRun->getId(), $foreignEvidence->getId()],
        ] as [$assessment, $reason, $observationId, $evidenceId]) {
            try {
                $service->assess($finding, $assessment, $reason, $observationId, $evidenceId);
                self::fail('An invalid assessment or reference was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame(ManualAssessment::FIXED, $finding->getManualAssessment());
                self::assertSame($before, $finding->getAssessedAt());
                self::assertSame('fixed', $finding->getStatus());
                self::assertCount(1, $this->history($finding));
            }
        }
    }

    public function testKnownObservationsAreAnArrivalBoundaryAndNeverAnAssumedAssessmentBasis(): void
    {
        $finding = $this->finding('known-observations');
        $existing = $this->observation($finding);
        $otherFinding = $this->finding('other-observations');
        $this->observation($otherFinding);
        self::getContainer()->get(FindingService::class)->assess($finding, 'confirmed');
        $history = $this->history($finding)[0];
        self::assertSame([$existing->getId()], $history->getKnownObservationIds());
        self::assertNull($history->getObservationId());
        self::assertNull($history->getEvidenceId());
        self::assertNull($history->getReferenceSnapshot());
        $repository = self::getContainer()->get(RetestRunRepository::class);
        self::assertFalse($repository->hasObservationAfterAssessment($finding, $history->getKnownObservationIds()));

        $newRun = $this->observation($finding);
        $newRun->setStartedAt($finding->getAssessedAt())->setFinishedAt($finding->getAssessedAt());
        $this->entityManager->flush();
        self::assertTrue($repository->hasObservationAfterAssessment($finding, $history->getKnownObservationIds()));
        $historyId = $history->getId();
        $this->entityManager->clear();
        self::assertSame([$existing->getId()], $this->entityManager->find(FindingAssessment::class, $historyId)->getKnownObservationIds());
    }

    public function testAnAssessmentWithoutExistingObservationsStartsWithAnEmptyBoundary(): void
    {
        $finding = $this->finding('no-observations');
        self::getContainer()->get(FindingService::class)->assess($finding, 'confirmed');
        $history = $this->history($finding)[0];
        self::assertSame([], $history->getKnownObservationIds());
        $repository = self::getContainer()->get(RetestRunRepository::class);
        self::assertFalse($repository->hasObservationAfterAssessment($finding, []));
        $newRun = $this->observation($finding);
        $newRun->setStartedAt($finding->getAssessedAt())->setFinishedAt($finding->getAssessedAt());
        $this->entityManager->flush();
        self::assertTrue($repository->hasObservationAfterAssessment($finding, []));
    }

    public function testNewObservationRemainsNewAfterResetReusesTheSQLiteRowid(): void
    {
        $finding = $this->finding('reset-arrival-boundary');
        $oldRun = $this->observation($finding);
        $connection = $this->entityManager->getConnection();
        $oldRowid = $connection->fetchOne('SELECT rowid FROM retest_run WHERE id = ?', [$oldRun->getId()]);
        $service = self::getContainer()->get(FindingService::class);
        $service->assess($finding, 'confirmed');
        $history = $this->history($finding)[0];
        $this->entityManager->remove($oldRun);
        $this->entityManager->flush();
        $service->resetVerificationState($finding);
        $newRun = $this->observation($finding);
        $newRun->setStartedAt($finding->getAssessedAt())->setFinishedAt($finding->getAssessedAt());
        $this->entityManager->flush();

        self::assertSame($oldRowid, $connection->fetchOne('SELECT rowid FROM retest_run WHERE id = ?', [$newRun->getId()]));
        self::assertNotSame($oldRun->getId(), $newRun->getId());
        self::assertSame([$oldRun->getId()], $history->getKnownObservationIds());
        self::assertTrue(self::getContainer()->get(RetestRunRepository::class)->hasObservationAfterAssessment($finding, $history->getKnownObservationIds()));
        self::assertSame('confirmed', $finding->getManualAssessment());
        self::assertCount(1, $this->history($finding));
    }

    public function testDatabaseFailureRollsBackBothHistoryAndCurrentDecision(): void
    {
        $finding = $this->finding('atomic');
        $service = self::getContainer()->get(FindingService::class);
        $service->markVulnerable($finding);
        $connection = $this->entityManager->getConnection();
        $before = $connection->fetchAssociative('SELECT manual_assessment, status, assessed_at FROM finding WHERE id = ?', [$finding->getId()]);
        // ORM inserts new history before updating the existing finding. Failing
        // that later update must roll back the already inserted history too.
        $connection->executeStatement("CREATE TRIGGER fail_assessment_update BEFORE UPDATE ON finding WHEN NEW.manual_assessment = 'fixed' BEGIN SELECT RAISE(ABORT, 'fixture assessment write failure'); END");

        try {
            $service->confirmFixed($finding);
            self::fail('The fixture write failure should abort the assessment.');
        } catch (Exception) {
            self::assertSame($before, $connection->fetchAssociative('SELECT manual_assessment, status, assessed_at FROM finding WHERE id = ?', [$finding->getId()]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding_assessment WHERE finding_id = ?', [$finding->getId()]));
        }
    }

    public function testLegacyProtectionDoesNotClaimNewManualSourceOrDate(): void
    {
        foreach ([ReviewState::MANUALLY_CHECKED, ReviewState::CONFIRMED_FIXED] as $marker) {
            $finding = $this->finding($marker)->setStatus('verified')->setReviewState($marker);
            $this->entityManager->flush();
            self::assertTrue($finding->hasProtectedAssessment());
            self::assertNull($finding->getManualAssessment());
            self::assertNull($finding->getAssessedAt());
            self::assertCount(0, $this->history($finding));
            $service = self::getContainer()->get(FindingService::class);
            $service->markAsOpen($finding);
            $service->markManualChecking($finding);
            $service->resetVerificationState($finding);
            self::assertSame('verified', $finding->getStatus());
            self::assertSame($marker, $finding->getReviewState());
        }
        self::assertTrue($this->finding('old-duplicate')->setStatus('duplicate')->isDiscarded());
        self::assertFalse($this->finding('old-wontfix')->setStatus('wontfix')->isDiscarded());
    }

    public function testContactIsIndependentAndCannotBeRepeatedlyRedated(): void
    {
        $finding = $this->finding('contact');
        $service = self::getContainer()->get(FindingService::class);
        $service->confirmFixed($finding);
        $assessedAt = $finding->getAssessedAt();
        $service->markContacted($finding);
        self::assertNotNull($finding->getContactedAt());
        $contactedAt = new \DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $finding->setContactedAt($contactedAt);
        $this->entityManager->flush();
        $service->markContacted($finding);
        self::assertSame($contactedAt, $finding->getContactedAt());
        self::assertSame($assessedAt, $finding->getAssessedAt());
        self::assertSame(ManualAssessment::FIXED, $finding->getManualAssessment());
        self::assertCount(1, $this->history($finding));
        $service->discardFinding($finding);
        self::assertSame($contactedAt, $finding->getContactedAt());
    }

    public function testStoredDecisionBasisSurvivesRemovalOfTechnicalRecords(): void
    {
        $finding = $this->finding('basis');
        $run = $this->observation($finding);
        $evidence = $this->evidence($finding);
        self::getContainer()->get(FindingService::class)->assess($finding, 'confirmed', null, $run->getId(), $evidence->getId());
        $history = $this->history($finding)[0];
        $expectedSnapshot = $history->getReferenceSnapshot();
        $observationId = $run->getId();
        $evidenceId = $evidence->getId();
        $this->entityManager->remove($run);
        $this->entityManager->remove($evidence);
        $this->entityManager->flush();
        $historyId = $history->getId();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(FindingAssessment::class, $historyId);
        self::assertSame($expectedSnapshot, $reloaded->getReferenceSnapshot());
        self::assertSame($observationId, $reloaded->getObservationId());
        self::assertSame($evidenceId, $reloaded->getEvidenceId());
    }

    public function testResetsPreserveManualAssessmentAndItsHistory(): void
    {
        $finding = $this->finding('reset');
        $service = self::getContainer()->get(FindingService::class);
        $service->discardFinding($finding, 'duplicate');
        $assessedAt = $finding->getAssessedAt();
        $finding->setLastRetestedAt(new \DateTimeImmutable('2026-08-01'));
        $this->entityManager->flush();
        $service->markAsOpen($finding);
        $service->markManualChecking($finding);
        $service->resetVerificationState($finding);
        $service->resetFreshStartState($finding);
        self::assertSame(ManualAssessment::DISCARDED, $finding->getManualAssessment());
        self::assertSame('duplicate', $finding->getDiscardReason());
        self::assertSame('discarded', $finding->getStatus());
        self::assertSame($assessedAt, $finding->getAssessedAt());
        self::assertNull($finding->getLastRetestedAt());
        self::assertCount(1, $this->history($finding));
    }

    public function testDeletingFindingRemovesHistoryEvenWithoutForeignKeyEnforcement(): void
    {
        $finding = $this->finding('delete');
        $service = self::getContainer()->get(FindingService::class);
        $service->markVulnerable($finding);
        $service->confirmFixed($finding);
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        self::assertSame(0, (int) $connection->fetchOne('PRAGMA foreign_keys'));
        $id = $finding->getId();
        $service->deleteFinding($finding);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding_assessment WHERE finding_id = ?', [$id]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding WHERE id = ?', [$id]));
    }

    public function testAdditiveMigrationPreservesAmbiguousLegacyDataWithoutBackfill(): void
    {
        // A second isolated database represents a minimal pre-3a schema. This
        // executes only this migration's statements, never the live DB runner.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => APP_TEST_ROOT.'/assessment-migration.sqlite']);
        try {
            $connection->executeStatement('CREATE TABLE finding (id VARCHAR(36) NOT NULL PRIMARY KEY, status VARCHAR(20) NOT NULL, review_state VARCHAR(32) DEFAULT NULL, private_notes CLOB DEFAULT NULL, contacted_at DATETIME DEFAULT NULL)');
            $connection->executeStatement("INSERT INTO finding VALUES ('legacy', 'verified', 'confirmed_fixed', 'keep note', '2026-08-01 12:00:00')");
            $before = $connection->fetchAssociative('SELECT * FROM finding');
            require_once dirname(__DIR__).'/migrations/Version20261002010000.php';
            $migration = new \DoctrineMigrations\Version20261002010000($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            $after = $connection->fetchAssociative('SELECT * FROM finding');
            self::assertSame($before, array_intersect_key($after, $before));
            self::assertNull($after['manual_assessment']);
            self::assertNull($after['discard_reason']);
            self::assertNull($after['assessed_at']);
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding_assessment'));
            $schemaTool = new SchemaTool($this->entityManager);
            $expected = $schemaTool->getSchemaFromMetadata($this->entityManager->getMetadataFactory()->getAllMetadata());
            // This historical 3a migration predates the later nullable review
            // boundary extension; its original columns still match exactly.
            $expected->getTable('finding_assessment')->dropColumn('known_observation_states');
            $manager = $connection->createSchemaManager();
            $difference = $manager->createComparator()->compareTables(
                $expected->getTable('finding_assessment'),
                $manager->introspectTable('finding_assessment'),
            );
            self::assertTrue($difference->isEmpty(), 'The additive history table must match its Doctrine mapping.');
        } finally {
            $connection->close();
        }
    }

    /** @return list<FindingAssessment> */
    private function history(Finding $finding): array
    {
        return self::getContainer()->get(FindingAssessmentRepository::class)->findRecentByFinding($finding);
    }

    private function finding(string $name): Finding
    {
        $domain = (new Domain())->setHostname($name.'.example.test')->setAuthorized(true);
        $finding = (new Finding())->setDomain($domain)->setTitle('Synthetic case')->setType('synthetic')
            ->setUrl('https://'.$name.'.example.test/case');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function observation(Finding $finding): RetestRun
    {
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('inconclusive')
            ->setStartedAt(new \DateTimeImmutable('2026-09-15T12:00:00+00:00'))
            ->setFinishedAt(new \DateTimeImmutable('2026-09-15T12:00:01+00:00'));
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function evidence(Finding $finding): Evidence
    {
        $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath('fixture/missing.png');
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();

        return $evidence;
    }
}
