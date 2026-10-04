<?php

use App\Value\FindingReadLabels;
use App\Value\FindingSeverity;
use App\Value\FindingStatus;
use App\Value\RetestResult;

/** @var \App\Dto\StudioExportView $view */
/** @var callable $escape */
$filter = $view->filter;
$scopeLabels = ['active' => 'Aktiver Bestand', 'all' => 'Alle Fälle einschließlich Archiv', 'discarded' => 'Verworfen · Archiv', 'duplicates' => 'Duplikate · Archiv'];
$eventLabels = ['reported' => 'Gemeldet (Ingest)', 'sent' => 'Erstmals versendet', 'contacted' => 'Als kontaktiert markiert', 'confirmed' => 'Erstmals manuell bestätigt', 'fixed' => 'Erstmals manuell behoben'];
$hasAdditionalFilters = $filter->exactDomain || $filter->event !== '' || $filter->from !== '' || $filter->to !== '' || $filter->tld !== '' || $filter->type !== '' || $filter->severity !== '' || $filter->legacyStatus !== '' || $filter->legacyBucket !== '' || $filter->legacyReview !== '';
$hasLegacyFilters = $filter->legacyStatus !== '' || $filter->legacyBucket !== '' || $filter->legacyReview !== '';
$formatNumber = static fn (int $value): string => number_format($value, 0, ',', '.');
$returnPath = $view->inventoryPath;
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title>Export · LibreBugBounty Studio</title>
  <link rel="stylesheet" href="/css/studio.css">
  <link rel="stylesheet" href="/css/studio-export.css">
