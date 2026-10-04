<?php
/** @var string $defaultPayload */
/** @var string $reviewTimeout */
/** @var string $reviewDecisionDelay */
/** @var string $inventoryPageSize */
/** @var string $exportProfile */
/** @var string $exportScreenshotMode */
/** @var array<string, string> $errors */
/** @var ?string $message */
/** @var array<string, string> $app */
/** @var callable $escape */
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($t('Einstellungen & Info')) ?> · <?= $escape($app['name'].' '.$app['releaseName']) ?></title>
  <link rel="stylesheet" href="/css/studio.css">
  <link rel="stylesheet" href="/css/studio-settings.css">
  <script src="/js/i18n.js" defer></script>
</head>
<body data-studio data-studio-settings>
  <div class="studio-shell studio-settings-shell">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
      <div class="studio-header-tools"><?php require __DIR__.'/language.php'; ?></div>
    </header>

    <main class="studio-settings-main">
      <div class="studio-settings-content">
        <header class="studio-settings-heading">
          <div>
            <p class="studio-eyebrow"><?= $escape($t('Arbeitsumgebung')) ?></p>
            <h1><?= $escape($t('Einstellungen & Info')) ?></h1>
            <p><?= $escape($t('Vorgaben, Dokumentation und Informationen zur Anwendung.')) ?></p>
          </div>
        </header>

        <?php if ($message !== null && $message !== ''): ?>
          <p class="studio-settings-feedback" data-tone="success" role="status"><?= $escape($message) ?></p>
        <?php endif; ?>
        <?php if ($errors !== []): ?>
          <div class="studio-settings-feedback" data-tone="error" role="alert">
            <strong><?= $escape($t('Die Einstellungen wurden nicht gespeichert.')) ?></strong>
            <span><?= $escape($t('Bitte korrigiere die markierten Felder. Deine Eingaben bleiben erhalten.')) ?></span>
          </div>
        <?php endif; ?>

        <div class="studio-settings-layout">
          <section class="studio-settings-panel" aria-labelledby="settings-title">
            <div class="studio-settings-panel-heading">
              <div><h2 id="settings-title"><?= $escape($t('Vorgaben')) ?></h2></div>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 3-1 3-3 1-2 3 2 2v3l3 1 1 3h4l1-3 3-1v-3l2-2-2-3-3-1-1-3H9Z" transform="translate(1 1)" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.4"/></svg>
            </div>
            <form method="post" action="/settings" class="studio-settings-form">
              <p class="studio-settings-field-hint"><?= $escape($t('Diese Vorgaben gelten für die lokale Installation. Deine Auswahl auf den Seiten hat Vorrang.')) ?></p>
              <fieldset class="studio-settings-group">
              <legend><?= $escape($t('Allgemein')) ?></legend>
              <label for="settings-default-payload"><?= $escape($t('Standardkennzeichen')) ?>
                <span><?= $escape($t('Wird beim Erfassen neuer URLs vorausgefüllt.')) ?></span>
              </label>
              <input id="settings-default-payload" name="default_payload" value="<?= $escape($defaultPayload) ?>" autocomplete="off" spellcheck="false"<?= isset($errors['default_payload']) ? ' aria-invalid="true" aria-describedby="settings-default-payload-error"' : '' ?>>
              <?php if (isset($errors['default_payload'])): ?><p class="studio-settings-field-error" id="settings-default-payload-error"><?= $escape($errors['default_payload']) ?></p><?php endif; ?>

              <label for="settings-review-timeout"><?= $escape($t('Browser-Zeitlimit')) ?>
                <span><?= $escape($t('Gilt pro Screenshot-Aufnahme oder technischer Browserprüfung. Zulässig sind 1000 bis 120000 Millisekunden.')) ?></span>
              </label>
              <div class="studio-settings-timeout">
                <input id="settings-review-timeout" name="review_timeout_ms" value="<?= $escape($reviewTimeout) ?>" inputmode="numeric" pattern="[0-9]+" min="1000" max="120000" aria-describedby="settings-review-timeout-hint<?= isset($errors['review_timeout_ms']) ? ' settings-review-timeout-error' : '' ?>"<?= isset($errors['review_timeout_ms']) ? ' aria-invalid="true"' : '' ?>>
                <span>ms</span>
              </div>
              <p class="studio-settings-field-hint" id="settings-review-timeout-hint"><?= $escape($t('Ein höherer Wert hilft langsamen Zielseiten, bindet die jeweilige Browserprüfung aber länger.')) ?></p>
              <?php if (isset($errors['review_timeout_ms'])): ?><p class="studio-settings-field-error" id="settings-review-timeout-error"><?= $escape($errors['review_timeout_ms']) ?></p><?php endif; ?>
              </fieldset>

              <fieldset class="studio-settings-group">
              <legend><?= $escape($t('Review')) ?></legend>
              <label for="settings-review-decision-delay"><?= $escape($t('Entscheidungspause im Review')) ?></label>
              <select id="settings-review-decision-delay" name="review_decision_delay_seconds" aria-describedby="settings-review-decision-delay-hint<?= isset($errors['review_decision_delay_seconds']) ? ' settings-review-decision-delay-error' : '' ?>"<?= isset($errors['review_decision_delay_seconds']) ? ' aria-invalid="true"' : '' ?>>
                <?php if (!in_array($reviewDecisionDelay, ['0', '3', '5'], true)): ?><option value="<?= $escape($reviewDecisionDelay) ?>" selected><?= $escape($t('Ungültige Auswahl')) ?></option><?php endif; ?>
                <?php foreach (['0' => 'Aus', '3' => '3 Sekunden', '5' => '5 Sekunden'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $reviewDecisionDelay === (string) $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?>
              </select>
              <p class="studio-settings-field-hint" id="settings-review-decision-delay-hint"><?= $escape($t('Neue Fälle können erst nach Ablauf der Pause bewertet werden.')) ?></p>
              <?php if (isset($errors['review_decision_delay_seconds'])): ?><p class="studio-settings-field-error" id="settings-review-decision-delay-error"><?= $escape($errors['review_decision_delay_seconds']) ?></p><?php endif; ?>
              </fieldset>

              <fieldset class="studio-settings-group">
              <legend><?= $escape($t('Bestand')) ?></legend>
              <label for="settings-inventory-page-size"><?= $escape($t('Fälle pro Seite')) ?></label>
              <select id="settings-inventory-page-size" name="inventory_page_size" aria-describedby="settings-inventory-page-size-hint<?= isset($errors['inventory_page_size']) ? ' settings-inventory-page-size-error' : '' ?>"<?= isset($errors['inventory_page_size']) ? ' aria-invalid="true"' : '' ?>>
                <?php if (!in_array($inventoryPageSize, ['10', '25', '50', '100'], true)): ?><option value="<?= $escape($inventoryPageSize) ?>" selected><?= $escape($t('Ungültige Auswahl')) ?></option><?php endif; ?>
                <?php foreach (['10', '25', '50', '100'] as $value): ?><option value="<?= $escape($value) ?>"<?= $inventoryPageSize === $value ? ' selected' : '' ?>><?= $escape($value) ?></option><?php endforeach; ?>
              </select>
              <p class="studio-settings-field-hint" id="settings-inventory-page-size-hint"><?= $escape($t('Startwert für den Bestand. Alle Fälle kannst du weiterhin direkt auf der Seite wählen.')) ?></p>
              <?php if (isset($errors['inventory_page_size'])): ?><p class="studio-settings-field-error" id="settings-inventory-page-size-error"><?= $escape($errors['inventory_page_size']) ?></p><?php endif; ?>
              </fieldset>

              <fieldset class="studio-settings-group">
              <legend><?= $escape($t('Export')) ?></legend>
              <label for="settings-export-profile"><?= $escape($t('Bevorzugte Exportvorlage')) ?></label>
              <select id="settings-export-profile" name="export_profile" aria-describedby="settings-export-profile-hint<?= isset($errors['export_profile']) ? ' settings-export-profile-error' : '' ?>"<?= isset($errors['export_profile']) ? ' aria-invalid="true"' : '' ?>>
                <?php if (!in_array($exportProfile, ['urls', 'state', 'report'], true)): ?><option value="<?= $escape($exportProfile) ?>" selected><?= $escape($t('Ungültige Auswahl')) ?></option><?php endif; ?>
                <?php foreach (['report' => ['Meldung mit Belegen', 'ZIP'], 'state' => ['Aktueller Fallstand', 'JSON'], 'urls' => ['URL-Liste', 'JSON']] as $value => [$label, $format]): ?><option value="<?= $escape($value) ?>"<?= $exportProfile === $value ? ' selected' : '' ?>><?= $escape($t($label).' ('.$format.')') ?></option><?php endforeach; ?>
              </select>
              <p class="studio-settings-field-hint" id="settings-export-profile-hint"><?= $escape($t('Öffnet Export mit dieser Vorlage. Inhalte und private Notizen wählst du bei jedem Export selbst.')) ?></p>
              <?php if (isset($errors['export_profile'])): ?><p class="studio-settings-field-error" id="settings-export-profile-error"><?= $escape($errors['export_profile']) ?></p><?php endif; ?>

              <label for="settings-export-screenshots"><?= $escape($t('Screenshots im Meldungspaket')) ?></label>
              <select id="settings-export-screenshots" name="export_screenshot_mode" aria-describedby="settings-export-screenshots-hint<?= isset($errors['export_screenshot_mode']) ? ' settings-export-screenshots-error' : '' ?>"<?= isset($errors['export_screenshot_mode']) ? ' aria-invalid="true"' : '' ?>>
                <?php if (!in_array($exportScreenshotMode, ['basis', 'latest', 'all', 'none'], true)): ?><option value="<?= $escape($exportScreenshotMode) ?>" selected><?= $escape($t('Ungültige Auswahl')) ?></option><?php endif; ?>
                <?php foreach (['latest' => 'Neuester gespeicherter Bildbeleg', 'basis' => 'Beleg meiner Bewertung', 'all' => 'Alle gespeicherten Bildbelege', 'none' => 'Keine Bilddateien'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $exportScreenshotMode === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?>
              </select>
              <p class="studio-settings-field-hint" id="settings-export-screenshots-hint"><?= $escape($t('Startwert nur für ZIP-Pakete. Der neueste Screenshot ist nicht automatisch der Beleg deiner Bewertung.')) ?></p>
              <?php if (isset($errors['export_screenshot_mode'])): ?><p class="studio-settings-field-error" id="settings-export-screenshots-error"><?= $escape($errors['export_screenshot_mode']) ?></p><?php endif; ?>
              </fieldset>

              <div class="studio-settings-actions">
                <button type="submit"><?= $escape($t('Einstellungen speichern')) ?></button>
                <a href="/"><?= $escape($t('Zum Eingang')) ?></a>
              </div>
            </form>
            <section class="studio-settings-docs" aria-labelledby="settings-docs-title" data-documentation-links>
              <h3 id="settings-docs-title"><?= $escape($t('Dokumentation')) ?></h3>
              <p><?= $escape($t('Direkt hier lesen, auch ohne Internet. Die Dokumentation ist auf Englisch.')) ?></p>
              <nav aria-label="<?= $escape($t('Dokumentation')) ?>">
                <a href="/docs/usage"><?= $escape($t('Benutzerhandbuch')) ?> <span aria-hidden="true">→</span></a>
                <a href="/docs/readme"><?= $escape($t('Installation & Upgrade')) ?> <span aria-hidden="true">→</span></a>
                <a href="/docs/backup"><?= $escape($t('Sicherung & Wiederherstellung')) ?> <span aria-hidden="true">→</span></a>
                <a href="/docs/changelog">Changelog <span aria-hidden="true">→</span></a>
                <a href="/docs/roadmap">Roadmap <span aria-hidden="true">→</span></a>
              </nav>
            </section>
          </section>

          <section class="studio-settings-panel studio-about-panel" id="about" aria-labelledby="about-title">
            <div class="studio-about-hero" data-about-release>
              <?php $logoSize = 72; $logoClass = 'studio-about-logo'; require __DIR__.'/logo.php'; unset($logoSize, $logoClass); ?>
              <h2 id="about-title"><?= $escape($app['name']) ?></h2>
              <p class="studio-about-version">v<?= $escape($app['version']) ?> <span aria-hidden="true">·</span> <?= $escape($app['releaseName']) ?></p>
            </div>
            <p class="studio-about-lead"><?= $escape($t('Eine lokale Arbeitsumgebung für Bug-Bounty-Ingest, technische Prüfung, Bildbelege und manuelle Entscheidungen.')) ?></p>
            <section class="studio-about-release-notes" data-release-notes aria-labelledby="release-notes-title">
              <h3 id="release-notes-title"><?= $escape($t('Neu in {version}', ['version' => $app['version']])) ?></h3>
              <ul>
                <li><?= $escape($t('Ein gemeinsamer Arbeitsbereich für Eingang, Bestand, Review, Statistik und Export.')) ?></li>
                <li><?= $escape($t('Bildbelege manuell bewerten und neue Widersprüche, Unklarheiten oder Fehler erneut sichten.')) ?></li>
                <li><?= $escape($t('Gemeldete, kontaktierte und behobene Fälle gemeinsam im Rückblick auswerten.')) ?></li>
                <li><?= $escape($t('URL-Listen, Fallstatus oder Berichtspakete mit ausgewählten Bildbelegen exportieren.')) ?></li>
                <li><?= $escape($t('Englische Oberfläche als Standard, Deutsch und Englisch direkt im Header wählen.')) ?></li>
              </ul>
            </section>
            <details class="studio-about-history" data-release-history>
              <summary><?= $escape($t('Versionsgeschichte')) ?></summary>
              <div class="studio-about-history-entries">
                <article data-release-entry>
                  <h3>v<?= $escape($app['version']) ?> <span aria-hidden="true">·</span> <?= $escape($app['releaseName']) ?></h3>
                  <p><?= $escape($t('Aus dem Prototyp wird ein vollständiger lokaler Arbeitsbereich: schneller Eingang, bildbasierter Review, Aktivitätsstatistik und konfigurierbare Exporte.')) ?></p>
                </article>
                <article data-release-entry>
                  <h3>v1.1.0 <span aria-hidden="true">·</span> Scriptor Quo</h3>
                  <p><?= $escape($t('Aus der täglichen Nutzung entstanden bessere Prüf- und Screenshot-Abläufe, Kontaktmarkierungen und Domain-Exporte. Schrittweise Verfeinerungen des bestehenden Workflows.')) ?></p>
                </article>
                <article data-release-entry>
                  <h3>v1.0.0 <span aria-hidden="true">·</span> Scriptor</h3>
                  <p><?= $escape($t('Die Idee eines lokalen OpenBugBounty-Workflows wurde zum ersten nutzbaren Prototyp: URL-Erfassung, Browserprüfung und Bildbelege als Grundlage für die manuelle Sichtung.')) ?></p>
                </article>
              </div>
            </details>
            <p class="studio-about-changelog"><a href="/docs/changelog"><?= $escape($t('Vollständigen Changelog lesen')) ?> <span aria-hidden="true">→</span></a></p>
            <dl class="studio-about-facts">
              <div><dt><?= $escape($t('Autor')) ?></dt><dd><a href="<?= $escape($app['homepage']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($app['author']) ?> <span aria-hidden="true">↗</span></a></dd></div>
              <div><dt>Flickr</dt><dd><a href="<?= $escape($app['flickrUrl']) ?>" target="_blank" rel="noopener noreferrer">tkoschka <span aria-hidden="true">↗</span></a></dd></div>
              <div><dt>OpenBugBounty</dt><dd><a href="<?= $escape($app['profileUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($app['profile']) ?> <span aria-hidden="true">↗</span></a></dd></div>
              <div><dt><?= $escape($t('Quellcode')) ?></dt><dd><a href="<?= $escape($app['repository']) ?>" target="_blank" rel="noopener noreferrer">GitHub <span aria-hidden="true">↗</span></a></dd></div>
              <div><dt><?= $escape($t('Lizenz')) ?></dt><dd><code><?= $escape($app['license']) ?></code></dd></div>
            </dl>
            <section class="studio-about-support" id="support" aria-labelledby="support-title">
              <h3 id="support-title"><?= $escape($t('LibreBugBounty unterstützen')) ?></h3>
              <p><?= $escape($t('Wenn dir LibreBugBounty hilft, kannst du die Weiterentwicklung freiwillig unterstützen.')) ?></p>
              <a href="<?= $escape($app['donationUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($t('Über PayPal unterstützen')) ?> <span aria-hidden="true">↗</span></a>
            </section>
            <p class="studio-about-vibe">Proudly vibe-coded.</p>
          </section>
        </div>
      </div>
    </main>

    <?php $activeWorkspace = 'settings'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
</body>
</html>
