<?php

namespace App\Tests;

use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\BrowserRetestClientInterface;
use App\Service\DomainService;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use App\Service\RetestService;
use App\Service\ValidationService;
use App\Value\EvidenceKind;
use App\Value\RetestResult;
use Symfony\Component\Filesystem\Filesystem;

final class RetestServiceTest extends UnitTestCase
{
    public function testUnauthorizedDomainsCanStillBeRetested(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domain = new Domain();
        $domain->setHostname('example.com');
        $domain->setScheme('https');
        $domain->setAuthorized(false);
        $entityManager->persist($domain);

        $finding = new Finding();
        $finding->setDomain($domain);
        $finding->setTitle('Stored finding');
        $finding->setType('other');
        $finding->setSeverity('low');
        $finding->setStatus('new');
        $finding->setUrl('https://example.com/');
        $finding->setMethod('GET');
        $entityManager->persist($finding);

        $service = $this->createRetestService($entityManager, $repos, new class implements BrowserRetestClientInterface {
            public function retest(BrowserRetestRequest $request): RetestResultData
            {
                return new RetestResultData(
                    result: RetestResult::STILL_VULNERABLE,
                    httpStatus: 200,
                    finalUrl: $request->url,
                    observedEvidence: 'dialog',
                    dialogText: 'dialog',
                    screenshotBase64: base64_encode('shot-1'),
                    raw: ['mocked' => true],
                );
            }
        });

        $run = $service->retest($finding, true);

        self::assertSame(RetestResult::STILL_VULNERABLE, $run->getResult());
        self::assertSame('verified', $finding->getStatus());
        self::assertNotNull($run->getScreenshotPath());
        self::assertCount(1, $repos['evidence']->findBy(['finding' => $finding, 'kind' => EvidenceKind::SCREENSHOT]));
    }

    public function testBrowserRetestFindsExpectedEvidence(): void
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
        $finding->setTitle('Reflected XSS');
        $finding->setType(self::DEFAULT_FINDING_TYPE);
        $finding->setSeverity('medium');
        $finding->setStatus('new');
        $finding->setUrl('https://example.com/search?q=xss-token-123');
        $finding->setMethod('GET');
        $finding->setExpectedEvidence('xss-token-123');
        $entityManager->persist($finding);

        $service = $this->createRetestService($entityManager, $repos, new class implements BrowserRetestClientInterface {
            public function retest(BrowserRetestRequest $request): RetestResultData
            {
                return new RetestResultData(
                    result: RetestResult::STILL_VULNERABLE,
                    httpStatus: 200,
                    finalUrl: $request->url,
                    observedEvidence: $request->expectedEvidence,
                    dialogText: $request->expectedEvidence,
                    screenshotBase64: base64_encode('shot-2'),
                    raw: ['mocked' => true],
                );
            }
        });

        $run = $service->retest($finding);

