<?php

use App\Value\FindingReadLabels;

/** @var \App\Dto\FindingDetailView $view */
/** @var callable $escape */
/** @var callable $csrfField */
/** @var ?string $message */
/** @var ?string $error */
/** @var ?string $returnPath */
$finding = $view->finding;
$assessment = $finding->getManualAssessment();
$state = $view->assessmentState;
$latestRun = $state->latestRun;
$latestJob = $view->screenshotJobs[0] ?? null;
$findingPath = '/findings/'.$finding->getId();
$returnPath ??= null;
$returnField = $returnPath !== null
    ? '<input type="hidden" name="return_to" value="'.$escape($returnPath).'">'
    : '';
$jobLabel = static fn (string $status): string => $t(match ($status) {
    'queued' => 'Vorgemerkt',
    'running' => 'Aufnahme läuft',
    'available' => 'Aufnahme abgeschlossen',
    'failed' => 'Aufnahme fehlgeschlagen',
    default => $status,
});
$jobTone = static fn (string $status): string => match ($status) {
    'queued', 'running' => 'pending',
    'available' => 'success',
    'failed' => 'error',
    default => 'neutral',
};
$storedTime = static function (?string $value) use ($formatTime, $t): string {
    if ($value === null || $value === '') {
        return $t('Zeitpunkt unbekannt');
    }
    try {
        return $formatTime(new \DateTimeImmutable($value));
    } catch (\Throwable) {
        return $value;
    }
};
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($finding->getDomain()->getHostname()) ?> · <?= $escape($t('Fall')) ?> · <?= $escape(\App\AppInfo::NAME.' '.\App\AppInfo::RELEASE_NAME) ?></title>
  <link rel="stylesheet" href="/css/studio.css">
  <link rel="stylesheet" href="/css/studio-detail.css">
  <script src="/js/i18n.js" defer></script>
  <script src="/js/studio-detail.js" defer></script>
