<?php

namespace App\Controller;

use App\Dto\FindingReadFilter;
use App\Dto\FindingReadView;
use App\Dto\FindingDetailView;
use App\Dto\FindingAssessmentState;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\Evidence;
use App\Entity\RetestRun;
use App\Repository\EvidenceRepository;
use App\Repository\RetestRunRepository;
use App\Repository\ScreenshotJobRepository;
use App\Service\FindingService;
use App\Service\FindingDetailService;
use App\Service\FindingListService;
use App\Service\FindingNavigation;
use App\Service\EvidenceStorageInterface;
use App\Service\SettingsService;
use App\Service\RetestService;
use App\Service\ScreenshotStatusService;
use App\Service\ScreenshotQueueService;
use App\Value\FindingStatus;
use App\Value\FindingReadLabels;
use App\Value\ScreenshotJobStatus;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route(path: '/')]
final class WebController
{
    public function __construct(
        private readonly FindingService $findingService,
        private readonly RetestService $retestService,
        private readonly SettingsService $settings,
        private readonly EvidenceRepository $evidenceRepository,
        private readonly RetestRunRepository $retestRunRepository,
        private readonly EvidenceStorageInterface $storage,
        private readonly ScreenshotStatusService $screenshotStatus,
        private readonly ScreenshotQueueService $screenshotQueue,
        private readonly ScreenshotJobRepository $screenshotJobs,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly FindingListService $findingList,
        private readonly FindingNavigation $navigation,
        private readonly FindingDetailService $findingDetail,
    ) {
    }

