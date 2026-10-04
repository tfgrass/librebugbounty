<?php

namespace App\Controller;

use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Repository\EvidenceRepository;
use App\Repository\RetestRunRepository;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingArtifactCleanupException;
use App\Service\FindingNavigation;
use App\Service\FindingService;
use App\Service\RetestService;
use App\Service\ScreenshotQueueService;
use App\Service\SettingsService;
use App\Service\UiTranslator;
use App\Value\FindingReadLabels;
use App\Value\ScreenshotJobStatus;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route(path: '/')]
final class WebController
{
    public function __construct(
        private readonly FindingService $findingService,
        private readonly RetestService $retestService,
        private readonly SettingsService $settings,
        private readonly EvidenceRepository $evidenceRepository,
        private readonly RetestRunRepository $retestRunRepository,
        private readonly EvidenceStorageInterface $storage,
        private readonly ScreenshotQueueService $screenshotQueue,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly FindingNavigation $navigation,
        private readonly UiTranslator $i18n,
    ) {
    }

    #[Route(path: 'legacy', name: 'legacy_redirect', methods: ['GET'])]
    public function legacy(Request $request): RedirectResponse
    {
        return $this->redirectWithQuery('/findings', $request);
    }

    #[Route(path: 'legacy/findings/{id}', name: 'legacy_finding_redirect', methods: ['GET'])]
    public function legacyFinding(string $id, Request $request): RedirectResponse
    {
        return $this->redirectWithQuery('/findings/'.rawurlencode($id), $request);
    }

    #[Route(path: 'about', name: 'about', methods: ['GET'])]
    public function about(Request $request): RedirectResponse
    {
        $query = $request->getQueryString();

        return new RedirectResponse('/settings'.($query === null ? '' : '?'.$query).'#about', Response::HTTP_PERMANENTLY_REDIRECT);
    }

