<?php
/** @var callable $escape */
/** @var string $activeWorkspace */
$workspaceReviewPath = isset($returnPath) && is_string($returnPath) && str_starts_with($returnPath, '/review') ? $returnPath : '/review';
$workspaceInventoryPath = isset($returnPath) && is_string($returnPath) && str_starts_with($returnPath, '/findings') ? $returnPath : '/findings';
$workspaceExportPath = isset($view->filterQuery) && $activeWorkspace === 'inventory' ? '/export?'.http_build_query($view->filterQuery, '', '&', PHP_QUERY_RFC3986) : '/export';
$workspaceItems = [
    ['intake', '/', $t('Eingang'), '<path d="M12 3v12m-4-4 4 4 4-4M4 15v5h16v-5"/>'],
    ['review', $workspaceReviewPath, 'Review', '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 12 3 3 7-7"/>'],
    ['inventory', $workspaceInventoryPath, $t('Bestand'), '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>'],
    ['statistics', '/statistics', $t('Statistiken'), '<path d="M4 20V4m0 16h16M8 15l4-5 4 2 4-7"/>'],
    ['export', $workspaceExportPath, $t('Export'), '<path d="M12 16V3m-4 4 4-4 4 4M4 15v5h16v-5"/>'],
];
?>
<nav class="studio-workspace-nav" aria-label="<?= $escape($t('Arbeitsbereiche')) ?>">
  <?php foreach ($workspaceItems as [$workspaceKey, $workspacePath, $workspaceLabel, $workspaceIcon]): ?>
    <a class="studio-workspace-link<?= $activeWorkspace === $workspaceKey ? ' studio-workspace-link-active' : '' ?>" href="<?= $escape($workspacePath) ?>"<?= $activeWorkspace === $workspaceKey ? ' aria-current="page"' : '' ?>>
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $workspaceIcon ?></svg>
      <span><?= $escape($workspaceLabel) ?></span>
    </a>
    <?php if ($activeWorkspace === 'finding' && $workspaceKey === 'review'): ?>
      <span class="studio-workspace-link studio-workspace-link-active studio-detail-context" aria-current="page"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M14 4v16M4 14h10" stroke="currentColor" stroke-width="1.5"/></svg><span><?= $escape($t('Fall')) ?></span></span>
    <?php endif; ?>
  <?php endforeach; ?>
  <div class="studio-utility-nav" role="group" aria-label="<?= $escape($t('Anwendung')) ?>"
       data-utility-label-close="<?= $escape($t('Schließen')) ?>"
       data-utility-label-loading="<?= $escape($t('Wird geladen …')) ?>"
       data-utility-label-error="<?= $escape($t('Inhalt konnte nicht geladen werden.')) ?>"
       data-utility-label-fallback="<?= $escape($t('Als Seite öffnen')) ?>"
       data-utility-label-warning="<?= $escape($t('Systemstatus: Warnung bei letzter Prüfung')) ?>">
    <a class="studio-utility-link studio-settings-link<?= $activeWorkspace === 'settings' ? ' studio-settings-link-active' : '' ?>" href="/settings" title="<?= $escape($t('Einstellungen')) ?>" aria-label="<?= $escape($t('Einstellungen')) ?>"<?= $activeWorkspace === 'settings' ? ' aria-current="page"' : '' ?>><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m9 3-1 3-3 1-2 3 2 2v3l3 1 1 3h4l1-3 3-1v-3l2-2-2-3-3-1-1-3H9Z" transform="translate(1 1)" stroke-linejoin="round"/><circle cx="12" cy="12" r="3"/></svg></a>
    <a class="studio-utility-link" href="/status" data-utility-open="status" title="<?= $escape($t('Systemstatus')) ?>" aria-label="<?= $escape($t('Systemstatus')) ?>" aria-haspopup="dialog"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg><span class="studio-utility-warning" data-utility-warning-dot hidden aria-hidden="true"></span></a>
    <a class="studio-utility-link" href="/about" data-utility-open="about" title="<?= $escape($t('Über LibreBugBounty')) ?>" aria-label="<?= $escape($t('Über LibreBugBounty')) ?>" aria-haspopup="dialog"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/></svg></a>
  </div>
</nav>
<link rel="stylesheet" href="/css/studio-utilities.css">
<script src="/js/studio-utilities.js" defer></script>
