<?php

namespace App\Tests;

use App\Command\EvidenceRefreshCommand;
use App\Dto\RetestResultData;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Repository\DomainRepository;
use App\Repository\FindingRepository;
use App\Repository\RetestRunRepository;
use App\Service\BrowserRetestClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\RetestService;
use App\Service\ValidationService;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshPreservationTest extends DatabaseTestCase
{
    public function testRefreshPreservesOldRecordsFilesAndMetadataEvenOnFailure(): void
    {
        $domain = new Domain();
        $domain->setHostname('localhost')->setScheme('http');
        $finding = new Finding();
        $date = new \DateTimeImmutable('2026-01-02');
        $finding->setDomain($domain)->setTitle('Local fixture')->setType('other')->setUrl('http://localhost/fixture')
            ->setPrivateNotes('keep this note')->setContactedAt($date)->setReportedAt($date)->setNotifiedOwnerAt($date);
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $file = $storage->storeContents($finding, 'historical image', 'old.png');
        $evidence = new Evidence();
        $evidence->setFinding($finding)->setKind('screenshot')->setFilePath($file->relativePath);
        $oldRun = new RetestRun();
        $oldRun->setFinding($finding)->setMode('browser')->setResult('error');
        foreach ([$domain, $finding, $evidence, $oldRun] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        foreach ([false, true] as $fail) {
            $browser = $this->createMock(BrowserRetestClientInterface::class);
            if ($fail) {
                $browser->method('retest')->willThrowException(new \RuntimeException('simulated transport failure'));
            } else {
                $browser->method('retest')->willReturn(new RetestResultData(result: 'error', errorMessage: 'local fixture'));
            }
            $validation = self::getContainer()->get(ValidationService::class);
            $service = new RetestService($this->entityManager, self::getContainer()->get(RetestRunRepository::class), $browser, $validation, $storage);
            $command = new EvidenceRefreshCommand(self::getContainer()->get(DomainRepository::class), self::getContainer()->get(FindingRepository::class), $service, $validation);
            try {
                (new CommandTester($command))->execute(['--finding-id' => $finding->getId(), '--browser' => 'chromium']);
                self::assertFalse($fail);
            } catch (\RuntimeException $error) {
                self::assertTrue($fail);
                self::assertSame('simulated transport failure', $error->getMessage());
            }
            $this->entityManager->refresh($finding);
            self::assertSame('keep this note', $finding->getPrivateNotes());
            self::assertEquals($date, $finding->getContactedAt());
            self::assertEquals($date, $finding->getReportedAt());
            self::assertEquals($date, $finding->getNotifiedOwnerAt());
            self::assertNotNull($this->entityManager->find(Evidence::class, $evidence->getId()));
            self::assertNotNull($this->entityManager->find(RetestRun::class, $oldRun->getId()));
            self::assertSame('historical image', $storage->read($file->relativePath));
        }
    }
}
