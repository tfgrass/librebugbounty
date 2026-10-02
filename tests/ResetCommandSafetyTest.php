<?php

namespace App\Tests;

use App\Command\ResetCommand;
use App\Command\ResetMvpCommand;
use App\Command\ResetVerificationCommand;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\DomainService;
use App\Service\FindingService;
use App\Service\ResetService;
use App\Service\ValidationService;
use Symfony\Component\Console\Tester\CommandTester;

final class ResetCommandSafetyTest extends UnitTestCase
{
    public function testEveryResetPreviewsItsScopeAndRequiresExplicitForce(): void
    {
        $repos = $this->createRepositories();
        $manager = $this->createEntityManagerMock();
        $this->wirePersistCallbacks($manager, $repos['domains'], $repos['evidence'], $repos['findings'], $repos['retestRuns']);
        $domain = new Domain();
        $domain->setHostname('localhost')->setScheme('http');
        $finding = new Finding();
        $finding->setDomain($domain)->setUrl('http://localhost/fixture')->setTitle('Fixture')->setPrivateNotes('keep');
        $manager->persist($finding);
        $file = $this->storage->storeContents($finding, 'keep', 'fixture.png');
        $validation = new ValidationService($this->createValidator());
        $findings = new FindingService(new DomainService($repos['domains'], $manager, $validation), $repos['findings'], $manager, $validation, $this->storage);
        $service = new ResetService($repos['findings'], $repos['evidence'], $repos['retestRuns'], $findings, $manager, $this->storage);
        foreach ([ResetCommand::class, ResetMvpCommand::class, ResetVerificationCommand::class] as $class) {
            $tester = new CommandTester(new $class($service));
            self::assertSame(1, $tester->execute([]));
            self::assertStringContainsString('Files in configured artifact storage', $tester->getDisplay());
            self::assertStringContainsString('--force', $tester->getDisplay());
            self::assertSame(0, $tester->execute(['--dry-run' => true, '--force' => true]));
            self::assertStringContainsString('Findings reset (not deleted)', $tester->getDisplay());
            self::assertSame('keep', $finding->getPrivateNotes());
            self::assertSame('keep', $this->storage->read($file->relativePath));
        }
    }
}
