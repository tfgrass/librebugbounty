<?php

namespace App\Tests;

use App\Service\FindingService;

final class PocRecoveryDeletionTest extends DatabaseTestCase
{
    public function testDeletingAFindingRemovesPocAndRecoveryRecordsWithoutForeignKeyEnforcement(): void
    {
        $service = self::getContainer()->get(FindingService::class);
        $finding = $service->createIntakeFinding('https://deletion.example.test/original')->finding;
        $db = $this->entityManager->getConnection();
        $db->executeStatement('PRAGMA foreign_keys = OFF');
        $db->insert('finding_poc_revision', [
            'id' => 'd83d21b1-f782-4f8b-9110-8c07ab6a589c', 'finding_id' => $finding->getId(),
            'revision' => 1, 'previous_url' => $finding->getUrl(), 'url' => 'https://deletion.example.test/new',
            'source' => 'manual', 'evidence_id' => 'ac3a194d-e384-409c-b752-bc879edc06d3',
            'screenshot_job_id' => '6bfc756f-c880-4e05-a38f-efcb18206922', 'changed_at' => '2026-10-18 12:00:00',
        ]);
        $db->insert('finding_review_disposition', [
            'finding_id' => $finding->getId(), 'revision' => 1, 'state' => 'deferred',
            'reason' => 'unreachable', 'changed_at' => '2026-10-18 12:00:00', 'retry_state' => 'pending',
        ]);
        $db->insert('finding_review_skip', [
            'finding_id' => $finding->getId(), 'context_fingerprint' => str_repeat('a', 64),
            'actions' => '[]', 'skip_count' => 3, 'prompted' => 1,
        ]);

        $service->deleteFinding($finding);

        foreach (['finding_poc_revision', 'finding_review_disposition', 'finding_review_skip', 'finding', 'screenshot_job'] as $table) {
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table), $table.' must not retain orphaned records.');
        }
    }
}
