<?php

namespace App\Controller;

use App\Entity\Finding;
use App\Entity\Evidence;
use App\Entity\RetestRun;
use App\Repository\EvidenceRepository;
use App\Repository\FindingRepository;
use App\Repository\RetestRunRepository;
use App\Service\FindingService;
use App\Service\SettingsService;
use App\Service\RetestService;
use App\Value\FindingStatus;
use App\Value\EvidenceKind;
use App\Value\ReviewState;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/')]
final class WebController
{
    public function __construct(
        private readonly FindingService $findingService,
        private readonly RetestService $retestService,
        private readonly SettingsService $settings,
        private readonly FindingRepository $findings,
        private readonly EvidenceRepository $evidenceRepository,
        private readonly RetestRunRepository $retestRunRepository,
    ) {
    }

    #[Route(name: 'home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        $domainQuery = trim($request->query->getString('domain'));
        $statusSelection = trim($request->query->getString('status'));
        $bucketFilter = trim($request->query->getString('bucket'));
        $page = max(1, $request->query->getInt('page', 1));
        $pageSizeSelection = strtolower(trim($request->query->getString('pageSize', '10')));
        if (!in_array($pageSizeSelection, ['10', '25', '50', '100', 'all'], true)) {
            $pageSizeSelection = '10';
        }

        if ($bucketFilter === '' && in_array($statusSelection, ['open', 'manual_review', 'unchecked'], true)) {
            $bucketFilter = $statusSelection;
            $statusSelection = '';
        }

        $statusFilter = in_array($statusSelection, ['new', 'verified', 'fixed'], true) ? $statusSelection : '';

