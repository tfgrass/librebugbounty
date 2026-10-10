<?php use App\Value\PursuitStatus; ?>
<section class="studio-settings-panel" id="restrictions" aria-labelledby="restrictions-title" data-restrictions>
  <div class="studio-settings-panel-heading"><h2 id="restrictions-title"><?= $escape($t('Sperrzentrale')) ?></h2></div>
  <p class="studio-settings-field-hint"><?= $escape($t('Fall- und Domainsperren wirken unabhängig. Domainsperren gelten nur für den exakten Hostnamen und bleiben auch ohne Fall erhalten. Aufheben plant keine Rechecks neu.')) ?></p>
  <?php if ($restrictionError): ?><p class="studio-settings-feedback" data-tone="error" role="alert"><?= $escape($restrictionError) ?></p><?php endif; ?>
  <?php if (!$restrictions['available']): ?>
    <p><?= $escape($t('Bitte zuerst die Datenbankmigration für Nachverfolgung und Kontakte ausführen.')) ?></p>
  <?php else: ?>
    <?php foreach (['domains' => 'Domain', 'cases' => 'Dieser Fall'] as $group => $label): ?>
      <h3><?= $escape($t($group === 'domains' ? 'Domainsperren' : 'Fallsperren')) ?></h3>
      <?php if ($restrictions[$group] === []): ?><p><?= $escape($t('Keine gespeicherten Sperren.')) ?></p><?php endif; ?>
      <ul class="studio-restriction-list">
      <?php foreach ($restrictions[$group] as $row): $scope = $group === 'domains' ? 'domain' : 'case'; ?>
        <li data-restriction-scope="<?= $scope ?>">
          <strong><?= $escape($row['hostname']) ?></strong>
          <?php if ($scope === 'case'): ?><a href="/findings/<?= $escape($row['finding_id']) ?>#nachverfolgung"><?= $escape($row['title']) ?></a><?php else: ?><span><?= $escape($t('Verbleibende Fälle: {count}', ['count' => $formatNumber((int) $row['case_count'])])) ?></span><?php endif; ?>
          <?php if ($scope === 'case' && $row['pursuit'] === 'closed'): ?><p><?= $escape($t('Nicht weiterverfolgen')) ?> · <?= $escape($t(PursuitStatus::REASONS[$row['reason']] ?? $row['reason'])) ?></p><?php endif; ?>
          <p><?= $escape($t($row['contact_blocked'] ? 'Kontakt gesperrt' : 'Kontakt nicht gesperrt')) ?> · <?= $escape($t($row['checks_blocked'] ? 'Prüfungen gesperrt' : 'Prüfungen nicht gesperrt')) ?></p>
          <?php $actions = []; if ($row['contact_blocked']) $actions['contact_blocked'] = 'Kontaktsperre aufheben'; if ($row['checks_blocked']) $actions['checks_blocked'] = 'Prüfsperre aufheben'; if ($scope === 'case' && $row['pursuit'] === 'closed') $actions['pursuit'] = 'Fall wieder öffnen'; ?>
          <div class="studio-restriction-actions">
          <?php foreach ($actions as $field => $action): ?>
            <form method="post" action="/settings/restrictions" data-restriction-action="<?= $escape($field) ?>">
              <?= $csrfField('settings_restrictions') ?>
              <input type="hidden" name="scope" value="<?= $scope ?>"><input type="hidden" name="lift" value="<?= $escape($field) ?>">
              <input type="hidden" name="<?= $scope === 'domain' ? 'hostname' : 'finding_id' ?>" value="<?= $escape($scope === 'domain' ? $row['hostname'] : $row['finding_id']) ?>">
              <input type="hidden" name="revision" value="<?= $escape($row['revision']) ?>">
              <button type="submit"><?= $escape($t($action)) ?></button>
            </form>
          <?php endforeach; ?>
          </div>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
    <details><summary><?= $escape($t('Sperrprotokoll · letzte 100 Änderungen')) ?></summary>
      <ol class="studio-restriction-list">
      <?php foreach ($restrictions['history'] as $entry): $before = json_decode($entry['previous_state'], true); $after = json_decode($entry['new_state'], true); ?>
        <li><strong><?= $escape($entry['hostname']) ?></strong> · <?= $escape($t($entry['scope'] === 'domain' ? 'Domain' : 'Dieser Fall')) ?> · <?= $escape($formatTime(new \DateTimeImmutable($entry['changed_at']))) ?>
          <?php if ($entry['scope'] === 'case'): ?><p><?= $escape($t('Dieser Fall')) ?>: <code><?= $escape($entry['finding_id']) ?></code></p><?php endif; ?>
          <?php foreach (['contact_blocked' => 'Kontakt', 'checks_blocked' => 'Prüfungen'] as $flag => $flagLabel): if ($before[$flag] !== $after[$flag]): ?><p><?= $escape($t($flagLabel)) ?>: <?= $escape($t($after[$flag] ? 'Gesperrt' : 'Nicht gesperrt')) ?></p><?php endif; endforeach; ?>
          <?php if (($before['reason'] ?? null) !== ($after['reason'] ?? null)): ?><p><?= $escape($t('Beendigungsgrund')) ?>: <?= $escape(isset(PursuitStatus::REASONS[$after['reason'] ?? '']) ? $t(PursuitStatus::REASONS[$after['reason']]) : '—') ?></p><?php endif; ?>
          <?php if (($before['pursuit'] ?? null) !== ($after['pursuit'] ?? null)): ?><p><?= $escape($t($after['pursuit'] === 'closed' ? 'Nicht weiterverfolgen' : 'Wird weiterverfolgt')) ?></p><?php endif; ?>
        </li>
      <?php endforeach; ?>
      </ol>
    </details>
  <?php endif; ?>
</section>
