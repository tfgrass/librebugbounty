<?php

namespace App\Service;

use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingPocRevision;
use App\Entity\ScreenshotJob;
use App\Exception\InteractiveCaptureInvalidatedException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;

final class FindingPocService
{
    public function __construct(private readonly Connection $connection) {}

    /** Preserve the exact spelling: payload encodings and query order are significant. */
    public function validate(Finding $finding, string $url): void
    {
        if ($finding->getMethod() !== 'GET') {
            throw new \InvalidArgumentException('Only GET findings support selecting a browser PoC URL.');
        }
        if ($url === '' || strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('The PoC must be an HTTP(S) URL without whitespace or control characters.');
        }
        $parts = parse_url($url);
        $original = parse_url($finding->getUrl());
        // parse_url accepts malformed numeric suffixes such as :80foo even
        // though Chromium rejects them. Validate the untouched authority too.
        $hasAuthority = preg_match('~\Ahttps?://([^/?#]+)~i', $url, $authority) === 1;
        $validPort = $hasAuthority && preg_match('~\A(?:\[[^\]]+\]|[^:]+)(?::[0-9]*)?\z~D', $authority[1]) === 1;
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !$validPort
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || !is_array($original) || !is_string($original['host'] ?? null)
            || strcasecmp($parts['host'], $original['host']) !== 0) {
            throw new \InvalidArgumentException('The PoC must be an HTTP(S) URL on the original host without credentials.');
        }
    }

    /**
     * Join the evidence transaction; the caller owns flush/commit/rollback.
     * Use its isolated managed Finding so unrelated caller edits stay untouched.
     */
    public function apply(Finding $finding, string $url, int $expectedRevision, string $source, Evidence $evidence, ScreenshotJob $job, EntityManagerInterface $writer): ?FindingPocRevision
    {
        if (!$this->connection->isTransactionActive() || $writer->getConnection() !== $this->connection) {
            throw new \LogicException('PoC selection must share the evidence persistence transaction.');
        }
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            // A deferred transaction must wait for other writers before any
            // model/proxy read, rather than fail while upgrading a read lock.
            $this->connection->executeStatement('UPDATE finding SET id = id WHERE 1 = 0');
        }
        $finding = $writer->find(Finding::class, $finding->getId());
        if ($finding === null) {
            throw new InteractiveCaptureInvalidatedException('The finding no longer exists.', 409);
        }
        $this->validate($finding, $url);
        if ($expectedRevision < 0 || !in_array($source, ['observed', 'manual'], true)) {
            throw new \InvalidArgumentException('Invalid PoC revision or selection source.');
        }
        if ($evidence->getFinding()->getId() !== $finding->getId() || $job->getFinding()->getId() !== $finding->getId()
            || $evidence->getFilePath() === null || $evidence->getFilePath() !== $job->getScreenshotPath()) {
            throw new \InvalidArgumentException('The PoC revision must reference this finding and its saved screenshot.');
        }

        // The conditional write acquires the SQLite writer before reading and
        // rejects stale browser sessions even when the submitted URL is equal.
        $changed = $this->connection->executeStatement(
            'UPDATE finding SET poc_url = ?, poc_revision = poc_revision + 1 WHERE id = ? AND poc_revision = ? AND COALESCE(poc_url, url) <> ?',
            [$url, $finding->getId(), $expectedRevision, $url],
        );
        if ($changed !== 1) {
            $current = $this->connection->fetchAssociative('SELECT COALESCE(poc_url, url) AS active_url, poc_revision FROM finding WHERE id = ?', [$finding->getId()]);
            if ($current === false || (int) $current['poc_revision'] !== $expectedRevision) {
                throw new InteractiveCaptureInvalidatedException('The active PoC changed. Reload the finding before saving a selection.', 409);
            }
            if ($current['active_url'] === $url) {
                return null;
            }
            throw new InteractiveCaptureInvalidatedException('The active PoC could not be updated.', 409);
        }

        $revision = new FindingPocRevision($writer->getReference(Finding::class, $finding->getId()), $expectedRevision + 1, $finding->getPocUrl(), $url, $source, $evidence, $job);
        $finding->setPocUrl($url, $expectedRevision + 1);
        $writer->persist($revision);
        return $revision;
    }

    /** @return list<array{revision:int, previousUrl:string, url:string, source:string, evidenceId:string, screenshotJobId:string, sourceUrl:?string, capturedAt:?string, sha256:?string, changedAt:string}> */
    public function history(Finding $finding): array
    {
        return array_map(static fn (array $row): array => [
            'revision' => (int) $row['revision'],
            'previousUrl' => $row['previous_url'],
            'url' => $row['url'],
            'source' => $row['source'],
            'evidenceId' => $row['evidence_id'],
            'screenshotJobId' => $row['screenshot_job_id'],
            'sourceUrl' => $row['source_url'],
            'capturedAt' => $row['captured_at'] === null ? null : (new \DateTimeImmutable($row['captured_at']))->format(\DateTimeInterface::ATOM),
            'sha256' => $row['sha256'],
            'changedAt' => (new \DateTimeImmutable($row['changed_at']))->format(\DateTimeInterface::ATOM),
        ], $this->connection->fetchAllAssociative('SELECT * FROM finding_poc_revision WHERE finding_id = ? ORDER BY revision DESC', [$finding->getId()]));
    }
}
