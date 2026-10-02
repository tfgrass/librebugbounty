<?php

use App\Value\FindingReadLabels;
use App\Value\FindingSeverity;
use App\Value\FindingStatus;
use App\Value\RetestResult;

/** @var list<\App\Dto\FindingReadView> $findings */
/** @var \App\Dto\FindingReadFilter $filter */
?>
<section class="panel" data-intake data-intake-view="classic">
  <?php if ($message !== null): ?><div class="notice success"><?= $escape($message) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="notice error"><?= $escape($error) ?></div><?php endif; ?>
  <h2>Bestand</h2>
  <p class="hint">Globale Fallzahlen. Ein Klick setzt die Listenfilter zurück und öffnet genau die gezählte Menge. Manuelle Bewertung, technische Beobachtung und Kontakt sind unabhängige Merkmale.</p>
  <div class="stats">
    <?php foreach ($stats as $key => $stat): ?>
      <a class="stat stat-link" data-stat="<?= $escape($key) ?>" data-count="<?= $escape($stat['count']) ?>" href="<?= $escape($stat['url']) ?>"><span><?= $escape($stat['label']) ?></span><strong><?= $escape($stat['count']) ?></strong></a>
    <?php endforeach; ?>
  </div>
  <p class="hint">Screenshot-Aufträge im aktiven Bestand: <?= $escape($screenshotStats['queued']) ?> queued · <?= $escape($screenshotStats['running']) ?> running · <?= $escape($screenshotStats['failed']) ?> failed.</p>
  <div class="section-head"><div><h2>Intake</h2></div></div>
  <form method="post" action="/findings" id="intake-form">
    <?= $this->csrfField('finding_create') ?>
    <label>URL <input name="url" type="url" inputmode="url" autocomplete="url" autocapitalize="off" spellcheck="false" placeholder="https://example.com/" required autofocus></label>
    <label>Kennzeichen <input name="payload" placeholder="<?= $escape($defaultPayload) ?>" value="<?= $escape($defaultPayload) ?>"></label>
    <p class="hint">Kennzeichen und Notizen für deine Belege. Der Fall wird gespeichert; sein Screenshot entsteht separat. Eine technische Beobachtung wird angezeigt, sobald eine gespeicherte Beobachtung vorhanden ist.</p>
    <label>Notiz <textarea name="annotate" placeholder="Optionale Notiz"></textarea></label>
    <button type="submit">Speichern</button>
  </form>
  <p id="intake-notice" class="hint" role="status" aria-live="polite"></p>
  <section id="intake-history" hidden>
    <h3>Eingaben dieser Sitzung</h3>
    <p class="hint">Die letzten 50 Eingaben bleiben in diesem Tab beim Neuladen erhalten. Fall und Ergebnisstände werden serverseitig gespeichert. Fehlgeschlagene Eingaben kannst du bewusst wieder ins Formular übernehmen.</p>
    <p id="intake-storage-notice" class="hint" role="status"></p>
    <p id="intake-status-notice" class="hint" role="status"></p>
    <div id="intake-history-list" class="detail-list"></div>
  </section>
  <div id="intake-toasts" role="status" aria-live="polite" style="position:fixed;right:16px;bottom:16px;width:min(420px,calc(100vw - 32px));z-index:60;pointer-events:none"></div>
  <script src="/js/intake.js" defer></script>
</section>

