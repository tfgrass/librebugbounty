<?php

namespace App\Tests;

use App\Command\ArtifactAuditCommand;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Service\EvidenceService;
use App\Service\EvidenceStorageInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebReadAcceptanceTest extends DatabaseTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

    public function testDetailAndImagesAreReadOnlyWithPresentMissingAndNoEvidence(): void
    {
        $finding = $this->finding('present');
        $empty = $this->finding('empty');
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $png = base64_decode(self::PNG, true);
        $source = APP_TEST_ROOT.'/fixture.png';
        file_put_contents($source, $png);
        $evidence = self::getContainer()->get(EvidenceService::class)->addEvidence($finding, 'screenshot', filePath: $source);
        $missing = new Evidence();
        $missing->setFinding($finding)->setKind('screenshot')->setFilePath('storage/artifacts/'.$finding->getId().'/missing.png');
        $this->entityManager->persist($missing);
        $run = new RetestRun();
        $run->setFinding($finding)->setMode('browser')->setResult('error')->setScreenshotPath($missing->getFilePath());
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $before = $this->snapshot();
        $paths = $storage->listPaths();
        for ($i = 0; $i < 2; $i++) {
            $response = $this->get('/legacy/findings/'.$finding->getId());
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Screenshot file is missing or unavailable', $response->getContent());
            self::assertStringContainsString('record has been retained', $response->getContent());
            self::assertStringContainsString('fixture note', $response->getContent());
            self::assertStringContainsString('2026-01-02', $response->getContent());
            self::assertStringContainsString('<img ', $response->getContent());
            self::assertStringContainsString('No screenshots yet.', $this->get('/legacy/findings/'.$empty->getId())->getContent());
            $url = '/artifacts/'.substr($evidence->getFilePath(), strlen('storage/artifacts/'));
            $image = $this->get($url);
            self::assertSame(200, $image->getStatusCode());
            self::assertSame('image/png', $image->headers->get('Content-Type'));
            self::assertSame($png, $image->getContent());
            self::assertSame([1, 1], array_slice(getimagesizefromstring($image->getContent()), 0, 2));
            self::assertSame(404, $this->get('/artifacts/'.$finding->getId().'/missing.png')->getStatusCode());
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame($paths, $storage->listPaths());
    }

    public function testArtifactAuditIncludesRunReferencesAndNeverDeletes(): void
    {
        $finding = $this->finding('audit');
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $orphan = $storage->storeContents($finding, 'orphan', 'unused.png');
        $run = new RetestRun();
        $run->setFinding($finding)->setMode('browser')->setResult('error')->setScreenshotPath('storage/artifacts/'.$finding->getId().'/gone.png');
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $before = $this->snapshot();
        $tester = new CommandTester(self::getContainer()->get(ArtifactAuditCommand::class));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Missing or unavailable', $tester->getDisplay());
        self::assertStringContainsString('Unreferenced file', $tester->getDisplay());
        self::assertTrue($storage->exists($orphan->relativePath));
        self::assertSame($before, $this->snapshot());
    }

    public function testOverviewPaginates5500CasesAndKeepsFilters(): void
    {
        $first = $this->finding('bulk-0');
        for ($i = 1; $i < 5500; $i++) {
            $finding = new Finding();
            $finding->setDomain($first->getDomain())->setTitle('Fixture '.$i)->setType('other')
                ->setUrl('http://localhost/fixture/'.$i)->setMethod('GET')->setStatus('new');
            $this->entityManager->persist($finding);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();
        $response = $this->get('/legacy?pageSize=10&page=1&status=new&domain=localhost');
        self::assertSame(200, $response->getStatusCode());
        preg_match_all('#href="/findings/([a-f0-9-]+)(?:\?[^"]*)?"#', $response->getContent(), $links);
        self::assertCount(10, array_unique($links[1]));
        self::assertStringContainsString('data-total-filtered="5500"', $response->getContent());
        $next = $this->get('/legacy?pageSize=10&page=2&status=new&domain=localhost');
        preg_match_all('#href="/findings/([a-f0-9-]+)(?:\?[^"]*)?"#', $next->getContent(), $nextLinks);
        self::assertCount(10, array_unique($nextLinks[1]));
        self::assertSame([], array_intersect($links[1], $nextLinks[1]));
        self::assertStringNotContainsString('<img ', $response->getContent());
        $filtered = $this->get('/legacy?status=fixed&domain=localhost');
        self::assertSame(200, $filtered->getStatusCode());
        self::assertSame(0, preg_match_all('/class="row-link"/', $filtered->getContent()));
    }

    private function finding(string $label): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'localhost']);
        if ($domain === null) {
            $domain = new Domain();
            $domain->setHostname('localhost')->setScheme('http');
            $this->entityManager->persist($domain);
        }
        $finding = new Finding();
        $finding->setDomain($domain)->setTitle($label)->setType('other')->setUrl('http://localhost/fixture/'.$label)
            ->setMethod('GET')->setStatus('new')->setPrivateNotes('fixture note')->setContactedAt(new \DateTimeImmutable('2026-01-02'));
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        return $finding;
    }

    private function get(string $url): Response
    {
        $request = Request::create($url);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        return $response;
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['domain', 'finding', 'evidence', 'retest_run', 'setting'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }
        return $snapshot;
    }
}
