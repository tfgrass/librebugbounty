<?php

namespace App\Controller;

use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Service\FindingService;
use App\Service\IntakeStatusService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

#[Route(path: '/api/findings')]
final class FindingIntakeApiController
{
    private const MAX_STATUS_IDS = 50;

    public function __construct(
        private readonly FindingService $findingService,
        private readonly FindingRepository $findings,
        private readonly IntakeStatusService $intakeStatus,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly UiTranslator $i18n,
    ) {
    }

    #[Route(name: 'api_finding_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            // Keep the endpoint usable by both server-rendered forms and the
            // planned second frontend without giving them different semantics.
            $parameters = $request->getPayload()->all();
        } catch (JsonException) {
            return $this->error('Der Anfrageinhalt ist kein gültiges JSON-Objekt.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $token = $parameters['_token'] ?? null;
        try {
            $validToken = is_string($token)
                && $this->csrf->isTokenValid(new CsrfToken('finding_create', $token));
        } catch (\Throwable) {
            return $this->storageError();
        }
        if (!$validToken) {
            return $this->error(
                'Das Formular ist abgelaufen oder ungültig. Bitte lade die Seite neu.',
                Response::HTTP_FORBIDDEN,
            );
        }

        try {
            $url = $this->stringParameter($parameters, 'url', required: true);
            $payload = $this->stringParameter($parameters, 'payload');
            $notes = $this->stringParameter($parameters, 'annotate');
            $url = trim($url);
            $payload = trim($payload);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->findingService->createIntakeFinding(
                url: $url,
                expectedEvidence: $payload !== '' ? $payload : null,
                privateNotes: $notes !== '' ? $notes : null,
            );
            $finding = $result->finding;
            $outcome = $result->created ? 'stored' : 'duplicate';
        } catch (\InvalidArgumentException) {
            return $this->error('Die URL oder ihre Domain ist ungültig.', Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable) {
            return $this->storageError();
        }

        try {
            return $this->json([
                'outcome' => $outcome,
                'finding' => [
                    'id' => $finding->getId(),
                    'url' => $finding->getUrl(),
                    'detailUrl' => '/findings/'.$finding->getId(),
                ],
                'status' => $this->intakeStatus->status($finding),
            ], $outcome === 'stored' ? Response::HTTP_CREATED : Response::HTTP_OK);
        } catch (\Throwable) {
            return $this->storageError();
        }
    }

    #[Route(path: '/status', name: 'api_finding_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        try {
            $ids = $this->statusIds($request);
            if ($ids === []) {
                throw new \InvalidArgumentException('Mindestens eine Finding-ID ist erforderlich.');
            }
            if (count($ids) > self::MAX_STATUS_IDS) {
                return $this->error(
                    'Höchstens {count} Finding-IDs können gleichzeitig gelesen werden.',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['count' => self::MAX_STATUS_IDS],
                );
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $foundById = [];
            foreach ($this->findings->findBy(['id' => $ids]) as $finding) {
                $foundById[$finding->getId()] = $finding;
            }

            $statuses = [];
            $missingIds = [];
            foreach ($ids as $id) {
                $finding = $foundById[$id] ?? null;
                if (!$finding instanceof Finding) {
                    $missingIds[] = $id;
                    continue;
                }
                $statuses[] = $this->intakeStatus->status($finding);
            }

            return $this->json([
                'findings' => $statuses,
                'missingIds' => $missingIds,
            ]);
        } catch (\Throwable) {
            return $this->error(
                'Die Ergebnisstände konnten nicht gelesen werden.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function stringParameter(array $parameters, string $name, bool $required = false): string
    {
        if (!array_key_exists($name, $parameters)) {
            if ($required) {
                throw new \InvalidArgumentException('Ein erforderliches Textfeld fehlt.');
            }

            return '';
        }
        if (!is_string($parameters[$name])) {
            throw new \InvalidArgumentException('Eingabefelder müssen Text enthalten.');
        }

        return $parameters[$name];
    }

    /** @return list<string> */
    private function statusIds(Request $request): array
    {
        $raw = $request->query->all()['ids'] ?? [];
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Die Finding-IDs müssen als Liste übergeben werden.');
        }

        $ids = [];
        foreach ($raw as $id) {
            if (!is_string($id) || !Uuid::isValid($id)) {
                throw new \InvalidArgumentException('Alle Finding-IDs müssen gültige UUIDs sein.');
            }
            $canonical = Uuid::fromString($id)->toRfc4122();
            $ids[$canonical] = true;
        }

        return array_keys($ids);
    }

    private function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, [
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param array<string, int|float|string> $parameters */
    private function error(string $message, int $status, array $parameters = []): JsonResponse
    {
        return $this->json([
            'error' => $this->i18n->trans($message, $parameters),
            'errorKey' => $message,
            'errorParameters' => $parameters,
        ], $status);
    }

    private function storageError(): JsonResponse
    {
        return $this->error(
            'Die Speicherung konnte nicht bestätigt werden. Bitte prüfe den Bestand, bevor du die Eingabe erneut sendest.',
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }
}
