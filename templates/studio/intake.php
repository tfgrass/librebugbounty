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
  <link rel="stylesheet" href="/css/studio.css">
  <script src="/js/i18n.js" defer></script>
  <script src="/js/intake.js" defer></script>
</head>
<body data-studio>
  <div class="studio-shell">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
    </header>

    <main class="studio-workspace" data-intake data-intake-create-url="/api/findings" data-intake-status-url="/api/findings/status" data-intake-storage-key="librebugbounty.intake.v1">
      <section class="studio-composer" aria-labelledby="studio-intake-title">
        <div class="studio-composer-heading">
          <div>
            <p class="studio-eyebrow"><?= $escape($t('Eingang')) ?></p>
            <h1 id="studio-intake-title"><?= $escape($t('URL erfassen')) ?></h1>
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
              <label for="studio-payload"><?= $escape($t('Kennzeichen')) ?>
                <input id="studio-payload" name="payload" value="<?= $escape($defaultPayload) ?>" placeholder="<?= $escape($defaultPayload) ?>" spellcheck="false">
              </label>
              <label for="studio-notes"><?= $escape($t('Notiz')) ?> <span class="studio-optional"><?= $escape($t('Optional')) ?></span>
                <textarea id="studio-notes" name="annotate" placeholder="<?= $escape($t('Eine Notiz für diesen Fall')) ?>" rows="2"></textarea>
              </label>
            </div>
          </details>
        </form>

        <p class="studio-composer-hint"><?= $escape($t('Speichern, nächste URL. Der Screenshot entsteht im Hintergrund.')) ?></p>
        <p id="intake-notice" class="studio-notice" role="status" aria-live="polite"></p>
        <noscript><p class="studio-notice"><?= $escape($t('JavaScript ist deaktiviert. Erfassen speichert den Fall und öffnet seine Detailseite.')) ?></p></noscript>
      </section>

      <section id="intake-history" class="studio-history" aria-labelledby="studio-history-title">
        <div class="studio-history-heading">
          <h2 id="studio-history-title"><?= $escape($t('Diese Sitzung')) ?></h2>
          <span class="studio-session-count"><span data-intake-count>0</span> <?= $escape($t('Eingaben')) ?></span>
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
        <p class="studio-session-hint"><?= $escape($t('Die letzten 50 Eingaben bleiben in diesem Tab erhalten.')) ?></p>
      </section>
    </main>

    <?php $activeWorkspace = 'intake'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
</body>
</html>
