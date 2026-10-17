<?php

/** Shared by the dedicated page and the Review card without a readable image.
 * @var \App\Entity\Finding $finding
 * @var string $interactiveCsrfToken CSRF id review_interactive_{findingId}
 * @var string $returnPath
 * @var callable $escape
 * @var callable $t
 */
$novncMessages = [
    'loading' => $t('Browser-Status wird geprüft …'),
    'available' => $t('Bereit für eine interaktive Prüfung'),
    'own_active' => $t('Dein interaktiver Browser ist aktiv'),
    'busy' => $t('Browser in Benutzung'),
    'activating' => $t('Interaktiver Browser wird vorbereitet …'),
    'revoked' => $t('Die Browser-Steuerung wurde übernommen oder entzogen.'),
    'closing' => $t('Interaktiver Browser wird beendet …'),
    'expired' => $t('Die interaktive Browser-Session ist abgelaufen.'),
    'worker_unavailable' => $t('Der interaktive Browser ist derzeit nicht verfügbar.'),
    'locked' => $t('Aufnahme bereits festgehalten. Nur Speichern oder Abbrechen ist noch möglich.'),
    'connected' => $t('Live verbunden · Maus und Tastatur steuern den Browser'),
    'connecting' => $t('Live-Verbindung wird aufgebaut …'),
    'retryActivation' => $t('Browser-Aktivierung erneut versuchen'),
    'binding_changed' => $t('Der Fund wurde geändert. Beende die bisherige Session ausdrücklich, bevor du hier neu startest.'),
    'disconnected' => $t('Live-Verbindung getrennt. Der Browser bleibt bis zum Session-Ende erhalten.'),
    'lastFrame' => $t('Letztes Live-Bild · kein Nachweis der gespeicherten Aufnahme'),
    'statusError' => $t('Browser-Status nicht bestätigt. Steuerung pausiert; Status erneut prüfen.'),
    'actionError' => $t('Die Aktion wurde nicht bestätigt. Prüfe den Status, bevor du sie erneut ausführst.'),
    'channelError' => $t('Für die interaktive Prüfung müssen sicherer Zufall und Tab-Speicher verfügbar sein.'),
    'stale_revision' => $t('Der Browser-Status hat sich geändert. Prüfe den aktuellen Status und bestätige erneut.'),
    'policy_blocked' => $t('Die Arbeitsrichtlinie erlaubt diese interaktive Prüfung nicht.'),
    'discarded' => $t('Dieser Fund wurde verworfen. Eine interaktive Prüfung ist nicht verfügbar.'),
    'invalid_url' => $t('Eine interaktive Prüfung erfordert eine gültige HTTP- oder HTTPS-URL.'),
    'expires' => $t('Verbleibende Zeit: {value}'),
    'confirmTakeover' => $t('Browser übernehmen? Der bisherige Tab verliert die Steuerung; Seite und Aufnahme bleiben erhalten.'),
    'confirmEndStart' => $t('Andere Session beenden und hier neu starten? Nicht gespeicherte Inhalte der anderen Session gehen verloren.'),
    'confirmSave' => $t('Beleg speichern? Ein festgehaltenes Dialogbild wird bevorzugt. Die Bewertung bleibt eine gesonderte Entscheidung.'),
    'confirmRelease' => $t('Session ohne Speichern beenden? Nicht gespeicherte Inhalte gehen verloren.'),
    'dialogCaptured' => $t('Browser-Dialog vor dem Schließen als Bild festgehalten.'),
    'dialogUnconfirmed' => $t('Browser-Dialog beobachtet; seine Bildaufnahme ist nicht bestätigt.'),
    'fullscreen' => $t('Vollbild'),
    'exitFullscreen' => $t('Vollbild verlassen'),
];
?>
<link rel="stylesheet" href="/css/studio-review-interactive.css">
<section class="novnc-panel" data-review-novnc data-finding-id="<?= $escape($finding->getId()) ?>" data-csrf-token="<?= $escape($interactiveCsrfToken ?? '') ?>" data-return-path="<?= $escape($returnPath ?? '/review') ?>" data-state="loading" aria-label="<?= $escape($t('Interaktiv prüfen')) ?>" tabindex="-1">
  <script type="application/json" data-novnc-messages><?= json_encode($novncMessages, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <header class="novnc-heading">
    <div><strong><?= $escape($t('Interaktiv prüfen')) ?></strong><p><?= $escape($t('Live-Browser im Review · Start nur per Klick')) ?></p></div>
    <span class="novnc-badge" data-novnc-state role="status" aria-live="polite"><?= $escape($novncMessages['loading']) ?></span>
  </header>
  <p class="novnc-description"><?= $escape($t('Ein frischer Chromium-Kontext, ein aktiver Bediener. Wiederverbinden und Übernehmen behalten die Seite; die maximale Laufzeit beträgt 20 Minuten.')) ?></p>
  <p class="novnc-notice" data-novnc-notice role="status" aria-live="polite" hidden></p>
  <div class="novnc-toolbar" data-novnc-controls hidden>
    <button type="button" class="novnc-primary" data-novnc-action="acquire" hidden><?= $escape($t('Interaktive Prüfung starten')) ?></button>
    <button type="button" data-novnc-action="takeover" hidden><?= $escape($t('Browser übernehmen')) ?></button>
    <button type="button" data-novnc-action="end-and-start" hidden><?= $escape($t('Andere Session beenden und hier starten')) ?></button>
    <button type="button" data-novnc-action="reconnect" hidden><?= $escape($t('Wiederverbinden')) ?></button>
    <button type="button" class="novnc-primary" data-novnc-action="save" hidden><?= $escape($t('Beleg speichern')) ?></button>
    <button type="button" data-novnc-action="release" hidden><?= $escape($t('Abbrechen / Session beenden')) ?></button>
    <button type="button" data-novnc-action="refresh"><?= $escape($t('Session-Status prüfen')) ?></button>
    <button type="button" data-novnc-fullscreen aria-pressed="false"><?= $escape($t('Vollbild')) ?></button>
    <span class="novnc-expiry" data-novnc-expiry></span>
  </div>
  <div class="novnc-confirm" data-novnc-confirm role="group" aria-label="<?= $escape($t('Aktion bestätigen')) ?>" hidden>
    <p data-novnc-confirm-message></p>
    <button type="button" class="novnc-primary" data-novnc-confirm-yes><?= $escape($t('Bestätigen')) ?></button>
    <button type="button" data-novnc-confirm-no><?= $escape($t('Zurück')) ?></button>
  </div>
  <div class="novnc-viewport" data-novnc-viewport>
    <div class="novnc-placeholder" data-novnc-placeholder>
      <span class="novnc-placeholder-icon" aria-hidden="true">▣</span>
      <strong><?= $escape($t('Live-Browseransicht')) ?></strong>
      <p data-novnc-placeholder-message><?= $escape($t('Starte die Prüfung ausdrücklich. Das Öffnen dieser Ansicht startet keinen Browser.')) ?></p>
    </div>
    <div class="novnc-screen" data-novnc-screen aria-label="<?= $escape($t('Browser-Steuerung')) ?>" hidden></div>
    <figure class="novnc-last-frame" data-novnc-last-frame hidden><canvas></canvas><figcaption><?= $escape($novncMessages['lastFrame']) ?></figcaption></figure>
  </div>
  <p class="novnc-connection" data-novnc-connection aria-live="polite"></p>
  <section class="novnc-dialog" data-novnc-dialog hidden>
    <strong><?= $escape($t('Browser-Dialog beobachtet')) ?></strong>
    <p data-novnc-dialog-message></p><p data-novnc-dialog-image></p>
    <p><?= $escape($t('Eine Dialog-Beobachtung allein bestätigt keine Schwachstelle.')) ?></p>
  </section>
  <p class="novnc-description"><?= $escape($t('Eine Live-Ansicht oder gespeicherte Aufnahme ist keine automatische Bewertung. Wähle die Bewertungsgrundlage anschließend ausdrücklich im Review.')) ?></p>
  <noscript><p class="novnc-notice"><?= $escape($t('Die Live-Ansicht benötigt JavaScript. Ohne JavaScript bleiben gespeicherte Bilder im Review lesbar; Browser-Sessions können hier nicht gestartet oder übernommen werden.')) ?></p></noscript>
</section>
<script type="module" src="/js/studio-review-novnc.js"></script>
