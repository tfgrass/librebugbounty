<?php

namespace App\Tests;

use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\FindingService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

final class FindingIntakeApiTest extends DatabaseTestCase
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

    public function testPostStoresImmediatelyAndReturnsAnExactDuplicateWithoutStartingBrowserWork(): void
    {
        $token = $this->intakeToken();
        $url = 'http://fast-intake.localhost/search?q=fixture';

        $storedResponse = $this->jsonRequest('/api/findings', [
            '_token' => $token,
            'url' => $url,
            'payload' => 'FIRST-MARKER',
            'annotate' => 'Keep the first note',
        ]);
        self::assertSame(Response::HTTP_CREATED, $storedResponse->getStatusCode());
        self::assertStringContainsString('application/json', (string) $storedResponse->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $storedResponse->headers->get('Cache-Control'));
        $stored = $this->json($storedResponse);
        self::assertSame('stored', $stored['outcome']);
        self::assertSame($url, $stored['finding']['url']);
        self::assertSame('/findings/'.$stored['finding']['id'], $stored['finding']['detailUrl']);
        self::assertSame($stored['finding']['id'], $stored['status']['id']);
        self::assertSame('queued', $stored['status']['screenshot']['state']);
        self::assertNull($stored['status']['observation']);

        $duplicateResponse = $this->request('/api/findings', 'POST', [
            '_token' => $token,
            'url' => $url,
            'payload' => 'REPLACEMENT-MARKER',
            'annotate' => 'Do not replace the first note',
        ]);
        self::assertSame(Response::HTTP_OK, $duplicateResponse->getStatusCode());
        $duplicate = $this->json($duplicateResponse);
        self::assertSame('duplicate', $duplicate['outcome']);
        self::assertSame($stored['finding']['id'], $duplicate['finding']['id']);
        self::assertSame($stored['status']['detailUrl'], $duplicate['status']['detailUrl']);

        $finding = $this->entityManager->getRepository(Finding::class)->find($stored['finding']['id']);
        self::assertInstanceOf(Finding::class, $finding);
        self::assertSame('FIRST-MARKER', $finding->getExpectedEvidence());
        self::assertSame('Keep the first note', $finding->getPrivateNotes());
        self::assertSame(1, $this->entityManager->getRepository(Finding::class)->count([]));
        self::assertSame(1, $this->entityManager->getRepository(ScreenshotJob::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(RetestRun::class)->count([]));
    }

    public function testPostRejectsMissingOrForgedCsrfAndInvalidInputWithoutWritingAnything(): void
    {
        $token = $this->intakeToken();
        foreach ([
            ['parameters' => ['url' => 'http://csrf.localhost/fixture'], 'status' => Response::HTTP_FORBIDDEN],
            ['parameters' => ['_token' => 'forged', 'url' => 'http://csrf.localhost/fixture'], 'status' => Response::HTTP_FORBIDDEN],
            ['parameters' => ['_token' => $token, 'url' => 'not a URL'], 'status' => Response::HTTP_UNPROCESSABLE_ENTITY],
            ['parameters' => ['_token' => $token, 'url' => ['http://nested.localhost/fixture']], 'status' => Response::HTTP_UNPROCESSABLE_ENTITY],
        ] as $case) {
            $response = $this->request('/api/findings', 'POST', $case['parameters']);
            self::assertSame($case['status'], $response->getStatusCode());
            self::assertIsString($this->json($response)['error']);
            self::assertSame(0, $this->entityManager->getRepository(Finding::class)->count([]));
            self::assertSame(0, $this->entityManager->getRepository(ScreenshotJob::class)->count([]));
        }
    }

    public function testQueueInsertFailureRollsBackTheFindingAndReturnsOnlyAGenericServerError(): void
    {
        $token = $this->intakeToken();
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            "CREATE TRIGGER fixture_reject_screenshot BEFORE INSERT ON screenshot_job "
            ."BEGIN SELECT RAISE(ABORT, 'private fixture queue failure'); END",
        );

        $response = $this->request('/api/findings', 'POST', [
            '_token' => $token,
            'url' => 'http://atomic-intake.localhost/fixture',
        ]);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(
            'Die Speicherung konnte nicht bestätigt werden. Bitte prüfe den Bestand, bevor du die Eingabe erneut sendest.',
            $body['error'],
        );
        self::assertStringNotContainsString('private fixture queue failure', $response->getContent());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
    }

    public function testStatusReadsSeveralDeduplicatedIdsInRequestOrderAndReportsMissingIds(): void
    {
        $service = self::getContainer()->get(FindingService::class);
        $first = $service->createFinding('http://status-first.localhost/fixture');
        $second = $service->createFinding('http://status-second.localhost/fixture');
        $missing = Uuid::v4()->toRfc4122();
        $before = $this->snapshot();

        $query = http_build_query(['ids' => [strtoupper($second->getId()), $missing, $first->getId(), $second->getId()]]);
        $response = $this->request('/api/findings/status?'.$query);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $body = $this->json($response);
        self::assertSame([$second->getId(), $first->getId()], array_column($body['findings'], 'id'));
        self::assertSame([$missing], $body['missingIds']);
        self::assertSame('/findings/'.$second->getId(), $body['findings'][0]['detailUrl']);
        self::assertSame('queued', $body['findings'][0]['screenshot']['state']);
        self::assertSame($before, $this->snapshot());
    }

    public function testStatusValidatesIdsAndLimitsTheUniqueBatchToFifty(): void
    {
        foreach ([[], ['not-a-uuid']] as $ids) {
            $response = $this->request('/api/findings/status?'.http_build_query(['ids' => $ids]));
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
            self::assertIsString($this->json($response)['error']);
        }

        $fifty = array_map(static fn (): string => Uuid::v4()->toRfc4122(), range(1, 50));
        $accepted = $this->request('/api/findings/status?'.http_build_query(['ids' => [...$fifty, $fifty[0]]]));
        self::assertSame(Response::HTTP_OK, $accepted->getStatusCode());
        self::assertSame([], $this->json($accepted)['findings']);
        self::assertSame($fifty, $this->json($accepted)['missingIds']);

        $tooMany = $this->request('/api/findings/status?'.http_build_query([
            'ids' => [...$fifty, Uuid::v4()->toRfc4122()],
        ]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $tooMany->getStatusCode());
        self::assertStringContainsString('50', $this->json($tooMany)['error']);
    }

    private function intakeToken(): string
    {
        $response = $this->request('/');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(1, preg_match(
            '#<form[^>]+action="/findings"[^>]*>(.*?)</form>#s',
            $response->getContent(),
            $form,
        ));
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $form[1], $token));

        return html_entity_decode($token[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['domain', 'finding', 'finding_assessment', 'retest_run', 'screenshot_job', 'evidence'] as $table) {
            $snapshot[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $snapshot;
    }
}
