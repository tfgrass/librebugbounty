<?php
/** @var string $defaultPayload */
/** @var string $reviewTimeout */
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
    </header>

    <main class="studio-settings-main">
      <div class="studio-settings-content">
        <header class="studio-settings-heading">
          <div>
            <p class="studio-eyebrow"><?= $escape($t('Arbeitsumgebung')) ?></p>
            <h1><?= $escape($t('Einstellungen & Info')) ?></h1>
            <p><?= $escape($t('Wenige globale Vorgaben für Erfassung und Screenshot-Aufnahmen.')) ?></p>
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
              <div><p class="studio-eyebrow"><?= $escape($t('Allgemein')) ?></p><h2 id="settings-title"><?= $escape($t('Vorgaben')) ?></h2></div>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 3-1 3-3 1-2 3 2 2v3l3 1 1 3h4l1-3 3-1v-3l2-2-2-3-3-1-1-3H9Z" transform="translate(1 1)" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.4"/></svg>
            </div>
            <form method="post" action="/settings" class="studio-settings-form">
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

              <div class="studio-settings-actions">
                <button type="submit"><?= $escape($t('Einstellungen speichern')) ?></button>
                <a href="/"><?= $escape($t('Zum Eingang')) ?></a>
              </div>
            </form>
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
                <li><?= $escape($t('Deutsche Oberfläche als Standard, Englisch über APP_LOCALE.')) ?></li>
              </ul>
            </section>
            <dl class="studio-about-facts">
              <div><dt><?= $escape($t('Autor')) ?></dt><dd><a href="<?= $escape($app['homepage']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($app['author']) ?> <span aria-hidden="true">↗</span></a></dd></div>
              <div><dt>OpenBugBounty</dt><dd><a href="<?= $escape($app['profileUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($app['profile']) ?> <span aria-hidden="true">↗</span></a></dd></div>
              <div><dt><?= $escape($t('Quellcode')) ?></dt><dd><a href="<?= $escape($app['repository']) ?>" target="_blank" rel="noopener noreferrer">GitHub <span aria-hidden="true">↗</span></a></dd></div>
              <div><dt><?= $escape($t('Lizenz')) ?></dt><dd><code><?= $escape($app['license']) ?></code></dd></div>
            </dl>
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
