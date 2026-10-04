<?php
$languageReturnPath = is_string($languageReturnPath ?? null) ? $languageReturnPath : '/';
if (!str_starts_with($languageReturnPath, '/')
    || str_starts_with($languageReturnPath, '//')
    || str_contains($languageReturnPath, '\\')
    || preg_match('/[\x00-\x1F\x7F]/', $languageReturnPath)
) {
    $languageReturnPath = '/';
}
?>
<nav class="studio-language" data-language-switcher aria-label="<?= $escape($t('Sprache')) ?>">
  <?php foreach (['en' => 'English', 'de' => 'Deutsch'] as $languageCode => $languageName): ?>
    <a href="<?= $escape('/language/'.$languageCode.'?'.http_build_query(['return' => $languageReturnPath], '', '&', PHP_QUERY_RFC3986)) ?>" data-language="<?= $escape($languageCode) ?>" lang="<?= $escape($languageCode) ?>" hreflang="<?= $escape($languageCode) ?>" aria-label="<?= $escape($languageName) ?>" title="<?= $escape($t('Zur Sprache {language} wechseln', ['language' => $languageName])) ?>"<?= $locale === $languageCode ? ' aria-current="page"' : '' ?>><?= $escape(strtoupper($languageCode)) ?></a>
  <?php endforeach; ?>
</nav>
<?php unset($languageReturnPath, $languageCode, $languageName); ?>
