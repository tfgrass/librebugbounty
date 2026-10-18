<?php
/** @var int $recheckIntervalDays */
/** @var int $recheckErrorBackoffDays */
/** @var string $helpDialogExample */
?>
<div class="studio-intake-help-content" data-intake-help-content>
  <section class="studio-intake-help-section">
    <h3><?= $escape($t('URL und Kennzeichen')) ?></h3>
    <p><?= $escape($t('Füge die vollständige, reproduzierbare URL samt Payload ein. Das Kennzeichen ist der erwartete Dialogtext, zum Beispiel:')) ?> <code data-intake-help-example><?= $escape($helpDialogExample) ?></code></p>
    <p><?= $escape($t('Das Kennzeichen-Feld ergänzt keinen Payload in der URL. Dialoge werden erfasst; das Kennzeichen ist derzeit kein strenger Erfolgsfilter.')) ?></p>
  </section>
  <section class="studio-intake-help-section">
    <h3><?= $escape($t('Speichern und prüfen')) ?></h3>
    <p><?= $escape($t('Erfassen speichert den Fall lokal und reiht den Screenshot im Hintergrund ein. Währenddessen kannst du direkt die nächste URL erfassen. Danach sichtest du den Beleg manuell im Review. Es wird keine Meldung an die Website versendet.')) ?></p>
    <p><?= $escape($t('Jede erfolgreiche URL-Erfassung hält die Session aktiv. Nach 2 Std. 30 Min. ohne Erfassung beginnt mit der nächsten URL eine neue Session. Öffnen und Review verlängern sie nicht.')) ?></p>
  </section>
  <section class="studio-intake-help-section">
    <h3><?= $escape($t('Doppelte URLs')) ?></h3>
    <p><?= $escape($t('Exakt dieselbe URL führt zum vorhandenen Fall; dessen Kennzeichen und Notiz bleiben unverändert. Andere Parameter, Payloads oder Kodierungen können eigene Fälle für dieselbe Lücke erzeugen.')) ?></p>
  </section>
  <section class="studio-intake-help-section">
    <h3><?= $escape($t('Automatische Rechecks')) ?></h3>
    <p><?= $escape($t('Aktuell werden Rechecks nach {interval} Tagen fällig, nach technischen Fehlern nach {backoff} Tagen. Der tatsächliche Start hängt von der Warteschlange ab.', ['interval' => $recheckIntervalDays, 'backoff' => $recheckErrorBackoffDays])) ?></p>
    <p><?= $escape($t('Während manueller Prüfung oder einer Prüfsperre pausieren Rechecks. Behobene und verworfene Fälle sind ausgeschlossen.')) ?> <a href="/settings"><?= $escape($t('Intervalle in den Einstellungen ändern')) ?></a></p>
  </section>
</div>
