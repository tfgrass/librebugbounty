<?php

namespace App\Tests;

use App\Service\DomainService;
use App\Service\ValidationService;

final class DomainServiceTest extends UnitTestCase
{
    public function testDomainNormalizationAndDeduplication(): void
    {
        $repos = $this->createRepositories();
        $entityManager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($entityManager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);

        $service = new DomainService(
            $repos['domains'],
            $entityManager,
            new ValidationService($this->createValidator()),
        );

        $first = $service->upsertDomain('HTTPS://Example.COM./search?q=1', 'https', true, 'manual', 'verified', 'security@example.com', 'first');
        $second = $service->upsertDomain('example.com', 'https', true, null, null, null, null);

        self::assertSame('example.com', $first->domain->getHostname());
        self::assertSame($first->domain->getId(), $second->domain->getId());
        self::assertCount(1, $repos['domains']->findAllOrdered());
        self::assertTrue($second->domain->isAuthorized());
    }
}
