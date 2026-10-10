<?php

namespace App\Tests;

use App\Service\EvidenceStorageInterface;
use App\Service\StoredImageInspector;
use PHPUnit\Framework\TestCase;

final class StoredImageInspectorTest extends TestCase
{
    public function testMissingFilesAreNotRead(): void
    {
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('exists')->willReturn(false);
        $storage->expects(self::never())->method('read');
        $inspector = new StoredImageInspector($storage);
        foreach ([null, '', '../outside.png', 'https://outside.invalid/image.png', 'absent.png'] as $path) self::assertSame('missing', $inspector->problem($path));
    }

    public function testOversizedFilesAreNotReadIntoMemory(): void
    {
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('exists')->willReturn(true);
        $storage->method('size')->willReturn(StoredImageInspector::MAX_IMAGE_BYTES + 1);
        $storage->expects(self::never())->method('read');
        self::assertSame('too_large', (new StoredImageInspector($storage))->problem('huge.png'));
    }

    public function testReadFailuresAfterExistenceCheckBecomeUnavailable(): void
    {
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('exists')->willReturn(true);
        $storage->method('size')->willReturn(100);
        $storage->method('read')->willThrowException(new \RuntimeException('File disappeared.'));
        self::assertSame('unreadable', (new StoredImageInspector($storage))->problem('disappeared.png'));
    }

    public function testInvalidAndSupportedHeadersAreDistinguished(): void
    {
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('exists')->willReturn(true);
        $storage->method('size')->willReturn(100);
        $storage->method('read')->willReturnOnConsecutiveCalls('', '<html>not an image</html>', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII='));
        $inspector = new StoredImageInspector($storage);
        self::assertSame('invalid', $inspector->problem('empty.png'));
        self::assertSame('invalid', $inspector->problem('html.png'));
        self::assertNull($inspector->problem('supported.png'));
    }
}
