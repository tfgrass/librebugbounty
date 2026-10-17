<?php

/** @var \App\Entity\Finding $finding */
/** @var string $interactiveCsrfToken */
/** @var string $returnPath */
/** @var callable $escape */
/** @var callable $t */
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <title><?= $escape($t('Interaktiv prüfen')) ?> · <?= $escape($finding->getDomain()->getHostname()) ?></title>
  <link rel="stylesheet" href="/css/studio.css">
</head>
<body data-studio>
  <main class="interactive-shell novnc-shell">
    <header class="interactive-header">
      <div>
        <p class="studio-eyebrow"><?= $escape($t('Interaktiv prüfen')) ?></p>
        <h1><?= $escape($finding->getDomain()->getHostname()) ?></h1>
        <p class="interactive-url"><?= $escape($finding->getUrl()) ?></p>
      </div>
      <?php
      // The controller validates return_to; also keep standalone template use local.
      $returnPath = isset($returnPath) && preg_match('~^/review(?:[/?]|$)~D', $returnPath) && !preg_match('/[\r\n]/', $returnPath) ? $returnPath : '/review';
      ?>
      <a class="interactive-back" href="<?= $escape($returnPath) ?>"><?= $escape($t('Zurück zum Review')) ?> ↩</a>
    </header>
    <?php require __DIR__.'/review-novnc-panel.php'; ?>
  </main>
</body>
</html>
