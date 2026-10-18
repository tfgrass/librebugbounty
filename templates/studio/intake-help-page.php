<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($t('URL erfassen – so funktioniert’s')) ?> · <?= $escape(\App\AppInfo::NAME.' '.\App\AppInfo::RELEASE_NAME) ?></title>
  <?php require __DIR__.'/styles.php'; ?>
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
            <p class="studio-eyebrow"><?= $escape($t('Eingang')) ?></p>
            <h1><?= $escape($t('URL erfassen – so funktioniert’s')) ?></h1>
          </div>
        </header>
        <div class="studio-settings-panel">
          <?php require __DIR__.'/intake-help-content.php'; ?>
          <p class="studio-intake-help-return"><a href="/"><?= $escape($t('Zurück zur URL-Erfassung')) ?></a></p>
        </div>
      </div>
    </main>
    <?php $activeWorkspace = 'intake'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
</body>
</html>
