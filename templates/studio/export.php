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
$returnPath = $view->inventoryPath;
$profile = $view->profile;
$profileOptions = [
    'urls' => ['title' => 'URL-Liste', 'description' => 'URLs und Befundtypen kompakt zur weiteren Übernahme.', 'format' => 'JSON'],
    'state' => ['title' => 'Aktueller Fallstand', 'description' => 'Falldaten und Belegverweise für deine Weiterverarbeitung.', 'format' => 'JSON'],
    'report' => ['title' => 'Meldung mit Belegen', 'description' => 'Lesbarer Bericht mit ausgewählten lokalen Screenshots.', 'format' => 'ZIP'],
];
$profileUrl = static fn (string $value): string => '/export?'.http_build_query($view->filterQuery + ['profile' => $value], '', '&', PHP_QUERY_RFC3986);
$contentGroups = [
    'include_request_data' => ['label' => 'Request- und PoC-Daten', 'hint' => 'Methode, Parameter, Kennzeichen und erwarteter Befund.', 'enabled' => $view->includeRequestData],
    'include_assessment' => ['label' => 'Bewertung und technische Beobachtung', 'hint' => 'Manuelles Urteil und gespeicherte technische Ergebnisse.', 'enabled' => $view->includeAssessment],
    'include_contact' => ['label' => 'Kontakt und Versand', 'hint' => 'Gespeicherte Kontakt- und Versandzeitpunkte.', 'enabled' => $view->includeContact],
];
$screenshotLabels = ['basis' => 'Beleg meiner Bewertung', 'latest' => 'Neuester gespeicherter Bildbeleg', 'all' => 'Alle gespeicherten Bildbelege', 'none' => 'Keine Bilddateien'];
$screenshotHints = [
    'basis' => 'Nur ausdrücklich zur aktuellen Bewertung oder ihrer späteren Sichtung aufgezeichnete Bildbelege. Ohne bekannte Bildgrundlage wird kein anderes Bild eingesetzt.',
    'latest' => 'Der neueste gespeicherte Screenshot jedes Falls nach Aufnahmezeit, bei unbekannter Aufnahmezeit nach Ablagezeit. Seine Auswahl bedeutet nicht, dass dieses Bild manuell beurteilt wurde.',
    'all' => 'Alle gespeicherten Screenshotbelege der ausgewählten Fälle.',
    'none' => 'Bericht und Metadaten ohne angehängte Bilddateien.',
];
$downloadFormat = strtoupper($view->downloadFormat);
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($t('Export')) ?> · <?= $escape(\App\AppInfo::NAME.' '.\App\AppInfo::RELEASE_NAME) ?></title>
  <link rel="stylesheet" href="/css/studio.css">
  <link rel="stylesheet" href="/css/studio-export.css">
