<?php

namespace App\Tests;

use App\Controller\PriorityExportController;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Repository\FindingRepository;
use Symfony\Component\HttpFoundation\Request;

final class PriorityExportControllerTest extends UnitTestCase
{
    public function testLiveExportGroupsFindingsAndProvidesContactAction(): void
    {
        $domain = new Domain();
        $domain->setHostname('example.com');
        $domain->setScheme('https');

        $finding = new Finding();
        $finding->setDomain($domain);
        $finding->setTitle('Reflected XSS');
        $finding->setType(self::DEFAULT_FINDING_TYPE);
        $finding->setSeverity('high');
        $finding->setStatus('verified');
        $finding->setUrl('https://example.com/search?q=x');
        $finding->setMethod('GET');
        $finding->setReportUrl('https://example.com/security');
        $finding->initializeTimestamps();

        $repository = $this->createMock(FindingRepository::class);
        $repository->expects(self::once())
            ->method('findForPriorityExport')
            ->willReturn([$finding]);

        $response = (new PriorityExportController($repository))(Request::create('/operator-priority?days=14'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Live Betreiber-Priorität', $response->getContent());
        self::assertStringContainsString('example.com', $response->getContent());
        self::assertStringContainsString('/findings/'.$finding->getId().'/mark-contacted', $response->getContent());
        self::assertStringContainsString('https://example.com/security', $response->getContent());
    }
}
