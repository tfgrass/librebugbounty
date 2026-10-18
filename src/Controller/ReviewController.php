<?php

namespace App\Controller;

use App\Dto\FindingDetailView;
use App\Dto\ReviewQueueView;
use App\Entity\Finding;
use App\Exception\InteractiveCaptureInvalidatedException;
use App\Repository\FindingRepository;
use App\Service\BrowserInteractiveClientInterface;
use App\Service\FindingDetailService;
use App\Service\InteractiveCaptureService;
use App\Service\ReviewQueueService;
use App\Service\ReviewDispositionService;
use App\Service\ReviewTrail;
use App\Service\SettingsService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface as WorkerHttpException;

final class ReviewController
{
    private const INTERACTIVE_SESSION_PREFIX = 'review_interactive_';
    private const INTERACTIVE_CLEANUP_KEY = 'review_browser_cleanup_pending';

    public function __construct(
        private readonly ReviewQueueService $queue,
        private readonly FindingRepository $findings,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly UiTranslator $i18n,
        private readonly ReviewTrail $trails,
        private readonly SettingsService $settings,
        private readonly \App\Service\FindingWorkPolicy $workPolicy,
        private readonly InteractiveCaptureService $interactiveCapture,
        private readonly BrowserInteractiveClientInterface $browserInteractive,
        private readonly FindingDetailService $details,
        private readonly ReviewDispositionService $dispositions,
    ) {
    }

    #[Route(path: '/review', name: 'studio_review', methods: ['GET'])]
    public function review(Request $request): Response
    {
        try {
            $query = $request->query->all();
            if ((isset($query['trail']) && !is_string($query['trail'])) || (isset($query['card']) && !is_string($query['card']))) {
                throw new \InvalidArgumentException('Ungültiger Review-Durchlauf.');
            }
            $trail = $this->trails->open($request->getSession(), $query['trail'] ?? null);
            $request->attributes->set('_review_trail', $trail);
            $query['trail'] = $trail['id'];
            $card = $query['card'] ?? null;
            if ($card !== null && (!($trail['current']['explicit'] ?? false) || ($trail['current']['id'] ?? null) !== $card)) {
                throw new \InvalidArgumentException('Diese Karte gehört nicht zum aktuellen Review-Durchlauf.');
            }
            if ($card !== null && !$this->queue->exists($card)) {
                $card = null;
                unset($query['card']);
            }
            $after = $query['after'] ?? null;
            if (is_string($after) && Uuid::isValid($after) && !$this->queue->exists($after)) {
                // A deleted cursor must not make reloading a still-existing
                // displayed card fail, and a GET must not reset any finding.
                $current = $trail['current'];
                if ($current !== null && $this->queue->exists($current['id'])) {
                    $current['explicit'] = true;
                    $trail = $this->trails->displayed($request->getSession(), $trail, $current);
                    $request->attributes->set('_review_trail', $trail);
                    $card = $current['id'];
                    $query['card'] = $card;
                    $query['evidence'] ??= $current['evidence'];
                } elseif ($current !== null || $trail['entries'] !== []) {
                    unset($query['after']);
                }
            }
            $view = $this->queue->get($query, $card, $card !== null);
        } catch (\InvalidArgumentException $exception) {
            return $this->plain($this->i18n->trans($exception->getMessage()), Response::HTTP_BAD_REQUEST);
        } catch (\UnexpectedValueException $exception) {
            return $this->plain($this->i18n->trans($exception->getMessage()), Response::HTTP_CONFLICT);
        }
        $query = $request->query->all();
        $message = is_string($query['message'] ?? null) ? $query['message'] : null;

        return $this->render($request, $view, $message);
    }

