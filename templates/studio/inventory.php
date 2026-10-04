<?php

use App\Value\FindingReadLabels;
use App\Value\FindingSeverity;
use App\Value\FindingStatus;
use App\Value\RetestResult;

/** @var \App\Dto\FindingListView $view */
/** @var callable $escape */
/** @var ?string $message */
/** @var ?string $error */
$filter = $view->filter;
$pagination = $view->pagination;
$path = $view->path;
$listQuery = $view->filterQuery + ['pageSize' => $pagination['pageSize'], 'page' => $pagination['page']];
$returnPath = $path.'?'.http_build_query($listQuery, '', '&', PHP_QUERY_RFC3986);
$pageUrl = static fn (int $page): string => $path.'?'.http_build_query(array_replace($listQuery, ['page' => $page]), '', '&', PHP_QUERY_RFC3986);
$scopeUrl = static fn (string $scope): string => $path.'?'.http_build_query(array_replace($listQuery, ['scope' => $scope, 'page' => 1]), '', '&', PHP_QUERY_RFC3986);
$exportUrl = '/export?'.http_build_query($view->filterQuery, '', '&', PHP_QUERY_RFC3986);
$scopeLabels = ['active' => 'Aktiv', 'discarded' => 'Verworfen', 'duplicates' => 'Duplikate', 'all' => 'Alle Fälle'];
$hasAdditionalFilters = $filter->domain !== '' || $filter->exactDomain || $filter->type !== '' || $filter->severity !== '' || $filter->legacyStatus !== '' || $filter->legacyBucket !== '' || $filter->legacyReview !== '' || $filter->event !== '' || $filter->tld !== '' || $filter->sent !== '';
$hasSelectionFilters = $hasAdditionalFilters || $filter->q !== '' || $filter->assessment !== '' || $filter->observation !== '' || $filter->contact !== '';
$isFirstStart = $view->stats['active']['count'] + $view->stats['discarded']['count'] === 0;
$isArchivedOnly = !$isFirstStart && $view->stats['active']['count'] === 0 && $filter->scope === 'active' && !$hasSelectionFilters;
$eventLabels = ['reported' => 'Gemeldet (Ingest)', 'sent' => 'Erstmals versendet', 'contacted' => 'Als kontaktiert markiert', 'confirmed' => 'Erstmals manuell bestätigt', 'fixed' => 'Erstmals manuell behoben'];
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($t('Bestand')) ?> · <?= $escape(\App\AppInfo::NAME.' '.\App\AppInfo::RELEASE_NAME) ?></title>
  <link rel="stylesheet" href="/css/studio.css">
  <link rel="stylesheet" href="/css/studio-inventory.css">
