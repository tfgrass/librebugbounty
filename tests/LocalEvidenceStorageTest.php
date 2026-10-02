<?php

namespace App\Tests;

use App\Entity\Finding;
use App\Service\LocalEvidenceStorage;

final class LocalEvidenceStorageTest extends UnitTestCase
{
    public function testRelocationLegacyPathsAndRepeatedNamesKeepBothFiles(): void
    {
        $finding = new Finding();
        mkdir($this->artifactRoot.'/'.$finding->getId(), 0775, true);
        file_put_contents($this->artifactRoot.'/'.$finding->getId().'/old.png', 'old');
        self::assertSame('old', $this->storage->read('storage/artifacts/'.$finding->getId().'/old.png'));
        $first = $this->storage->storeContents($finding, 'first', 'same.png');
        $second = $this->storage->storeContents($finding, 'second', 'same.png');
        self::assertNotSame($first->relativePath, $second->relativePath);
        self::assertSame('first', $this->storage->read($first->relativePath));
        self::assertSame('second', $this->storage->read($second->relativePath));
        self::assertSame(hash('sha256', 'first'), $first->sha256);
        self::assertCount(3, $this->storage->listPaths());
    }

    public function testDeleteAndClearCannotAffectAdjacentFilesOrDatabase(): void
    {
        $outside = APP_TEST_ROOT.'/control-'.bin2hex(random_bytes(5));
        mkdir($outside);
        file_put_contents($outside.'/control.txt', 'keep');
        $db = new \PDO('sqlite:'.$outside.'/control.sqlite');
        $db->exec('CREATE TABLE sentinel (value TEXT)');
        $db->exec("INSERT INTO sentinel VALUES ('keep')");
        $before = hash_file('sha256', $outside.'/control.sqlite');
        $first = new Finding();
        $second = new Finding();
        $this->storage->storeContents($first, 'one', 'a.png');
        $remaining = $this->storage->storeContents($second, 'two', 'b.png');
        $this->storage->deleteForFinding($first);
        self::assertTrue($this->storage->exists($remaining->relativePath));
        // Clearing may unlink an accidental link, but must never traverse its target.
        symlink($outside, $this->artifactRoot.'/outside');
        $this->storage->clear();
        self::assertSame([], $this->storage->listPaths());
        self::assertSame('keep', file_get_contents($outside.'/control.txt'));
        self::assertSame($before, hash_file('sha256', $outside.'/control.sqlite'));
        self::assertSame('keep', $db->query('SELECT value FROM sentinel')->fetchColumn());
    }

    public function testInvalidPathsAndSymlinksAreNotReadable(): void
    {
        $finding = new Finding();
        $this->storage->storeContents($finding, 'inside', 'a.png');
        $outside = APP_TEST_ROOT.'/outside.txt';
        file_put_contents($outside, 'outside');
        symlink($outside, $this->artifactRoot.'/linked.txt');
        foreach (['../outside.txt', '/etc/hosts', 'storage/artifacts/../outside.txt', 'linked.txt', "bad\0path"] as $path) {
            self::assertFalse($this->storage->exists($path));
            try {
                $this->storage->read($path);
                self::fail('Unsafe path accepted.');
            } catch (\InvalidArgumentException) {
                // Expected rejection of paths outside the configured storage.
            }
        }
        self::assertSame('outside', file_get_contents($outside));
    }

    public function testMisconfiguredStorageFailsWithoutCreatingAnArtifact(): void
    {
        mkdir($this->artifactRoot);
        file_put_contents($this->artifactRoot.'/blocked', 'not a directory');
        $storage = new LocalEvidenceStorage($this->artifactRoot.'/blocked');
        $this->expectException(\Symfony\Component\Filesystem\Exception\IOExceptionInterface::class);
        $storage->storeContents(new Finding(), 'content', 'shot.png');
    }
}
