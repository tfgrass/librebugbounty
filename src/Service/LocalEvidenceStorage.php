<?php

namespace App\Service;

use App\Dto\StoredEvidenceResult;
use App\Entity\Finding;
use Symfony\Component\Filesystem\Filesystem;

final class LocalEvidenceStorage implements EvidenceStorageInterface
{
    public function __construct(
        private readonly string $evidenceDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function storeFile(Finding $finding, string $sourcePath, ?string $targetFilename = null): StoredEvidenceResult
    {
        if (!is_file($sourcePath)) {
            throw new \RuntimeException(sprintf('Evidence file "%s" does not exist.', $sourcePath));
        }

        $relativeDirectory = sprintf('storage/artifacts/%s', $finding->getId());
        $absoluteDirectory = $this->projectPath($relativeDirectory);
        $this->filesystem->mkdir($absoluteDirectory);

        $targetFilename ??= basename($sourcePath);
        $targetFilename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $targetFilename) ?: 'evidence.bin';
        $absoluteTarget = $absoluteDirectory.'/'.$targetFilename;

        $this->filesystem->copy($sourcePath, $absoluteTarget, true);

        return new StoredEvidenceResult(
            relativePath: $relativeDirectory.'/'.$targetFilename,
            sha256: hash_file('sha256', $absoluteTarget) ?: '',
            originalFilename: basename($sourcePath),
        );
    }

    private function projectPath(string $relativePath): string
    {
        if (str_starts_with($this->evidenceDir, '/')) {
            $base = preg_replace('#/storage/artifacts$#', '', $this->evidenceDir) ?: dirname($this->evidenceDir, 2);
            return rtrim($base, '/').'/'.ltrim($relativePath, '/');
        }

        return rtrim(getcwd() ?: '.', '/').'/'.ltrim($relativePath, '/');
    }
}