        $totalFiltered = $this->findings->countByDomainAndStatus(
            $domainQuery !== '' ? $domainQuery : null,
            $statusFilter !== '' ? $statusFilter : null,
            $bucketFilter !== '' ? $bucketFilter : null,
        );
        $pageSize = $pageSizeSelection === 'all' ? max(1, $totalFiltered) : (int) $pageSizeSelection;
        $totalPages = max(1, (int) ceil($totalFiltered / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;

        $findings = $this->findings->findPageByDomainAndStatus(
            $domainQuery !== '' ? $domainQuery : null,
            $statusFilter !== '' ? $statusFilter : null,
            $bucketFilter !== '' ? $bucketFilter : null,
            $pageSize,
            $offset,
        );
        $stats = [
            'total' => $this->findings->countAllFindings(),
            'open' => $this->findings->countByBucket('open'),
            'fixed' => $this->findings->countByBucket('fixed'),
            'manual_review' => $this->findings->countByBucket('manual_review'),
            'unchecked' => $this->findings->countByBucket('unchecked'),
        ];

        return new Response($this->renderHomePage(
            findings: $findings,
            stats: $stats,
            defaultPayload: $this->settings->getDefaultPayload(),
            autoVerifyMode: $this->settings->getAutoVerifyMode(),
            filters: [
                'domain' => $domainQuery,
                'status' => $statusFilter,
                'bucket' => $bucketFilter,
                'selection' => $bucketFilter !== '' ? $bucketFilter : $statusFilter,
                'page' => $page,
                'pageSize' => $pageSizeSelection,
                'totalFiltered' => $totalFiltered,
                'totalPages' => $totalPages,
            ],
            message: $request->query->getString('message') ?: null,
            error: $request->query->getString('error') ?: null,
        ));
    }

    #[Route(path: 'findings', name: 'finding_create', methods: ['POST'])]
    public function createFinding(Request $request): Response
    {
        try {
            $payload = trim($request->request->getString('payload'));
            $finding = $this->findingService->createFinding(
                url: $request->request->getString('url'),
                expectedEvidence: $payload !== '' ? $payload : null,
                privateNotes: $request->request->getString('annotate') ?: null,
            );

            if ($this->settings->getAutoVerifyMode() === 'submit') {
                try {
                    $run = $this->retestService->retest($finding, true);

                    return $this->redirectMessage(sprintf(
                        'Finding %s stored for %s. Auto verification: %s.',
                        $this->shortId($finding),
                        $finding->getDomain()->getHostname(),
                        $run->getResult(),
                    ));
                } catch (\Throwable $verificationException) {
                    return $this->redirectError(sprintf(
                        'Finding %s stored for %s, but auto verification failed: %s',
                        $this->shortId($finding),
                        $finding->getDomain()->getHostname(),
                        $verificationException->getMessage(),
                    ));
                }
            }

            return $this->redirectMessage(sprintf(
                'Finding %s stored for %s. Auto verification is set to cron only.',
                $this->shortId($finding),
                $finding->getDomain()->getHostname(),
            ));
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    #[Route(path: 'settings', name: 'settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $payload = trim($request->request->getString('default_payload'));
            $autoVerifyMode = $request->request->getString('auto_verify_mode');
            $timeoutMs = trim($request->request->getString('review_timeout_ms'));
            $concurrency = trim($request->request->getString('review_concurrency'));

            if ($payload === '') {
                $payload = SettingsService::DEFAULTS['intake.default_payload'];
            }
            if (!in_array($autoVerifyMode, ['submit', 'cron_only'], true)) {
                $autoVerifyMode = SettingsService::DEFAULTS['intake.auto_verify_mode'];
            }
            if ($timeoutMs === '' || !ctype_digit($timeoutMs) || (int) $timeoutMs < 1000) {
                $timeoutMs = SettingsService::DEFAULTS['review.scan_timeout_ms'];
            }
            if ($concurrency === '' || !ctype_digit($concurrency) || (int) $concurrency < 1) {
                $concurrency = SettingsService::DEFAULTS['review.scan_concurrency'];
            }

            $this->settings->save([
                'intake.default_payload' => $payload,
                'intake.auto_verify_mode' => $autoVerifyMode,
                'review.scan_timeout_ms' => $timeoutMs,
                'review.scan_concurrency' => $concurrency,
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
        return $this->redirectToRoute('home', ['_fragment' => 'about-modal']);
    }

    #[Route(path: 'findings/{id}/retest', name: 'finding_retest', methods: ['POST'])]
    public function retestFinding(string $id): Response
    {
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $run = $this->retestService->retest($finding, true);

            return $this->redirectMessage(sprintf(
                'Retest finished for %s: %s.',
                $this->shortId($finding),
                $run->getResult(),
            ));
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    #[Route(path: 'findings/{id}', name: 'finding_show', methods: ['GET'])]
    public function showFinding(string $id, Request $request): Response
    {
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $evidence = $this->pruneMissingScreenshotEvidence(
                $this->evidenceRepository->findBy(['finding' => $finding], ['createdAt' => 'DESC'])
            );

            return new Response($this->renderFindingPage(
                finding: $finding,
                evidence: $evidence,
                runs: $this->retestRunRepository->findRecentByFinding($finding, 20),
                message: $request->query->getString('message') ?: null,
                error: $request->query->getString('error') ?: null,
            ));
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    #[Route(path: 'findings/{id}/mark-vulnerable', name: 'finding_mark_vulnerable', methods: ['POST'])]
    public function markVulnerable(string $id): Response
    {
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $this->findingService->markVulnerable($finding);

            return $this->redirectMessage(sprintf(
                'Marked %s as vulnerable and queued it for manual review.',
                $this->shortId($finding),
            ));
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    #[Route(path: 'findings/{id}/confirm-fixed', name: 'finding_confirm_fixed', methods: ['POST'])]
    public function confirmFixed(string $id): Response
    {
        try {
            $finding = $this->findingService->getFindingOrFail($id);
            $this->findingService->confirmFixed($finding);

            return $this->redirectMessage(sprintf(
                'Confirmed %s as fixed.',
                $this->shortId($finding),
            ));
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

        $absolutePath = dirname(__DIR__, 2).'/storage/artifacts/'.$normalizedPath;
        if (!is_file($absolutePath)) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }

        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }

        $mimeType = 'application/octet-stream';
        if (preg_match('/\.(png|jpe?g|gif|webp)$/i', $absolutePath)) {
            $mimeType = match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => 'application/octet-stream',
            };
        }

        return new Response($contents, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.basename($absolutePath).'"',
            'Cache-Control' => 'private, max-age=0, no-cache',
        ]);
    }

    private function renderHomePage(array $findings, array $stats, string $defaultPayload, string $autoVerifyMode, array $filters, ?string $message, ?string $error): string
    {
        $messageBox = $message ? '<div class="notice success">'.$this->escape($message).'</div>' : '';
        $errorBox = $error ? '<div class="notice error">'.$this->escape($error).'</div>' : '';
        $currentPage = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = max(1, (int) ($filters['pageSize'] ?? 50));
        $totalFiltered = max(0, (int) ($filters['totalFiltered'] ?? 0));
        $totalPages = max(1, (int) ($filters['totalPages'] ?? 1));
        $domainFilter = (string) ($filters['domain'] ?? '');
        $statusFilter = (string) ($filters['status'] ?? '');
        $bucketFilter = (string) ($filters['bucket'] ?? '');
        $selectedFilter = (string) ($filters['selection'] ?? '');
        $selectedPageSize = (string) ($filters['pageSize'] ?? '10');
        if (!in_array($selectedPageSize, ['10', '25', '50', '100', 'all'], true)) {
            $selectedPageSize = '10';
        }
        $firstItem = $totalFiltered === 0 ? 0 : (($currentPage - 1) * $pageSize) + 1;
        $lastItem = min($totalFiltered, $currentPage * $pageSize);

        $buildPageUrl = function (int $page, ?string $bucketOverride = null) use ($domainFilter, $statusFilter, $bucketFilter, $selectedPageSize): string {
            $query = ['page' => $page];
            if ($domainFilter !== '') {
                $query['domain'] = $domainFilter;
            }
            if ($statusFilter !== '') {
                $query['status'] = $statusFilter;
            }
            $effectiveBucket = $bucketOverride ?? $bucketFilter;
            if ($effectiveBucket !== '') {
                $query['bucket'] = $effectiveBucket;
            }
            if (in_array($selectedPageSize, ['10', '25', '50', '100', 'all'], true)) {
                $query['pageSize'] = $selectedPageSize;
            }

            return '/?'.http_build_query($query);
        };

        $findingRows = [];
        foreach ($findings as $finding) {
            \assert($finding instanceof Finding);
            $findingRows[] = sprintf(
                '<tr>'
                .'<td><a class="row-link" href="/findings/%s"><code>%s</code></a></td>'
                .'<td><a class="row-link" href="/findings/%s"><code>%s</code></a></td>'
                .'<td>%s</td>'
                .'<td>%s</td>'
                .'<td>%s</td>'
                .'</tr>',
                $this->escape($finding->getId()),
                $this->escape($this->shortId($finding)),
                $this->escape($finding->getId()),
                $this->escape($finding->getDomain()->getHostname()),
                $this->statusCell($finding),
                $this->escape($finding->getSubmittedAt()?->format(DATE_ATOM) ?? 'n/a'),
                $this->escape($finding->getLastRetestedAt()?->format(DATE_ATOM) ?? 'n/a'),
            );
        }

        $findingTableRows = $this->rowsOrEmpty($findingRows, 5, 'No findings yet');
        $pagination = '';
        $prevDisabled = $currentPage <= 1 ? ' aria-disabled="true" class="button ghost"' : '';
        $nextDisabled = $currentPage >= $totalPages ? ' aria-disabled="true" class="button ghost"' : '';
        $pageSizeForm = '<form method="get" action="/#findings" class="per-page-form">'
            .($domainFilter !== '' ? '<input type="hidden" name="domain" value="'.$this->escape($domainFilter).'">' : '')
            .($statusFilter !== '' ? '<input type="hidden" name="status" value="'.$this->escape($statusFilter).'">' : '')
            .($bucketFilter !== '' ? '<input type="hidden" name="bucket" value="'.$this->escape($bucketFilter).'">' : '')
            .'<input type="hidden" name="page" value="1">'
            .'<label class="sr-only" for="page-size-select">Rows per page</label>'
            .'<select id="page-size-select" name="pageSize" onchange="this.form.submit()">'
            .'<option value="10"'.($selectedPageSize === '10' ? ' selected' : '').'>10</option>'
            .'<option value="25"'.($selectedPageSize === '25' ? ' selected' : '').'>25</option>'
            .'<option value="50"'.($selectedPageSize === '50' ? ' selected' : '').'>50</option>'
            .'<option value="all"'.($selectedPageSize === 'all' ? ' selected' : '').'>All</option>'
            .'</select>'
            .'</form>';
        $showingLine = sprintf(
            '<div class="pagination-summary">Showing %s of %d findings</div>',
            $pageSizeForm,
            $totalFiltered,
        );
        if ($selectedPageSize !== 'all' && $totalPages > 1) {
            $pagination = '<div class="pagination">'
                .'<div class="pagination-actions">'
                .($totalPages > 1 ? '<a href="'.$this->escape($currentPage > 1 ? $buildPageUrl($currentPage - 1) : '#').'#findings"'.$prevDisabled.'>Previous</a>' : '')
                .($totalPages > 1 ? '<a href="'.$this->escape($currentPage < $totalPages ? $buildPageUrl($currentPage + 1) : '#').'#findings"'.$nextDisabled.'>Next</a>' : '')
                .'</div>'
                .'</div>';
        }

        ob_start();
        ?>
<section class="panel">
  <?= $messageBox ?>
  <?= $errorBox ?>
  <div class="stats">
    <a class="stat stat-link" href="<?= $this->escape($buildPageUrl(1, '')) ?>"><span>Total</span><strong><?= $this->escape($stats['total']) ?></strong></a>
    <a class="stat stat-link" href="<?= $this->escape($buildPageUrl(1, 'open')) ?>"><span>Open</span><strong><?= $this->escape($stats['open']) ?></strong></a>
    <a class="stat stat-link" href="<?= $this->escape($buildPageUrl(1, 'fixed')) ?>"><span>Fixed</span><strong><?= $this->escape($stats['fixed']) ?></strong></a>
    <a class="stat stat-link" href="<?= $this->escape($buildPageUrl(1, 'manual_review')) ?>"><span>Manual Review</span><strong><?= $this->escape($stats['manual_review']) ?></strong></a>
    <a class="stat stat-link" href="<?= $this->escape($buildPageUrl(1, 'unchecked')) ?>"><span>Unchecked</span><strong><?= $this->escape($stats['unchecked']) ?></strong></a>
  </div>
  <div class="section-head">
    <div>
      <h2>Intake</h2>
    </div>
  </div>
  <form method="post" action="/findings">
    <label>URL <input name="url" placeholder="https://example.com/search?q=%3Csvg%20onload=alert(1)%3E" required></label>
    <label>Payload <input name="payload" placeholder="<?= $this->escape($defaultPayload) ?>" value="<?= $this->escape($defaultPayload) ?>"></label>
    <p class="hint" id="payload-hint">Your XSS must display <code data-payload-token><?= $this->escape($defaultPayload) ?></code> in a JS popup, for example: <code>&lt;script&gt;alert('<span data-payload-token><?= $this->escape($defaultPayload) ?></span>')&lt;/script&gt;</code> or <code>&lt;img src=x onerror=prompt(/<span data-payload-token><?= $this->escape($defaultPayload) ?></span>/)&gt;</code></p>
    <label>Notes <textarea name="annotate" placeholder="Optional note."></textarea></label>
    <button type="submit"><?= $this->escape($autoVerifyMode === 'cron_only' ? 'Save' : 'Save and Verify') ?></button>
    <script>
(() => {
  const payloadInput = document.querySelector('input[name="payload"]');
  const tokens = document.querySelectorAll('[data-payload-token]');
  const fallback = <?= json_encode($defaultPayload) ?>;
  const update = () => {
    const value = (payloadInput && payloadInput.value ? payloadInput.value.trim() : '') || fallback;
    tokens.forEach((token) => {
      token.textContent = value;
    });
  };
  if (payloadInput) {
    payloadInput.addEventListener('input', update);
  }
  update();
})();
    </script>
  </form>
</section>

<section class="panel wide" id="findings">
  <div class="section-head">
    <div>
      <h2>Findings</h2>
      <p class="hint">Compact overview for intake and rechecks.</p>
    </div>
  </div>
  <form method="get" action="/#findings" class="filters">
    <div class="split">
      <label>Domain <input name="domain" value="<?= $this->escape($domainFilter) ?>" placeholder="example.com"></label>
      <label>Status / Bucket
        <select name="status" onchange="this.form.submit()">
          <option value="">Any status</option>
          <optgroup label="Buckets">
            <?php foreach (['open' => 'Open', 'manual_review' => 'Manual Review', 'unchecked' => 'Unchecked'] as $value => $label): ?>
              <option value="<?= $this->escape($value) ?>"<?= $selectedFilter === $value ? ' selected' : '' ?>><?= $this->escape($label) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="Statuses">
            <?php foreach (['new' => 'New', 'verified' => 'Verified', 'fixed' => 'Fixed'] as $value => $label): ?>
              <option value="<?= $this->escape($value) ?>"<?= $selectedFilter === $value ? ' selected' : '' ?>><?= $this->escape($label) ?></option>
            <?php endforeach; ?>
          </optgroup>
        </select>
      </label>
    </div>
    <input type="hidden" name="pageSize" value="<?= $this->escape($selectedPageSize) ?>">
    <div class="filter-actions">
      <button type="submit">Search</button>
      <a class="button ghost" href="/">Reset</a>
    </div>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>Domain</th><th>Status</th><th>Submitted</th><th>Last Recheck</th></tr></thead>
      <tbody><?= $findingTableRows ?></tbody>
    </table>
  </div>
  <div class="pagination-footer">
    <?= $showingLine ?>
    <?= $pagination ?>
  </div>
</section>
<?php
        return $this->renderLayout(
            title: 'LibreBugBounty UI',
            body: ob_get_clean(),
        );
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
            .'button,.button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;border:0;border-radius:999px;padding:10px 16px;background:var(--accent);color:#fff;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}'
            .'button.secondary,.button.secondary{background:var(--accent-2)}button.ghost,.button.ghost{background:transparent;color:var(--accent);border:1px solid rgba(15,109,72,.22)}'
            .'button.danger,.button.danger{background:#b91c1c;color:#fff}'
            .'.actions,.filter-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.inline-form{display:inline-block}.row-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.row-actions button,.row-actions .button{padding:8px 12px;min-height:36px;font-size:.86rem}.section-head{display:grid;gap:10px;margin-bottom:14px;grid-template-columns:1fr auto;align-items:end}.hint{color:var(--muted);font-size:.9rem}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:.95rem}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--border);vertical-align:top}th{font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}.row-link{display:inline-flex;align-items:center;gap:8px;text-decoration:none}.row-link:hover code{text-decoration:underline}.detail-grid{display:grid;gap:18px;grid-template-columns:1.1fr .9fr;align-items:start}.detail-list{display:grid;gap:12px;margin:16px 0 0}.detail-list>div{display:grid;gap:4px;padding:10px 0;border-bottom:1px solid var(--border)}.detail-list dt{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}.detail-list dd{margin:0;font-size:.98rem}.shot-grid{display:grid;gap:12px}.shot-card{margin:0;padding:12px;border:1px solid var(--border);border-radius:16px;background:rgba(255,255,255,.6)}.shot-card img{display:block;width:100%;height:auto;border-radius:12px}.shot-card figcaption{margin-top:8px;font-size:.82rem;color:var(--muted)}.pagination-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:14px 0 0}.pagination-summary{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.per-page-form{display:inline-flex;align-items:center;gap:8px}.per-page-form select{width:auto;min-width:76px}.pagination{display:flex;justify-content:flex-end;align-items:center;gap:12px;flex-wrap:wrap}.pagination-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.pagination-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 16px;border-radius:999px;border:1px solid rgba(15,109,72,.22);text-decoration:none;color:var(--accent);font-weight:700}.pagination-actions a[aria-disabled=\"true\"]{pointer-events:none;opacity:.45}.badge{display:inline-flex;align-items:center;padding:5px 11px;border-radius:999px;font-size:.8rem;font-weight:800;letter-spacing:.01em;border:1px solid transparent;text-transform:none;width:max-content;max-width:100%}.status-pills{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.badge.status.new{background:rgba(59,130,246,.10);color:#1d4ed8}.badge.status.verified{background:rgba(245,158,11,.14);color:#92400e}.badge.status.reported{background:rgba(140,92,246,.12);color:#6d28d9}.badge.status.fixed{background:rgba(15,109,72,.14);color:#0b4d34}.badge.status.wontfix,.badge.status.duplicate{background:rgba(107,114,128,.12);color:#374151}.badge.review.manual_checking{background:rgba(245,158,11,.16);color:#92400e}.badge.review.confirmed_fixed{background:rgba(15,109,72,.16);color:#0b4d34}.badge.bucket.open{background:rgba(30,64,175,.10);color:#1e40af}.badge.bucket.fixed{background:rgba(15,109,72,.10);color:#0b4d34}.badge.bucket.manual_review{background:rgba(217,119,6,.12);color:#b45309}.badge.bucket.unchecked{background:rgba(107,114,128,.10);color:#4b5563}.utility-nav{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;padding:16px 24px 0}.utility-nav a{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 14px;border-radius:999px;border:1px solid rgba(15,109,72,.18);text-decoration:none;color:var(--accent);font-weight:700;background:rgba(255,255,255,.55)}.about-dialog{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(32,22,12,.42);backdrop-filter:blur(8px);z-index:50}.about-dialog:target{display:flex}.about-dialog__panel{width:min(720px,100%);background:var(--panel);border:1px solid var(--border);border-radius:24px;box-shadow:var(--shadow);padding:24px;position:relative}.about-dialog__close{position:absolute;top:16px;right:16px;display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 12px;border-radius:999px;text-decoration:none;border:1px solid rgba(15,109,72,.18);background:rgba(255,255,255,.72);color:var(--accent);font-weight:700}.about-hero{display:grid;gap:10px;padding-right:92px}.about-title{display:grid;gap:4px}.about-title h1{margin:0;font-size:clamp(2rem,5vw,3.2rem);line-height:1.02;letter-spacing:-.03em}.about-version{font-size:1rem;font-weight:700;color:var(--muted)}.about-subtitle{margin:0;font-size:1.02rem;color:var(--muted)}.about-section{display:grid;gap:12px}.about-section p{margin:0;line-height:1.55}.about-section a{color:var(--accent);text-decoration:underline;text-underline-offset:2px;text-decoration-thickness:1px}.about-section a:hover{text-decoration-thickness:2px}.about-note{color:var(--muted);font-size:.96rem}.about-changelog{display:grid;gap:8px;margin:4px 0 0;padding-left:18px;color:var(--text)}.about-changelog li{line-height:1.45}.about-actions{justify-content:center}.about-actions .button{min-width:min(100%,320px);background:var(--accent);color:#f4fff8;box-shadow:0 10px 22px rgba(15,109,72,.18);text-decoration:none}.about-actions .button:hover{filter:brightness(1.04)}'
            .'.notice{padding:12px 14px;border-radius:14px;margin-bottom:16px;border:1px solid transparent}.notice.success{background:rgba(15,109,72,.10);color:#0b4d34;border-color:rgba(15,109,72,.16)}.notice.error{background:rgba(185,28,28,.10);color:#7f1d1d;border-color:rgba(185,28,28,.16)}'
            .'.badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:.82rem;font-weight:700;text-transform:lowercase;letter-spacing:.02em;border:1px solid transparent}.badge-stack{display:grid;gap:6px}.badge.status.new{background:rgba(59,130,246,.10);color:#1d4ed8}.badge.status.verified{background:rgba(245,158,11,.12);color:#92400e}.badge.status.reported{background:rgba(140,92,246,.12);color:#6d28d9}.badge.status.fixed{background:rgba(15,109,72,.12);color:#0b4d34}.badge.status.wontfix,.badge.status.duplicate{background:rgba(107,114,128,.12);color:#374151}.badge.review.manual_checking{background:rgba(245,158,11,.14);color:#92400e}.badge.review.confirmed_fixed{background:rgba(15,109,72,.14);color:#0b4d34}'
            .'@media (max-width:720px){main{padding-left:16px;padding-right:16px}.section-head{grid-template-columns:1fr}.split{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}'
            .'@media (max-width:540px){.stats{grid-template-columns:1fr}}'
            .'</style>'
            .'</head>'
            .'<body>'
            .'<div class="utility-nav"><a href="/">Overview</a><a href="/settings">Settings</a><a href="#about-modal">About</a></div>'
            .'<main>'.$body.'</main>'
            .$this->renderAboutModal()
            .'</body>'
            .'</html>';
    }

    private function renderSettingsPage(array $settings): string
    {
        $defaultPayload = (string) ($settings['intake.default_payload'] ?? SettingsService::DEFAULTS['intake.default_payload']);
        $autoVerifyMode = (string) ($settings['intake.auto_verify_mode'] ?? SettingsService::DEFAULTS['intake.auto_verify_mode']);
        $reviewTimeout = (string) ($settings['review.scan_timeout_ms'] ?? SettingsService::DEFAULTS['review.scan_timeout_ms']);
        $reviewConcurrency = (string) ($settings['review.scan_concurrency'] ?? SettingsService::DEFAULTS['review.scan_concurrency']);

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
    <label>Auto Verification
      <select name="auto_verify_mode">
        <option value="submit"<?= $autoVerifyMode === 'submit' ? ' selected' : '' ?>>On submit</option>
        <option value="cron_only"<?= $autoVerifyMode === 'cron_only' ? ' selected' : '' ?>>Cron only</option>
      </select>
    </label>
    <label>Review Timeout (ms)
      <input name="review_timeout_ms" inputmode="numeric" value="<?= $this->escape($reviewTimeout) ?>" placeholder="45000">
    </label>
    <label>Review Concurrency
      <input name="review_concurrency" inputmode="numeric" value="<?= $this->escape($reviewConcurrency) ?>" placeholder="4">
    </label>
    <p class="hint">Use a lower timeout for fragile targets, and set auto verification to cron only when you want intake to stay fast.</p>
    <div class="actions">
      <button type="submit">Save settings</button>
      <a class="button ghost" href="/">Back to overview</a>
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
    <div><dt>Auto Verification</dt><dd><code>On submit</code> retests immediately after intake, while <code>Cron only</code> leaves verification to <code>app:review:scan</code>.</dd></div>
    <div><dt>Review Timeout</dt><dd>Browser timeout for the review scan and browser retests.</dd></div>
    <div><dt>Review Concurrency</dt><dd>How many findings the review scan may process in parallel.</dd></div>
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
        <li>Evidence refresh now wipes screenshots, retest runs, and stored evidence before rebuilding them.</li>
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

    /**
     * @param list<Evidence> $evidence
     * @param list<RetestRun> $runs
     */
    private function renderFindingPage(Finding $finding, array $evidence, array $runs, ?string $message, ?string $error): string
    {
        $messageBox = $message ? '<div class="notice success">'.$this->escape($message).'</div>' : '';
        $errorBox = $error ? '<div class="notice error">'.$this->escape($error).'</div>' : '';

        $screenshotCards = [];
        foreach ($evidence as $item) {
            if ($item->getKind() !== EvidenceKind::SCREENSHOT || $item->getFilePath() === null) {
                continue;
            }

            $screenshotCards[] = sprintf(
                '<figure class="shot-card">'
                .'<a href="/artifacts/%s" target="_blank" rel="noreferrer"><img src="/artifacts/%s" alt="Screenshot for %s"></a>'
                .'<figcaption><code>%s</code></figcaption>'
                .'</figure>',
                $this->escape($this->artifactRelativeUrl($item->getFilePath() ?? '')),
                $this->escape($this->artifactRelativeUrl($item->getFilePath() ?? '')),
                $this->escape($finding->getId()),
                $this->escape($item->getFilePath()),
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
                $this->escape($item->getFilePath() ?? 'n/a'),
                $this->escape($item->getCreatedAt()->format(DATE_ATOM)),
            );
        }

        $runRows = [];
        foreach ($runs as $run) {
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
                $this->escape($run->getResult()),
                $this->escape((string) ($run->getHttpStatus() ?? 'n/a')),
                $this->escape($run->getStartedAt()->format(DATE_ATOM)),
                $this->escape($run->getScreenshotPath() ?? 'n/a'),
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
    <a class="button ghost" href="/">Back to overview</a>
  </div>
  <div class="detail-grid">
    <div>
      <?= $this->statusCell($finding) ?>
      <dl class="detail-list">
        <div><dt>Title</dt><dd><?= $this->escape($finding->getTitle()) ?></dd></div>
        <div><dt>URL</dt><dd><code><?= $this->escape($finding->getUrl()) ?></code></dd></div>
        <div><dt>Payload</dt><dd><code><?= $this->escape($finding->getExpectedEvidence() ?? 'n/a') ?></code></dd></div>
        <div><dt>Submitted</dt><dd><?= $this->escape($finding->getSubmittedAt()?->format(DATE_ATOM) ?? 'n/a') ?></dd></div>
        <div><dt>Last Recheck</dt><dd><?= $this->escape($finding->getLastRetestedAt()?->format(DATE_ATOM) ?? 'n/a') ?></dd></div>
      </dl>
      <div class="row-actions">
        <?= $this->findingActions($finding) ?>
      </div>
    </div>
    <div>
      <h3>Screenshots</h3>
      <?php if ($screenshotCards === []): ?>
        <p class="hint">No screenshots yet.</p>
      <?php else: ?>
        <div class="shot-grid"><?= implode('', $screenshotCards) ?></div>
      <?php endif; ?>
    </div>
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
        $decisionActions = '';
        $canMarkVulnerable = $finding->getReviewState() === ReviewState::MANUAL_CHECKING
            || $finding->getStatus() !== FindingStatus::VERIFIED;
        $canConfirmFixed = $finding->getReviewState() === ReviewState::MANUAL_CHECKING
            || $finding->getStatus() !== FindingStatus::FIXED;

        if ($canMarkVulnerable) {
            $decisionActions .= sprintf(
                '<form method="post" action="/findings/%s/mark-vulnerable" class="inline-form"><button type="submit" class="secondary">Mark Vulnerable</button></form>',
                $this->escape($finding->getId()),
            );
        }
        if ($canConfirmFixed) {
            $decisionActions .= sprintf(
                '<form method="post" action="/findings/%s/confirm-fixed" class="inline-form"><button type="submit" class="ghost">Confirm Fixed</button></form>',
                $this->escape($finding->getId()),
            );
        }

        return sprintf(
            '<div class="row-actions">'
            .$decisionActions
            .'<form method="post" action="/findings/%s/retest" class="inline-form"><button type="submit">Recheck + Screenshot</button></form>'
            .'<form method="post" action="/findings/%s/delete" class="inline-form"><button type="submit" class="danger">Delete</button></form>'
            .'</div>',
            $this->escape($finding->getId()),
            $this->escape($finding->getId()),
        );
    }

    private function statusCell(Finding $finding): string
    {
        $pills = [];
        $review = match ($finding->getReviewState()) {
            ReviewState::MANUAL_CHECKING => $this->badge('review manual_checking', 'Manual checking'),
            ReviewState::CONFIRMED_FIXED => $this->badge('review confirmed_fixed', 'Confirmed fixed'),
            default => '',
        };

        if ($review !== '') {
            $pills[] = $review;
        } else {
            $pills[] = $this->badge('status '.$finding->getStatus(), $this->humanizeBadgeLabel($finding->getStatus()));
        }

        $bucket = $this->bucketForFinding($finding);
        if ($bucket !== null && $bucket !== $finding->getStatus()) {
            $pills[] = $this->badge('bucket '.$bucket, $this->humanizeBadgeLabel($bucket));
        }

        return '<div class="status-pills">'.implode('', $pills).'</div>';
    }

    private function badge(string $class, string $label): string
    {
        return sprintf('<span class="badge %s">%s</span>', $this->escape($class), $this->escape($label));
    }

    private function bucketForFinding(Finding $finding): ?string
    {
        if ($finding->getReviewState() === ReviewState::MANUAL_CHECKING) {
            return 'manual_review';
        }

        if ($finding->getStatus() === 'fixed') {
            return 'fixed';
        }

        if ($finding->getLastRetestedAt() === null) {
            return 'unchecked';
        }

        if (in_array($finding->getStatus(), ['new', 'verified', 'reported'], true)) {
            return 'open';
        }

        return null;
    }

    private function humanizeBadgeLabel(string $value): string
    {
        return match ($value) {
            'manual_review' => 'Manual review',
            'manual_checking' => 'Manual checking',
            'confirmed_fixed' => 'Confirmed fixed',
            'new' => 'New',
            'verified' => 'Verified',
            'reported' => 'Reported',
            'fixed' => 'Fixed',
            'wontfix' => 'Wontfix',
            'duplicate' => 'Duplicate',
            'open' => 'Open',
            'unchecked' => 'Unchecked',
            default => ucfirst(str_replace('_', ' ', $value)),
        };
    }

    private function redirectMessage(string $message): RedirectResponse
    {
        return new RedirectResponse('/?message='.rawurlencode($message));
    }

    private function redirectError(string $error): RedirectResponse
    {
        return new RedirectResponse('/?error='.rawurlencode($error));
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

    private function artifactRelativeUrl(string $filePath): string
    {
        $normalized = ltrim(str_replace('\\', '/', $filePath), '/');
        return preg_replace('#^storage/artifacts/#', '', $normalized) ?: $normalized;
    }

    /**
     * @param list<Evidence> $evidence
     * @return list<Evidence>
     */
    private function pruneMissingScreenshotEvidence(array $evidence): array
    {
        $entityManager = $this->evidenceRepository->getEntityManager();
        $projectRoot = dirname(__DIR__, 2);
        $kept = [];
        $removed = false;

        foreach ($evidence as $item) {
            if (!$item instanceof Evidence) {
                continue;
            }

            if ($item->getKind() === EvidenceKind::SCREENSHOT && $item->getFilePath() !== null) {
                $absolutePath = $projectRoot.'/'.ltrim(str_replace('\\', '/', $item->getFilePath()), '/');
                if (!is_file($absolutePath)) {
                    $entityManager->remove($item);
                    $removed = true;
                    continue;
                }
            }

            $kept[] = $item;
        }

        if ($removed) {
            $entityManager->flush();
        }

        return $kept;
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
