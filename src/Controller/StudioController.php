<?php

namespace App\Controller;

use App\Service\SettingsService;
use App\Service\FindingDetailService;
use App\Repository\FindingRepository;
use App\Entity\Finding;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class StudioController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly FindingDetailService $findingDetail,
        private readonly FindingRepository $findings,
    ) {
    }

    #[Route(path: '/studio', name: 'studio', methods: ['GET'])]
    public function intake(): Response
    {
        $defaultPayload = $this->settings->getDefaultPayload();
        $csrfToken = $this->csrf->getToken('finding_create')->getValue();
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/intake.php';
        $html = ob_get_clean();

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    #[Route(path: '/studio/', name: 'studio_trailing_slash', methods: ['GET'])]
    public function canonicalIntake(): RedirectResponse
    {
        return new RedirectResponse('/studio', Response::HTTP_PERMANENTLY_REDIRECT);
    }

    #[Route(path: '/studio/findings/{id}', name: 'studio_finding_show', methods: ['GET'])]
    public function finding(string $id, Request $request): Response
    {
        if (!Uuid::isValid($id) || !$this->findings->find($id) instanceof Finding) {
            return new Response('Fall nicht gefunden.', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        $view = $this->findingDetail->get($id);
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrfField = fn (string $tokenId): string => '<input type="hidden" name="_token" value="'.$escape($this->csrf->getToken($tokenId)->getValue()).'">';
        $query = $request->query->all();
        $message = is_string($query['message'] ?? null) ? $query['message'] : null;
        $error = is_string($query['error'] ?? null) ? $query['error'] : null;

        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/finding.php';
        $html = ob_get_clean();

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
