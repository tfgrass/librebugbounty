<?php

namespace App\Controller;

use App\Service\DocumentationService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DocumentationController
{
    public function __construct(
        private readonly DocumentationService $documentation,
        private readonly UiTranslator $i18n,
    ) {
    }

    #[Route(path: '/docs', name: 'studio_documentation', methods: ['GET'])]
    public function index(): RedirectResponse
    {
        return new RedirectResponse('/docs/usage');
    }

    #[Route(path: '/docs/{slug}', name: 'studio_documentation_page', requirements: ['slug' => '[a-z]+'], methods: ['GET'])]
    public function show(string $slug, Request $request): Response
    {
        $page = $this->documentation->page($slug);
        if ($page === null) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }
        $pages = $this->documentation->pages();
        $locale = $this->i18n->locale();
        $languageReturnPath = $request->getRequestUri();
        $t = fn (string $key, array $parameters = []): string => $this->i18n->trans($key, $parameters);
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $i18nJson = $this->i18n->browserCatalogJson();

        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/documentation.php';
        $html = ob_get_clean();

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route(path: '/docs/images/{image}', name: 'studio_documentation_image', requirements: ['image' => '[a-z-]+\.png'], methods: ['GET'])]
    public function image(string $image): Response
    {
        $path = $this->documentation->imagePath($image);
        if ($path === null) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        return new BinaryFileResponse($path, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
