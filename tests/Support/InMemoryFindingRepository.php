<?php

namespace App\Tests\Support;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Value\FindingStatus;

final class InMemoryFindingRepository extends FindingRepository
{
    /** @var array<string, Finding> */
    private array $findings = [];

    public function __construct()
    {
    }

    public function add(Finding $finding): void
    {
        $this->findings[$finding->getId()] = $finding;
    }

    public function remove(Finding $finding): void
    {
        unset($this->findings[$finding->getId()]);
    }

    public function findOneByDomainAndUrl(Domain $domain, string $url): ?Finding
    {
        foreach ($this->findings as $finding) {
            if ($finding->getDomain()->getHostname() === $domain->getHostname() && $finding->getUrl() === $url) {
                return $finding;
            }
        }

        return null;
    }

    public function find(mixed $id, \Doctrine\DBAL\LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        $id = (string) $id;

        return $this->findings[$id] ?? null;
    }

    public function findByDomainAndStatus(?Domain $domain = null, ?string $status = null, ?string $type = null, ?string $severity = null): array
    {
        return array_values(array_filter($this->findings, static function (Finding $finding) use ($domain, $status, $type, $severity): bool {
            if ($domain !== null && $finding->getDomain()->getHostname() !== $domain->getHostname()) {
                return false;
            }
            if (!self::matchesReadableStatus($finding, $status)) {
                return false;
            }
            if ($type !== null && $finding->getType() !== $type) {
                return false;
            }
            if ($severity !== null && $finding->getSeverity() !== $severity) {
                return false;
            }

            return true;
        }));
    }

    public function findPageByDomainAndStatus(?string $domainQuery = null, ?string $status = null, ?string $bucket = null, int $limit = 50, int $offset = 0): array
    {
        $results = array_values(array_filter($this->findings, static function (Finding $finding) use ($domainQuery, $status): bool {
            if ($domainQuery !== null && $domainQuery !== '' && !str_contains(strtolower($finding->getDomain()->getHostname()), strtolower($domainQuery))) {
                return false;
            }
            if (!self::matchesReadableStatus($finding, $status)) {
                return false;
            }

            return true;
        }));

        usort($results, static function (Finding $a, Finding $b): int {
            $aDate = $a->getSubmittedAt() ?? $a->getCreatedAt();
            $bDate = $b->getSubmittedAt() ?? $b->getCreatedAt();

            return $bDate <=> $aDate;
        });

        $results = array_values(array_filter($results, static function (Finding $finding) use ($bucket): bool {
            if ($bucket === null || $bucket === '') {
                return true;
            }

            return match ($bucket) {
                'open' => in_array($finding->getStatus(), ['new', 'verified', 'reported'], true)
                    && in_array($finding->getReviewState(), [null, 'manually_checked'], true)
                    && $finding->getLastRetestedAt() !== null,
                'fixed' => $finding->getStatus() === 'fixed',
                'manual_review' => $finding->getReviewState() === 'manual_checking',
                'unchecked' => $finding->getLastRetestedAt() === null,
                default => throw new \InvalidArgumentException(sprintf('Unsupported bucket "%s".', $bucket)),
            };
        }));

        return array_slice($results, max(0, $offset), $limit);
    }

    public function countByDomainAndStatus(?string $domainQuery = null, ?string $status = null, ?string $bucket = null): int
    {
        return count($this->findPageByDomainAndStatus($domainQuery, $status, $bucket, 1000000, 0));
    }

    public function countAllFindings(): int
    {
        return count(array_filter($this->findings, static fn (Finding $finding): bool => !$finding->isDiscarded()));
    }