    #[Route(path: 'findings', name: 'finding_create', methods: ['POST'])]
    public function createFinding(Request $request): Response
    {
        if (!$this->validCsrf($request, 'finding_create')) {
            return new Response($this->i18n->trans('Ungültiges Formular. Bitte neu laden.'), Response::HTTP_FORBIDDEN);
        }

        $parameters = $request->request->all();
        foreach (['url', 'payload', 'annotate', 'surface'] as $field) {
            if (array_key_exists($field, $parameters) && !is_string($parameters[$field])) {
                return new Response($this->i18n->trans('Ungültige Eingabe.'), Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $payload = trim($request->request->getString('payload'));
            $result = $this->findingService->createIntakeFinding(
                url: trim($request->request->getString('url')),
                expectedEvidence: $payload !== '' ? $payload : null,
                privateNotes: $request->request->getString('annotate') ?: null,
            );
            $finding = $result->finding;
            if (!$result->created) {
                return $this->redirectMessage($this->i18n->trans(
                    'URL wurde nicht importiert, weil sie bereits als Fall {id} vorhanden ist.',
                    ['id' => $this->shortId($finding)],
                ), $this->navigation->findingReturnPath($finding->getId(), []));
            }

            return $this->redirectMessage($this->i18n->trans(
                'Fall {id} für {host} gespeichert. Screenshot wurde eingereiht.',
                ['id' => $this->shortId($finding), 'host' => $finding->getDomain()->getHostname()],
            ), $this->navigation->findingReturnPath($finding->getId(), []));
        } catch (\InvalidArgumentException $exception) {
            return $this->redirectError($this->i18n->trans($exception->getMessage()), '/');
        } catch (\Throwable) {
            return $this->redirectError($this->i18n->trans('Die Speicherung konnte nicht bestätigt werden. Bitte prüfe den Bestand, bevor du die Eingabe erneut sendest.'), '/');
        }
    }

    #[Route(path: 'findings/{id}/retest', name: 'finding_retest', methods: ['POST'])]
    public function retestFinding(string $id, Request $request): Response
    {
        $parameters = $request->request->all();
        if (!$this->validNavigationParameters($parameters)) {
            return new Response($this->i18n->trans('Ungültige Angaben für die technische Prüfung.'), Response::HTTP_BAD_REQUEST);
        }
        $returnPath = $this->navigation->findingReturnPath($id, $parameters);

        try {
            $finding = $this->findingService->getFindingOrFail($id);
            if ($finding->isDiscarded()) {
                return new Response($this->i18n->trans('Verworfene Fälle werden im normalen Arbeiten ignoriert.'), Response::HTTP_CONFLICT);
            }
            $run = $this->retestService->retest(
                finding: $finding,
                screenshot: false,
                timeoutMs: $this->settings->getReviewScanTimeoutMs(),
                dryRun: false,
                noStatusUpdate: false,
                headless: true,
            );

            return $this->redirectMessage($this->i18n->trans(
                'Technische Prüfung für {id} abgeschlossen: {result}.',
                ['id' => $this->shortId($finding), 'result' => $this->i18n->trans(FindingReadLabels::observation($run->getResult()))],
            ), $returnPath);
        } catch (\Throwable $exception) {
            return $this->redirectError($this->i18n->trans($exception->getMessage()), $returnPath);
        }
    }

    #[Route(path: 'findings/{id}/screenshots', name: 'finding_screenshot_queue', methods: ['POST'])]
    public function queueScreenshot(string $id, Request $request): Response
    {
        $parameters = $request->request->all();
        if (!$this->validNavigationParameters($parameters)) {
            return new Response($this->i18n->trans('Ungültige Angaben für den Screenshot-Auftrag.'), Response::HTTP_BAD_REQUEST);
        }
        $returnPath = $this->navigation->findingReturnPath($id, $parameters);

        try {
            $finding = $this->findingService->getFindingOrFail($id);
            if ($finding->isDiscarded()) {
                return new Response($this->i18n->trans('Verworfene Fälle werden im normalen Arbeiten ignoriert.'), Response::HTTP_CONFLICT);
            }
            $queued = $this->screenshotQueue->enqueue($finding);

            return $this->redirectMessage($this->i18n->trans(
                'Screenshot für {id} {state}.',
                [
                    'id' => $this->shortId($finding),
                    'state' => $queued->created
                        ? $this->i18n->trans('wurde eingereiht')
                        : $this->i18n->trans('ist bereits {status}', ['status' => $this->i18n->trans(match ($queued->job->getStatus()) {
                            ScreenshotJobStatus::QUEUED => 'Screenshot in Warteschlange',
                            ScreenshotJobStatus::RUNNING => 'Screenshot wird erstellt',
                            ScreenshotJobStatus::AVAILABLE => 'Screenshot verfügbar',
                            ScreenshotJobStatus::FAILED => 'Screenshot fehlgeschlagen',
                            default => 'Screenshot-Status unbekannt',
                        })]),
                ],
            ), $returnPath);
        } catch (\Throwable $exception) {
            return $this->redirectError($this->i18n->trans($exception->getMessage()), $returnPath);
        }
    }

    #[Route(path: 'findings/{id}/assessment', name: 'finding_assessment', methods: ['POST'])]
    public function assessFinding(string $id, Request $request): Response
    {
        if (!$this->validCsrf($request, 'finding_assessment_'.$id)) {
            return new Response($this->i18n->trans('Die Bewertung wurde nicht gespeichert. Formular bitte neu laden.'), Response::HTTP_FORBIDDEN);
        }
        $parameters = $request->request->all();
        foreach (['assessment', 'discard_reason', 'observation_id', 'evidence_id', 'surface', 'return_to'] as $field) {
            if (array_key_exists($field, $parameters) && !is_string($parameters[$field])) {
                return new Response($this->i18n->trans('Ungültige Bewertungsangaben.'), Response::HTTP_BAD_REQUEST);
            }
        }
        $returnPath = $this->navigation->findingReturnPath($id, $parameters);

        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $assessment = $request->request->getString('assessment');
            $reason = trim($request->request->getString('discard_reason')) ?: null;
            if (!in_array($assessment, ['confirmed', 'fixed', 'discarded'], true)
                || !in_array($reason, [null, 'duplicate'], true)
            ) {
                return new Response($this->i18n->trans('Ungültige Bewertung oder ungültiger Verwerfungsgrund.'), Response::HTTP_BAD_REQUEST);
            }

            // References are explicit user choices. An empty field must never
            // imply that the newest technical result was reviewed.
            $observationId = trim($request->request->getString('observation_id')) ?: null;
            $evidenceId = trim($request->request->getString('evidence_id')) ?: null;
            if ($observationId !== null) {
                $observation = $this->retestRunRepository->find($observationId);
                if (!$observation instanceof RetestRun || $observation->getFinding()->getId() !== $finding->getId()) {
                    return new Response($this->i18n->trans('Die gewählte Beobachtung gehört nicht zu diesem Fall.'), Response::HTTP_BAD_REQUEST);
                }
            }
            if ($evidenceId !== null) {
                $evidence = $this->evidenceRepository->find($evidenceId);
                if (!$evidence instanceof Evidence || $evidence->getFinding()->getId() !== $finding->getId()) {
                    return new Response($this->i18n->trans('Der gewählte Beleg gehört nicht zu diesem Fall.'), Response::HTTP_BAD_REQUEST);
                }
            }

            $this->findingService->assess(
                $finding,
                $assessment,
                $assessment === 'discarded' ? $reason : null,
                $observationId,
                $evidenceId,
            );

            return $this->redirectMessage($this->i18n->trans('Bewertung gespeichert: {assessment}.', ['assessment' => $this->i18n->trans(FindingReadLabels::assessment($assessment, $assessment === 'discarded' ? $reason : null))]), $returnPath);
        } catch (\InvalidArgumentException $exception) {
            return new Response($this->i18n->trans($exception->getMessage()), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {
            return $this->redirectError($this->i18n->trans($exception->getMessage()), $returnPath);
        }
    }

    #[Route(path: 'findings/{id}/notes', name: 'finding_notes', methods: ['POST'])]
    public function updateFindingNotes(string $id, Request $request): Response
    {
        if (!$this->validCsrf($request, 'finding_notes_'.$id)) {
            return new Response($this->i18n->trans('Die Notiz wurde nicht gespeichert. Formular bitte neu laden.'), Response::HTTP_FORBIDDEN);
        }
        $parameters = $request->request->all();
        if (!is_string($parameters['notes'] ?? null) || !$this->validNavigationParameters($parameters)) {
            return new Response($this->i18n->trans('Ungültige Notizangaben.'), Response::HTTP_BAD_REQUEST);
        }
        $returnPath = $this->navigation->findingReturnPath($id, $parameters);
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $this->findingService->updateNotes($finding, $parameters['notes']);

            return $this->redirectMessage($this->i18n->trans('Notiz gespeichert.'), $returnPath);
        } catch (\Throwable $exception) {
            return $this->redirectError($this->i18n->trans($exception->getMessage()), $returnPath);
        }
    }

    #[Route(path: 'findings/{id}/mark-contacted', name: 'finding_mark_contacted', methods: ['POST'])]
    public function markContacted(string $id, Request $request): Response
    {
        if (!$this->validCsrf($request, 'finding_mark_contacted_'.$id)) {
            return new Response($this->i18n->trans('Ungültiges Formular. Bitte neu laden.'), Response::HTTP_FORBIDDEN);
        }
        $parameters = $request->request->all();
        if (!$this->validNavigationParameters($parameters)) {
            return new Response($this->i18n->trans('Ungültige Kontaktangaben.'), Response::HTTP_BAD_REQUEST);
        }
        $returnPath = $this->navigation->findingReturnPath($id, $parameters);
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $this->findingService->markContacted($finding);

            return $this->redirectMessage($this->i18n->trans('Kontaktzeitpunkt für {id} gespeichert.', ['id' => $this->shortId($finding)]), $returnPath);
        } catch (\Throwable $exception) {
            return $this->redirectError($this->i18n->trans($exception->getMessage()), $returnPath);
        }
    }

    #[Route(path: 'findings/{id}/mark-sent', name: 'finding_mark_sent', methods: ['POST'])]
    public function markSent(string $id, Request $request): Response
    {
        if (!$this->validCsrf($request, 'finding_mark_sent_'.$id)) {
            return new Response($this->i18n->trans('Ungültiges Formular. Bitte neu laden.'), Response::HTTP_FORBIDDEN);
        }
        $parameters = $request->request->all();
        if (!$this->validNavigationParameters($parameters)) {
            return new Response($this->i18n->trans('Ungültige Versandangaben.'), Response::HTTP_BAD_REQUEST);
        }
        $returnPath = $this->navigation->findingReturnPath($id, $parameters);
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $this->findingService->markSent($finding);

            return $this->redirectMessage($this->i18n->trans('Versandzeitpunkt für {id} gespeichert.', ['id' => $this->shortId($finding)]), $returnPath);
        } catch (\Throwable $exception) {
            return $this->redirectError($this->i18n->trans($exception->getMessage()), $returnPath);
        }
    }

