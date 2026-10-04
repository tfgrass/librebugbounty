<?php

namespace App\Controller;

use App\Service\SettingsService;
use App\Service\FindingDetailService;
use App\Service\FindingListService;
use App\Service\FindingNavigation;
use App\Service\StatisticsService;
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
        private readonly FindingListService $findingList,
        private readonly FindingNavigation $navigation,
        private readonly StatisticsService $statistics,
    ) {
    }

    #[Route(path: '/', name: 'studio', methods: ['GET'])]
    public function intake(Request $request): Response
    {
        if ($this->findingList->hasListQuery($request->query->all())) {
            return $this->redirectWithQuery('/findings', $request);
        }
        $query = $request->query->all();
        $message = is_string($query['message'] ?? null) ? $query['message'] : null;
        $error = is_string($query['error'] ?? null) ? $query['error'] : null;
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

    #[Route(path: '/studio', name: 'studio_alias', methods: ['GET'])]
    public function canonicalIntake(Request $request): RedirectResponse
    {
        return $this->redirectWithQuery('/', $request);
    }

    #[Route(path: '/findings', name: 'studio_findings', methods: ['GET'])]
    public function inventory(Request $request): Response
    {
        try {
            $view = $this->findingList->get($request->query->all(), '/findings');
        } catch (\InvalidArgumentException $exception) {
            return new Response('Ungültiger Filter: '.$exception->getMessage(), Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $query = $request->query->all();
        $message = is_string($query['message'] ?? null) ? $query['message'] : null;
        $error = is_string($query['error'] ?? null) ? $query['error'] : null;
        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/inventory.php';
        $html = ob_get_clean();

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    #[Route(path: '/statistics', name: 'studio_statistics', methods: ['GET'])]
    public function statistics(Request $request): Response
    {
        try {
            $view = $this->statistics->get($request->query->all());
        } catch (\InvalidArgumentException $exception) {
            return new Response('Ungültiger Zeitraum: '.$exception->getMessage(), Response::HTTP_BAD_REQUEST, [
                'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store',
            ]);
        }
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/statistics.php';
        $html = ob_get_clean();

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    #[Route(path: '/studio/statistics', name: 'studio_statistics_alias', methods: ['GET'])]
    public function canonicalStatistics(Request $request): RedirectResponse
    {
        return $this->redirectWithQuery('/statistics', $request);
    }

    #[Route(path: '/studio/findings', name: 'studio_findings_alias', methods: ['GET'])]
    public function canonicalInventory(Request $request): RedirectResponse
    {
        return $this->redirectWithQuery('/findings', $request);
    }

    #[Route(path: '/studio/findings/{id}', name: 'studio_finding_alias', methods: ['GET'])]
    public function canonicalFinding(string $id, Request $request): RedirectResponse
    {
        return $this->redirectWithQuery('/findings/'.rawurlencode($id), $request);
    }

    #[Route(path: '/settings', name: 'settings_alias', methods: ['GET'])]
    public function canonicalSettings(Request $request): RedirectResponse
    {
        return $this->redirectWithQuery('/legacy/settings', $request);
    }

    #[Route(path: '/findings/{id}', name: 'studio_finding_show', methods: ['GET'])]
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

        $returnPath = $this->navigation->listReturnPath($query['return_to'] ?? null);

        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/finding.php';
        $html = ob_get_clean();

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function redirectWithQuery(string $path, Request $request): RedirectResponse
    {
        $query = $request->getQueryString();

        return new RedirectResponse($path.($query === null ? '' : '?'.$query), Response::HTTP_PERMANENTLY_REDIRECT);
    }
}
