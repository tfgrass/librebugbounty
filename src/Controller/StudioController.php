<?php

namespace App\Controller;

use App\Service\SettingsService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class StudioController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly CsrfTokenManagerInterface $csrf,
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
}
