<?php

namespace App\Controller;

use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Value\FindingReadLabels;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route(path: '/operator-priority')]
final class PriorityExportController
{
    public function __construct(
        private readonly FindingRepository $findings,
        private readonly CsrfTokenManagerInterface $csrf,
    )
    {
    }

    #[Route(name: 'operator_priority', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $days = max(1, min(365, $request->query->getInt('days', 14)));
        $until = new \DateTimeImmutable('tomorrow');
        $from = $until->modify(sprintf('-%d days', $days));

        $findings = $this->findings->findForPriorityExport($from, $until);
        $research = $this->loadResearchLedger();
        $groups = $this->groupFindings($findings, $research);
        $this->sortGroups($groups);

        return new Response($this->render($groups, $from, $until, $days));
    }

    /**
     * @param list<Finding> $findings
     * @param array<string, array<string, mixed>> $research
     * @return array<string, array<string, mixed>>
     */
    private function groupFindings(array $findings, array $research): array
    {
        $groups = [];
        foreach ($findings as $finding) {
            $domain = $finding->getDomain();
            $hostname = $domain->getHostname();
            if (!isset($groups[$hostname])) {
                $groups[$hostname] = [
                    'hostname' => $hostname,
                    'scheme' => $domain->getScheme() ?: 'https',
                    'research' => $research[$hostname] ?? [
                        'operator' => 'Betreiber nicht im Research-Ledger gefunden',
                        'seat' => 'nicht ermittelt',
                        'facts' => 'Keine Größen-/Finanzdaten im Research-Ledger.',
                        'size' => 'unknown / preliminary',
                        'evidence' => '',
                        'status' => 'identity-unverified / figures-unverified',
                    ],
                    'findings' => [],
                ];
            }
            $groups[$hostname]['findings'][] = $finding;
        }

        return $groups;
    }

    /** @param array<string, array<string, mixed>> $groups */
    private function sortGroups(array &$groups): void
    {
        uasort($groups, function (array $left, array $right): int {
            $leftRank = $this->sizeRank((string) $left['research']['size']);
            $rightRank = $this->sizeRank((string) $right['research']['size']);
            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            $countCompare = count($right['findings']) <=> count($left['findings']);
            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strcasecmp((string) $left['hostname'], (string) $right['hostname']);
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function loadResearchLedger(): array
    {
        $path = dirname(__DIR__, 2).'/reports/operator-research-ledger.md';
        if (!is_file($path)) {
            return [];
        }

        $research = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (!str_starts_with($line, '| `')) {
                continue;
            }

            $columns = array_map('trim', explode('|', trim($line, '|')));
            if (count($columns) < 8 || !preg_match('/^`([^`]+)`$/', $columns[0], $match)) {
                continue;
            }

            $research[$match[1]] = [
                'operator' => $columns[2],
                'seat' => $columns[3],
                'facts' => $columns[4],
                'size' => $columns[5],
                'evidence' => $columns[6],
                'status' => $columns[7],
            ];
        }

        return $research;
    }

    private function sizeRank(string $size): int
    {
        $size = strtolower($size);
        foreach (['large' => 0, 'medium' => 1, 'small' => 2, 'micro' => 3] as $label => $rank) {
            if (str_contains($size, $label)) {
                return $rank;
            }
        }

        return 4;
    }

    /** @param array<string, array<string, mixed>> $groups */
    private function render(array $groups, \DateTimeImmutable $from, \DateTimeImmutable $until, int $days): string
    {
        $findingCount = array_sum(array_map(static fn (array $group): int => count($group['findings']), $groups));
        $deCount = count(array_filter($groups, fn (array $group): bool => $this->isGerman($group)));
        $generated = (new \DateTimeImmutable())->format('Y-m-d H:i:s T');
        $rows = '';
        $rank = 0;

        foreach ($groups as $group) {
            ++$rank;
            $research = $group['research'];
            $hostname = (string) $group['hostname'];
            $scheme = (string) $group['scheme'];
            $domainUrl = $this->safeUrl($scheme.'://'.$hostname);
            $country = $this->isGerman($group) ? 'Deutschland' : 'EU / sonstige';
            $findingRows = '';

            foreach ($group['findings'] as $finding) {
                \assert($finding instanceof Finding);
                $target = $this->safeUrl($finding->getUrl());
                $report = $this->safeUrl($finding->getReportUrl());
                $date = ($finding->getSubmittedAt() ?? $finding->getCreatedAt())->format('Y-m-d');
                $findingRows .= '<li>'
                    .'<span class="severity '.self::e($finding->getSeverity()).'">'.self::e($finding->getSeverity()).'</span> '
                    .'<a href="/findings/'.rawurlencode($finding->getId()).'">Reportseite</a> '
                    .'<span class="muted">'.self::e($finding->getTitle()).' · '.self::e($date).'</span>'
                    .'<span class="muted"> · Altstatus: '.self::e($finding->getStatus()).' · Manuelle Bewertung: '.self::e(FindingReadLabels::assessment($finding->getManualAssessment(), $finding->getDiscardReason())).'</span>'
                    .($target !== null ? ' · <a href="'.self::e($target).'" target="_blank" rel="noreferrer">Ziel</a>' : '')
                    .($report !== null ? ' · <a href="'.self::e($report).'" target="_blank" rel="noreferrer">externer Report</a>' : '')
                    .'<form method="post" action="/findings/'.rawurlencode($finding->getId()).'/mark-contacted" class="contact-form">'
                    .'<input type="hidden" name="_token" value="'.self::e($this->csrf->getToken('finding_mark_contacted_'.$finding->getId())->getValue()).'">'
                    .'<input type="hidden" name="return_to" value="/operator-priority?days='.self::e((string) $days).'">'
                    .'<button type="submit">Kontaktiert – ausblenden</button></form>'
                    .'</li>';
            }

            $rows .= '<tr data-domain="'.self::e(strtolower($hostname)).'" data-country="'.self::e($country).'" data-size="'.self::e(strtolower((string) $research['size'])).'">'
                .'<td class="rank">'.$rank.'</td>'
                .'<td><a class="domain" href="'.self::e($domainUrl).'" target="_blank" rel="noreferrer">'.self::e($hostname).'</a><div class="muted">'.self::e($country).' · '.count($group['findings']).' Fälle in der Auswahl</div></td>'
                .'<td>'.self::e((string) $research['operator']).'<div class="muted">'.self::e((string) $research['seat']).'</div></td>'
                .'<td><strong>'.self::e((string) $research['size']).'</strong><div class="facts">'.self::e((string) $research['facts']).'</div>'.$this->sourceLinks((string) $research['evidence']).'</td>'
                .'<td><ul class="finding-list">'.$findingRows.'</ul></td>'
                .'</tr>';
        }

        $noRows = $rows === '' ? '<tr><td colspan="5" class="empty">Keine nicht kontaktierten Fälle mit Altstatus ungleich fixed im gewählten Zeitraum.</td></tr>' : '';
        $title = 'Live Betreiber-Priorität';

        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.self::e($title).'</title><style>'.$this->css().'</style></head><body><main>'
            .'<header><div><p class="eyebrow">LibreBugBounty · dynamischer HTML-Export</p><h1>'.self::e($title).'</h1><p class="intro">Die Auswahl zeigt Fälle ohne Kontaktzeitpunkt und mit Altstatus ungleich fixed. Verworfene Fälle sind ausgeblendet. Beim Neuladen werden Datenbankänderungen übernommen.</p></div><nav><a href="/">Dashboard</a><button type="button" onclick="location.reload()">Neu laden</button></nav></header>'
            .'<section class="stats"><div><strong>'.self::e((string) $findingCount).'</strong><span>Fälle in der Auswahl</span></div><div><strong>'.self::e((string) count($groups)).'</strong><span>Domains in der Auswahl</span></div><div><strong>'.self::e((string) $deCount).'</strong><span>Deutschland</span></div><div><strong>'.self::e((string) (count($groups) - $deCount)).'</strong><span>EU / sonstige</span></div></section>'
            .'<section class="meta"><span>Zeitraum: '.self::e($from->format('Y-m-d')).' bis '.self::e($until->modify('-1 day')->format('Y-m-d')).' ('.$days.' Tage)</span><span>Stand: '.self::e($generated).'</span><a href="/operator-priority?days=14">14 Tage</a><a href="/operator-priority?days=30">30 Tage</a></section>'
            .'<section class="filters"><label>Suche <input id="search" type="search" placeholder="Domain oder Betreiber"></label><label>Gebiet <select id="country"><option value="">alle</option><option>Deutschland</option><option>EU / sonstige</option></select></label><label>Größe <select id="size"><option value="">alle</option><option value="large">large</option><option value="medium">medium</option><option value="small">small</option><option value="micro">micro</option><option value="unknown">unknown</option></select></label><label class="check"><input id="unknown" type="checkbox"> unknown ausblenden</label></section>'
            .'<div class="table-wrap"><table><thead><tr><th>#</th><th>Domain</th><th>Betreiber</th><th>Größe / Evidenz</th><th>Findings und Aktionen</th></tr></thead><tbody id="rows">'.$rows.$noRows.'</tbody></table></div>'
            .'<p class="footnote">Sortierung: large → medium → small → micro → unknown, danach Finding-Anzahl. Umsatz-/Gewinn-/Mitarbeiterangaben stammen aus dem Research-Ledger; unklare Werte bleiben als solche gekennzeichnet.</p>'
            .'</main><script>const search=document.querySelector("#search"),country=document.querySelector("#country"),size=document.querySelector("#size"),unknown=document.querySelector("#unknown");function filter(){const q=search.value.toLowerCase(),c=country.value,s=size.value;document.querySelectorAll("#rows tr[data-domain]").forEach(r=>{const ok=(!q||(r.dataset.domain+" "+r.textContent.toLowerCase()).includes(q))&&(!c||r.dataset.country===c)&&(!s||r.dataset.size.includes(s))&&(!unknown.checked||!r.dataset.size.includes("unknown"));r.hidden=!ok})}for(const el of [search,country,size,unknown])el.addEventListener("input",filter);</script></body></html>';
    }

    /** @param array<string, mixed> $group */
    private function isGerman(array $group): bool
    {
        return str_contains(strtolower((string) $group['research']['seat']), 'deutschland');
    }

    private function sourceLinks(string $evidence): string
    {
        preg_match_all('/\[[^\]]+\]\(<([^>]+)>\)|\[[^\]]+\]\(([^)]+)\)/', $evidence, $matches, PREG_SET_ORDER);
        $links = [];
        foreach (array_slice($matches, 0, 3) as $match) {
            $url = $this->safeUrl($match[1] !== '' ? $match[1] : $match[2]);
            if ($url !== null) {
                $links[] = '<a href="'.self::e($url).'" target="_blank" rel="noreferrer">Quelle</a>';
            }
        }

        return $links === [] ? '' : '<div class="sources">'.implode(' · ', $links).'</div>';
    }

    private function safeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('~^https?://~i', $url) === 1 ? $url : null;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function css(): string
    {
        return 'body{margin:0;background:#f6f8f7;color:#18241e;font:15px/1.45 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}main{max-width:1700px;margin:auto;padding:28px}header{display:flex;justify-content:space-between;gap:24px;align-items:flex-start}h1{font-size:clamp(2rem,4vw,3.3rem);margin:.1em 0}.eyebrow{color:#137447;font-weight:800;text-transform:uppercase;letter-spacing:.08em;font-size:.75rem}.intro,.muted,.footnote{color:#64746a}.intro{max-width:800px}nav{display:flex;gap:10px;flex-wrap:wrap}a,button{color:#0b6940}nav a,nav button,.contact-form button{border:1px solid #b8d4c3;border-radius:999px;padding:9px 14px;background:#fff;text-decoration:none;font-weight:700;cursor:pointer}.stats{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:12px;margin:24px 0}.stats div{background:#fff;border:1px solid #dce7df;border-radius:16px;padding:16px}.stats strong{display:block;font-size:1.7rem}.stats span{color:#64746a}.meta,.filters{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin:12px 0}.meta{color:#64746a}.meta a{font-weight:700}.filters{background:#fff;border:1px solid #dce7df;border-radius:16px;padding:14px}.filters label{display:flex;gap:8px;align-items:center;font-weight:700}.filters input[type=search],select{border:1px solid #bfd1c5;border-radius:9px;padding:8px;background:#fff}.check{font-weight:400!important}.table-wrap{overflow:auto;background:#fff;border:1px solid #dce7df;border-radius:16px}table{width:100%;border-collapse:collapse;min-width:1050px}th,td{padding:13px 12px;border-bottom:1px solid #e3ece6;text-align:left;vertical-align:top}th{font-size:.75rem;color:#64746a;text-transform:uppercase;letter-spacing:.06em;background:#fbfdfb;position:sticky;top:0}.rank{font-weight:900;color:#137447;font-size:1.2rem}.domain{font-weight:800}.facts{margin-top:5px;max-width:480px}.sources{margin-top:8px;font-size:.85rem}.finding-list{margin:0;padding-left:18px;min-width:360px}.finding-list li{margin:0 0 9px}.severity{font-size:.72rem;font-weight:800;text-transform:uppercase;border-radius:5px;padding:2px 5px;background:#eaf3ed}.severity.high,.severity.critical{background:#ffe1dd;color:#a32419}.severity.low{background:#eef2f0}.contact-form{display:inline;margin-left:8px}.contact-form button{padding:4px 8px;font-size:.78rem;background:#f3faf5}.empty{text-align:center;padding:35px}.footnote{margin:14px 0;font-size:.9rem}@media(max-width:700px){main{padding:16px}header{display:block}.stats{grid-template-columns:repeat(2,1fr)}}';
    }
}
