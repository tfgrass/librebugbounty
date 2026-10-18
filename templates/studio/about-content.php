<?php
/** @var array<string, string> $app */
/** @var string $utilityTitle */
?>
<section class="studio-settings-panel studio-about-panel" id="about" aria-labelledby="about-title" data-utility-content="about" data-utility-title="<?= $escape($utilityTitle) ?>">
  <header class="studio-about-identity" data-about-release>
    <?php $logoSize = 56; $logoClass = 'studio-about-logo'; require __DIR__.'/logo.php'; unset($logoSize, $logoClass); ?>
    <div>
      <h2 id="about-title"><?= $escape($app['name']) ?></h2>
      <p class="studio-about-lead"><?= $escape($t('Eine lokale Arbeitsumgebung für Bug-Bounty-Ingest, technische Prüfung, Bildbelege und manuelle Entscheidungen.')) ?></p>
    </div>
    <p class="studio-about-version">v<?= $escape($app['version']) ?><span><?= $escape($app['releaseName']) ?></span></p>
  </header>

  <div class="studio-about-columns">
    <div class="studio-about-main">
      <section class="studio-about-release-notes" data-release-notes aria-labelledby="release-notes-title">
        <h3 id="release-notes-title"><?= $escape($t('Neu in {version}', ['version' => $app['version']])) ?></h3>
        <ul>
          <li><strong><?= $escape($t('Review nach deinem Arbeitsstil')) ?></strong><p><?= $escape($t('Klassische Ansicht oder bildschirmfüllender Fokusmodus mit erreichbaren Bedienelementen und Tastatursteuerung.')) ?></p></li>
          <li><strong><?= $escape($t('Bildbelege genauer prüfen')) ?></strong><p><?= $escape($t('Zoom, Originalgröße, Maus- und Touch-Steuerung helfen beim Prüfen. Deine Bewertung bleibt eine ausdrückliche Entscheidung.')) ?></p></li>
          <li><strong><?= $escape($t('Flüssiger durch die Warteschlange')) ?></strong><p><?= $escape($t('Vorab geladene Bilder und weiche Übergänge verkürzen das Warten beim Wechsel zwischen Fällen.')) ?></p></li>
        </ul>
      </section>

      <section class="studio-about-history" data-release-history aria-labelledby="release-history-title">
        <h3 id="release-history-title"><?= $escape($t('Versionsgeschichte')) ?></h3>
        <div class="studio-about-history-entries">
          <article data-release-entry>
            <h4>2.0.0 <span>Moneta</span></h4>
            <p><?= $escape($t('Aus dem Prototyp wird ein vollständiger lokaler Arbeitsbereich: schneller Eingang, bildbasierter Review, Aktivitätsstatistik und konfigurierbare Exporte.')) ?></p>
          </article>
          <article data-release-entry>
            <h4>1.1.0 <span>Scriptor Quo</span></h4>
            <p><?= $escape($t('Aus der täglichen Nutzung entstanden bessere Prüf- und Screenshot-Abläufe, Kontaktmarkierungen und Domain-Exporte. Schrittweise Verfeinerungen des bestehenden Workflows.')) ?></p>
          </article>
          <article data-release-entry>
            <h4>1.0.0 <span>Scriptor</span></h4>
            <p><?= $escape($t('Die Idee eines lokalen OpenBugBounty-Workflows wurde zum ersten nutzbaren Prototyp: URL-Erfassung, Browserprüfung und Bildbelege als Grundlage für die manuelle Sichtung.')) ?></p>
          </article>
        </div>
      </section>
    </div>

    <aside class="studio-about-sidebar" aria-labelledby="about-project-title">
      <section class="studio-about-project">
        <h3 id="about-project-title"><?= $escape($t('Projekt & Profile')) ?></h3>
        <dl class="studio-about-facts">
          <div><dt><?= $escape($t('Autor')) ?></dt><dd><a href="<?= $escape($app['homepage']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($app['author']) ?> <span aria-hidden="true">↗</span></a></dd></div>
          <div><dt>OpenBugBounty</dt><dd><a href="<?= $escape($app['profileUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($app['profile']) ?> <span aria-hidden="true">↗</span></a></dd></div>
          <div><dt>Flickr</dt><dd><a href="<?= $escape($app['flickrUrl']) ?>" target="_blank" rel="noopener noreferrer">tkoschka <span aria-hidden="true">↗</span></a></dd></div>
          <div><dt><?= $escape($t('Quellcode')) ?></dt><dd><a href="<?= $escape($app['repository']) ?>" target="_blank" rel="noopener noreferrer">GitHub <span aria-hidden="true">↗</span></a></dd></div>
          <div><dt><?= $escape($t('Lizenz')) ?></dt><dd><code><?= $escape($app['license']) ?></code></dd></div>
        </dl>
      </section>

      <section class="studio-settings-docs" aria-labelledby="settings-docs-title" data-documentation-links>
        <h3 id="settings-docs-title"><?= $escape($t('Dokumentation')) ?></h3>
        <nav aria-label="<?= $escape($t('Dokumentation')) ?>">
          <a href="/docs/usage"><?= $escape($t('Benutzerhandbuch')) ?> <span aria-hidden="true">→</span></a>
          <a href="/docs/readme"><?= $escape($t('Installation & Upgrade')) ?> <span aria-hidden="true">→</span></a>
          <a href="/docs/backup"><?= $escape($t('Sicherung & Wiederherstellung')) ?> <span aria-hidden="true">→</span></a>
          <a href="/docs/changelog"><?= $escape($t('Vollständigen Changelog lesen')) ?> <span aria-hidden="true">→</span></a>
          <a href="/docs/roadmap">Roadmap <span aria-hidden="true">→</span></a>
        </nav>
      </section>

      <section class="studio-about-support" id="support" aria-labelledby="support-title">
        <h3 id="support-title"><?= $escape($t('LibreBugBounty unterstützen')) ?></h3>
        <p><?= $escape($t('Wenn dir LibreBugBounty hilft, kannst du die Weiterentwicklung freiwillig unterstützen.')) ?></p>
        <a href="<?= $escape($app['donationUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($t('Über PayPal unterstützen')) ?> <span aria-hidden="true">↗</span></a>
      </section>
    </aside>
  </div>
  <p class="studio-about-vibe">Proudly vibe-coded.</p>
</section>
