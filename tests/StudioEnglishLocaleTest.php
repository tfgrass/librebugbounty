<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\RetestRun;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

final class StudioEnglishLocaleTest extends DatabaseTestCase
{
    private Session $session;
    private string|false $previousEnvironmentLocale;
    private bool $hadServerLocale;
    private mixed $previousServerLocale;
    private bool $hadEnvLocale;
    private mixed $previousEnvLocale;

    protected function setUp(): void
    {
        $this->previousEnvironmentLocale = getenv('APP_LOCALE');
        $this->hadServerLocale = array_key_exists('APP_LOCALE', $_SERVER);
        $this->previousServerLocale = $_SERVER['APP_LOCALE'] ?? null;
        $this->hadEnvLocale = array_key_exists('APP_LOCALE', $_ENV);
        $this->previousEnvLocale = $_ENV['APP_LOCALE'] ?? null;
        putenv('APP_LOCALE=en');
        $_SERVER['APP_LOCALE'] = $_ENV['APP_LOCALE'] = 'en';

        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->previousEnvironmentLocale === false
                ? putenv('APP_LOCALE')
                : putenv('APP_LOCALE='.$this->previousEnvironmentLocale);
            $this->restoreSuperglobal($_SERVER, $this->hadServerLocale, $this->previousServerLocale);
            $this->restoreSuperglobal($_ENV, $this->hadEnvLocale, $this->previousEnvLocale);
        }
    }

    public function testCentralStudioPagesRenderTheConfiguredEnglishLocale(): void
    {
        $pages = [
            ['/', 'Add URL', 'URL erfassen'],
            ['/findings', 'Cases', 'Fälle'],
            ['/review?images=all', 'Manual review', 'Manuelle Prüfung'],
            ['/statistics', 'From first report to resolution.', 'Von der ersten Meldung bis zur Behebung.'],
            ['/export', 'What do you want to export for?', 'Wofür möchtest du exportieren?'],
            ['/settings', 'Settings & info', 'Einstellungen & Info'],
        ];

        foreach ($pages as [$path, $english, $german]) {
            $response = $this->request($path);
            self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $path);
            self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'), $path);
            $xpath = $this->xpath($response->getContent());
            self::assertSame('en', $xpath->evaluate('string(/html/@lang)'), $path);
            $visibleText = $this->visibleText($xpath);
            self::assertStringContainsString($english, $visibleText, $path);
            self::assertStringNotContainsString($german, $visibleText, $path);
        }
    }

    public function testExportArchiveScopesAndIntakeApiFeedbackUseEnglish(): void
    {
        $export = $this->xpath($this->request('/export?scope=discarded')->getContent());
        $visibleText = $this->visibleText($export);
        self::assertStringContainsString('Discarded · Archive', $visibleText);
        self::assertStringContainsString('Duplicates · Archive', $visibleText);
        self::assertStringNotContainsString('Verworfen · Archiv', $visibleText);
        self::assertStringNotContainsString('Duplikate · Archiv', $visibleText);

        $invalid = $this->request('/api/findings', 'POST');
        self::assertSame(Response::HTTP_FORBIDDEN, $invalid->getStatusCode());
        self::assertSame(
            'The form has expired or is invalid. Reload the page.',
            json_decode($invalid->getContent(), true, 512, JSON_THROW_ON_ERROR)['error'],
        );

        $invalidUrl = $this->request('/api/findings', 'POST', [
            '_token' => $this->intakeToken(),
            'url' => 'https://localhost/fixture',
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $invalidUrl->getStatusCode());
        $invalidPayload = json_decode($invalidUrl->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('The URL or its domain is invalid.', $invalidPayload['error']);
        self::assertSame('Die URL oder ihre Domain ist ungültig.', $invalidPayload['errorKey']);

        $finding = $this->finding('api-status');
        $status = $this->request('/api/findings/status?'.http_build_query(['ids' => [$finding->getId()]]));
        self::assertSame(Response::HTTP_OK, $status->getStatusCode());
        $payload = json_decode($status->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('No screenshot job stored', $payload['findings'][0]['screenshot']['label']);

        $ids = array_map(static fn (): string => Uuid::v4()->toRfc4122(), range(1, 51));
        $tooMany = $this->request('/api/findings/status?'.http_build_query(['ids' => $ids]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $tooMany->getStatusCode());
        $error = json_decode($tooMany->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('At most 50 finding IDs can be read at once.', $error['error']);
        self::assertSame('Höchstens {count} Finding-IDs können gleichzeitig gelesen werden.', $error['errorKey']);
        self::assertSame(['count' => 50], $error['errorParameters']);
    }

    public function testHumanReportPackageUsesTheConfiguredEnglishLocale(): void
    {
        $finding = $this->finding('english-report')
            ->setMethod('POST')
            ->setRequestParams(['query' => 'LBB-ENGLISH'])
            ->setPayload('<svg onload=alert(1)>')
            ->setExpectedEvidence('LBB-ENGLISH')
            ->setPrivateNotes('English report fixture')
            ->setReportUrl('https://reports.example/report/1')
            ->setReportedAt(new \DateTimeImmutable('2026-10-01T08:00:00+02:00'))
            ->setContactedAt(new \DateTimeImmutable('2026-10-02T09:00:00+02:00'))
            ->setNotifiedOwnerAt(new \DateTimeImmutable('2026-10-02T09:05:00+02:00'));
        $assessedAt = new \DateTimeImmutable('2026-10-03T10:00:00+02:00');
        $finding->setManualAssessment('confirmed', null, $assessedAt);
        $this->entityManager->persist(new FindingAssessment($finding, 'confirmed', null, $assessedAt));
        $this->entityManager->persist((new RetestRun())
            ->setFinding($finding)
            ->setMode('browser')
            ->setResult('still_vulnerable')
            ->setStartedAt(new \DateTimeImmutable('2026-10-03T09:58:00+02:00'))
            ->setFinishedAt(new \DateTimeImmutable('2026-10-03T09:59:00+02:00'))
            ->setHttpStatus(200)
            ->setFinalUrl($finding->getUrl())
            ->setObservedEvidence('LBB-ENGLISH'));
        $this->entityManager->flush();

        $response = $this->request('/export/download?'.http_build_query([
            'profile' => 'report', 'screenshots' => 'none', 'include_request_data' => '1',
            'include_assessment' => '1', 'include_contact' => '1', 'include_notes' => '1',
        ]));
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        $archivePath = $response->getFile()->getPathname();
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($archivePath));
        try {
            $report = $archive->getFromName('report.md');
            self::assertIsString($report);
        } finally {
            $archive->close();
            @unlink($archivePath);
        }

        foreach (['# LibreBugBounty – report package', 'Created:', '- Type:', '- Severity:', '- Method:', '- Manual assessment: Finding confirmed', '- Latest technical observation:', 'Evidence found', '- Image evidence:', 'No image file for the selected image option.'] as $expected) {
            self::assertStringContainsString($expected, $report);
        }
        foreach (['Meldungspaket', 'Erstellt:', 'Typ:', 'Schweregrad:', 'Methode:', 'Manuelle Bewertung:', 'Letzte technische Beobachtung:', 'Bildbelege:'] as $german) {
            self::assertStringNotContainsString($german, $report);
        }
    }

    private function finding(string $label): Finding
    {
        $domain = (new Domain())->setHostname('english-export.example')->setScheme('https');
        $finding = (new Finding())->setDomain($domain)->setTitle($label)->setType('reflected_xss')
            ->setSeverity('medium')->setUrl('https://english-export.example/'.$label)
            ->setExpectedEvidence('LBB-ENGLISH');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    private function intakeToken(): string
    {
        $response = $this->request('/');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $response->getContent(), $match));

        return html_entity_decode($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    private function visibleText(\DOMXPath $xpath): string
    {
        $parts = [];
        foreach ($xpath->query('//body//text()[not(ancestor::script) and not(ancestor::style)]') as $node) {
            $parts[] = $node->nodeValue;
        }
        $text = preg_replace('/\s+/u', ' ', implode(' ', $parts));
        self::assertIsString($text);

        return trim($text);
    }

    private function restoreSuperglobal(array &$source, bool $existed, mixed $value): void
    {
        if ($existed) {
            $source['APP_LOCALE'] = $value;
        } else {
            unset($source['APP_LOCALE']);
        }
    }
}
