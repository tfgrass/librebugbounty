<?php
/** Shared, native form for temporary review deferral. */
$recoveryReasonLabels = [
    'unreachable' => 'Ziel nicht erreichbar / Serverfehler',
    'protection' => 'Schutzseite oder CAPTCHA',
    'poc_unreproducible' => 'PoC derzeit nicht reproduzierbar',
    'evidence' => 'Beleg fehlt oder ist unbrauchbar',
    'other' => 'Anderer Grund',
];
$recoveryDeferred = ($recoveryDisposition['state'] ?? null) === 'deferred';
$recoveryPrefix = 'review-recovery-'.$recoveryFindingId;
?>
<section class="studio-review-recovery" id="review-recovery" data-review-recovery-state="<?= $recoveryDeferred ? 'deferred' : 'active' ?>">
  <?php if ($recoveryDeferred): ?>
    <p class="studio-status" data-tone="pending"><?= $escape($t('Zurückgestellt')) ?></p>
    <p><?= $escape($t($recoveryReasonLabels[$recoveryDisposition['reason']] ?? 'Anderer Grund')) ?></p>
    <?php if (($recoveryDisposition['note'] ?? '') !== ''): ?><p class="review-recovery-note"><?= $escape($recoveryDisposition['note']) ?></p><?php endif; ?>
    <p><?= $escape($t(($recoveryDisposition['due_at'] ?? null) === null ? 'Wiederaufnahme nur von Hand.' : 'Einmalige Wiederprüfung: {date}', ['date' => ($recoveryDisposition['due_at'] ?? null) === null ? '' : $storedTime($recoveryDisposition['due_at'])])) ?></p>
    <?php if (($recoveryDisposition['retry_state'] ?? null) !== null): ?>
      <?php $recoveryRetryLabel = match ($recoveryDisposition['retry_state']) {
          'pending' => 'Wiederprüfung vorgemerkt', 'running' => 'Wiederprüfung läuft',
          'completed' => 'Wiederprüfung abgeschlossen', 'interrupted' => 'Wiederprüfung unterbrochen',
          'cancelled' => 'Wiederprüfung aufgehoben', default => 'Wiederprüfung',
      }; ?>
      <p data-review-retry-state="<?= $escape($recoveryDisposition['retry_state']) ?>"><?= $escape($t($recoveryRetryLabel)) ?><?php if (($recoveryDisposition['retry_finished_at'] ?? null) !== null): ?> · <?= $escape($storedTime($recoveryDisposition['retry_finished_at'])) ?><?php endif; ?></p>
      <?php if (($recoveryDisposition['retry_result'] ?? null) !== null): ?><p><?= $escape($t(\App\Value\FindingReadLabels::observation($recoveryDisposition['retry_result']))) ?></p><?php endif; ?>
      <?php if (($recoveryDisposition['retry_message'] ?? '') !== ''): ?><p><?= $escape($t($recoveryDisposition['retry_message'])) ?></p><?php endif; ?>
    <?php endif; ?>
    <form method="post" action="/review/<?= $escape($recoveryFindingId) ?>/resume" data-review-resume-form>
      <?= $csrfField('review_resume_'.$recoveryFindingId) ?>
      <input type="hidden" name="context_token" value="<?= $escape($recoveryContextToken) ?>">
      <button class="review-button studio-detail-button" type="submit"><?= $escape($t('Wieder in die Sichtung aufnehmen')) ?></button>
    </form>
  <?php endif; ?>
  <details class="review-recovery-fold">
    <summary><?= $escape($t($recoveryDeferred ? 'Zurückstellung ändern' : 'Derzeit nicht prüfbar / Zurückstellen')) ?></summary>
    <p><?= $escape($t('Nimmt den Fall aus der normalen Sichtung. Bewertung, Hinweise und Belege bleiben erhalten. Zum Termin wird einmal geprüft; bei erneutem Prüfproblem bleibt der Fall zurückgestellt.')) ?></p>
    <form method="post" action="/review/<?= $escape($recoveryFindingId) ?>/defer" class="review-recovery-form" data-review-defer-form>
      <?= $csrfField('review_defer_'.$recoveryFindingId) ?>
      <input type="hidden" name="context_token" value="<?= $escape($recoveryContextToken) ?>">
      <?php if (($recoveryTrailId ?? '') !== ''): ?><input type="hidden" name="trail_id" value="<?= $escape($recoveryTrailId) ?>"><input type="hidden" name="trail_version" value="<?= $escape($recoveryTrailVersion) ?>"><?php endif; ?>
      <label for="<?= $escape($recoveryPrefix) ?>-reason"><?= $escape($t('Grund')) ?><select id="<?= $escape($recoveryPrefix) ?>-reason" name="reason" required><?php foreach ($recoveryReasonLabels as $value => $label): ?><option value="<?= $escape($value) ?>"<?= ($recoveryDisposition['reason'] ?? '') === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?></select></label>
      <label for="<?= $escape($recoveryPrefix) ?>-note"><?= $escape($t('Notiz (optional)')) ?><textarea id="<?= $escape($recoveryPrefix) ?>-note" name="note" maxlength="2000" rows="2"><?= $escape($recoveryDisposition['note'] ?? '') ?></textarea></label>
      <label for="<?= $escape($recoveryPrefix) ?>-schedule"><?= $escape($t('Wiederprüfung')) ?><select id="<?= $escape($recoveryPrefix) ?>-schedule" name="schedule"><option value="manual"><?= $escape($t('Unbefristet · nur von Hand wieder aufnehmen')) ?></option><option value="tomorrow"><?= $escape($t('Morgen um 09:00 (Berlin)')) ?></option><option value="date"><?= $escape($t('Am gewählten Datum um 09:00 (Berlin)')) ?></option></select></label>
      <label for="<?= $escape($recoveryPrefix) ?>-date"><?= $escape($t('Datum (bei gewähltem Termin)')) ?><input id="<?= $escape($recoveryPrefix) ?>-date" type="date" name="due_date" min="<?= (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d') ?>"></label>
      <button class="review-button studio-detail-button" type="submit"><?= $escape($t('Zurückstellen speichern')) ?></button>
    </form>
  </details>
</section>
