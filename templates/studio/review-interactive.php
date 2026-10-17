<?php

/** @var \App\Entity\Finding $finding */
/** @var \App\Dto\BrowserInteractiveSessionResult $status */
/** @var string $csrfToken */
/** @var string $returnQueryString */
/** @var callable $escape */
/** @var callable $t */
/** @var callable $formatTime */
$sessionPath = '/review/'.rawurlencode($finding->getId()).'/interactive-session';
$frameUrl = $sessionPath.'/frame?n='.bin2hex(random_bytes(6));
$inputPath = $sessionPath.'/input';
// Prefer the tri-state contract. Legacy booleans are usable only without an
// inspection error; an absent/invalid result must never mean clearance.
$challengeState = static function (array $challenge): string {
    if (!empty($challenge['error']) || !empty($challenge['snapshot']['error'])) {
        return 'unknown';
    }
    if (array_key_exists('state', $challenge)) {
        return in_array($challenge['state'], ['detected', 'cleared', 'unknown'], true) ? $challenge['state'] : 'unknown';
    }

    return match ($challenge['active'] ?? null) {
        true => 'detected',
        false => 'cleared',
        default => 'unknown',
    };
};
$initialState = $challengeState($status->initialChallenge);
$currentState = $challengeState($status->challenge);
$initialLabels = [
    'detected' => $t('Browser-Schutz war beim Start aktiv'),
    'cleared' => $t('Browser-Schutz war beim Start nicht erkannt'),
    'unknown' => $t('Browser-Schutz beim Start: unbekannt'),
];
$currentLabels = [
    'detected' => $t('Schutzseite noch sichtbar'),
    'cleared' => $t('Browser-Schutz aktuell nicht erkannt'),
    'unknown' => $t('Browser-Schutz aktuell: unbekannt'),
];
$remainingMs = max(0, ($status->expiresAt->getTimestamp() - time()) * 1000);
$expired = $remainingMs === 0;
$manualActionCount ??= 0;
$dialog = $status->dialogObservation;
$keys = ['Enter', 'Tab', 'Escape', 'Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown'];
$messages = [
    'expired' => $t('Die interaktive Browser-Session ist abgelaufen. Bitte im Review neu starten.'),
    'frameError' => $t('Live-Ansicht nicht verfügbar. Das letzte Bild ist möglicherweise veraltet. Bitte erneut versuchen oder die Session beenden.'),
    'inputError' => $t('Die Browser-Aktion konnte nicht bestätigt werden. Prüfe den Session-Status, bevor du sie erneut sendest.'),
    'textLimit' => $t('Gib zwischen 1 und 10000 UTF-8-Bytes ein.'),
    'unknown' => $currentLabels['unknown'],
];
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <title><?= $escape($t('Interaktive Aufnahme')) ?> · <?= $escape(substr($finding->getId(), 0, 8)) ?></title>
  <link rel="stylesheet" href="/css/studio.css">
  <link rel="stylesheet" href="/css/studio-review-interactive.css">
