<?php

namespace App\Service;

use App\AppInfo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A bounded release lookup; rendering only ever reads the local snapshot. */
final class ReleaseUpdateService
{
    private const ENDPOINT = 'https://api.github.com/repos/tfgrass/librebugbounty/releases/latest';
    private const CHECK_INTERVAL = 86400;
    private const ERROR_INTERVAL = 3600;
    private const FORCE_COOLDOWN = 60;
    private const MAX_RESPONSE_BYTES = 131072;
    private const MAX_CACHE_BYTES = 4096;

    public function __construct(
        private readonly HttpClientInterface $client,
        #[Autowire('%kernel.cache_dir%/release-update')]
        private readonly string $cacheDirectory,
    ) {
    }

    /**
     * No HTTP, directory creation, repair writes, or locks on the read path.
     *
     * @return array{state: string, latestVersion: ?string, latestTag: ?string, releaseUrl: ?string, checkedAt: ?\DateTimeImmutable, attemptedAt: ?\DateTimeImmutable, available: bool, stale: bool}
     */
    public function snapshot(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        return $this->present($this->readCache($now), $now);
    }

    /** @return array<string, mixed> The snapshot, plus whether HTTP was attempted. */
    public function check(bool $force = false, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $lock = null;
        $performed = false;
        try {
            if (!is_dir($this->cacheDirectory)
                && !@mkdir($this->cacheDirectory, 0700, true)
                && !is_dir($this->cacheDirectory)) {
                throw new \RuntimeException('Release cache unavailable.');
            }
            $lock = @fopen($this->cacheDirectory.'/check.lock', 'c');
            if ($lock === false) {
                throw new \RuntimeException('Release lock unavailable.');
            }
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return $this->snapshot($now) + ['performed' => false];
            }

            // Re-read after claiming the lock: another process may have just checked.
            $cache = $this->readCache($now);
            $interval = $force ? self::FORCE_COOLDOWN : ($cache['failed'] ? self::ERROR_INTERVAL : self::CHECK_INTERVAL);
            if ($cache['attemptedAt'] !== null && $now->getTimestamp() - $cache['attemptedAt'] < $interval) {
                return $this->present($cache, $now) + ['performed' => false];
            }

            // Reserve this attempt durably before HTTP. Even a killed worker cannot
            // turn a restart loop into unbounded requests to GitHub.
            $cache['attemptedAt'] = $now->getTimestamp();
            $cache['failed'] = true;
            $this->writeCache($cache);
            $performed = true;
            try {
                $tag = $this->fetchTag();
                $cache['latestTag'] = $tag;
                $cache['checkedAt'] = $now->getTimestamp();
                $cache['failed'] = false;
            } catch (\Throwable) {
                // Keep the last verified candidate. Never persist remote bodies,
                // exception text, credentials, or arbitrary release URLs.
            }
            $this->writeCache($cache);

            return $this->present($cache, $now) + ['performed' => $performed];
        } catch (\Throwable) {
            $cache = $this->readCache($now);
            $cache['failed'] = true;

            return $this->present($cache, $now) + ['performed' => $performed];
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function fetchTag(): string
    {
        $response = null;
        try {
            $response = $this->client->request('GET', self::ENDPOINT, [
                'timeout' => 5.0,
                'max_duration' => 5.0,
                'max_redirects' => 0,
                'buffer' => false,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'LibreBugBounty-ReleaseCheck',
                ],
                'on_progress' => static function (int $downloaded, int $size): void {
                    if (max($downloaded, $size) > self::MAX_RESPONSE_BYTES) {
                        throw new \RuntimeException('Release response too large.');
                    }
                },
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Release lookup failed.');
            }
            $headers = $response->getHeaders(false);
            $contentType = strtolower(trim(explode(';', $headers['content-type'][0] ?? '')[0]));
            if (!in_array($contentType, ['application/json', 'application/vnd.github+json'], true)
                || (isset($headers['content-length'][0]) && (float) $headers['content-length'][0] > self::MAX_RESPONSE_BYTES)) {
                throw new \RuntimeException('Invalid release response.');
            }
            $body = '';
            foreach ($this->client->stream($response, 5.0) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new \RuntimeException('Release lookup timed out.');
                }
                $body .= $chunk->getContent();
                if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                    throw new \RuntimeException('Release response too large.');
                }
            }
            $release = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($release)
                || ($release['draft'] ?? null) !== false
                || ($release['prerelease'] ?? null) !== false
                || !is_string($release['tag_name'] ?? null)
                || $this->versionFromTag($release['tag_name']) === null) {
                throw new \RuntimeException('Invalid stable release.');
            }

