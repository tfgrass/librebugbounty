<?php

namespace App\Tests;

use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\BrowserRetestClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\RetestService;
use App\Service\ReviewService;
use App\Service\ValidationService;
use App\Value\RetestResult;

final class ReviewServiceTest extends UnitTestCase
{
    public function testReviewScanProcessesAllNonConfirmedFindingsAndSkipsConfirmedFixed(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domain = new Domain();
        $domain->setHostname('example.com');
        $domain->setScheme('https');
        $domain->setAuthorized(true);
        $entityManager->persist($domain);

        $pendingFinding = new Finding();
        $pendingFinding->setDomain($domain);
        $pendingFinding->setTitle('Pending');
        $pendingFinding->setType(self::DEFAULT_FINDING_TYPE);
        $pendingFinding->setSeverity('medium');
        $pendingFinding->setStatus('new');
        $pendingFinding->setUrl('https://example.com/pending?q=test');
        $pendingFinding->setMethod('GET');
        $pendingFinding->setSubmittedAt(new \DateTimeImmutable('-1 day'));
        $entityManager->persist($pendingFinding);

        $confirmedFinding = new Finding();
        $confirmedFinding->setDomain($domain);
        $confirmedFinding->setTitle('Confirmed');
        $confirmedFinding->setType(self::DEFAULT_FINDING_TYPE);
        $confirmedFinding->setSeverity('medium');
        $confirmedFinding->setStatus('fixed');
        $confirmedFinding->setReviewState('confirmed_fixed');
        $confirmedFinding->setUrl('https://example.com/confirmed?q=test');
        $confirmedFinding->setMethod('GET');
        $confirmedFinding->setSubmittedAt(new \DateTimeImmutable());
        $entityManager->persist($confirmedFinding);

        $capture = new \stdClass();
        $capture->browserCalls = [];
        $browserClient = new class($capture) implements BrowserRetestClientInterface {
            public function __construct(private object $capture)
            {
            }

            public function retest(BrowserRetestRequest $request): RetestResultData
            {
                $this->capture->browserCalls[] = $request->browser;

                return new RetestResultData(
                    result: RetestResult::STILL_VULNERABLE,
                    httpStatus: 200,
                    finalUrl: $request->url,
                    observedEvidence: $request->expectedEvidence,
                    dialogText: $request->expectedEvidence,
                );
            }
        };

        $reviewService = new ReviewService(
            $repos['findings'],
            new RetestService(
                $entityManager,
                $repos['retestRuns'],
                $browserClient,
                new ValidationService($this->createValidator()),
                new class implements EvidenceStorageInterface {
                    public function storeFile(\App\Entity\Finding $finding, string $sourcePath, ?string $targetFilename = null): \App\Dto\StoredEvidenceResult
                    {
                        return new \App\Dto\StoredEvidenceResult('storage/artifacts/mock', 'deadbeef');
                    }
                },
            ),
            $entityManager,
        );

        $processed = $reviewService->scan();

        self::assertSame(1, $processed);
        self::assertSame(['chromium', 'firefox'], $capture->browserCalls);
        self::assertSame('verified', $pendingFinding->getStatus());
        self::assertNull($pendingFinding->getReviewState());
        self::assertSame('fixed', $confirmedFinding->getStatus());
        self::assertSame('confirmed_fixed', $confirmedFinding->getReviewState());
    }

    public function testReviewScanMarksFixedAndInconclusiveResultsForManualChecking(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domain = new Domain();
        $domain->setHostname('example.com');
        $domain->setScheme('https');
        $domain->setAuthorized(true);
        $entityManager->persist($domain);

        $finding = new Finding();
        $finding->setDomain($domain);
        $finding->setTitle('Manual check');
        $finding->setType(self::DEFAULT_FINDING_TYPE);
        $finding->setSeverity('medium');
        $finding->setStatus('verified');
        $finding->setUrl('https://example.com/manual?q=test');
        $finding->setMethod('GET');
        $finding->setSubmittedAt(new \DateTimeImmutable('-1 day'));
        $entityManager->persist($finding);

        $capture = new \stdClass();
        $capture->results = [RetestResult::FIXED, RetestResult::INCONCLUSIVE];
        $capture->index = 0;
        $browserClient = new class($capture) implements BrowserRetestClientInterface {
            public function __construct(private object $capture)
            {
            }

            public function retest(BrowserRetestRequest $request): RetestResultData
            {
                $result = $this->capture->results[$this->capture->index];
                $this->capture->index++;

                return new RetestResultData(
                    result: $result,
                    httpStatus: 200,
                    finalUrl: $request->url,
                    observedEvidence: $request->expectedEvidence,
                    dialogText: $request->expectedEvidence,
                );
            }
        };

        $reviewService = new ReviewService(
            $repos['findings'],
            new RetestService(
                $entityManager,
                $repos['retestRuns'],
                $browserClient,
                new ValidationService($this->createValidator()),
                new class implements EvidenceStorageInterface {
                    public function storeFile(\App\Entity\Finding $finding, string $sourcePath, ?string $targetFilename = null): \App\Dto\StoredEvidenceResult
                    {
                        return new \App\Dto\StoredEvidenceResult('storage/artifacts/mock', 'deadbeef');
                    }
                },
            ),
            $entityManager,
        );

        $processed = $reviewService->scan();

        self::assertSame(1, $processed);
        self::assertSame('fixed', $finding->getStatus());
        self::assertSame('manual_checking', $finding->getReviewState());
    }

