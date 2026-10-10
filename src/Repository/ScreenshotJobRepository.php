<?php

namespace App\Repository;

use App\Entity\Finding;
use App\Entity\ScreenshotJob;
use App\Value\FindingStatus;
use App\Value\ScreenshotJobStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ScreenshotJob> */
final class ScreenshotJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScreenshotJob::class);
    }

    public function findActiveForFinding(Finding $finding): ?ScreenshotJob
    {
        return $this->findOneBy(['activeKey' => $finding->getId()]);
    }

    /** @return list<ScreenshotJob> */
    public function findRecentByFinding(Finding $finding, ?int $limit = 20): array
    {
        $query = $this->createQueryBuilder('j')
            ->andWhere('j.finding = :finding')
            ->setParameter('finding', $finding)
            ->orderBy('j.requestedAt', 'DESC')
            ->addOrderBy('j.id', 'DESC');
        if ($limit !== null) {
            $query->setMaxResults($limit);
        }

        return $query->getQuery()->getResult();
    }

    public function claimNext(): ?ScreenshotJob
    {
        $connection = $this->getEntityManager()->getConnection();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $id = $connection->fetchOne(
                // UUIDs are random and requested_at only has second precision. This is a
                // SQLite rowid table, so rowid is the durable insertion-order tie-breaker.
                'SELECT j.id FROM screenshot_job j INNER JOIN finding f ON f.id = j.finding_id WHERE j.status = :status AND '.$this->normalFindingSql().' ORDER BY j.requested_at ASC, j.rowid ASC LIMIT 1',
                ['status' => ScreenshotJobStatus::QUEUED] + self::normalFindingParameters(),
            );
            if (!is_string($id) || $id === '') {
                return null;
            }

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $updated = $connection->executeStatement(
                // Recheck the persisted finding when claiming: it may have been
                // discarded after selection, even if a managed entity is stale.
                'UPDATE screenshot_job SET status = :running, started_at = :now, attempts = attempts + 1, updated_at = :now WHERE id = :id AND status = :queued AND EXISTS (SELECT 1 FROM finding f WHERE f.id = screenshot_job.finding_id AND '.$this->normalFindingSql().')',
                [
                    'running' => ScreenshotJobStatus::RUNNING,
                    'queued' => ScreenshotJobStatus::QUEUED,
                    'now' => $now,
                    'id' => $id,
                ] + self::normalFindingParameters(),
            );
            if ($updated === 1) {
                $job = $this->find($id);
                if ($job instanceof ScreenshotJob) {
                    $this->getEntityManager()->refresh($job);
                }

                return $job;
            }
        }

        return null;
    }

    public function failRepeatedlyInterrupted(int $maxAttempts = 3): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $failed = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE screenshot_job SET status = :failed, active_key = NULL, finished_at = :now, error_message = :error, updated_at = :now WHERE status = :running AND attempts >= :max_attempts',
            [
                'failed' => ScreenshotJobStatus::FAILED,
                'running' => ScreenshotJobStatus::RUNNING,
                'error' => sprintf('Screenshot worker stopped %d times while processing this job; manual retry is required.', $maxAttempts),
                'now' => $now,
                'max_attempts' => $maxAttempts,
            ],
        );
        if ($failed > 0) {
            $this->getEntityManager()->clear();
        }

        return $failed;
    }

    public function recoverInterrupted(int $maxAttempts = 3): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $recovered = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE screenshot_job SET status = :queued, started_at = NULL, error_message = :error, updated_at = :now WHERE status = :running AND attempts < :max_attempts',
            [
                'queued' => ScreenshotJobStatus::QUEUED,
                'running' => ScreenshotJobStatus::RUNNING,
                'error' => 'Previous screenshot worker stopped before completing this job; queued again.',
                'now' => $now,
                'max_attempts' => $maxAttempts,
            ],
        );
        if ($recovered > 0) {
            $this->getEntityManager()->clear();
        }

        return $recovered;
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(j.id) FROM screenshot_job j INNER JOIN finding f ON f.id = j.finding_id WHERE j.status = :status AND '.$this->normalFindingSql(),
            ['status' => $status] + self::normalFindingParameters(),
        );
    }

    /** @return array{queued: int, running: int, failed: int} Read-only counters displayed in the inventory. */
    public function inventoryCounts(): array
    {
        $counts = [ScreenshotJobStatus::QUEUED => 0, ScreenshotJobStatus::RUNNING => 0, ScreenshotJobStatus::FAILED => 0];
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT j.status, COUNT(j.id) AS total FROM screenshot_job j INNER JOIN finding f ON f.id = j.finding_id'
            .' WHERE j.status IN (:queued, :running, :failed) AND '.$this->normalFindingSql().' GROUP BY j.status',
            ['queued' => ScreenshotJobStatus::QUEUED, 'running' => ScreenshotJobStatus::RUNNING, 'failed' => ScreenshotJobStatus::FAILED] + self::normalFindingParameters(),
        );
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    private function normalFindingSql(): string
    {
        return '(f.manual_assessment IS NULL OR f.manual_assessment <> :discarded) AND f.status NOT IN (:duplicate, :discarded) AND '.\App\Service\FindingWorkPolicy::checksAllowedSql($this->getEntityManager()->getConnection());
    }

    /** @return array<string, string> */
    private static function normalFindingParameters(): array
    {
        return ['duplicate' => FindingStatus::DUPLICATE, 'discarded' => FindingStatus::DISCARDED];
    }
}
