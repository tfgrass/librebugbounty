<?php
/** @var array<string, mixed> $health */
/** @var string $utilityTitle */
/** @var bool $utilityWarning */
?>
<section class="studio-settings-panel studio-settings-health" id="health" aria-labelledby="settings-health-title" data-utility-content="status" data-utility-title="<?= $escape($utilityTitle) ?>" data-utility-warning="<?= $utilityWarning ? 'true' : 'false' ?>">
  <div class="studio-settings-panel-heading">
    <div><h2 id="settings-health-title"><?= $escape($t('Status & Gesundheit')) ?></h2></div>
    <a class="studio-health-refresh" href="/status"><?= $escape($t('Status aktualisieren')) ?></a>
  </div>
  <p class="studio-settings-field-hint studio-health-checked"><?= $escape($t('Stand: {date}', ['date' => $formatTime($health['checkedAt'], true)])) ?></p>
  <div class="studio-settings-health-grid">
    <?php foreach (['recheck' => 'Recheck-Worker', 'screenshot' => 'Screenshot-Worker'] as $kind => $label): $group = $health[$kind]; ?>
    <article class="studio-settings-health-card" data-health-kind="<?= $escape($kind) ?>" data-health-state="<?= $escape($group['state']) ?>">
      <header>
        <span class="studio-settings-health-dot" data-state="<?= $escape($group['state']) ?>" aria-hidden="true"></span>
        <h3><?= $escape($t($label)) ?></h3>
      </header>
      <p class="studio-settings-health-state" data-health-summary><?= $escape($t('{active}/{expected} Worker aktiv', ['active' => $group['activeWorkers'], 'expected' => $group['expectedWorkers']])) ?></p>
      <p class="studio-settings-field-hint" data-health-browser-summary><?= $escape($t('{active}/{expected} Browserdienste erreichbar', ['active' => $group['reachableBrowsers'], 'expected' => $group['expectedBrowsers']])) ?></p>
      <dl class="studio-health-metrics">
          <?php if ($kind === 'recheck'): ?>
          <div><dt><?= $escape($t('Fällig jetzt')) ?></dt><dd><?= $escape($formatNumber($group['dueNow'])) ?></dd></div>
          <div><dt><?= $escape($t('Geplant')) ?></dt><dd><?= $escape($formatNumber($group['scheduled'])) ?></dd></div>
          <div><dt><?= $escape($t('Pausiert für Review')) ?></dt><dd><?= $escape($formatNumber($group['pausedManual'])) ?></dd></div>
          <div><dt><?= $escape($t('Nächster Termin')) ?></dt><dd><?= $group['nextDueAt'] === null ? $escape($t('Kein Termin')) : $escape($formatTime($group['nextDueAt'])) ?></dd></div>
          <div><dt><?= $escape($t('Intervall')) ?></dt><dd><?= $escape($t('{days} Tage', ['days' => $group['intervalDays']])) ?></dd></div>
          <div><dt><?= $escape($t('Fälle mit technischem Fehler')) ?></dt><dd><a href="/errors?kind=technical" data-health-errors="technical"><?= $escape($formatNumber($group['errorCases'])) ?></a></dd></div>
          <?php else: ?>
          <div><dt><?= $escape($t('Wartend')) ?></dt><dd><?= $escape($formatNumber($group['queued'])) ?></dd></div>
          <div><dt><?= $escape($t('In Arbeit')) ?></dt><dd><?= $escape($formatNumber($group['running'])) ?></dd></div>
          <div><dt><?= $escape($t('Abgeschlossene Aufnahmen')) ?></dt><dd><?= $escape($formatNumber($group['available'])) ?></dd></div>
          <div><dt><?= $escape($t('Fehlgeschlagene Aufträge (Historie)')) ?></dt><dd><?= $escape($formatNumber($group['failed'])) ?></dd></div>
          <div><dt><?= $escape($t('Fälle mit Aufnahmefehler')) ?></dt><dd><a href="/errors?kind=screenshot" data-health-errors="screenshot"><?= $escape($formatNumber($group['errorCases'])) ?></a></dd></div>
          <?php endif; ?>
      </dl>
      <details class="studio-health-details">
        <summary><?= $escape($t('Technische Details')) ?></summary>
        <ul class="studio-health-workers" aria-label="<?= $escape($t($label)) ?>">
          <?php foreach ($group['workers'] as $worker): ?>
          <li data-health-worker="<?= $escape($worker['id']) ?>" data-worker-state="<?= $escape($worker['state']) ?>" data-browser-state="<?= $worker['browserReachable'] ? 'ok' : 'unavailable' ?>">
            <div><strong><?= $escape($worker['id']) ?></strong><span class="studio-health-worker-state"><?= $escape($t(['ok' => 'Signal aktuell', 'stale' => 'Signal veraltet', 'unknown' => 'Noch kein Signal'][$worker['state']])) ?></span></div>
            <span class="studio-health-worker-seen"><?= $worker['lastSeen'] === null ? $escape($t('Zeitpunkt unbekannt')) : $escape($t('Letztes Signal: {date}', ['date' => $formatTime($worker['lastSeen'], true)])) ?></span>
            <span class="studio-health-browser" data-state="<?= $worker['browserReachable'] ? 'ok' : 'unavailable' ?>"><?= $escape($t($worker['browserReachable'] ? 'Browserdienst erreichbar' : 'Browserdienst nicht erreichbar')) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </details>
    </article>
    <?php endforeach; ?>
  </div>
  <p class="studio-settings-field-hint studio-health-checked"><?= $escape($t('Grün bedeutet: alle erwarteten Worker senden aktuelle Signale und ihre Browserdienste antworten. Die Dienstprüfung startet keine Browseraufnahme.')) ?></p>
  <p class="studio-settings-field-hint"><?= $escape($t('Signale gelten für Rechecks 10 Minuten und für Screenshots 5 Minuten. Auftragszahlen enthalten die Historie; abgeschlossene Aufnahmen bestätigen nicht die Lesbarkeit der Bilddateien.')) ?></p>
  <p class="studio-settings-field-hint"><a href="/errors"><?= $escape($t('Fehlerübersicht öffnen')) ?></a> · <a href="/errors?kind=missing" data-health-errors="missing"><?= $escape($t('Gespeicherte Bilddateien prüfen')) ?></a></p>
</section>
