<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        // Historical web assertions deliberately exercise German copy. Locale
        // acceptance tests keep the browser default or choose their own translator.
        if ($this->usesGermanCopy()) {
            self::getContainer()->set(\App\Service\UiTranslator::class, new \App\Service\UiTranslator('de'));
        }

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $path = $this->entityManager->getConnection()->getParams()['path'] ?? '';
        if ($path !== APP_TEST_ROOT.'/database.sqlite') {
            throw new \RuntimeException('Refusing to reset a database outside the isolated test directory.');
        }
        if (($_SERVER['EVIDENCE_STORAGE_DIR'] ?? '') !== APP_TEST_ROOT.'/artifacts') {
            throw new \RuntimeException('Refusing to clear artifacts outside the isolated test directory.');
        }
        self::getContainer()->get(\App\Service\EvidenceStorageInterface::class)->clear();
        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    protected function usesGermanCopy(): bool
    {
        return true;
    }

    private function resetSchema(): void
    {
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);

        try {
            if ($metadata !== []) {
                $schemaTool->dropSchema($metadata);
            }
        } catch (\Throwable) {
            // Ignore clean-up errors when the database is still empty.
        }

        if ($metadata !== []) {
            $schemaTool->createSchema($metadata);
        }
    }
}