    #[Route(path: 'legacy', name: 'home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        try {
            $view = $this->findingList->get($request->query->all(), '/legacy');
        } catch (\InvalidArgumentException $exception) {
            return new Response('Ungültiger Filter: '.$exception->getMessage(), Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }

        return new Response($this->renderHomePage(
            findings: $view->findings,
            stats: $view->stats,
            screenshotStats: $view->screenshotStats,
            defaultPayload: $this->settings->getDefaultPayload(),
            filter: $view->filter,
            pagination: $view->pagination,
            message: $request->query->getString('message') ?: null,
            error: $request->query->getString('error') ?: null,
        ), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route(path: 'findings', name: 'finding_create', methods: ['POST'])]
    public function createFinding(Request $request): Response
    {
        if (!$this->validCsrf($request, 'finding_create')) {
            return new Response('Ungültiges Formular. Bitte neu laden.', Response::HTTP_FORBIDDEN);
        }

        $parameters = $request->request->all();
        foreach (['url', 'payload', 'annotate', 'surface'] as $field) {
            if (array_key_exists($field, $parameters) && !is_string($parameters[$field])) {
                return new Response('Ungültige Eingabe.', Response::HTTP_BAD_REQUEST);
            }
        }
        $studio = ($parameters['surface'] ?? null) === 'studio';
        $intakePath = $studio ? '/' : '/legacy';

        try {
            $payload = trim($request->request->getString('payload'));
            $submittedUrl = trim($request->request->getString('url'));
            $result = $this->findingService->createIntakeFinding(
                url: $submittedUrl,
                expectedEvidence: $payload !== '' ? $payload : null,
                privateNotes: $request->request->getString('annotate') ?: null,
            );
            $finding = $result->finding;
            if (!$result->created) {
                return $this->redirectMessage(sprintf(
                    'URL not imported because it already exists as finding %s.',
                    $this->shortId($finding),
                ), $this->findingReturnPath($finding->getId(), $parameters));
            }

            return $this->redirectMessage(sprintf(
                'Finding %s stored for %s. Screenshot queued.',
                $this->shortId($finding),
                $finding->getDomain()->getHostname(),
            ), $studio ? '/findings/'.rawurlencode($finding->getId()) : '/legacy');
        } catch (\InvalidArgumentException $exception) {
            return $this->redirectError($exception->getMessage(), $intakePath);
        } catch (\Throwable) {
            return $this->redirectError('Die Speicherung konnte nicht bestätigt werden. Bitte prüfe den Bestand, bevor du die Eingabe erneut sendest.', $intakePath);
        }
    }

    #[Route(path: 'legacy/settings', name: 'settings', methods: ['GET'])]
    #[Route(path: 'settings', name: 'settings_save', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $payload = trim($request->request->getString('default_payload'));
            $timeoutMs = trim($request->request->getString('review_timeout_ms'));

            if ($payload === '') {
                $payload = SettingsService::DEFAULTS['intake.default_payload'];
            }
            if ($timeoutMs === '' || !ctype_digit($timeoutMs) || (int) $timeoutMs < 1000) {
                $timeoutMs = SettingsService::DEFAULTS['review.scan_timeout_ms'];
            }

            $this->settings->save([
                'intake.default_payload' => $payload,
                'review.scan_timeout_ms' => $timeoutMs,
            ]);

            return $this->redirectMessage('Settings saved.');
        }

        return new Response($this->renderSettingsPage(
            settings: $this->settings->all(),
        ));
    }

    #[Route(path: 'about', name: 'about', methods: ['GET'])]
    public function about(): Response
    {
        return new RedirectResponse('/legacy#about-modal');
    }

    #[Route(path: 'findings/{id}/retest', name: 'finding_retest', methods: ['POST'])]
    public function retestFinding(string $id): Response
    {
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            if ($finding->isDiscarded()) {
                return new Response('Verworfene Fälle werden im normalen Arbeiten ignoriert.', Response::HTTP_CONFLICT);
            }
            $errors = [];
            try {
                $queued = $this->screenshotQueue->enqueue($finding);
                $queueResult = $queued->created ? 'queued' : 'already '.$queued->job->getStatus();
            } catch (\Throwable $queueException) {
                $queueResult = 'could not be queued';
                $errors[] = 'Screenshot queue failed: '.$queueException->getMessage();
            }
            try {
                $run = $this->retestService->retest($finding, false, 120000, false, false, true);
                $retestResult = $run->getResult();
            } catch (\Throwable $retestException) {
                $retestResult = 'failed';
                $errors[] = 'Retest failed: '.$retestException->getMessage();
            }

            $message = sprintf(
                'Retest finished for %s: %s. Screenshot %s.',
                $this->shortId($finding),
                $retestResult,
                $queueResult,
            );

            return $errors === []
                ? $this->redirectMessage($message, '/legacy/findings/'.$finding->getId())
                : $this->redirectMessageAndError($message, implode(' ', $errors), '/legacy/findings/'.$finding->getId());
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    #[Route(path: 'findings/{id}/screenshots', name: 'finding_screenshot_queue', methods: ['POST'])]
    public function queueScreenshot(string $id): Response
    {
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            if ($finding->isDiscarded()) {
                return new Response('Verworfene Fälle werden im normalen Arbeiten ignoriert.', Response::HTTP_CONFLICT);
            }
            $queued = $this->screenshotQueue->enqueue($finding);

            return $this->redirectMessage(sprintf(
                'Screenshot for %s %s.',
                $this->shortId($finding),
                $queued->created ? 'queued' : 'is already '.$queued->job->getStatus(),
            ), '/legacy/findings/'.$finding->getId());
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    #[Route(path: 'legacy/findings/{id}', name: 'finding_show', methods: ['GET'])]
    public function showFinding(string $id, Request $request): Response
    {
        try {
            return new Response($this->renderFindingPage(
                view: $this->findingDetail->get($id),
                message: $request->query->getString('message') ?: null,
                error: $request->query->getString('error') ?: null,
            ));
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    #[Route(path: 'findings/{id}/assessment', name: 'finding_assessment', methods: ['POST'])]
    public function assessFinding(string $id, Request $request): Response
    {
        if (!$this->validCsrf($request, 'finding_assessment_'.$id)) {
            return new Response('Die Bewertung wurde nicht gespeichert. Formular bitte neu laden.', Response::HTTP_FORBIDDEN);
        }
        $parameters = $request->request->all();
        foreach (['assessment', 'discard_reason', 'observation_id', 'evidence_id', 'surface', 'return_to'] as $field) {
            if (array_key_exists($field, $parameters) && !is_string($parameters[$field])) {
                return new Response('Ungültige Bewertungsangaben.', Response::HTTP_BAD_REQUEST);
            }
        }
        $returnPath = $this->findingReturnPath($id, $parameters);

        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $assessment = $request->request->getString('assessment');
            $reason = trim($request->request->getString('discard_reason')) ?: null;
            if (!in_array($assessment, ['confirmed', 'fixed', 'discarded'], true)
                || !in_array($reason, [null, 'duplicate'], true)
            ) {
                return new Response('Ungültige Bewertung oder ungültiger Verwerfungsgrund.', Response::HTTP_BAD_REQUEST);
            }

            // A reference is an explicit choice by the user. Leaving either
            // field empty must never imply that the newest run was reviewed.
            $observationId = trim($request->request->getString('observation_id')) ?: null;
            $evidenceId = trim($request->request->getString('evidence_id')) ?: null;
            if ($observationId !== null) {
                $observation = $this->retestRunRepository->find($observationId);
                if (!$observation instanceof RetestRun || $observation->getFinding()->getId() !== $finding->getId()) {
                    return new Response('Die gewählte Beobachtung gehört nicht zu diesem Fall.', Response::HTTP_BAD_REQUEST);
                }
            }
            if ($evidenceId !== null) {
                $evidence = $this->evidenceRepository->find($evidenceId);
                if (!$evidence instanceof Evidence || $evidence->getFinding()->getId() !== $finding->getId()) {
                    return new Response('Der gewählte Beleg gehört nicht zu diesem Fall.', Response::HTTP_BAD_REQUEST);
                }
            }

            $this->findingService->assess(
                $finding,
                $assessment,
                $assessment === 'discarded' ? $reason : null,
                $observationId,
                $evidenceId,
            );

            return $this->redirectMessage('Bewertung gespeichert: '.$this->assessmentLabel($assessment, $assessment === 'discarded' ? $reason : null).'.', $returnPath);
        } catch (\InvalidArgumentException $exception) {
            return new Response($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage(), $returnPath);
        }
    }

    #[Route(path: 'findings/{id}/notes', name: 'finding_notes', methods: ['POST'])]
    public function updateFindingNotes(string $id, Request $request): Response
    {
        if (!$this->validCsrf($request, 'finding_notes_'.$id)) {
            return new Response('Die Notiz wurde nicht gespeichert. Formular bitte neu laden.', Response::HTTP_FORBIDDEN);
        }
        $parameters = $request->request->all();
        if (!is_string($parameters['notes'] ?? null)
            || (array_key_exists('surface', $parameters) && !is_string($parameters['surface']))
            || (array_key_exists('return_to', $parameters) && !is_string($parameters['return_to']))
        ) {
            return new Response('Ungültige Notizangaben.', Response::HTTP_BAD_REQUEST);
        }
        $returnPath = $this->findingReturnPath($id, $parameters);
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $this->findingService->updateNotes($finding, $parameters['notes']);

            return $this->redirectMessage('Notiz gespeichert.', $returnPath);
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage(), $returnPath);
        }
    }

    #[Route(path: 'findings/{id}/mark-vulnerable', name: 'finding_mark_vulnerable', methods: ['POST'])]
    public function markVulnerable(string $id, Request $request): Response
    {
        if (!$this->validCsrf($request, 'finding_mark_vulnerable_'.$id)) {
            return new Response('Ungültiges Formular. Bitte neu laden.', Response::HTTP_FORBIDDEN);
        }
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $this->findingService->markVulnerable($finding);

            return $this->redirectMessage(sprintf(
                'Befund %s manuell bestätigt.',
                $this->shortId($finding),
            ), '/legacy/findings/'.$finding->getId());
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    #[Route(path: 'findings/{id}/mark-contacted', name: 'finding_mark_contacted', methods: ['POST'])]
    public function markContacted(string $id, Request $request): Response
    {
        if (!$this->validCsrf($request, 'finding_mark_contacted_'.$id)) {
            return new Response('Ungültiges Formular. Bitte neu laden.', Response::HTTP_FORBIDDEN);
        }
        $parameters = $request->request->all();
        foreach (['surface', 'return_to'] as $field) {
            if (array_key_exists($field, $parameters) && !is_string($parameters[$field])) {
                return new Response('Ungültige Kontaktangaben.', Response::HTTP_BAD_REQUEST);
            }
        }
        $returnPath = $this->findingReturnPath($id, $parameters);
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $this->findingService->markContacted($finding);

            $message = sprintf(
                'Kontaktzeitpunkt für %s gespeichert.',
                $this->shortId($finding),
            );
            if (($parameters['surface'] ?? null) === 'studio') {
                return $this->redirectMessage($message, $returnPath);
            }
            $returnTo = trim($request->request->getString('return_to'));
            if (str_starts_with($returnTo, '/operator-priority')) {
                $returnTo = '/legacy'.$returnTo;
            }
            if (preg_match('~^/legacy/operator-priority(?:\?days=[0-9]{1,3})?$~D', $returnTo)) {
                return new RedirectResponse($returnTo.(str_contains($returnTo, '?') ? '&' : '?').'message='.rawurlencode($message));
            }

            return $this->redirectMessage($message, $returnPath);
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage(), $returnPath);
        }
    }

    #[Route(path: 'findings/{id}/confirm-fixed', name: 'finding_confirm_fixed', methods: ['POST'])]
    public function confirmFixed(string $id, Request $request): Response
    {
        if (!$this->validCsrf($request, 'finding_confirm_fixed_'.$id)) {
            return new Response('Ungültiges Formular. Bitte neu laden.', Response::HTTP_FORBIDDEN);
        }
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $this->findingService->confirmFixed($finding);

            return $this->redirectMessage(sprintf(
                'Fall %s manuell als behoben bewertet.',
                $this->shortId($finding),
            ), '/legacy/findings/'.$finding->getId());
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    #[Route(path: 'findings/{id}/delete', name: 'finding_delete', methods: ['POST'])]
    public function deleteFinding(string $id): Response
    {
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $hostname = $finding->getDomain()->getHostname();
            $this->findingService->deleteFinding($finding);

            return $this->redirectMessage(sprintf(
                'Deleted finding %s from %s.',
                $this->shortId($finding),
                $hostname,
            ));
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    #[Route(path: 'artifacts/{path}', name: 'artifact_show', methods: ['GET'], requirements: ['path' => '.+'])]
    public function showArtifact(string $path): Response
    {
        $normalizedPath = ltrim(str_replace('\\', '/', $path), '/');
        if (preg_match('#(^|/)\.\.(?:/|$)#', $normalizedPath)) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }

        if (str_starts_with($normalizedPath, 'storage/artifacts/')) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }

        try {
            $contents = $this->storage->read($normalizedPath);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return new Response('Evidence file is missing or unavailable.', Response::HTTP_NOT_FOUND);
        }

        $mimeType = 'application/octet-stream';
        if (preg_match('/\.(png|jpe?g|gif|webp)$/i', $normalizedPath)) {
            $mimeType = match (strtolower(pathinfo($normalizedPath, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => 'application/octet-stream',
            };
        }

        return new Response($contents, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.basename($normalizedPath).'"',
            'Cache-Control' => 'private, max-age=0, no-cache',
        ]);
    }

    /** @param list<FindingReadView> $findings */
    private function renderHomePage(array $findings, array $stats, array $screenshotStats, string $defaultPayload, FindingReadFilter $filter, array $pagination, ?string $message, ?string $error): string
    {
        $escape = fn (mixed $value): string => $this->escape($value);
        $filterQuery = $this->findingList->filterQuery($filter);
        $pageUrl = static fn (int $page): string => '/legacy?'.http_build_query($filterQuery + ['pageSize' => $pagination['pageSize'], 'page' => $page]).'#findings';
        $studioReturnPath = '/findings?'.http_build_query($filterQuery + ['pageSize' => $pagination['pageSize'], 'page' => $pagination['page']], '', '&', PHP_QUERY_RFC3986);
        ob_start();
        try {
            require dirname(__DIR__, 2).'/templates/home.php';
            $body = ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        return $this->renderLayout(title: 'LibreBugBounty UI', body: $body);
    }

    private function renderLayout(string $title, string $body): string
    {
        $escapedTitle = $this->escape($title);

        return '<!doctype html>'
            .'<html lang="en">'
            .'<head>'
            .'<meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="description" content="LibreBugBounty is a local OpenBugBounty alternative for reflected XSS triage, automated browser verification, and screenshot evidence.">'
            .'<title>'.$escapedTitle.'</title>'
            .'<style>'
            .':root{color-scheme:light;--bg:#f3efe6;--panel:rgba(255,251,244,.88);--text:#1f1a16;--muted:#6d6258;--accent:#0f6d48;--accent-2:#8c5cf6;--border:#e6dcca;--shadow:0 18px 48px rgba(48,32,14,.08)}'
            .'*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--text);background:radial-gradient(circle at top left,rgba(255,255,255,.95),transparent 40%),radial-gradient(circle at 90% 10%,rgba(140,92,246,.10),transparent 30%),radial-gradient(circle at 10% 90%,rgba(15,109,72,.10),transparent 25%),linear-gradient(180deg,#f8f4ec 0%,var(--bg) 100%)}'
            .'a{color:inherit}code,pre{background:rgba(15,109,72,.08);border-radius:10px;padding:2px 6px;white-space:pre-wrap;word-break:break-word}pre{margin:0;padding:10px 12px}main{padding:24px;display:grid;gap:20px}'
            .'.panel{background:var(--panel);backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow);padding:18px}.wide{width:100%}.stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:16px}.stat{padding:14px 16px;border-radius:16px;background:rgba(255,255,255,.66);border:1px solid var(--border);text-decoration:none;color:inherit;display:block}.stat-link{transition:transform .15s ease, box-shadow .15s ease}.stat-link:hover{transform:translateY(-1px);box-shadow:0 8px 22px rgba(48,32,14,.08)}.stat span{display:block;font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:6px}.stat strong{font-size:1.35rem;line-height:1;font-weight:800}'
            .'.filters,form{display:grid;gap:10px}.split{display:grid;gap:10px;grid-template-columns:repeat(2,minmax(0,1fr))}'
            .'label{display:grid;gap:6px;font-size:.92rem;color:var(--muted)}input,select,textarea{width:100%;border:1px solid var(--border);border-radius:12px;padding:10px 12px;font:inherit;background:rgba(255,255,255,.95);color:var(--text)}textarea{min-height:88px;resize:vertical}'
            .'button,.button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;border:0;border-radius:999px;padding:10px 16px;background:var(--accent);color:#fff;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}button:disabled{opacity:.55;cursor:wait}'
            .'button.secondary,.button.secondary{background:var(--accent-2)}button.ghost,.button.ghost{background:transparent;color:var(--accent);border:1px solid rgba(15,109,72,.22)}'
            .'button.danger,.button.danger{background:#b91c1c;color:#fff}'
            .'.actions,.filter-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.inline-form{display:inline-block}.row-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.row-actions button,.row-actions .button{padding:8px 12px;min-height:36px;font-size:.86rem}.section-head{display:grid;gap:10px;margin-bottom:14px;grid-template-columns:1fr auto;align-items:end}.hint{color:var(--muted);font-size:.9rem}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:.95rem}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--border);vertical-align:top}th{font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}.row-link{display:inline-flex;align-items:center;gap:8px;text-decoration:none}.row-link:hover code{text-decoration:underline}.detail-grid{display:grid;gap:18px;grid-template-columns:1.1fr .9fr;align-items:start}.detail-list{display:grid;gap:12px;margin:16px 0 0}.detail-list>div{display:grid;gap:4px;padding:10px 0;border-bottom:1px solid var(--border)}.detail-list dt{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}.detail-list dd{margin:0;font-size:.98rem}.shot-grid{display:grid;gap:12px}.shot-card{margin:0;padding:12px;border:1px solid var(--border);border-radius:16px;background:rgba(255,255,255,.6)}.shot-card img{display:block;width:100%;height:auto;border-radius:12px}.shot-card figcaption{margin-top:8px;font-size:.82rem;color:var(--muted)}.pagination-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:14px 0 0}.pagination-summary{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.per-page-form{display:inline-flex;align-items:center;gap:8px}.per-page-form select{width:auto;min-width:76px}.pagination{display:flex;justify-content:flex-end;align-items:center;gap:12px;flex-wrap:wrap}.pagination-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.pagination-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 16px;border-radius:999px;border:1px solid rgba(15,109,72,.22);text-decoration:none;color:var(--accent);font-weight:700}.pagination-actions a[aria-disabled=\"true\"]{pointer-events:none;opacity:.45}.badge{display:inline-flex;align-items:center;padding:5px 11px;border-radius:999px;font-size:.8rem;font-weight:800;letter-spacing:.01em;border:1px solid transparent;text-transform:none;width:max-content;max-width:100%}.status-pills{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.badge.status.new{background:rgba(59,130,246,.10);color:#1d4ed8}.badge.status.verified{background:rgba(245,158,11,.14);color:#92400e}.badge.status.reported{background:rgba(140,92,246,.12);color:#6d28d9}.badge.status.fixed{background:rgba(15,109,72,.14);color:#0b4d34}.badge.status.wontfix,.badge.status.duplicate{background:rgba(107,114,128,.12);color:#374151}.badge.review.manual_checking{background:rgba(245,158,11,.16);color:#92400e}.badge.review.confirmed_fixed{background:rgba(15,109,72,.16);color:#0b4d34}.badge.meta.contacted{background:rgba(37,99,235,.12);color:#1d4ed8}.badge.bucket.open{background:rgba(30,64,175,.10);color:#1e40af}.badge.bucket.fixed{background:rgba(15,109,72,.10);color:#0b4d34}.badge.bucket.manual_review{background:rgba(217,119,6,.12);color:#b45309}.badge.bucket.unchecked{background:rgba(107,114,128,.10);color:#4b5563}.utility-nav{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;padding:16px 24px 0}.utility-nav a{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 14px;border-radius:999px;border:1px solid rgba(15,109,72,.18);text-decoration:none;color:var(--accent);font-weight:700;background:rgba(255,255,255,.55)}.about-dialog{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(32,22,12,.42);backdrop-filter:blur(8px);z-index:50}.about-dialog:target{display:flex}.about-dialog__panel{width:min(720px,100%);background:var(--panel);border:1px solid var(--border);border-radius:24px;box-shadow:var(--shadow);padding:24px;position:relative}.about-dialog__close{position:absolute;top:16px;right:16px;display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 12px;border-radius:999px;text-decoration:none;border:1px solid rgba(15,109,72,.18);background:rgba(255,255,255,.72);color:var(--accent);font-weight:700}.about-hero{display:grid;gap:10px;padding-right:92px}.about-title{display:grid;gap:4px}.about-title h1{margin:0;font-size:clamp(2rem,5vw,3.2rem);line-height:1.02;letter-spacing:-.03em}.about-version{font-size:1rem;font-weight:700;color:var(--muted)}.about-subtitle{margin:0;font-size:1.02rem;color:var(--muted)}.about-section{display:grid;gap:12px}.about-section p{margin:0;line-height:1.55}.about-section a{color:var(--accent);text-decoration:underline;text-underline-offset:2px;text-decoration-thickness:1px}.about-section a:hover{text-decoration-thickness:2px}.about-note{color:var(--muted);font-size:.96rem}.about-changelog{display:grid;gap:8px;margin:4px 0 0;padding-left:18px;color:var(--text)}.about-changelog li{line-height:1.45}.about-actions{justify-content:center}.about-actions .button{min-width:min(100%,320px);background:var(--accent);color:#f4fff8;box-shadow:0 10px 22px rgba(15,109,72,.18);text-decoration:none}.about-actions .button:hover{filter:brightness(1.04)}'
            .'.notice{padding:12px 14px;border-radius:14px;margin-bottom:16px;border:1px solid transparent}.notice.success{background:rgba(15,109,72,.10);color:#0b4d34;border-color:rgba(15,109,72,.16)}.notice.error{background:rgba(185,28,28,.10);color:#7f1d1d;border-color:rgba(185,28,28,.16)}#intake-history-list .shot-card p{overflow-wrap:anywhere}'
            .'.panel{min-width:0}.table-wrap{max-width:100%}'
            .'.badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:.82rem;font-weight:700;text-transform:lowercase;letter-spacing:.02em;border:1px solid transparent}.badge-stack{display:grid;gap:6px}.badge.status.new{background:rgba(59,130,246,.10);color:#1d4ed8}.badge.status.verified{background:rgba(245,158,11,.12);color:#92400e}.badge.status.reported{background:rgba(140,92,246,.12);color:#6d28d9}.badge.status.fixed{background:rgba(15,109,72,.12);color:#0b4d34}.badge.status.wontfix,.badge.status.duplicate{background:rgba(107,114,128,.12);color:#374151}.badge.review.manual_checking{background:rgba(245,158,11,.14);color:#92400e}.badge.review.confirmed_fixed{background:rgba(15,109,72,.14);color:#0b4d34}.badge.meta.contacted{background:rgba(37,99,235,.12);color:#1d4ed8}'
            .'@media (max-width:720px){main{padding-left:16px;padding-right:16px}.section-head{grid-template-columns:1fr}.split{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}'
            .'@media (max-width:540px){.stats{grid-template-columns:1fr}}'
            .'</style>'
            .'</head>'
            .'<body>'
            .'<div class="utility-nav"><a href="/legacy">Overview</a><a href="/">Studio</a><a href="/findings">Bestand</a><a href="/legacy/operator-priority">Operator-Priorität</a><a href="/legacy/settings">Settings</a><a href="#about-modal">About</a></div>'
            .'<main>'.$body.'</main>'
            .$this->renderAboutModal()
            .'</body>'
            .'</html>';
    }

    private function renderSettingsPage(array $settings): string
    {
        $defaultPayload = (string) ($settings['intake.default_payload'] ?? SettingsService::DEFAULTS['intake.default_payload']);
        $reviewTimeout = (string) ($settings['review.scan_timeout_ms'] ?? SettingsService::DEFAULTS['review.scan_timeout_ms']);

        ob_start();
        ?>
<section class="panel">
  <div class="section-head">
    <div>
      <h2>Settings</h2>
    </div>
  </div>
  <form method="post" action="/settings">
    <label>Default Payload
      <input name="default_payload" value="<?= $this->escape($defaultPayload) ?>" placeholder="OPENBUGBOUNTY">
    </label>
    <label>Review Timeout (ms)
      <input name="review_timeout_ms" inputmode="numeric" value="<?= $this->escape($reviewTimeout) ?>" placeholder="45000">
    </label>
    <p class="hint">New URLs are stored immediately with a queued screenshot. Intake does not run a headless verification; explicit review and retest actions use the timeout below.</p>
    <div class="actions">
      <button type="submit">Save settings</button>
      <a class="button ghost" href="/legacy">Back to overview</a>
    </div>
  </form>
</section>

<section class="panel">
  <div class="section-head">
    <div>
      <h2>What these do</h2>
    </div>
  </div>
  <div class="detail-list">
    <div><dt>Default Payload</dt><dd>Used when no payload is typed in the intake form or CLI defaults.</dd></div>
    <div><dt>Intake processing</dt><dd>Intake confirms durable storage without running a headless check. Screenshot capture remains asynchronous.</dd></div>
    <div><dt>Review Timeout</dt><dd>Browser timeout for the review scan and browser retests.</dd></div>
  </div>
</section>
<?php
        return $this->renderLayout(
            title: 'LibreBugBounty Settings',
            body: ob_get_clean(),
        );
    }

    private function renderAboutModal(): string
    {
        ob_start();
        ?>
<div class="about-dialog" id="about-modal" aria-hidden="true">
  <div class="about-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="about-modal-title">
    <a class="about-dialog__close" href="#" aria-label="Close about modal">Close</a>
    <header class="about-hero">
      <div class="about-title">
        <h1 id="about-modal-title">LibreBugBounty</h1>
        <div class="about-version">v1.0.1</div>
      </div>
      <p class="about-subtitle">Vibe-coded by <a href="https://github.com/tfgrass" target="_blank" rel="noreferrer">tfgrass</a>.</p>
    </header>
    <section class="about-section">
      <p class="about-note">If you like photography, check my <a href="https://www.flickr.com/photos/tkoschka/" target="_blank" rel="noreferrer">Flickr</a>. If you want to build a Raspi DSLM checkout <a href="https://github.com/openDSLM/" target="_blank" rel="noreferrer">openDSLM</a>. My former OpenBugBounty profile was <a href="https://www.openbugbounty.org/researchers/MitRauch/" target="_blank" rel="noreferrer">MitRauch</a>; my current one is <a href="https://www.openbugbounty.org/researchers/tomkoschka/" target="_blank" rel="noreferrer">tomkoschka</a>. If you feel like donating, a <a href="https://www.whitewall.com/us/photo-gifts/gift-certificates" target="_blank" rel="noreferrer">WhiteWall photo lab gift card</a> would be lovely.</p>
      <p class="about-note">v1.0.1 is focused on making the local verification loop feel dependable and repeatable.</p>
      <ul class="about-changelog">
        <li>Evidence refresh preserves existing screenshots, notes, contact history, and stored records.</li>
        <li>Chromium and Firefox retests run in parallel, with better timeout handling for slow targets.</li>
        <li>Finding reports link screenshots directly, and the UI gained a cleaner back path from report views.</li>
        <li>Cron hardening is part of the current direction so repeated scans stay predictable instead of noisy.</li>
      </ul>
      <div class="actions about-actions">
        <a class="button" href="https://github.com/tfgrass/librebugbounty/releases" target="_blank" rel="noreferrer">Check for newer versions</a>
      </div>
    </section>
  </div>
</div>
<?php
        return ob_get_clean();
    }

    private function renderFindingPage(FindingDetailView $view, ?string $message, ?string $error): string
    {
        $finding = $view->finding;
        $evidence = $view->evidence;
        $runs = $view->runs;
        $screenshotJobs = $view->screenshotJobs;
        $assessments = $view->assessments;
        $messageBox = $message ? '<div class="notice success">'.$this->escape($message).'</div>' : '';
        $errorBox = $error ? '<div class="notice error">'.$this->escape($error).'</div>' : '';

        $screenshotCards = [];
        foreach ($view->screenshots as $shot) {
            $item = $shot['evidence'];
            if (!$shot['available']) {
                $screenshotCards[] = '<figure class="shot-card"><p class="notice error">Screenshot file is missing or unavailable. The evidence record has been retained.</p>'
                    .'<figcaption>Stored: '.$this->escape($item->getCreatedAt()->format(DATE_ATOM)).'<br><code>'.$this->escape($item->getFilePath()).'</code></figcaption></figure>';
                continue;
            }

            $captureTime = $shot['capturedAt'] !== null
                ? 'Captured: '.$shot['capturedAt']->format(DATE_ATOM)
                : 'Stored: '.$item->getCreatedAt()->format(DATE_ATOM).' (capture time unavailable)';
            $screenshotCards[] = sprintf(
                '<figure class="shot-card">'
                .'<a href="%s" target="_blank" rel="noreferrer"><img src="%s" alt="Screenshot for %s"></a>'
                .'<figcaption>%s<br><code>%s</code></figcaption>'
                .'</figure>',
                $this->escape($shot['url']),
                $this->escape($shot['url']),
                $this->escape($finding->getId()),
                $this->escape($captureTime),
                $this->escape($item->getFilePath()),
            );
        }

        $screenshotJobRows = [];
        foreach (array_slice($screenshotJobs, 0, 20) as $job) {
            $errorDetails = $job->getErrorMessage() !== null
                ? '<details><summary>Failure details</summary><p>'.$this->escape($job->getErrorMessage()).'</p></details>'
                : '';
            $metadata = $job->getCaptureMetadata() ?? [];
            $challengeDetails = '';
            if (($metadata['challengeDetected'] ?? false) === true) {
                $waitedSeconds = max(0, (int) ($metadata['challengeWaitedMs'] ?? 0)) / 1000;
                $challengeMessage = ($metadata['challengeCleared'] ?? false) === true
                    ? sprintf('Browser protection cleared after %.1f seconds; capture continued on the target page.', $waitedSeconds)
                    : sprintf('Browser protection was still active after %.1f seconds; the screenshot may show the protection page.', $waitedSeconds);
                $challengeDetails = '<details><summary>Browser protection detected</summary><p>'.$this->escape($challengeMessage).'</p></details>';
            }
            $screenshotJobRows[] = sprintf(
                '<tr><td><code>%s</code></td><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td></tr>',
                $this->escape(substr($job->getId(), 0, 8)),
                $this->escape($job->getStatus()),
                $errorDetails.$challengeDetails,
                $this->escape($job->getRequestedAt()->format(DATE_ATOM)),
                $this->escape($job->getStartedAt()?->format(DATE_ATOM) ?? 'n/a'),
                $this->escape($job->getCapturedAt()?->format(DATE_ATOM) ?? 'n/a'),
                $job->getAttempts(),
            );
        }

        $evidenceRows = [];
        foreach ($evidence as $item) {
            $evidenceRows[] = sprintf(
                '<tr>'
                .'<td><code>%s</code></td>'
                .'<td>%s</td>'
                .'<td>%s</td>'
                .'<td>%s</td>'
                .'<td>%s</td>'
                .'</tr>',
                $this->escape(substr($item->getId(), 0, 8)),
                $this->escape($item->getKind()),
                $this->escape($item->getValue() ?? 'n/a'),
                $this->escape($item->getFilePath() ?? 'n/a').($item->getFilePath() !== null ? '<br><span class="hint">'.($this->storage->exists($item->getFilePath()) ? 'Available' : 'Missing or unavailable — record retained').'</span>' : ''),
                $this->escape($item->getCreatedAt()->format(DATE_ATOM)),
            );
        }

        $runRows = [];
        foreach ($runs as $run) {
            $capture = $this->screenshotStatus->forRun($run);
            $captureHtml = '<span'.($capture->isProblem() ? ' class="notice error"' : '').'>'.$this->escape($capture->label).'</span>';
            if ($capture->detail !== null) {
                $captureHtml .= '<details><summary>Capture details</summary><p>'.$this->escape($capture->detail).'</p></details>';
            }
            if ($run->getScreenshotPath() !== null) {
                $captureHtml .= '<br><code>'.$this->escape($run->getScreenshotPath()).'</code>';
            }
            $runRows[] = sprintf(
                '<tr>'
                .'<td><code>%s</code></td>'
                .'<td>%s</td>'
                .'<td>%s</td>'
                .'<td>%s</td>'
                .'<td>%s</td>'
                .'<td>%s</td>'
                .'</tr>',
                $this->escape(substr($run->getId(), 0, 8)),
                $this->escape($run->getMode()),
                $this->escape($run->getResult()).($run->getErrorMessage() !== null ? '<details><summary>Operation error</summary><p>'.$this->escape($run->getErrorMessage()).'</p></details>' : ''),
                $this->escape((string) ($run->getHttpStatus() ?? 'n/a')),
                $this->escape($run->getStartedAt()->format(DATE_ATOM)),
                $captureHtml,
            );
        }

        ob_start();
        ?>
<section class="panel">
  <?= $messageBox ?>
  <?= $errorBox ?>
  <div class="section-head">
    <div>
      <h2>Finding <?= $this->escape($this->shortId($finding)) ?></h2>
    </div>
    <div class="row-actions">
      <a class="button ghost" href="/findings/<?= $this->escape($finding->getId()) ?>">Fall im Studio öffnen</a>
      <a class="button ghost" href="/legacy">Back to overview</a>
    </div>
  </div>
  <div class="detail-grid">
    <div>
      <?= $this->renderAssessmentCard($finding, $runs, $evidence, $view->assessmentState) ?>
      <dl class="detail-list">
        <div><dt>Title</dt><dd><?= $this->escape($finding->getTitle()) ?></dd></div>
        <div><dt>URL</dt><dd><code><?= $this->escape($finding->getUrl()) ?></code></dd></div>
        <div><dt>Payload</dt><dd><code><?= $this->escape($finding->getExpectedEvidence() ?? 'n/a') ?></code></dd></div>
        <div><dt>Submitted</dt><dd><?= $this->escape($finding->getSubmittedAt()?->format(DATE_ATOM) ?? 'n/a') ?></dd></div>
        <div><dt>Notes</dt><dd><?= nl2br($this->escape($finding->getPrivateNotes() ?? 'n/a')) ?></dd></div>
        <div><dt>Contacted</dt><dd><?= $this->escape($finding->getContactedAt()?->format(DATE_ATOM) ?? 'n/a') ?></dd></div>
        <div><dt>Last Recheck</dt><dd><?= $this->escape($finding->getLastRetestedAt()?->format(DATE_ATOM) ?? 'n/a') ?></dd></div>
      </dl>
      <div class="row-actions">
        <?= $this->findingActions($finding) ?>
      </div>
    </div>
    <div>
      <h3>Screenshots</h3>
      <p class="hint">Stored images may belong to an earlier observation. Storage time is not the capture time.</p>
      <?php if ($screenshotJobs !== []): ?>
        <p class="<?= $screenshotJobs[0]->getStatus() === ScreenshotJobStatus::FAILED ? 'notice error' : 'hint' ?>">Latest screenshot job: <?= $this->escape($screenshotJobs[0]->getStatus()) ?></p>
      <?php endif; ?>
      <?php if ($runs !== []): ?>
        <?php $latestCapture = $this->screenshotStatus->forRun($runs[0]); ?>
        <p class="<?= $latestCapture->isProblem() ? 'notice error' : 'hint' ?>">Latest recorded run: <?= $this->escape($latestCapture->label) ?></p>
      <?php endif; ?>
      <?php if ($screenshotCards === []): ?>
        <p class="hint">No screenshots yet.</p>
      <?php else: ?>
        <div class="shot-grid"><?= implode('', $screenshotCards) ?></div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?= $this->renderAssessmentHistory($assessments) ?>

<section class="panel wide">
  <div class="section-head">
    <div>
      <h2>Screenshot Queue</h2>
      <p class="hint">Screenshots run serially in the background and never change the finding assessment.</p>
    </div>
    <?php if (!$finding->isDiscarded()): ?>
      <form method="post" action="/findings/<?= $this->escape($finding->getId()) ?>/screenshots" class="inline-form"><button type="submit">Queue Screenshot</button></form>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>Status</th><th>Requested</th><th>Started</th><th>Captured</th><th>Attempts</th></tr></thead>
      <tbody><?= $this->rowsOrEmpty($screenshotJobRows, 6, 'No screenshot job yet') ?></tbody>
    </table>
  </div>
</section>

<section class="panel wide">
  <div class="section-head">
    <div>
      <h2>Evidence</h2>
      <p class="hint">All stored artifacts for this finding.</p>
    </div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>Kind</th><th>Value</th><th>File</th><th>Created</th></tr></thead>
      <tbody><?= $this->rowsOrEmpty($evidenceRows, 5, 'No evidence yet') ?></tbody>
    </table>
  </div>
</section>

<section class="panel wide">
  <div class="section-head">
    <div>
      <h2>Retest Runs</h2>
      <p class="hint">Browser verification history.</p>
    </div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>Mode</th><th>Result</th><th>HTTP</th><th>Started</th><th>Screenshot</th></tr></thead>
      <tbody><?= $this->rowsOrEmpty($runRows, 6, 'No retest runs yet') ?></tbody>
    </table>
  </div>
</section>
<?php
        return $this->renderLayout(
            title: sprintf('LibreBugBounty #%s', $this->shortId($finding)),
            body: ob_get_clean(),
        );
    }

    private function findingActions(Finding $finding): string
    {
        $id = $this->escape($finding->getId());
        $actions = '';
        if ($finding->getContactedAt() === null) {
            $actions .= '<form method="post" action="/findings/'.$id.'/mark-contacted" class="inline-form">'
                .$this->csrfField('finding_mark_contacted_'.$finding->getId())
                .'<button type="submit" class="secondary">Kontaktiert</button></form>';
        }
        if (!$finding->isDiscarded()) {
            $actions .= '<form method="post" action="/findings/'.$id.'/retest" class="inline-form"><button type="submit">Recheck + Queue Screenshot</button></form>';
        }
        $actions .= '<form method="post" action="/findings/'.$id.'/delete" class="inline-form"><button type="submit" class="danger">Delete</button></form>';

        return $actions;
    }

    /**
     * @param list<RetestRun> $runs
     * @param list<Evidence> $evidence
     */
    private function renderAssessmentCard(Finding $finding, array $runs, array $evidence, FindingAssessmentState $state): string
    {
        $assessment = $finding->getManualAssessment();
        $latestRun = $state->latestRun;
        $latestAt = $state->latestAt;
        $newerObservation = $state->newerObservation;
        $needsConfirmation = $state->needsConfirmation;
        $canConfirm = $state->canConfirm;

        ob_start();
        ?>
<div class="assessment-card" id="assessment">
  <h3>Bewertung</h3>
  <?php if ($assessment !== null): ?>
    <p><strong><?= $this->escape($this->assessmentLabel($assessment, $finding->getDiscardReason())) ?></strong><br>
      <span class="hint">Manuell · <?= $this->escape($finding->getAssessedAt()?->format(DATE_ATOM) ?? 'Zeitpunkt unbekannt') ?></span>
    </p>
  <?php else: ?>
    <p><?= $this->escape(FindingReadLabels::assessment(null)) ?>.</p>
    <?php if ($finding->getStatus() !== FindingStatus::NEW || $finding->getReviewState() !== null): ?>
      <p class="hint">Altbestand · Herkunft unklar: Status <code><?= $this->escape($finding->getStatus()) ?></code>, Review <code><?= $this->escape($finding->getReviewState() ?? 'unbekannt') ?></code>. Zeitpunkt und Entscheidungsgrundlage unbekannt.</p>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($finding->isDiscarded()): ?>
    <p class="hint">Dieser Fall wird im normalen Arbeiten ignoriert. Neue technische Beobachtungen reaktivieren ihn nicht.</p>
  <?php endif; ?>
  <h4>Letzte technische Beobachtung</h4>
  <?php if ($latestRun !== null): ?>
    <p><?= $this->escape(FindingReadLabels::observation($latestRun->getResult())) ?> · <?= $this->escape($latestAt?->format(DATE_ATOM)) ?><br>
      <span class="hint">Technischer Lauf · <?= $this->escape($latestRun->getMode()) ?> · <code><?= $this->escape($latestRun->getId()) ?></code></span>
    </p>
    <?php if ($needsConfirmation): ?>
      <p class="hint">Manuelle Beurteilung erforderlich. Ein uneindeutiges Ergebnis bedeutet keine Behebung.</p>
    <?php endif; ?>
    <?php if ($assessment !== null): ?>
      <p class="notice success"><?= $newerObservation ? 'Neue technische' : 'Technische' ?> Beobachtung vom <?= $this->escape($latestAt?->format(DATE_ATOM)) ?>. Deine Bewertung bleibt erhalten.</p>
    <?php endif; ?>
  <?php else: ?>
    <p class="hint"><?= $this->escape(FindingReadLabels::observation(null)) ?>.</p>
  <?php endif; ?>
  <form method="post" action="/findings/<?= $this->escape($finding->getId()) ?>/assessment" id="assessment-form">
    <?= $this->csrfField('finding_assessment_'.$finding->getId()) ?>
    <details>
      <summary>Bewertungsgrundlage auswählen (optional)</summary>
      <p class="hint">Nur auswählen, wenn du diese Beobachtung oder diesen Beleg tatsächlich beurteilt hast. Ohne Auswahl bleibt die Grundlage unbekannt.</p>
      <label>Beurteilte Beobachtung
        <select name="observation_id">
          <option value="">Unbekannt / keine konkrete Beobachtung</option>
          <?php foreach ($runs as $run): ?>
            <option value="<?= $this->escape($run->getId()) ?>"><?= $this->escape(($run->getFinishedAt() ?? $run->getStartedAt())->format(DATE_ATOM).' · '.$run->getResult().' · '.$run->getMode().' · '.substr($run->getId(), 0, 8)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Beurteilter Beleg
        <select name="evidence_id">
          <option value="">Unbekannt / kein konkreter Beleg</option>
          <?php foreach ($evidence as $item): ?>
            <option value="<?= $this->escape($item->getId()) ?>"><?= $this->escape($item->getCreatedAt()->format(DATE_ATOM).' · '.$item->getKind().' · '.substr($item->getId(), 0, 8)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </details>
    <label>Grund für Verwerfen
      <select name="discard_reason"><option value="">Ohne besonderen Grund</option><option value="duplicate">Duplikat</option></select>
    </label>
    <div class="row-actions">
      <?php if ($canConfirm): ?>
        <button type="submit" name="assessment" value="confirmed" class="secondary">Bestätigen</button>
      <?php endif; ?>
      <?php if ($state->canMarkFixed): ?>
        <button type="submit" name="assessment" value="fixed" class="ghost">Behoben</button>
      <?php endif; ?>
      <?php if ($state->canDiscard): ?>
        <button type="submit" name="assessment" value="discarded" class="ghost">Verwerfen</button>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php
        return ob_get_clean();
    }

    /** @param list<FindingAssessment> $assessments */
    private function renderAssessmentHistory(array $assessments): string
    {
        $rows = [];
        foreach ($assessments as $assessment) {
            $basis = [];
            $snapshot = $assessment->getReferenceSnapshot() ?? [];
            $observationId = $assessment->getObservationId();
            $evidenceId = $assessment->getEvidenceId();
            if ($observationId !== null) {
                $basis[] = 'Beobachtung: <code>'.$this->escape($observationId).'</code>';
                if (isset($snapshot['observation'])) {
                    $observation = $snapshot['observation'];
                    $basis[] = $this->escape(($observation['finishedAt'] ?? $observation['startedAt'] ?? 'Zeitpunkt unbekannt')
                        .' · '.($observation['result'] ?? 'Ergebnis unbekannt').' · '.($observation['mode'] ?? 'Herkunft unbekannt'));
                }
            } else {
                $basis[] = 'Beobachtungsbezug unbekannt.';
            }
            if ($evidenceId !== null) {
                $basis[] = 'Beleg: <code>'.$this->escape($evidenceId).'</code>';
                if (isset($snapshot['evidence'])) {
                    $item = $snapshot['evidence'];
                    $basis[] = 'Ablagezeit: '.$this->escape($item['storedAt'] ?? 'unbekannt').' · '.$this->escape($item['kind'] ?? 'Art unbekannt');
                }
            } else {
                $basis[] = 'Belegbezug unbekannt.';
            }
            $rows[] = '<tr><td>'.$this->escape($this->assessmentLabel($assessment->getAssessment(), $assessment->getDiscardReason())).'</td>'
                .'<td>'.$this->escape($assessment->getAssessedAt()->format(DATE_ATOM)).'</td>'
                .'<td>'.$this->escape($assessment->getSource() === 'manual' ? 'Manuell' : $assessment->getSource()).'</td>'
                .'<td>'.implode('<br>', $basis).'</td></tr>';
        }

        return '<section class="panel wide" id="assessment-history"><h2>Bewertungshistorie</h2>'
            .'<p class="hint">Ausdrückliche Bewertungsänderungen. Historische Entscheidungen mit unbekannter Herkunft werden nicht nachträglich erfunden.</p>'
            .'<div class="table-wrap"><table><thead><tr><th>Bewertung</th><th>Zeitpunkt</th><th>Herkunft</th><th>Grundlage</th></tr></thead>'
            .'<tbody>'.$this->rowsOrEmpty($rows, 4, 'Noch keine Bewertungsänderung aufgezeichnet.').'</tbody></table></div></section>';
    }

    private function assessmentLabel(string $assessment, ?string $reason = null): string
    {
        return FindingReadLabels::assessment($assessment, $reason);
    }

    private function csrfField(string $tokenId): string
    {
        return '<input type="hidden" name="_token" value="'.$this->escape($this->csrf->getToken($tokenId)->getValue()).'">';
    }

    private function validCsrf(Request $request, string $tokenId): bool
    {
        $token = $request->request->all()['_token'] ?? null;

        return is_string($token) && $this->csrf->isTokenValid(new CsrfToken($tokenId, $token));
    }

    private function redirectMessage(string $message, string $path = '/legacy'): RedirectResponse
    {
        return new RedirectResponse($path.(str_contains($path, '?') ? '&' : '?').'message='.rawurlencode($message));
    }

    private function redirectMessageAndError(string $message, string $error, string $path = '/legacy'): RedirectResponse
    {
        return new RedirectResponse($path.(str_contains($path, '?') ? '&' : '?').http_build_query(['message' => $message, 'error' => $error], '', '&', PHP_QUERY_RFC3986));
    }

    private function redirectRunResult(RetestRun $run, string $message): RedirectResponse
    {
        $capture = $this->screenshotStatus->forRun($run);
        $params = ['message' => $message.' '.$capture->label.'.'];
        if ($capture->isProblem()) {
            // Keep the successful save distinct from the failed capture. Raw
            // diagnostics stay on the detail page instead of entering the URL.
            $params['error'] = $capture->label.'. See the finding details for capture information.';
        }

        return new RedirectResponse('/legacy?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    private function findingReturnPath(string $id, array $parameters): string
    {
        return $this->navigation->findingReturnPath($id, $parameters);
    }

    private function redirectError(string $error, string $path = '/legacy'): RedirectResponse
    {
        return new RedirectResponse($path.(str_contains($path, '?') ? '&' : '?').'error='.rawurlencode($error));
    }

    private function rowsOrEmpty(array $rows, int $colspan, string $message): string
    {
        if ($rows === []) {
            return '<tr><td colspan="'.$colspan.'" class="hint">'.$this->escape($message).'</td></tr>';
        }

        return implode('', $rows);
    }

    private function shortId(Finding $finding): string
    {
        return substr($finding->getId(), 0, 8);
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
