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
    public function testExportOnlyIncludesDomainsWithoutContactedOrFixedFindings(): void
    {
        $domains = new InMemoryDomainRepository();

        $eligible = new Domain();
        $eligible->setHostname('alpha.example.com');
        $eligible->setScheme('https');
        $eligible->setAuthorized(true);
        $domains->add($eligible);

        $contacted = new Domain();
        $contacted->setHostname('beta.example.com');
        $contacted->setScheme('https');
        $contacted->setAuthorized(true);
        $domains->add($contacted);

        $fixed = new Domain();
        $fixed->setHostname('gamma.example.com');
        $fixed->setScheme('https');
        $fixed->setAuthorized(true);
        $domains->add($fixed);

        $findingA = new Finding();
        $findingA->setDomain($eligible);
        $findingA->setTitle('Open');
        $findingA->setType(self::DEFAULT_FINDING_TYPE);
        $findingA->setSeverity('medium');
        $findingA->setStatus('verified');
        $findingA->setUrl('https://alpha.example.com/?q=test');
        $findingA->setMethod('GET');
        $eligible->getFindings()->add($findingA);

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

        $findingC = new Finding();
        $findingC->setDomain($fixed);
        $findingC->setTitle('Fixed');
        $findingC->setType(self::DEFAULT_FINDING_TYPE);
        $findingC->setSeverity('medium');
        $findingC->setStatus('fixed');
        $findingC->setUrl('https://gamma.example.com/?q=test');
        $findingC->setMethod('GET');
        $fixed->getFindings()->add($findingC);

        $command = new DomainExportCommand($domains);
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame("alpha.example.com\n", $tester->getDisplay());
    }

    public function testExportOnlyIncludesContactedDomainsWhenRequested(): void
    {
        $domains = new InMemoryDomainRepository();

        $nonContacted = new Domain();
        $nonContacted->setHostname('alpha.example.com');
        $nonContacted->setScheme('https');
        $nonContacted->setAuthorized(true);
        $domains->add($nonContacted);

        $contacted = new Domain();
        $contacted->setHostname('beta.example.com');
        $contacted->setScheme('http');
        $contacted->setAuthorized(false);
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

        self::assertSame(Command::SUCCESS, $tester->execute(['--contacted-only' => true]));
        self::assertSame("beta.example.com\n", $tester->getDisplay());
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

    public function testOverviewGroupsDomainsByFixedContactedAndUncontacted(): void
    {
        $domains = new InMemoryDomainRepository();

        $uncontactedEmpty = new Domain();
        $uncontactedEmpty->setHostname('alpha.example.com');
        $uncontactedEmpty->setScheme('https');
        $uncontactedEmpty->setAuthorized(true);
        $domains->add($uncontactedEmpty);

        $contacted = new Domain();
        $contacted->setHostname('beta.example.com');
        $contacted->setScheme('https');
        $contacted->setAuthorized(true);
        $domains->add($contacted);

        $fixed = new Domain();
        $fixed->setHostname('gamma.example.com');
        $fixed->setScheme('https');
        $fixed->setAuthorized(true);
        $domains->add($fixed);

        $uncontactedFindingDomain = new Domain();
        $uncontactedFindingDomain->setHostname('delta.example.com');
        $uncontactedFindingDomain->setScheme('https');
        $uncontactedFindingDomain->setAuthorized(true);
        $domains->add($uncontactedFindingDomain);

        $contactedFinding = new Finding();
        $contactedFinding->setDomain($contacted);
        $contactedFinding->setTitle('Contacted');
        $contactedFinding->setType(self::DEFAULT_FINDING_TYPE);
        $contactedFinding->setSeverity('medium');
        $contactedFinding->setStatus('verified');
        $contactedFinding->setUrl('https://beta.example.com/?q=test');
        $contactedFinding->setMethod('GET');
        $contactedFinding->setContactedAt(new \DateTimeImmutable('-1 hour'));
        $contacted->getFindings()->add($contactedFinding);

        $fixedFinding = new Finding();
        $fixedFinding->setDomain($fixed);
        $fixedFinding->setTitle('Fixed');
        $fixedFinding->setType(self::DEFAULT_FINDING_TYPE);
        $fixedFinding->setSeverity('medium');
        $fixedFinding->setStatus('fixed');
        $fixedFinding->setUrl('https://gamma.example.com/?q=test');
        $fixedFinding->setMethod('GET');
        $fixed->getFindings()->add($fixedFinding);

        $openFinding = new Finding();
        $openFinding->setDomain($uncontactedFindingDomain);
        $openFinding->setTitle('Open');
        $openFinding->setType(self::DEFAULT_FINDING_TYPE);
        $openFinding->setSeverity('medium');
        $openFinding->setStatus('verified');
        $openFinding->setUrl('https://delta.example.com/?q=test');
        $openFinding->setMethod('GET');
        $uncontactedFindingDomain->getFindings()->add($openFinding);

        $command = new DomainExportCommand($domains);
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--overview' => true]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('== marked fixed (1) ==', $display);
        self::assertStringContainsString("gamma.example.com\n", $display);
        self::assertStringContainsString('== marked contacted (1) ==', $display);
        self::assertStringContainsString("beta.example.com\n", $display);
        self::assertStringContainsString('== uncontacted (2) ==', $display);
        self::assertStringContainsString("alpha.example.com\n", $display);
        self::assertStringContainsString("delta.example.com\n", $display);
    }
}