</head>
<body data-studio>
  <main class="interactive-shell" data-remaining-ms="<?= $remainingMs ?>">
    <header class="interactive-header">
      <div>
        <p class="studio-eyebrow"><?= $escape($t('Interaktive Aufnahme')) ?></p>
        <h1><?= $escape($finding->getDomain()->getHostname()) ?></h1>
        <p class="interactive-url" data-interactive-url><?= $escape($status->finalUrl ?? $finding->getUrl()) ?></p>
      </div>
      <a class="interactive-back" href="<?= $escape('/review'.($returnQueryString !== '' ? '?'.$returnQueryString : '')) ?>"><?= $escape($t('Zurück zum Review')) ?> ↩</a>
    </header>

    <p class="interactive-hint"><?= $escape($t('Dies ist ein interaktiver Wiederholungsversuch in einem frischen Chromium-Kontext. Cookies und Browser-Fingerabdruck der ursprünglichen Aufnahme werden nicht fortgesetzt.')) ?></p>
    <p class="interactive-url" data-interactive-initial-url><?= $escape($t('Start-URL')) ?>: <?= $escape($status->initialUrl ?? $finding->getUrl()) ?></p>
    <section class="interactive-status" aria-label="<?= $escape($t('Session-Status')) ?>">
      <span class="studio-status" data-initial-state="<?= $initialState ?>" data-tone="<?= $initialState === 'detected' ? 'pending' : 'neutral' ?>"><?= $escape($initialLabels[$initialState]) ?></span>
      <span class="studio-status" data-current-state="<?= $currentState ?>" data-tone="<?= $currentState === 'detected' ? 'pending' : 'neutral' ?>"><?= $escape($currentLabels[$currentState]) ?></span>
      <span data-interactive-expiry><?= $escape($t('Session endet: {value}', ['value' => $formatTime($status->expiresAt, true)])) ?></span>
      <a href="<?= $escape($sessionPath) ?>"><?= $escape($t('Session-Status prüfen')) ?></a>
    </section>
    <p class="interactive-hint"><?= $escape($t('Der Status und die URL stammen aus der letzten Prüfung beim Öffnen oder nach einer Browser-Aktion. Ein nicht erkannter Schutz bestätigt weder die Zielseite noch das technische Prüfergebnis.')) ?></p>
    <p class="interactive-hint" data-manual-actions><?= $escape($t('Bestätigte Browser-Aktionen: {count}', ['count' => (string) $manualActionCount])) ?></p>
    <?php if ($status->navigationError !== null): ?><p class="interactive-notice"><?= $escape($t('Die Navigation wurde nicht vollständig bestätigt. Prüfe die sichtbare Seite vor dem Speichern.')) ?></p><?php endif; ?>
    <section class="interactive-hint" data-interactive-dialog<?= $dialog === [] ? ' hidden' : '' ?>>
      <strong><?= $escape($t('Browser-Dialog beobachtet')) ?></strong>
      <span data-dialog-message><?= $escape(is_string($dialog['message'] ?? null) ? mb_substr($dialog['message'], 0, 10000) : '') ?></span>
      <p data-dialog-image><?= $escape($t(($dialog['captureSucceeded'] ?? false) === true ? 'Browser-Dialog vor dem Schließen als Bild festgehalten.' : 'Browser-Dialog beobachtet; seine Bildaufnahme ist nicht bestätigt.')) ?></p>
      <p><?= $escape($t('Die Dialog-Beobachtung wird als Herkunft des Belegs gespeichert. Ob der Dialog im Bild festgehalten wurde, wird gesondert protokolliert.')) ?></p>
    </section>
    <p class="interactive-notice" role="status" aria-live="polite" data-interactive-notice<?= $expired ? '' : ' hidden' ?>><?= $expired ? $escape($messages['expired']) : '' ?></p>
    <noscript><p class="interactive-hint"><?= $escape($t('Ohne JavaScript aktualisieren Browser-Aktionen die Seite. Nutze „Session-Status prüfen“, um Status und Bild neu zu laden.')) ?></p></noscript>

    <fieldset class="interactive-session-controls"<?= $expired ? ' disabled' : '' ?>>
      <legend><?= $escape($t('Browser-Steuerung')) ?></legend>
      <p class="interactive-hint"><?= $escape($t('Klicke im Bild, um die Seite zu bedienen. Mit JavaScript wird nur das Bild alle drei Sekunden aktualisiert; dein eingegebener Text bleibt erhalten. Löse den Schutz manuell in diesem Wiederholungsversuch.')) ?></p>
      <section class="interactive-frame-panel" aria-label="<?= $escape($t('Live-Browseransicht')) ?>">
        <div class="interactive-frame-scroll">
          <form method="post" action="<?= $escape($inputPath) ?>" class="interactive-click-form" data-interactive-input>
            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="action" value="click">
            <input class="interactive-frame" type="image" name="click" src="<?= $escape($frameUrl) ?>" alt="<?= $escape($t('Live-Browseransicht anklicken')) ?>" width="1440" height="900">
          </form>
        </div>
      </section>

      <section class="interactive-controls" aria-label="<?= $escape($t('Browser-Steuerung')) ?>">
        <?php foreach ([['reload', 'Neu laden'], ['back', 'Zurück']] as [$action, $label]): ?>
          <form method="post" action="<?= $escape($inputPath) ?>" data-interactive-input>
            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="<?= $escape($action) ?>">
            <button class="review-button" type="submit"><?= $escape($t($label)) ?></button>
          </form>
        <?php endforeach; ?>
        <?php foreach ([['-600', 'Hochscrollen'], ['600', 'Runterscrollen']] as [$delta, $label]): ?>
          <form method="post" action="<?= $escape($inputPath) ?>" data-interactive-input>
            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="scroll"><input type="hidden" name="deltaY" value="<?= $escape($delta) ?>">
            <button class="review-button" type="submit"><?= $escape($t($label)) ?></button>
          </form>
        <?php endforeach; ?>
        <?php foreach ($keys as $key): ?>
          <form method="post" action="<?= $escape($inputPath) ?>" data-interactive-input>
            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="key"><input type="hidden" name="key" value="<?= $escape($key) ?>">
            <button class="review-key" type="submit"><kbd><?= $escape($key) ?></kbd></button>
          </form>
        <?php endforeach; ?>
        <form method="post" action="<?= $escape($inputPath) ?>" class="interactive-text-form" data-interactive-input>
          <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="type">
          <label for="interactive-text"><?= $escape($t('Text eingeben')) ?></label>
          <input id="interactive-text" name="text" type="text" autocomplete="off" maxlength="10000" required aria-describedby="interactive-text-limit">
          <button class="review-button" type="submit"><?= $escape($t('Senden')) ?></button>
          <small id="interactive-text-limit"><?= $escape($t('Maximal 10000 UTF-8-Bytes.')) ?></small>
        </form>
      </section>

      <section class="interactive-actions" aria-label="<?= $escape($t('Aufnahme abschließen')) ?>">
        <form method="post" action="<?= $escape($sessionPath.'/save') ?>">
          <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
          <button class="review-button review-button-primary" type="submit"><?= $escape($t('Beleg speichern')) ?></button>
          <p><?= $escape($t('Ein zuvor festgehaltener Browser-Dialog wird bevorzugt gespeichert, auch wenn die Seite danach weitergeleitet hat. Ohne Dialogbild wird die aktuelle Seite aufgenommen.')) ?></p>
          <p><?= $escape($t('Die Aufnahme wird als weiterer Screenshot gespeichert und mit interaktiver Herkunft markiert. Bewerte sie erst danach ausdrücklich als Bewertungsgrundlage.')) ?></p>
        </form>
        <form method="post" action="<?= $escape($sessionPath.'/cancel') ?>">
          <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="return_query" value="<?= $escape($returnQueryString) ?>">
          <button class="review-button" type="submit"><?= $escape($t('Session beenden')) ?></button>
        </form>
      </section>
    </fieldset>
  </main>
  <script>
    (() => {
      const messages = <?= json_encode($messages, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const shell = document.querySelector('.interactive-shell');
      const controls = document.querySelector('.interactive-session-controls');
      const frame = document.querySelector('.interactive-frame');
      const notice = document.querySelector('[data-interactive-notice]');
      const currentState = document.querySelector('[data-current-state]');
      const text = document.querySelector('#interactive-text');
      const deadline = performance.now() + Number(shell.dataset.remainingMs);
      let ended = controls.disabled, busy = false, timer, frameBusy = false, objectUrl;
      const notify = message => { notice.textContent = message; notice.hidden = !message; };
      const unknown = () => {
        currentState.dataset.currentState = 'unknown';
        currentState.dataset.tone = 'neutral';
        currentState.textContent = messages.unknown;
      };
      const expire = () => {
        ended = true;
        controls.disabled = true;
        window.clearTimeout(timer);
        unknown();
        notify(messages.expired);
      };
      const alive = () => {
        if (!ended && performance.now() >= deadline) expire();
        return !ended;
      };
      const refreshFrame = async () => {
        if (!alive() || frameBusy || busy) return;
        frameBusy = true;
        try {
          const source = new URL(<?= json_encode($sessionPath.'/frame', JSON_THROW_ON_ERROR) ?>, window.location.href);
          source.searchParams.set('n', Math.random().toString(36).slice(2));
          const response = await fetch(source, { cache: 'no-store', credentials: 'same-origin', signal: AbortSignal.timeout(30000) });
          if (response.status === 404 || response.status === 410) { expire(); return; }
          if (!response.ok || !response.headers.get('content-type')?.startsWith('image/png')) throw new Error('frame');
          const nextUrl = URL.createObjectURL(await response.blob());
          const image = new Image();
          image.src = nextUrl;
          try { await image.decode(); } catch (error) { URL.revokeObjectURL(nextUrl); throw error; }
          if (!alive()) { URL.revokeObjectURL(nextUrl); return; }
          frame.src = nextUrl;
          if (objectUrl) URL.revokeObjectURL(objectUrl);
          objectUrl = nextUrl;
          if (notice.textContent === messages.frameError) notify('');
        } catch (_) {
          if (alive()) { unknown(); notify(messages.frameError); }
        } finally {
          frameBusy = false;
        }
      };
      const scheduleFrame = () => {
        timer = window.setTimeout(async () => {
          await refreshFrame();
          if (alive()) scheduleFrame();
        }, 3000);
      };
      frame.addEventListener('error', () => { if (alive()) { unknown(); notify(messages.frameError); } });
      text.addEventListener('input', () => {
        text.setCustomValidity(new TextEncoder().encode(text.value).length > 10000 ? messages.textLimit : '');
      });
      // Native image submissions use click.x/click.y (normalized by PHP to
      // click_x/click_y). Keep a fixed 1:1 frame so both paths use worker pixels.
      let clickPoint = { x: 0, y: 0 };
      frame.addEventListener('click', event => {
        const rect = frame.getBoundingClientRect();
        clickPoint = {
          x: Math.max(0, Math.min(1439, Math.round(event.clientX - rect.left))),
          y: Math.max(0, Math.min(899, Math.round(event.clientY - rect.top))),
        };
      });
      for (const form of document.querySelectorAll('[data-interactive-input]')) {
        form.addEventListener('submit', async event => {
          event.preventDefault();
          if (!alive() || busy) return;
          const values = new FormData(form);
          if (values.get('action') === 'click') {
            values.set('click_x', String(clickPoint.x));
            values.set('click_y', String(clickPoint.y));
          }
          if (values.get('action') === 'type') {
            const size = new TextEncoder().encode(values.get('text')).length;
            if (size === 0 || size > 10000) { text.setCustomValidity(messages.textLimit); text.reportValidity(); return; }
          }
          busy = true;
          controls.disabled = true;
          const sentText = text.value;
          try {
            // Allow the proxy's bounded frame/input/status calls, including a
            // navigation action and PHP session-lock waiting, to finish first.
            const response = await fetch(form.getAttribute('action'), { method: 'POST', body: values, credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(120000) });
            if (response.status === 404 || response.status === 410) { expire(); return; }
            if (!response.ok) throw new Error('input');
            const documentResult = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextState = documentResult.querySelector('[data-current-state]');
            const nextUrl = documentResult.querySelector('[data-interactive-url]');
            if (!nextState || !nextUrl) throw new Error('status');
            if (!alive()) return;
            currentState.dataset.currentState = nextState.dataset.currentState;
            currentState.dataset.tone = nextState.dataset.tone;
            currentState.textContent = nextState.textContent;
            document.querySelector('[data-interactive-url]').textContent = nextUrl.textContent;
            for (const selector of ['[data-manual-actions]', '[data-interactive-dialog]']) {
              const next = documentResult.querySelector(selector);
              const current = document.querySelector(selector);
              if (next && current) {
                current.replaceChildren(...Array.from(next.childNodes, node => node.cloneNode(true)));
                current.hidden = next.hidden;
              }
            }
            notify('');
            if (values.get('action') === 'type' && text.value === sentText) { text.value = ''; text.setCustomValidity(''); }
          } catch (_) {
            if (alive()) { unknown(); notify(messages.inputError); }
          } finally {
            busy = false;
            controls.disabled = ended;
            if (!ended) await refreshFrame();
          }
        });
      }
      // Save/cancel remain explicit native CSRF-protected POSTs. Suppress repeat
      // submissions without disabling successful form fields (including CSRF).
      for (const form of document.querySelectorAll('.interactive-actions form')) {
        form.addEventListener('submit', event => {
          if (!alive() || busy) { event.preventDefault(); return; }
          busy = true;
          ended = true;
          window.clearTimeout(timer);
        });
      }
      if (alive()) scheduleFrame();
      window.setInterval(alive, 1000);
      window.addEventListener('pagehide', () => {
        ended = true;
        window.clearTimeout(timer);
        if (objectUrl) URL.revokeObjectURL(objectUrl);
      });
      window.addEventListener('pageshow', event => { if (event.persisted) expire(); });
    })();
  </script>
</body>
</html>
