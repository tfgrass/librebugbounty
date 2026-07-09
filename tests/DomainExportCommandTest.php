<?php

namespace App\Tests;

use App\Command\DomainExportCommand;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Tests\Support\InMemoryDomainRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DomainExportCommandTest extends UnitTestCase
{
    public function testExportOnlyIncludesDomainsWithoutContactedFindings(): void
    {
        $domains = new InMemoryDomainRepository();

        $nonContacted = new Domain();
        $nonContacted->setHostname('alpha.example.com');
        $nonContacted->setScheme('https');
        $nonContacted->setAuthorized(true);
        $domains->add($nonContacted);

        $contacted = new Domain();
        $contacted->setHostname('beta.example.com');
        $contacted->setScheme('https');
        $contacted->setAuthorized(true);
        $domains->add($contacted);

        $findingA = new Finding();
        $findingA->setDomain($nonContacted);
        $findingA->setTitle('Open');
        $findingA->setType(self::DEFAULT_FINDING_TYPE);
        $findingA->setSeverity('medium');
        $findingA->setStatus('verified');
        $findingA->setUrl('https://alpha.example.com/?q=test');
        $findingA->setMethod('GET');
        $nonContacted->getFindings()->add($findingA);

        $findingB = new Finding();
        $findingB->setDomain($contacted);
        $findingB->setTitle('Contacted');
        $findingB->setType(self::DEFAULT_FINDING_TYPE);
        $findingB->setSeverity('medium');
        $findingB->setStatus('verified');
        $findingB->setUrl('https://beta.example.com/?q=test');
        $findingB->setMethod('GET');
        $findingB->setContactedAt(new \DateTimeImmutable('-1 hour'));
        $contacted->getFindings()->add($findingB);

        $command = new DomainExportCommand($domains);
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame("alpha.example.com\n", $tester->getDisplay());
    }

    public function testExportCanReturnJson(): void
    {
        $domains = new InMemoryDomainRepository();

        $domain = new Domain();
        $domain->setHostname('gamma.example.com');
        $domain->setScheme('https');
        $domain->setAuthorized(false);
        $domains->add($domain);

        $finding = new Finding();
        $finding->setDomain($domain);
        $finding->setTitle('Open');
        $finding->setType(self::DEFAULT_FINDING_TYPE);
        $finding->setSeverity('medium');
        $finding->setStatus('verified');
        $finding->setUrl('https://gamma.example.com/?q=test');
        $finding->setMethod('GET');
        $domain->getFindings()->add($finding);

        $command = new DomainExportCommand($domains);
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        self::assertStringContainsString('"hostname": "gamma.example.com"', $tester->getDisplay());
    }
}
