<?php
/** Inventory-only preferences. All server-side actions are explicit POSTs. */
$json = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$viewReturn = $path.'?'.http_build_query($listQuery, '', '&', PHP_QUERY_RFC3986);
?>
<details class="studio-inventory-views" id="ansichten" data-inventory-views<?= $message !== null || $error !== null ? ' open' : '' ?>>
  <summary><?= $escape($t('Eigene Ansichten')) ?> <span data-saved-view-count><?= $escape($formatNumber(count($savedViews))) ?></span><span class="studio-view-recent-count" data-recent-count hidden></span></summary>
  <div class="studio-view-columns">
    <section aria-labelledby="saved-views-heading">
      <h2 id="saved-views-heading"><?= $escape($t('Gespeicherte Ansichten')) ?></h2>
      <p class="studio-list-hint"><?= $escape($t('Gespeicherte Filter zeigen immer den aktuellen Bestand. Änderungen an den Filtern überschreiben keine Ansicht.')) ?></p>
      <?php if ($savedViews === []): ?><p class="studio-list-hint" data-no-saved-views><?= $escape($t('Noch keine gespeicherten Ansichten.')) ?></p><?php endif; ?>
      <ul class="studio-view-list" data-saved-views>
        <?php foreach ($savedViews as $saved): ?>
          <li data-saved-view="<?= $escape($saved['id']) ?>">
            <?php if ($saved['url'] !== null): ?>
              <a class="studio-view-link" data-view-open href="<?= $escape($saved['url']) ?>"<?= $saved['query'] === $viewQuery ? ' aria-current="true"' : '' ?>><?= $escape($saved['name']) ?></a>
            <?php else: ?><strong><?= $escape($saved['name']) ?></strong><?php endif; ?>
            <p class="studio-view-description"><?= $escape($saved['description']) ?></p>
            <details class="studio-view-manage"><summary><?= $escape($t('Ansicht verwalten')) ?></summary>
              <?php if ($saved['url'] !== null): ?>
                <form method="post" action="/inventory-views/<?= $escape($saved['id']) ?>/rename" data-view-rename>
                  <?= $csrfField('inventory_view_rename_'.$saved['id']) ?>
                  <input type="hidden" name="return_to" value="<?= $escape($viewReturn) ?>">
                  <label><?= $escape($t('Name der Ansicht')) ?><input name="name" value="<?= $escape($saved['name']) ?>" maxlength="80" required autocomplete="off"></label>
                  <button class="studio-list-button" type="submit"><?= $escape($t('Umbenennen')) ?></button>
                </form>
              <?php endif; ?>
              <form method="post" action="/inventory-views/<?= $escape($saved['id']) ?>/delete" data-view-delete>
                <?= $csrfField('inventory_view_delete_'.$saved['id']) ?>
                <input type="hidden" name="return_to" value="<?= $escape($viewReturn) ?>">
                <button class="studio-list-button" type="submit"><?= $escape($t('Ansicht löschen')) ?></button>
                <span class="studio-list-hint"><?= $escape($t('Löscht nur diese Ansicht, keine Fälle.')) ?></span>
              </form>
            </details>
          </li>
        <?php endforeach; ?>
      </ul>
      <details class="studio-view-save" data-current-view-save>
        <summary><?= $escape($t('Aktuelle Filter als Ansicht speichern')) ?></summary>
        <p class="studio-view-description"><?= $escape($viewDescription) ?></p>
        <form method="post" action="/inventory-views" data-view-create>
          <?= $csrfField('inventory_view_create') ?>
          <input type="hidden" name="return_to" value="<?= $escape($viewReturn) ?>">
          <input type="hidden" name="filters" value="<?= $escape($json($viewQuery)) ?>">
          <label><?= $escape($t('Name der Ansicht')) ?><input name="name" maxlength="80" required autocomplete="off" placeholder="<?= $escape($t('Zum Beispiel: Noch nicht kontaktiert')) ?>"></label>
          <button class="studio-list-button studio-list-button-primary" type="submit"><?= $escape($t('Ansicht speichern')) ?></button>
        </form>
        <p class="studio-list-hint"><?= $escape($t('Gespeichert werden die angewendeten Filter, ohne Seitenzahl und Zeilen pro Seite.')) ?></p>
      </details>
    </section>
    <section aria-labelledby="recent-views-heading" data-recent-views hidden>
      <h2 id="recent-views-heading"><?= $escape($t('Zuletzt verwendete Ansichten')) ?></h2>
      <p class="studio-list-hint"><?= $escape($t('Die letzten zehn Filterkombinationen, nur in diesem Browser. Suchtexte können vertrauliche Angaben enthalten; du kannst den Verlauf jederzeit löschen.')) ?></p>
      <p class="studio-list-hint" data-recent-unavailable role="status" hidden><?= $escape($t('Der lokale Verlauf ist nicht verfügbar. Gespeicherte Ansichten funktionieren weiterhin.')) ?></p>
      <p class="studio-list-hint" data-recent-empty><?= $escape($t('Noch keine zuletzt verwendeten Ansichten.')) ?></p>
      <ol class="studio-view-list" data-recent-list></ol>
      <button class="studio-list-button" type="button" data-recent-clear hidden><?= $escape($t('Verlauf löschen')) ?></button>
    </section>
    <noscript><p class="studio-list-hint"><?= $escape($t('Zuletzt verwendete Ansichten benötigen JavaScript. Gespeicherte Ansichten funktionieren auch ohne JavaScript.')) ?></p></noscript>
  </div>
  <template data-recent-save-template>
    <details class="studio-view-save"><summary><?= $escape($t('Als Ansicht speichern')) ?></summary>
      <form method="post" action="/inventory-views" data-recent-save>
        <?= $csrfField('inventory_view_create') ?>
        <input type="hidden" name="return_to" value="<?= $escape($viewReturn) ?>">
        <input type="hidden" name="filters" value="">
        <label><?= $escape($t('Name der Ansicht')) ?><input name="name" maxlength="80" required autocomplete="off"></label>
        <button class="studio-list-button" type="submit"><?= $escape($t('Ansicht speichern')) ?></button>
      </form>
    </details>
  </template>
  <script id="inventory-view-data" type="application/json"><?= $json(['query' => $viewQuery, 'record' => $recordRecentView, 'fields' => $viewVocabulary, 'maxBytes' => \App\Service\InventoryViewService::MAX_FILTER_BYTES]) ?></script>
</details>
