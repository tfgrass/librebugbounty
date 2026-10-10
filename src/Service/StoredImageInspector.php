<?php

namespace App\Service;

/** Read-only inspection, used on explicit evidence/diagnostic views, never by workers. */
final class StoredImageInspector
{
    public const MAX_IMAGE_BYTES = 25 * 1024 * 1024;
    public function __construct(private readonly EvidenceStorageInterface $storage)
    {
    }

    /** @return 'missing'|'unreadable'|'invalid'|'too_large'|null */
    public function problem(?string $path): ?string
    {
        if ($path === null || $path === '' || !$this->storage->exists($path)) {
            return 'missing';
        }
        $size = $this->storage->size($path);
        if ($size === null) return 'unreadable';
        if ($size > self::MAX_IMAGE_BYTES) return 'too_large';
        try {
            $header = @getimagesizefromstring($this->storage->read($path));
        } catch (\RuntimeException|\InvalidArgumentException) {
            return 'unreadable';
        }

        // Header/format validation, not a full pixel decode or checksum audit.
        return is_array($header) && in_array($header['mime'] ?? '', ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)
            ? null : 'invalid';
    }
}