    public function countByStatuses(array $statuses): int
    {
        return count(array_filter($this->findings, static function (Finding $finding) use ($statuses): bool {
            foreach ($statuses as $status) {
                if (self::matchesReadableStatus($finding, $status)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function countByBucket(string $bucket): int
    {
        return $this->countByDomainAndStatus(null, null, $bucket);
    }

    public function countOpenFindingsForDomain(Domain $domain): int
    {
        $count = 0;
        foreach ($this->findings as $finding) {
            if ($finding->isDiscarded()) {
                continue;
            }
            if ($finding->getDomain()->getHostname() !== $domain->getHostname()) {
                continue;
            }

            if (in_array($finding->getStatus(), ['new', 'verified', 'reported'], true)
                && in_array($finding->getReviewState(), [null, 'manually_checked'], true)
            ) {
                $count++;
            }
        }

        return $count;
    }

    public function findDueForRetest(?\DateTimeImmutable $olderThan = null, ?Domain $domain = null, ?string $status = null, int $limit = 20): array
    {
        $olderThan ??= new \DateTimeImmutable('-30 days');
        $results = [];

        foreach ($this->findings as $finding) {
            if ($finding->isDiscarded()) {
                continue;
            }
            if ($domain !== null && $finding->getDomain()->getHostname() !== $domain->getHostname()) {
                continue;
            }
            if ($status !== null && $finding->getStatus() !== $status) {
                continue;
            }
            if (!in_array($finding->getStatus(), ['new', 'verified', 'reported'], true)) {
                continue;
            }
            $lastRetestedAt = $finding->getLastRetestedAt();
            if ($lastRetestedAt !== null && $lastRetestedAt > $olderThan) {
                continue;
            }
            $results[] = $finding;
        }

        usort($results, static function (Finding $a, Finding $b): int {
            $aDate = $a->getLastRetestedAt() ?? $a->getCreatedAt();
            $bDate = $b->getLastRetestedAt() ?? $b->getCreatedAt();

            return $aDate <=> $bDate;
        });

        return array_slice($results, 0, $limit);
    }

    public function findAllForBrowserRetest(?Domain $domain = null, ?string $status = null, int $limit = 1000): array
    {
        $results = array_values(array_filter($this->findings, static function (Finding $finding) use ($domain, $status): bool {
            if ($finding->isDiscarded()) {
                return false;
            }
            if ($domain !== null && $finding->getDomain()->getHostname() !== $domain->getHostname()) {
                return false;
            }
            if ($status !== null && $finding->getStatus() !== $status) {
                return false;
            }

            return true;
        }));

        usort($results, static function (Finding $a, Finding $b): int {
            $aDate = $a->getSubmittedAt() ?? $a->getCreatedAt();
            $bDate = $b->getSubmittedAt() ?? $b->getCreatedAt();

            return $aDate <=> $bDate;
        });

        return array_slice($results, 0, $limit);
    }

    public function findOpenFindingsWithoutEvidence(int $limit = 20): array
    {
        $results = [];

        foreach ($this->findings as $finding) {
            if ($finding->isDiscarded()) {
                continue;
            }
            if (!in_array($finding->getStatus(), ['new', 'verified', 'reported'], true)) {
                continue;
            }
            if (count($finding->getEvidence()) > 0) {
                continue;
            }

            $results[] = $finding;
        }

        usort($results, static function (Finding $a, Finding $b): int {
            $aDate = $a->getSubmittedAt() ?? $a->getCreatedAt();
            $bDate = $b->getSubmittedAt() ?? $b->getCreatedAt();

            return $aDate <=> $bDate;
        });

        return array_slice($results, 0, $limit);
    }

    public function findAllWithoutScreenshotEvidence(?Domain $domain = null, ?string $status = null, int $limit = 1000): array
    {
        return $this->findAllWithoutScreenshotEvidenceByStatuses($domain, $status !== null ? [$status] : [], $limit);
    }

    public function findAllWithoutScreenshotEvidenceByStatuses(?Domain $domain = null, array $statuses = [], int $limit = 1000): array
    {
        $results = [];
        $statuses = array_values(array_filter($statuses, static fn (string $status): bool => $status !== ''));

        foreach ($this->findings as $finding) {
            if ($finding->isDiscarded()) {
                continue;
            }
            if ($domain !== null && $finding->getDomain()->getHostname() !== $domain->getHostname()) {
                continue;
            }
            if ($statuses !== [] && !in_array($finding->getStatus(), $statuses, true)) {
                continue;
            }
            foreach ($finding->getEvidence() as $evidence) {
                if ($evidence->getKind() === 'screenshot') {
                    continue 2;
                }
            }

            $results[] = $finding;
        }

        usort($results, static function (Finding $a, Finding $b): int {
            $aDate = $a->getSubmittedAt() ?? $a->getCreatedAt();
            $bDate = $b->getSubmittedAt() ?? $b->getCreatedAt();

            return $aDate <=> $bDate;
        });

        return array_slice($results, 0, $limit);
    }

    public function findAllOrdered(int $limit = 1000): array
    {
        $results = array_values(array_filter($this->findings, static fn (Finding $finding): bool => !$finding->isDiscarded()));

        usort($results, static function (Finding $a, Finding $b): int {
            $aDate = $a->getSubmittedAt() ?? $a->getCreatedAt();
            $bDate = $b->getSubmittedAt() ?? $b->getCreatedAt();

            return $aDate <=> $bDate;
        });

        return array_slice($results, 0, $limit);
    }

    public function findRecentByFinding(Finding $finding, int $limit = 5): array
    {
        return [];
    }

    private static function matchesReadableStatus(Finding $finding, ?string $status): bool
    {
        return match ($status) {
            FindingStatus::DISCARDED => $finding->isDiscarded(),
            FindingStatus::DUPLICATE => $finding->getStatus() === FindingStatus::DUPLICATE
                || ($finding->getManualAssessment() === 'discarded' && $finding->getDiscardReason() === 'duplicate'),
            default => !$finding->isDiscarded() && ($status === null || $status === '' || $finding->getStatus() === $status),
        };
    }
}
