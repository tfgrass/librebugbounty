<?php

namespace App\Service;

use App\Dto\StoredEvidenceResult;
use App\Entity\Finding;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Uid\Uuid;

final class LocalEvidenceStorage implements EvidenceStorageInterface
{
    private const PREFIX = 'storage/artifacts/';
    private readonly string $root;

    public function __construct(string $evidenceDir, private readonly Filesystem $filesystem = new Filesystem())
    {
        if (trim($evidenceDir) === '') {
            throw new \InvalidArgumentException('Evidence storage directory must be configured.');
        }
        $project = dirname(__DIR__, 2);
        $this->root = rtrim(Path::makeAbsolute($evidenceDir, $project), '/');
        if ($this->root === '' || $this->root === $project || str_starts_with($project, $this->root.'/')) {
            throw new \InvalidArgumentException('Evidence storage must use a dedicated directory.');
        }
        $this->assertNoSymlinks($this->root);
    }

    public function storeFile(Finding $finding, string $sourcePath, ?string $targetFilename = null): StoredEvidenceResult
    {
        $contents = is_file($sourcePath) ? @file_get_contents($sourcePath) : false;
        if ($contents === false) {
            throw new \RuntimeException('Evidence source file cannot be read.');
        }
        $stored = $this->storeContents($finding, $contents, $targetFilename ?? basename($sourcePath));

        return new StoredEvidenceResult($stored->relativePath, $stored->sha256, basename($sourcePath));
    }

    public function storeContents(Finding $finding, string $contents, string $filename): StoredEvidenceResult
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($filename)) ?: 'evidence.bin';
        // Each write gets its own name; repeated uploads preserve historical evidence.
        $relative = $finding->getId().'/'.Uuid::v4()->toRfc4122().'-'.$safeName;
        $absolute = $this->resolve($relative);
        $this->filesystem->mkdir(dirname($absolute));
        $this->assertNoSymlinks($absolute);
        $this->filesystem->dumpFile($absolute, $contents);

        return new StoredEvidenceResult(self::PREFIX.$relative, hash('sha256', $contents), $filename);
    }

    public function exists(string $path): bool
    {
        try {
            $absolute = $this->resolve($path);
            clearstatcache(true, $absolute);
            return is_file($absolute) && is_readable($absolute);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function read(string $path): string
    {
        $absolute = $this->resolve($path);
        $contents = is_file($absolute) ? @file_get_contents($absolute) : false;
        if ($contents === false) {
            throw new \RuntimeException('Evidence file is missing or unreadable.');
        }

        return $contents;
    }

    public function deleteFile(string $path): void
    {
        $this->filesystem->remove($this->resolve($path));
    }

    public function deleteForFinding(Finding $finding): void
    {
        $this->filesystem->remove($this->resolve($finding->getId()));
    }

    public function clear(): void
    {
        $this->assertNoSymlinks($this->root);
        // Keep the configured root itself, including its permissions.
        if (!is_dir($this->root)) {
            $this->filesystem->mkdir($this->root);
            return;
        }
        foreach (new \FilesystemIterator($this->root) as $entry) {
            $this->filesystem->remove($entry->getPathname());
        }
    }

    public function listPaths(): array
    {
        $this->assertNoSymlinks($this->root);
        if (!is_dir($this->root)) {
            return [];
        }
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isLink() && $file->isFile()) {
                $paths[] = self::PREFIX.substr($file->getPathname(), strlen($this->root) + 1);
            }
        }
        sort($paths);
        return $paths;
    }

    private function resolve(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, self::PREFIX)) {
            $path = substr($path, strlen(self::PREFIX));
        }
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, ':') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Invalid evidence path.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid evidence path.');
            }
        }
        $absolute = $this->root.'/'.$path;
        $this->assertNoSymlinks($absolute);
        return $absolute;
    }

    private function assertNoSymlinks(string $path): void
    {
        for ($part = $path; $part !== '/' && $part !== '.'; $part = dirname($part)) {
            if (is_link($part)) {
                throw new \InvalidArgumentException('Symlinks are not supported in evidence storage.');
            }
        }
    }
}
