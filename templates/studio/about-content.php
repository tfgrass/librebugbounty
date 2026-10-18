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

  <section class="studio-about-updates" data-release-update="<?= $escape($releaseUpdate['state']) ?>" aria-labelledby="about-update-title">
    <div class="studio-about-update-heading">
      <h3 id="about-update-title"><?= $escape($t('Updates')) ?></h3>
      <form method="post" action="/about/check-update" data-release-check-form>
        <input type="hidden" name="_token" value="<?= $escape($updateCsrfToken) ?>">
        <button type="submit"><?= $escape($t('Jetzt prüfen')) ?></button>
      </form>
    </div>
    <?php if ($releaseUpdate['state'] === 'error'): ?>
      <p><?= $escape($t('Die letzte Updateprüfung ist fehlgeschlagen. Versuche es später erneut.')) ?></p>
    <?php elseif ($releaseUpdate['state'] === 'unknown'): ?>
      <p><?= $escape($t('Noch nicht auf neue Releases geprüft.')) ?></p>
    <?php elseif ($releaseUpdate['state'] === 'stale'): ?>
      <p><?= $escape($t('Der gespeicherte Releasestand ist veraltet. Eine neue Prüfung steht aus.')) ?></p>
    <?php elseif ($releaseUpdate['state'] === 'current'): ?>
      <p><?= $escape($t('Du verwendest den aktuellen stabilen Release.')) ?></p>
    <?php elseif ($releaseUpdate['state'] === 'ahead'): ?>
      <p><?= $escape($t('Deine Version ist neuer als der zuletzt veröffentlichte stabile Release.')) ?></p>
    <?php endif; ?>
    <?php if ($releaseUpdate['available']): ?>
      <p class="studio-about-update-available"><a href="<?= $escape($releaseUpdate['releaseUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($t('Version {version} ist verfügbar', ['version' => $releaseUpdate['latestVersion']])) ?> <span aria-hidden="true">↗</span></a></p>
      <details class="studio-about-update-instructions">
        <summary><?= $escape($t('Update durchführen')) ?></summary>
        <p><?= $escape($t('Sichere deine Daten und lokalen Änderungen. Die Upgrade-Anleitung beschreibt Backup, Abhängigkeiten, Migrationen und Neustart.')) ?> <a href="/docs/readme#upgrade-an-existing-workspace"><?= $escape($t('Upgrade-Anleitung öffnen')) ?> →</a></p>
        <p><?= $escape($t('Für eine Git-Installation den veröffentlichten Tag auswählen:')) ?></p>
        <pre><code><?= $escape("git status --short\ngit fetch --tags origin\ngit switch --detach refs/tags/".$releaseUpdate['latestTag']) ?></code></pre>
      </details>
    <?php endif; ?>
    <p class="studio-about-update-meta"><?= $escape($t('Automatische Prüfung einmal täglich. Nur stabile GitHub-Releases werden berücksichtigt.')) ?><?php if ($releaseUpdate['checkedAt'] !== null): ?> <?= $escape($t('Releasestand vom {time}', ['time' => $formatTime($releaseUpdate['checkedAt'])])) ?><?php endif; ?></p>
  </section>

  <div class="studio-about-columns">
    <div class="studio-about-main">
      <section class="studio-about-release-notes" data-release-notes aria-labelledby="release-notes-title">
        <h3 id="release-notes-title"><?= $escape($t('Neu in {version}', ['version' => $app['version']])) ?></h3>
        <ul>
          <li><strong><?= $escape($t('Dialog-Recovery und PoC-Übernahme')) ?></strong><p><?= $escape($t('Erreiche den Alert im interaktiven Browser, wiederhole fehlgeschlagene Aufnahmen und übernimm eine geänderte URL für PoC und Rechecks. Original und Bildquelle bleiben nachvollziehbar.')) ?></p></li>
          <li><strong><?= $escape($t('Review gezielt zurückstellen')) ?></strong><p><?= $escape($t('Parke vorübergehend nicht prüfbare Fälle mit Grund und Termin. Zum Termin wird einmal getestet; ein erneuter Fehler lässt den Fall zurückgestellt.')) ?></p></li>
          <li><strong><?= $escape($t('Sitzungsfortschritt und Arbeitskorb')) ?></strong><p><?= $escape($t('Behalte Erfassungstempo und letzte Einträge im Blick, klappe Statistiken bei Bedarf ein und arbeite offene Wiedervorlagen nach Termin ab. Hilfe und Updatehinweise stehen direkt bereit.')) ?></p></li>
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