    #[Route(path: 'findings/{id}/delete', name: 'finding_delete', methods: ['POST'])]
    public function deleteFinding(string $id, Request $request): Response
    {
        $parameters = $request->request->all();
        foreach (['surface', 'return_to', 'confirm_delete'] as $field) {
            if (array_key_exists($field, $parameters) && !is_string($parameters[$field])) {
                return new Response($this->i18n->trans('Ungültige Löschangaben.'), Response::HTTP_BAD_REQUEST);
            }
        }
        $failurePath = $this->navigation->findingReturnPath($id, $parameters);
        if (($parameters['confirm_delete'] ?? null) !== '1') {
            return $this->redirectError($this->i18n->trans('Bitte bestätige das endgültige Löschen dieses Falls und seiner Belege.'), $failurePath);
        }
        $successPath = $this->navigation->returnPathAfterDeletion($id, $parameters['return_to'] ?? null) ?? '/findings';
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $hostname = $finding->getDomain()->getHostname();
            $shortId = $this->shortId($finding);
            $this->findingService->deleteFinding($finding);

            return $this->redirectMessage($this->i18n->trans('Fall {id} von {host} endgültig gelöscht.', ['id' => $shortId, 'host' => $hostname]), $successPath);
        } catch (FindingArtifactCleanupException $exception) {
            return $this->redirectError($this->i18n->trans($exception->getMessage()), $successPath);
        } catch (\Throwable $exception) {
            return $this->redirectError($this->i18n->trans($exception->getMessage()), $failurePath);
        }
    }

    #[Route(path: 'artifacts/{path}', name: 'artifact_show', methods: ['GET'], requirements: ['path' => '.+'])]
    public function showArtifact(string $path): Response
    {
        $normalizedPath = ltrim(str_replace('\\', '/', $path), '/');
        if (preg_match('#(^|/)\.\.(?:/|$)#', $normalizedPath)
            || str_starts_with($normalizedPath, 'storage/artifacts/')
        ) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }

        try {
            $contents = $this->storage->read($normalizedPath);
        } catch (\InvalidArgumentException|\RuntimeException) {
            return new Response('Evidence file is missing or unavailable.', Response::HTTP_NOT_FOUND);
        }

        $mimeType = 'application/octet-stream';
        if (preg_match('/\.(png|jpe?g|gif|webp)$/i', $normalizedPath)) {
            $mimeType = match (strtolower(pathinfo($normalizedPath, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => 'application/octet-stream',
            };
        }

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.basename($normalizedPath).'"',
            'Cache-Control' => 'private, max-age=0, no-cache',
        ]);
    }

    /** @param array<string, mixed> $parameters */
    private function validNavigationParameters(array $parameters): bool
    {
        foreach (['surface', 'return_to'] as $field) {
            if (array_key_exists($field, $parameters) && !is_string($parameters[$field])) {
                return false;
            }
        }

        return true;
    }

    private function validCsrf(Request $request, string $tokenId): bool
    {
        $token = $request->request->all()['_token'] ?? null;

        return is_string($token) && $this->csrf->isTokenValid(new CsrfToken($tokenId, $token));
    }

    private function redirectMessage(string $message, string $path): RedirectResponse
    {
        return new RedirectResponse($path.(str_contains($path, '?') ? '&' : '?').'message='.rawurlencode($message));
    }

    private function redirectError(string $error, string $path): RedirectResponse
    {
        return new RedirectResponse($path.(str_contains($path, '?') ? '&' : '?').'error='.rawurlencode($error));
    }

    private function redirectWithQuery(string $path, Request $request): RedirectResponse
    {
        $query = $request->getQueryString();

        return new RedirectResponse($path.($query === null ? '' : '?'.$query), Response::HTTP_PERMANENTLY_REDIRECT);
    }

    private function shortId(Finding $finding): string
    {
        return substr($finding->getId(), 0, 8);
    }
}
