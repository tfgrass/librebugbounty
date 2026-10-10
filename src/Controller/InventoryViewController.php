<?php

namespace App\Controller;

use App\Service\FindingNavigation;
use App\Service\InventoryViewService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class InventoryViewController
{
    public function __construct(
        private readonly InventoryViewService $views,
        private readonly FindingNavigation $navigation,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly UiTranslator $i18n,
    ) {
    }

    #[Route('/inventory-views', name: 'inventory_view_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        return $this->change($request, 'create');
    }

    #[Route('/inventory-views/{id}/{action}', name: 'inventory_view_change', requirements: ['action' => 'rename|delete'], methods: ['POST'])]
    public function update(string $id, string $action, Request $request): Response
    {
        return $this->change($request, $action, $id);
    }

    private function change(Request $request, string $action, ?string $id = null): Response
    {
        $parameters = $request->request->all();
        $token = $parameters['_token'] ?? null;
        $tokenId = 'inventory_view_'.$action.($id === null ? '' : '_'.$id);
        if (!is_string($token) || !$this->csrf->isTokenValid(new CsrfToken($tokenId, $token))) {
            return new Response($this->i18n->trans('Ungültiges Formular. Bitte lade die Seite neu.'), Response::HTTP_FORBIDDEN, ['Cache-Control' => 'no-store']);
        }
        $return = $this->navigation->listReturnPath($parameters['return_to'] ?? null) ?? '/findings';
        if (parse_url($return, PHP_URL_PATH) !== '/findings') {
            $return = '/findings';
        }
        try {
            if ($action === 'create') {
                $raw = $parameters['filters'] ?? null;
                if (!is_string($raw) || strlen($raw) > 16384) {
                    throw new \InvalidArgumentException('Ungültige Ansichtsangaben.');
                }
                $query = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($query)) {
                    throw new \InvalidArgumentException('Ungültige Ansichtsangaben.');
                }
                $this->views->create($parameters['name'] ?? null, $query);
            } elseif ($action === 'rename') {
                $this->views->rename($id, $parameters['name'] ?? null);
            } else {
                $this->views->delete($id);
            }
        } catch (\OutOfBoundsException $exception) {
            return new Response($this->i18n->trans($exception->getMessage()), Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        } catch (\InvalidArgumentException|\JsonException $exception) {
            $error = $exception instanceof \JsonException ? 'Ungültige Ansichtsangaben.' : $exception->getMessage();
            return $this->redirect($return, 'error', $error);
        }

        return $this->redirect($return, 'message', match ($action) {
            'create' => 'Ansicht gespeichert.', 'rename' => 'Ansicht umbenannt.', default => 'Ansicht gelöscht.',
        });
    }

    private function redirect(string $return, string $kind, string $message): RedirectResponse
    {
        return new RedirectResponse($return.(str_contains($return, '?') ? '&' : '?').http_build_query([$kind => $this->i18n->trans($message)], '', '&', PHP_QUERY_RFC3986).'#ansichten', Response::HTTP_SEE_OTHER, ['Cache-Control' => 'no-store']);
    }
}