</head>
<body data-studio data-studio-list>
  <div class="studio-shell studio-list-shell">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
      <div class="studio-header-tools"><?php require __DIR__.'/language.php'; ?></div>
    </header>

    <main class="studio-list-main" id="findings">
      <div class="studio-list-heading">
        <div><p class="studio-eyebrow"><?= $escape($t('Bestand')) ?></p><h1><?= $escape($t('Fälle')) ?></h1></div>
        <a class="studio-list-button studio-list-button-primary" href="/"><span aria-hidden="true">+</span> <?= $escape($t('URL erfassen')) ?></a>
      </div>

      <?php if ($message !== null && $message !== ''): ?><p class="studio-list-feedback" data-tone="success" role="status"><?= $escape($message) ?></p><?php endif; ?>
      <?php if ($error !== null && $error !== ''): ?><p class="studio-list-feedback" data-tone="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>

      <section class="studio-list-tools" aria-label="<?= $escape($t('Suche und Filter')) ?>">
        <nav class="studio-list-scopes" aria-label="<?= $escape($t('Bestand und Archiv')) ?>">
          <?php foreach ($scopeLabels as $scope => $label): ?>
            <a href="<?= $escape($scopeUrl($scope)) ?>" data-scope="<?= $escape($scope) ?>"<?= $filter->scope === $scope ? ' aria-current="page"' : '' ?>>
              <?= $escape($t($label)) ?>
              <?php if (isset($view->stats[$scope])): ?><span title="<?= $escape($t('Fälle insgesamt in diesem Bereich')) ?>"><?= $escape($formatNumber($view->stats[$scope]['count'])) ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </nav>
        <form method="get" action="<?= $escape($path) ?>" id="finding-filters" class="studio-list-filters">
          <input type="hidden" name="scope" value="<?= $escape($filter->scope) ?>">
          <input type="hidden" name="pageSize" value="<?= $escape($pagination['pageSize']) ?>">
          <div class="studio-list-search-row">
            <label class="studio-list-search" for="studio-list-search">
              <span class="studio-sr-only"><?= $escape($t('Domain, Titel oder vollständige URL suchen')) ?></span>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" stroke="currentColor" stroke-width="1.5"/><path d="m16 16 5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
              <input id="studio-list-search" type="search" name="q" value="<?= $escape($filter->q) ?>" placeholder="<?= $escape($t('Domain, Titel oder URL suchen')) ?>" autocomplete="off">
            </label>
            <button class="studio-list-button studio-list-button-primary" type="submit"><?= $escape($t('Suchen & filtern')) ?></button>
          </div>
          <div class="studio-list-primary-filters">
            <label><?= $escape($t('Manuelle Bewertung')) ?>
              <select name="assessment">
                <?php foreach (['' => 'Alle Bewertungen', 'confirmed' => 'Befund bestätigt', 'fixed' => 'Behoben', 'discarded' => 'Verworfen', 'unknown' => 'Keine aufgezeichnete Bewertung'] as $value => $label): ?>
                  <option value="<?= $escape($value) ?>"<?= $filter->assessment === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label><?= $escape($t('Letzte technische Beobachtung')) ?>
              <select name="observation">
                <option value=""<?= $filter->observation === '' ? ' selected' : '' ?>><?= $escape($t('Alle technischen Ergebnisse')) ?></option>
                <?php foreach ([...RetestResult::values(), 'none'] as $value): ?>
                  <option value="<?= $escape($value) ?>"<?= $filter->observation === $value ? ' selected' : '' ?>><?= $escape($t(FindingReadLabels::observation($value === 'none' ? null : $value))) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label><?= $escape($t('Kontakt')) ?>
              <select name="contact">
                <?php foreach (['' => 'Alle Kontaktstände', 'yes' => 'Kontaktiert', 'no' => 'Nicht kontaktiert'] as $value => $label): ?>
                  <option value="<?= $escape($value) ?>"<?= $filter->contact === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="studio-list-filter-options">
            <details class="studio-list-additional"<?= $hasAdditionalFilters ? ' open' : '' ?>>
              <summary><?= $escape($t('Weitere Filter')) ?><?= $hasAdditionalFilters ? $escape($t(' · aktiv')) : '' ?></summary>
              <div class="studio-list-additional-fields">
                <label>Domain <input name="domain" value="<?= $escape($filter->domain) ?>" placeholder="example.com" autocomplete="off"></label>
                <label><?= $escape($t('Domainvergleich')) ?> <select name="exact_domain"><option value="0"<?= !$filter->exactDomain ? ' selected' : '' ?>><?= $escape($t('Enthält den Domainfilter')) ?></option><option value="1"<?= $filter->exactDomain ? ' selected' : '' ?>><?= $escape($t('Entspricht dem Domainfilter exakt')) ?></option></select></label>
                <label><?= $escape($t('Ereignis')) ?> <select name="event"><option value=""><?= $escape($t('Kein Ereignisfilter')) ?></option><?php foreach ($eventLabels as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->event === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?></select></label>
                <label><?= $escape($t('Vom Tag')) ?> <input type="date" name="from" value="<?= $escape($filter->from) ?>"></label>
                <label><?= $escape($t('Bis einschließlich')) ?> <input type="date" name="to" value="<?= $escape($filter->to) ?>"></label>
                <label>TLD <input name="tld" value="<?= $escape($filter->tld) ?>" placeholder=".de, ip oder local" autocomplete="off"></label>
                <label><?= $escape($t('Versand')) ?> <select name="sent"><?php foreach (['' => 'Alle Versandstände', 'yes' => 'Versand erfasst', 'no' => 'Kein Versand erfasst'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->sent === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?></select></label>
                <label><?= $escape($t('Typ')) ?> <input name="type" value="<?= $escape($filter->type) ?>" placeholder="<?= $escape($t('Alle Typen')) ?>"></label>
                <label><?= $escape($t('Schweregrad')) ?> <select name="severity">
                  <?php foreach (['', ...FindingSeverity::values()] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->severity === $value ? ' selected' : '' ?>><?= $escape($value === '' ? $t('Alle Schweregrade') : $value) ?></option><?php endforeach; ?>
                </select></label>
                <label><?= $escape($t('Historischer Status')) ?> <select name="legacy_status">
                  <?php foreach (['', ...FindingStatus::values()] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->legacyStatus === $value ? ' selected' : '' ?>><?= $escape($value === '' ? $t('Alle historischen Statuswerte') : $value) ?></option><?php endforeach; ?>
                </select></label>
                <label><?= $escape($t('Historische Gruppe')) ?> <select name="legacy_bucket">
                  <?php foreach (['', 'open', 'fixed', 'manual_review', 'unchecked'] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->legacyBucket === $value ? ' selected' : '' ?>><?= $escape($value === '' ? $t('Alle historischen Gruppen') : $value) ?></option><?php endforeach; ?>
                </select></label>
                <label><?= $escape($t('Frühere Review-Markierung')) ?> <select name="legacy_review">
                  <?php foreach (['' => 'Alle Review-Markierungen', 'confirmed_fixed' => 'Als behoben markiert', 'manually_checked' => 'Als geprüft markiert'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->legacyReview === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?>
                </select></label>
              </div>
              <p class="studio-list-hint"><?= $escape($t('Alle Filter gelten gemeinsam. Historischer Status, historische Gruppe und frühere Review-Markierung sind gespeicherte Diagnosewerte mit unklarer Herkunft.')) ?></p>
            </details>
            <a class="studio-list-reset" href="<?= $escape($path) ?>"><?= $escape($t('Zurücksetzen')) ?></a>
          </div>
        </form>
      </section>

      <?php if ($filter->legacyStatus !== '' || $filter->legacyBucket !== '' || $filter->legacyReview !== ''): ?>
        <?php $legacyDetails = ($filter->legacyStatus !== '' ? $t('Historischer Status {value}. ', ['value' => $filter->legacyStatus]) : '').($filter->legacyBucket !== '' ? $t('Historische Gruppe {value}. ', ['value' => $filter->legacyBucket]) : '').($filter->legacyReview !== '' ? $t('Frühere Review-Markierung {value}. ', ['value' => $filter->legacyReview]) : ''); ?>
        <p class="studio-list-feedback" data-tone="warning" data-history-filter><?= $escape($t('Diagnosefilter aktiv: {details}Die historische Herkunft dieser Werte bleibt unklar.', ['details' => $legacyDetails])) ?></p>
      <?php endif; ?>
      <?php if ($filter->scope !== 'active'): ?><p class="studio-list-archive-hint"><?= $escape($t($filter->scope === 'all' ? 'Diese Ansicht enthält auch das Archiv.' : 'Archiv: Diese Fälle werden im normalen Arbeiten ignoriert.')) ?> <?= $escape($t('Neue technische Beobachtungen reaktivieren verworfene Fälle nicht.')) ?></p><?php endif; ?>
      <?php if ($filter->event !== '' || $filter->tld !== ''): ?>
        <p class="studio-list-archive-hint" data-statistics-filter><?= $escape($t('Statistikauswahl: {event}{from}{to}{tld}. Die Bewertung daneben zeigt den heutigen Stand.', ['event' => $t($eventLabels[$filter->event] ?? 'Alle Ereignisse'), 'from' => $filter->from !== '' ? $t(' · ab {date}', ['date' => $formatDate($filter->from)]) : '', 'to' => $filter->to !== '' ? $t(' · bis {date}', ['date' => $formatDate($filter->to)]) : '', 'tld' => $filter->tld !== '' ? ' · TLD '.$filter->tld : ''])) ?></p>
      <?php endif; ?>

      <section class="studio-list-results" aria-labelledby="studio-list-results-title">
        <div class="studio-list-results-heading"><h2 id="studio-list-results-title"><?= $escape($t($scopeLabels[$filter->scope])) ?></h2><span data-total-filtered="<?= $escape($pagination['totalFiltered']) ?>"><?= $escape($t('{count} Fälle', ['count' => $formatNumber($pagination['totalFiltered'])])) ?> <span class="studio-list-order"><?= $escape($t(' · Neueste zuerst')) ?></span></span><a class="studio-list-button" data-export-selection href="<?= $escape($exportUrl) ?>"><?= $escape($t('Auswahl exportieren')) ?> <span aria-hidden="true">↗</span></a></div>
        <div class="studio-list-column-headings" aria-hidden="true"><span><?= $escape($t('Fall')) ?></span><span><?= $escape($t('Manuelle Bewertung')) ?></span><span><?= $escape($t('Technische Beobachtung')) ?></span><span><?= $escape($t('Kontakt')) ?></span><span><?= $escape($t('Eingang')) ?></span><span></span></div>
        <ol class="studio-findings">
          <?php foreach ($view->findings as $finding): ?>
            <?php
            $detailUrl = '/findings/'.rawurlencode($finding->id).'?'.http_build_query(['return_to' => $returnPath], '', '&', PHP_QUERY_RFC3986);
            $assessmentTone = match ($finding->assessment) { 'confirmed' => 'confirmed', 'fixed' => 'fixed', 'discarded' => 'archived', default => 'unknown' };
            $observationTone = match ($finding->observationResult) { 'inconclusive', 'pending' => 'pending', 'error' => 'error', default => 'neutral' };
            ?>
            <li>
              <article class="studio-finding-row" data-finding-id="<?= $escape($finding->id) ?>">
                <div class="studio-finding-identity">
                  <a class="studio-finding-domain" href="<?= $escape($detailUrl) ?>"><?= $escape($finding->domain) ?></a>
                  <p class="studio-finding-title"><?= $escape($finding->title) ?></p>
                  <p class="studio-finding-url" title="<?= $escape($finding->url) ?>"><?= $escape($finding->url) ?></p>
                  <p class="studio-finding-record-meta"><code><?= $escape(substr($finding->id, 0, 8)) ?></code><span><?= $escape($finding->type) ?></span><span><?= $escape($finding->severity) ?></span></p>
                  <details class="studio-finding-history" data-dimension="history"><summary><?= $escape($t('Historische Werte · Diagnose')) ?></summary><p><?= $escape($t('Historischer Status')) ?>: <code><?= $escape($finding->legacyStatus) ?></code> · <?= $escape($t('Frühere Review-Markierung')) ?>: <code><?= $escape($finding->legacyReviewState ?? $t('Kein Review-Wert')) ?></code><br><?= $escape($t($finding->assessment === null ? 'Historische Herkunft unklar; keine manuelle Entscheidung nachträglich abgeleitet.' : 'Kompatibilitätswerte; die manuelle Bewertung wird gesondert aufgezeichnet.')) ?></p></details>
                </div>
                <div class="studio-finding-dimension" data-dimension="assessment"><span class="studio-finding-mobile-label"><?= $escape($t('Manuelle Bewertung')) ?></span><span class="studio-finding-state" data-tone="<?= $escape($assessmentTone) ?>"><?= $escape($t(FindingReadLabels::assessment($finding->assessment, $finding->discardReason))) ?></span>
                  <?php if ($finding->assessment !== null): ?><small><?= $escape($t('Manuell')) ?> · <?= $escape($formatTime($finding->assessedAt)) ?></small><?php elseif ($finding->legacyStatus !== 'new' || $finding->legacyReviewState !== null): ?><small><?= $escape($t('Historischer Bestand · Herkunft unklar')) ?></small><?php endif; ?>
                  <?php if ($finding->discarded && $finding->assessment === null): ?><small><?= $escape($t('Im Archiv · historische Kennzeichnung')) ?></small><?php endif; ?>
                </div>
                <div class="studio-finding-dimension" data-dimension="observation"><span class="studio-finding-mobile-label"><?= $escape($t('Technische Beobachtung')) ?></span><span class="studio-finding-state" data-tone="<?= $escape($observationTone) ?>"><?= $escape($t(FindingReadLabels::observation($finding->observationResult))) ?></span>
                  <?php if ($finding->observationId !== null): ?><small><?= $escape($formatTime($finding->observationAt)) ?> · <?= $escape($finding->observationMode ?? $t('Herkunft unbekannt')) ?></small><?php endif; ?>
                </div>
                <div class="studio-finding-dimension" data-dimension="contact"><span class="studio-finding-mobile-label"><?= $escape($t('Kontakt & Versand')) ?></span><span><?= $escape($t(FindingReadLabels::contact($finding->contactedAt))) ?></span><?php if ($finding->contactedAt !== null): ?><small><?= $escape($formatTime($finding->contactedAt)) ?></small><?php endif; ?><?php if ($finding->sentAt !== null): ?><small><?= $escape($t('Versendet')) ?> · <?= $escape($formatTime($finding->sentAt)) ?></small><?php endif; ?></div>
                <div class="studio-finding-dimension" data-dimension="date"><span class="studio-finding-mobile-label"><?= $escape($t('Eingang')) ?></span><time datetime="<?= $escape(($finding->submittedAt ?? $finding->createdAt)->format(DATE_ATOM)) ?>"><?= $escape($formatTime($finding->submittedAt ?? $finding->createdAt)) ?></time><?php if ($finding->submittedAt === null): ?><small><?= $escape($t('Ablagezeit; Eingangszeit unbekannt')) ?></small><?php endif; ?></div>
                <a class="studio-finding-open" href="<?= $escape($detailUrl) ?>" aria-label="<?= $escape($t('Fall {domain} öffnen', ['domain' => $finding->domain])) ?>"><?= $escape($t('Fall öffnen')) ?> <span aria-hidden="true">↗</span></a>
              </article>
            </li>
          <?php endforeach; ?>
        </ol>
        <?php if ($view->findings === []): ?>
          <div class="studio-list-empty" data-list-empty-state="<?= $isFirstStart ? 'first-start' : ($isArchivedOnly ? 'archived' : 'filtered') ?>"<?= $isFirstStart ? ' data-first-start' : '' ?>>
            <?php if ($isFirstStart): ?>
              <h3><?= $escape($t('Noch keine Fälle')) ?></h3>
              <p><?= $escape($t('Erfasse eine URL. Der Screenshot entsteht im Hintergrund; danach kannst du den Fall prüfen, bewerten und exportieren.')) ?></p>
              <a class="studio-list-button studio-list-button-primary" data-first-start-cta href="/"><?= $escape($t('URL erfassen')) ?></a>
            <?php elseif ($isArchivedOnly): ?>
              <h3><?= $escape($t('Keine aktiven Fälle')) ?></h3>
              <p><?= $escape($t('Deine gespeicherten Fälle liegen im Archiv. Du kannst sie dort ansehen oder eine neue URL erfassen.')) ?></p>
              <a class="studio-list-button" href="/findings?scope=discarded"><?= $escape($t('Archiv ansehen')) ?></a>
              <a class="studio-list-button studio-list-button-primary" href="/"><?= $escape($t('URL erfassen')) ?></a>
            <?php else: ?>
              <h3><?= $escape($t('Keine Fälle für diese Filter')) ?></h3>
              <p><?= $escape($t('Ändere den Suchtext oder die Filter. Verworfene Fälle findest du im Archiv.')) ?></p>
              <a class="studio-list-button" href="<?= $escape($path) ?>"><?= $escape($t('Filter zurücksetzen')) ?></a>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="studio-list-pagination">
          <form method="get" action="<?= $escape($path) ?>" id="page-size-form" class="studio-list-page-size">
            <?php foreach ($view->filterQuery as $name => $value): ?><input type="hidden" name="<?= $escape($name) ?>" value="<?= $escape($value) ?>"><?php endforeach; ?>
            <input type="hidden" name="page" value="1">
            <label for="studio-list-page-size"><?= $escape($t('Zeilen pro Seite')) ?></label>
            <select id="studio-list-page-size" name="pageSize"><?php foreach (['10', '25', '50', '100', 'all'] as $value): ?><option value="<?= $escape($value) ?>"<?= $pagination['pageSize'] === $value ? ' selected' : '' ?>><?= $escape($value === 'all' ? $t('Alle') : $value) ?></option><?php endforeach; ?></select>
            <button class="studio-list-button" type="submit"><?= $escape($t('Anwenden')) ?></button>
          </form>
          <?php if ($pagination['pageSize'] !== 'all' && $pagination['totalPages'] > 1): ?>
            <nav class="studio-list-pages" aria-label="<?= $escape($t('Ergebnisseiten')) ?>"><span><?= $escape($t('Seite {page} von {pages}', ['page' => $formatNumber($pagination['page']), 'pages' => $formatNumber($pagination['totalPages'])])) ?></span>
              <?php if ($pagination['page'] > 1): ?><a class="studio-list-button" data-page="previous" href="<?= $escape($pageUrl($pagination['page'] - 1)) ?>">← <?= $escape($t('Zurück')) ?></a><?php endif; ?>
              <?php if ($pagination['page'] < $pagination['totalPages']): ?><a class="studio-list-button" data-page="next" href="<?= $escape($pageUrl($pagination['page'] + 1)) ?>"><?= $escape($t('Weiter')) ?> →</a><?php endif; ?>
            </nav>
          <?php endif; ?>
        </div>
      </section>

      <details class="studio-list-metrics"><summary><?= $escape($t('Globale Kennzahlen')) ?> <span><?= $escape($t('unabhängig von den Listenfiltern')) ?></span></summary>
        <p class="studio-list-hint"><?= $escape($t('Ein Klick setzt die Filter zurück und öffnet die gezählte Menge. Bewertung, technische Beobachtung und Kontakt sind unabhängige Merkmale.')) ?></p>
        <div class="studio-list-stats"><?php foreach ($view->stats as $key => $stat): ?><a data-stat="<?= $escape($key) ?>" data-count="<?= $escape($stat['count']) ?>" href="<?= $escape($stat['url']) ?>"><strong><?= $escape($formatNumber($stat['count'])) ?></strong><span><?= $escape($t($stat['label'])) ?></span></a><?php endforeach; ?></div>
        <p class="studio-list-hint"><?= $escape($t('Screenshot-Aufträge im aktiven Bestand: {queued} vorgemerkt · {running} laufen · {failed} fehlgeschlagen.', ['queued' => $formatNumber($view->screenshotStats['queued']), 'running' => $formatNumber($view->screenshotStats['running']), 'failed' => $formatNumber($view->screenshotStats['failed'])])) ?></p>
      </details>
    </main>

    <?php $activeWorkspace = 'inventory'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
</body>
</html>
