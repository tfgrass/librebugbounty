<?php

/** @var array<string, mixed> $view */
/** @var callable $escape */
$period = $view['period'];
$filters = $view['filters'];
$series = $view['series'];
$history = $view['history'] ?? null;
$contactHistoryUrl = '/findings?'.http_build_query(['scope' => 'all', 'event' => 'contacted'] + ($filters['tld'] === '' ? [] : ['tld' => $filters['tld']]), '', '&', PHP_QUERY_RFC3986);
$metrics = ['reported' => 'Gemeldet', 'contacted' => 'Kontaktiert', 'sent' => 'Versendet', 'fixed' => 'Behoben', 'confirmed' => 'Bestätigt'];
$metricColors = ['reported' => '#80adff', 'sent' => '#bca4ff', 'fixed' => '#77dbb0', 'confirmed' => '#e9bf7e', 'contacted' => '#78cee3', 'hosts' => '#9fb0c8'];
$mainMetrics = ['reported', 'contacted', 'sent', 'fixed'];
$formatNumber = static fn (int|float $number): string => number_format($number, 0, ',', '.');
$formatShare = static fn (float $share): string => $share > 0 && $share < 1 ? '< 1 %' : number_format($share, 0, ',', '.').' %';
$fullRowLabel = static fn (array $row): string => (new \DateTimeImmutable($row['from']))->format('d.m.Y').($row['from'] === $row['to'] ? '' : ' – '.(new \DateTimeImmutable($row['to']))->format('d.m.Y'));
$baseQuery = ['period' => $period['kind'], 'anchor' => $period['anchor'], 'granularity' => $period['requestedGranularity']] + $filters;
if ($period['kind'] === 'custom') {
    $baseQuery['from'] = $period['from'];
    $baseQuery['to'] = $period['to'];
}
$statisticsUrl = static function (array $changes) use ($baseQuery): string {
    $query = array_replace($baseQuery, $changes);
    if (($query['period'] ?? '') !== 'custom') {
        unset($query['from'], $query['to']);
    }
    return '/statistics?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};
$plotAxes = [];
foreach ($metrics as $metric => $label) {
    $maximum = max(1, ...array_column($series, $metric));
    $step = 1;
    if ($maximum > 4) {
        $target = $maximum / 4;
        $base = 10 ** floor(log10($target));
        foreach ([1, 2, 5, 10] as $factor) {
            if ($factor * $base >= $target) {
                $step = max(1, (int) ($factor * $base));
                break;
            }
        }
    }
    $axisMaximum = (int) (ceil($maximum / $step) * $step);
    $plotAxes[$metric] = ['maximum' => $axisMaximum, 'ticks' => range($axisMaximum, 0, -$step)];
}
$plotPath = static function (string $metric) use ($series, $plotAxes): string {
    $points = [];
    $last = count($series) - 1;
    foreach ($series as $index => $row) {
        $x = $last === 0 ? 500 : 1000 * $index / $last;
        $y = 120 - 120 * $row[$metric] / $plotAxes[$metric]['maximum'];
        $points[] = ($index === 0 ? 'M' : 'L').round($x, 2).' '.round($y, 2);
    }
    return implode(' ', $points);
};
$measure = $filters['tldMeasure'];
$segments = $view['tlds'][$measure];
$tldTotal = $view['tlds'][$measure === 'hosts' ? 'hostTotal' : 'caseTotal'];
$segmentColors = ['#80adff', '#bca4ff', '#77dbb0', '#e9bf7e', '#78cee3', '#e698b6', '#8c9cb4', '#adbe80'];
$donutLength = 2 * M_PI * 68;
$donutOffset = 0.0;
$snapshotTotal = array_sum(array_column($view['snapshot'], 'count'));
$calendarMetric = $filters['heatmapMetric'];
$heatmapPalettes = [
    'reported' => ['#283342', '#304f78', '#416fa8', '#5b90d2', '#80adff'],
    'sent' => ['#283342', '#494068', '#69548e', '#9378bf', '#bca4ff'],
    'contacted' => ['#283342', '#2a4f5e', '#3b7181', '#53a0b5', '#78cee3'],
    'confirmed' => ['#283342', '#665037', '#96744c', '#be9b62', '#e9bf7e'],
    'fixed' => ['#283342', '#2a5149', '#3e7a66', '#59ad8e', '#77dbb0'],
];
$calendarMaximum = max(1, ...array_column($view['calendar'], $calendarMetric));
$calendarStart = new \DateTimeImmutable($view['calendarYear'].'-01-01');
$calendarOffset = (int) $calendarStart->format('N') - 1;
$monthLabels = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
$payload = json_encode($view, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$isEmpty = array_sum(array_map(static fn (string $metric): int => $view['kpis'][$metric]['count'], $mainMetrics)) === 0;
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title>Statistiken · LibreBugBounty Studio</title>
  <link rel="stylesheet" href="/css/studio.css">
  <link rel="stylesheet" href="/css/studio-statistics.css">
  <script src="/js/statistics.js" defer></script>
</head>
<body data-studio data-statistics>
  <div class="studio-shell">
    <header class="studio-header">
      <a class="studio-brand" href="/" aria-label="LibreBugBounty Studio, Eingang">
        <svg class="studio-brand-mark" width="27" height="27" viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M14 2.5 24 8.3v11.4l-10 5.8-10-5.8V8.3L14 2.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M10 11h8v7a4 4 0 0 1-8 0v-7Zm2-3h4v3h-4V8Zm2 4v10M7 13h3m8 0h3M7 17h3m8 0h3m-10 5 2-2m5 0 2 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="studio-brand-name">LibreBugBounty</span><span class="studio-brand-tag">STUDIO</span>
      </a>
      <a class="studio-classic-link" href="/legacy">Klassisch <span aria-hidden="true">↗</span></a>
    </header>

    <main class="stat-main" id="statistics">
      <div class="stat-content">
        <div class="stat-heading">
          <div><p class="studio-eyebrow">DEIN ÜBERBLICK</p><h1>Statistiken</h1><p>Von der ersten Meldung bis zur Behebung.</p></div>
          <span class="stat-heading-mark" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 19h16M6 15V9m6 6V4m6 11v-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>
        </div>

        <section class="stat-controls" aria-label="Zeitraum und Filter">
          <div class="stat-period-bar">
            <nav class="stat-segmented" aria-label="Statistikzeitraum">
              <?php foreach (['week' => 'Woche', 'month' => 'Monat', 'year' => 'Jahr', 'all' => 'Gesamt'] as $kind => $label): ?>
                <a data-period="<?= $escape($kind) ?>" href="<?= $escape($statisticsUrl(['period' => $kind])) ?>"<?= $period['kind'] === $kind ? ' aria-current="page"' : '' ?>><?= $escape($label) ?></a>
              <?php endforeach; ?>
            </nav>
            <div class="stat-period-navigation">
              <?php if ($period['previousUrl'] !== null): ?><a class="stat-icon-button" data-period-previous href="<?= $escape($period['previousUrl']) ?>" aria-label="Vorheriger Zeitraum">←</a><?php else: ?><span class="stat-icon-button" aria-disabled="true" aria-label="Kein vorheriger Zeitraum">←</span><?php endif; ?>
              <div class="stat-period-label"><?= $escape($period['label']) ?><small><?= $period['ongoing'] ? 'Laufender Zeitraum · ' : '' ?>Europe/Berlin</small></div>
              <?php if ($period['nextUrl'] !== null): ?><a class="stat-icon-button" data-period-next href="<?= $escape($period['nextUrl']) ?>" aria-label="Nächster Zeitraum">→</a><?php else: ?><span class="stat-icon-button" aria-disabled="true" aria-label="Kein nächster Zeitraum">→</span><?php endif; ?>
            </div>
          </div>
          <div class="stat-filter-row">
            <form method="get" action="/statistics" id="statistics-filters" class="stat-filter-row">
              <?php foreach ($baseQuery as $name => $value): ?><?php if (!in_array($name, ['granularity', 'tld'], true)): ?><input type="hidden" name="<?= $escape($name) ?>" value="<?= $escape($value) ?>"><?php endif; ?><?php endforeach; ?>
              <label for="statistics-granularity">Zusammenfassung<select name="granularity" id="statistics-granularity"><?php foreach (['day' => 'Pro Tag', 'week' => 'Pro Woche', 'month' => 'Pro Monat'] as $key => $label): ?><option value="<?= $escape($key) ?>"<?= $period['requestedGranularity'] === $key ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
              <label for="statistics-tld">Domain-Endung<select name="tld" id="statistics-tld"><?php foreach ($view['tldOptions'] as $option): ?><option value="<?= $escape($option['key']) ?>"<?= $filters['tld'] === $option['key'] ? ' selected' : '' ?>><?= $escape($option['label']) ?></option><?php endforeach; ?></select></label>
              <button class="stat-button" type="submit">Anwenden</button>
            </form>
            <details class="stat-custom"<?= $period['kind'] === 'custom' ? ' open' : '' ?>>
              <summary>Eigener Zeitraum</summary>
              <form method="get" action="/statistics" id="statistics-custom-period">
                <input type="hidden" name="period" value="custom">
                <?php foreach ($baseQuery as $name => $value): ?><?php if (!in_array($name, ['period', 'from', 'to'], true)): ?><input type="hidden" name="<?= $escape($name) ?>" value="<?= $escape($value) ?>"><?php endif; ?><?php endforeach; ?>
                <label>Von<input type="date" name="from" value="<?= $escape($period['from']) ?>" required></label>
                <label>Bis<input type="date" name="to" value="<?= $escape($period['to']) ?>" required></label>
                <button class="stat-button" type="submit">Zeitraum anzeigen</button>
              </form>
            </details>
          </div>
          <?php if ($filters['tld'] !== ''): ?><p class="stat-filter-summary">Domain-Endung <?= $escape($filters['tld']) ?> in allen Auswertungen <a href="<?= $escape($statisticsUrl(['tld' => ''])) ?>">Filter entfernen</a></p><?php endif; ?>
        </section>

        <section class="stat-kpis" aria-label="Kennzahlen im gewählten Zeitraum">
          <?php foreach (['reported', 'contacted', 'sent', 'fixed', 'hosts'] as $metric): ?>
            <?php $kpi = $view['kpis'][$metric]; $tag = $kpi['url'] === null ? 'div' : 'a'; $hint = match ($metric) { 'reported' => 'Neue Fälle im Eingang', 'contacted' => 'Gespeicherte Kontaktmarkierungen', 'sent' => 'Als versendet markierte Fälle', 'fixed' => 'Manuell als behoben bewertet', default => 'Verschiedene Hosts neuer Fälle' }; ?>
            <<?= $tag ?> class="stat-kpi" data-stat-kpi="<?= $escape($metric) ?>" data-count="<?= $escape($kpi['count']) ?>" style="--metric-color: <?= $escape($metricColors[$metric]) ?>"<?= $kpi['url'] === null ? '' : ' href="'.$escape($kpi['url']).'"' ?>>
              <span class="stat-kpi-label"><?= $escape($metrics[$metric] ?? $kpi['label']) ?><?= $kpi['url'] === null ? '' : '<span aria-hidden="true">↗</span>' ?></span>
              <strong class="stat-kpi-value"><?= $escape($formatNumber($kpi['count'])) ?></strong>
              <span class="stat-kpi-bottom"><span><?= $escape($hint) ?></span>
                <?php if ($kpi['delta'] !== null): ?><span class="stat-kpi-delta" title="<?= $escape($period['comparisonLabel'] ?? '') ?>"><?= $kpi['delta'] > 0 ? '+' : '' ?><?= $escape($formatNumber($kpi['delta'])) ?><?= $kpi['deltaPercent'] === null ? '' : ' ('.($kpi['deltaPercent'] > 0 ? '+' : '').$escape(number_format($kpi['deltaPercent'], 1, ',', '.')).' %)' ?> zum Vergleichszeitraum</span><?php endif; ?>
              </span>
            </<?= $tag ?>>
          <?php endforeach; ?>
        </section>

        <section class="stat-card stat-chart-card" aria-labelledby="statistics-activity-title">
          <div class="stat-card-heading"><div><p class="stat-card-eyebrow">AKTIVITÄT</p><h2 id="statistics-activity-title">Deine Arbeit im Verlauf</h2><p><?= $escape(match ($period['granularity']) { 'week' => 'Wochenwerte', 'month' => $period['bucketMonths'] > 1 ? $period['bucketMonths'].'-Monatswerte' : 'Monatswerte', default => 'Tageswerte' }) ?> · Jeder Schritt zählt an seinem eigenen Datum.</p><p class="stat-scale-explanation">Jede Reihe hat eine eigene Skala. Die Zeitachse gilt für alle.</p></div></div>
          <div class="stat-static-legend" data-no-js><?php foreach ($mainMetrics as $metric): ?><span style="--metric-color: <?= $escape($metricColors[$metric]) ?>"><i class="stat-legend-line" aria-hidden="true"></i><?= $escape($metrics[$metric]) ?></span><?php endforeach; ?></div>
          <fieldset class="stat-series-controls" data-js-only hidden><legend class="studio-sr-only">Diagrammlinien auswählen</legend><?php foreach ($metrics as $metric => $label): ?><label style="--metric-color: <?= $escape($metricColors[$metric]) ?>"><input type="checkbox" data-series-toggle="<?= $escape($metric) ?>"<?= in_array($metric, $mainMetrics, true) ? ' checked' : '' ?>><i class="stat-legend-line" aria-hidden="true"></i><?= $escape($label) ?></label><?php endforeach; ?></fieldset>
          <div class="stat-chart-rows" data-chart-rows>
            <?php foreach ($metrics as $metric => $label): ?>
              <?php $axis = $plotAxes[$metric]; ?>
              <section class="stat-chart-row" data-chart-series="<?= $escape($metric) ?>" data-chart-axis-max="<?= $escape($axis['maximum']) ?>" data-count="<?= $escape($view['kpis'][$metric]['count']) ?>" style="--metric-color: <?= $escape($metricColors[$metric]) ?>" aria-labelledby="statistics-row-<?= $escape($metric) ?>"<?= in_array($metric, $mainMetrics, true) ? '' : ' hidden' ?>>
                <div class="stat-chart-row-heading"><h3 id="statistics-row-<?= $escape($metric) ?>"><i class="stat-legend-line" aria-hidden="true"></i><?= $escape($label) ?></h3><a class="stat-chart-row-total" href="<?= $escape($view['kpis'][$metric]['url']) ?>" aria-label="<?= $escape($label.': '.$view['kpis'][$metric]['count'].' Fälle im gewählten Zeitraum öffnen') ?>"><strong><?= $escape($formatNumber($view['kpis'][$metric]['count'])) ?></strong> im Zeitraum <span aria-hidden="true">↗</span></a></div>
                <div class="stat-chart-row-scale" data-chart-scale="<?= $escape($metric) ?>">Eigene Skala · 0–<?= $escape($formatNumber($axis['maximum'])) ?> Fälle</div>
                <div class="stat-chart-plot">
                  <div class="stat-chart-axis-y" aria-hidden="true" data-chart-y-axis="<?= $escape($metric) ?>"><?php foreach ($axis['ticks'] as $tick): ?><span><?= $escape($formatNumber($tick)) ?></span><?php endforeach; ?></div>
                  <div class="stat-chart-graphic">
                    <svg class="stat-chart-svg" data-activity-chart data-chart-metric="<?= $escape($metric) ?>" viewBox="0 0 1000 120" preserveAspectRatio="none" role="img" aria-labelledby="statistics-row-<?= $escape($metric) ?>" aria-describedby="statistics-chart-summary statistics-scale-<?= $escape($metric) ?>">
                      <defs><linearGradient id="statistics-<?= $escape($metric) ?>-fill" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="<?= $escape($metricColors[$metric]) ?>" stop-opacity=".12"/><stop offset="100%" stop-color="<?= $escape($metricColors[$metric]) ?>" stop-opacity="0"/></linearGradient></defs>
                      <g data-chart-grid="<?= $escape($metric) ?>"><?php foreach ($axis['ticks'] as $tick): ?><?php $tickY = 120 - 120 * $tick / $axis['maximum']; ?><line class="stat-chart-grid" x1="0" x2="1000" y1="<?= $escape($tickY) ?>" y2="<?= $escape($tickY) ?>"/><?php endforeach; ?></g>
                      <path data-series-area="<?= $escape($metric) ?>" d="<?= $escape($plotPath($metric).' L1000 120 L0 120 Z') ?>" fill="url(#statistics-<?= $escape($metric) ?>-fill)"<?= count($series) < 2 ? ' hidden' : '' ?>/>
                      <path class="stat-series-line" data-series-path="<?= $escape($metric) ?>" d="<?= $escape($plotPath($metric)) ?>" stroke="<?= $escape($metricColors[$metric]) ?>"/>
                      <?php if (count($series) === 1): ?><circle data-series-single="<?= $escape($metric) ?>" cx="500" cy="<?= $escape(120 - 120 * $series[0][$metric] / $axis['maximum']) ?>" r="3" fill="<?= $escape($metricColors[$metric]) ?>" vector-effect="non-scaling-stroke"/><?php endif; ?>
                      <line class="stat-crosshair" data-chart-crosshair="<?= $escape($metric) ?>" x1="0" x2="0" y1="0" y2="120" hidden/>
                      <circle data-chart-point="<?= $escape($metric) ?>" cx="0" cy="0" r="3.5" fill="<?= $escape($metricColors[$metric]) ?>" stroke="#1b212a" stroke-width="1.5" vector-effect="non-scaling-stroke" hidden/>
                    </svg>
                  </div>
                </div>
                <p id="statistics-scale-<?= $escape($metric) ?>" class="studio-sr-only"><?= $escape($label) ?>: eigene Skala von 0 bis <?= $escape($axis['maximum']) ?> Fällen. Insgesamt <?= $escape($view['kpis'][$metric]['count']) ?> im gewählten Zeitraum.</p>
              </section>
            <?php endforeach; ?>
          </div>
          <div class="stat-chart-axis-x stat-shared-axis" aria-hidden="true"><?php if ($series !== []): ?><span><?= $escape($fullRowLabel($series[0])) ?></span><?php if (count($series) > 2): ?><span><?= $escape($fullRowLabel($series[(int) floor((count($series) - 1) / 2)])) ?></span><?php endif; ?><?php if (count($series) > 1): ?><span><?= $escape($fullRowLabel($series[count($series) - 1])) ?></span><?php endif; ?><?php endif; ?></div>
          <p id="statistics-chart-summary" class="studio-sr-only">Aktivität im Zeitraum <?= $escape($period['label']) ?>. Getrennte Diagramme mit jeweils eigener Skala und gemeinsamer Zeitachse. Alle Werte stehen in der aufklappbaren Tabelle.</p>
          <div class="stat-chart-inspection" data-chart-tooltip data-js-only hidden aria-live="polite"><strong data-chart-tooltip-date></strong><div class="stat-chart-inspection-values" data-chart-tooltip-values></div></div>
          <p class="stat-chart-instructions" data-js-only hidden>Tippe ins Diagramm oder bewege den Zeiger. Mit den Pfeiltasten wechselst du zwischen den Werten.</p>
          <p class="stat-empty-note" data-chart-empty-selection hidden>Wähle mindestens eine Reihe für den Verlauf.</p>
          <?php if ($isEmpty): ?><p class="stat-empty-note">Für diese Auswahl sind noch keine Meldungen, Kontaktmarkierungen, Versandmarkierungen oder Behebungen aufgezeichnet.</p><?php endif; ?>
          <?php if ($period['comparisonLabel'] !== null): ?><p class="stat-card-note"><?= $escape($period['comparisonLabel']) ?></p><?php endif; ?>
          <details class="stat-table-details" data-series-table><summary>Alle Diagrammwerte als Tabelle</summary><div class="stat-table-wrap"><table class="stat-table"><caption class="studio-sr-only">Aktivitäten nach Zeitraum, einschließlich optionaler Diagrammlinien</caption><thead><tr><th scope="col">Zeitraum</th><?php foreach ($metrics as $metric => $label): ?><th scope="col"><?= $escape($label) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($series as $row): ?><tr><td><?= $escape($fullRowLabel($row)) ?></td><?php foreach ($metrics as $metric => $label): ?><td><a href="<?= $escape($row['urls'][$metric]) ?>" aria-label="<?= $escape($label.': '.$row[$metric].' · '.$fullRowLabel($row)) ?>"><?= $escape($formatNumber($row[$metric])) ?></a></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></details>
        </section>

        <?php if ($history !== null): ?>
          <section class="stat-card stat-history-card" aria-labelledby="statistics-history-title">
            <div class="stat-card-heading"><div><p class="stat-card-eyebrow">GESAMTER GESPEICHERTER BESTAND</p><h2 id="statistics-history-title">Gespeicherte Historie</h2><p>Unabhängig vom gewählten Zeitraum<?= $filters['tld'] === '' ? '' : ' · mit dem gewählten TLD-Filter' ?></p></div><?php if ($period['kind'] !== 'all'): ?><a class="stat-button" data-history-timeline href="<?= $escape($statisticsUrl(['period' => 'all'])) ?>">Gesamten Verlauf ansehen <span aria-hidden="true">↗</span></a><?php endif; ?></div>
            <div class="stat-history-values">
              <a class="stat-history-item" data-history-key="contacts" data-count="<?= $escape($history['contactDatedCount']) ?>" href="<?= $escape($contactHistoryUrl) ?>"><span>Gespeicherte Kontakte <span aria-hidden="true">↗</span></span><strong><?= $escape($formatNumber($history['contactDatedCount'])) ?></strong><small><?= $history['contactFrom'] === null ? 'Keine Kontaktmarkierungen vorhanden' : 'Gespeicherte Markierungen ab '.$escape((new \DateTimeImmutable($history['contactFrom']))->format('d.m.Y')) ?></small></a>
              <?php foreach ($history['items'] as $item): ?><a class="stat-history-item" data-history-key="<?= $escape($item['key']) ?>" data-count="<?= $escape($item['count']) ?>" href="<?= $escape($item['url']) ?>"><span><?= $escape($item['label']) ?> <span aria-hidden="true">↗</span></span><strong><?= $escape($formatNumber($item['count'])) ?></strong><small>Ältere Kennzeichnung · Datum unbekannt</small></a><?php endforeach; ?>
            </div>
            <p class="stat-card-note">Die drei älteren Statusgruppen können dieselben Fälle enthalten und werden nicht addiert. Ohne belegtes Datum erhalten diese Markierungen keinen Punkt im Zeitverlauf.</p>
            <p class="stat-card-note">Kontaktpunkte verwenden das gespeicherte Datum der Kontaktmarkierung. Dieses muss nicht der erste Kontakt sein.</p>
          </section>
        <?php endif; ?>

        <div class="stat-grid">
          <section class="stat-card stat-tld-card" aria-labelledby="statistics-tld-title">
            <div class="stat-card-heading"><div><p class="stat-card-eyebrow">VERTEILUNG</p><h2 id="statistics-tld-title">Domain-Endungen</h2><p>Neue Fälle im gewählten Zeitraum</p></div><nav class="stat-segmented" aria-label="TLD-Zählweise"><?php foreach (['cases' => 'Fälle', 'hosts' => 'Hosts'] as $key => $label): ?><a data-tld-mode="<?= $escape($key) ?>" href="<?= $escape($statisticsUrl(['tldMeasure' => $key])) ?>"<?= $measure === $key ? ' aria-current="page"' : '' ?>><?= $escape($label) ?></a><?php endforeach; ?></nav></div>
            <div class="stat-tld-body">
              <svg class="stat-donut" data-tld-donut viewBox="0 0 200 200" role="img" aria-label="<?= $escape('Domain-Endungen: '.$formatNumber($tldTotal).' '.($measure === 'hosts' ? 'Hosts' : 'Fälle')) ?>"><circle cx="100" cy="100" r="68" fill="none" stroke="#2a3545" stroke-width="20"/><g data-tld-segments transform="rotate(-90 100 100)"><?php foreach ($segments as $index => $segment): ?><?php $length = $tldTotal > 0 ? $donutLength * $segment['count'] / $tldTotal : 0; ?><circle class="stat-donut-segment" cx="100" cy="100" r="68" fill="none" stroke="<?= $escape($segmentColors[$index % count($segmentColors)]) ?>" stroke-width="20" stroke-dasharray="<?= $escape(max(0, $length - min(2, $length * .15)).' '.$donutLength) ?>" stroke-dashoffset="<?= $escape(-$donutOffset) ?>"><title><?= $escape($segment['label'].': '.$formatNumber($segment['count'])) ?></title></circle><?php $donutOffset += $length; ?><?php endforeach; ?></g><text class="stat-donut-total" data-tld-total x="100" y="102" text-anchor="middle"><?= $escape($formatNumber($tldTotal)) ?></text><text class="stat-donut-label" data-tld-unit x="100" y="122" text-anchor="middle"><?= $measure === 'hosts' ? 'Hosts' : 'Fälle' ?></text></svg>
              <ul class="stat-tld-legend" data-tld-legend><?php foreach ($segments as $index => $segment): ?><?php $tag = $segment['url'] === null ? 'span' : 'a'; ?><li><<?= $tag ?><?= $segment['url'] === null ? '' : ' href="'.$escape($segment['url']).'"' ?> data-tld-segment="<?= $escape($segment['key']) ?>" data-count="<?= $escape($segment['count']) ?>" style="--segment-color: <?= $escape($segmentColors[$index % count($segmentColors)]) ?>"><i class="stat-color-dot" aria-hidden="true"></i><span class="stat-tld-name"><?= $escape($segment['label']) ?></span><strong class="stat-tld-count"><?= $escape($formatNumber($segment['count'])) ?></strong><span class="stat-tld-percent"><?= $escape($formatShare($tldTotal > 0 ? 100 * $segment['count'] / $tldTotal : 0)) ?></span></<?= $tag ?>></li><?php endforeach; ?></ul>
            </div>
            <?php if ($tldTotal === 0): ?><p class="stat-empty-note" data-tld-empty>Keine neuen Fälle im gewählten Zeitraum.</p><?php endif; ?>
            <p class="stat-card-note" data-tld-note><?= $measure === 'hosts' ? 'Jeder vollständige Hostname zählt einmal. Mehrere Fälle auf demselben Host erhöhen die Hostzahl nicht.' : 'Ein Fall zählt einmal. Die fünf häufigsten TLDs werden einzeln gezeigt; IP-Adressen und lokale Hosts stehen separat.' ?></p>
          </section>

          <section class="stat-card" aria-labelledby="statistics-snapshot-title">
            <div class="stat-card-heading"><div><p class="stat-card-eyebrow">AKTUELLER STAND</p><h2 id="statistics-snapshot-title">Manuelle Bewertungen</h2><p>Aktiver Bestand · unabhängig vom Zeitraum</p></div></div>
            <ul class="stat-snapshot-list"><?php foreach ($view['snapshot'] as $row): ?><li><a data-snapshot="<?= $escape($row['key']) ?>" data-count="<?= $escape($row['count']) ?>" href="<?= $escape($row['url']) ?>" style="--metric-color: <?= $escape($metricColors[$row['key']] ?? '#8d9eb6') ?>"><span class="stat-row-label"><?= $escape($row['label']) ?></span><strong class="stat-row-count"><?= $escape($formatNumber($row['count'])) ?></strong><span class="stat-snapshot-track" aria-hidden="true"><span class="stat-snapshot-fill" style="width: <?= $escape($snapshotTotal > 0 ? round(100 * $row['count'] / $snapshotTotal, 2) : 0) ?>%"></span></span></a></li><?php endforeach; ?></ul>
            <p class="stat-card-note"><?= $escape($formatNumber($view['totals']['active'])) ?> aktive Fälle · <?= $escape($formatNumber($view['totals']['archived'])) ?> im Archiv. Kontakt und technische Beobachtung sind eigene Merkmale.</p>
          </section>

          <section class="stat-card stat-calendar-card" aria-labelledby="statistics-calendar-title">
            <div class="stat-card-heading"><div><p class="stat-card-eyebrow">JAHRESBLICK</p><h2 id="statistics-calendar-title">Dein Jahr <?= $escape($view['calendarYear']) ?></h2><p>Jeder Tag auf einen Blick · das gesamte Kalenderjahr</p></div><nav class="stat-segmented" aria-label="Aktivität im Jahreskalender"><?php foreach (['reported', 'sent', 'contacted'] as $metric): ?><a data-heatmap-metric="<?= $escape($metric) ?>" href="<?= $escape($statisticsUrl(['heatmapMetric' => $metric])) ?>"<?= $calendarMetric === $metric ? ' aria-current="page"' : '' ?>><?= $escape($metrics[$metric]) ?></a><?php endforeach; ?></nav></div>
            <div class="stat-heatmap-wrap" tabindex="0" aria-label="Jahreskalender, auf schmalen Bildschirmen seitlich scrollbar"><svg class="stat-heatmap-svg" data-heatmap-chart viewBox="0 0 795 136" role="img" aria-labelledby="statistics-calendar-title" aria-describedby="statistics-heatmap-description">
              <?php foreach ([0 => 'Mo', 2 => 'Mi', 4 => 'Fr'] as $weekday => $label): ?><text x="0" y="<?= 35 + $weekday * 14 ?>"><?= $escape($label) ?></text><?php endforeach; ?>
              <?php for ($month = 1; $month <= 12; $month++): ?><?php $monthStart = $calendarStart->setDate($view['calendarYear'], $month, 1); $monthColumn = (int) floor(((int) $calendarStart->diff($monthStart)->days + $calendarOffset) / 7); ?><text x="<?= 30 + $monthColumn * 14 ?>" y="12"><?= $escape($monthLabels[$month - 1]) ?></text><?php endfor; ?>
              <?php foreach ($view['calendar'] as $index => $row): ?><?php $cell = $index + $calendarOffset; $count = $row[$calendarMetric]; $level = $count === 0 ? 0 : min(4, max(1, (int) ceil(4 * $count / $calendarMaximum))); ?><a data-heatmap-link="<?= $escape($row['date']) ?>" href="<?= $escape($row['urls'][$calendarMetric]) ?>" tabindex="-1"><rect class="stat-heatmap-cell" data-heatmap-date="<?= $escape($row['date']) ?>" data-heatmap-index="<?= $index ?>" data-count="<?= $escape($count) ?>" x="<?= 30 + (int) floor($cell / 7) * 14 ?>" y="<?= 25 + ($cell % 7) * 14 ?>" width="12" height="12" rx="2" fill="<?= $escape($heatmapPalettes[$calendarMetric][$level]) ?>"><title><?= $escape((new \DateTimeImmutable($row['date']))->format('d.m.Y').' · '.$metrics[$calendarMetric].': '.$count) ?></title></rect></a><?php endforeach; ?>
            </svg></div>
            <p class="stat-card-note stat-heatmap-scroll-hint">Jahreskalender seitlich scrollen ↔</p>
            <div class="stat-heatmap-footer"><span id="statistics-heatmap-description">Kalenderjahr <?= $escape($view['calendarYear']) ?> · <?= $escape($metrics[$calendarMetric]) ?> · Europe/Berlin</span><span class="stat-heatmap-scale" aria-hidden="true">Weniger<?php foreach ($heatmapPalettes[$calendarMetric] as $color): ?><i style="--cell-color: <?= $escape($color) ?>"></i><?php endforeach; ?>Mehr</span></div>
            <p class="stat-heatmap-selection" data-heatmap-selection data-js-only hidden aria-live="polite">Tippe auf einen Tag, um seine Fälle zu öffnen.</p>
            <p class="stat-card-note" data-no-js><a href="<?= $escape($statisticsUrl(['period' => 'year', 'anchor' => $view['calendarYear'].'-01-01', 'granularity' => 'day'])) ?>">Alle Jahreswerte als Tagesdiagramm und Tabelle öffnen ↗</a></p>
          </section>

          <section class="stat-card stat-aging-card" aria-labelledby="statistics-aging-title">
            <div class="stat-card-heading"><div><p class="stat-card-eyebrow">AKTUELLER STAND</p><h2 id="statistics-aging-title">Offene Kontaktarbeit</h2><p>Bestätigte Fälle ohne Kontakt oder Versand · Alter seit Eingang</p></div></div>
            <ul class="stat-aging-list"><?php foreach ($view['aging'] as $row): ?><li><a data-aging="<?= $escape($row['key']) ?>" data-count="<?= $escape($row['count']) ?>" href="<?= $escape($row['url']) ?>"><span class="stat-row-label"><?= $escape($row['label']) ?></span><strong class="stat-row-count"><?= $escape($formatNumber($row['count'])) ?></strong></a></li><?php endforeach; ?></ul>
            <p class="stat-card-note">Aktiver Bestand, unabhängig vom gewählten Zeitraum<?= $filters['tld'] !== '' ? ' · mit dem gewählten TLD-Filter' : '' ?>.</p>
          </section>
        </div>

        <div class="stat-definitions"><p>Gemeldet = neue Fälle im Eingang · Versendet = als versendet markierte Fälle · Behoben = aufgezeichnete manuelle Behebung.</p><details><summary>Zählweise und verfügbare Historie</summary><ul><li>Jeder Fall zählt je Schritt höchstens einmal. Kontaktmarkierung und Versandmarkierung werden getrennt gezählt.</li><li>Aktivitäten bleiben im Rückblick erhalten, solange ihre Fälle gespeichert sind; verworfene Fälle und Duplikate gehören zum Rückblick.</li><li>Alte Behebungen ohne bekanntes Datum erscheinen nicht im Verlauf.</li><?php foreach ($view['notes'] as $note): ?><li><?= $escape($note) ?></li><?php endforeach; ?></ul></details></div>
      </div>
    </main>

    <nav class="studio-workspace-nav" aria-label="Arbeitsbereiche">
      <a class="studio-workspace-link" href="/"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M4 15v5h16v-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Eingang</span></a>
      <a class="studio-workspace-link" href="/findings"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg><span>Bestand</span></a>
      <a class="studio-workspace-link studio-workspace-link-active" href="/statistics" aria-current="page"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 20h16M7 16V9m5 7V4m5 12v-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg><span>Statistiken</span></a>
      <a class="studio-settings-link" href="/legacy/settings" aria-label="Einstellungen · klassische Ansicht"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 3-1 3-3 1-2 3 2 2v3l3 1 1 3h4l1-3 3-1v-3l2-2-2-3-3-1-1-3H9Z" transform="translate(1 1)" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.4"/></svg></a>
    </nav>
  </div>
  <script id="statistics-data" type="application/json"><?= $payload ?></script>
</body>
</html>