            return $release['tag_name'];
        } finally {
            $response?->cancel();
        }
    }

    /** @return array{latestTag: ?string, checkedAt: ?int, attemptedAt: ?int, failed: bool} */
    private function readCache(\DateTimeImmutable $now): array
    {
        $empty = ['latestTag' => null, 'checkedAt' => null, 'attemptedAt' => null, 'failed' => false];
        try {
            $path = $this->cacheDirectory.'/release.json';
            if (!file_exists($path)) {
                return $empty;
            }
            $body = @file_get_contents($path, false, null, 0, self::MAX_CACHE_BYTES + 1);
            if ($body === false || strlen($body) > self::MAX_CACHE_BYTES) {
                throw new \RuntimeException('Invalid release cache.');
            }
            $cache = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($cache) || array_diff_key($empty, $cache) !== [] || ($cache['schema'] ?? null) !== 1
                || !is_bool($cache['failed'] ?? null)
                || !$this->validTimestamp($cache['checkedAt'] ?? null)
                || !$this->validTimestamp($cache['attemptedAt'] ?? null)
                || (!is_null($cache['latestTag'] ?? null) && (!is_string($cache['latestTag']) || $this->versionFromTag($cache['latestTag']) === null))
                || (($cache['latestTag'] ?? null) === null) !== (($cache['checkedAt'] ?? null) === null)
                || ($cache['checkedAt'] !== null && ($cache['attemptedAt'] === null || $cache['checkedAt'] > $cache['attemptedAt']))
                || ($cache['attemptedAt'] !== null && $cache['attemptedAt'] > $now->getTimestamp())) {
                throw new \RuntimeException('Invalid release cache.');
            }

            return array_replace($empty, array_intersect_key($cache, $empty));
        } catch (\Throwable) {
            return array_replace($empty, ['failed' => true]);
        }
    }

    private function validTimestamp(mixed $value): bool
    {
        return $value === null || (is_int($value) && $value >= 0);
    }

    private function writeCache(array $cache): void
    {
        $path = @tempnam($this->cacheDirectory, '.release-');
        if ($path === false) {
            throw new \RuntimeException('Release cache unavailable.');
        }
        try {
            $body = json_encode(['schema' => 1] + $cache, JSON_THROW_ON_ERROR);
            if (@file_put_contents($path, $body) !== strlen($body) || !@rename($path, $this->cacheDirectory.'/release.json')) {
                throw new \RuntimeException('Release cache write failed.');
            }
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function present(array $cache, \DateTimeImmutable $now): array
    {
        $latestVersion = $cache['latestTag'] === null ? null : $this->versionFromTag($cache['latestTag']);
        // Current application version is always read from this running build,
        // never from the cache written by an older build.
        $comparison = $latestVersion === null ? null : $this->compareVersions($latestVersion, AppInfo::VERSION);
        $stale = $cache['checkedAt'] !== null
            && ($cache['failed'] || $now->getTimestamp() - $cache['checkedAt'] >= self::CHECK_INTERVAL);
        $state = match (true) {
            $cache['failed'] => 'error',
            $latestVersion === null => 'unknown',
            $stale => 'stale',
            $comparison > 0 => 'available',
            $comparison < 0 => 'ahead',
            default => 'current',
        };

        return [
            'state' => $state,
            'latestVersion' => $latestVersion,
            'latestTag' => $cache['latestTag'],
            'releaseUrl' => $cache['latestTag'] === null ? null : AppInfo::REPOSITORY.'/releases/tag/'.$cache['latestTag'],
            'checkedAt' => $cache['checkedAt'] === null ? null : new \DateTimeImmutable('@'.$cache['checkedAt']),
            'attemptedAt' => $cache['attemptedAt'] === null ? null : new \DateTimeImmutable('@'.$cache['attemptedAt']),
            'available' => $comparison !== null && $comparison > 0,
            'stale' => $stale,
        ];
    }

    private function versionFromTag(string $tag): ?string
    {
        return strlen($tag) <= 96 && preg_match('/^v?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D', $tag, $match) === 1
            ? $match[1] : null;
    }

    /** Numeric comparison without integer-overflow or lexical 2.1.9/2.1.10 traps. */
    private function compareVersions(string $left, string $right): int
    {
        foreach (array_map(null, explode('.', $left), explode('.', $right)) as [$a, $b]) {
            $comparison = strlen($a) <=> strlen($b);
            if ($comparison === 0) {
                $comparison = strcmp($a, $b);
            }
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }
}
