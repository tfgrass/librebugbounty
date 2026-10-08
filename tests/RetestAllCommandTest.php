<?php

namespace App\Tests;

use App\Command\RetestAllCommand;
use App\Dto\BrowserRetestRequest;
use App\Dto\RetestResultData;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\BrowserRetestClientInterface;
use App\Service\RetestService;
use App\Service\ValidationService;
use App\Tests\Support\InMemoryDomainRepository;
use App\Tests\Support\InMemoryFindingRepository;
use App\Tests\Support\InMemoryRetestRunRepository;
use App\Value\RetestResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RetestAllCommandTest extends UnitTestCase
{
    public function testExecuteContinuesAfterAFailure(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $domain = new Domain();
        $domain->setHostname('example.com');
        $domain->setScheme('https');
        $domain->setAuthorized(true);
        $domain->initializeTimestamps();
        $entityManager->persist($domain);

        $failingFinding = new Finding();
        $failingFinding->setDomain($domain);
        $failingFinding->setTitle('Failing');
        $failingFinding->setType(self::DEFAULT_FINDING_TYPE);
        $failingFinding->setSeverity('medium');
        $failingFinding->setStatus('new');
        $failingFinding->setUrl('https://example.com/?q=fail');
        $failingFinding->setMethod('GET');
        $failingFinding->initializeTimestamps();
        $entityManager->persist($failingFinding);

        $successfulFinding = new Finding();
        $successfulFinding->setDomain($domain);
        $successfulFinding->setTitle('Successful');
        $successfulFinding->setType(self::DEFAULT_FINDING_TYPE);
        $successfulFinding->setSeverity('medium');
        $successfulFinding->setStatus('new');
        $successfulFinding->setUrl('https://example.com/?q=ok');
        $successfulFinding->setMethod('GET');
        $successfulFinding->initializeTimestamps();
        $entityManager->persist($successfulFinding);

        $service = new RetestService(
            $entityManager,
            $repos['retestRuns'],
            new class implements BrowserRetestClientInterface {
                public function retest(BrowserRetestRequest $request): RetestResultData
                {
                    if (str_contains($request->url, 'fail')) {
                        throw new \RuntimeException('Idle timeout reached for "http://playwright:3000/retest".');
                    }

                    return new RetestResultData(
                        result: RetestResult::STILL_VULNERABLE,
                        httpStatus: 200,
                        finalUrl: $request->url,
                        observedEvidence: 'ok',
                        dialogText: 'ok',
                    );
                }
            },
            new ValidationService($this->createValidator()),
            $this->storage,
            new \App\Service\RecheckPolicy(),
        );

        $command = new RetestAllCommand(
            $repos['domains'],
            $repos['findings'],
            $service,
            new ValidationService($this->createValidator()),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--execute' => true, '--limit' => 2]));

        // Symfony wraps styled console blocks according to the detected terminal
        // width. Normalize whitespace so this assertion tests the message rather
        // than the local DDEV terminal dimensions.
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('Skipping', $display);
        self::assertStringContainsString('Idle timeout reached for "http://playwright:3000/retest".', $display);
        self::assertStringContainsString(substr($successfulFinding->getId(), 0, 8).' -> still_vulnerable (chromium)', $display);
        self::assertStringContainsString('Retest batch finished. Processed 1 finding(s). 1 finding(s) failed and were skipped.', $display);
    }
}
