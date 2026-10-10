<?php
use App\Value\PursuitStatus;
$statusLabels = ['pending' => 'Abruf vorgemerkt', 'found' => 'Kontaktvorschläge gefunden', 'expired' => 'Abgelaufene Angaben', 'not_found' => 'Keine security.txt gefunden', 'invalid' => 'Ungültige Angaben', 'error' => 'Abruf fehlgeschlagen'];
?>
<div class="studio-follow-up-grid">
  <section class="studio-work-card" id="nachverfolgung" aria-labelledby="follow-up-title" tabindex="-1">
    <h2 id="follow-up-title"><?= $escape($t('Nachverfolgung')) ?></h2>
    <p class="studio-detail-hint"><?= $escape($t('Die Bearbeitung ist unabhängig von der fachlichen Bewertung. Ein bestätigter Befund bleibt bestätigt.')) ?></p>
    <?php if (!$workState['available']): ?>
      <p role="status"><?= $escape($t('Bitte zuerst die Datenbankmigration für Nachverfolgung und Kontakte ausführen.')) ?></p>
    <?php else: ?>
      <p class="studio-work-effective" data-effective-policy><?= $escape($t($workState['checksBlocked'] ? 'Prüfungen gesperrt' : 'Prüfungen nicht gesperrt')) ?> · <?= $escape($t($workState['contactBlocked'] ? 'Kontakt gesperrt' : 'Kontakt nicht gesperrt')) ?></p>
      <form method="post" action="<?= $escape($findingPath) ?>/follow-up" data-follow-up="case">
        <?= $csrfField('finding_follow_up_'.$finding->getId()) ?><?= $returnField ?>
        <input type="hidden" name="scope" value="case">
        <label for="follow-up-pursuit"><?= $escape($t('Dieser Fall')) ?></label>
        <select id="follow-up-pursuit" name="pursuit"><?php foreach (['active' => 'Wird weiterverfolgt', 'closed' => 'Nicht weiterverfolgen'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $workState['case']['pursuit'] === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?></select>
        <label for="follow-up-reason"><?= $escape($t('Beendigungsgrund')) ?></label>
        <select id="follow-up-reason" name="reason"><option value=""><?= $escape($t('Beim Beenden erforderlich')) ?></option><?php foreach (PursuitStatus::REASONS as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $workState['case']['reason'] === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?></select>
        <?php foreach (['contact_blocked' => 'Keine weiteren Kontakte', 'checks_blocked' => 'Keine weiteren Prüfungen'] as $flag => $label): ?>
          <label class="studio-work-check"><input type="checkbox" name="<?= $escape($flag) ?>" value="1"<?= $workState['case'][$flag] ? ' checked' : '' ?>> <span><?= $escape($t($label)) ?></span></label>
        <?php endforeach; ?>
        <p class="studio-detail-hint"><?= $escape($t('Ablehnung von Kontakt oder Prüfungen setzt die jeweilige Sperre. Beim Wiederöffnen bleiben angehakte Sperren bestehen.')) ?></p>
        <button class="studio-detail-button" type="submit"><?= $escape($t('Nachverfolgung speichern')) ?></button>
      </form>
      <details class="studio-detail-fold" data-domain-restrictions>
        <summary><?= $escape($t('Sperren für diese Domain')) ?> · <?= $escape($finding->getDomain()->getHostname()) ?></summary>
        <p><?= $escape($t('Gilt für alle Fälle dieses exakten Hostnamens, auch bei erneutem Import. Subdomains sind nicht eingeschlossen.')) ?></p>
        <p><?= $escape($t($workState['domain']['checks_blocked'] ? 'Prüfungen gesperrt' : 'Prüfungen nicht gesperrt')) ?> · <?= $escape($t($workState['domain']['contact_blocked'] ? 'Kontakt gesperrt' : 'Kontakt nicht gesperrt')) ?></p>
        <form method="post" action="<?= $escape($findingPath) ?>/follow-up" data-follow-up="domain">
          <?= $csrfField('finding_follow_up_'.$finding->getId()) ?><?= $returnField ?><input type="hidden" name="scope" value="domain">
          <?php foreach (['contact_blocked' => 'Keine weiteren Kontakte', 'checks_blocked' => 'Keine weiteren Prüfungen'] as $flag => $label): ?><label class="studio-work-check"><input type="checkbox" name="<?= $escape($flag) ?>" value="1"<?= $workState['domain'][$flag] ? ' checked' : '' ?>> <span><?= $escape($t($label)) ?></span></label><?php endforeach; ?>
          <button class="studio-detail-button" type="submit"><?= $escape($t('Domainsperren speichern')) ?></button>
        </form>
      </details>
      <p class="studio-detail-hint"><?= $escape($t('Gesperrte Screenshot-Aufträge bleiben vorgemerkt und können nach Aufheben der Sperre weiterlaufen. Recheck-Termine werden entfernt; Wiederöffnen plant sie nicht neu. Bereits gestartete Aufrufe lassen sich nicht rückgängig machen.')) ?></p>
      <details class="studio-detail-fold"><summary><?= $escape($t('Sperrprotokoll · letzte 20 Änderungen')) ?></summary>
        <ol class="studio-record-list"><?php foreach ($workHistory as $entry): $change = json_decode($entry['new_state'], true); ?>
          <li class="studio-record"><strong><?= $escape($t($entry['scope'] === 'domain' ? 'Domain' : 'Dieser Fall')) ?></strong> · <?= $escape($storedTime($entry['changed_at'])) ?>
            <?php if (isset($change['pursuit'])): ?><p><?= $escape($t($change['pursuit'] === 'closed' ? 'Nicht weiterverfolgen' : 'Wird weiterverfolgt')) ?><?= isset(PursuitStatus::REASONS[$change['reason'] ?? '']) ? ' · '.$escape($t(PursuitStatus::REASONS[$change['reason']])) : '' ?></p><?php endif; ?>
            <p><?= $escape($t($change['contact_blocked'] ? 'Kontakt gesperrt' : 'Kontakt nicht gesperrt')) ?> · <?= $escape($t($change['checks_blocked'] ? 'Prüfungen gesperrt' : 'Prüfungen nicht gesperrt')) ?></p>
          </li><?php endforeach; ?></ol>
      </details>
    <?php endif; ?>
  </section>
  <section class="studio-work-card" id="kontakte" aria-labelledby="contact-discovery-title" tabindex="-1">
    <h2 id="contact-discovery-title"><?= $escape($t('Kontaktvorschläge')) ?></h2>
    <p class="studio-detail-hint"><?= $escape($t('Nur auf Knopfdruck: öffentliche security.txt über HTTPS abrufen. Keine Websuche, kein Nachrichtenversand. Vorschläge und Richtlinien vor einer Kontaktaufnahme selbst prüfen.')) ?></p>
    <?php if ($workState['available'] && !$workState['checksBlocked'] && !$workState['contactBlocked'] && !$finding->isDiscarded()): ?>
      <form method="post" action="<?= $escape($findingPath) ?>/contact-discovery" data-contact-discovery>
        <?= $csrfField('finding_contact_discovery_'.$finding->getId()) ?><?= $returnField ?>
        <?php foreach ($contactProviders as $providerId => $providerLabel): ?><button class="studio-detail-button" type="submit" name="provider" value="<?= $escape($providerId) ?>"><?= $escape($t('Kontakte suchen: {provider}', ['provider' => $providerLabel])) ?></button><?php endforeach; ?>
      </form>
    <?php else: ?><p data-contact-discovery-blocked><?= $escape($t('Kontaktermittlung ist für diesen Fall nicht verfügbar oder gesperrt.')) ?></p><?php endif; ?>
    <?php if ($contactHistory === []): ?><p><?= $escape($t('Noch keine Kontaktabfrage gespeichert.')) ?></p><?php endif; ?>
    <ol class="studio-record-list" data-contact-history>
      <?php foreach ($contactHistory as $attempt): $result = $attempt['result']; ?>
        <li class="studio-record" data-contact-status="<?= $escape($result['status']) ?>">
          <h3><?= $escape($t($statusLabels[$result['status']] ?? 'Ungültige Angaben')) ?></h3>
          <p><?= $escape($contactProviders[$attempt['provider']] ?? $attempt['provider']) ?> · <?= $escape($storedTime($attempt['fetched_at'])) ?></p>
          <?php if ($result['source'] !== ''): ?><p><?= $escape($t('Quelle')) ?>: <code><?= $escape($result['source']) ?></code></p><?php endif; ?>
          <?php if ($result['expires'] !== null): ?><p><?= $escape($t('Veröffentlichtes Ablaufdatum')) ?>: <?= $escape($storedTime($result['expires'])) ?></p><?php endif; ?>
          <?php if ($result['status'] !== 'found' && $result['contacts'] !== []): ?><p class="studio-detail-hint"><?= $escape($t('Nicht als aktuelle Kontaktfreigabe verwenden.')) ?></p><?php endif; ?>
          <ul><?php foreach ($result['contacts'] as $contact): ?><li><?= $escape($t($contact['channel'] === 'email' ? 'E-Mail' : 'Formular oder Meldeportal')) ?>: <code><?= $escape($contact['value']) ?></code></li><?php endforeach; ?></ul>
          <?php foreach ($result['policies'] as $policyUrl): ?><p><?= $escape($t('Melderichtlinie')) ?>: <code><?= $escape($policyUrl) ?></code></p><?php endforeach; ?>
          <?php if ($result['languages'] !== []): ?><p><?= $escape($t('Bevorzugte Sprachen')) ?>: <?= $escape(implode(', ', $result['languages'])) ?></p><?php endif; ?>
          <?php foreach ($result['warnings'] as $warning): ?><p class="studio-detail-hint"><?= $escape($t($warning)) ?></p><?php endforeach; ?>
        </li>
      <?php endforeach; ?>
    </ol>
    <p class="studio-detail-hint"><?= $escape($t('Letzte zehn Abfragen. Fehler ersetzen keine früheren Vorschläge. Ein erneuter Abruf innerhalb einer Minute wird zusammengefasst.')) ?></p>
  </section>
</div>