</head>
<body data-studio data-studio-export>
  <div class="studio-shell">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
      <div class="studio-header-tools">
        <a class="studio-header-link" href="<?= $escape($view->inventoryPath) ?>"><?= $escape($t('Bestand')) ?> <span aria-hidden="true">↗</span></a>
        <?php require __DIR__.'/language.php'; ?>
      </div>
    </header>

    <main class="studio-export-main" id="export">
      <div class="studio-export-content">
        <div class="studio-export-heading">
          <div><p class="studio-eyebrow"><?= $escape($t('Daten mitnehmen')) ?></p><h1><?= $escape($t('Export')) ?></h1><p><?= $escape($t('Wähle den Zweck, deine Fälle und die Inhalte, die du mitnehmen möchtest.')) ?></p></div>
          <span class="studio-export-mark" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m-4-4 4 4 4-4M4 15v5h16v-5"/></svg></span>
        </div>

        <?php if ($isFirstStart): ?>
          <section class="studio-first-start" data-first-start aria-labelledby="export-first-start-title">
            <div><h2 id="export-first-start-title"><?= $escape($t('Noch keine Fälle zum Exportieren')) ?></h2><p><?= $escape($t('Erfasse zuerst eine URL. Später kannst du hier Falldaten und ausgewählte Bildbelege mitnehmen.')) ?></p></div>
            <a data-first-start-cta href="/"><?= $escape($t('URL erfassen')) ?> <span aria-hidden="true">↗</span></a>
          </section>
        <?php endif; ?>

        <section class="studio-export-presets" aria-labelledby="export-presets-title">
          <h2 id="export-presets-title"><?= $escape($t('Wofür möchtest du exportieren?')) ?></h2>
          <nav class="studio-export-profiles" aria-label="<?= $escape($t('Exportvorlage')) ?>">
            <?php foreach ($profileOptions as $value => $option): ?>
              <a class="studio-export-profile" href="<?= $escape($profileUrl($value)) ?>" data-export-profile="<?= $escape($value) ?>"<?= $profile === $value ? ' aria-current="page"' : '' ?>><span class="studio-export-profile-title"><?= $escape($t($option['title'])) ?><span class="studio-export-profile-format"><?= $escape($option['format']) ?></span></span><span class="studio-export-profile-description"><?= $escape($t($option['description'])) ?></span></a>
            <?php endforeach; ?>
          </nav>
          <p class="studio-export-hint"><?= $escape($t('Vorlagen übernehmen die angewendeten Fallfilter und setzen die Inhaltsoptionen neu. Private Fallnotizen sind dabei ausgeschaltet.')) ?></p>
        </section>

        <form class="studio-export-layout" id="export-filters" method="get" action="/export">
          <input type="hidden" name="profile" value="<?= $escape($profile) ?>">
          <section class="studio-export-panel studio-export-selection" aria-labelledby="export-selection-title">
            <div class="studio-export-panel-heading"><h2 id="export-selection-title"><?= $escape($t('Auswahl')) ?></h2><a class="studio-export-text-link" href="/export"><?= $escape($t('Zurücksetzen')) ?></a></div>
            <label class="studio-export-field studio-export-scope"><?= $escape($t('Bereich')) ?>
              <select name="scope" id="export-scope">
                <?php foreach ($scopeLabels as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->scope === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?>
              </select>
            </label>
            <p class="studio-export-hint"><?= $escape($t($filter->scope === 'active' ? 'Standardauswahl: aktive Fälle. Für archivierte Fälle wähle bewusst einen anderen Bereich.' : 'Diese Auswahl enthält archivierte Fälle.')) ?> <?= $escape($t('Alle Filter gelten gemeinsam.')) ?></p>

            <div class="studio-export-fields">
              <label class="studio-export-field studio-export-field-wide"><?= $escape($t('Suche')) ?>
                <input type="search" name="q" value="<?= $escape($filter->q) ?>" placeholder="<?= $escape($t('Domain, Titel oder URL')) ?>" autocomplete="off">
              </label>
              <label class="studio-export-field studio-export-field-wide">Domain
                <input name="domain" value="<?= $escape($filter->domain) ?>" placeholder="example.com" autocomplete="off">
              </label>
              <label class="studio-export-field"><?= $escape($t('Manuelle Bewertung')) ?>
                <select name="assessment">
                  <?php foreach (['' => 'Alle Bewertungen', 'confirmed' => 'Befund bestätigt', 'fixed' => 'Behoben', 'discarded' => 'Verworfen', 'unknown' => 'Keine aufgezeichnete Bewertung'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->assessment === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?>
                </select>
              </label>
              <label class="studio-export-field"><?= $escape($t('Letzte technische Beobachtung')) ?>
                <select name="observation">
                  <option value=""<?= $filter->observation === '' ? ' selected' : '' ?>><?= $escape($t('Alle technischen Ergebnisse')) ?></option>
                  <?php foreach ([...RetestResult::values(), 'none'] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->observation === $value ? ' selected' : '' ?>><?= $escape($t(FindingReadLabels::observation($value === 'none' ? null : $value))) ?></option><?php endforeach; ?>
                </select>
              </label>
              <label class="studio-export-field"><?= $escape($t('Kontakt')) ?>
                <select name="contact">
                  <?php foreach (['' => 'Alle Kontaktstände', 'yes' => 'Kontaktiert', 'no' => 'Nicht kontaktiert'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->contact === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?>
                </select>
              </label>
              <label class="studio-export-field"><?= $escape($t('Versand')) ?>
                <select name="sent">
                  <?php foreach (['' => 'Alle Versandstände', 'yes' => 'Versand erfasst', 'no' => 'Kein Versand erfasst'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->sent === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?>
                </select>
              </label>
            </div>

            <details class="studio-export-additional"<?= $hasAdditionalFilters ? ' open' : '' ?>>
              <summary><?= $escape($t('Weitere Filter')) ?><?= $hasAdditionalFilters ? $escape($t(' · aktiv')) : '' ?></summary>
              <div class="studio-export-fields">
                <label class="studio-export-field studio-export-field-wide"><?= $escape($t('Domainvergleich')) ?>
                  <select name="exact_domain"><option value="0"<?= !$filter->exactDomain ? ' selected' : '' ?>><?= $escape($t('Enthält den Domainfilter')) ?></option><option value="1"<?= $filter->exactDomain ? ' selected' : '' ?>><?= $escape($t('Entspricht dem Domainfilter exakt')) ?></option></select>
                </label>
                <label class="studio-export-field studio-export-field-wide"><?= $escape($t('Ereignis')) ?>
                  <select name="event"><option value=""><?= $escape($t('Kein Ereignisfilter')) ?></option><?php foreach ($eventLabels as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->event === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?></select>
                </label>
                <label class="studio-export-field"><?= $escape($t('Vom Tag')) ?> <input type="date" name="from" value="<?= $escape($filter->from) ?>"></label>
                <label class="studio-export-field"><?= $escape($t('Bis einschließlich')) ?> <input type="date" name="to" value="<?= $escape($filter->to) ?>"></label>
                <label class="studio-export-field">TLD <input name="tld" value="<?= $escape($filter->tld) ?>" placeholder=".de, ip oder local" autocomplete="off"></label>
                <label class="studio-export-field"><?= $escape($t('Typ')) ?> <input name="type" value="<?= $escape($filter->type) ?>" placeholder="<?= $escape($t('Alle Typen')) ?>"></label>
                <label class="studio-export-field studio-export-field-wide"><?= $escape($t('Schweregrad')) ?>
                  <select name="severity"><?php foreach (['', ...FindingSeverity::values()] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->severity === $value ? ' selected' : '' ?>><?= $escape($value === '' ? $t('Alle Schweregrade') : $value) ?></option><?php endforeach; ?></select>
                </label>
                <label class="studio-export-field"><?= $escape($t('Historischer Status')) ?>
                  <select name="legacy_status"><?php foreach (['', ...FindingStatus::values()] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->legacyStatus === $value ? ' selected' : '' ?>><?= $escape($value === '' ? $t('Alle historischen Statuswerte') : $value) ?></option><?php endforeach; ?></select>
                </label>
                <label class="studio-export-field"><?= $escape($t('Historische Gruppe')) ?>
                  <select name="legacy_bucket"><?php foreach (['', 'open', 'fixed', 'manual_review', 'unchecked'] as $value): ?><option value="<?= $escape($value) ?>"<?= $filter->legacyBucket === $value ? ' selected' : '' ?>><?= $escape($value === '' ? $t('Alle historischen Gruppen') : $value) ?></option><?php endforeach; ?></select>
                </label>
                <label class="studio-export-field studio-export-field-wide"><?= $escape($t('Frühere Review-Markierung')) ?>
                  <select name="legacy_review"><?php foreach (['' => 'Alle Review-Markierungen', 'confirmed_fixed' => 'Als behoben markiert', 'manually_checked' => 'Als geprüft markiert'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filter->legacyReview === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?></select>
                </label>
              </div>
              <p class="studio-export-hint"><?= $escape($t('Datumsfilter benötigen ein Ereignis. Historischer Status, historische Gruppe und frühere Review-Markierung sind Diagnosewerte mit unklarer Herkunft.')) ?></p>
            </details>

            <div class="studio-export-apply"><button class="studio-export-button" type="submit" data-export-preview><?= $escape($t('Vorschau aktualisieren')) ?></button><span><?= $escape($t('Für die gesamte Auswahl, über alle Listenseiten.')) ?></span></div>
          </section>

          <section class="studio-export-panel studio-export-preview" aria-labelledby="export-preview-title">
            <div class="studio-export-panel-heading"><div><p class="studio-eyebrow"><?= $escape($t('Exportvorschau')) ?></p><h2 id="export-preview-title"><?= $escape($t($profileOptions[$profile]['title'])) ?></h2></div><span class="studio-export-format" data-export-format="<?= $escape($view->downloadFormat) ?>"><?= $escape($downloadFormat) ?></span></div>
            <p class="studio-export-current-scope" data-export-scope="<?= $escape($filter->scope) ?>"><?= $escape($t($scopeLabels[$filter->scope])) ?></p>
            <dl class="studio-export-counts">
              <div><dt><?= $escape($t('Fälle')) ?></dt><dd data-export-finding-count="<?= $escape($view->findingCount) ?>"><?= $escape($formatNumber($view->findingCount)) ?></dd></div>
              <div><dt><?= $escape($t('Domains')) ?></dt><dd data-export-domain-count="<?= $escape($view->domainCount) ?>"><?= $escape($formatNumber($view->domainCount)) ?></dd></div>
            </dl>
            <?php if ($profile === 'report'): ?>
              <dl class="studio-export-image-counts" aria-label="<?= $escape($t('Bildauswahl für die Meldung')) ?>">
                <div><dt><?= $escape($t('Voraussichtlich beifügbare Bilder')) ?></dt><dd data-export-screenshot-count="<?= $escape($view->screenshotCount) ?>"><?= $escape($formatNumber($view->screenshotCount)) ?></dd></div>
                <div><dt><?= $escape($t('Nicht beigefügte Bilddateien')) ?></dt><dd data-export-missing-screenshot-count="<?= $escape($view->missingScreenshotCount) ?>"><?= $escape($formatNumber($view->missingScreenshotCount)) ?></dd></div>
                <div><dt><?= $escape($t('Fälle ohne bekannte Bildgrundlage der Bewertung')) ?></dt><dd data-export-unknown-basis-count="<?= $escape($view->unknownBasisCount) ?>"><?= $escape($formatNumber($view->unknownBasisCount)) ?></dd></div>
              </dl>
            <?php endif; ?>
            <p class="studio-export-hint"><?= $escape($t('Zähler für die angewendete Auswahl.')) ?><?= $profile === 'report' ? ' '.$escape($t('Die Vorschau prüft Bildpfad, Lesbarkeit und Größe; Hash und tatsächliches Bildformat werden beim Download geprüft.')) : '' ?> <?= $escape($t('Aktualisiere die Vorschau nach Änderungen an Filtern oder Inhalten.')) ?></p>
            <?php if ($view->findingCount === 0 && !$isFirstStart): ?><p class="studio-export-empty" role="status"><?= $escape($t('Keine Fälle für diese Auswahl. Passe den Bereich oder die Filter an.')) ?></p><?php endif; ?>
            <?php if ($hasLegacyFilters): ?><p class="studio-export-empty"><?= $escape($t('Diagnosefilter aktiv. Die Herkunft der historischen Werte bleibt unklar.')) ?></p><?php endif; ?>
            <a class="studio-export-text-link studio-export-inventory-link" href="<?= $escape($view->inventoryPath) ?>"><?= $escape($t('Auswahl im Bestand ansehen')) ?> <span aria-hidden="true">↗</span></a>

            <?php if ($profile === 'urls'): ?>
              <div class="studio-export-contents"><h3><?= $escape($t('Das ist enthalten')) ?></h3><p><?= $escape($t('Eine kompakte JSON-Liste mit URL und gespeichertem Befundtyp je Fall.')) ?></p></div>
            <?php else: ?>
              <fieldset class="studio-export-customize">
                <legend><?= $escape($t('Inhalt anpassen')) ?></legend>
                <p class="studio-export-hint"><?= $escape($t('URL, Titel, Befundtyp und Schweregrad bilden die Fallübersicht.')) ?><?= $profile === 'report' ? ' '.$escape($t('Das Meldungspaket verwendet neutrale Fallnummern.')) : '' ?> <?= $escape($t('Wähle die zusätzlichen Angaben selbst.')) ?></p>
                <div class="studio-export-content-options">
                  <?php foreach ($contentGroups as $name => $group): ?>
                    <input type="hidden" name="<?= $escape($name) ?>" value="0">
                    <label class="studio-export-option" for="export-<?= $escape($name) ?>"><input type="checkbox" name="<?= $escape($name) ?>" value="1" id="export-<?= $escape($name) ?>"<?= $group['enabled'] ? ' checked' : '' ?>><span><?= $escape($t($group['label'])) ?><small><?= $escape($t($group['hint'])) ?></small></span></label>
                  <?php endforeach; ?>
                </div>

                <?php if ($profile === 'report'): ?>
                  <label class="studio-export-field studio-export-screenshots" for="export-screenshots">Screenshots
                    <select name="screenshots" id="export-screenshots" aria-describedby="export-screenshot-hint"><?php foreach ($screenshotLabels as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $view->screenshotMode === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?></select>
                  </label>
                  <p class="studio-export-hint" id="export-screenshot-hint"><?= $escape($t($screenshotHints[$view->screenshotMode])) ?></p>
                <?php endif; ?>

                <input type="hidden" name="include_notes" value="0">
                <label class="studio-export-notes" for="export-include-notes"><input type="checkbox" name="include_notes" value="1" id="export-include-notes"<?= $view->includePrivateNotes ? ' checked' : '' ?>><span><?= $escape($t('Private Fallnotizen einschließen')) ?><small><?= $escape($t('Nur nach deiner ausdrücklichen Auswahl.')) ?></small></span></label>
              </fieldset>

              <div class="studio-export-contents studio-export-package-description"><h3><?= $escape($t('Dein Download')) ?></h3>
                <?php if ($profile === 'report'): ?><p><?= $escape($t('ZIP mit lesbarem Bericht, strukturierten Falldaten und den verfügbaren ausgewählten Bildern. Fehlende, veränderte, ungültige oder zu große Dateien und unbekannte Bewertungsgrundlagen werden ausgewiesen.')) ?></p>
                <?php else: ?><p><?= $escape($t('JSON mit dem aktuellen Fallstand, den gewählten Angaben und Belegverweisen. Bilddateien sind darin nicht enthalten.')) ?></p><?php endif; ?>
                <p><?= $escape($t('Vollständige Verläufe bleiben separat im Projekt gespeichert.')) ?></p>
              </div>
            <?php endif; ?>
            <button class="studio-export-button studio-export-button-primary" type="submit" formaction="/export/download" data-export-download><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M4 15v5h16v-5"/></svg><?= $escape($t('{format} herunterladen', ['format' => $downloadFormat])) ?></button>
            <p class="studio-export-download-hint"><?= $escape($t('Der Download verwendet die gewählten Filter und Inhaltsoptionen.')) ?></p>
          </section>
        </form>
      </div>
    </main>

    <?php $activeWorkspace = 'export'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
</body>
</html>
