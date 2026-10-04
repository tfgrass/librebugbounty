<?php

namespace App\Tests;

use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use App\Service\SettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StudioIntakeTest extends DatabaseTestCase
{
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());

        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);

        $screenshot = $this->createMock(BrowserScreenshotClientInterface::class);
        $screenshot->expects(self::never())->method('capture');
        $screenshot->expects(self::never())->method('waitUntilReady');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $screenshot);
    }

    public function testStudioLoadsWithoutExposingLegacyNavigationOrChangingDataOrArtifacts(): void
    {
        self::getContainer()->get(FindingService::class)->createFinding('http://studio-existing.localhost/fixture');
        $before = $this->snapshot();
        $storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $paths = $storage->listPaths();

        $response = $this->request('/');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $xpath = $this->xpath($response->getContent());
        $form = $this->intakeForm($xpath);
        self::assertSame('post', strtolower($form->getAttribute('method')));
        self::assertSame(1, $xpath->query('.//input[@name="url"]', $form)->length);
        self::assertSame(1, $xpath->query('.//input[@name="payload"]', $form)->length);
        self::assertSame(1, $xpath->query('.//textarea[@name="annotate"]', $form)->length);
        self::assertSame(SettingsService::DEFAULTS['intake.default_payload'], $this->payload($xpath));

        self::assertSame(0, $xpath->query('//a[starts-with(@href, "/legacy")]')->length);
        self::assertSame($before, $this->snapshot());
        self::assertSame($paths, $storage->listPaths());
    }

    public function testTrailingSlashRedirectsToTheWorkingCanonicalStudioRoute(): void
    {
        $before = $this->snapshot();
        $response = $this->request('/studio/');

        self::assertContains($response->getStatusCode(), [Response::HTTP_MOVED_PERMANENTLY, Response::HTTP_PERMANENTLY_REDIRECT]);
        $location = $response->headers->get('Location');
        if (parse_url($location, PHP_URL_PATH) === '/studio') {
            $canonical = $this->request($location);
            self::assertSame(Response::HTTP_PERMANENTLY_REDIRECT, $canonical->getStatusCode());
            $location = $canonical->headers->get('Location');
        }
        self::assertSame('/', parse_url($location, PHP_URL_PATH));
        self::assertContains(parse_url($location, PHP_URL_HOST), [null, 'localhost']);
        self::assertSame(Response::HTTP_OK, $this->request($location)->getStatusCode());
        self::assertSame($before, $this->snapshot());
    }

    public function testStudioIsAReadOnlyGetRouteRatherThanASecondWriteEndpoint(): void
    {
        $before = $this->snapshot();
        $response = $this->request('/studio', 'POST', ['url' => 'http://studio-post.localhost/fixture']);

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        self::assertStringContainsString('GET', (string) $response->headers->get('Allow'));
        self::assertSame($before, $this->snapshot());
    }

    public function testConfiguredDefaultPayloadIsPreservedAndEscapedInTheStudioForm(): void
    {
        $payload = '" ><span id="fixture-settings-markup">Kennzeichen & Hinweis</span>';
        self::getContainer()->get(SettingsService::class)->save(['intake.default_payload' => $payload]);
        $before = $this->snapshot();

        $response = $this->request('/');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $xpath = $this->xpath($response->getContent());
        self::assertSame($payload, $this->payload($xpath));
        self::assertSame(0, $xpath->query('//*[@id="fixture-settings-markup"]')->length);
        self::assertSame($before, $this->snapshot());
    }

    public function testBlankConfiguredPayloadUsesTheExistingDefault(): void
    {
        self::getContainer()->get(SettingsService::class)->save(['intake.default_payload' => '   ']);
        $before = $this->snapshot();

        $response = $this->request('/');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(SettingsService::DEFAULTS['intake.default_payload'], $this->payload($this->xpath($response->getContent())));
        self::assertSame($before, $this->snapshot());
    }

    public function testStudioCsrfWorksWithExistingApiForStoredAndDuplicateResponses(): void
    {
        $response = $this->request('/');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $xpath = $this->xpath($response->getContent());
        $form = $this->intakeForm($xpath);
        $token = $xpath->query('.//input[@name="_token"]', $form)->item(0);
        self::assertInstanceOf(\DOMElement::class, $token);
        self::assertNotSame('', $token->getAttribute('value'));
        $parameters = [
            '_token' => $token->getAttribute('value'),
            'url' => 'http://studio-intake.localhost/fixture',
            'payload' => $this->payload($xpath),
            'annotate' => 'Original studio note',
        ];

        $storedResponse = $this->jsonRequest('/api/findings', $parameters);
        self::assertSame(Response::HTTP_CREATED, $storedResponse->getStatusCode());
        $stored = $this->json($storedResponse);
        self::assertSame('stored', $stored['outcome']);
        self::assertSame('queued', $stored['status']['screenshot']['state']);
        self::assertNull($stored['status']['observation']);

        $duplicateResponse = $this->jsonRequest('/api/findings', array_replace($parameters, [
            'payload' => 'REPLACEMENT',
            'annotate' => 'Replacement note',
        ]));
        self::assertSame(Response::HTTP_OK, $duplicateResponse->getStatusCode());
        $duplicate = $this->json($duplicateResponse);
        self::assertSame('duplicate', $duplicate['outcome']);
        self::assertSame($stored['finding']['id'], $duplicate['finding']['id']);

        $finding = $this->entityManager->getRepository(Finding::class)->find($stored['finding']['id']);
        self::assertInstanceOf(Finding::class, $finding);
        self::assertSame($parameters['payload'], $finding->getExpectedEvidence());
        self::assertSame('Original studio note', $finding->getPrivateNotes());
        self::assertSame(1, $this->entityManager->getRepository(Finding::class)->count([]));
        self::assertSame(1, $this->entityManager->getRepository(ScreenshotJob::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(RetestRun::class)->count([]));
        self::assertSame(Response::HTTP_OK, $this->request($stored['finding']['detailUrl'])->getStatusCode());
    }

    private function intakeForm(\DOMXPath $xpath): \DOMElement
    {
        $form = $xpath->query('//form[.//input[@name="url"]]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);

        return $form;
    }

    private function payload(\DOMXPath $xpath): string
    {
        $input = $xpath->query('.//input[@name="payload"]', $this->intakeForm($xpath))->item(0);
        self::assertInstanceOf(\DOMElement::class, $input);

        return $input->getAttribute('value');
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function jsonRequest(string $path, array $payload): Response
    {
        $request = Request::create(
            $path,
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function json(Response $response): array
    {
        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['domain', 'finding', 'finding_assessment', 'retest_run', 'screenshot_job', 'evidence', 'setting'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $snapshot;
    }
}
