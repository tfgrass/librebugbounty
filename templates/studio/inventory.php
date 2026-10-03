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
$formatTime = static fn (?\DateTimeImmutable $at): string => $at === null
    ? 'Zeitpunkt unbekannt'
    : $at->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y · H:i');
$scopeLabels = ['active' => 'Aktiv', 'discarded' => 'Verworfen', 'duplicates' => 'Duplikate', 'all' => 'Alle Fälle'];
$hasAdditionalFilters = $filter->domain !== '' || $filter->exactDomain || $filter->type !== '' || $filter->severity !== '' || $filter->legacyStatus !== '' || $filter->legacyBucket !== '';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title>Bestand · LibreBugBounty Studio</title>
  <link rel="stylesheet" href="/css/studio.css">
  <link rel="stylesheet" href="/css/studio-inventory.css">
</head>
<body data-studio data-studio-list>
  <div class="studio-shell studio-list-shell">
    <header class="studio-header">
      <a class="studio-brand" href="/" aria-label="LibreBugBounty Studio, Eingang">
        <svg class="studio-brand-mark" width="27" height="27" viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M14 2.5 24 8.3v11.4l-10 5.8-10-5.8V8.3L14 2.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M10 11h8v7a4 4 0 0 1-8 0v-7Zm2-3h4v3h-4V8Zm2 4v10M7 13h3m8 0h3M7 17h3m8 0h3m-10 5 2-2m5 0 2 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="studio-brand-name">LibreBugBounty</span>
        <span class="studio-brand-tag">STUDIO</span>
      </a>
      <a class="studio-classic-link" href="<?= $escape('/legacy?'.http_build_query($listQuery, '', '&', PHP_QUERY_RFC3986)) ?>">Bestand klassisch <span aria-hidden="true">↗</span></a>
    </header>

    <main class="studio-list-main" id="findings">
      <div class="studio-list-heading">
        <div><p class="studio-eyebrow">BESTAND</p><h1>Fälle</h1></div>
        <a class="studio-list-button studio-list-button-primary" href="/"><span aria-hidden="true">+</span> URL erfassen</a>
      </div>

      <?php if ($message !== null && $message !== ''): ?><p class="studio-list-feedback" data-tone="success" role="status"><?= $escape($message) ?></p><?php endif; ?>
      <?php if ($error !== null && $error !== ''): ?><p class="studio-list-feedback" data-tone="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>

      <section class="studio-list-tools" aria-label="Suche und Filter">
        <nav class="studio-list-scopes" aria-label="Bestand und Archiv">
          <?php foreach ($scopeLabels as $scope => $label): ?>
            <a href="<?= $escape($scopeUrl($scope)) ?>" data-scope="<?= $escape($scope) ?>"<?= $filter->scope === $scope ? ' aria-current="page"' : '' ?>>
              <?= $escape($label) ?>
              <?php if (isset($view->stats[$scope])): ?><span title="Fälle insgesamt in diesem Bereich"><?= $escape($view->stats[$scope]['count']) ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </nav>
        <form method="get" action="<?= $escape($path) ?>" id="finding-filters" class="studio-list-filters">
          <input type="hidden" name="scope" value="<?= $escape($filter->scope) ?>">
          <input type="hidden" name="pageSize" value="<?= $escape($pagination['pageSize']) ?>">
          <div class="studio-list-search-row">
            <label class="studio-list-search" for="studio-list-search">
              <span class="studio-sr-only">Domain, Titel oder vollständige URL suchen</span>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" stroke="currentColor" stroke-width="1.5"/><path d="m16 16 5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
              <input id="studio-list-search" type="search" name="q" value="<?= $escape($filter->q) ?>" placeholder="Domain, Titel oder URL suchen" autocomplete="off">
            </label>
            <button class="studio-list-button studio-list-button-primary" type="submit">Suchen &amp; filtern</button>
          </div>
          <div class="studio-list-primary-filters">
            <label>Manuelle Bewertung
              <select name="assessment">
                <?php foreach (['' => 'Alle Bewertungen', 'confirmed' => 'Befund bestätigt', 'fixed' => 'Behoben', 'discarded' => 'Verworfen', 'unknown' => 'Keine aufgezeichnete Bewertung'] as $value => $label): ?>
                  <option value="<?= $escape($value) ?>"<?= $filter->assessment === $value ? ' selected' : '' ?>><?= $escape($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Letzte technische Beobachtung
              <select name="observation">
                <option value=""<?= $filter->observation === '' ? ' selected' : '' ?>>Alle Beobachtungen</option>
                <?php foreach ([...RetestResult::values(), 'none'] as $value): ?>
                  <option value="<?= $escape($value) ?>"<?= $filter->observation === $value ? ' selected' : '' ?>><?= $escape(FindingReadLabels::observation($value === 'none' ? null : $value)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Kontakt
              <select name="contact">
                <?php foreach (['' => 'Alle Kontaktstände', 'yes' => 'Kontaktiert', 'no' => 'Nicht kontaktiert'] as $value => $label): ?>
                  <option value="<?= $escape($value) ?>"<?= $filter->contact === $value ? ' selected' : '' ?>><?= $escape($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="studio-list-filter-options">
            <details class="studio-list-additional"<?= $hasAdditionalFilters ? ' open' : '' ?>>
              <summary>Weitere Filter<?= $hasAdditionalFilters ? ' · aktiv' : '' ?></summary>
              <div class="studio-list-additional-fields">
                <label>Domain <input name="domain" value="<?= $escape($filter->domain) ?>" placeholder="example.com" autocomplete="off"></label>
                <label>Domainvergleich <select name="exact_domain"><option value="0"<?= !$filter->exactDomain ? ' selected' : '' ?>>Enthält den Domainfilter</option><option value="1"<?= $filter->exactDomain ? ' selected' : '' ?>>Entspricht dem Domainfilter exakt</option></select></label>
                <label>Typ <input name="type" value="<?= $escape($filter->type) ?>" placeholder="Alle Typen"></label>
                <label>Schweregrad <select name="severity">
                  <?php foreach (['', ...FindingSeverity::values()] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->severity === $value ? ' selected' : '' ?>><?= $escape($value === '' ? 'Alle Schweregrade' : $value) ?></option><?php endforeach; ?>
                </select></label>
                <label>Altstatusfilter <select name="legacy_status">
                  <?php foreach (['', ...FindingStatus::values()] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->legacyStatus === $value ? ' selected' : '' ?>><?= $escape($value === '' ? 'Alle Altstatuswerte' : $value) ?></option><?php endforeach; ?>
                </select></label>
                <label>Altgruppenfilter <select name="legacy_bucket">
                  <?php foreach (['', 'open', 'fixed', 'manual_review', 'unchecked'] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->legacyBucket === $value ? ' selected' : '' ?>><?= $escape($value === '' ? 'Alle Altgruppen' : $value) ?></option><?php endforeach; ?>
                </select></label>
              </div>
              <p class="studio-list-hint">Alle Filter gelten gemeinsam. Altstatus und Altgruppe sind gespeicherte Diagnosewerte mit unklarer historischer Herkunft.</p>
            </details>
            <a class="studio-list-reset" href="<?= $escape($path) ?>">Zurücksetzen</a>
          </div>
        </form>
      </section>

      <?php if ($filter->legacyStatus !== '' || $filter->legacyBucket !== ''): ?>
        <p class="studio-list-feedback" data-tone="warning" data-legacy-filter>Diagnosefilter aktiv: <?= $filter->legacyStatus !== '' ? 'Altstatus '.$escape($filter->legacyStatus).'. ' : '' ?><?= $filter->legacyBucket !== '' ? 'Altgruppe '.$escape($filter->legacyBucket).'. ' : '' ?>Die historische Herkunft dieser Werte bleibt unklar.</p>
      <?php endif; ?>
      <?php if ($filter->scope !== 'active'): ?><p class="studio-list-archive-hint"><?= $filter->scope === 'all' ? 'Diese Ansicht enthält auch das Archiv.' : 'Archiv: Diese Fälle werden im normalen Arbeiten ignoriert.' ?> Neue technische Beobachtungen reaktivieren verworfene Fälle nicht.</p><?php endif; ?>

      <section class="studio-list-results" aria-labelledby="studio-list-results-title">
        <div class="studio-list-results-heading"><h2 id="studio-list-results-title"><?= $escape($scopeLabels[$filter->scope]) ?></h2><span data-total-filtered="<?= $escape($pagination['totalFiltered']) ?>"><?= $escape($pagination['totalFiltered']) ?> Fälle <span class="studio-list-order">· Neueste zuerst</span></span></div>
        <div class="studio-list-column-headings" aria-hidden="true"><span>Fall</span><span>Manuelle Bewertung</span><span>Technische Beobachtung</span><span>Kontakt</span><span>Eingang</span><span></span></div>
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
                  <details class="studio-finding-legacy" data-dimension="legacy"><summary>Altwerte · Diagnose</summary><p>Status: <code><?= $escape($finding->legacyStatus) ?></code> · Review: <code><?= $escape($finding->legacyReviewState ?? 'Kein Review-Wert') ?></code><br><?= $finding->assessment === null ? 'Historische Herkunft unklar; keine manuelle Entscheidung nachträglich abgeleitet.' : 'Kompatibilitätswerte; die manuelle Bewertung wird gesondert aufgezeichnet.' ?></p></details>
                </div>
                <div class="studio-finding-dimension" data-dimension="assessment"><span class="studio-finding-mobile-label">Manuelle Bewertung</span><span class="studio-finding-state" data-tone="<?= $escape($assessmentTone) ?>"><?= $escape(FindingReadLabels::assessment($finding->assessment, $finding->discardReason)) ?></span>
                  <?php if ($finding->assessment !== null): ?><small>Manuell · <?= $escape($formatTime($finding->assessedAt)) ?></small><?php elseif ($finding->legacyStatus !== 'new' || $finding->legacyReviewState !== null): ?><small>Altbestand · Herkunft unklar</small><?php endif; ?>
                  <?php if ($finding->discarded && $finding->assessment === null): ?><small>Im Archiv · Altkennzeichnung</small><?php endif; ?>
                </div>
                <div class="studio-finding-dimension" data-dimension="observation"><span class="studio-finding-mobile-label">Technische Beobachtung</span><span class="studio-finding-state" data-tone="<?= $escape($observationTone) ?>"><?= $escape(FindingReadLabels::observation($finding->observationResult)) ?></span>
                  <?php if ($finding->observationId !== null): ?><small><?= $escape($formatTime($finding->observationAt)) ?> · <?= $escape($finding->observationMode ?? 'Herkunft unbekannt') ?></small><?php endif; ?>
                </div>
                <div class="studio-finding-dimension" data-dimension="contact"><span class="studio-finding-mobile-label">Kontakt</span><span><?= $escape(FindingReadLabels::contact($finding->contactedAt)) ?></span><?php if ($finding->contactedAt !== null): ?><small><?= $escape($formatTime($finding->contactedAt)) ?></small><?php endif; ?></div>
                <div class="studio-finding-dimension" data-dimension="date"><span class="studio-finding-mobile-label">Eingang</span><time datetime="<?= $escape(($finding->submittedAt ?? $finding->createdAt)->format(DATE_ATOM)) ?>"><?= $escape($formatTime($finding->submittedAt ?? $finding->createdAt)) ?></time><?php if ($finding->submittedAt === null): ?><small>Ablagezeit; Eingangszeit unbekannt</small><?php endif; ?></div>
                <a class="studio-finding-open" href="<?= $escape($detailUrl) ?>" aria-label="Fall <?= $escape($finding->domain) ?> öffnen">Fall öffnen <span aria-hidden="true">↗</span></a>
              </article>
            </li>
          <?php endforeach; ?>
        </ol>
        <?php if ($view->findings === []): ?><div class="studio-list-empty"><h3>Keine Fälle für diese Filter</h3><p>Ändere den Suchtext oder die Filter. Verworfene Fälle findest du im Archiv.</p><a class="studio-list-button" href="<?= $escape($path) ?>">Filter zurücksetzen</a></div><?php endif; ?>

        <div class="studio-list-pagination">
          <form method="get" action="<?= $escape($path) ?>" id="page-size-form" class="studio-list-page-size">
            <?php foreach ($view->filterQuery as $name => $value): ?><input type="hidden" name="<?= $escape($name) ?>" value="<?= $escape($value) ?>"><?php endforeach; ?>
            <input type="hidden" name="page" value="1">
            <label for="studio-list-page-size">Zeilen pro Seite</label>
            <select id="studio-list-page-size" name="pageSize"><?php foreach (['10', '25', '50', '100', 'all'] as $value): ?><option value="<?= $escape($value) ?>"<?= $pagination['pageSize'] === $value ? ' selected' : '' ?>><?= $escape($value === 'all' ? 'Alle' : $value) ?></option><?php endforeach; ?></select>
            <button class="studio-list-button" type="submit">Anwenden</button>
          </form>
          <?php if ($pagination['pageSize'] !== 'all' && $pagination['totalPages'] > 1): ?>
            <nav class="studio-list-pages" aria-label="Ergebnisseiten"><span>Seite <?= $escape($pagination['page']) ?> von <?= $escape($pagination['totalPages']) ?></span>
              <?php if ($pagination['page'] > 1): ?><a class="studio-list-button" data-page="previous" href="<?= $escape($pageUrl($pagination['page'] - 1)) ?>">← Zurück</a><?php endif; ?>
              <?php if ($pagination['page'] < $pagination['totalPages']): ?><a class="studio-list-button" data-page="next" href="<?= $escape($pageUrl($pagination['page'] + 1)) ?>">Weiter →</a><?php endif; ?>
            </nav>
          <?php endif; ?>
        </div>
      </section>

      <details class="studio-list-metrics"><summary>Globale Kennzahlen <span>unabhängig von den Listenfiltern</span></summary>
        <p class="studio-list-hint">Ein Klick setzt die Filter zurück und öffnet die gezählte Menge. Bewertung, technische Beobachtung und Kontakt sind unabhängige Merkmale.</p>
        <div class="studio-list-stats"><?php foreach ($view->stats as $key => $stat): ?><a data-stat="<?= $escape($key) ?>" data-count="<?= $escape($stat['count']) ?>" href="<?= $escape($stat['url']) ?>"><strong><?= $escape($stat['count']) ?></strong><span><?= $escape($stat['label']) ?></span></a><?php endforeach; ?></div>
        <p class="studio-list-hint">Screenshot-Aufträge im aktiven Bestand: <?= $escape($view->screenshotStats['queued']) ?> vorgemerkt · <?= $escape($view->screenshotStats['running']) ?> laufen · <?= $escape($view->screenshotStats['failed']) ?> fehlgeschlagen.</p>
      </details>
    </main>

    <nav class="studio-workspace-nav" aria-label="Arbeitsbereiche">
      <a class="studio-workspace-link" href="/"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M4 15v5h16v-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Eingang</span></a>
      <a class="studio-workspace-link studio-workspace-link-active" href="/findings" aria-current="page"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg><span>Bestand</span></a>
      <a class="studio-settings-link" href="/legacy/settings" aria-label="Einstellungen · klassische Ansicht"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 3-1 3-3 1-2 3 2 2v3l3 1 1 3h4l1-3 3-1v-3l2-2-2-3-3-1-1-3H9Z" transform="translate(1 1)" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.4"/></svg></a>
    </nav>
  </div>
</body>
</html>
