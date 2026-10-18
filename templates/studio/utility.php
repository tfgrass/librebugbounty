<?php
/** @var string $utility */
/** @var string $utilityTitle */
/** @var string $utilityTemplate */
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($utilityTitle) ?> · <?= $escape($app['name'].' '.$app['releaseName']) ?></title>
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
            <p class="studio-eyebrow"><?= $escape($t('Arbeitsumgebung')) ?></p>
            <h1><?= $escape($utilityTitle) ?></h1>
          </div>
        </header>
        <?php require $utilityTemplate; ?>
      </div>
    </main>
    <?php $activeWorkspace = $utility; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
</body>
</html>