    #[Route(path: '/review/{id}/assessment', name: 'studio_review_assessment', methods: ['POST'])]
    public function assess(string $id, Request $request): Response
    {
        if (!Uuid::isValid($id) || !$this->findings->find($id) instanceof Finding) {
            return $this->plain($this->i18n->trans('Fall nicht gefunden.'), Response::HTTP_NOT_FOUND);
        }
        $parameters = $request->request->all();
        $submitted = [];
        $malformed = false;
        foreach (['assessment', 'discard_reason', 'observation_id', 'evidence_id', 'displayed_evidence_id', 'kind', 'images', 'after', '_token', 'context_token', 'trail_id', 'trail_version'] as $field) {
            if (array_key_exists($field, $parameters) && !is_string($parameters[$field])) {
                $malformed = true;
            }
            $submitted[$field] = is_string($parameters[$field] ?? null) ? $parameters[$field] : '';
        }
        $query = array_intersect_key($parameters, array_flip(['kind', 'images', 'after']));
        $query['evidence'] = $submitted['displayed_evidence_id'];
        $trail = null;
        $trailError = null;
        try {
            $trail = $this->trails->check($request->getSession(), $submitted['trail_id'], $submitted['trail_version'], $id);
            $query['trail'] = $trail['id'];
            if ($trail['current']['explicit'] ?? false) { $query['card'] = $id; }
            $request->attributes->set('_review_trail', $trail);
        } catch (\UnexpectedValueException $exception) {
            $trailError = $exception->getMessage();
        }
        try {
            $view = $this->queue->get($query, $id, $trail['current']['explicit'] ?? false);
        } catch (\InvalidArgumentException $exception) {
            return $this->render($request, $this->queue->get([], $id), null, $this->i18n->trans($exception->getMessage()), $submitted, Response::HTTP_BAD_REQUEST);
        }
        if ($malformed) {
            return $this->render($request, $view, null, $this->i18n->trans('Ungültige Bewertungsangaben.'), $submitted, Response::HTTP_BAD_REQUEST);
        }
        if (!$this->csrf->isTokenValid(new CsrfToken('review_assessment_'.$id, $submitted['_token']))) {
            return $this->render($request, $view, null, $this->i18n->trans('Die Bewertung wurde nicht gespeichert. Formular bitte neu laden.'), $submitted, Response::HTTP_FORBIDDEN);
        }
        if ($trailError !== null) {
            return $this->render($request, $view, null, $this->i18n->trans($trailError), $submitted, Response::HTTP_CONFLICT);
        }
        // Keep a complete, unmodified current card available even if a failed
        // ORM flush closes the entity manager. Never redirect on write failure.
        $failureResponse = $this->render($request, $view, null, $this->i18n->trans('Die Bewertung konnte nicht gespeichert werden. Bitte den Fall neu laden und erneut prüfen.'), $submitted, Response::HTTP_INTERNAL_SERVER_ERROR);
        try {
            $committedFingerprint = $this->queue->assess(
                $id,
                $submitted['assessment'],
                trim($submitted['discard_reason']) ?: null,
                trim($submitted['observation_id']) ?: null,
                trim($submitted['evidence_id']) ?: null,
                $submitted['context_token'],
                $trail['current']['explicit'] ?? false,
            );
        } catch (\UnexpectedValueException $exception) {
            return $this->render($request, $this->queue->get($query, $id, $trail['current']['explicit'] ?? false), null, $this->i18n->trans($exception->getMessage()), $submitted, Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $exception) {
            return $this->render($request, $view, null, $this->i18n->trans($exception->getMessage()), $submitted, Response::HTTP_BAD_REQUEST);
        } catch (\Throwable) {
            return $failureResponse;
        }

        $message = match ($submitted['assessment']) {
            'confirmed' => $this->i18n->trans('Vulnerable bestätigt.'),
            'fixed' => $this->i18n->trans('Not vulnerable bestätigt.'),
            'discarded' => $this->i18n->trans('Fall verworfen.'),
            'keep' => $this->i18n->trans('Hinweis geprüft. Bewertung beibehalten.'),
        };
        $card = $this->card($view, $trail['current']['explicit'] ?? false);
        $card['fingerprint'] = $committedFingerprint;
        $this->trails->forward($request->getSession(), $trail, $card);
        $nextPath = $this->queue->path($view->kind, $view->images, $id, null, $trail['id']);

        return new RedirectResponse($nextPath.(str_contains($nextPath, '?') ? '&' : '?').'message='.rawurlencode($message).'&reviewed='.rawurlencode($id), Response::HTTP_SEE_OTHER, ['Cache-Control' => 'no-store']);
    }

    #[Route(path: '/review/{id}/skip', name: 'studio_review_skip', methods: ['POST'])]
    public function skip(string $id, Request $request): Response
    {
        try {
            $parameters = $this->navigationParameters($request);
            $trail = $this->trails->check($request->getSession(), $parameters['trail_id'], $parameters['trail_version'], $id);
            $request->attributes->set('_review_trail', $trail);
            if (!$this->csrf->isTokenValid(new CsrfToken('review_skip_'.$trail['id'].'_'.$trail['version'], $parameters['_token']))) {
                return $this->navigationFailure($request, $trail, 'Überspringen wurde nicht ausgeführt. Formular bitte neu laden.', Response::HTTP_FORBIDDEN);
            }
            $card = $trail['current'];
            [$fingerprint, $token] = array_pad(explode(':', $parameters['context_token'], 2), 2, '');
            if (!hash_equals($this->queue->fingerprint($id), $fingerprint)
                || !$this->csrf->isTokenValid(new CsrfToken('review_context_'.$id.'_'.$fingerprint, $token))) {
                throw new \UnexpectedValueException('Der Fall oder seine letzte Beobachtung hat sich geändert. Bitte die angezeigten Daten erneut prüfen.');
            }
            // Resolve the submitted image through the same ownership-aware card
            // projection; a foreign image cannot become the return destination.
            $view = $this->queue->get(array_replace($this->cardQuery($card, $trail['id']), ['evidence' => $parameters['displayed_evidence_id'] ?: $card['evidence']]), $id, $card['explicit']);
            $card = $this->card($view, $card['explicit']);
            // Retain the state the user actually saw; recording the skip must
            // never upgrade reset permission to a later concurrent state.
            $card['fingerprint'] = $fingerprint;
            $prompt = $this->dispositions->recordSkip($id, $parameters['context_token'], $trail['id'].':'.$trail['version']);
            if ($prompt) {
                $request->getSession()->set('review_recovery_prompt', ['id' => $id, 'fingerprint' => $fingerprint]);
            }
            $this->trails->forward($request->getSession(), $trail, $card);
            $path = $this->queue->path($view->kind, $view->images, $id, null, $trail['id']);
            return new RedirectResponse($path.($prompt ? '&prompt='.rawurlencode($id) : ''), Response::HTTP_SEE_OTHER, ['Cache-Control' => 'no-store']);
        } catch (\InvalidArgumentException $exception) {
            return $this->plain($this->i18n->trans($exception->getMessage()), Response::HTTP_BAD_REQUEST);
        } catch (\UnexpectedValueException $exception) {
            return isset($trail) ? $this->navigationFailure($request, $trail, $exception->getMessage(), Response::HTTP_CONFLICT)
                : $this->plain($this->i18n->trans($exception->getMessage()), Response::HTTP_CONFLICT);
        }
    }

    #[Route(path: '/review/{id}/defer', name: 'studio_review_defer', methods: ['POST'])]
    public function defer(string $id, Request $request): Response
    {
        return $this->changeDisposition($id, $request, false);
    }

    #[Route(path: '/review/{id}/resume', name: 'studio_review_resume', methods: ['POST'])]
    public function resume(string $id, Request $request): Response
    {
        return $this->changeDisposition($id, $request, true);
    }

    private function changeDisposition(string $id, Request $request, bool $resume): Response
    {
        if (!Uuid::isValid($id) || !$this->findings->find($id) instanceof Finding) {
            return $this->plain($this->i18n->trans('Fall nicht gefunden.'), Response::HTTP_NOT_FOUND);
        }
        $trail = null;
        try {
            $parameters = $request->request->all();
            foreach (['_token', 'context_token', 'trail_id', 'trail_version', 'reason', 'note', 'schedule', 'due_date'] as $field) {
                if (isset($parameters[$field]) && !is_string($parameters[$field])) {
                    throw new \InvalidArgumentException('Ungültige Angaben zur Zurückstellung.');
                }
            }
            if (!$this->csrf->isTokenValid(new CsrfToken('review_'.($resume ? 'resume_' : 'defer_').$id, $parameters['_token'] ?? ''))) {
                return $this->plain($this->i18n->trans('Die Änderung wurde nicht gespeichert. Formular bitte neu laden.'), Response::HTTP_FORBIDDEN);
            }
            if (($parameters['trail_id'] ?? '') !== '') {
                $trail = $this->trails->check($request->getSession(), $parameters['trail_id'], $parameters['trail_version'] ?? '', $id);
                $request->attributes->set('_review_trail', $trail);
            }
            $view = $trail === null ? null : $this->queue->get($this->cardQuery($trail['current'], $trail['id']), $id, $trail['current']['explicit']);
            if ($resume) {
                $this->dispositions->resume($id, $parameters['context_token'] ?? '');
            } else {
                $zone = new \DateTimeZone('Europe/Berlin');
                $schedule = $parameters['schedule'] ?? 'manual';
                $due = match ($schedule) {
                    'manual' => null,
                    'tomorrow' => new \DateTimeImmutable('tomorrow 09:00', $zone),
                    'date' => $this->recoveryDueDate($parameters['due_date'] ?? '', $zone),
                    default => throw new \InvalidArgumentException('Ungültiger Termin für die Zurückstellung.'),
                };
                $this->dispositions->defer($id, $parameters['reason'] ?? '', trim($parameters['note'] ?? ''), $due, $parameters['context_token'] ?? '');
            }
            $message = $this->i18n->trans($resume ? 'Fall wieder in die Sichtung aufgenommen.' : 'Fall zurückgestellt. Bewertung und Belege bleiben erhalten.');
            if ($trail !== null && $view !== null) {
                $card = $this->card($view, $trail['current']['explicit']);
                // Deferral changes the fingerprint. Back may revisit the case,
                // but must never restore reset permission for the older state.
                $card['fingerprint'] = $this->queue->fingerprint($id);
                $this->trails->forward($request->getSession(), $trail, $card);
                $path = $this->queue->path($view->kind, $view->images, $id, null, $trail['id']);
            } else {
                $path = '/findings/'.$id;
            }
            return new RedirectResponse($path.(str_contains($path, '?') ? '&' : '?').'message='.rawurlencode($message), Response::HTTP_SEE_OTHER, ['Cache-Control' => 'no-store']);
        } catch (\InvalidArgumentException $error) {
            return $this->plain($this->i18n->trans($error->getMessage()), Response::HTTP_BAD_REQUEST);
        } catch (\UnexpectedValueException $error) {
            return $trail === null ? $this->plain($this->i18n->trans($error->getMessage()), Response::HTTP_CONFLICT)
                : $this->navigationFailure($request, $trail, $error->getMessage(), Response::HTTP_CONFLICT);
        } catch (\Throwable) {
            return $this->plain($this->i18n->trans('Die Änderung konnte nicht gespeichert werden. Bitte neu laden und erneut versuchen.'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function recoveryDueDate(string $date, \DateTimeZone $zone): \DateTimeImmutable
    {
        $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if ($due === false || $due->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Ungültiger Termin für die Zurückstellung.');
        }
        return $due->setTime(9, 0);
    }

    #[Route(path: '/review/{id}/interactive-session/start', name: 'studio_review_interactive_start', methods: ['POST'])]
    public function startInteractive(string $id, Request $request): Response
    {
        return $this->interactiveOperation($id, $request, function (FindingDetailView $detail) use ($id, $request): Response {
            $parameters = $request->request->all();
            if (isset($parameters['return_query']) && !is_string($parameters['return_query'])) {
                throw new HttpException(400, 'Ungültige Review-Auswahl.');
            }
            $query = $this->safeReturnQuery($parameters['return_query'] ?? '');
            try {
                $trail = $this->trails->open($request->getSession(), $query['trail'] ?? null);
            } catch (\UnexpectedValueException $error) {
                throw new HttpException(409, 'Dieser Review-Durchlauf ist abgelaufen. Bitte Review neu öffnen.', $error);
            }
            if (isset($query['trail']) && ($trail['current']['id'] ?? null) !== $id) {
                throw new HttpException(409, 'Diese Karte gehört nicht zum aktuellen Review-Durchlauf.');
            }
            if (isset($query['card']) && $query['card'] !== $id) {
                throw new HttpException(400, 'Ungültige Review-Auswahl.');
            }
            $evidenceId = $query['evidence'] ?? $trail['current']['evidence'] ?? null;
            $binding = $this->interactiveBinding($detail, $evidenceId);
            $query['evidence'] = $binding['trigger_evidence_id'];
            $query['trail'] = $trail['id'];
            $query['card'] = $id;

            // Production uses Symfony's native PHP session handler (handler_id:
            // null) and never saves the session early. Its per-session file lock
            // serializes concurrent POSTs, including two saves of this record.
            // A different finding needs an explicit takeover flow, not an
            // implicit close while opening a new worker page.
            foreach (array_keys($request->getSession()->all()) as $key) {
                if (str_starts_with($key, self::INTERACTIVE_SESSION_PREFIX)) {
                    $existingId = substr($key, strlen(self::INTERACTIVE_SESSION_PREFIX));
                    $existing = $request->getSession()->get($key);
                    if ($existingId !== $id && is_array($existing) && is_int($existing['expires_at'] ?? null)
                        && $existing['expires_at'] > time()) {
                        throw new HttpException(409, 'Eine andere interaktive Browser-Session ist bereits aktiv. Bitte diese zuerst beenden.');
                    }
                }
            }
            $this->closePendingInteractiveSessions($request);
            foreach (array_keys($request->getSession()->all()) as $key) {
                if (str_starts_with($key, self::INTERACTIVE_SESSION_PREFIX)) {
                    $this->closeExistingInteractiveSession($request, substr($key, strlen(self::INTERACTIVE_SESSION_PREFIX)), true);
                }
            }
            $session = $this->interactiveCapture->start($detail->finding);
            $request->getSession()->set(self::INTERACTIVE_SESSION_PREFIX.$id, $binding + [
                'session_id' => $session->id,
                'expires_at' => $session->expiresAt->getTimestamp(),
                'created_at' => $session->createdAt->format('Y-m-d\TH:i:s.vP'),
                'manual_action_count' => 0,
                'return_query' => $query,
                'initial_challenge' => $session->initialChallenge,
                'initial_http_status' => $session->initialHttpStatus,
            ]);
            if (preg_match('/^[a-f0-9]{48}$/D', $session->id) !== 1 || $session->expiresAt->getTimestamp() <= time()) {
                throw new InteractiveCaptureInvalidatedException('Invalid worker session.');
            }
            $view = $this->queue->get($query, $id, true);
            $this->trails->displayed($request->getSession(), $trail, $this->card($view, true));

            return new RedirectResponse('/review/'.rawurlencode($id).'/interactive-session', Response::HTTP_SEE_OTHER, ['Cache-Control' => 'no-store']);
        }, true);
    }

    #[Route(path: '/review/{id}/interactive-session', name: 'studio_review_interactive_show', methods: ['GET'])]
    public function showInteractive(string $id, Request $request): Response
    {
        return $this->interactiveOperation($id, $request, function (FindingDetailView $detail, array $record): Response {
            $status = $this->browserInteractive->interactiveStatus($record['session_id']);
            if ($status->id !== $record['session_id'] || $status->expiresAt->getTimestamp() !== $record['expires_at'] || $status->expiresAt->getTimestamp() <= time()) {
                throw new InteractiveCaptureInvalidatedException('Invalid worker session.');
            }

            return $this->renderInteractive($detail->finding, $status, $record['return_query'], (int) ($record['manual_action_count'] ?? 0));
        });
    }

    #[Route(path: '/review/{id}/interactive-session/frame', name: 'studio_review_interactive_frame', methods: ['GET'])]
    public function interactiveFrame(string $id, Request $request): Response
    {
        return $this->interactiveOperation($id, $request, function (FindingDetailView $detail, array $record): Response {
            $image = $this->browserInteractive->interactiveFrame($record['session_id']);

            return new Response($image, Response::HTTP_OK, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($image),
                'Cache-Control' => 'no-store',
            ]);
        });
    }

    #[Route(path: '/review/{id}/interactive-session/input', name: 'studio_review_interactive_input', methods: ['POST'])]
    public function interactiveInput(string $id, Request $request): Response
    {
        return $this->interactiveOperation($id, $request, function (FindingDetailView $detail, array $record) use ($id, $request): Response {
            try {
                $event = $this->interactiveEvent($request);
            } catch (\InvalidArgumentException|\Symfony\Component\HttpFoundation\Exception\BadRequestException $error) {
                throw new HttpException(400, 'Nicht unterstützte Aktion für die interaktive Aufnahme.', $error);
            }
            $status = $this->browserInteractive->interactiveInput($record['session_id'], $event);
            if ($status->id !== $record['session_id'] || $status->expiresAt->getTimestamp() !== $record['expires_at'] || $status->expiresAt->getTimestamp() <= time()) {
                throw new InteractiveCaptureInvalidatedException('Invalid worker session.');
            }
            $record['manual_action_count'] = max(0, (int) ($record['manual_action_count'] ?? 0)) + 1;
            $request->getSession()->set(self::INTERACTIVE_SESSION_PREFIX.$id, $record);

            return $this->interactiveRedirect($id);
        });
    }

    #[Route(path: '/review/{id}/interactive-session/save', name: 'studio_review_interactive_save', methods: ['POST'])]
    public function saveInteractive(string $id, Request $request): Response
    {
        return $this->interactiveOperation($id, $request, function (FindingDetailView $detail, array $record) use ($id, $request): Response {
            $saved = $this->interactiveCapture->save($detail->finding, $record['session_id'], $record);
            // Persistence succeeded. A cleanup failure must not invite another
            // save of the same evidence; the worker TTL is the final boundary.
            if (!$saved->sessionClosed) {
                $this->closeExistingInteractiveSession($request, $id);
            } else {
                $request->getSession()->remove(self::INTERACTIVE_SESSION_PREFIX.$id);
            }
            $query = $record['return_query'];
            $query['evidence'] = $saved->evidence->getId();
            $query = $this->interactiveReturnQuery($request, $id, $query);
            $query['message'] = $this->i18n->trans('Interaktive Aufnahme gespeichert. Wähle dieses Bild ausdrücklich als Bewertungsgrundlage.');

            return new RedirectResponse('/review?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), Response::HTTP_SEE_OTHER, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route(path: '/review/{id}/interactive-session/cancel', name: 'studio_review_interactive_cancel', methods: ['POST'])]
    public function cancelInteractive(string $id, Request $request): Response
    {
        return $this->interactiveOperation($id, $request, function (FindingDetailView $detail, array $record) use ($id, $request): Response {
            $query = $this->interactiveReturnQuery($request, $id, $record['return_query']);
            $this->closeExistingInteractiveSession($request, $id, true);
            $query['message'] = $this->i18n->trans('Interaktive Browser-Session beendet, ohne eine neue Aufnahme zu speichern.');

            return new RedirectResponse('/review?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), Response::HTTP_SEE_OTHER, ['Cache-Control' => 'no-store']);
        });
    }

    #[Route(path: '/review/back', name: 'studio_review_back', methods: ['POST'])]
    public function back(Request $request): Response
    {
        try {
            $parameters = $this->navigationParameters($request);
            $trail = $this->trails->check($request->getSession(), $parameters['trail_id'], $parameters['trail_version']);
            $request->attributes->set('_review_trail', $trail);
            if (!$this->csrf->isTokenValid(new CsrfToken('review_back_'.$trail['id'].'_'.$trail['version'], $parameters['_token']))) {
                return $this->navigationFailure($request, $trail, 'Zurück wurde nicht ausgeführt. Formular bitte neu laden.', Response::HTTP_FORBIDDEN);
            }
            $card = $trail['entries'] === [] ? null : $trail['entries'][array_key_last($trail['entries'])];
            if ($card === null) { throw new \UnexpectedValueException('In diesem Durchlauf gibt es noch keinen vorherigen Fall.'); }
            if (!$this->queue->exists($card['id'])) {
                // Consume exactly the missing step. A single Back request must
                // never cascade into resetting another, unseen older finding.
                $trail = $this->trails->removeMissingPrevious($request->getSession(), $trail);
                $current = $trail['current'];
                if ($current !== null && $this->queue->exists($current['id'])) {
                    $current['explicit'] = true;
                    $this->trails->displayed($request->getSession(), $trail, $current);
                    $path = $this->queue->path($current['kind'], $current['images'], $current['after'], $current['evidence'], $trail['id'], $current['id']);
                } else {
                    $path = $this->queue->path($card['kind'], $card['images'], null, null, $trail['id']);
                }
                $message = $this->i18n->trans('Der vorherige Fall wurde gelöscht. Dieser Verlaufsschritt wurde entfernt.');
                return new RedirectResponse($path.(str_contains($path, '?') ? '&' : '?').'message='.rawurlencode($message), Response::HTTP_SEE_OTHER, ['Cache-Control' => 'no-store']);
            }
            $card['fingerprint'] = $this->queue->resetForReview($card['id'], $card['fingerprint'], $card['evidence']);
            $card['explicit'] = true;
            $this->trails->back($request->getSession(), $trail, $card);
            $path = $this->queue->path($card['kind'], $card['images'], $card['after'], $card['evidence'], $trail['id'], $card['id']);
            return new RedirectResponse($path, Response::HTTP_SEE_OTHER, ['Cache-Control' => 'no-store']);
        } catch (\InvalidArgumentException $exception) {
            return $this->plain($this->i18n->trans($exception->getMessage()), Response::HTTP_BAD_REQUEST);
        } catch (\UnexpectedValueException $exception) {
            return isset($trail) ? $this->navigationFailure($request, $trail, $exception->getMessage(), Response::HTTP_CONFLICT)
                : $this->plain($this->i18n->trans($exception->getMessage()), Response::HTTP_CONFLICT);
        } catch (\Throwable) {
            return $this->plain($this->i18n->trans('Die Bewertung konnte nicht zurückgesetzt werden. Bitte Review neu laden.'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** All worker access is behind the same browser-session and case checks. */
    private function interactiveOperation(string $id, Request $request, callable $operation, bool $start = false): Response
    {
        try {
            // GET show/frame are read-only, session-bound routes. Every POST,
            // including cancel and a replacement start, requires a CSRF token.
            if ($request->isMethod('POST')) {
                $parameters = $request->request->all();
                if (!is_string($parameters['_token'] ?? null)
                    || !$this->csrf->isTokenValid(new CsrfToken('review_interactive_'.$id, $parameters['_token']))) {
                    throw new HttpException(403, 'Ungültiges Formular für die interaktive Aufnahme.');
                }
            }
            try {
                $finding = Uuid::isValid($id) ? $this->findings->findOneBy(['id' => $id]) : null;
                if (!$finding instanceof Finding) {
                    throw new HttpException(404, 'Fall nicht gefunden.');
                }
                // Re-read persisted state even in a long-lived/reused kernel.
                $detail = $this->details->get($id, true);
                if ($detail->finding->isDiscarded() || !$this->workPolicy->checksAllowed($detail->finding)) {
                    throw new HttpException(403, 'Weitere Prüfungen sind für diesen Fall gesperrt oder die Nachverfolgung ist beendet.');
                }
                if ($start) {
                    return $operation($detail);
                }
                $record = $request->getSession()->get(self::INTERACTIVE_SESSION_PREFIX.$id);
                if (!is_array($record)
                    || !is_string($record['session_id'] ?? null)
                    || preg_match('/^[a-f0-9]{48}$/D', $record['session_id']) !== 1
                    || !is_int($record['expires_at'] ?? null)
                    || $record['expires_at'] <= time()
                    || !is_array($record['return_query'] ?? null)
                    || !is_string($record['trigger_evidence_id'] ?? null)) {
                    throw new HttpException(410, 'Die interaktive Browser-Session ist nicht mehr vorhanden. Bitte im Review neu starten.');
                }
                $binding = $this->interactiveBinding($detail, $record['trigger_evidence_id']);
                foreach ($binding as $key => $value) {
                    if (($record[$key] ?? null) !== $value) {
                        throw new HttpException(409, 'Der Fall oder die auslösende Aufnahme hat sich geändert. Bitte im Review neu starten.');
                    }
                }
            } catch (HttpExceptionInterface $error) {
                $this->closeExistingInteractiveSession($request, $id);
                throw $error;
            }

            return $operation($detail, $record);
        } catch (HttpExceptionInterface $error) {
            return $this->plain($this->i18n->trans($error->getMessage()), $error->getStatusCode());
        } catch (\Throwable $error) {
            if ($error instanceof InteractiveCaptureInvalidatedException || $this->workerSessionGone($error)) {
                $this->closeExistingInteractiveSession($request, $id);
                return $this->plain($this->i18n->trans('Die interaktive Browser-Session ist nicht mehr verfügbar. Bitte im Review neu starten.'), Response::HTTP_GONE);
            }
            // Transport, worker 5xx, storage and DB failures are inconclusive.
            // Retain the binding so a transient failure cannot discard a page.
            return $this->plain($this->i18n->trans('Die interaktive Browser-Aktion ist vorübergehend fehlgeschlagen. Bitte erneut versuchen.'), Response::HTTP_BAD_GATEWAY);
        }
    }

    /** @return array<string, string|int> */
    private function interactiveBinding(FindingDetailView $detail, ?string $evidenceId): array
    {
        $finding = $detail->finding;
        $url = $finding->getPocUrl();
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === '' || preg_match('/[\x00-\x1f\x7f]/', $url)) {
            throw new HttpException(409, 'Für diese URL ist keine interaktive Aufnahme möglich.');
        }
        // Jobs are projected newest first. Choose the latest completed,
        // available screenshot for this exact URL before considering the
        // selected image; an older challenge cannot override a newer result.
        $job = null;
        foreach ($detail->screenshotJobs as $candidate) {
            $metadata = $candidate->getCaptureMetadata() ?? [];
            if ($candidate->getStatus() === 'available' && ($metadata['pocUrl'] ?? $candidate->getUrl()) === $url
                && ($metadata['queuedPocRevision'] ?? 0) === $finding->getPocRevision()) {
                $job = $candidate;
                break;
            }
        }
        $selected = null;
        foreach ($detail->screenshots as $shot) {
            if ($job !== null && $shot['job']?->getId() === $job->getId() && $shot['available']
                && ($evidenceId === null || $shot['evidence']->getId() === $evidenceId)) {
                $selected = $shot;
                break;
            }
        }
        $metadata = $job?->getCaptureMetadata() ?? [];
        if ($job === null || $selected === null || ($metadata['challengeDetected'] ?? null) !== true
            || ($metadata['challengeCleared'] ?? null) !== false) {
            throw new HttpException(409, 'Die auslösende Schutzseiten-Aufnahme ist nicht mehr verfügbar. Bitte im Review neu starten.');
        }
        $evidence = $selected['evidence'];

        return [
            'finding_id' => $finding->getId(),
            'start_url' => $url,
            'start_url_hash' => hash('sha256', $url),
            'poc_revision' => $finding->getPocRevision(),
            'trigger_job_id' => $job->getId(),
            'trigger_evidence_id' => $evidence->getId(),
            'trigger_hash' => hash('sha256', json_encode([$job->getId(), $job->getUrl(), $job->getStatus(),
                $job->getScreenshotPath(), $metadata, $evidence->getId(), $evidence->getFilePath(), $evidence->getSha256()], JSON_THROW_ON_ERROR)),
        ];
    }

    private function closeExistingInteractiveSession(Request $request, string $id, bool $strict = false): void
    {
        $key = self::INTERACTIVE_SESSION_PREFIX.$id;
        $record = $request->getSession()->get($key);
        $remove = true;
        try {
            if (is_array($record) && is_string($record['session_id'] ?? null)
                && preg_match('/^[a-f0-9]{48}$/D', $record['session_id']) === 1) {
                $this->browserInteractive->closeInteractive($record['session_id']);
            }
        } catch (\Throwable $error) {
            if (!$this->workerSessionGone($error) && $strict) {
                // A failed explicit close is inconclusive. Keep the active
                // record so cancel/restart can be retried without losing it.
                $remove = false;
                throw $error;
            }
            if (!$this->workerSessionGone($error)) {
                // This ID can only be closed, never used for input/capture again.
                // Keep it until confirmed closed or expired so repeated starts
                // cannot accumulate live worker pages after a transport failure.
                $pending = $request->getSession()->get(self::INTERACTIVE_CLEANUP_KEY, []);
                $pending[$record['session_id']] = is_int($record['expires_at'] ?? null) ? $record['expires_at'] : time() + 1200;
                $request->getSession()->set(self::INTERACTIVE_CLEANUP_KEY, $pending);
            }
        } finally {
            if ($remove) {
                $request->getSession()->remove($key);
            }
        }
    }

    private function closePendingInteractiveSessions(Request $request): void
    {
        $pending = $request->getSession()->get(self::INTERACTIVE_CLEANUP_KEY, []);
        foreach ($pending as $sessionId => $expiresAt) {
            if ($expiresAt > time()) {
                try {
                    $this->browserInteractive->closeInteractive($sessionId);
                } catch (\Throwable $error) {
                    if (!$this->workerSessionGone($error)) {
                        throw $error;
                    }
                }
            }
            unset($pending[$sessionId]);
            $request->getSession()->set(self::INTERACTIVE_CLEANUP_KEY, $pending);
        }
        $request->getSession()->remove(self::INTERACTIVE_CLEANUP_KEY);
    }

    private function workerSessionGone(\Throwable $error): bool
    {
        do {
            $status = $error instanceof WorkerHttpException ? $error->getResponse()->getStatusCode() : $error->getCode();
            if (in_array($status, [404, 410], true)) {
                return true;
            }
            $error = $error->getPrevious();
        } while ($error !== null);

        return false;
    }

    /** @param array<string, string> $query @return array<string, string> */
    private function interactiveReturnQuery(Request $request, string $id, array $query): array
    {
        try {
            $trail = $this->trails->open($request->getSession(), $query['trail']);
        } catch (\UnexpectedValueException) {
            // Another tab can evict this navigation trail during the worker TTL.
            // That must not turn a committed capture into an apparent save error.
            $trail = $this->trails->open($request->getSession(), null);
        }
        $query['trail'] = $trail['id'];
        $query['card'] = $id;
        $view = $this->queue->get($query, $id, true);
        $this->trails->displayed($request->getSession(), $trail, $this->card($view, true));

        return $query;
    }

    /** @return array<string, string> */
    private function safeReturnQuery(string $query): array
    {
        $parsed = [];
        parse_str($query, $parsed);
        $result = array_intersect_key($parsed, array_flip(['kind', 'images', 'after', 'trail', 'card', 'evidence']));
        foreach ($result as $key => $value) {
            if (!is_string($value) || (in_array($key, ['trail', 'card', 'evidence'], true) && !Uuid::isValid($value))) {
                throw new HttpException(400, 'Ungültige Review-Auswahl.');
            }
        }
        try {
            [$result['kind'], $result['images'], $after] = $this->queue->filters($result);
        } catch (\InvalidArgumentException $error) {
            throw new HttpException(400, 'Ungültige Review-Auswahl.', $error);
        }
        unset($result['after']);
        if ($after !== null) {
            $result['after'] = $after;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function interactiveEvent(Request $request): array
    {
        $action = $request->request->getString('action');
        if ($action === 'click') {
            return ['action' => 'click', 'x' => $this->interactiveInteger($request, 'click_x', 0, 1439), 'y' => $this->interactiveInteger($request, 'click_y', 0, 899)];
        }
        if ($action === 'type') {
            $text = $request->request->getString('text');
            if ($text === '' || strlen($text) > 10000) {
                throw new \InvalidArgumentException('Der interaktive Text muss zwischen 1 und 10000 Zeichen haben.');
            }

            return ['action' => 'type', 'text' => $text];
        }
        if ($action === 'key') {
            $key = $request->request->getString('key');
            if (!in_array($key, ['Enter', 'Tab', 'Escape', 'Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown'], true)) {
                throw new \InvalidArgumentException('Nicht unterstützte Taste für die interaktive Aufnahme.');
            }

            return ['action' => 'key', 'key' => $key];
        }
        if ($action === 'scroll') {
            $deltaY = $this->interactiveInteger($request, 'deltaY', -2000, 2000);

            return ['action' => 'scroll', 'deltaY' => max(-2000, min(2000, $deltaY))];
        }
        if (in_array($action, ['reload', 'back'], true)) {
            return ['action' => $action];
        }

        throw new \InvalidArgumentException('Nicht unterstützte Aktion für die interaktive Aufnahme.');
    }

    private function interactiveInteger(Request $request, string $field, int $min, int $max): int
    {
        $value = $request->request->all()[$field] ?? null;
        if ((!is_int($value) && !is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) {
            throw new \InvalidArgumentException('Invalid interactive input.');
        }

        return (int) $value;
    }

    private function interactiveRedirect(string $id): RedirectResponse
    {
        return new RedirectResponse('/review/'.rawurlencode($id).'/interactive-session?n='.bin2hex(random_bytes(4)), Response::HTTP_SEE_OTHER, ['Cache-Control' => 'no-store']);
    }

    private function renderInteractive(
        Finding $finding,
        \App\Dto\BrowserInteractiveSessionResult $status,
        array $returnQuery,
        int $manualActionCount = 0,
    ): Response {
        $locale = $this->i18n->locale();
        $t = fn (string $key, array $parameters = []): string => $this->i18n->trans($key, $parameters);
        $formatTime = fn (\DateTimeInterface $at, bool $withSeconds = false): string => $this->i18n->formatDateTime($at, $withSeconds);
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrfToken = $this->csrf->getToken('review_interactive_'.$finding->getId())->getValue();
        $returnQueryString = http_build_query($returnQuery, '', '&', PHP_QUERY_RFC3986);
        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/review-interactive.php';
        $html = ob_get_clean();

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function navigationParameters(Request $request): array
    {
        $result = [];
        $parameters = $request->request->all();
        foreach (['_token', 'trail_id', 'trail_version', 'context_token', 'displayed_evidence_id'] as $field) {
            if (isset($parameters[$field]) && !is_string($parameters[$field])) { throw new \InvalidArgumentException('Ungültiger Review-Durchlauf.'); }
            $result[$field] = $parameters[$field] ?? '';
        }
        return $result;
    }

    private function navigationFailure(Request $request, array $trail, string $message, int $status): Response
    {
        $card = $trail['current'];
        if ($card === null || !$this->queue->exists($card['id'])) {
            return $this->plain($this->i18n->trans($message), $status);
        }
        $view = $this->queue->get($this->cardQuery($card, $trail['id']), $card['id'], $card['explicit']);
        return $this->render($request, $view, null, $this->i18n->trans($message), [], $status);
    }

    private function card(ReviewQueueView $view, bool $explicit = false): ?array
    {
        return $view->detail === null ? null : [
            'id' => $view->detail->finding->getId(), 'kind' => $view->kind, 'images' => $view->images,
            'after' => $view->after, 'evidence' => $view->selectedEvidenceId,
            'fingerprint' => $view->stateFingerprint, 'explicit' => $explicit,
        ];
    }

    private function cardQuery(array $card, string $trailId): array
    {
        return array_filter(['kind' => $card['kind'], 'images' => $card['images'], 'after' => $card['after'], 'evidence' => $card['evidence'], 'trail' => $trailId,
            'card' => $card['explicit'] ? $card['id'] : null], static fn ($value): bool => $value !== null);
    }

    private function render(Request $request, ReviewQueueView $view, ?string $message = null, ?string $error = null, array $submitted = [], int $status = Response::HTTP_OK): Response
    {
        $trail = $request->attributes->get('_review_trail') ?? $this->trails->open($request->getSession(), null);
        $explicit = ($trail['current']['explicit'] ?? false) && ($trail['current']['id'] ?? null) === $view->detail?->finding->getId();
        // Session navigation state is not case data; reading never resets a case.
        $trail = $this->trails->displayed($request->getSession(), $trail, $this->card($view, $explicit));
        $request->attributes->set('_review_trail', $trail);
        $reviewTrailId = $trail['id'];
        $reviewTrailVersion = $trail['version'];
        $reviewBackAvailable = $trail['entries'] !== [];
        $reviewTrailTruncated = $trail['truncated'] ?? false;
        $reviewTrailLimit = ReviewTrail::MAX_STEPS;
        $reviewBackPath = '/review/back';
        $reviewSkipPath = $view->detail === null ? '' : '/review/'.$view->detail->finding->getId().'/skip';
        $reviewBackToken = $this->csrf->getToken('review_back_'.$reviewTrailId.'_'.$reviewTrailVersion)->getValue();
        $reviewSkipToken = $this->csrf->getToken('review_skip_'.$reviewTrailId.'_'.$reviewTrailVersion)->getValue();
        $reviewDecisionDelaySeconds = $this->settings->getReviewDecisionDelaySeconds();
        $isFirstStart = $view->detail === null && $this->findings->count([]) === 0;
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $locale = $this->i18n->locale();
        $languageReturnPath = $request->isMethod('GET') ? $request->getRequestUri() : $view->currentPath;
        $t = fn (string $key, array $parameters = []): string => $this->i18n->trans($key, $parameters);
        $formatTime = fn (?\DateTimeInterface $at, bool $withSeconds = false): string => $this->i18n->formatDateTime($at, $withSeconds);
        $formatDate = fn (\DateTimeInterface|string $date): string => $this->i18n->formatDate($date);
        $formatNumber = fn (int|float $value, int $decimals = 0): string => $this->i18n->formatNumber($value, $decimals);
        $i18nJson = $this->i18n->browserCatalogJson();
        $csrfField = fn (string $tokenId): string => '<input type="hidden" name="_token" value="'.$escape($this->csrf->getToken($tokenId)->getValue()).'">';
        $contextToken = $view->detail === null ? '' : $this->queue->contextToken($view->detail->finding->getId(), $view->stateFingerprint);
        $recoveryPrompt = null;
        $prompt = $request->getSession()->get('review_recovery_prompt');
        $promptId = $request->query->all()['prompt'] ?? null;
        if (is_array($prompt) && is_string($promptId)
            && $promptId === ($prompt['id'] ?? null)
            && $this->queue->exists($prompt['id'])
            && hash_equals($this->queue->fingerprint($prompt['id']), $prompt['fingerprint'])) {
            $recoveryPrompt = $prompt['id'];
        }
        $lastReviewedId = $view->lastReviewedId;
        $selectedEvidenceId = $view->selectedEvidenceId;
        $interactiveCsrfToken = $view->detail === null ? '' : $this->csrf->getToken('review_interactive_'.$view->detail->finding->getId())->getValue();
        ob_start();
        $checksAllowed = $view->detail === null || $this->workPolicy->checksAllowed($view->detail->finding);
        require dirname(__DIR__, 2).'/templates/studio/review.php';
        $html = ob_get_clean();

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function plain(string $message, int $status): Response
    {
        return new Response($message, $status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
