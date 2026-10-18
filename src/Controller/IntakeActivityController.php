<?php

namespace App\Controller;

use App\Service\IntakeActivityConflict;
use App\Service\IntakeActivityService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

#[Route(path: '/api/intake/activity')]
final class IntakeActivityController
{
    public function __construct(
        private readonly IntakeActivityService $activity,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly UiTranslator $i18n,
    ) {
    }

    #[Route(name: 'api_intake_activity', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        try {
            return $this->snapshot($request);
        } catch (\InvalidArgumentException) {
            return $this->error('Die Auswahl für die Erfassungssession ist ungültig.', 422);
        } catch (\Throwable) {
            return $this->error('Die Erfassungssession konnte nicht gelesen werden.', 500);
        }
    }

    #[Route(name: 'api_intake_activity_change', methods: ['POST'])]
    public function change(Request $request): JsonResponse
    {
        try {
            $parameters = $request->getPayload()->all();
        } catch (JsonException) {
            return $this->error('Der Anfrageinhalt ist kein gültiges JSON-Objekt.', 422);
        }
        try {
            if (!is_string($parameters['_token'] ?? null) || !$this->csrf->isTokenValid(new CsrfToken('finding_create', $parameters['_token']))) {
                return $this->error('Das Formular ist abgelaufen oder ungültig. Bitte lade die Seite neu.', 403);
            }
            // Validate selections before changing anything; a bad query string
            // must not turn a successful mutation into an apparent failure.
            $this->selection($request);
            $action = $parameters['action'] ?? null;
            $sessionId = $parameters['sessionId'] ?? null;
            $expected = $parameters['expectedActiveSessionId'] ?? null;
            $generation = $parameters['expectedSessionGeneration'] ?? null;
            if (!in_array($action, ['end', 'resume'], true) || !is_string($sessionId) || !Uuid::isValid($sessionId)
                || ($expected !== null && (!is_string($expected) || !Uuid::isValid($expected)))
                || !(is_int($generation) && $generation >= 0)
                || ($action === 'resume' && !array_key_exists('expectedActiveSessionId', $parameters))) {
                throw new \InvalidArgumentException();
            }
            $browser = $this->activity->browserId($request);
            if ($browser === null) { throw new IntakeActivityConflict(); }
            $this->activity->change($browser, $action, Uuid::fromString($sessionId)->toRfc4122(), $expected !== null ? Uuid::fromString($expected)->toRfc4122() : null, $generation);
            return $this->snapshot($request);
        } catch (IntakeActivityConflict) {
            return $this->error('Die Erfassungssession hat sich inzwischen geändert. Bitte aktualisiere die Anzeige.', 409);
        } catch (\InvalidArgumentException) {
            return $this->error('Die Auswahl für die Erfassungssession ist ungültig.', 422);
        } catch (\Throwable) {
            return $this->error('Die Änderung der Erfassungssession konnte nicht bestätigt werden. Bitte aktualisiere die Anzeige.', 500);
        }
    }

    private function snapshot(Request $request): JsonResponse
    {
        [$period, $minutes, $history] = $this->selection($request);
        return $this->json($this->activity->snapshot($this->activity->browserId($request), $period, $minutes, $history));
    }

    private function selection(Request $request): array
    {
        $parameters = $request->query->all();
        $period = $parameters['period'] ?? 'session';
        $minutes = $parameters['minutes'] ?? '15';
        $history = $parameters['history'] ?? 'session';
        if (!is_string($period) || !in_array($period, ['session', 'today', 'window'], true)
            || !is_string($history) || !in_array($history, ['session', 'all'], true)
            || !is_string($minutes) || !ctype_digit($minutes) || !in_array((int) $minutes, IntakeActivityService::MINUTES, true)) {
            throw new \InvalidArgumentException();
        }
        return [$period, (int) $minutes, $history];
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['error' => $this->i18n->trans($message), 'errorKey' => $message, 'errorParameters' => []], $status);
    }
}
