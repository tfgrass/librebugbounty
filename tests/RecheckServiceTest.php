<?php

namespace App\Tests;

use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;
use App\Dto\ScreenshotEnqueueResult;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScreenshotJob;
use App\Service\BrowserRetestClientInterface;
use App\Service\RecheckPolicy;
use App\Service\RecheckService;
use App\Service\RetestService;
use App\Service\ScreenshotEnqueuerInterface;
use App\Service\ValidationService;
use App\Value\ManualAssessment;
use App\Value\RetestResult;

final class RecheckServiceTest extends UnitTestCase
{
    /** @var list<Finding> */
    private array $queuedScreenshots = [];

    private function wireClaimConnection(
        \Doctrine\ORM\EntityManagerInterface $entityManager,
        array $repos,
        int $claimResult = 1,
    ): void {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('beginTransaction')->willReturnCallback(static function (): void {
        });
        $connection->method('commit')->willReturnCallback(static function (): void {
        });
        $connection->method('rollBack')->willReturnCallback(static function (): void {
        });
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use ($repos, $claimResult): int {
                if (str_contains($sql, 'WHERE id = ? AND next_due_at = ?')) {
                    if ($claimResult !== 1) {
                        return 0;
                    }
                    // Simulate the leased claim so later assertions see it.
                    foreach ($repos['findings']->findDueForRecheck(new \DateTimeImmutable('+100 years')) as $finding) {
                        if ($finding->getId() === $params[1]) {
                            $finding->setNextDueAt(new \DateTimeImmutable($params[0]));
                        }
                    }

                    return 1;
                }
                if (str_contains($sql, 'WHERE domain_id = ? AND id != ?')) {
                    $updated = 0;
                    foreach ($repos['findings']->findDueForRecheck(new \DateTimeImmutable('+100 years')) as $finding) {
                        if ($finding->getDomain()->getId() === $params[1]
                            && $finding->getId() !== $params[2]
                            && $finding->getNextDueAt() !== null
                            && $finding->getNextDueAt() < new \DateTimeImmutable($params[3])) {
                            $finding->setNextDueAt(new \DateTimeImmutable($params[0]));
                            $updated++;
                        }
                    }

                    return $updated;
                }

                return 0;
            },
        );
        $entityManager->method('getConnection')->willReturn($connection);
    }

    public function testProcessNextRetestsTheOldestDueFindingHeadlessWithoutScreenshot(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);
        $this->wireClaimConnection($entityManager, $repos);

        $requests = [];
        $client = new class($requests) implements BrowserRetestClientInterface {
            public function __construct(public array &$requests)
            {
            }

            public function retest(BrowserRetestRequest $request): RetestResultData
            {
                $this->requests[] = $request;

                return new RetestResultData(
                    result: RetestResult::STILL_VULNERABLE,
                    httpStatus: 200,
                    finalUrl: $request->url,
                    observedEvidence: 'dialog',
                    dialogText: 'dialog',
                    raw: ['mocked' => true],
                );
            }
        };

        $older = $this->dueFinding($entityManager, 'a.example', '-40 days', 'reported');
        $this->dueFinding($entityManager, 'b.example', '-30 days', 'reported');
        $future = $this->dueFinding($entityManager, 'c.example', '+5 days', 'reported');

        $service = $this->createService($entityManager, $repos, $client);
        $outcome = $service->processNext();

        self::assertSame(RetestResult::STILL_VULNERABLE, $outcome);
        self::assertCount(1, $client->requests);
        $request = $client->requests[0];
        self::assertSame($older->getUrl(), $request->url);
        self::assertTrue($request->headless);
        self::assertFalse($request->screenshot);
        self::assertSame([], $this->queuedScreenshots, 'A stable result does not start serial screenshot work.');
        self::assertSame('reported', $older->getStatus(), 'still_vulnerable keeps reported findings reported.');
        self::assertNotNull($older->getNextDueAt());
        self::assertGreaterThan(new \DateTimeImmutable('+27 days'), $older->getNextDueAt());
        self::assertNotNull($future->getNextDueAt());
    }

    public function testOutOfScopeAndFixedFindingsAreDroppedFromTheSchedule(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);
        $this->wireClaimConnection($entityManager, $repos);

        $client = $this->neverCalledClient();
        $fixed = $this->dueFinding($entityManager, 'fixed.example', '-40 days', 'fixed');
        $open = $this->dueFinding($entityManager, 'open.example', '-39 days', 'reported');

        $service = $this->createService($entityManager, $repos, $client);
        $claimed = $service->claimNext();

        self::assertSame($open, $claimed, 'Fixed findings leave the recheck scope (D4).');
        self::assertNull($fixed->getNextDueAt());
    }

    public function testProtectedAssessmentIsSkippedWithoutBrowserWork(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);
        $this->wireClaimConnection($entityManager, $repos);

        $client = $this->neverCalledClient();
        $protected = $this->dueFinding($entityManager, 'human.example', '-40 days', 'verified');
        $protected->setManualAssessment(ManualAssessment::CONFIRMED, null, new \DateTimeImmutable());

        $service = $this->createService($entityManager, $repos, $client);
        $outcome = $service->processNext();

        self::assertSame('skipped-protected', $outcome);
        self::assertNotNull($protected->getNextDueAt());
        self::assertGreaterThan(new \DateTimeImmutable('+27 days'), $protected->getNextDueAt());
    }

    public function testDomainSpacingLeavesTheSecondFindingOfTheSameDomainDue(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);
        $this->wireClaimConnection($entityManager, $repos);

        $client = $this->fixedResultClient();
        $first = $this->dueFinding($entityManager, 'same.example', '-40 days', 'reported');
        $second = $this->dueFinding($entityManager, 'same.example', '-39 days', 'reported');
        $other = $this->dueFinding($entityManager, 'other.example', '-38 days', 'reported');

        $service = $this->createService($entityManager, $repos, $client);

        self::assertSame(RetestResult::FIXED, $service->processNext());
        self::assertSame('fixed', $first->getStatus());

        // The same-domain candidate is skipped while another domain is due.
        self::assertSame(RetestResult::FIXED, $service->processNext());
        self::assertSame('fixed', $other->getStatus());
        self::assertSame('reported', $second->getStatus());
        self::assertNotNull($second->getNextDueAt());
        self::assertGreaterThan(new \DateTimeImmutable('+50 minutes'), $second->getNextDueAt(), 'Same-domain candidate is pushed back by the spacing window.');
        self::assertLessThan(new \DateTimeImmutable('+70 minutes'), $second->getNextDueAt());

        // Nothing claimable anymore within the spacing window.
        self::assertNull($service->claimNext());
    }

    public function testLostClaimRaceFallsThroughToTheNextCandidate(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        // The first UPDATE always loses the race (another worker won).
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('beginTransaction')->willReturnCallback(static function (): void {
        });
        $connection->method('rollBack')->willReturnCallback(static function (): void {
        });
        $connection->method('commit')->willReturnCallback(static function (): void {
        });
        $firstUpdateSeen = false;
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$firstUpdateSeen, $repos): int {
                if (str_contains($sql, 'WHERE id = ? AND next_due_at = ?')) {
                    if (!$firstUpdateSeen) {
                        $firstUpdateSeen = true;

                        return 0; // lost the race
                    }
                    foreach ($repos['findings']->findDueForRecheck(new \DateTimeImmutable('+100 years')) as $finding) {
                        if ($finding->getId() === $params[1]) {
                            $finding->setNextDueAt(new \DateTimeImmutable($params[0]));
                        }
                    }

                    return 1;
                }

                return 0;
            },
        );
        $entityManager->method('getConnection')->willReturn($connection);

        $client = $this->fixedResultClient();
        $stolen = $this->dueFinding($entityManager, 'stolen.example', '-40 days', 'reported');
        $next = $this->dueFinding($entityManager, 'next.example', '-39 days', 'reported');

        $service = $this->createService($entityManager, $repos, $client);
        $claimed = $service->claimNext();

        self::assertSame($next, $claimed, 'A lost claim race falls through to the next due finding.');
        self::assertNotNull($stolen->getNextDueAt(), 'The stolen finding keeps its slot.');
    }

    public function testRetestExceptionKeepsTheSlotWithShortBackoff(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $client = new class implements BrowserRetestClientInterface {
            public function retest(BrowserRetestRequest $request): RetestResultData
            {
                throw new \RuntimeException('worker unreachable');
            }
        };
        $entityManager->method('contains')->willReturn(true);
        $this->wireClaimConnection($entityManager, $repos);

        $finding = $this->dueFinding($entityManager, 'down.example', '-40 days', 'reported');

        $service = $this->createService($entityManager, $repos, $client);
        $outcome = $service->processNext();

        self::assertStringStartsWith('error: worker unreachable', $outcome);
        self::assertSame('reported', $finding->getStatus());
        self::assertNotNull($finding->getNextDueAt());
        self::assertLessThan(new \DateTimeImmutable('+4 days'), $finding->getNextDueAt());
        self::assertGreaterThan(new \DateTimeImmutable('+2 days'), $finding->getNextDueAt());
    }

    public function testChangedResultQueuesAScreenshotWithoutMakingTheRetestCaptureOne(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);
        $this->wireClaimConnection($entityManager, $repos);

        $requests = [];
        $client = new class($requests) implements BrowserRetestClientInterface {
            public function __construct(public array &$requests)
            {
            }

            public function retest(BrowserRetestRequest $request): RetestResultData
            {
                $this->requests[] = $request;

                return new RetestResultData(
                    result: RetestResult::FIXED,
                    httpStatus: 200,
                    finalUrl: $request->url,
                    raw: ['mocked' => true],
                );
            }
        };

        $finding = $this->dueFinding($entityManager, 'changed.example', '-40 days', 'reported');
        $service = $this->createService($entityManager, $repos, $client);

        self::assertSame(RetestResult::FIXED, $service->processNext());
        self::assertFalse($requests[0]->screenshot, 'The parallel recheck remains headless and screenshot-free.');
        self::assertCount(1, $this->queuedScreenshots);
        self::assertSame($finding, $this->queuedScreenshots[0]);
    }

    /** @var array<string, Domain> */
    private array $recheckDomains = [];

    private function dueFinding(
        \Doctrine\ORM\EntityManagerInterface $entityManager,
        string $hostname,
        string $dueAt,
        string $status,
    ): Finding {
        $domain = $this->recheckDomains[$hostname] ?? null;
        if ($domain === null) {
            $domain = new Domain();
            $domain->setHostname($hostname);
            $domain->setScheme('https');
            $domain->setAuthorized(true);
            $entityManager->persist($domain);
            $this->recheckDomains[$hostname] = $domain;
        }

        $finding = new Finding();
        $finding->setDomain($domain);
        $finding->setTitle('Due finding '.$hostname);
        $finding->setType(self::DEFAULT_FINDING_TYPE);
        $finding->setSeverity('medium');
        $finding->setStatus($status);
        $finding->setUrl('https://'.$hostname.'/search?q=token');
        $finding->setMethod('GET');
        $finding->setSubmittedAt(new \DateTimeImmutable('-60 days'));
        $finding->setNextDueAt(new \DateTimeImmutable($dueAt));
        $entityManager->persist($finding);

        return $finding;
    }

    private function neverCalledClient(): BrowserRetestClientInterface
    {
        return new class implements BrowserRetestClientInterface {
            public function retest(BrowserRetestRequest $request): RetestResultData
            {
                throw new \LogicException('The browser client must not be called.');
            }
        };
    }

    private function fixedResultClient(): BrowserRetestClientInterface
    {
        return new class implements BrowserRetestClientInterface {
            public function retest(BrowserRetestRequest $request): RetestResultData
            {
                return new RetestResultData(
                    result: RetestResult::FIXED,
                    httpStatus: 200,
                    finalUrl: $request->url,
                    raw: ['mocked' => true],
                );
            }
        };
    }

    private function createService(
        \Doctrine\ORM\EntityManagerInterface $entityManager,
        array $repos,
        BrowserRetestClientInterface $client,
        ?ScreenshotEnqueuerInterface $screenshots = null,
    ): RecheckService {
        $policy = new RecheckPolicy();
        $screenshots ??= new class($this->queuedScreenshots) implements ScreenshotEnqueuerInterface {
            public function __construct(private array &$queued)
            {
            }

            public function enqueue(Finding $finding): ScreenshotEnqueueResult
            {
                $this->queued[] = $finding;
                $job = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl());

                return new ScreenshotEnqueueResult($job, true);
            }
        };

        return new RecheckService(
            $repos['findings'],
            new RetestService(
                $entityManager,
                $repos['retestRuns'],
                $client,
                new ValidationService($this->createValidator()),
                $this->storage,
                $policy,
            ),
            $policy,
            $entityManager,
            $repos['retestRuns'],
            $screenshots,
        );
    }
}
