<?php

namespace App\Tests;

use App\Service\DomainService;
use App\Service\FindingService;
use App\Service\ImportService;
use App\Service\ValidationService;

final class ImportServiceTest extends UnitTestCase
{
    public function testImportTxtCreatesDomainsAndFindings(): void
    {
        $tmp = tempnam(APP_TEST_ROOT, 'import-');
        file_put_contents($tmp, <<<TXT
# comment
example.com | reflected_xss | medium | https://example.com/search?q=token | token | token
TXT);

        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $service = new ImportService(
            new DomainService(
                $repos['domains'],
                $entityManager,
                new ValidationService($this->createValidator()),
            ),
            new FindingService(
                new DomainService(
                    $repos['domains'],
                    $entityManager,
                    new ValidationService($this->createValidator()),
                ),
                $repos['findings'],
                $entityManager,
                new ValidationService($this->createValidator()),
                $this->storage,
            ),
        );

        $result = $service->importTxt($tmp);

        self::assertSame(1, $result->domainsCreated);
        self::assertSame(1, $result->findingsCreated);
        self::assertSame(0, $result->skipped);
        self::assertCount(1, $repos['domains']->findAllOrdered());
        self::assertCount(1, $repos['findings']->findByDomainAndStatus());
    }
}