<section class="panel wide" id="findings">
  <div class="section-head"><div><h2>Findings</h2><p class="hint">Aktiver Bestand im normalen Arbeiten; verworfene Fälle sind im Archiv ausdrücklich auffindbar.</p></div></div>
  <p class="hint">Filter gelten gemeinsam. Für verworfene Bewertungen wähle eine Archivansicht.</p>
  <?php if ($filter->legacyStatus !== '' || $filter->legacyBucket !== ''): ?>
    <p class="notice success" data-legacy-filter>Diagnosefilter aktiv:
      <?php if ($filter->legacyStatus !== ''): ?>Altstatusfilter <code><?= $escape($filter->legacyStatus) ?></code>.<?php endif; ?>
      <?php if ($filter->legacyBucket !== ''): ?>Altgruppenfilter <code><?= $escape($filter->legacyBucket) ?></code>.<?php endif; ?>
      Diese Filter verwenden die gespeicherten Statusfelder mit unklarer historischer Herkunft.
    </p>
  <?php endif; ?>
  <form method="get" action="/#findings" class="filters" id="finding-filters">
    <div class="split">
      <label>Domain <input name="domain" value="<?= $escape($filter->domain) ?>" placeholder="example.com"></label>
      <label>Bestand / Archiv
        <select name="scope">
          <?php foreach (['active' => 'Aktiver Bestand', 'discarded' => 'Archiv: Verworfen', 'duplicates' => 'Archiv: Duplikate', 'all' => 'Gesamter Bestand einschließlich Archiv'] as $value => $label): ?>
            <option value="<?= $escape($value) ?>"<?= $filter->scope === $value ? ' selected' : '' ?>><?= $escape($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Manuelle Bewertung
        <select name="assessment">
          <?php foreach (['' => 'Alle Bewertungen', 'confirmed' => 'Befund bestätigt', 'fixed' => 'Behoben', 'discarded' => 'Verworfen', 'unknown' => 'Keine aufgezeichnete manuelle Bewertung'] as $value => $label): ?>
            <option value="<?= $escape($value) ?>"<?= $filter->assessment === $value ? ' selected' : '' ?>><?= $escape($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Letzte technische Beobachtung
        <select name="observation">
          <option value=""<?= $filter->observation === '' ? ' selected' : '' ?>>Alle Beobachtungen</option>
          <?php foreach ([...RetestResult::values(), 'none'] as $value): ?>
            <option value="<?= $escape($value) ?>"<?= $filter->observation === $value ? ' selected' : '' ?>><?= $escape(FindingReadLabels::observation($value === 'none' ? null : $value)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Kontakt
        <select name="contact">
          <?php foreach (['' => 'Alle Kontaktstände', 'yes' => 'Kontaktiert', 'no' => 'Nicht kontaktiert'] as $value => $label): ?>
            <option value="<?= $escape($value) ?>"<?= $filter->contact === $value ? ' selected' : '' ?>><?= $escape($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <details<?= $filter->legacyStatus !== '' || $filter->legacyBucket !== '' || $filter->type !== '' || $filter->severity !== '' || $filter->exactDomain ? ' open' : '' ?>>
      <summary>Zusätzliche Filter und Altwerte zur Diagnose</summary>
      <p class="hint">Altstatus und Altgruppe sind gespeicherte Kompatibilitätswerte. Ihre historische Herkunft bleibt unklar.</p>
      <div class="split">
        <label>Altstatusfilter
          <select name="legacy_status">
            <?php foreach (['', ...FindingStatus::values()] as $value): ?>
              <option value="<?= $escape($value) ?>"<?= $filter->legacyStatus === $value ? ' selected' : '' ?>><?= $escape($value === '' ? 'Alle Altstatuswerte' : $value) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Altgruppenfilter
          <select name="legacy_bucket">
            <?php foreach (['', 'open', 'fixed', 'manual_review', 'unchecked'] as $value): ?>
              <option value="<?= $escape($value) ?>"<?= $filter->legacyBucket === $value ? ' selected' : '' ?>><?= $escape($value === '' ? 'Alle Altgruppen' : $value) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Typ <input name="type" value="<?= $escape($filter->type) ?>"></label>
        <label>Schweregrad
          <select name="severity">
            <?php foreach (['', ...FindingSeverity::values()] as $value): ?>
              <option value="<?= $escape($value) ?>"<?= $filter->severity === $value ? ' selected' : '' ?>><?= $escape($value === '' ? 'Alle Schweregrade' : $value) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Domainvergleich
          <select name="exact_domain"><option value="0"<?= !$filter->exactDomain ? ' selected' : '' ?>>Domain enthält Suchtext</option><option value="1"<?= $filter->exactDomain ? ' selected' : '' ?>>Domain entspricht Suchtext exakt</option></select>
        </label>
      </div>
    </details>
    <input type="hidden" name="pageSize" value="<?= $escape($pagination['pageSize']) ?>">
    <div class="filter-actions"><button type="submit">Filtern</button><a class="button ghost" href="/">Zurücksetzen</a></div>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID / Domain</th><th>Manuelle Bewertung</th><th>Letzte technische Beobachtung</th><th>Kontakt</th><th>Altwerte (Diagnose)</th><th>Eingang</th></tr></thead>
      <tbody>
        <?php foreach ($findings as $finding): ?>
          <tr data-finding-id="<?= $escape($finding->id) ?>">
            <td><a class="row-link" href="/findings/<?= $escape($finding->id) ?>"><code><?= $escape(substr($finding->id, 0, 8)) ?></code></a><br>
              <a class="row-link" href="/findings/<?= $escape($finding->id) ?>"><code><?= $escape($finding->domain) ?></code></a><br><span class="hint"><?= $escape($finding->title) ?><br><?= $escape($finding->type) ?> · <?= $escape($finding->severity) ?></span>
            </td>
            <td data-dimension="assessment"><?= $escape(FindingReadLabels::assessment($finding->assessment, $finding->discardReason)) ?>
              <?php if ($finding->assessment !== null): ?><br><span class="hint">Manuell · <?= $escape($finding->assessedAt?->format(DATE_ATOM) ?? 'Zeitpunkt unbekannt') ?></span><?php endif; ?>
              <?php if ($finding->discarded && $finding->assessment === null): ?><br><span class="hint">Im Archiv · Altkennzeichnung</span><?php endif; ?>
            </td>
            <td data-dimension="observation"><?= $escape(FindingReadLabels::observation($finding->observationResult)) ?>
              <?php if ($finding->observationId !== null): ?><br><span class="hint"><?= $escape($finding->observationAt?->format(DATE_ATOM) ?? 'Zeitpunkt unbekannt') ?> · <?= $escape($finding->observationMode ?? 'Herkunft unbekannt') ?></span><?php endif; ?>
            </td>
            <td data-dimension="contact"><?= $escape(FindingReadLabels::contact($finding->contactedAt)) ?>
              <?php if ($finding->contactedAt !== null): ?><br><span class="hint"><?= $escape($finding->contactedAt->format(DATE_ATOM)) ?></span><?php endif; ?>
            </td>
            <td data-dimension="legacy"><code><?= $escape($finding->legacyStatus) ?></code><br><code><?= $escape($finding->legacyReviewState ?? 'Kein Review-Wert') ?></code><br><span class="hint"><?= $finding->assessment === null ? 'Historische Herkunft unklar' : 'Kompatibilitätswerte' ?></span></td>
            <td><?= $escape(($finding->submittedAt ?? $finding->createdAt)->format(DATE_ATOM)) ?><?= $finding->submittedAt === null ? '<br><span class="hint">Ablagezeit; Eingangszeit unbekannt</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($findings === []): ?><tr><td colspan="6" class="hint">Keine Fälle für diese Filter.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="pagination-footer">
    <div class="pagination-summary">
      <span data-total-filtered="<?= $escape($pagination['totalFiltered']) ?>">Ergebnisse: <?= $escape($pagination['totalFiltered']) ?> Fälle</span>
      <form method="get" action="/#findings" class="per-page-form" id="page-size-form">
        <?php foreach ($filterQuery as $name => $value): ?><input type="hidden" name="<?= $escape($name) ?>" value="<?= $escape($value) ?>"><?php endforeach; ?>
        <input type="hidden" name="page" value="1">
        <label class="sr-only" for="page-size-select">Zeilen pro Seite</label>
        <select id="page-size-select" name="pageSize" onchange="this.form.submit()">
          <?php foreach (['10', '25', '50', '100', 'all'] as $value): ?><option value="<?= $escape($value) ?>"<?= $pagination['pageSize'] === $value ? ' selected' : '' ?>><?= $escape($value === 'all' ? 'Alle' : $value) ?></option><?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php if ($pagination['pageSize'] !== 'all' && $pagination['totalPages'] > 1): ?>
      <nav class="pagination" aria-label="Ergebnisseiten"><span>Seite <?= $escape($pagination['page']) ?> von <?= $escape($pagination['totalPages']) ?></span><div class="pagination-actions">
        <?php if ($pagination['page'] > 1): ?><a data-page="previous" href="<?= $escape($pageUrl($pagination['page'] - 1)) ?>">Zurück</a><?php endif; ?>
        <?php if ($pagination['page'] < $pagination['totalPages']): ?><a data-page="next" href="<?= $escape($pageUrl($pagination['page'] + 1)) ?>">Weiter</a><?php endif; ?>
      </div></nav>
    <?php endif; ?>
  </div>
</section>
