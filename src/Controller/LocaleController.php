<?php

namespace App\Controller;

use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class LocaleController
{
    #[Route(path: '/language/{locale}', name: 'ui_language', requirements: ['locale' => 'en|de'], methods: ['GET'])]
    public function switch(Request $request, string $locale): RedirectResponse
    {
        $return = $request->query->all()['return'] ?? '/';
        $response = new RedirectResponse($this->localReturnPath($return), RedirectResponse::HTTP_SEE_OTHER);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->setCookie(Cookie::create(UiTranslator::COOKIE_NAME)
            ->withValue($locale)
            ->withExpires(new \DateTimeImmutable('+1 year'))
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX));

        return $response;
    }

    private function localReturnPath(mixed $path): string
    {
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        $decoded = $path;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            if (str_starts_with($decoded, '//') || preg_match('/[\\\\\x00-\x1F\x7F]/', $decoded)) {
                return '/';
            }
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        if (str_starts_with($decoded, '//') || preg_match('/[\\\\\x00-\x1F\x7F]/', $decoded)) {
            return '/';
        }

        return $path;
    }
}
