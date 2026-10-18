<?php
/** @var string $defaultPayload */
/** @var string $csrfToken */
/** @var callable $escape */
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($t('Eingang')) ?> · <?= $escape(\App\AppInfo::NAME.' '.\App\AppInfo::RELEASE_NAME) ?></title>
  <?php require __DIR__.'/styles.php'; ?>
  <script src="/js/i18n.js" defer></script>
  <script src="/js/intake.js?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/js/intake.js'), 0, 16) ?>" defer></script>
  <script src="/js/intake-help.js?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/js/intake-help.js'), 0, 16) ?>" defer></script>
</head>
<body data-studio>
  <div class="studio-shell">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
      <div class="studio-header-tools"><?php require __DIR__.'/language.php'; ?></div>
    </header>

    <main class="studio-workspace" data-intake data-intake-create-url="/api/findings" data-intake-status-url="/api/findings/status" data-intake-activity-url="/api/intake/activity" data-intake-storage-key="librebugbounty.intake.v1">
      <section class="studio-composer" aria-labelledby="studio-intake-title">
        <div class="studio-composer-heading">
          <div>
            <p class="studio-eyebrow"><?= $escape($t('Eingang')) ?></p>
            <div class="studio-intake-title-row">
              <h1 id="studio-intake-title"><?= $escape($t('URL erfassen')) ?></h1>
              <a class="studio-help-icon" href="/intake/help" data-intake-help-open aria-haspopup="dialog" aria-label="<?= $escape($t('Hilfe zu URL und Kennzeichen')) ?>" title="<?= $escape($t('Hilfe zu URL und Kennzeichen')) ?>">?</a>
            </div>
          </div>
          <span class="studio-key-hint" aria-hidden="true"><kbd>↵</kbd> <?= $escape($t('zum Erfassen')) ?></span>
        </div>

        <?php if ($message !== null && $message !== ''): ?><p class="studio-notice" role="status"><?= $escape($message) ?></p><?php endif; ?>
        <?php if ($error !== null && $error !== ''): ?><p class="studio-notice studio-entry-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>

        <form method="post" action="/findings" id="intake-form" class="studio-form">
          <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
          <div class="studio-url-row">
            <label class="studio-url-field" for="studio-url">
              <span class="studio-sr-only">URL</span>
              <svg class="studio-url-icon" width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m8 12 4-4m-5 6-1 1a3.5 3.5 0 0 1-5-5l4-4a3.5 3.5 0 0 1 5 0m0 8a3.5 3.5 0 0 0 5 0l4-4a3.5 3.5 0 0 0-5-5l-1 1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
              <input id="studio-url" name="url" type="url" inputmode="url" autocomplete="url" autocapitalize="off" spellcheck="false" placeholder="https://example.com/" required autofocus>
            </label>
            <button class="studio-submit" type="submit">
              <?= $escape($t('Erfassen')) ?>
            </button>
          </div>

          <details class="studio-details">
            <summary>
              <span class="studio-details-label"><?= $escape($t('Details hinzufügen')) ?></span>
              <span class="studio-payload-preview"><?= $escape($t('Kennzeichen')) ?> <span data-intake-payload-label><?= $escape($defaultPayload) ?></span></span>
            </summary>
            <div class="studio-details-fields">
              <div class="studio-marker-field">
                <label for="studio-payload"><?= $escape($t('Kennzeichen')) ?></label>
                <input id="studio-payload" name="payload" value="<?= $escape($defaultPayload) ?>" placeholder="<?= $escape($defaultPayload) ?>" spellcheck="false" aria-describedby="studio-marker-hint">
                <p id="studio-marker-hint" class="studio-marker-hint"><?= $escape($t('Erwarteter Dialogtext beim Nachtesten')) ?></p>
              </div>
              <label for="studio-notes"><?= $escape($t('Notiz')) ?> <span class="studio-optional"><?= $escape($t('Optional')) ?></span>
                <textarea id="studio-notes" name="annotate" placeholder="<?= $escape($t('Eine Notiz für diesen Fall')) ?>" rows="2"></textarea>
              </label>
            </div>
          </details>
        </form>

        <p id="intake-notice" class="studio-notice" role="status" aria-live="polite"></p>
        <noscript><p class="studio-notice"><?= $escape($t('JavaScript ist deaktiviert. Erfassen speichert den Fall und öffnet seine Detailseite.')) ?></p></noscript>
      </section>

      <section class="studio-intake-activity" data-intake-activity hidden aria-label="<?= $escape($t('Erfassungsfortschritt')) ?>">
        <div class="studio-activity-heading">
          <button type="button" class="studio-activity-toggle" data-intake-activity-toggle aria-expanded="true" aria-controls="intake-activity-details intake-activity-controls"><span class="studio-activity-chevron" aria-hidden="true">⌄</span><span class="studio-activity-label"><?= $escape($t('Dein Fortschritt')) ?></span><span class="studio-activity-compact" data-intake-compact-summary hidden></span></button>
          <div class="studio-activity-controls" id="intake-activity-controls">
            <label><span class="studio-sr-only"><?= $escape($t('Zeitraum für Fortschritt')) ?></span><select data-intake-period>
              <option value="session"><?= $escape($t('Aktuelle Session')) ?></option>
              <option value="today"><?= $escape($t('Heute')) ?></option>
              <option value="window"><?= $escape($t('Letztes Zeitfenster')) ?></option>
            </select></label>
            <label><span class="studio-sr-only"><?= $escape($t('Zeitfenster für Tempovergleich')) ?></span><select data-intake-minutes>
              <?php foreach ([5, 10, 15, 30, 60, 120] as $minutes): ?><option value="<?= $minutes ?>"<?= $minutes === 15 ? ' selected' : '' ?>><?= $escape($t('{minutes} Min', ['minutes' => $minutes])) ?></option><?php endforeach; ?>
            </select></label>
          </div>
        </div>
        <div id="intake-activity-details">
        <div class="studio-activity-metrics">
          <div class="studio-activity-metric studio-activity-progress">
            <div><strong data-intake-stat-count>0</strong> <span><?= $escape($t('neu erfasst')) ?></span></div>
            <div class="studio-activity-goal"><progress data-intake-milestone value="0" max="10" aria-label="<?= $escape($t('Fortschritt zur nächsten Etappe')) ?>"></progress><span data-intake-milestone-label><?= $escape($t('Etappe {count}', ['count' => 10])) ?></span></div>
          </div>
          <div class="studio-activity-metric"><div class="studio-activity-pace" data-intake-stat-pace>—</div><span data-intake-stat-average><?= $escape($t('Tempo wird geladen')) ?></span></div>
          <div class="studio-activity-metric studio-activity-clock"><div data-intake-stat-time><?= $escape($t('Noch keine Session')) ?></div><button type="button" class="studio-activity-action" data-intake-session-end hidden><?= $escape($t('Session beenden')) ?></button><span data-intake-session-hint><?= $escape($t('Startet mit deiner ersten Erfassung')) ?></span></div>
        </div>
        <p class="studio-activity-context" data-intake-activity-context><?= $escape($t('Nur deine URL-Erfassungen · 2 Std. 30 Min. ohne Erfassung trennen Sessions.')) ?></p>
        <div class="studio-activity-summary" data-intake-session-summary hidden><p data-intake-session-summary-text></p><button type="button" class="studio-activity-action" data-intake-session-resume><?= $escape($t('Vorige Session fortsetzen')) ?></button></div>
        </div>
        <p class="studio-notice" data-intake-activity-notice role="status" aria-live="polite"></p>
        <span class="studio-sr-only" data-intake-milestone-notice role="status" aria-live="polite"></span>
      </section>

      <section id="intake-history" class="studio-history" aria-labelledby="studio-history-title">
        <div class="studio-history-heading">
          <h2 id="studio-history-title" data-intake-count><?= $escape($t('Letzte {count}', ['count' => 0])) ?></h2>
          <label class="studio-history-filter" data-intake-history-filter hidden><input type="checkbox" data-intake-session-only checked> <?= $escape($t('Nur aktuelle Session')) ?></label>
        </div>
        <div class="studio-history-messages">
          <p id="intake-storage-notice" class="studio-notice" role="status"></p>
          <p id="intake-status-notice" class="studio-notice" role="status"></p>
        </div>
        <div class="studio-history-content">
          <div id="intake-history-list" class="studio-history-list" tabindex="0" aria-label="<?= $escape($t('Eingaben dieser Sitzung')) ?>"></div>
          <div class="studio-empty" data-intake-empty>
            <svg width="34" height="34" viewBox="0 0 36 36" fill="none" aria-hidden="true"><path d="M18 7v17m-6-6 6 6 6-6M8 23v6h20v-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <p><?= $escape($t('Bereit für die erste URL')) ?></p>
            <span><?= $escape($t('Einfügen, Enter. Dein Verlauf erscheint hier.')) ?></span>
          </div>
          <div id="intake-toasts" class="studio-toasts" role="status" aria-live="polite"></div>
        </div>
        <p class="studio-session-hint" data-intake-history-hint><?= $escape($t('Die letzten 50 Eingaben bleiben in diesem Tab erhalten.')) ?></p>
      </section>
    </main>

    <dialog id="studio-intake-help" class="studio-utility-dialog studio-intake-help" aria-labelledby="studio-intake-help-title">
      <header class="studio-utility-dialog-header">
        <h2 id="studio-intake-help-title"><?= $escape($t('URL erfassen – so funktioniert’s')) ?></h2>
        <button type="button" data-intake-help-close autofocus><?= $escape($t('Schließen')) ?></button>
      </header>
      <div class="studio-utility-dialog-body"><?php require __DIR__.'/intake-help-content.php'; ?></div>
    </dialog>

    <?php $activeWorkspace = 'intake'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
</body>
</html>
