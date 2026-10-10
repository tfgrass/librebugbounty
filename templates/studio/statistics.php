<?php

/** @var array<string, mixed> $view */
/** @var callable $escape */
$period = $view['period'];
$filters = $view['filters'];
$series = $view['series'];
$history = $view['history'] ?? null;
$contactHistoryUrl = '/findings?'.http_build_query(['scope' => 'all', 'event' => 'contacted'] + ($filters['tld'] === '' ? [] : ['tld' => $filters['tld']]), '', '&', PHP_QUERY_RFC3986);
$metrics = ['reported' => 'Gemeldet', 'contacted' => 'Kontaktiert', 'fixed' => 'Behoben'];
$metricColors = ['reported' => '#80adff', 'contacted' => '#bca4ff', 'fixed' => '#77dbb0', 'confirmed' => '#e9bf7e', 'hosts' => '#9fb0c8'];
$mainMetrics = array_keys($metrics);
$formatShare = static fn (float $share): string => $share > 0 && $share < 1 ? '< 1%' : $t('{number} %', ['number' => $formatNumber($share)]);
$fullRowLabel = static fn (array $row): string => $formatDate($row['from']).($row['from'] === $row['to'] ? '' : ' – '.$formatDate($row['to']));
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
$maximum = 1;
foreach ($mainMetrics as $metric) {
    $maximum = max($maximum, ...array_column($series, $metric));
}
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
$axisTicks = range($axisMaximum, 0, -$step);
$plotHeight = 240;
$plotPath = static function (string $metric) use ($series, $axisMaximum, $plotHeight): string {
    $points = [];
    $last = count($series) - 1;
    foreach ($series as $index => $row) {
        $x = $last === 0 ? 500 : 1000 * $index / $last;
        $y = $plotHeight - $plotHeight * $row[$metric] / $axisMaximum;
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
    'contacted' => ['#283342', '#494068', '#69548e', '#9378bf', '#bca4ff'],
    'fixed' => ['#283342', '#2a5149', '#3e7a66', '#59ad8e', '#77dbb0'],
];
$calendarMaximum = max(1, ...array_column($view['calendar'], $calendarMetric));
$calendarStart = new \DateTimeImmutable($view['calendarYear'].'-01-01');
$calendarOffset = (int) $calendarStart->format('N') - 1;
$monthLabels = $locale === 'de'
    ? ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez']
    : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$weekdayLabels = $locale === 'de' ? [0 => 'Mo', 2 => 'Mi', 4 => 'Fr'] : [0 => 'Mon', 2 => 'Wed', 4 => 'Fri'];
$periodLabel = $period['kind'] === 'all' ? $t('Gesamter Zeitraum') : $formatDate($period['from']).' – '.$formatDate($period['to']);
$localizeServiceText = static function (string $text) use ($t, $formatDate, $locale): string {
    $translated = $t($text);
    if ($translated !== $text || $locale === 'de') {
        return $translated;
    }
    if (preg_match('/^Bewertungshistorie ist erst ab (\d{2}\.\d{2}\.\d{4}) im erhaltenen Bestand belegt\.$/', $text, $match) === 1) {
        $date = \DateTimeImmutable::createFromFormat('!d.m.Y', $match[1]);
        return $t('Bewertungshistorie ist erst ab {date} im erhaltenen Bestand belegt.', ['date' => $date === false ? $match[1] : $formatDate($date)]);
    }
    if (preg_match('/^Der lange Zeitraum wird für eine lesbare Darstellung in (?:(\d+)-Monatsgruppen|(Wochen|Monaten)) zusammengefasst\.$/', $text, $match) === 1) {
        $group = ($match[1] ?? '') !== '' ? $t('{months}-Monatsgruppen', ['months' => $match[1]]) : $t($match[2]);
        return $t('Der lange Zeitraum wird für eine lesbare Darstellung in {groups} zusammengefasst.', ['groups' => $group]);
    }
    if (preg_match('/^Vergleich: (\d{2}\.\d{2}\.\d{4}(?: \d{2}:\d{2})?) bis (\d{2}\.\d{2}\.\d{4}(?: \d{2}:\d{2})?) mit (\d{2}\.\d{2}\.\d{4}(?: \d{2}:\d{2})?) bis (\d{2}\.\d{2}\.\d{4}(?: \d{2}:\d{2})?) \(([^)]+)\)$/', $text, $match) === 1) {
        $dates = [];
        foreach (array_slice($match, 1, 4) as $value) {
            $withTime = str_contains($value, ':');
            $date = \DateTimeImmutable::createFromFormat($withTime ? '!d.m.Y H:i' : '!d.m.Y', $value);
            $dates[] = $date === false ? $value : $formatDate($date).($withTime ? ' '.$date->format('H:i') : '');
        }
        return $t('Vergleich: {a} bis {b} mit {c} bis {d} ({context})', ['a' => $dates[0], 'b' => $dates[1], 'c' => $dates[2], 'd' => $dates[3], 'context' => $t($match[5])]);
    }
    return $text;
};
$payload = json_encode($view, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$isEmpty = array_sum(array_map(static fn (string $metric): int => $view['kpis'][$metric]['count'], $mainMetrics)) === 0;
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($t('Statistiken')) ?> · <?= $escape(\App\AppInfo::NAME.' '.\App\AppInfo::RELEASE_NAME) ?></title>
  <link rel="stylesheet" href="/css/studio.css">
  <link rel="stylesheet" href="/css/studio-statistics.css">
  <script src="/js/i18n.js" defer></script>
  <script src="/js/statistics.js" defer></script>
</head>
<body data-studio data-statistics>
  <div class="studio-shell">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
      <div class="studio-header-tools"><?php require __DIR__.'/language.php'; ?></div>
    </header>

    <main class="stat-main" id="statistics">
      <div class="stat-content">
        <div class="stat-heading">
          <div><p class="studio-eyebrow"><?= $escape($t('Dein Überblick')) ?></p><h1><?= $escape($t('Statistiken')) ?></h1><p><?= $escape($t('Von der ersten Meldung bis zur Behebung.')) ?></p></div>
          <span class="stat-heading-mark" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 19h16M6 15V9m6 6V4m6 11v-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>
        </div>

        <?php if ($isFirstStart): ?>
          <section class="studio-first-start" data-first-start aria-labelledby="statistics-first-start-title">
            <div><h2 id="statistics-first-start-title"><?= $escape($t('Deine Statistik beginnt mit der ersten URL')) ?></h2><p><?= $escape($t('Erfasse Fälle, halte Kontakte fest und bewerte Ergebnisse. Deine Aktivität erscheint hier, sobald sie gespeichert ist.')) ?></p></div>
            <a data-first-start-cta href="/"><?= $escape($t('URL erfassen')) ?> <span aria-hidden="true">↗</span></a>
          </section>
        <?php endif; ?>

        <section class="stat-controls" aria-label="<?= $escape($t('Zeitraum und Filter')) ?>">
          <div class="stat-period-bar">
            <nav class="stat-segmented" aria-label="<?= $escape($t('Statistikzeitraum')) ?>">
              <?php foreach (['week' => 'Woche', 'month' => 'Monat', 'last_3_months' => 'Letzte 3 Monate', 'year' => 'Jahr', 'all' => 'Gesamt'] as $kind => $label): ?>
                <a data-period="<?= $escape($kind) ?>" href="<?= $escape($statisticsUrl(['period' => $kind])) ?>"<?= $period['kind'] === $kind ? ' aria-current="page"' : '' ?>><?= $escape($t($label)) ?></a>
              <?php endforeach; ?>
            </nav>
            <div class="stat-period-navigation">
              <?php if ($period['previousUrl'] !== null): ?><a class="stat-icon-button" data-period-previous href="<?= $escape($period['previousUrl']) ?>" aria-label="<?= $escape($t('Vorheriger Zeitraum')) ?>">←</a><?php else: ?><span class="stat-icon-button" aria-disabled="true" aria-label="<?= $escape($t('Kein vorheriger Zeitraum')) ?>">←</span><?php endif; ?>
              <div class="stat-period-label"><?= $escape($periodLabel) ?><small><?= $period['ongoing'] ? $escape($t('Laufender Zeitraum · ')) : '' ?>Europe/Berlin</small></div>
              <?php if ($period['nextUrl'] !== null): ?><a class="stat-icon-button" data-period-next href="<?= $escape($period['nextUrl']) ?>" aria-label="<?= $escape($t('Nächster Zeitraum')) ?>">→</a><?php else: ?><span class="stat-icon-button" aria-disabled="true" aria-label="<?= $escape($t('Kein nächster Zeitraum')) ?>">→</span><?php endif; ?>
            </div>
          </div>
          <div class="stat-filter-row">
            <form method="get" action="/statistics" id="statistics-filters" class="stat-filter-row">
              <?php foreach ($baseQuery as $name => $value): ?><?php if (!in_array($name, ['granularity', 'tld'], true)): ?><input type="hidden" name="<?= $escape($name) ?>" value="<?= $escape($value) ?>"><?php endif; ?><?php endforeach; ?>
              <label for="statistics-granularity"><?= $escape($t('Zusammenfassung')) ?><select name="granularity" id="statistics-granularity"><?php foreach (['day' => 'Pro Tag', 'week' => 'Pro Woche', 'month' => 'Pro Monat'] as $key => $label): ?><option value="<?= $escape($key) ?>"<?= $period['requestedGranularity'] === $key ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?></select></label>
              <label for="statistics-tld"><?= $escape($t('Domain-Endung')) ?><select name="tld" id="statistics-tld"><?php foreach ($view['tldOptions'] as $option): ?><option value="<?= $escape($option['key']) ?>"<?= $filters['tld'] === $option['key'] ? ' selected' : '' ?>><?= $escape($t($option['label'])) ?></option><?php endforeach; ?></select></label>
              <button class="stat-button" type="submit"><?= $escape($t('Anwenden')) ?></button>
            </form>
            <details class="stat-custom"<?= $period['kind'] === 'custom' ? ' open' : '' ?>>
              <summary><?= $escape($t('Eigener Zeitraum')) ?></summary>
              <form method="get" action="/statistics" id="statistics-custom-period">
                <input type="hidden" name="period" value="custom">
                <?php foreach ($baseQuery as $name => $value): ?><?php if (!in_array($name, ['period', 'from', 'to'], true)): ?><input type="hidden" name="<?= $escape($name) ?>" value="<?= $escape($value) ?>"><?php endif; ?><?php endforeach; ?>
                <label><?= $escape($t('Von')) ?><input type="date" name="from" value="<?= $escape($period['from']) ?>" required></label>
                <label><?= $escape($t('Bis')) ?><input type="date" name="to" value="<?= $escape($period['to']) ?>" required></label>
                <button class="stat-button" type="submit"><?= $escape($t('Zeitraum anzeigen')) ?></button>
              </form>
            </details>
          </div>
          <?php if ($filters['tld'] !== ''): ?><p class="stat-filter-summary"><?= $escape($t('Domain-Endung {tld} in allen Auswertungen', ['tld' => $filters['tld']])) ?> <a href="<?= $escape($statisticsUrl(['tld' => ''])) ?>"><?= $escape($t('Filter entfernen')) ?></a></p><?php endif; ?>
        </section>

        <section class="stat-kpis" aria-label="<?= $escape($t('Kennzahlen im gewählten Zeitraum')) ?>">
          <?php foreach (['reported', 'contacted', 'fixed', 'hosts'] as $metric): ?>
            <?php $kpi = $view['kpis'][$metric]; $tag = $kpi['url'] === null ? 'div' : 'a'; $hint = match ($metric) { 'reported' => 'Neue Fälle im Eingang', 'contacted' => 'Gespeicherte Kontaktmarkierungen', 'fixed' => 'Manuell als behoben bewertet', default => 'Verschiedene Hosts neuer Fälle' }; ?>
            <<?= $tag ?> class="stat-kpi" data-stat-kpi="<?= $escape($metric) ?>" data-count="<?= $escape($kpi['count']) ?>" style="--metric-color: <?= $escape($metricColors[$metric]) ?>"<?= $kpi['url'] === null ? '' : ' href="'.$escape($kpi['url']).'"' ?>>
              <span class="stat-kpi-label"><?= $escape($t($metrics[$metric] ?? $kpi['label'])) ?><?= $kpi['url'] === null ? '' : '<span aria-hidden="true">↗</span>' ?></span>
              <strong class="stat-kpi-value"><?= $escape($formatNumber($kpi['count'])) ?></strong>
              <span class="stat-kpi-bottom"><span><?= $escape($t($hint)) ?></span>
                <?php if ($kpi['delta'] !== null): ?><span class="stat-kpi-delta" title="<?= $escape($period['comparisonLabel'] === null ? '' : $localizeServiceText($period['comparisonLabel'])) ?>"><?= $kpi['delta'] > 0 ? '+' : '' ?><?= $escape($formatNumber($kpi['delta'])) ?><?= $kpi['deltaPercent'] === null ? '' : ' ('.($kpi['deltaPercent'] > 0 ? '+' : '').$escape($formatNumber($kpi['deltaPercent'], 1)).'%)' ?> <?= $escape($t('zum Vergleichszeitraum')) ?></span><?php endif; ?>
              </span>
            </<?= $tag ?>>
          <?php endforeach; ?>
        </section>

        <section class="stat-card stat-chart-card" aria-labelledby="statistics-activity-title">
          <div class="stat-card-heading"><div><p class="stat-card-eyebrow"><?= $escape($t('Aktivität')) ?></p><h2 id="statistics-activity-title"><?= $escape($t('Deine Arbeit im Verlauf')) ?></h2><p><?= $escape($t(match ($period['granularity']) { 'week' => 'Wochenwerte', 'month' => $period['bucketMonths'] > 1 ? '{months}-Monatswerte' : 'Monatswerte', default => 'Tageswerte' }, ['months' => $formatNumber($period['bucketMonths'])])) ?> · <?= $escape($t('Jeder Schritt zählt an seinem eigenen Datum.')) ?></p><p class="stat-scale-explanation"><?= $escape($t('Gemeinsame Skala für gemeldete, kontaktierte und behobene Fälle.')) ?></p></div></div>
          <div class="stat-static-legend" data-no-js><?php foreach ($metrics as $metric => $label): ?><span data-metric="<?= $escape($metric) ?>" style="--metric-color: <?= $escape($metricColors[$metric]) ?>"><i class="stat-legend-line" aria-hidden="true"></i><?= $escape($t($label)) ?></span><?php endforeach; ?></div>
          <fieldset class="stat-series-controls" data-js-only hidden><legend class="studio-sr-only"><?= $escape($t('Diagrammlinien auswählen')) ?></legend><?php foreach ($metrics as $metric => $label): ?><label data-metric="<?= $escape($metric) ?>" style="--metric-color: <?= $escape($metricColors[$metric]) ?>"><input type="checkbox" data-series-toggle="<?= $escape($metric) ?>" checked><i class="stat-legend-line" aria-hidden="true"></i><?= $escape($t($label)) ?></label><?php endforeach; ?></fieldset>
          <div class="stat-chart-plot">
            <div class="stat-chart-axis-y" aria-hidden="true" data-chart-y-axis><?php foreach ($axisTicks as $tick): ?><span><?= $escape($formatNumber($tick)) ?></span><?php endforeach; ?></div>
            <div class="stat-chart-graphic">
              <svg class="stat-chart-svg" data-activity-chart data-chart-axis-max="<?= $escape($axisMaximum) ?>" viewBox="0 0 1000 <?= $plotHeight ?>" preserveAspectRatio="none" role="img" aria-labelledby="statistics-activity-title" aria-describedby="statistics-chart-summary">
                <g data-chart-grid><?php foreach ($axisTicks as $tick): ?><?php $tickY = $plotHeight - $plotHeight * $tick / $axisMaximum; ?><line class="stat-chart-grid" x1="0" x2="1000" y1="<?= $escape($tickY) ?>" y2="<?= $escape($tickY) ?>"/><?php endforeach; ?></g>
                <line class="stat-crosshair" data-chart-crosshair x1="0" x2="0" y1="0" y2="<?= $plotHeight ?>" hidden/>
                <?php foreach ($metrics as $metric => $label): ?>
                  <g data-chart-series="<?= $escape($metric) ?>" data-count="<?= $escape($view['kpis'][$metric]['count']) ?>" style="--metric-color: <?= $escape($metricColors[$metric]) ?>">
                    <path class="stat-series-line" data-series-path="<?= $escape($metric) ?>" d="<?= $escape($plotPath($metric)) ?>" stroke="<?= $escape($metricColors[$metric]) ?>"/>
                    <?php if (count($series) === 1): ?><circle data-series-single="<?= $escape($metric) ?>" cx="500" cy="<?= $escape($plotHeight - $plotHeight * $series[0][$metric] / $axisMaximum) ?>" r="3" fill="<?= $escape($metricColors[$metric]) ?>" vector-effect="non-scaling-stroke"/><?php endif; ?>
                    <circle data-chart-point="<?= $escape($metric) ?>" cx="0" cy="0" r="4" fill="<?= $escape($metricColors[$metric]) ?>" stroke="#1b212a" stroke-width="1.5" vector-effect="non-scaling-stroke" hidden/>
                  </g>
                <?php endforeach; ?>
              </svg>
              <div class="stat-chart-axis-x stat-shared-axis" aria-hidden="true"><?php if ($series !== []): ?><span><?= $escape($fullRowLabel($series[0])) ?></span><?php if (count($series) > 2): ?><span><?= $escape($fullRowLabel($series[(int) floor((count($series) - 1) / 2)])) ?></span><?php endif; ?><?php if (count($series) > 1): ?><span><?= $escape($fullRowLabel($series[count($series) - 1])) ?></span><?php endif; ?><?php endif; ?></div>
            </div>
          </div>
          <p id="statistics-chart-summary" class="studio-sr-only"><?= $escape($t('Aktivität im Zeitraum {period}. Gemeldet, Kontaktiert und Behoben auf einer gemeinsamen Skala von 0 bis {maximum} Fällen. Alle Werte stehen in der aufklappbaren Tabelle.', ['period' => $periodLabel, 'maximum' => $formatNumber($axisMaximum)])) ?></p>
          <div class="stat-chart-inspection-slot" data-js-only hidden><div class="stat-chart-inspection" data-chart-tooltip hidden aria-live="polite"><strong data-chart-tooltip-date></strong><div class="stat-chart-inspection-values" data-chart-tooltip-values></div></div></div>
          <p class="stat-chart-instructions" data-js-only hidden><?= $escape($t('Tippe ins Diagramm oder bewege den Zeiger. Mit den Pfeiltasten wechselst du zwischen den Werten.')) ?></p>
          <p class="stat-empty-note" data-chart-empty-selection hidden><?= $escape($t('Wähle mindestens eine Reihe für den Verlauf.')) ?></p>
          <?php if ($isEmpty): ?><p class="stat-empty-note"><?= $escape($t('Für diese Auswahl sind noch keine Meldungen, Kontakte oder Behebungen aufgezeichnet.')) ?></p><?php endif; ?>
          <?php if ($period['comparisonLabel'] !== null): ?><p class="stat-card-note"><?= $escape($localizeServiceText($period['comparisonLabel'])) ?></p><?php endif; ?>
          <details class="stat-table-details" data-series-table><summary><?= $escape($t('Alle Diagrammwerte als Tabelle')) ?></summary><div class="stat-table-wrap"><table class="stat-table"><caption class="studio-sr-only"><?= $escape($t('Gemeldete, kontaktierte und behobene Fälle nach Zeitraum')) ?></caption><thead><tr><th scope="col"><?= $escape($t('Zeitraum')) ?></th><?php foreach ($metrics as $metric => $label): ?><th scope="col"><?= $escape($t($label)) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($series as $row): ?><tr><td><?= $escape($fullRowLabel($row)) ?></td><?php foreach ($metrics as $metric => $label): ?><td><a href="<?= $escape($row['urls'][$metric]) ?>" aria-label="<?= $escape($t('{label}: {count} · {period}', ['label' => $t($label), 'count' => $formatNumber($row[$metric]), 'period' => $fullRowLabel($row)])) ?>"><?= $escape($formatNumber($row[$metric])) ?></a></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></details>
        </section>

        <?php if ($history !== null): ?>
          <section class="stat-card stat-history-card" aria-labelledby="statistics-history-title">
            <div class="stat-card-heading"><div><p class="stat-card-eyebrow"><?= $escape($t('Gesamter gespeicherter Bestand')) ?></p><h2 id="statistics-history-title"><?= $escape($t('Gespeicherte Historie')) ?></h2><p><?= $escape($t('Unabhängig vom gewählten Zeitraum')) ?><?= $filters['tld'] === '' ? '' : $escape($t(' · mit dem gewählten TLD-Filter')) ?></p></div><?php if ($period['kind'] !== 'all'): ?><a class="stat-button" data-history-timeline href="<?= $escape($statisticsUrl(['period' => 'all'])) ?>"><?= $escape($t('Gesamten Verlauf ansehen')) ?> <span aria-hidden="true">↗</span></a><?php endif; ?></div>
            <div class="stat-history-values">
              <a class="stat-history-item" data-history-key="contacts" data-count="<?= $escape($history['contactDatedCount']) ?>" href="<?= $escape($contactHistoryUrl) ?>"><span><?= $escape($t('Gespeicherte Kontakte')) ?> <span aria-hidden="true">↗</span></span><strong><?= $escape($formatNumber($history['contactDatedCount'])) ?></strong><small><?= $escape($history['contactFrom'] === null ? $t('Keine Kontaktmarkierungen vorhanden') : $t('Gespeicherte Markierungen ab {date}', ['date' => $formatDate($history['contactFrom'])])) ?></small></a>
              <?php foreach ($history['items'] as $item): ?><a class="stat-history-item" data-history-key="<?= $escape($item['key']) ?>" data-count="<?= $escape($item['count']) ?>" href="<?= $escape($item['url']) ?>"><span><?= $escape($t($item['label'])) ?> <span aria-hidden="true">↗</span></span><strong><?= $escape($formatNumber($item['count'])) ?></strong><small><?= $escape($t('Historische Kennzeichnung · Datum unbekannt')) ?></small></a><?php endforeach; ?>
            </div>
            <p class="stat-card-note"><?= $escape($t('Die drei historischen Statusgruppen können dieselben Fälle enthalten und werden nicht addiert. Ohne belegtes Datum erhalten diese Markierungen keinen Punkt im Zeitverlauf.')) ?></p>
            <p class="stat-card-note"><?= $escape($t('Kontaktpunkte verwenden das gespeicherte Datum der Kontaktmarkierung. Dieses muss nicht der erste Kontakt sein.')) ?></p>
          </section>
        <?php endif; ?>

        <div class="stat-grid">
          <section class="stat-card stat-tld-card" aria-labelledby="statistics-tld-title">
            <div class="stat-card-heading"><div><p class="stat-card-eyebrow"><?= $escape($t('Verteilung')) ?></p><h2 id="statistics-tld-title"><?= $escape($t('Domain-Endungen')) ?></h2><p><?= $escape($t('Neue Fälle im gewählten Zeitraum')) ?></p></div><nav class="stat-segmented" aria-label="<?= $escape($t('TLD-Zählweise')) ?>"><?php foreach (['cases' => 'Fälle', 'hosts' => 'Hosts'] as $key => $label): ?><a data-tld-mode="<?= $escape($key) ?>" href="<?= $escape($statisticsUrl(['tldMeasure' => $key])) ?>"<?= $measure === $key ? ' aria-current="page"' : '' ?>><?= $escape($t($label)) ?></a><?php endforeach; ?></nav></div>
            <div class="stat-tld-body">
              <svg class="stat-donut" data-tld-donut viewBox="0 0 200 200" role="img" aria-label="<?= $escape($t('Domain-Endungen: {count} {unit}', ['count' => $formatNumber($tldTotal), 'unit' => $t($measure === 'hosts' ? 'Hosts' : 'Fälle')])) ?>"><circle cx="100" cy="100" r="68" fill="none" stroke="#2a3545" stroke-width="20"/><g data-tld-segments transform="rotate(-90 100 100)"><?php foreach ($segments as $index => $segment): ?><?php $length = $tldTotal > 0 ? $donutLength * $segment['count'] / $tldTotal : 0; ?><circle class="stat-donut-segment" cx="100" cy="100" r="68" fill="none" stroke="<?= $escape($segmentColors[$index % count($segmentColors)]) ?>" stroke-width="20" stroke-dasharray="<?= $escape(max(0, $length - min(2, $length * .15)).' '.$donutLength) ?>" stroke-dashoffset="<?= $escape(-$donutOffset) ?>"><title><?= $escape($t($segment['label']).': '.$formatNumber($segment['count'])) ?></title></circle><?php $donutOffset += $length; ?><?php endforeach; ?></g><text class="stat-donut-total" data-tld-total x="100" y="102" text-anchor="middle"><?= $escape($formatNumber($tldTotal)) ?></text><text class="stat-donut-label" data-tld-unit x="100" y="122" text-anchor="middle"><?= $escape($t($measure === 'hosts' ? 'Hosts' : 'Fälle')) ?></text></svg>
              <ul class="stat-tld-legend" data-tld-legend><?php foreach ($segments as $index => $segment): ?><?php $tag = $segment['url'] === null ? 'span' : 'a'; ?><li><<?= $tag ?><?= $segment['url'] === null ? '' : ' href="'.$escape($segment['url']).'"' ?> data-tld-segment="<?= $escape($segment['key']) ?>" data-count="<?= $escape($segment['count']) ?>" style="--segment-color: <?= $escape($segmentColors[$index % count($segmentColors)]) ?>"><i class="stat-color-dot" aria-hidden="true"></i><span class="stat-tld-name"><?= $escape($t($segment['label'])) ?></span><strong class="stat-tld-count"><?= $escape($formatNumber($segment['count'])) ?></strong><span class="stat-tld-percent"><?= $escape($formatShare($tldTotal > 0 ? 100 * $segment['count'] / $tldTotal : 0)) ?></span></<?= $tag ?>></li><?php endforeach; ?></ul>
            </div>
            <?php if ($tldTotal === 0): ?><p class="stat-empty-note" data-tld-empty><?= $escape($t('Keine neuen Fälle im gewählten Zeitraum.')) ?></p><?php endif; ?>
            <p class="stat-card-note" data-tld-note><?= $escape($t($measure === 'hosts' ? 'Jeder vollständige Hostname zählt einmal. Mehrere Fälle auf demselben Host erhöhen die Hostzahl nicht.' : 'Ein Fall zählt einmal. Die fünf häufigsten TLDs werden einzeln gezeigt; IP-Adressen und lokale Hosts stehen separat.')) ?></p>
          </section>

          <section class="stat-card" aria-labelledby="statistics-snapshot-title">
            <div class="stat-card-heading"><div><p class="stat-card-eyebrow"><?= $escape($t('Aktueller Stand')) ?></p><h2 id="statistics-snapshot-title"><?= $escape($t('Manuelle Bewertungen')) ?></h2><p><?= $escape($t('Aktiver Bestand · unabhängig vom Zeitraum')) ?></p></div></div>
            <ul class="stat-snapshot-list"><?php foreach ($view['snapshot'] as $row): ?><li><a data-snapshot="<?= $escape($row['key']) ?>" data-count="<?= $escape($row['count']) ?>" href="<?= $escape($row['url']) ?>" style="--metric-color: <?= $escape($metricColors[$row['key']] ?? '#8d9eb6') ?>"><span class="stat-row-label"><?= $escape($t($row['label'])) ?></span><strong class="stat-row-count"><?= $escape($formatNumber($row['count'])) ?></strong><span class="stat-snapshot-track" aria-hidden="true"><span class="stat-snapshot-fill" style="width: <?= $escape($snapshotTotal > 0 ? round(100 * $row['count'] / $snapshotTotal, 2) : 0) ?>%"></span></span></a></li><?php endforeach; ?></ul>
            <p class="stat-card-note"><?= $escape($t('{active} aktive Fälle · {archived} im Archiv. Kontakt und technische Beobachtung sind eigene Merkmale.', ['active' => $formatNumber($view['totals']['active']), 'archived' => $formatNumber($view['totals']['archived'])])) ?></p>
          </section>

          <section class="stat-card stat-calendar-card" aria-labelledby="statistics-calendar-title">
            <div class="stat-card-heading"><div><p class="stat-card-eyebrow"><?= $escape($t('Jahresblick')) ?></p><h2 id="statistics-calendar-title"><?= $escape($t('Dein Jahr {year}', ['year' => $view['calendarYear']])) ?></h2><p><?= $escape($t('Jeder Tag auf einen Blick · das gesamte Kalenderjahr')) ?></p></div><nav class="stat-segmented" aria-label="<?= $escape($t('Aktivität im Jahreskalender')) ?>"><?php foreach ($mainMetrics as $metric): ?><a data-heatmap-metric="<?= $escape($metric) ?>" href="<?= $escape($statisticsUrl(['heatmapMetric' => $metric])) ?>"<?= $calendarMetric === $metric ? ' aria-current="page"' : '' ?>><?= $escape($t($metrics[$metric])) ?></a><?php endforeach; ?></nav></div>
            <div class="stat-heatmap-wrap" tabindex="0" aria-label="<?= $escape($t('Jahreskalender, auf schmalen Bildschirmen seitlich scrollbar')) ?>"><svg class="stat-heatmap-svg" data-heatmap-chart viewBox="0 0 795 136" role="img" aria-labelledby="statistics-calendar-title" aria-describedby="statistics-heatmap-description">
              <?php foreach ($weekdayLabels as $weekday => $label): ?><text x="0" y="<?= 35 + $weekday * 14 ?>"><?= $escape($label) ?></text><?php endforeach; ?>
              <?php for ($month = 1; $month <= 12; $month++): ?><?php $monthStart = $calendarStart->setDate($view['calendarYear'], $month, 1); $monthColumn = (int) floor(((int) $calendarStart->diff($monthStart)->days + $calendarOffset) / 7); ?><text x="<?= 30 + $monthColumn * 14 ?>" y="12"><?= $escape($monthLabels[$month - 1]) ?></text><?php endfor; ?>
              <?php foreach ($view['calendar'] as $index => $row): ?><?php $cell = $index + $calendarOffset; $count = $row[$calendarMetric]; $level = $count === 0 ? 0 : min(4, max(1, (int) ceil(4 * $count / $calendarMaximum))); ?><a data-heatmap-link="<?= $escape($row['date']) ?>" href="<?= $escape($row['urls'][$calendarMetric]) ?>" tabindex="-1"><rect class="stat-heatmap-cell" data-heatmap-date="<?= $escape($row['date']) ?>" data-heatmap-index="<?= $index ?>" data-count="<?= $escape($count) ?>" x="<?= 30 + (int) floor($cell / 7) * 14 ?>" y="<?= 25 + ($cell % 7) * 14 ?>" width="12" height="12" rx="2" fill="<?= $escape($heatmapPalettes[$calendarMetric][$level]) ?>"><title><?= $escape($formatDate($row['date']).' · '.$t($metrics[$calendarMetric]).': '.$formatNumber($count)) ?></title></rect></a><?php endforeach; ?>
            </svg></div>
            <p class="stat-card-note stat-heatmap-scroll-hint"><?= $escape($t('Jahreskalender seitlich scrollen ↔')) ?></p>
            <div class="stat-heatmap-footer"><span id="statistics-heatmap-description"><?= $escape($t('Kalenderjahr {year} · {metric} · Europe/Berlin', ['year' => $view['calendarYear'], 'metric' => $t($metrics[$calendarMetric])])) ?></span><span class="stat-heatmap-scale" aria-hidden="true"><?= $escape($t('Weniger')) ?><?php foreach ($heatmapPalettes[$calendarMetric] as $color): ?><i style="--cell-color: <?= $escape($color) ?>"></i><?php endforeach; ?><?= $escape($t('Mehr')) ?></span></div>
            <p class="stat-heatmap-selection" data-heatmap-selection data-js-only hidden aria-live="polite"><?= $escape($t('Tippe auf einen Tag, um seine Fälle zu öffnen.')) ?></p>
            <p class="stat-card-note" data-no-js><a href="<?= $escape($statisticsUrl(['period' => 'year', 'anchor' => $view['calendarYear'].'-01-01', 'granularity' => 'day'])) ?>"><?= $escape($t('Alle Jahreswerte als Tagesdiagramm und Tabelle öffnen ↗')) ?></a></p>
          </section>

          <?php require __DIR__.'/follow-up-statistics.php'; ?>
          <section class="stat-card stat-aging-card" aria-labelledby="statistics-aging-title">
            <div class="stat-card-heading"><div><p class="stat-card-eyebrow"><?= $escape($t('Aktueller Stand')) ?></p><h2 id="statistics-aging-title"><?= $escape($t('Offene Kontaktarbeit')) ?></h2><p><?= $escape($t('Bestätigte Fälle ohne Kontakt · Alter seit Eingang')) ?></p></div></div>
            <ul class="stat-aging-list"><?php foreach ($view['aging'] as $row): ?><li><a data-aging="<?= $escape($row['key']) ?>" data-count="<?= $escape($row['count']) ?>" href="<?= $escape($row['url']) ?>"><span class="stat-row-label"><?= $escape($t($row['label'])) ?></span><strong class="stat-row-count"><?= $escape($formatNumber($row['count'])) ?></strong></a></li><?php endforeach; ?></ul>
            <p class="stat-card-note"><?= $escape($t('Aktiver Bestand, unabhängig vom gewählten Zeitraum{filter}.', ['filter' => $filters['tld'] !== '' ? $t(' · mit dem gewählten TLD-Filter') : ''])) ?></p>
          </section>
        </div>

        <div class="stat-definitions"><p><?= $escape($t('Gemeldet = neue Fälle im Eingang')) ?> · <?= $escape($t('Kontaktiert = Fälle mit gespeicherter Kontaktmarkierung')) ?> · <?= $escape($t('Behoben = aufgezeichnete manuelle Behebung.')) ?></p><details><summary><?= $escape($t('Zählweise und verfügbare Historie')) ?></summary><ul><li><?= $escape($t('Jeder Fall zählt je Schritt höchstens einmal. Gemeldet zählt den Eingang, Kontaktiert die Kontaktmarkierung und Behoben die erste manuelle Behebung.')) ?></li><li><?= $escape($t('Aktivitäten bleiben im Rückblick erhalten, solange ihre Fälle gespeichert sind; verworfene Fälle und Duplikate gehören zum Rückblick.')) ?></li><li><?= $escape($t('Historische Behebungen ohne bekanntes Datum erscheinen nicht im Verlauf.')) ?></li><?php foreach ($view['notes'] as $note): ?><li><?= $escape($localizeServiceText($note)) ?></li><?php endforeach; ?></ul></details></div>
      </div>
    </main>

    <?php $activeWorkspace = 'statistics'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
  <script id="statistics-data" type="application/json"><?= $payload ?></script>
</body>
</html>
