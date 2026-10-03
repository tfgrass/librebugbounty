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
$formatTime = static fn (?\DateTimeImmutable $at): string => $at === null
    ? 'Zeitpunkt unbekannt'
    : $at->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y · H:i:s T');
$jobLabel = static fn (string $status): string => match ($status) {
    'queued' => 'Vorgemerkt',
    'running' => 'Aufnahme läuft',
    'available' => 'Aufnahme abgeschlossen',
    'failed' => 'Aufnahme fehlgeschlagen',
    default => $status,
};
$jobTone = static fn (string $status): string => match ($status) {
    'queued', 'running' => 'pending',
    'available' => 'success',
    'failed' => 'error',
    default => 'neutral',
};
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($finding->getDomain()->getHostname()) ?> · Fall · LibreBugBounty Studio</title>
  <link rel="stylesheet" href="/css/studio.css">
  <link rel="stylesheet" href="/css/studio-detail.css">
  <script src="/js/studio-detail.js" defer></script>
</head>
<body data-studio data-studio-detail>
  <div class="studio-shell studio-detail-shell">
    <header class="studio-header">
      <a class="studio-brand" href="/" aria-label="LibreBugBounty Studio, Eingang">
        <svg class="studio-brand-mark" width="27" height="27" viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M14 2.5 24 8.3v11.4l-10 5.8-10-5.8V8.3L14 2.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M10 11h8v7a4 4 0 0 1-8 0v-7Zm2-3h4v3h-4V8Zm2 4v10M7 13h3m8 0h3M7 17h3m8 0h3m-10 5 2-2m5 0 2 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="studio-brand-name">LibreBugBounty</span>
        <span class="studio-brand-tag">STUDIO</span>
      </a>
      <a class="studio-classic-link" href="<?= $escape('/legacy'.$findingPath) ?>">Fall klassisch <span aria-hidden="true">↗</span></a>
    </header>

    <main class="studio-detail-workspace" id="studio-detail-main">
      <div class="studio-case-heading">
        <div class="studio-case-title">
          <p class="studio-eyebrow">FALL · <?= $escape(substr($finding->getId(), 0, 8)) ?></p>
          <h1><?= $escape($finding->getDomain()->getHostname()) ?></h1>
          <p class="studio-case-subtitle"><?= $escape($finding->getTitle()) ?></p>
        </div>
        <a class="studio-detail-back" href="<?= $escape($returnPath ?? '/findings') ?>"><span aria-hidden="true">←</span> Zum Bestand</a>
      </div>

      <?php if ($message !== null && $message !== ''): ?>
        <p class="studio-detail-feedback" data-tone="success" role="status"><?= $escape($message) ?></p>
      <?php endif; ?>
      <?php if ($error !== null && $error !== ''): ?>
        <p class="studio-detail-feedback" data-tone="error" role="alert"><?= $escape($error) ?></p>
      <?php endif; ?>

      <nav class="studio-detail-sections" aria-label="Bereiche dieses Falls">
        <a href="#beleg">Beleg</a>
        <a href="#entscheidung">Entscheidung</a>
        <a href="#verlauf" data-open-history>Verlauf <span><?= count($view->assessments) + count($view->screenshotJobs) + count($view->runs) ?></span></a>
      </nav>

      <div class="studio-case-layout">
        <section class="studio-evidence-workspace" id="beleg" aria-labelledby="studio-evidence-title" tabindex="-1">
          <div class="studio-section-heading">
            <h2 id="studio-evidence-title">Beleg</h2>
            <span class="studio-detail-muted"><?= count($view->screenshots) ?> Bild<?= count($view->screenshots) === 1 ? '' : 'er' ?></span>
          </div>

          <div class="studio-capture-state" data-tone="<?= $escape($latestJob !== null ? $jobTone($latestJob->getStatus()) : 'neutral') ?>">
            <span class="studio-status" data-tone="<?= $escape($latestJob !== null ? $jobTone($latestJob->getStatus()) : 'neutral') ?>"><?= $escape($latestJob !== null ? 'Letzter Screenshot · '.$jobLabel($latestJob->getStatus()) : 'Noch kein Screenshot-Auftrag') ?></span>
            <?php if ($latestJob !== null): ?>
              <span class="studio-detail-muted"><?= $escape($formatTime($latestJob->getRequestedAt())) ?></span>
              <?php if ($latestJob->getStatus() === 'failed'): ?>
                <p><?= $escape($latestJob->getErrorMessage() ?? 'Die Aufnahme konnte nicht abgeschlossen werden.') ?></p>
              <?php elseif (in_array($latestJob->getStatus(), ['queued', 'running'], true)): ?>
                <p>Der Auftrag läuft separat. Vorhandene Bilder bleiben frühere Belege.</p>
              <?php endif; ?>
              <?php $latestMetadata = $latestJob->getCaptureMetadata() ?? []; ?>
              <?php if (($latestMetadata['challengeDetected'] ?? false) === true): ?>
                <p class="studio-challenge-notice"><?= ($latestMetadata['challengeCleared'] ?? false) === true ? 'Browser-Schutz erkannt und beendet; anschließend wurde die Zielseite beobachtet.' : 'Browser-Schutz blieb aktiv. Ein Bild kann die Schutzseite zeigen.' ?></p>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <?php if ($view->screenshots === []): ?>
            <div class="studio-evidence-empty">
              <svg width="42" height="42" viewBox="0 0 48 48" fill="none" aria-hidden="true"><rect x="7" y="8" width="34" height="31" rx="3" stroke="currentColor" stroke-width="1.4"/><circle cx="17" cy="18" r="3" stroke="currentColor" stroke-width="1.4"/><path d="m8 34 10-10 8 7 6-6 9 9" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
              <h3>Noch kein Bildbeleg</h3>
              <p><?= $latestJob !== null && in_array($latestJob->getStatus(), ['queued', 'running'], true) ? 'Die Aufnahme entsteht im Hintergrund. Nach Abschluss kannst du die Seite aktualisieren.' : 'Aufnahmezustand und vorhandene technische Belege findest du im Verlauf.' ?></p>
            </div>
          <?php else: ?>
            <?php if (count($view->screenshots) > 1): ?>
              <nav class="studio-shot-picker" aria-label="Bildbeleg auswählen">
                <?php foreach ($view->screenshots as $index => $shot): ?>
                  <a href="#bild-<?= $escape($shot['evidence']->getId()) ?>" data-shot-select="<?= $escape($shot['evidence']->getId()) ?>">
                    <span>Bild <?= $index + 1 ?></span>
                    <small><?= $escape($shot['capturedAt'] !== null ? $formatTime($shot['capturedAt']) : 'Aufnahmezeit unbekannt') ?></small>
                    <?php if (!$shot['available']): ?><small class="studio-detail-error">Datei nicht verfügbar</small><?php endif; ?>
                  </a>
                <?php endforeach; ?>
              </nav>
            <?php endif; ?>
            <div class="studio-shot-stage" data-shot-stage>
              <?php foreach ($view->screenshots as $index => $shot): ?>
                <?php $localImage = $shot['available'] && is_string($shot['url']) && str_starts_with($shot['url'], '/artifacts/'); ?>
                <figure class="studio-shot" id="bild-<?= $escape($shot['evidence']->getId()) ?>" data-shot-id="<?= $escape($shot['evidence']->getId()) ?>" tabindex="-1">
                  <?php if ($localImage): ?>
                    <a class="studio-shot-image-link" href="<?= $escape($shot['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="Bild <?= $index + 1 ?> in Originalgröße öffnen">
                      <img src="<?= $escape($shot['url']) ?>" alt="Gespeicherter Screenshot <?= $index + 1 ?> für <?= $escape($finding->getDomain()->getHostname()) ?>" loading="<?= $index === 0 ? 'eager' : 'lazy' ?>" decoding="async">
                    </a>
                  <?php else: ?>
                    <div class="studio-shot-missing">
                      <span aria-hidden="true">⊘</span>
                      <h3>Bilddatei nicht verfügbar</h3>
                      <p>Der Beleg ist aufgezeichnet. Die zugehörige Datei fehlt oder kann nicht gelesen werden.</p>
                    </div>
                  <?php endif; ?>
                  <figcaption>
                    <div>
                      <strong>Bild <?= $index + 1 ?></strong>
                      <span>Aufnahme: <?= $escape($shot['capturedAt'] !== null ? $formatTime($shot['capturedAt']) : 'unbekannt') ?></span>
                      <span>Ablage: <?= $escape($formatTime($shot['evidence']->getCreatedAt())) ?></span>
                    </div>
                    <?php if ($localImage): ?><a class="studio-detail-text-link" href="<?= $escape($shot['url']) ?>" target="_blank" rel="noopener noreferrer">Original öffnen <span aria-hidden="true">↗</span></a><?php endif; ?>
                  </figcaption>
                </figure>
              <?php endforeach; ?>
            </div>
            <p class="studio-detail-hint">Ein Bildbeleg ist eine Aufnahme. Die manuelle Bewertung steht im Inspector.</p>
          <?php endif; ?>

          <div class="studio-case-source">
            <div class="studio-source-heading"><h3>Gemeldete URL</h3><button type="button" class="studio-copy-button" data-copy-target="studio-finding-url" hidden>URL kopieren</button></div>
            <code id="studio-finding-url"><?= $escape($finding->getUrl()) ?></code>
            <p class="studio-copy-status" role="status" aria-live="polite" data-copy-status></p>
            <details class="studio-detail-fold">
              <summary>Kennzeichen &amp; Eingabedaten</summary>
              <dl class="studio-record-data">
                <dt>Kennzeichen</dt><dd><code><?= $escape($finding->getExpectedEvidence() ?? 'Nicht aufgezeichnet') ?></code></dd>
                <?php if ($finding->getPayload() !== null && $finding->getPayload() !== ''): ?><dt>Payload</dt><dd><code><?= $escape($finding->getPayload()) ?></code></dd><?php endif; ?>
                <dt>Eingetragen</dt><dd><?= $escape($formatTime($finding->getSubmittedAt())) ?></dd>
                <dt>Typ</dt><dd><?= $escape($finding->getType()) ?></dd>
                <dt>HTTP-Methode</dt><dd><?= $escape($finding->getMethod()) ?></dd>
                <?php if ($finding->getLastRetestedAt() !== null): ?><dt>Letzte Prüfung</dt><dd><?= $escape($formatTime($finding->getLastRetestedAt())) ?></dd><?php endif; ?>
              </dl>
              <?php if ($finding->getRequestParams() !== null): ?><pre><?= $escape(json_encode($finding->getRequestParams(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            </details>
          </div>
        </section>

        <aside class="studio-inspector" id="entscheidung" aria-labelledby="studio-decision-title" tabindex="-1">
          <section class="studio-inspector-section">
            <p class="studio-eyebrow">INSPECTOR</p>
            <h2 id="studio-decision-title">Bewertung</h2>
            <p class="studio-assessment-value" data-assessment="<?= $escape($assessment ?? 'unknown') ?>"><?= $escape(FindingReadLabels::assessment($assessment, $finding->getDiscardReason())) ?></p>
            <?php if ($assessment !== null): ?>
              <p class="studio-detail-hint">Manuell · <?= $escape($formatTime($finding->getAssessedAt())) ?></p>
            <?php elseif ($finding->getStatus() !== 'new' || $finding->getReviewState() !== null): ?>
              <p class="studio-detail-hint">Altbestand · Herkunft und Entscheidungsgrundlage unbekannt. Status: <?= $escape($finding->getStatus()) ?> · Review: <?= $escape($finding->getReviewState() ?? 'unbekannt') ?>.</p>
            <?php endif; ?>
            <?php if ($finding->isDiscarded()): ?><p class="studio-detail-hint">Im normalen Arbeiten ignoriert. Neue Beobachtungen reaktivieren diesen Fall nicht.</p><?php endif; ?>

            <div class="studio-observation">
              <h3>Technische Beobachtung</h3>
              <p><?= $escape(FindingReadLabels::observation($latestRun?->getResult())) ?></p>
              <?php if ($latestRun !== null): ?><span class="studio-detail-muted"><?= $escape($formatTime($state->latestAt)) ?> · <?= $escape($latestRun->getMode()) ?></span><?php endif; ?>
              <?php if ($state->newerObservation): ?><p class="studio-observation-callout">Neue technische Beobachtung seit deiner Bewertung. Die Bewertung bleibt erhalten.</p><?php endif; ?>
              <?php if ($state->needsConfirmation): ?><p class="studio-observation-callout">Manuelle Beurteilung erforderlich. Ein uneindeutiges Ergebnis bedeutet keine Behebung.</p><?php endif; ?>
            </div>

            <form method="post" action="<?= $escape($findingPath) ?>/assessment" class="studio-assessment-form" id="assessment-form">
              <?= $csrfField('finding_assessment_'.$finding->getId()) ?>
              <input type="hidden" name="surface" value="studio">
              <?= $returnField ?>
              <details class="studio-detail-fold studio-basis">
                <summary>Bewertungsgrundlage <span>optional</span></summary>
                <p class="studio-detail-hint">Nur auswählen, wenn du diesen Beleg oder diese Beobachtung beurteilt hast. Ohne Auswahl bleibt die Grundlage unbekannt.</p>
                <label for="studio-observation-basis">Beurteilte Beobachtung</label>
                <select name="observation_id" id="studio-observation-basis">
                  <option value="">Unbekannt / keine konkrete Beobachtung</option>
                  <?php foreach ($view->runs as $run): ?><option value="<?= $escape($run->getId()) ?>"><?= $escape($formatTime($run->getFinishedAt() ?? $run->getStartedAt()).' · '.FindingReadLabels::observation($run->getResult()).' · '.substr($run->getId(), 0, 8)) ?></option><?php endforeach; ?>
                </select>
                <label for="studio-evidence-basis">Beurteilter Beleg</label>
                <select name="evidence_id" id="studio-evidence-basis">
                  <option value="">Unbekannt / kein konkreter Beleg</option>
                  <?php foreach ($view->evidence as $item): ?><option value="<?= $escape($item->getId()) ?>"><?= $escape($formatTime($item->getCreatedAt()).' · '.$item->getKind().' · '.substr($item->getId(), 0, 8)) ?></option><?php endforeach; ?>
                </select>
              </details>
              <div class="studio-assessment-actions">
                <?php if ($state->canConfirm): ?><button class="studio-detail-button studio-detail-button-primary" type="submit" name="assessment" value="confirmed">Bestätigen</button><?php endif; ?>
                <?php if ($state->canMarkFixed): ?><button class="studio-detail-button" type="submit" name="assessment" value="fixed">Behoben</button><?php endif; ?>
              </div>
              <?php if ($state->canDiscard): ?>
                <details class="studio-detail-fold studio-discard">
                  <summary>Verwerfen</summary>
                  <label for="studio-discard-reason">Grund</label>
                  <select name="discard_reason" id="studio-discard-reason"><option value="">Ohne besonderen Grund</option><option value="duplicate">Duplikat</option></select>
                  <p class="studio-detail-hint">Der Fall bleibt erhalten und wird im normalen Arbeiten ignoriert.</p>
                  <button class="studio-detail-button studio-detail-button-discard" type="submit" name="assessment" value="discarded">Verwerfen speichern</button>
                </details>
              <?php endif; ?>
            </form>
          </section>

          <section class="studio-inspector-section" aria-labelledby="studio-note-title">
            <h2 id="studio-note-title">Notiz</h2>
            <form method="post" action="<?= $escape($findingPath) ?>/notes" data-studio-notes>
              <?= $csrfField('finding_notes_'.$finding->getId()) ?>
              <input type="hidden" name="surface" value="studio">
              <?= $returnField ?>
              <label class="studio-sr-only" for="studio-case-notes">Notiz zu diesem Fall</label>
              <textarea id="studio-case-notes" name="notes" rows="4" placeholder="Deine Notiz zu diesem Fall"><?= $escape($finding->getPrivateNotes() ?? '') ?></textarea>
              <div class="studio-note-actions"><span class="studio-detail-muted" data-note-state role="status">Explizit speichern</span><button class="studio-detail-button" type="submit">Notiz speichern</button></div>
            </form>
          </section>

          <section class="studio-inspector-section" aria-labelledby="studio-contact-title">
            <div class="studio-source-heading"><h2 id="studio-contact-title">Kontakt</h2><span class="studio-status" data-tone="<?= $finding->getContactedAt() !== null ? 'success' : 'neutral' ?>"><?= $escape(FindingReadLabels::contact($finding->getContactedAt())) ?></span></div>
            <?php if ($finding->getContactedAt() !== null): ?>
              <p class="studio-detail-hint"><?= $escape($formatTime($finding->getContactedAt())) ?></p>
            <?php else: ?>
              <p class="studio-detail-hint">Den Zeitpunkt eines bereits erfolgten Kontakts festhalten.</p>
              <form method="post" action="<?= $escape($findingPath) ?>/mark-contacted">
                <?= $csrfField('finding_mark_contacted_'.$finding->getId()) ?>
                <input type="hidden" name="surface" value="studio">
                <?= $returnField ?>
                <button class="studio-detail-button" type="submit">Als kontaktiert markieren</button>
              </form>
            <?php endif; ?>
          </section>
          <section class="studio-inspector-section" aria-labelledby="studio-sent-title">
            <div class="studio-source-heading"><h2 id="studio-sent-title">Meldung versendet</h2><span class="studio-status" data-tone="<?= $finding->getNotifiedOwnerAt() !== null ? 'success' : 'neutral' ?>"><?= $finding->getNotifiedOwnerAt() !== null ? 'Versendet' : 'Noch kein Versand erfasst' ?></span></div>
            <?php if ($finding->getNotifiedOwnerAt() !== null): ?>
              <p class="studio-detail-hint"><time datetime="<?= $escape($finding->getNotifiedOwnerAt()->format(DATE_ATOM)) ?>"><?= $escape($formatTime($finding->getNotifiedOwnerAt())) ?></time></p>
            <?php else: ?>
              <p class="studio-detail-hint">Eine bereits an den Betreiber verschickte Meldung festhalten.</p>
              <form method="post" action="<?= $escape($findingPath) ?>/mark-sent" data-studio-sent>
                <?= $csrfField('finding_mark_sent_'.$finding->getId()) ?>
                <input type="hidden" name="surface" value="studio">
                <?= $returnField ?>
                <button class="studio-detail-button" type="submit">Als versendet markieren</button>
              </form>
            <?php endif; ?>
          </section>
        </aside>
      </div>

      <section class="studio-technical-section" id="verlauf" aria-label="Technik und Historie" tabindex="-1">
        <details class="studio-technical-history" data-studio-history>
          <summary><span>Technik &amp; Historie</span><span class="studio-detail-muted"><?= count($view->assessments) ?> Bewertungen · <?= count($view->screenshotJobs) ?> Aufnahmen · <?= count($view->runs) ?> Beobachtungen</span></summary>
          <div class="studio-history-sections">
            <section aria-labelledby="studio-assessment-history-title">
              <h2 id="studio-assessment-history-title">Bewertungshistorie</h2>
              <?php if ($view->assessments === []): ?><p class="studio-detail-hint">Noch keine Bewertungsänderung aufgezeichnet.</p><?php endif; ?>
              <ol class="studio-record-list">
                <?php foreach ($view->assessments as $entry): ?>
                  <?php $snapshot = $entry->getReferenceSnapshot() ?? []; ?>
                  <li class="studio-record">
                    <div class="studio-record-heading"><strong><?= $escape(FindingReadLabels::assessment($entry->getAssessment(), $entry->getDiscardReason())) ?></strong><span><?= $escape($formatTime($entry->getAssessedAt())) ?></span></div>
                    <p class="studio-detail-hint">Herkunft: <?= $escape($entry->getSource() === 'manual' ? 'Manuell' : $entry->getSource()) ?></p>
                    <dl class="studio-record-data">
                      <dt>Beobachtungsgrundlage</dt><dd><?= $entry->getObservationId() !== null ? '<code>'.$escape($entry->getObservationId()).'</code>' : 'Unbekannt / keine konkrete Beobachtung' ?>
                        <?php if (isset($snapshot['observation'])): ?><br><?= $escape(($snapshot['observation']['finishedAt'] ?? $snapshot['observation']['startedAt'] ?? 'Zeitpunkt unbekannt').' · '.($snapshot['observation']['result'] ?? 'Ergebnis unbekannt').' · '.($snapshot['observation']['mode'] ?? 'Herkunft unbekannt')) ?><?php endif; ?>
                      </dd>
                      <dt>Beleggrundlage</dt><dd><?= $entry->getEvidenceId() !== null ? '<code>'.$escape($entry->getEvidenceId()).'</code>' : 'Unbekannt / kein konkreter Beleg' ?>
                        <?php if (isset($snapshot['evidence'])): ?><br>Ablage: <?= $escape(($snapshot['evidence']['storedAt'] ?? 'unbekannt').' · '.($snapshot['evidence']['kind'] ?? 'Art unbekannt')) ?><?php endif; ?>
                      </dd>
                    </dl>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
            <section aria-labelledby="studio-job-history-title">
              <h2 id="studio-job-history-title">Screenshot-Aufträge</h2>
              <?php if ($view->screenshotJobs === []): ?><p class="studio-detail-hint">Noch kein Auftrag aufgezeichnet.</p><?php endif; ?>
              <ol class="studio-record-list">
                <?php foreach ($view->screenshotJobs as $job): ?>
                  <li class="studio-record">
                    <div class="studio-record-heading"><strong class="studio-status" data-tone="<?= $escape($jobTone($job->getStatus())) ?>"><?= $escape($jobLabel($job->getStatus())) ?></strong><code><?= $escape($job->getId()) ?></code></div>
                    <dl class="studio-record-data">
                      <dt>Angefordert</dt><dd><?= $escape($formatTime($job->getRequestedAt())) ?></dd>
                      <dt>Begonnen</dt><dd><?= $escape($formatTime($job->getStartedAt())) ?></dd>
                      <dt>Aufgenommen</dt><dd><?= $escape($formatTime($job->getCapturedAt())) ?></dd>
                      <dt>Abgeschlossen</dt><dd><?= $escape($formatTime($job->getFinishedAt())) ?></dd>
                      <dt>Versuche</dt><dd><?= $job->getAttempts() ?></dd>
                      <?php if ($job->getScreenshotPath() !== null): ?><dt>Datei</dt><dd><code><?= $escape($job->getScreenshotPath()) ?></code></dd><?php endif; ?>
                    </dl>
                    <?php if ($job->getErrorMessage() !== null): ?><p class="studio-record-error"><?= $escape($job->getErrorMessage()) ?></p><?php endif; ?>
                    <?php $metadata = $job->getCaptureMetadata() ?? []; ?>
                    <?php if (($metadata['challengeDetected'] ?? false) === true): ?><p class="studio-challenge-notice">Browser-Schutz erkannt · <?= ($metadata['challengeCleared'] ?? false) === true ? 'beendet' : 'weiterhin aktiv' ?> · <?= $escape(number_format(max(0, (int) ($metadata['challengeWaitedMs'] ?? 0)) / 1000, 1, ',', '.')) ?> Sekunden gewartet.</p><?php endif; ?>
                    <?php if ($metadata !== []): ?><details class="studio-detail-fold"><summary>Aufnahmedetails</summary><pre><?= $escape(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
            <section aria-labelledby="studio-evidence-history-title">
              <h2 id="studio-evidence-history-title">Alle Belege</h2>
              <?php if ($view->evidence === []): ?><p class="studio-detail-hint">Noch kein Beleg aufgezeichnet.</p><?php endif; ?>
              <ol class="studio-record-list">
                <?php foreach ($view->evidence as $item): ?>
                  <li class="studio-record">
                    <div class="studio-record-heading"><strong><?= $escape($item->getKind()) ?></strong><span>Ablage: <?= $escape($formatTime($item->getCreatedAt())) ?></span></div>
                    <p><code><?= $escape($item->getId()) ?></code></p>
                    <?php if ($item->getValue() !== null): ?><pre><?= $escape($item->getValue()) ?></pre><?php endif; ?>
                    <?php if ($item->getFilePath() !== null): ?><p class="studio-detail-hint">Datei: <code><?= $escape($item->getFilePath()) ?></code></p><?php endif; ?>
                    <?php foreach ($view->screenshots as $shot): ?><?php if ($shot['evidence']->getId() === $item->getId() && !$shot['available']): ?><p class="studio-record-error">Datei fehlt oder ist nicht verfügbar; Beleg erhalten.</p><?php endif; ?><?php endforeach; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
            <section aria-labelledby="studio-observation-history-title">
              <h2 id="studio-observation-history-title">Technische Beobachtungen</h2>
              <?php if ($view->runs === []): ?><p class="studio-detail-hint">Noch keine technische Beobachtung aufgezeichnet.</p><?php endif; ?>
              <ol class="studio-record-list">
                <?php foreach ($view->runs as $run): ?>
                  <li class="studio-record">
                    <div class="studio-record-heading"><strong><?= $escape(FindingReadLabels::observation($run->getResult())) ?></strong><code><?= $escape($run->getId()) ?></code></div>
                    <dl class="studio-record-data"><dt>Modus</dt><dd><?= $escape($run->getMode()) ?></dd><dt>Begonnen</dt><dd><?= $escape($formatTime($run->getStartedAt())) ?></dd><dt>Abgeschlossen</dt><dd><?= $escape($formatTime($run->getFinishedAt())) ?></dd><dt>HTTP</dt><dd><?= $escape($run->getHttpStatus() !== null ? (string) $run->getHttpStatus() : 'Nicht aufgezeichnet') ?></dd></dl>
                    <?php if ($run->getFinalUrl() !== null): ?><p class="studio-detail-hint">Letzte URL: <code><?= $escape($run->getFinalUrl()) ?></code></p><?php endif; ?>
                    <?php if ($run->getObservedEvidence() !== null): ?><pre><?= $escape($run->getObservedEvidence()) ?></pre><?php endif; ?>
                    <?php if ($run->getScreenshotPath() !== null): ?><p class="studio-detail-hint">Aufnahmedatei: <code><?= $escape($run->getScreenshotPath()) ?></code></p><?php endif; ?>
                    <?php if ($run->getErrorMessage() !== null): ?><p class="studio-record-error"><?= $escape($run->getErrorMessage()) ?></p><?php endif; ?>
                    <?php if ($run->getRawResult() !== null): ?><details class="studio-detail-fold"><summary>Laufdetails</summary><pre><?= $escape(json_encode($run->getRawResult(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
          </div>
        </details>
      </section>
    </main>

    <nav class="studio-workspace-nav" aria-label="Arbeitsbereiche">
      <a class="studio-workspace-link" href="/"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M4 15v5h16v-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Eingang</span></a>
      <span class="studio-workspace-link studio-workspace-link-active studio-detail-context" aria-current="page"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M14 4v16M4 14h10" stroke="currentColor" stroke-width="1.5"/></svg><span>Fall</span></span>
      <a class="studio-workspace-link" href="<?= $escape($returnPath ?? '/findings') ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg><span>Bestand</span></a>
      <a class="studio-settings-link" href="/legacy/settings" aria-label="Einstellungen · klassische Ansicht"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 3-1 3-3 1-2 3 2 2v3l3 1 1 3h4l1-3 3-1v-3l2-2-2-3-3-1-1-3H9Z" transform="translate(1 1)" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.4"/></svg></a>
      <a class="studio-workspace-link" href="/statistics"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 20V4m0 16h16M8 15l4-5 4 2 4-7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Statistiken</span></a>
    </nav>
  </div>
</body>
</html>
