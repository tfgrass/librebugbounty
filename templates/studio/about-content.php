<?php
/** @var array<string, string> $app */
/** @var string $utilityTitle */
?>
<section class="studio-settings-panel studio-about-panel" id="about" aria-labelledby="about-title" data-utility-content="about" data-utility-title="<?= $escape($utilityTitle) ?>">
  <div class="studio-about-hero" data-about-release>
    <?php $logoSize = 72; $logoClass = 'studio-about-logo'; require __DIR__.'/logo.php'; unset($logoSize, $logoClass); ?>
    <h2 id="about-title"><?= $escape($app['name']) ?></h2>
    <p class="studio-about-version">v<?= $escape($app['version']) ?> <span aria-hidden="true">·</span> <?= $escape($app['releaseName']) ?></p>
  </div>
  <p class="studio-about-lead"><?= $escape($t('Eine lokale Arbeitsumgebung für Bug-Bounty-Ingest, technische Prüfung, Bildbelege und manuelle Entscheidungen.')) ?></p>
  <section class="studio-about-release-notes" data-release-notes aria-labelledby="release-notes-title">
    <h3 id="release-notes-title"><?= $escape($t('Neu in {version}', ['version' => $app['version']])) ?></h3>
    <p class="studio-about-lead"><?= $escape($t('Review im klassischen Layout oder im optionalen Fokusmodus mit Zoom, Bildnavigation und Tastatursteuerung.')) ?></p>
  </section>
  <section class="studio-settings-docs" aria-labelledby="settings-docs-title" data-documentation-links>
    <h3 id="settings-docs-title"><?= $escape($t('Dokumentation')) ?></h3>
    <nav aria-label="<?= $escape($t('Dokumentation')) ?>">
      <a href="/docs/usage"><?= $escape($t('Benutzerhandbuch')) ?> <span aria-hidden="true">→</span></a>
      <a href="/docs/readme"><?= $escape($t('Installation & Upgrade')) ?> <span aria-hidden="true">→</span></a>
      <a href="/docs/backup"><?= $escape($t('Sicherung & Wiederherstellung')) ?> <span aria-hidden="true">→</span></a>
      <a href="/docs/changelog">Changelog <span aria-hidden="true">→</span></a>
      <a href="/docs/roadmap">Roadmap <span aria-hidden="true">→</span></a>
    </nav>
  </section>
  <dl class="studio-about-facts">
    <div><dt><?= $escape($t('Autor')) ?></dt><dd><a href="<?= $escape($app['homepage']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($app['author']) ?> <span aria-hidden="true">↗</span></a></dd></div>
    <div><dt><?= $escape($t('Quellcode')) ?></dt><dd><a href="<?= $escape($app['repository']) ?>" target="_blank" rel="noopener noreferrer">GitHub <span aria-hidden="true">↗</span></a></dd></div>
    <div><dt><?= $escape($t('Lizenz')) ?></dt><dd><code><?= $escape($app['license']) ?></code></dd></div>
  </dl>
  <details class="studio-about-profiles">
    <summary><?= $escape($t('Weitere Profile')) ?></summary>
    <dl class="studio-about-facts">
      <div><dt>Flickr</dt><dd><a href="<?= $escape($app['flickrUrl']) ?>" target="_blank" rel="noopener noreferrer">tkoschka <span aria-hidden="true">↗</span></a></dd></div>
      <div><dt>OpenBugBounty</dt><dd><a href="<?= $escape($app['profileUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($app['profile']) ?> <span aria-hidden="true">↗</span></a></dd></div>
    </dl>
  </details>
  <section class="studio-about-support" id="support" aria-labelledby="support-title">
    <h3 id="support-title"><?= $escape($t('LibreBugBounty unterstützen')) ?></h3>
    <a href="<?= $escape($app['donationUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($t('Über PayPal unterstützen')) ?> <span aria-hidden="true">↗</span></a>
  </section>
  <p class="studio-about-vibe">Proudly vibe-coded.</p>
</section>
