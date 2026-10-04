<a class="studio-brand" href="/" aria-label="<?= $escape(\App\AppInfo::NAME.' v'.\App\AppInfo::VERSION.' '.\App\AppInfo::RELEASE_NAME.', '.$t('Eingang')) ?>">
  <?php require __DIR__.'/logo.php'; ?>
  <span class="studio-brand-copy">
    <span class="studio-brand-name"><?= $escape(\App\AppInfo::NAME) ?></span>
    <span class="studio-brand-version">v<?= $escape(\App\AppInfo::VERSION) ?></span>
    <span class="studio-brand-tag"><?= $escape(\App\AppInfo::RELEASE_NAME) ?></span>
  </span>
</a>
