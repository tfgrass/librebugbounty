<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingReviewAcknowledgement;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StudioDeleteTest extends DatabaseTestCase
{
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);
        $capture = $this->createMock(BrowserScreenshotClientInterface::class);
        $capture->expects(self::never())->method('capture');
        $capture->expects(self::never())->method('waitUntilReady');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $capture);
    }

    public function testOpeningTheNativeDeleteConfirmationIsReadOnlyAndRetainsListContext(): void
    {
        $finding = $this->finding('confirmation');
        $before = $this->snapshot();
        $returnTo = '/findings?scope=active&q=confirmation&pageSize=25';
        $response = $this->request('/findings/'.$finding->getId().'?'.http_build_query(['return_to' => $returnTo]));
        self::assertSame(200, $response->getStatusCode());
        $xpath = $this->xpath($response->getContent());
        $form = '//details[@data-studio-delete-section]//form[@data-studio-delete]';
        self::assertSame(1, $xpath->query($form)->length);
        self::assertSame('/findings/'.$finding->getId().'/delete', $xpath->evaluate('string('.$form.'/@action)'));
        self::assertSame('post', $xpath->evaluate('string('.$form.'/@method)'));
        self::assertSame('studio', $xpath->evaluate('string('.$form.'//input[@name="surface"]/@value)'));
        self::assertSame($returnTo, $xpath->evaluate('string('.$form.'//input[@name="return_to"]/@value)'));
        self::assertSame(1, $xpath->query($form.'//input[@type="checkbox" and @name="confirm_delete" and @value="1" and @required]')->length);
        self::assertSame(0, $xpath->query($form.'//input[@name="confirm_delete" and @checked]')->length);
        self::assertSame($before, $this->snapshot());
    }

    public function testMissingOrInvalidConfirmationPreservesTheEntireCaseAndItsFiles(): void
    {
        $finding = $this->finding('not-confirmed');
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $file = $storage->storeContents($finding, 'retain this local fixture', 'fixture.txt')->relativePath;
        $before = $this->snapshot();
        $returnTo = '/findings?scope=active&q=not-confirmed';
        foreach ([[], ['confirm_delete' => '0'], ['confirm_delete' => 'true'], ['confirm_delete' => ['1']]] as $parameters) {
            $response = $this->request('/findings/'.$finding->getId().'/delete', 'POST', $parameters + [
                'surface' => 'studio', 'return_to' => $returnTo,
            ]);
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/findings/'.$finding->getId(), parse_url($response->headers->get('Location'), PHP_URL_PATH));
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            self::assertSame($returnTo, $query['return_to']);
            self::assertStringContainsString('bestätige', $query['error']);
            self::assertSame($before, $this->snapshot());
            self::assertSame('retain this local fixture', $storage->read($file));
        }
    }

    public function testConfirmedDeletionRemovesOnlyTheSelectedCaseAndAllOfItsDependents(): void
    {
        $finding = $this->finding('remove');
        $sibling = $this->finding('retain');
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $file = $storage->storeContents($finding, 'selected fixture', 'selected.txt')->relativePath;
        $siblingFile = $storage->storeContents($sibling, 'sibling fixture', 'sibling.txt')->relativePath;
        $evidence = (new Evidence())->setFinding($finding)->setKind('note')->setFilePath($file)->setValue('stored fixture');
        $run = (new RetestRun())->setFinding($finding)->setMode('fixture')->setResult('inconclusive');
        $job = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus('queued')->setActiveKey($finding->getId());
        foreach ([$evidence, $run, $job] as $record) {
            $this->entityManager->persist($record);
        }
        $this->entityManager->flush();
        self::getContainer()->get(FindingService::class)->assess($finding, 'confirmed');
        $this->entityManager->persist(new FindingReviewAcknowledgement($finding, 'fixture-decision', 'confirmed', [], []));
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        $domainId = $finding->getDomain()->getId();
        $returnTo = '/findings?scope=active&q=retain&page=2&pageSize=25';
        $response = $this->request('/findings/'.$finding->getId().'/delete', 'POST', [
            'surface' => 'studio', 'confirm_delete' => '1', 'return_to' => $returnTo,
        ]);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/findings', parse_url($response->headers->get('Location'), PHP_URL_PATH));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertSame('retain', $query['q']);
        self::assertSame('2', $query['page']);
        self::assertSame('25', $query['pageSize']);
        self::assertStringContainsString('endgültig gelöscht', $query['message']);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding WHERE id = ?', [$finding->getId()]));
        foreach (['finding_assessment', 'finding_review_acknowledgement', 'evidence', 'retest_run', 'screenshot_job'] as $table) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE finding_id = ?', [$finding->getId()]), $table);
        }
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding WHERE id = ?', [$sibling->getId()]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM domain WHERE id = ?', [$domainId]));
        self::assertFalse($storage->exists($file));
        self::assertSame('sibling fixture', $storage->read($siblingFile));
    }

    public function testAnExternalReturnPathCannotRedirectDeletionAwayFromTheInventory(): void
    {
        $finding = $this->finding('return-path');
        $response = $this->request('/findings/'.$finding->getId().'/delete', 'POST', [
            'surface' => 'studio', 'confirm_delete' => '1', 'return_to' => 'https://outside.example.test/findings',
        ]);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/findings', parse_url($response->headers->get('Location'), PHP_URL_PATH));
        self::assertNull(parse_url($response->headers->get('Location'), PHP_URL_HOST));
    }

    public function testMalformedNavigationFieldsDoNotDeleteAnything(): void
    {
        $finding = $this->finding('invalid-input');
        $before = $this->snapshot();
        foreach ([['surface' => ['studio']], ['surface' => 'studio', 'return_to' => ['/findings']]] as $parameters) {
            self::assertSame(400, $this->request('/findings/'.$finding->getId().'/delete', 'POST', $parameters + [
                'confirm_delete' => '1',
            ])->getStatusCode());
            self::assertSame($before, $this->snapshot());
        }
    }

    private function finding(string $name): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'studio-delete.example.test']);
        if (!$domain instanceof Domain) {
            $domain = (new Domain())->setHostname('studio-delete.example.test');
            $this->entityManager->persist($domain);
        }
        $finding = (new Finding())->setDomain($domain)->setTitle($name)->setType('synthetic')
            ->setUrl('https://studio-delete.example.test/'.$name)->setPrivateNotes('private fixture');
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['domain', 'finding', 'finding_assessment', 'finding_review_acknowledgement', 'evidence', 'retest_run', 'screenshot_job', 'setting'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $snapshot;
    }
}