</head>
<body data-studio data-studio-detail>
  <div class="studio-shell studio-detail-shell">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
      <div class="studio-header-tools"><?php require __DIR__.'/language.php'; ?></div>
    </header>

    <main class="studio-detail-workspace" id="studio-detail-main">
      <div class="studio-case-heading">
        <div class="studio-case-title">
          <p class="studio-eyebrow"><?= $escape($t('Fall')) ?> · <?= $escape(substr($finding->getId(), 0, 8)) ?></p>
          <h1><?= $escape($finding->getDomain()->getHostname()) ?></h1>
          <p class="studio-case-subtitle"><?= $escape($finding->getTitle()) ?></p>
        </div>
        <a class="studio-detail-back" href="<?= $escape($returnPath ?? '/findings') ?>"><span aria-hidden="true">←</span> <?= $escape($t(str_starts_with($returnPath ?? '', '/review') ? 'Zur Review' : 'Zum Bestand')) ?></a>
      </div>

      <?php if ($message !== null && $message !== ''): ?>
        <p class="studio-detail-feedback" data-tone="success" role="status"><?= $escape($message) ?></p>
      <?php endif; ?>
      <?php if ($error !== null && $error !== ''): ?>
        <p class="studio-detail-feedback" data-tone="error" role="alert"><?= $escape($error) ?></p>
      <?php endif; ?>

      <nav class="studio-detail-sections" aria-label="<?= $escape($t('Bereiche dieses Falls')) ?>">
        <a href="#beleg"><?= $escape($t('Beleg')) ?></a>
        <a href="#entscheidung"><?= $escape($t('Entscheidung')) ?></a>
        <a href="#verlauf" data-open-history><?= $escape($t('Verlauf')) ?> <span><?= $escape($formatNumber(count($view->assessments) + count($view->screenshotJobs) + count($view->runs))) ?></span></a>
      </nav>

      <div class="studio-case-layout">
        <section class="studio-evidence-workspace" id="beleg" aria-labelledby="studio-evidence-title" tabindex="-1">
          <div class="studio-section-heading">
            <h2 id="studio-evidence-title"><?= $escape($t('Beleg')) ?></h2>
            <span class="studio-detail-muted"><?= $escape($t(count($view->screenshots) === 1 ? '{count} Bild' : '{count} Bilder', ['count' => $formatNumber(count($view->screenshots))])) ?></span>
          </div>

          <div class="studio-capture-state" data-tone="<?= $escape($latestJob !== null ? $jobTone($latestJob->getStatus()) : 'neutral') ?>">
            <span class="studio-status" data-tone="<?= $escape($latestJob !== null ? $jobTone($latestJob->getStatus()) : 'neutral') ?>"><?= $escape($latestJob !== null ? $t('Letzter Screenshot · {status}', ['status' => $jobLabel($latestJob->getStatus())]) : $t('Noch kein Screenshot-Auftrag')) ?></span>
            <?php if ($latestJob !== null): ?>
              <span class="studio-detail-muted"><?= $escape($formatTime($latestJob->getRequestedAt())) ?></span>
              <?php if ($latestJob->getStatus() === 'failed'): ?>
                <p><?= $escape($latestJob->getErrorMessage() ?? $t('Die Aufnahme konnte nicht abgeschlossen werden.')) ?></p>
              <?php elseif (in_array($latestJob->getStatus(), ['queued', 'running'], true)): ?>
                <p><?= $escape($t('Der Auftrag läuft separat. Vorhandene Bilder bleiben frühere Belege.')) ?></p>
              <?php endif; ?>
              <?php $latestMetadata = $latestJob->getCaptureMetadata() ?? []; ?>
              <?php if (($latestMetadata['challengeDetected'] ?? false) === true): ?>
                <p class="studio-challenge-notice"><?= $escape($t(($latestMetadata['challengeCleared'] ?? false) === true ? 'Browser-Schutz erkannt und beendet; anschließend wurde die Zielseite beobachtet.' : 'Browser-Schutz blieb aktiv. Ein Bild kann die Schutzseite zeigen.')) ?></p>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <?php if ($view->screenshots === []): ?>
            <div class="studio-evidence-empty">
              <svg width="42" height="42" viewBox="0 0 48 48" fill="none" aria-hidden="true"><rect x="7" y="8" width="34" height="31" rx="3" stroke="currentColor" stroke-width="1.4"/><circle cx="17" cy="18" r="3" stroke="currentColor" stroke-width="1.4"/><path d="m8 34 10-10 8 7 6-6 9 9" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
              <h3><?= $escape($t('Noch kein Bildbeleg')) ?></h3>
              <p><?= $escape($t($latestJob !== null && in_array($latestJob->getStatus(), ['queued', 'running'], true) ? 'Die Aufnahme entsteht im Hintergrund. Nach Abschluss kannst du die Seite aktualisieren.' : 'Aufnahmezustand und vorhandene technische Belege findest du im Verlauf.')) ?></p>
            </div>
          <?php else: ?>
            <?php if (count($view->screenshots) > 1): ?>
              <nav class="studio-shot-picker" aria-label="<?= $escape($t('Bildbeleg auswählen')) ?>">
                <?php foreach ($view->screenshots as $index => $shot): ?>
                  <a href="#bild-<?= $escape($shot['evidence']->getId()) ?>" data-shot-select="<?= $escape($shot['evidence']->getId()) ?>">
                    <span><?= $escape($t('Bild {number}', ['number' => $formatNumber($index + 1)])) ?></span>
                    <small><?= $escape($shot['capturedAt'] !== null ? $formatTime($shot['capturedAt']) : $t('Aufnahmezeit unbekannt')) ?></small>
                    <?php if (!$shot['available']): ?><small class="studio-detail-error"><?= $escape($t('Datei nicht verfügbar')) ?></small><?php endif; ?>
                  </a>
                <?php endforeach; ?>
              </nav>
            <?php endif; ?>
            <div class="studio-shot-stage" data-shot-stage>
              <?php foreach ($view->screenshots as $index => $shot): ?>
                <?php $localImage = $shot['available'] && is_string($shot['url']) && str_starts_with($shot['url'], '/artifacts/'); ?>
                <figure class="studio-shot" id="bild-<?= $escape($shot['evidence']->getId()) ?>" data-shot-id="<?= $escape($shot['evidence']->getId()) ?>" tabindex="-1">
                  <?php if ($localImage): ?>
                    <a class="studio-shot-image-link" href="<?= $escape($shot['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= $escape($t('Bild {number} in Originalgröße öffnen', ['number' => $formatNumber($index + 1)])) ?>">
                      <img src="<?= $escape($shot['url']) ?>" alt="<?= $escape($t('Gespeicherter Screenshot {number} für {host}', ['number' => $formatNumber($index + 1), 'host' => $finding->getDomain()->getHostname()])) ?>" loading="<?= $index === 0 ? 'eager' : 'lazy' ?>" decoding="async">
                    </a>
                  <?php else: ?>
                    <div class="studio-shot-missing">
                      <span aria-hidden="true">⊘</span>
                      <h3><?= $escape($t('Bilddatei nicht verfügbar')) ?></h3>
                      <p><?= $escape($t('Der Beleg ist aufgezeichnet. Die zugehörige Datei fehlt oder kann nicht gelesen werden.')) ?></p>
                    </div>
                  <?php endif; ?>
                  <figcaption>
                    <div>
                      <strong><?= $escape($t('Bild {number}', ['number' => $formatNumber($index + 1)])) ?></strong>
                      <span><?= $escape($t('Aufnahme')) ?>: <?= $escape($shot['capturedAt'] !== null ? $formatTime($shot['capturedAt']) : $t('unbekannt')) ?></span>
                      <span><?= $escape($t('Ablage')) ?>: <?= $escape($formatTime($shot['evidence']->getCreatedAt())) ?></span>
                    </div>
                    <?php if ($localImage): ?><a class="studio-detail-text-link" href="<?= $escape($shot['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($t('Original öffnen')) ?> <span aria-hidden="true">↗</span></a><?php endif; ?>
                  </figcaption>
                </figure>
              <?php endforeach; ?>
            </div>
            <p class="studio-detail-hint"><?= $escape($t('Ein Bildbeleg ist eine Aufnahme. Die manuelle Bewertung steht im Inspector.')) ?></p>
          <?php endif; ?>

          <div class="studio-case-source">
            <div class="studio-source-heading"><h3><?= $escape($t('Gemeldete URL')) ?></h3><button type="button" class="studio-copy-button" data-copy-target="studio-finding-url" hidden><?= $escape($t('URL kopieren')) ?></button></div>
            <code id="studio-finding-url"><?= $escape($finding->getUrl()) ?></code>
            <p class="studio-copy-status" role="status" aria-live="polite" data-copy-status></p>
            <details class="studio-detail-fold">
              <summary><?= $escape($t('Kennzeichen & Eingabedaten')) ?></summary>
              <dl class="studio-record-data">
                <dt><?= $escape($t('Kennzeichen')) ?></dt><dd><code><?= $escape($finding->getExpectedEvidence() ?? $t('Nicht aufgezeichnet')) ?></code></dd>
                <?php if ($finding->getPayload() !== null && $finding->getPayload() !== ''): ?><dt>Payload</dt><dd><code><?= $escape($finding->getPayload()) ?></code></dd><?php endif; ?>
                <dt><?= $escape($t('Eingetragen')) ?></dt><dd><?= $escape($formatTime($finding->getSubmittedAt())) ?></dd>
                <dt><?= $escape($t('Typ')) ?></dt><dd><?= $escape($finding->getType()) ?></dd>
                <dt><?= $escape($t('HTTP-Methode')) ?></dt><dd><?= $escape($finding->getMethod()) ?></dd>
                <?php if ($finding->getLastRetestedAt() !== null): ?><dt><?= $escape($t('Letzte Prüfung')) ?></dt><dd><?= $escape($formatTime($finding->getLastRetestedAt())) ?></dd><?php endif; ?>
              </dl>
              <?php if ($finding->getRequestParams() !== null): ?><pre><?= $escape(json_encode($finding->getRequestParams(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            </details>
          </div>
        </section>

        <aside class="studio-inspector" id="entscheidung" aria-labelledby="studio-decision-title" tabindex="-1">
          <section class="studio-inspector-section">
            <p class="studio-eyebrow"><?= $escape($t('Inspector')) ?></p>
            <h2 id="studio-decision-title"><?= $escape($t('Bewertung')) ?></h2>
            <p class="studio-assessment-value" data-assessment="<?= $escape($assessment ?? 'unknown') ?>"><?= $escape($t(FindingReadLabels::assessment($assessment, $finding->getDiscardReason()))) ?></p>
            <?php if ($assessment !== null): ?>
              <p class="studio-detail-hint"><?= $escape($t('Manuell')) ?> · <?= $escape($formatTime($finding->getAssessedAt())) ?></p>
            <?php elseif ($finding->getStatus() !== 'new' || $finding->getReviewState() !== null): ?>
              <p class="studio-detail-hint"><?= $escape($t('Historischer Bestand · Herkunft und Entscheidungsgrundlage unbekannt. Status: {status} · Review: {review}.', ['status' => $finding->getStatus(), 'review' => $finding->getReviewState() ?? $t('unbekannt')])) ?></p>
            <?php endif; ?>
            <?php if ($finding->isDiscarded()): ?><p class="studio-detail-hint"><?= $escape($t('Im normalen Arbeiten ignoriert. Neue Beobachtungen reaktivieren diesen Fall nicht.')) ?></p><?php endif; ?>

            <div class="studio-observation">
              <h3><?= $escape($t('Technische Beobachtung')) ?></h3>
              <p><?= $escape($t(FindingReadLabels::observation($latestRun?->getResult()))) ?></p>
              <?php if ($latestRun !== null): ?><span class="studio-detail-muted"><?= $escape($formatTime($state->latestAt)) ?> · <?= $escape($latestRun->getMode()) ?></span><?php endif; ?>
              <?php if ($state->newerObservation): ?><p class="studio-observation-callout" data-review-detail-notice><?= $escape($t('Neue Widersprüche, Unklarheiten oder Fehler sind noch nicht gesichtet. Die Bewertung bleibt erhalten.')) ?> <a href="/review?kind=changed&amp;images=all"><?= $escape($t('Neue Hinweise im Review prüfen ↗')) ?></a></p><?php endif; ?>
              <?php if ($state->needsConfirmation && !$state->newerObservation): ?><p class="studio-observation-callout"><?= $escape($t('Manuelle Beurteilung erforderlich. Ein uneindeutiges Ergebnis bedeutet keine Behebung.')) ?></p><?php endif; ?>
            </div>

            <form method="post" action="<?= $escape($findingPath) ?>/assessment" class="studio-assessment-form" id="assessment-form">
              <?= $csrfField('finding_assessment_'.$finding->getId()) ?>
              <?= $returnField ?>
              <details class="studio-detail-fold studio-basis">
                <summary><?= $escape($t('Bewertungsgrundlage')) ?> <span><?= $escape($t('Optional')) ?></span></summary>
                <p class="studio-detail-hint"><?= $escape($t('Nur auswählen, wenn du diesen Beleg oder diese Beobachtung beurteilt hast. Ohne Auswahl bleibt die Grundlage unbekannt.')) ?></p>
                <label for="studio-observation-basis"><?= $escape($t('Beurteilte Beobachtung')) ?></label>
                <select name="observation_id" id="studio-observation-basis">
                  <option value=""><?= $escape($t('Unbekannt / keine konkrete Beobachtung')) ?></option>
                  <?php foreach ($view->runs as $run): ?><option value="<?= $escape($run->getId()) ?>"><?= $escape($formatTime($run->getFinishedAt() ?? $run->getStartedAt()).' · '.$t(FindingReadLabels::observation($run->getResult())).' · '.substr($run->getId(), 0, 8)) ?></option><?php endforeach; ?>
                </select>
                <label for="studio-evidence-basis"><?= $escape($t('Beurteilter Beleg')) ?></label>
                <select name="evidence_id" id="studio-evidence-basis">
                  <option value=""><?= $escape($t('Unbekannt / kein konkreter Beleg')) ?></option>
                  <?php foreach ($view->evidence as $item): ?><option value="<?= $escape($item->getId()) ?>"><?= $escape($formatTime($item->getCreatedAt()).' · '.$item->getKind().' · '.substr($item->getId(), 0, 8)) ?></option><?php endforeach; ?>
                </select>
              </details>
              <div class="studio-assessment-actions">
                <?php if ($state->canConfirm): ?><button class="studio-detail-button studio-detail-button-primary" type="submit" name="assessment" value="confirmed"><?= $escape($t('Bestätigen')) ?></button><?php endif; ?>
                <?php if ($state->canMarkFixed): ?><button class="studio-detail-button" type="submit" name="assessment" value="fixed"><?= $escape($t('Behoben')) ?></button><?php endif; ?>
              </div>
              <?php if ($state->canDiscard): ?>
                <details class="studio-detail-fold studio-discard">
                  <summary><?= $escape($t('Verwerfen')) ?></summary>
                  <label for="studio-discard-reason"><?= $escape($t('Grund')) ?></label>
                  <select name="discard_reason" id="studio-discard-reason"><option value=""><?= $escape($t('Ohne besonderen Grund')) ?></option><option value="duplicate"><?= $escape($t('Duplikat')) ?></option></select>
                  <p class="studio-detail-hint"><?= $escape($t('Der Fall bleibt erhalten und wird im normalen Arbeiten ignoriert.')) ?></p>
                  <button class="studio-detail-button studio-detail-button-discard" type="submit" name="assessment" value="discarded"><?= $escape($t('Verwerfen speichern')) ?></button>
                </details>
              <?php endif; ?>
            </form>
          </section>

          <section class="studio-inspector-section" aria-labelledby="studio-note-title">
            <h2 id="studio-note-title"><?= $escape($t('Notiz')) ?></h2>
            <form method="post" action="<?= $escape($findingPath) ?>/notes" data-studio-notes>
              <?= $csrfField('finding_notes_'.$finding->getId()) ?>
              <?= $returnField ?>
              <label class="studio-sr-only" for="studio-case-notes"><?= $escape($t('Notiz zu diesem Fall')) ?></label>
              <textarea id="studio-case-notes" name="notes" rows="4" placeholder="<?= $escape($t('Deine Notiz zu diesem Fall')) ?>"><?= $escape($finding->getPrivateNotes() ?? '') ?></textarea>
              <div class="studio-note-actions"><span class="studio-detail-muted" data-note-state role="status"><?= $escape($t('Explizit speichern')) ?></span><button class="studio-detail-button" type="submit"><?= $escape($t('Notiz speichern')) ?></button></div>
            </form>
          </section>

          <section class="studio-inspector-section" aria-labelledby="studio-contact-title">
            <div class="studio-source-heading"><h2 id="studio-contact-title"><?= $escape($t('Kontakt')) ?></h2><span class="studio-status" data-tone="<?= $finding->getContactedAt() !== null ? 'success' : 'neutral' ?>"><?= $escape($t(FindingReadLabels::contact($finding->getContactedAt()))) ?></span></div>
            <?php if ($finding->getContactedAt() !== null): ?>
              <p class="studio-detail-hint"><?= $escape($formatTime($finding->getContactedAt())) ?></p>
            <?php else: ?>
              <p class="studio-detail-hint"><?= $escape($t('Den Zeitpunkt eines bereits erfolgten Kontakts festhalten.')) ?></p>
              <form method="post" action="<?= $escape($findingPath) ?>/mark-contacted">
                <?= $csrfField('finding_mark_contacted_'.$finding->getId()) ?>
                <?= $returnField ?>
                <button class="studio-detail-button" type="submit"><?= $escape($t('Als kontaktiert markieren')) ?></button>
              </form>
            <?php endif; ?>
          </section>
          <section class="studio-inspector-section" aria-labelledby="studio-sent-title">
            <div class="studio-source-heading"><h2 id="studio-sent-title"><?= $escape($t('Meldung versendet')) ?></h2><span class="studio-status" data-tone="<?= $finding->getNotifiedOwnerAt() !== null ? 'success' : 'neutral' ?>"><?= $escape($t($finding->getNotifiedOwnerAt() !== null ? 'Versendet' : 'Noch kein Versand erfasst')) ?></span></div>
            <?php if ($finding->getNotifiedOwnerAt() !== null): ?>
              <p class="studio-detail-hint"><time datetime="<?= $escape($finding->getNotifiedOwnerAt()->format(DATE_ATOM)) ?>"><?= $escape($formatTime($finding->getNotifiedOwnerAt())) ?></time></p>
            <?php else: ?>
              <p class="studio-detail-hint"><?= $escape($t('Eine bereits an den Betreiber verschickte Meldung festhalten.')) ?></p>
              <form method="post" action="<?= $escape($findingPath) ?>/mark-sent" data-studio-sent>
                <?= $csrfField('finding_mark_sent_'.$finding->getId()) ?>
                <?= $returnField ?>
                <button class="studio-detail-button" type="submit"><?= $escape($t('Als versendet markieren')) ?></button>
              </form>
            <?php endif; ?>
          </section>
          <?php if (!$finding->isDiscarded()): ?>
            <section class="studio-inspector-section" aria-labelledby="studio-technical-actions-title">
              <h2 id="studio-technical-actions-title"><?= $escape($t('Technische Aktionen')) ?></h2>
              <div class="studio-technical-actions">
                <form method="post" action="<?= $escape($findingPath) ?>/screenshots" data-studio-screenshot-action aria-describedby="studio-screenshot-action-hint">
                  <?= $returnField ?>
                  <button class="studio-detail-button" type="submit"><?= $escape($t('Screenshot einreihen')) ?></button>
                  <p class="studio-detail-hint" id="studio-screenshot-action-hint"><?= $escape($t('Reiht eine neue Aufnahme im Hintergrund ein. Die Bewertung bleibt unverändert.')) ?></p>
                </form>
                <form method="post" action="<?= $escape($findingPath) ?>/retest" data-studio-retest-action aria-describedby="studio-retest-action-hint">
                  <?= $returnField ?>
                  <button class="studio-detail-button studio-detail-button-primary" type="submit"><?= $escape($t('Ziel technisch erneut prüfen')) ?></button>
                  <p class="studio-detail-hint" id="studio-retest-action-hint"><?= $escape($t('Ruft die gemeldete URL jetzt aktiv im Browser auf und speichert das technische Ergebnis. Dabei entsteht kein Screenshot.')) ?></p>
                </form>
              </div>
            </section>
          <?php endif; ?>
          <section class="studio-inspector-section" aria-labelledby="studio-maintenance-title">
            <h2 id="studio-maintenance-title"><?= $escape($t('Fallverwaltung')) ?></h2>
            <details class="studio-detail-fold studio-delete" data-studio-delete-section>
              <summary><?= $escape($t('Fall endgültig löschen')) ?></summary>
              <p class="studio-detail-hint"><?= $escape($t('Dieser Fall, seine Bewertungen, technischen Beobachtungen, Screenshot-Aufträge und Belege werden dauerhaft entfernt.')) ?></p>
              <form method="post" action="<?= $escape($findingPath) ?>/delete" data-studio-delete>
                <?= $returnField ?>
                <label class="studio-delete-confirmation" for="studio-delete-confirmation">
                  <input id="studio-delete-confirmation" type="checkbox" name="confirm_delete" value="1" required>
                  <span><?= $escape($t('Diesen Fall und sämtliche Belege endgültig löschen.')) ?></span>
                </label>
                <button class="studio-detail-button studio-detail-button-danger" type="submit"><?= $escape($t('Endgültig löschen')) ?></button>
              </form>
            </details>
          </section>
        </aside>
      </div>

      <section class="studio-technical-section" id="verlauf" aria-label="<?= $escape($t('Technik und Historie')) ?>" tabindex="-1">
        <details class="studio-technical-history" data-studio-history>
          <summary><span><?= $escape($t('Technik & Historie')) ?></span><span class="studio-detail-muted"><?= $escape($t('{count} Bewertungen', ['count' => $formatNumber(count($view->assessments))])) ?><?= ($view->reviewAcknowledgements ?? []) !== [] ? ' · '.$escape($t('{count} Sichtungen', ['count' => $formatNumber(count($view->reviewAcknowledgements))])) : '' ?> · <?= $escape($t('{count} Aufnahmen', ['count' => $formatNumber(count($view->screenshotJobs))])) ?> · <?= $escape($t('{count} Beobachtungen', ['count' => $formatNumber(count($view->runs))])) ?></span></summary>
          <div class="studio-history-sections">
            <section aria-labelledby="studio-assessment-history-title">
              <h2 id="studio-assessment-history-title"><?= $escape($t('Bewertungshistorie')) ?></h2>
              <?php if ($view->assessments === []): ?><p class="studio-detail-hint"><?= $escape($t('Noch keine Bewertungsänderung aufgezeichnet.')) ?></p><?php endif; ?>
              <ol class="studio-record-list">
                <?php foreach ($view->assessments as $entry): ?>
                  <?php $snapshot = $entry->getReferenceSnapshot() ?? []; ?>
                  <li class="studio-record">
                    <div class="studio-record-heading"><strong><?= $escape($t(FindingReadLabels::assessment($entry->getAssessment(), $entry->getDiscardReason()))) ?></strong><span><?= $escape($formatTime($entry->getAssessedAt())) ?></span></div>
                    <p class="studio-detail-hint"><?= $escape($t('Herkunft')) ?>: <?= $escape($entry->getSource() === 'manual' ? $t('Manuell') : $entry->getSource()) ?></p>
                    <dl class="studio-record-data">
                      <dt><?= $escape($t('Beobachtungsgrundlage')) ?></dt><dd><?= $entry->getObservationId() !== null ? '<code>'.$escape($entry->getObservationId()).'</code>' : $escape($t('Unbekannt / keine konkrete Beobachtung')) ?>
                        <?php if (isset($snapshot['observation'])): ?><br><?= $escape($storedTime($snapshot['observation']['finishedAt'] ?? $snapshot['observation']['startedAt'] ?? null).' · '.$t(FindingReadLabels::observation($snapshot['observation']['result'] ?? null)).' · '.($snapshot['observation']['mode'] ?? $t('Herkunft unbekannt'))) ?><?php endif; ?>
                      </dd>
                      <dt><?= $escape($t('Beleggrundlage')) ?></dt><dd><?= $entry->getEvidenceId() !== null ? '<code>'.$escape($entry->getEvidenceId()).'</code>' : $escape($t('Unbekannt / kein konkreter Beleg')) ?>
                        <?php if (isset($snapshot['evidence'])): ?><br><?= $escape($t('Ablage')) ?>: <?= $escape($storedTime($snapshot['evidence']['storedAt'] ?? null).' · '.($snapshot['evidence']['kind'] ?? $t('Art unbekannt'))) ?><?php endif; ?>
                      </dd>
                    </dl>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
            <?php if (($view->reviewAcknowledgements ?? []) !== []): ?>
              <section aria-labelledby="studio-review-history-title">
                <h2 id="studio-review-history-title"><?= $escape($t('Gesichtete Hinweise')) ?></h2>
                <ol class="studio-record-list">
                  <?php foreach ($view->reviewAcknowledgements as $entry): ?>
                    <?php $snapshot = $entry->getReferenceSnapshot() ?? []; ?>
                    <li class="studio-record" data-review-acknowledgement="<?= $escape($entry->getId()) ?>">
                      <div class="studio-record-heading"><strong><?= $escape($t('Geprüft · Bewertung behalten')) ?></strong><span><?= $escape($formatTime($entry->getReviewedAt())) ?></span></div>
                      <p class="studio-detail-hint"><?= $escape($t('Beibehaltenes Urteil: {assessment}. Das Bewertungsdatum wurde nicht geändert.', ['assessment' => $t(FindingReadLabels::assessment($entry->getAssessment(), null))])) ?></p>
                      <dl class="studio-record-data">
                        <dt><?= $escape($t('Gesichtete Hinweise')) ?></dt><dd><?php foreach ($entry->getTriggeringObservationIds() as $triggerId): ?><code><?= $escape($triggerId) ?></code><br><?php endforeach; ?></dd>
                        <dt><?= $escape($t('Beobachtungsgrundlage')) ?></dt><dd><?= $entry->getObservationId() !== null ? '<code>'.$escape($entry->getObservationId()).'</code>' : $escape($t('Unbekannt / keine konkrete Beobachtung')) ?><?php if (isset($snapshot['observation'])): ?><br><?= $escape($storedTime($snapshot['observation']['finishedAt'] ?? $snapshot['observation']['startedAt'] ?? null).' · '.$t(FindingReadLabels::observation($snapshot['observation']['result'] ?? null)).' · '.($snapshot['observation']['mode'] ?? $t('Herkunft unbekannt'))) ?><?php endif; ?></dd>
                        <dt><?= $escape($t('Beleggrundlage')) ?></dt><dd><?= $entry->getEvidenceId() !== null ? '<code>'.$escape($entry->getEvidenceId()).'</code>' : $escape($t('Unbekannt / kein konkreter Beleg')) ?><?php if (isset($snapshot['evidence'])): ?><br><?= $escape($t('Ablage')) ?>: <?= $escape($storedTime($snapshot['evidence']['storedAt'] ?? null).' · '.($snapshot['evidence']['kind'] ?? $t('Art unbekannt'))) ?><?php endif; ?></dd>
                      </dl>
                    </li>
                  <?php endforeach; ?>
                </ol>
              </section>
            <?php endif; ?>
            <section aria-labelledby="studio-job-history-title">
              <h2 id="studio-job-history-title"><?= $escape($t('Screenshot-Aufträge')) ?></h2>
              <?php if ($view->screenshotJobs === []): ?><p class="studio-detail-hint"><?= $escape($t('Noch kein Auftrag aufgezeichnet.')) ?></p><?php endif; ?>
              <ol class="studio-record-list">
                <?php foreach ($view->screenshotJobs as $job): ?>
                  <li class="studio-record">
                    <div class="studio-record-heading"><strong class="studio-status" data-tone="<?= $escape($jobTone($job->getStatus())) ?>"><?= $escape($jobLabel($job->getStatus())) ?></strong><code><?= $escape($job->getId()) ?></code></div>
                    <dl class="studio-record-data">
                      <dt><?= $escape($t('Angefordert')) ?></dt><dd><?= $escape($formatTime($job->getRequestedAt())) ?></dd>
                      <dt><?= $escape($t('Begonnen')) ?></dt><dd><?= $escape($formatTime($job->getStartedAt())) ?></dd>
                      <dt><?= $escape($t('Aufgenommen')) ?></dt><dd><?= $escape($formatTime($job->getCapturedAt())) ?></dd>
                      <dt><?= $escape($t('Abgeschlossen')) ?></dt><dd><?= $escape($formatTime($job->getFinishedAt())) ?></dd>
                      <dt><?= $escape($t('Versuche')) ?></dt><dd><?= $escape($formatNumber($job->getAttempts())) ?></dd>
                      <?php if ($job->getScreenshotPath() !== null): ?><dt><?= $escape($t('Datei')) ?></dt><dd><code><?= $escape($job->getScreenshotPath()) ?></code></dd><?php endif; ?>
                    </dl>
                    <?php if ($job->getErrorMessage() !== null): ?><p class="studio-record-error"><?= $escape($job->getErrorMessage()) ?></p><?php endif; ?>
                    <?php $metadata = $job->getCaptureMetadata() ?? []; ?>
                    <?php if (($metadata['challengeDetected'] ?? false) === true): ?><p class="studio-challenge-notice"><?= $escape($t('Browser-Schutz erkannt · {state} · {seconds} Sekunden gewartet.', ['state' => $t(($metadata['challengeCleared'] ?? false) === true ? 'beendet' : 'weiterhin aktiv'), 'seconds' => $formatNumber(max(0, (int) ($metadata['challengeWaitedMs'] ?? 0)) / 1000, 1)])) ?></p><?php endif; ?>
                    <?php if ($metadata !== []): ?><details class="studio-detail-fold"><summary><?= $escape($t('Aufnahmedetails')) ?></summary><pre><?= $escape(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
            <section aria-labelledby="studio-evidence-history-title">
              <h2 id="studio-evidence-history-title"><?= $escape($t('Alle Belege')) ?></h2>
              <?php if ($view->evidence === []): ?><p class="studio-detail-hint"><?= $escape($t('Noch kein Beleg aufgezeichnet.')) ?></p><?php endif; ?>
              <ol class="studio-record-list">
                <?php foreach ($view->evidence as $item): ?>
                  <li class="studio-record">
                    <div class="studio-record-heading"><strong><?= $escape($item->getKind()) ?></strong><span><?= $escape($t('Ablage')) ?>: <?= $escape($formatTime($item->getCreatedAt())) ?></span></div>
                    <p><code><?= $escape($item->getId()) ?></code></p>
                    <?php if ($item->getValue() !== null): ?><pre><?= $escape($item->getValue()) ?></pre><?php endif; ?>
                    <?php if ($item->getFilePath() !== null): ?><p class="studio-detail-hint"><?= $escape($t('Datei')) ?>: <code><?= $escape($item->getFilePath()) ?></code></p><?php endif; ?>
                    <?php foreach ($view->screenshots as $shot): ?><?php if ($shot['evidence']->getId() === $item->getId() && !$shot['available']): ?><p class="studio-record-error"><?= $escape($t('Datei fehlt oder ist nicht verfügbar; Beleg erhalten.')) ?></p><?php endif; ?><?php endforeach; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
            <section aria-labelledby="studio-observation-history-title">
              <h2 id="studio-observation-history-title"><?= $escape($t('Technische Beobachtungen')) ?></h2>
              <?php if ($view->runs === []): ?><p class="studio-detail-hint"><?= $escape($t('Noch keine technische Beobachtung aufgezeichnet.')) ?></p><?php endif; ?>
              <ol class="studio-record-list">
                <?php foreach ($view->runs as $run): ?>
                  <li class="studio-record">
                    <div class="studio-record-heading"><strong><?= $escape($t(FindingReadLabels::observation($run->getResult()))) ?></strong><code><?= $escape($run->getId()) ?></code></div>
                    <dl class="studio-record-data"><dt><?= $escape($t('Modus')) ?></dt><dd><?= $escape($run->getMode()) ?></dd><dt><?= $escape($t('Begonnen')) ?></dt><dd><?= $escape($formatTime($run->getStartedAt())) ?></dd><dt><?= $escape($t('Abgeschlossen')) ?></dt><dd><?= $escape($formatTime($run->getFinishedAt())) ?></dd><dt>HTTP</dt><dd><?= $escape($run->getHttpStatus() !== null ? (string) $run->getHttpStatus() : $t('Nicht aufgezeichnet')) ?></dd></dl>
                    <?php if ($run->getFinalUrl() !== null): ?><p class="studio-detail-hint"><?= $escape($t('Letzte URL')) ?>: <code><?= $escape($run->getFinalUrl()) ?></code></p><?php endif; ?>
                    <?php if ($run->getObservedEvidence() !== null): ?><pre><?= $escape($run->getObservedEvidence()) ?></pre><?php endif; ?>
                    <?php if ($run->getScreenshotPath() !== null): ?><p class="studio-detail-hint"><?= $escape($t('Aufnahmedatei')) ?>: <code><?= $escape($run->getScreenshotPath()) ?></code></p><?php endif; ?>
                    <?php if ($run->getErrorMessage() !== null): ?><p class="studio-record-error"><?= $escape($run->getErrorMessage()) ?></p><?php endif; ?>
                    <?php if ($run->getRawResult() !== null): ?><details class="studio-detail-fold"><summary><?= $escape($t('Laufdetails')) ?></summary><pre><?= $escape(json_encode($run->getRawResult(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
          </div>
        </details>
      </section>
    </main>

    <?php $activeWorkspace = 'finding'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
</body>
</html>
