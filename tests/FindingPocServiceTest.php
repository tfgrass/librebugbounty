<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\ScreenshotJob;
use App\Exception\InteractiveCaptureInvalidatedException;
use App\Service\FindingPocService;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;

final class FindingPocServiceTest extends DatabaseTestCase
{
    private const ORIGINAL = 'https://target.example/original?payload=%2f%252f';
    private const SELECTED = 'https://target.example/new?payload=%2F%252F&b=2&a=1';

    public function testSelectionKeepsOriginalAndImmutableImageSourceAndHistory(): void
    {
        [$finding, $evidence, $job] = $this->fixture();
        $before = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$finding->getId()]);
        self::assertSame(self::ORIGINAL, $finding->getPocUrl());
        self::assertSame(0, $finding->getPocRevision());
        $this->apply($finding, $evidence, $job, self::SELECTED);
        $this->entityManager->refresh($finding);

        self::assertSame(self::ORIGINAL, $finding->getUrl());
        self::assertSame(self::SELECTED, $finding->getPocUrl());
        self::assertSame(1, $finding->getPocRevision());
        $after = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$finding->getId()]);
        unset($before['poc_url'], $before['poc_revision'], $before['updated_at'], $after['poc_url'], $after['poc_revision'], $after['updated_at']);
        self::assertSame($before, $after, 'PoC selection must not change a review, assessment, schedule or original URL.');
        $history = $this->service()->history($finding);
        self::assertCount(1, $history);
        self::assertSame(1, $history[0]['revision']);
        self::assertSame(self::ORIGINAL, $history[0]['previousUrl']);
        self::assertSame(self::SELECTED, $history[0]['url']);
        self::assertSame('observed', $history[0]['source']);
        self::assertSame($evidence->getId(), $history[0]['evidenceId']);
        self::assertSame($job->getId(), $history[0]['screenshotJobId']);
        self::assertSame('https://target.example/dialog?image=%2f', $history[0]['sourceUrl']);
        self::assertSame($job->getCapturedAt()->format(\DateTimeInterface::ATOM), $history[0]['capturedAt']);
        self::assertSame((new \DateTimeImmutable('2026-10-18T09:10:11+00:00'))->getTimestamp(), (new \DateTimeImmutable($history[0]['capturedAt']))->getTimestamp());
        self::assertSame(str_repeat('a', 64), $history[0]['sha256']);
        self::assertSame(self::ORIGINAL, $job->getUrl());
        self::assertSame('https://target.example/dialog?image=%2f', $job->getCaptureMetadata()['finalUrl']);

        // A history source remains truthful if a particular image is later removed.
        $this->entityManager->remove($evidence);
        $this->entityManager->remove($job);
        $this->entityManager->flush();
        self::assertSame($history, $this->service()->history($finding));
    }

    public function testStaleSelectionCannotOverwriteCurrentPocEvenWhenSameUrlWasSubmitted(): void
    {
        [$finding, $evidence, $job] = $this->fixture();
        $this->apply($finding, $evidence, $job, self::SELECTED);
        foreach ([self::SELECTED, 'https://target.example/another'] as $url) {
            try {
                $this->apply($finding, $evidence, $job, $url);
                self::fail('A stale browser revision must not be accepted.');
            } catch (InteractiveCaptureInvalidatedException $error) {
                self::assertSame(409, $error->getCode());
            }
        }
        self::assertSame(self::SELECTED, $this->entityManager->getConnection()->fetchOne('SELECT poc_url FROM finding WHERE id = ?', [$finding->getId()]));
        self::assertCount(1, $this->service()->history($finding));
    }

    public function testSelectingSameUrlIsNoopAndReturningToOriginalAddsHistory(): void
    {
        [$finding, $evidence, $job] = $this->fixture();
        self::assertNull($this->apply($finding, $evidence, $job, self::ORIGINAL));
        self::assertSame([], $this->service()->history($finding));
        $this->apply($finding, $evidence, $job, self::SELECTED);
        self::assertNull($this->apply($finding, $evidence, $job, self::SELECTED, 1));
        $this->apply($finding, $evidence, $job, self::ORIGINAL, 1, 'manual');
        $history = $this->service()->history($finding);
        self::assertSame([2, 1], array_column($history, 'revision'));
        self::assertSame(self::SELECTED, $history[0]['previousUrl']);
        self::assertSame(self::ORIGINAL, $history[0]['url']);
        self::assertSame('manual', $history[0]['source']);
        $this->entityManager->refresh($finding);
        self::assertSame(self::ORIGINAL, $finding->getPocUrl());
        self::assertSame(2, $finding->getPocRevision());
    }

    public function testApplyJoinsTransactionWithoutFlushingAndRollbackRestoresPoc(): void
    {
        [$finding, $evidence, $job] = $this->fixture();
        $db = $this->entityManager->getConnection();
        $writer = $this->writer();
        $db->beginTransaction();
        try {
            $this->service()->apply($writer->find(Finding::class, $finding->getId()), self::SELECTED, 0, 'manual', $writer->find(Evidence::class, $evidence->getId()), $writer->find(ScreenshotJob::class, $job->getId()), $writer);
            self::assertSame(self::SELECTED, $db->fetchOne('SELECT poc_url FROM finding WHERE id = ?', [$finding->getId()]));
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM finding_poc_revision'), 'The owning transaction chooses when history/evidence are flushed.');
            $writer->flush();
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM finding_poc_revision'));
        } finally {
            $db->rollBack();
            $writer->close();
        }
        self::assertNull($db->fetchOne('SELECT poc_url FROM finding WHERE id = ?', [$finding->getId()]));
        self::assertSame(0, (int) $db->fetchOne('SELECT poc_revision FROM finding WHERE id = ?', [$finding->getId()]));
        self::assertSame([], $this->service()->history($finding));
    }

    public function testApplyRequiresAnOwnedPersistenceTransaction(): void
    {
        [$finding, $evidence, $job] = $this->fixture();
        $this->expectException(\LogicException::class);
        $this->service()->apply($finding, self::SELECTED, 0, 'manual', $evidence, $job, $this->entityManager);
    }

    public function testOuterCallerEditsStayIsolatedAndHistoryInsertFailureRollsBackSelection(): void
    {
        [$finding, $evidence, $job] = $this->fixture();
        $finding->setPrivateNotes('Unsaved review note');
        $db = $this->entityManager->getConnection();
        $db->executeStatement("CREATE TRIGGER reject_poc_history BEFORE INSERT ON finding_poc_revision BEGIN SELECT RAISE(ABORT, 'forced history failure'); END");
        $writer = $this->writer();
        try {
            $db->transactional(function () use ($finding, $evidence, $job, $writer, $db): void {
                $this->service()->apply($finding, self::SELECTED, 0, 'observed', $evidence, $job, $writer);
                self::assertSame(self::ORIGINAL, $finding->getPocUrl(), 'The outer caller must not become dirty with a pending PoC selection.');
                self::assertSame(0, $finding->getPocRevision());
                self::assertNull($db->fetchOne('SELECT private_notes FROM finding WHERE id = ?', [$finding->getId()]));
                $writer->flush();
            });
            self::fail('A failed history insert must fail the entire selection.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('forced history failure', $error->getMessage());
        } finally {
            $writer->close();
        }
        self::assertNull($db->fetchOne('SELECT poc_url FROM finding WHERE id = ?', [$finding->getId()]));
        self::assertSame(0, (int) $db->fetchOne('SELECT poc_revision FROM finding WHERE id = ?', [$finding->getId()]));
        self::assertSame([], $this->service()->history($finding));
        self::assertTrue($this->entityManager->isOpen());
        $this->entityManager->flush();
        self::assertSame('Unsaved review note', $db->fetchOne('SELECT private_notes FROM finding WHERE id = ?', [$finding->getId()]));
        self::assertNull($db->fetchOne('SELECT poc_url FROM finding WHERE id = ?', [$finding->getId()]), 'A later ordinary flush must not resurrect a rolled-back PoC selection.');
    }

    #[DataProvider('invalidUrls')]
    public function testRejectsUnsafeOrCrossHostSelectionWithoutNormalizingIt(string $url): void
    {
        $finding = (new Finding())->setUrl(self::ORIGINAL)->setMethod('GET');
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->validate($finding, $url);
    }

    public static function invalidUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'relative' => ['/case'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'file' => ['file:///tmp/test'];
        yield 'other host' => ['https://elsewhere.example/case'];
        yield 'subdomain changes identity' => ['https://www.target.example/case'];
        yield 'credentials' => ['https://user:pass@target.example/case'];
        yield 'empty credentials' => ['https://@target.example/case'];
        yield 'browser rejects port suffix' => ['https://target.example:80foo/case'];
        yield 'browser rejects decimal port' => ['https://target.example:80.0/case'];
        yield 'newline' => ["https://target.example/a\nb"];
        yield 'space' => ['https://target.example/a b'];
        yield 'browser backslash authority' => ['https://target.example\\@elsewhere.example/case'];
        yield 'control' => ["https://target.example/a\x7fb"];
        yield 'exceeds interactive worker limit' => ['https://target.example/'.str_repeat('a', 8193)];
    }

    public function testGetOnlyButPayloadEncodingsAndFragmentsRemainExact(): void
    {
        $finding = (new Finding())->setUrl(self::ORIGINAL)->setMethod('GET');
        $url = 'HTTPS://TARGET.EXAMPLE/case?q=%0A%2f%252F&x=2&x=1#payload=alert(1)';
        $this->service()->validate($finding, $url);
        $finding->setMethod('POST');
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->validate($finding, $url);
    }

    private function service(): FindingPocService
    {
        return new FindingPocService($this->entityManager->getConnection());
    }

    private function writer(): EntityManager
    {
        return new EntityManager($this->entityManager->getConnection(), $this->entityManager->getConfiguration(), $this->entityManager->getEventManager());
    }

    private function apply(Finding $finding, Evidence $evidence, ScreenshotJob $job, string $url, int $expectedRevision = 0, string $source = 'observed'): ?\App\Entity\FindingPocRevision
    {
        $writer = $this->writer();
        try {
            return $this->entityManager->getConnection()->transactional(function () use ($writer, $finding, $evidence, $job, $url, $expectedRevision, $source) {
                $revision = $this->service()->apply($writer->find(Finding::class, $finding->getId()), $url, $expectedRevision, $source, $writer->find(Evidence::class, $evidence->getId()), $writer->find(ScreenshotJob::class, $job->getId()), $writer);
                $writer->flush();
                return $revision;
            });
        } finally {
            $writer->close();
        }
    }

    private function fixture(): array
    {
        $domain = (new Domain())->setHostname('target.example');
        $finding = (new Finding())->setDomain($domain)->setType('xss')->setTitle('PoC URL fixture')->setUrl(self::ORIGINAL)->setMethod('GET');
        $evidence = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath('proof.png')->setSha256(str_repeat('a', 64));
        $job = (new ScreenshotJob())->setFinding($finding)->setUrl(self::ORIGINAL)->setScreenshotPath('proof.png')
            ->setCapturedAt((new \DateTimeImmutable('2026-10-18T09:10:11+00:00'))->setTimezone(new \DateTimeZone(date_default_timezone_get())))->setCaptureMetadata(['finalUrl' => 'https://target.example/dialog?image=%2f']);
        foreach ([$domain, $finding, $evidence, $job] as $entity) $this->entityManager->persist($entity);
        $this->entityManager->flush();
        return [$finding, $evidence, $job];
    }
}
