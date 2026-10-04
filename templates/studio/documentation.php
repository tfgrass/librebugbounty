<?php
/** @var array{slug: string, title: string, html: string} $page */
/** @var list<array{slug: string, title: string}> $pages */
/** @var callable $escape */
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($t($page['title'])) ?> · <?= $escape(\App\AppInfo::NAME.' '.\App\AppInfo::RELEASE_NAME) ?></title>
  <link rel="stylesheet" href="/css/studio.css">
  <link rel="stylesheet" href="/css/studio-documentation.css">
  <script src="/js/i18n.js" defer></script>
</head>
<body data-studio data-documentation>
  <div class="studio-shell">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
      <div class="studio-header-tools"><?php require __DIR__.'/language.php'; ?></div>
    </header>
    <main class="studio-doc-main">
      <div class="studio-doc-layout">
        <aside class="studio-doc-sidebar">
          <a class="studio-doc-back" href="/settings">← <?= $escape($t('Zurück zu Einstellungen')) ?></a>
          <h1><?= $escape($t('Dokumentation')) ?></h1>
          <p class="studio-doc-language"><?= $escape($t('Englische Dokumentation')) ?></p>
          <nav aria-label="<?= $escape($t('Dokumentation')) ?>">
            <?php foreach ($pages as $item): ?>
              <a href="<?= $escape('/docs/'.$item['slug']) ?>"<?= $page['slug'] === $item['slug'] ? ' aria-current="page"' : '' ?>><?= $escape($t($item['title'])) ?></a>
            <?php endforeach; ?>
          </nav>
        </aside>
        <article class="studio-doc-prose" lang="en" data-documentation-page="<?= $escape($page['slug']) ?>">
          <?= $page['html'] ?>
        </article>
      </div>
    </main>
    <?php $activeWorkspace = 'settings'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
</body>
</html>