    public function testManualCheckingFindingsKeepTheirStatusAndStayInManualReview(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domain = new Domain();
        $domain->setHostname('example.com');
        $domain->setScheme('https');
        $domain->setAuthorized(true);
        $entityManager->persist($domain);

        $finding = new Finding();
        $finding->setDomain($domain);
        $finding->setTitle('Manual review');
        $finding->setType(self::DEFAULT_FINDING_TYPE);
        $finding->setSeverity('medium');
        $finding->setStatus('verified');
        $finding->setReviewState('manual_checking');
        $finding->setUrl('https://example.com/manual-review?q=test');
        $finding->setMethod('GET');
        $finding->setSubmittedAt(new \DateTimeImmutable('-1 day'));
        $entityManager->persist($finding);

        $capture = new \stdClass();
        $capture->browserCalls = [];
        $browserClient = new class($capture) implements BrowserRetestClientInterface {
            public function __construct(private object $capture)
            {
            }

            public function retest(BrowserRetestRequest $request): RetestResultData
            {
                $this->capture->browserCalls[] = $request->browser;

                return new RetestResultData(
                    result: RetestResult::FIXED,
                    httpStatus: 200,
                    finalUrl: $request->url,
                    observedEvidence: $request->expectedEvidence,
                    dialogText: $request->expectedEvidence,
                );
            }
        };

        $reviewService = new ReviewService(
            $repos['findings'],
            new RetestService(
                $entityManager,
                $repos['retestRuns'],
                $browserClient,
                new ValidationService($this->createValidator()),
                new class implements EvidenceStorageInterface {
                    public function storeFile(\App\Entity\Finding $finding, string $sourcePath, ?string $targetFilename = null): \App\Dto\StoredEvidenceResult
                    {
                        return new \App\Dto\StoredEvidenceResult('storage/artifacts/mock', 'deadbeef');
                    }
                },
            ),
            $entityManager,
        );

        $processed = $reviewService->scan();

        self::assertSame(0, $processed);
        self::assertSame([], $capture->browserCalls);
        self::assertSame('verified', $finding->getStatus());
        self::assertSame('manual_checking', $finding->getReviewState());
    }

    public function testPendingFindingsPreferNewOverOtherOpenStatuses(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domain = new Domain();
        $domain->setHostname('example.com');
        $domain->setScheme('https');
        $domain->setAuthorized(true);
        $entityManager->persist($domain);

        $verified = new Finding();
        $verified->setDomain($domain);
        $verified->setTitle('Verified');
        $verified->setType(self::DEFAULT_FINDING_TYPE);
        $verified->setSeverity('medium');
        $verified->setStatus('verified');
        $verified->setUrl('https://example.com/verified?q=test');
        $verified->setMethod('GET');
        $verified->setSubmittedAt(new \DateTimeImmutable('-1 day'));
        $entityManager->persist($verified);

        $new = new Finding();
        $new->setDomain($domain);
        $new->setTitle('New');
        $new->setType(self::DEFAULT_FINDING_TYPE);
        $new->setSeverity('medium');
        $new->setStatus('new');
        $new->setUrl('https://example.com/new?q=test');
        $new->setMethod('GET');
        $new->setSubmittedAt(new \DateTimeImmutable('-2 days'));
        $entityManager->persist($new);

        $reviewService = new ReviewService(
            $repos['findings'],
            new RetestService(
                $entityManager,
                $repos['retestRuns'],
                new class implements BrowserRetestClientInterface {
                    public function retest(BrowserRetestRequest $request): RetestResultData
                    {
                        return new RetestResultData(
                            result: RetestResult::STILL_VULNERABLE,
                            httpStatus: 200,
                            finalUrl: $request->url,
                            observedEvidence: $request->expectedEvidence,
                            dialogText: $request->expectedEvidence,
                        );
                    }
                },
                new ValidationService($this->createValidator()),
                new class implements EvidenceStorageInterface {
                    public function storeFile(\App\Entity\Finding $finding, string $sourcePath, ?string $targetFilename = null): \App\Dto\StoredEvidenceResult
                    {
                        return new \App\Dto\StoredEvidenceResult('storage/artifacts/mock', 'deadbeef');
                    }
                },
            ),
            $entityManager,
        );

        $targets = $reviewService->getPendingFindings();

        self::assertSame('new', $targets[0]->getStatus());
        self::assertSame('verified', $targets[1]->getStatus());
    }
}
