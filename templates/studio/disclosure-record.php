<?php
use App\Service\DisclosureRecordService;
$recordToday = DisclosureRecordService::today();
$recordReminder = $disclosureRecord['reminder'];
?>
<section class="studio-work-card studio-disclosure-record" id="meldung" aria-labelledby="disclosure-record-title" tabindex="-1" data-disclosure-record>
  <h2 id="disclosure-record-title"><?= $escape($t('Meldung & Verlauf')) ?></h2>
  <p class="studio-detail-hint"><?= $escape($t('Nur dokumentieren, nicht versenden. Historische Kontakt- und Versandmarkierungen bleiben unverändert. Wiedervorlagen sind manuell und lösen keine Prüfungen oder Nachrichten aus.')) ?></p>
  <?php if (!$disclosureRecord['available']): ?><p><?= $escape($t('Bitte zuerst die Datenbankmigration für die Meldeakte ausführen.')) ?></p>
  <?php else: ?>
    <div class="studio-follow-up-grid">
      <div>
        <h3><?= $escape($t('Aktivität festhalten')) ?></h3>
        <form method="post" action="<?= $escape($findingPath) ?>/disclosure-record" data-disclosure-activity>
          <?= $csrfField('finding_disclosure_record_'.$finding->getId()) ?><?= $returnField ?>
          <input type="hidden" name="kind" value="activity"><input type="hidden" name="entry_id" value="<?= $escape(\Symfony\Component\Uid\Uuid::v7()->toRfc4122()) ?>">
          <label for="record-activity"><?= $escape($t('Aktivität')) ?></label><select id="record-activity" name="activity"><?php foreach (DisclosureRecordService::ACTIVITIES as $value => $label): ?><option value="<?= $value ?>"><?= $escape($t($label)) ?></option><?php endforeach; ?></select>
          <label for="record-day"><?= $escape($t('Datum')) ?></label><input id="record-day" name="occurred_on" type="date" value="<?= $recordToday ?>" required>
          <label for="record-channel"><?= $escape($t('Kanal')) ?></label><select id="record-channel" name="channel"><?php foreach (DisclosureRecordService::CHANNELS as $value => $label): ?><option value="<?= $value ?>"<?= ($contactRoute['selected']['channel'] ?? 'email') === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?></select>
          <label for="record-recipient"><?= $escape($t('Empfänger / Beteiligter')) ?></label><input id="record-recipient" name="recipient" type="text" maxlength="2048" value="<?= $escape($contactRoute['selected']['destination'] ?? '') ?>" required>
          <label for="record-ticket"><?= $escape($t('Ticketnummer (optional)')) ?></label><input id="record-ticket" name="ticket" type="text" maxlength="255">
          <label for="record-comment"><?= $escape($t('Kommentar (optional)')) ?></label><textarea id="record-comment" name="comment" rows="3" maxlength="4000"></textarea>
          <button type="submit" class="studio-detail-button"><?= $escape($t('Aktivität speichern')) ?></button>
        </form>
      </div>
      <div>
        <h3><?= $escape($t('Wiedervorlage')) ?></h3>
        <?php if ($recordReminder): ?>
          <div class="studio-record" data-current-reminder data-reminder-open="<?= $recordReminder['completed_at'] === null ? 'yes' : 'no' ?>">
            <strong><?= $escape($t($recordReminder['completed_at'] !== null ? 'Erledigt' : ($recordReminder['due_on'] < $recordToday ? 'Überfällig' : ($recordReminder['due_on'] === $recordToday ? 'Heute' : 'Geplant')))) ?></strong>
            <p><?= $escape($formatDate($recordReminder['due_on'])) ?> · <?= $escape($recordReminder['next_step']) ?></p>
            <?php if ($recordReminder['completed_at'] !== null): ?><p><?= $escape($storedTime($recordReminder['completed_at'])) ?></p><?php endif; ?>
          </div>
          <?php if ($recordReminder['completed_at'] === null): ?>
            <form method="post" action="<?= $escape($findingPath) ?>/disclosure-record" data-disclosure-reminder-complete>
              <?= $csrfField('finding_disclosure_record_'.$finding->getId()) ?><?= $returnField ?>
              <input type="hidden" name="kind" value="reminder"><input type="hidden" name="action" value="complete"><input type="hidden" name="revision" value="<?= $escape($disclosureRecord['revision']) ?>">
              <button type="submit" class="studio-detail-button"><?= $escape($t('Wiedervorlage erledigen')) ?></button>
            </form>
          <?php endif; ?>
        <?php else: ?><p><?= $escape($t('Noch keine Wiedervorlage.')) ?></p><?php endif; ?>
        <p class="studio-detail-hint"><?= $escape($t('Eine offene Wiedervorlage pro Fall. Speichern ersetzt den Termin und nächsten Schritt. Kalenderdaten gelten in Europe/Berlin.')) ?></p>
        <form method="post" action="<?= $escape($findingPath) ?>/disclosure-record" data-disclosure-reminder>
          <?= $csrfField('finding_disclosure_record_'.$finding->getId()) ?><?= $returnField ?>
          <input type="hidden" name="kind" value="reminder"><input type="hidden" name="action" value="save"><input type="hidden" name="revision" value="<?= $escape($disclosureRecord['revision']) ?>">
          <label for="reminder-day"><?= $escape($t('Datum')) ?></label><input id="reminder-day" name="due_on" type="date" value="<?= $escape($recordReminder['due_on'] ?? $recordToday) ?>" required>
          <label for="reminder-step"><?= $escape($t('Nächster Schritt')) ?></label><input id="reminder-step" name="next_step" type="text" maxlength="255" value="<?= $escape($recordReminder['next_step'] ?? '') ?>" required>
          <button type="submit" class="studio-detail-button"><?= $escape($t('Wiedervorlage speichern')) ?></button>
        </form>
        <p><a href="/findings?reminder=today"><?= $escape($t('Heute')) ?></a> · <a href="/findings?reminder=overdue"><?= $escape($t('Überfällig')) ?></a></p>
      </div>
    </div>
    <h3><?= $escape($t('Meldeverlauf')) ?></h3>
    <?php if ($disclosureRecord['activities'] === []): ?><p><?= $escape($t('Noch keine Aktivität dokumentiert.')) ?></p><?php endif; ?>
    <ol class="studio-record-list" data-disclosure-history>
      <?php foreach ($disclosureRecord['activities'] as $entry): ?>
        <li class="studio-record" data-disclosure-activity-type="<?= $escape($entry['activity']) ?>">
          <strong><?= $escape($t(DisclosureRecordService::ACTIVITIES[$entry['activity']])) ?></strong> · <?= $escape($formatDate($entry['occurred_on'])) ?>
          <p><?= $escape($t(DisclosureRecordService::CHANNELS[$entry['channel']])) ?> · <?= $escape($entry['recipient']) ?></p>
          <?php if ($entry['ticket'] !== null): ?><p><?= $escape($t('Ticketnummer')) ?>: <?= $escape($entry['ticket']) ?></p><?php endif; ?>
          <?php if ($entry['comment'] !== null): ?><p class="studio-contact-route-notes"><?= $escape($entry['comment']) ?></p><?php endif; ?>
          <p class="studio-detail-hint"><?= $escape($t('Erfasst am')) ?>: <?= $escape($storedTime($entry['recorded_at'])) ?></p>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>
