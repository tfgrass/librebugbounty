<?php

namespace App\Tests;

use App\Entity\Finding;
use App\Repository\EvidenceRepository;
use App\Service\EvidenceService;
use App\Service\LocalEvidenceStorage;
use App\Service\ValidationService;
use Doctrine\ORM\EntityManagerInterface;

final class EvidenceFailureTest extends UnitTestCase
{
    public function testMetadataFailureRemovesOnlyTheNewFile(): void
    {
        $finding = new Finding();
        $old = $this->storage->storeContents($finding, 'old evidence', 'same.png');
        $source = APP_TEST_ROOT.'/new.png';
        file_put_contents($source, 'new evidence');
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('flush')->willThrowException(new \RuntimeException('simulated database failure'));
        $service = new EvidenceService($this->createMock(EvidenceRepository::class), $manager, new ValidationService($this->createValidator()), $this->storage);
        try {
            $service->addEvidence($finding, 'screenshot', filePath: $source);
            self::fail('Storage failure should propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('simulated database failure', $error->getMessage());
        }
        self::assertSame([$old->relativePath], $this->storage->listPaths());
        self::assertSame('old evidence', $this->storage->read($old->relativePath));
    }

    public function testFileFailureDoesNotPersistSuccessfulEvidence(): void
    {
        mkdir($this->artifactRoot);
        file_put_contents($this->artifactRoot.'/blocked', 'not a directory');
        $source = APP_TEST_ROOT.'/source.png';
        file_put_contents($source, 'fixture');
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('persist');
        $manager->expects(self::never())->method('flush');
        $service = new EvidenceService($this->createMock(EvidenceRepository::class), $manager, new ValidationService($this->createValidator()), new LocalEvidenceStorage($this->artifactRoot.'/blocked'));
        $this->expectException(\Symfony\Component\Filesystem\Exception\IOExceptionInterface::class);
        $service->addEvidence(new Finding(), 'screenshot', filePath: $source);
    }
}
