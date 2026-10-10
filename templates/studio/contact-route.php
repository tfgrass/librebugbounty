<div class="studio-contact-route" data-contact-route>
  <h3><?= $escape($t('Gewählter Meldeweg')) ?></h3>
  <p class="studio-detail-hint"><?= $escape($t('Ein Meldeweg pro Fall. Neue Kontaktabfragen ändern deine Auswahl nicht. Die Auswahl versendet nichts und hebt keine Sperren auf.')) ?></p>
  <?php if (!$contactRoute['available']): ?>
    <p><?= $escape($t('Bitte zuerst die Datenbankmigration für die Kontaktwahl ausführen.')) ?></p>
  <?php else: $selectedRoute = $contactRoute['selected']; ?>
    <?php if ($selectedRoute): ?>
      <div class="studio-record" data-selected-contact-route>
        <p><strong><?= $escape($t($selectedRoute['channel'] === 'email' ? 'E-Mail' : 'Formular oder Meldeportal')) ?></strong>: <code><?= $escape($selectedRoute['destination']) ?></code></p>
        <?php if ($selectedRoute['person'] !== null): ?><p><?= $escape($t('Ansprechpartner')) ?>: <?= $escape($selectedRoute['person']) ?></p><?php endif; ?>
        <?php if ($selectedRoute['source'] !== null): ?><p><?= $escape($t('Quelle')) ?>: <code><?= $escape($selectedRoute['source']) ?></code></p><?php endif; ?>
        <?php if ($selectedRoute['notes'] !== null): ?><p class="studio-contact-route-notes"><?= $escape($selectedRoute['notes']) ?></p><?php endif; ?>
        <p class="studio-detail-hint"><?= $escape($t($selectedRoute['provenance']['origin'] === 'manual' ? 'Manuell erfasst' : 'Aus Kontaktvorschlag gewählt')) ?> · <?= $escape($storedTime($selectedRoute['changed_at'])) ?></p>
        <?php if ($selectedRoute['provenance']['origin'] === 'suggestion'): ?>
          <p class="studio-detail-hint"><?= $escape($t('Abrufdatum')) ?>: <?= $escape($storedTime($selectedRoute['provenance']['fetched_at'])) ?></p>
          <?php if ($selectedRoute['provenance']['expires'] !== null): ?><p class="studio-detail-hint"><?= $escape($t('Veröffentlichtes Ablaufdatum')) ?>: <?= $escape($storedTime($selectedRoute['provenance']['expires'])) ?></p><?php endif; ?>
          <?php if (!\App\Service\ContactRouteService::suggestionCurrent(['status' => 'found', 'expires' => $selectedRoute['provenance']['expires']])): ?><p><?= $escape($t('Die gewählte Quelle ist inzwischen abgelaufen. Bitte vor einer Kontaktaufnahme erneut prüfen.')) ?></p><?php endif; ?>
        <?php endif; ?>
      </div>
    <?php else: ?><p data-no-contact-route><?= $escape($t('Noch kein Meldeweg gewählt.')) ?></p><?php endif; ?>
    <details class="studio-detail-fold" data-contact-route-manual-fold>
      <summary><?= $escape($t('Kontakt manuell hinzufügen')) ?></summary>
      <p class="studio-detail-hint"><?= $escape($t('Speichern ersetzt den bisherigen Meldeweg. E-Mail oder HTTP(S)-Formular-/Portal-URL; HTTPS ist empfohlen.')) ?></p>
      <form method="post" action="<?= $escape($findingPath) ?>/contact-route" data-contact-route-manual>
        <?= $csrfField('finding_contact_route_'.$finding->getId()) ?><?= $returnField ?>
        <input type="hidden" name="mode" value="manual"><input type="hidden" name="revision" value="<?= $escape($contactRoute['revision']) ?>">
        <label for="route-channel"><?= $escape($t('Kanal')) ?></label>
        <select name="channel" id="route-channel"><?php foreach (['email' => 'E-Mail', 'web' => 'Formular oder Meldeportal'] as $value => $label): ?><option value="<?= $value ?>"<?= ($selectedRoute['channel'] ?? 'email') === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?></select>
        <label for="route-destination"><?= $escape($t('E-Mail oder Formular-/Portal-URL')) ?></label>
        <input name="destination" id="route-destination" type="text" maxlength="2048" required value="<?= $escape($selectedRoute['destination'] ?? '') ?>">
        <label for="route-person"><?= $escape($t('Ansprechpartner (optional)')) ?></label><input name="person" id="route-person" type="text" maxlength="255" value="<?= $escape($selectedRoute['person'] ?? '') ?>">
        <label for="route-source"><?= $escape($t('Quelle (optional)')) ?></label><input name="source" id="route-source" type="text" maxlength="2048" value="<?= $escape($selectedRoute['source'] ?? '') ?>">
        <label for="route-notes"><?= $escape($t('Notiz (optional)')) ?></label><textarea name="notes" id="route-notes" rows="3" maxlength="4000"><?= $escape($selectedRoute['notes'] ?? '') ?></textarea>
        <button class="studio-detail-button" type="submit"><?= $escape($t('Meldeweg speichern')) ?></button>
      </form>
    </details>
  <?php endif; ?>
</div>
