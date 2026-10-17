<?php

namespace App\Controller;

use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Service\InteractiveBrowserCoordinator;
use App\Service\InteractiveBrowserLeaseService;
use App\Service\ReviewQueueService;
use App\Service\ReviewTrail;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class InteractiveBrowserController
{
    public function __construct(
        private readonly FindingRepository $findings,
        private readonly InteractiveBrowserCoordinator $browser,
        private readonly InteractiveBrowserLeaseService $leases,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly UiTranslator $i18n,
        private readonly ReviewQueueService $queue,
        private readonly ReviewTrail $trails,
    ) {}

    #[Route('/review/{id}/interactive', name: 'studio_review_novnc', methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $finding = $this->finding($id);
        if ($finding === null) { return new Response('Not found', 404); }
        $locale = $this->i18n->locale();
        $t = fn (string $key, array $parameters = []): string => $this->i18n->trans($key, $parameters);
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrfToken = $interactiveCsrfToken = $this->csrf->getToken('review_interactive_'.$id)->getValue();
        $returnPath = $this->returnPath($request->query->get('return_to'));
        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/review-novnc.php';
        return new Response(ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    #[Route('/review/{id}/interactive/status', name: 'studio_review_novnc_status', methods: ['GET'])]
    public function status(string $id, Request $request): Response
    {
        $finding = $this->finding($id);
        if ($finding === null) { return $this->json(['state' => 'revoked', 'reason' => 'not_found'], 404); }
        try {
            $owner = $this->owner($request);
            $channel = $this->channel($request->headers->get('X-LBB-Tab'), false);
            return $this->json($this->browser->status($finding, $owner, $channel));
        } catch (\Throwable) {
            return $this->json(['state' => 'worker_unavailable', 'reason' => 'unavailable', 'findingId' => $id, 'revision' => 0, 'reconnect' => false, 'canTakeover' => false, 'requiresEndStart' => false], 503);
        }
    }

    #[Route('/review/{id}/interactive/lease/{operation}', name: 'studio_review_novnc_lease', requirements: ['operation' => 'acquire|takeover|release|end-and-start'], methods: ['POST'])]
    public function mutate(string $id, string $operation, Request $request): Response
    {
        return $this->operation($id, $request, function (Finding $finding, string $owner, string $channel, ?int $revision, array $body) use ($operation): ?array {
            return match ($operation) {
                'acquire' => $this->browser->acquire($finding, $owner, $channel, $revision),
                'takeover' => $this->browser->takeover($finding, $owner, $channel, $this->requiredRevision($revision)),
                'release' => $this->release($finding, $owner, $channel, $revision),
                'end-and-start' => ($body['confirm'] ?? '') === '1' || ($body['confirm'] ?? null) === true
                    ? $this->browser->endAndStart($finding, $owner, $channel, $this->requiredRevision($revision))
                    : throw new \RuntimeException('Explicit confirmation required.', 409),
            };
        });
    }

    #[Route('/review/{id}/interactive/save', name: 'studio_review_novnc_save', methods: ['POST'])]
    public function save(string $id, Request $request): Response
    {
        return $this->operation($id, $request, function (Finding $finding, string $owner, string $channel, ?int $revision, array $body) use ($request): array {
            $saved = $this->browser->save($finding, $owner, $channel, $this->requiredRevision($revision));
            $redirect = $this->evidenceReturn($request, $finding, $saved->evidence->getId(), $body['return_to'] ?? null);
            return ['saved' => true, 'redirect' => $redirect, 'contextClosed' => $saved->sessionClosed];
        });
    }

    private function operation(string $id, Request $request, callable $fn): Response
    {
        $finding = $this->finding($id);
        if ($finding === null) { return $this->json(['state' => 'revoked', 'reason' => 'not_found'], 404); }
        try {
            if (strlen($request->getContent()) > 8192) { return $this->json(['state' => 'revoked', 'reason' => 'body_too_large'], 413); }
            $body = str_contains($request->headers->get('Content-Type', ''), 'application/json')
                ? json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR) : $request->request->all();
            if (!is_array($body) || !is_string($body['_token'] ?? null)
                || !$this->csrf->isTokenValid(new CsrfToken('review_interactive_'.$id, $body['_token']))) {
                return $this->json(['state' => 'revoked', 'reason' => 'csrf_invalid'], 403);
            }
            $channel = $this->channel($body['channelId'] ?? null, true);
            $revision = $this->revision($body['expectedRevision'] ?? null);
            $owner = $this->owner($request); // closes native session before RPCs
            $result = $fn($finding, $owner, $channel, $revision, $body);
            $payload = array_replace($this->browser->status($finding, $owner, $channel), is_array($result) && isset($result['saved']) ? $result : []);
            if ($this->wantsJson($request)) { return $this->json($payload); }
            return new RedirectResponse($payload['redirect'] ?? '/review/'.$id.'/interactive', 303, ['Cache-Control' => 'no-store']);
        } catch (\Throwable $error) {
            $code = in_array((int) $error->getCode(), [400, 403, 404, 409, 410, 413, 503], true) ? (int) $error->getCode() : 502;
            try {
                $payload = $this->browser->status($finding, $this->owner($request), $this->channel(($body ?? [])['channelId'] ?? null, false));
            } catch (\Throwable) { $payload = ['state' => 'worker_unavailable']; }
            $payload['reason'] = $code === 409 ? 'stale_revision_or_busy' : ($code === 410 ? 'expired' : 'operation_failed');
            return $this->json($payload, $code);
        }
    }

    private function release(Finding $finding, string $owner, string $channel, ?int $revision): ?array
    {
        $this->browser->release($finding, $owner, $channel, $revision);
        return null;
    }

    private function finding(string $id): ?Finding { return Uuid::isValid($id) ? $this->findings->find($id) : null; }

    private function owner(Request $request): string
    {
        $session = $request->getSession();
        $session->start();
        $owner = $this->leases->ownerHash($session->getId());
        $session->save();
        return $owner;
    }

    private function channel(mixed $value, bool $required): ?string
    {
        if (is_string($value) && preg_match('/^[a-f0-9]{48}$/D', $value) === 1) { return $value; }
        if ($required) { throw new \RuntimeException('Invalid interactive channel.', 400); }
        return null;
    }

    private function revision(mixed $value): ?int
    {
        if ($value === null || $value === '') { return null; }
        if ((is_int($value) && $value >= 0) || (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,14})$/D', $value))) { return (int) $value; }
        throw new \RuntimeException('Invalid interactive revision.', 400);
    }

    private function requiredRevision(?int $revision): int
    {
        return $revision ?? throw new \RuntimeException('Interactive revision required.', 400);
    }

    private function returnPath(mixed $value): string
    {
        if (!is_string($value) || preg_match('/[\x00-\x20\x7f]/', $value)) { return '/review?images=all'; }
        $parts = parse_url($value);
        return is_array($parts) && !isset($parts['scheme']) && !isset($parts['host']) && ($parts['path'] ?? '') === '/review'
            ? $value : '/review?images=all';
    }

    private function evidenceReturn(Request $request, Finding $finding, string $evidenceId, mixed $returnTo): string
    {
        $path = $this->returnPath($returnTo);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $query = array_intersect_key($query, array_flip(['kind', 'images', 'after', 'trail']));
        try {
            $trail = $this->trails->open($request->getSession(), $query['trail'] ?? null);
            $query['trail'] = $trail['id'];
            $query['evidence'] = $evidenceId;
            $view = $this->queue->get($query, $finding->getId(), true);
            $this->trails->displayed($request->getSession(), $trail, [
                'id' => $finding->getId(), 'kind' => $view->kind, 'images' => $view->images,
                'after' => $view->after, 'evidence' => $evidenceId, 'fingerprint' => $view->stateFingerprint, 'explicit' => true,
            ]);
            $query['card'] = $finding->getId();
            return '/review?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        } catch (\Throwable) { return '/findings/'.$finding->getId(); }
    }

    private function wantsJson(Request $request): bool { return str_contains($request->headers->get('Accept', ''), 'application/json'); }
    private function json(array $data, int $status = 200): JsonResponse { return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']); }
}
