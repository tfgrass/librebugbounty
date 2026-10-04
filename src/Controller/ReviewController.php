<?php

namespace App\Controller;

use App\Dto\ReviewQueueView;
use App\Repository\FindingRepository;
use App\Entity\Finding;
use App\Service\ReviewQueueService;
use App\Service\ReviewTrail;
use App\Service\SettingsService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class ReviewController
{
    public function __construct(
        private readonly ReviewQueueService $queue,
        private readonly FindingRepository $findings,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly UiTranslator $i18n,
        private readonly ReviewTrail $trails,
        private readonly SettingsService $settings,
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
            // Skip has no write lock: retain the state the user actually saw,
            // never upgrade its reset permission to a later concurrent state.
            $card['fingerprint'] = $fingerprint;
            $this->trails->forward($request->getSession(), $trail, $card);
            return new RedirectResponse($this->queue->path($view->kind, $view->images, $id, null, $trail['id']), Response::HTTP_SEE_OTHER, ['Cache-Control' => 'no-store']);
        } catch (\InvalidArgumentException $exception) {
            return $this->plain($this->i18n->trans($exception->getMessage()), Response::HTTP_BAD_REQUEST);
        } catch (\UnexpectedValueException $exception) {
            return isset($trail) ? $this->navigationFailure($request, $trail, $exception->getMessage(), Response::HTTP_CONFLICT)
                : $this->plain($this->i18n->trans($exception->getMessage()), Response::HTTP_CONFLICT);
        }
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
        $lastReviewedId = $view->lastReviewedId;
        $selectedEvidenceId = $view->selectedEvidenceId;
        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/review.php';
        $html = ob_get_clean();

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function plain(string $message, int $status): Response
    {
        return new Response($message, $status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
