<?php

namespace App\Controller;

use App\Dto\ReviewQueueView;
use App\Repository\FindingRepository;
use App\Entity\Finding;
use App\Service\ReviewQueueService;
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
    ) {
    }

    #[Route(path: '/review', name: 'studio_review', methods: ['GET'])]
    public function review(Request $request): Response
    {
        try {
            $view = $this->queue->get($request->query->all());
        } catch (\InvalidArgumentException $exception) {
            return $this->plain($this->i18n->trans($exception->getMessage()), Response::HTTP_BAD_REQUEST);
        }
        $query = $request->query->all();
        $message = is_string($query['message'] ?? null) ? $query['message'] : null;

        return $this->render($view, $message);
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
        foreach (['assessment', 'discard_reason', 'observation_id', 'evidence_id', 'displayed_evidence_id', 'kind', 'images', 'after', '_token', 'context_token'] as $field) {
            if (array_key_exists($field, $parameters) && !is_string($parameters[$field])) {
                $malformed = true;
            }
            $submitted[$field] = is_string($parameters[$field] ?? null) ? $parameters[$field] : '';
        }
        $query = array_intersect_key($parameters, array_flip(['kind', 'images', 'after']));
        $query['evidence'] = $submitted['displayed_evidence_id'];
        try {
            $view = $this->queue->get($query, $id);
        } catch (\InvalidArgumentException $exception) {
            return $this->render($this->queue->get([], $id), null, $this->i18n->trans($exception->getMessage()), $submitted, Response::HTTP_BAD_REQUEST);
        }
        if ($malformed) {
            return $this->render($view, null, $this->i18n->trans('Ungültige Bewertungsangaben.'), $submitted, Response::HTTP_BAD_REQUEST);
        }
        if (!$this->csrf->isTokenValid(new CsrfToken('review_assessment_'.$id, $submitted['_token']))) {
            return $this->render($view, null, $this->i18n->trans('Die Bewertung wurde nicht gespeichert. Formular bitte neu laden.'), $submitted, Response::HTTP_FORBIDDEN);
        }
        // Keep a complete, unmodified current card available even if a failed
        // ORM flush closes the entity manager. Never redirect on write failure.
        $failureResponse = $this->render($view, null, $this->i18n->trans('Die Bewertung konnte nicht gespeichert werden. Bitte den Fall neu laden und erneut prüfen.'), $submitted, Response::HTTP_INTERNAL_SERVER_ERROR);
        try {
            $this->queue->assess(
                $id,
                $submitted['assessment'],
                trim($submitted['discard_reason']) ?: null,
                trim($submitted['observation_id']) ?: null,
                trim($submitted['evidence_id']) ?: null,
                $submitted['context_token'],
            );
        } catch (\UnexpectedValueException $exception) {
            return $this->render($this->queue->get($query, $id), null, $this->i18n->trans($exception->getMessage()), $submitted, Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $exception) {
            return $this->render($view, null, $this->i18n->trans($exception->getMessage()), $submitted, Response::HTTP_BAD_REQUEST);
        } catch (\Throwable) {
            return $failureResponse;
        }

        $message = match ($submitted['assessment']) {
            'confirmed' => $this->i18n->trans('Vulnerable bestätigt.'),
            'fixed' => $this->i18n->trans('Not vulnerable bestätigt.'),
            'discarded' => $this->i18n->trans('Fall verworfen.'),
            'keep' => $this->i18n->trans('Hinweis geprüft. Bewertung beibehalten.'),
        };
        $nextPath = $this->queue->path($view->kind, $view->images, $id);

        return new RedirectResponse($nextPath.(str_contains($nextPath, '?') ? '&' : '?').'message='.rawurlencode($message).'&reviewed='.rawurlencode($id), Response::HTTP_SEE_OTHER, ['Cache-Control' => 'no-store']);
    }

    private function render(ReviewQueueView $view, ?string $message = null, ?string $error = null, array $submitted = [], int $status = Response::HTTP_OK): Response
    {
        $isFirstStart = $view->detail === null && $this->findings->count([]) === 0;
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $locale = $this->i18n->locale();
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
