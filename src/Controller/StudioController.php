<?php

namespace App\Controller;

use App\Service\SettingsService;
use App\Service\FindingDetailService;
use App\Service\FindingListService;
use App\Service\FindingNavigation;
use App\Service\StatisticsService;
use App\Service\UiTranslator;
use App\Service\ScreenshotComparisonService;
use App\Service\FindingProblemService;
use App\Service\InventoryViewService;
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
        private readonly UiTranslator $i18n,
        private readonly ScreenshotComparisonService $comparison,
        private readonly FindingProblemService $problems,
        private readonly InventoryViewService $inventoryViews,
        private readonly \App\Service\FollowUpService $followUp,
        private readonly \App\Service\ContactDiscoveryService $contacts,
        private readonly \App\Service\ContactRouteService $contactRoutes,
        private readonly \App\Service\DisclosureRecordService $disclosureRecords,
        private readonly \App\Service\FollowUpStatisticsService $followUpStatistics,
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
        $languageReturnPath = $request->getRequestUri();
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->i18nVariables($locale, $t, $formatTime, $formatDate, $formatNumber, $i18nJson);

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
            return new Response($this->i18n->trans('Ungültiger Filter: {message}', ['message' => $this->i18n->trans($exception->getMessage())]), Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->i18nVariables($locale, $t, $formatTime, $formatDate, $formatNumber, $i18nJson);
        $query = $request->query->all();
        $message = is_string($query['message'] ?? null) ? $query['message'] : null;
        $error = is_string($query['error'] ?? null) ? $query['error'] : null;
        $languageReturnPath = $request->getRequestUri();
        $savedViews = $this->inventoryViews->all();
        $viewQuery = $this->inventoryViews->normalize($view->filterQuery);
        $viewDescription = $this->inventoryViews->describe($viewQuery);
        $viewVocabulary = $this->inventoryViews->vocabulary();
        $recordRecentView = !array_key_exists('page', $query) && $message === null && $error === null
            && $viewQuery !== ['scope' => 'active'];
        $csrfField = fn (string $tokenId): string => '<input type="hidden" name="_token" value="'.$escape($this->csrf->getToken($tokenId)->getValue()).'">';
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
            return new Response($this->i18n->trans('Ungültiger Zeitraum: {message}', ['message' => $this->i18n->trans($exception->getMessage())]), Response::HTTP_BAD_REQUEST, [
                'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store',
            ]);
        }
        $followUpStats = $this->followUpStatistics->get($view['filters']['tld'] ?? '');
        $isFirstStart = $this->findings->count([]) === 0;
        $languageReturnPath = $request->getRequestUri();
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->i18nVariables($locale, $t, $formatTime, $formatDate, $formatNumber, $i18nJson);
        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/statistics.php';
        $html = ob_get_clean();

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    #[Route(path: '/errors', name: 'studio_errors', methods: ['GET'])]
    public function errors(Request $request): Response
    {
        try {
            $problems = $this->problems->get($request->query->all());
        } catch (\InvalidArgumentException $exception) {
            return new Response($this->i18n->trans($exception->getMessage()), Response::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
        }
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->i18nVariables($locale, $t, $formatTime, $formatDate, $formatNumber, $i18nJson);
        $languageReturnPath = $request->getRequestUri();
        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/errors.php';
        return new Response(ob_get_clean(), Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
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

    #[Route(path: '/findings/{id}', name: 'studio_finding_show', methods: ['GET'])]
    public function finding(string $id, Request $request): Response
    {
        if (!Uuid::isValid($id) || !$this->findings->find($id) instanceof Finding) {
            return new Response($this->i18n->trans('Fall nicht gefunden.'), Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        $view = $this->findingDetail->get($id);
        $workState = $this->followUp->state($view->finding);
        $workHistory = $this->followUp->history($view->finding);
        $contactProviders = $this->contacts->providers();
        $contactHistory = $this->contacts->history($view->finding);
        $contactRoute = $this->contactRoutes->state($view->finding);
        $disclosureRecord = $this->disclosureRecords->state($view->finding);
        try {
            $comparison = $this->comparison->get($view, $request->query->all());
        } catch (\InvalidArgumentException $exception) {
            return new Response($this->i18n->trans($exception->getMessage()), Response::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
        }
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->i18nVariables($locale, $t, $formatTime, $formatDate, $formatNumber, $i18nJson);
        $csrfField = fn (string $tokenId): string => '<input type="hidden" name="_token" value="'.$escape($this->csrf->getToken($tokenId)->getValue()).'">';
        $query = $request->query->all();
        $message = is_string($query['message'] ?? null) ? $query['message'] : null;
        $error = is_string($query['error'] ?? null) ? $query['error'] : null;

        $returnPath = $this->navigation->listReturnPath($query['return_to'] ?? null);
        $languageReturnPath = $request->getRequestUri();

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

    private function i18nVariables(
        ?string &$locale,
        mixed &$t,
        mixed &$formatTime,
        mixed &$formatDate,
        mixed &$formatNumber,
        ?string &$i18nJson,
    ): void {
        $locale = $this->i18n->locale();
        $t = fn (string $key, array $parameters = []): string => $this->i18n->trans($key, $parameters);
        $formatTime = fn (?\DateTimeInterface $at, bool $withSeconds = false): string => $this->i18n->formatDateTime($at, $withSeconds);
        $formatDate = fn (\DateTimeInterface|string $date): string => $this->i18n->formatDate($date);
        $formatNumber = fn (int|float $value, int $decimals = 0): string => $this->i18n->formatNumber($value, $decimals);
        $i18nJson = $this->i18n->browserCatalogJson();
    }
}
