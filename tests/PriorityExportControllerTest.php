<?php

namespace App\Tests;

use App\Controller\PriorityExportController;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Value\FindingReadLabels;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

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

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('fixture', 'fixture-token'));
        $response = (new PriorityExportController($repository, $csrf))(Request::create('/legacy/operator-priority?days=14'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Live Betreiber-Priorität', $response->getContent());
        self::assertStringContainsString('example.com', $response->getContent());
        self::assertStringContainsString('/findings/'.$finding->getId().'/mark-contacted', $response->getContent());
        self::assertStringContainsString('name="_token" value="fixture-token"', $response->getContent());
        self::assertStringContainsString('https://example.com/security', $response->getContent());
        self::assertStringContainsString('Fälle in der Auswahl', $response->getContent());
        self::assertStringContainsString('Altstatus: verified', $response->getContent());
        self::assertStringContainsString(FindingReadLabels::assessment(null), $response->getContent());
        self::assertStringContainsString('mit Altstatus ungleich fixed', $response->getContent());
    }
}