</head>
<body data-studio data-studio-export>
  <div class="studio-shell">
    <header class="studio-header">
      <a class="studio-brand" href="/" aria-label="LibreBugBounty Studio, Eingang">
        <svg class="studio-brand-mark" width="27" height="27" viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M14 2.5 24 8.3v11.4l-10 5.8-10-5.8V8.3L14 2.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M10 11h8v7a4 4 0 0 1-8 0v-7Zm2-3h4v3h-4V8Zm2 4v10M7 13h3m8 0h3M7 17h3m8 0h3m-10 5 2-2m5 0 2 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="studio-brand-name">LibreBugBounty</span><span class="studio-brand-tag">STUDIO</span>
      </a>
      <a class="studio-classic-link" href="<?= $escape($view->inventoryPath) ?>">Bestand <span aria-hidden="true">↗</span></a>
    </header>

    <main class="studio-export-main" id="export">
      <div class="studio-export-content">
        <div class="studio-export-heading">
          <div><p class="studio-eyebrow">DATEN MITNEHMEN</p><h1>Export</h1><p>Wähle deine Fälle und lade ihren aktuellen Stand als JSON herunter.</p></div>
          <span class="studio-export-mark" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m-4-4 4 4 4-4M4 15v5h16v-5"/></svg></span>
        </div>

        <form class="studio-export-layout" id="export-filters" method="get" action="/export">
          <section class="studio-export-panel studio-export-selection" aria-labelledby="export-selection-title">
            <div class="studio-export-panel-heading"><h2 id="export-selection-title">Auswahl</h2><a class="studio-export-text-link" href="/export">Zurücksetzen</a></div>
            <label class="studio-export-field studio-export-scope">Bereich
              <select name="scope" id="export-scope">
                <?php foreach ($scopeLabels as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->scope === $value ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?>
              </select>
            </label>
            <p class="studio-export-hint"><?= $filter->scope === 'active' ? 'Standardauswahl: aktive Fälle. Für archivierte Fälle wähle bewusst einen anderen Bereich.' : 'Diese Auswahl enthält archivierte Fälle.' ?> Alle Filter gelten gemeinsam.</p>

            <div class="studio-export-fields">
              <label class="studio-export-field studio-export-field-wide">Suche
                <input type="search" name="q" value="<?= $escape($filter->q) ?>" placeholder="Domain, Titel oder URL" autocomplete="off">
              </label>
              <label class="studio-export-field studio-export-field-wide">Domain
                <input name="domain" value="<?= $escape($filter->domain) ?>" placeholder="example.com" autocomplete="off">
              </label>
              <label class="studio-export-field">Manuelle Bewertung
                <select name="assessment">
                  <?php foreach (['' => 'Alle Bewertungen', 'confirmed' => 'Befund bestätigt', 'fixed' => 'Behoben', 'discarded' => 'Verworfen', 'unknown' => 'Keine aufgezeichnete Bewertung'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->assessment === $value ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?>
                </select>
              </label>
              <label class="studio-export-field">Letzte technische Beobachtung
                <select name="observation">
                  <option value=""<?= $filter->observation === '' ? ' selected' : '' ?>>Alle Beobachtungen</option>
                  <?php foreach ([...RetestResult::values(), 'none'] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->observation === $value ? ' selected' : '' ?>><?= $escape(FindingReadLabels::observation($value === 'none' ? null : $value)) ?></option><?php endforeach; ?>
                </select>
              </label>
              <label class="studio-export-field">Kontakt
                <select name="contact">
                  <?php foreach (['' => 'Alle Kontaktstände', 'yes' => 'Kontaktiert', 'no' => 'Nicht kontaktiert'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->contact === $value ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?>
                </select>
              </label>
              <label class="studio-export-field">Versand
                <select name="sent">
                  <?php foreach (['' => 'Alle Versandstände', 'yes' => 'Versand erfasst', 'no' => 'Kein Versand erfasst'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->sent === $value ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?>
                </select>
              </label>
            </div>

            <details class="studio-export-additional"<?= $hasAdditionalFilters ? ' open' : '' ?>>
              <summary>Weitere Filter<?= $hasAdditionalFilters ? ' · aktiv' : '' ?></summary>
              <div class="studio-export-fields">
                <label class="studio-export-field studio-export-field-wide">Domainvergleich
                  <select name="exact_domain"><option value="0"<?= !$filter->exactDomain ? ' selected' : '' ?>>Enthält den Domainfilter</option><option value="1"<?= $filter->exactDomain ? ' selected' : '' ?>>Entspricht dem Domainfilter exakt</option></select>
                </label>
                <label class="studio-export-field studio-export-field-wide">Ereignis
                  <select name="event"><option value="">Kein Ereignisfilter</option><?php foreach ($eventLabels as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->event === $value ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select>
                </label>
                <label class="studio-export-field">Vom Tag <input type="date" name="from" value="<?= $escape($filter->from) ?>"></label>
                <label class="studio-export-field">Bis einschließlich <input type="date" name="to" value="<?= $escape($filter->to) ?>"></label>
                <label class="studio-export-field">TLD <input name="tld" value="<?= $escape($filter->tld) ?>" placeholder=".de, ip oder local" autocomplete="off"></label>
                <label class="studio-export-field">Typ <input name="type" value="<?= $escape($filter->type) ?>" placeholder="Alle Typen"></label>
                <label class="studio-export-field studio-export-field-wide">Schweregrad
                  <select name="severity"><?php foreach (['', ...FindingSeverity::values()] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->severity === $value ? ' selected' : '' ?>><?= $escape($value === '' ? 'Alle Schweregrade' : $value) ?></option><?php endforeach; ?></select>
                </label>
                <label class="studio-export-field">Altstatusfilter
                  <select name="legacy_status"><?php foreach (['', ...FindingStatus::values()] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->legacyStatus === $value ? ' selected' : '' ?>><?= $escape($value === '' ? 'Alle Altstatuswerte' : $value) ?></option><?php endforeach; ?></select>
                </label>
                <label class="studio-export-field">Altgruppenfilter
                  <select name="legacy_bucket"><?php foreach (['', 'open', 'fixed', 'manual_review', 'unchecked'] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->legacyBucket === $value ? ' selected' : '' ?>><?= $escape($value === '' ? 'Alle Altgruppen' : $value) ?></option><?php endforeach; ?></select>
                </label>
                <label class="studio-export-field studio-export-field-wide">Alte Review-Markierung
                  <select name="legacy_review"><?php foreach (['' => 'Alle Review-Markierungen', 'confirmed_fixed' => 'confirmed_fixed', 'manually_checked' => 'manually_checked'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->legacyReview === $value ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select>
                </label>
              </div>
              <p class="studio-export-hint">Datumsfilter benötigen ein Ereignis. Altstatus, Altgruppe und alte Review-Markierung sind Diagnosewerte mit unklarer historischer Herkunft.</p>
            </details>

            <div class="studio-export-apply"><button class="studio-export-button" type="submit" data-export-preview>Vorschau aktualisieren</button><span>Für die gesamte Auswahl, über alle Listenseiten.</span></div>
          </section>

          <section class="studio-export-panel studio-export-preview" aria-labelledby="export-preview-title">
            <div class="studio-export-panel-heading"><div><p class="studio-eyebrow">EXPORTVORSCHAU</p><h2 id="export-preview-title">Deine Auswahl</h2></div><span class="studio-export-format">JSON</span></div>
            <p class="studio-export-current-scope" data-export-scope="<?= $escape($filter->scope) ?>"><?= $escape($scopeLabels[$filter->scope]) ?></p>
            <dl class="studio-export-counts">
              <div><dt>Fälle</dt><dd data-export-finding-count="<?= $escape($view->findingCount) ?>"><?= $escape($formatNumber($view->findingCount)) ?></dd></div>
              <div><dt>Domains</dt><dd data-export-domain-count="<?= $escape($view->domainCount) ?>"><?= $escape($formatNumber($view->domainCount)) ?></dd></div>
            </dl>
            <p class="studio-export-hint">Zähler für die angewendeten Filter. Aktualisiere die Vorschau nach Filteränderungen.</p>
            <?php if ($view->findingCount === 0): ?><p class="studio-export-empty" role="status">Keine Fälle für diese Auswahl. Passe den Bereich oder die Filter an.</p><?php endif; ?>
            <?php if ($hasLegacyFilters): ?><p class="studio-export-empty">Diagnosefilter aktiv. Die historische Herkunft der Altwerte bleibt unklar.</p><?php endif; ?>
            <a class="studio-export-text-link studio-export-inventory-link" href="<?= $escape($view->inventoryPath) ?>">Auswahl im Bestand ansehen <span aria-hidden="true">↗</span></a>

            <div class="studio-export-contents"><h3>Das ist enthalten</h3><ul><li>Aktueller Fallstand und manuelle Bewertung</li><li>Letzte technische Beobachtung</li><li>Kontakt- und Versandstand</li><li>Belegverweise und Dateimetadaten</li></ul><p>Bilddateien und vollständige Verläufe sind separat im Projekt gespeichert.</p></div>

            <label class="studio-export-notes" for="export-include-notes"><input type="checkbox" name="include_notes" value="1" id="export-include-notes"<?= $view->includePrivateNotes ? ' checked' : '' ?>><span>Private Fallnotizen einschließen<small>Nur nach deiner ausdrücklichen Auswahl.</small></span></label>
            <button class="studio-export-button studio-export-button-primary" type="submit" formaction="/export/download" data-export-download><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M4 15v5h16v-5"/></svg>JSON herunterladen</button>
            <p class="studio-export-download-hint">Der Download verwendet die gewählten Filter.</p>
          </section>
        </form>
      </div>
    </main>

    <?php $activeWorkspace = 'export'; require __DIR__.'/navigation.php'; ?>
  </div>
</body>
</html>