        self::assertSame(RetestResult::STILL_VULNERABLE, $run->getResult());
        self::assertSame('xss-token-123', $run->getObservedEvidence());
        self::assertNotNull($run->getFinishedAt());
        self::assertNotNull($run->getScreenshotPath());
        self::assertCount(1, $repos['evidence']->findBy(['finding' => $finding, 'kind' => EvidenceKind::SCREENSHOT]));
    }

    public function testBrowserSelectionIsForwardedToTheWorker(): void
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
        $finding->setTitle('Browser choice');
        $finding->setType(self::DEFAULT_FINDING_TYPE);
        $finding->setSeverity('medium');
        $finding->setStatus('new');
        $finding->setUrl('https://example.com/search?q=xss-token-123');
        $finding->setMethod('GET');
        $finding->setExpectedEvidence('xss-token-123');
        $entityManager->persist($finding);

        $capture = new class {
            public ?string $browser = null;
        };
        $service = $this->createRetestService($entityManager, $repos, new class($capture) implements BrowserRetestClientInterface {
            public function __construct(private readonly object $capture)
            {
            }

            public function retest(BrowserRetestRequest $request): RetestResultData
            {
                $this->capture->browser = $request->browser;

                return new RetestResultData(
                    result: RetestResult::STILL_VULNERABLE,
                    httpStatus: 200,
                    finalUrl: $request->url,
                    observedEvidence: $request->expectedEvidence,
                    dialogText: $request->expectedEvidence,
                    screenshotBase64: base64_encode('shot-3'),
                );
            }
        });

        $service->retest($finding, false, 120000, false, false, 'firefox');

        self::assertSame('firefox', $capture->browser);
    }

    public function testFixedFindingReopensToVerifiedWhenBrowserStillFindsEvidence(): void
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
        $finding->setTitle('Previously fixed finding');
        $finding->setType(self::DEFAULT_FINDING_TYPE);
        $finding->setSeverity('medium');
        $finding->setStatus('fixed');
        $finding->setUrl('https://example.com/search?q=xss-token-123');
        $finding->setMethod('GET');
        $finding->setExpectedEvidence('xss-token-123');
        $entityManager->persist($finding);

        $service = $this->createRetestService($entityManager, $repos, new class implements BrowserRetestClientInterface {
            public function retest(BrowserRetestRequest $request): RetestResultData
            {
                return new RetestResultData(
                    result: RetestResult::STILL_VULNERABLE,
                    httpStatus: 200,
                    finalUrl: $request->url,
                    observedEvidence: $request->expectedEvidence,
                    dialogText: $request->expectedEvidence,
                    screenshotBase64: base64_encode('shot-4'),
                    raw: ['mocked' => true],
                );
            }
        });

        $service->retest($finding);

        self::assertSame('verified', $finding->getStatus());
        self::assertCount(1, $repos['evidence']->findBy(['finding' => $finding, 'kind' => EvidenceKind::SCREENSHOT]));
    }

    public function testBrowserRetestClientIsAbstractedAndMockable(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domainService = new DomainService(
            $repos['domains'],
            $entityManager,
            new ValidationService($this->createValidator()),
        );
        $domainService->upsertDomain('example.com', 'https', true);

        $findingService = new FindingService(
            $domainService,
            $repos['findings'],
            $entityManager,
            new ValidationService($this->createValidator()),
            new Filesystem(),
        );
        $finding = $findingService->createFinding(
            hostname: 'example.com',
            title: 'Browser finding',
            type: self::DEFAULT_FINDING_TYPE,
            severity: 'medium',
            url: 'https://example.com/search?q=token',
            expectedEvidence: 'token',
        );

        $browserClient = new class implements BrowserRetestClientInterface {
            public function retest(BrowserRetestRequest $request): RetestResultData
            {
                return new RetestResultData(
                    result: RetestResult::STILL_VULNERABLE,
                    httpStatus: 200,
                    finalUrl: $request->url,
                    observedEvidence: $request->expectedEvidence,
                    dialogText: $request->expectedEvidence,
                    raw: ['mocked' => true],
                );
            }
        };

        $service = $this->createRetestService($entityManager, $repos, $browserClient);

        $run = $service->retest($finding, true);

        self::assertSame(RetestResult::STILL_VULNERABLE, $run->getResult());
        self::assertSame('browser', $run->getMode());
    }

    private function createRetestService(
        \Doctrine\ORM\EntityManagerInterface $entityManager,
        array $repos,
        BrowserRetestClientInterface $browserRetestClient,
    ): RetestService {
        return new RetestService(
            $entityManager,
            $repos['retestRuns'],
            $browserRetestClient,
            new \App\Service\ValidationService($this->createValidator()),
            new class implements EvidenceStorageInterface {
                public function storeFile(\App\Entity\Finding $finding, string $sourcePath, ?string $targetFilename = null): \App\Dto\StoredEvidenceResult
                {
                    return new \App\Dto\StoredEvidenceResult('storage/artifacts/mock', 'deadbeef');
                }
            },
        );
    }
}
