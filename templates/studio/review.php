<?php

use App\Value\FindingReadLabels;

/** @var \App\Dto\ReviewQueueView $view */
/** @var callable $escape */
/** @var callable $csrfField */
/** @var ?string $message */
/** @var ?string $error */
/** @var array<string, string> $submitted */
/** @var string $contextToken */
$detail = $view->detail;
$finding = $detail?->finding;
$state = $detail?->assessmentState;
$latestRun = $state?->latestRun;
$latestJob = $detail?->screenshotJobs[0] ?? null;
$selectedEvidenceId = $view->selectedEvidenceId;
$lastReviewedId = $view->lastReviewedId;
$submitted ??= [];
$formatNumber = static fn (int $number): string => number_format($number, 0, ',', '.');
$formatTime = static fn (?\DateTimeImmutable $at): string => $at === null
    ? 'unbekannt'
    : $at->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y · H:i:s T');
$kindLabels = ['all' => 'Alle offenen', 'inconclusive' => 'Uneindeutig', 'error' => 'Technischer Fehler', 'unchecked' => 'Ohne technische Prüfung'];
$imageLabels = ['ready' => 'Bildbereit', 'all' => 'Alle Bildzustände', 'missing' => 'Ohne lesbares Bild'];
$jobLabel = static fn (string $status): string => match ($status) {
    'queued' => 'Screenshot vorgemerkt',
    'running' => 'Screenshot wird aufgenommen',
    'available' => 'Letzte Aufnahme abgeschlossen',
    'failed' => 'Letzte Aufnahme fehlgeschlagen',
    default => 'Screenshot · '.$status,
};
$jobTone = static fn (string $status): string => match ($status) {
    'queued', 'running' => 'pending',
    'available' => 'success',
    'failed' => 'error',
    default => 'neutral',
};
$reviewUrl = static function (array $changes) use ($view): string {
    $query = ['kind' => $view->kind, 'images' => $view->images];
    if ($view->after !== null) {
        $query['after'] = $view->after;
    }
    return '/review?'.http_build_query(array_replace($query, $changes), '', '&', PHP_QUERY_RFC3986);
};
$findingPath = $finding === null ? null : '/findings/'.$finding->getId();
$notes = $finding?->getPrivateNotes();
$findingLink = $findingPath === null ? null : $findingPath.'?'.http_build_query(['return_to' => $view->currentPath], '', '&', PHP_QUERY_RFC3986);
$lastReviewedLink = $lastReviewedId === null ? null : '/findings/'.$lastReviewedId.'?'.http_build_query(['return_to' => $view->currentPath], '', '&', PHP_QUERY_RFC3986);
$canAssess = $finding !== null && $view->eligible;
$hasError = $error !== null && $error !== '';
$waitingForImages = $view->total === 0 && $view->images === 'ready' && $view->counts[$view->kind] > 0;
$evidenceLabels = [];
$unavailableEvidence = [];
foreach ($detail?->screenshots ?? [] as $index => $shot) {
    $id = $shot['evidence']->getId();
    $evidenceLabels[$id] = 'Bild '.($index + 1).' · Aufnahme: '.$formatTime($shot['capturedAt']);
    if (!$shot['available']) {
        $evidenceLabels[$id] .= ' · Datei nicht lesbar';
        $unavailableEvidence[$id] = true;
    }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title>Review · LibreBugBounty Studio</title>
  <link rel="stylesheet" href="/css/studio.css">
  <link rel="stylesheet" href="/css/studio-review.css">
  <script src="/js/studio-review.js" defer></script>
</head>
<body data-studio data-studio-review data-total="<?= $view->total ?>" data-remaining="<?= $view->remaining ?>" data-current-id="<?= $escape($finding?->getId() ?? '') ?>">
  <div class="studio-shell studio-review-shell<?= $finding !== null ? ' review-has-card' : '' ?>">
    <header class="studio-header">
      <a class="studio-brand" href="/" aria-label="LibreBugBounty Studio, Eingang">
        <svg class="studio-brand-mark" width="27" height="27" viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M14 2.5 24 8.3v11.4l-10 5.8-10-5.8V8.3L14 2.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M10 11h8v7a4 4 0 0 1-8 0v-7Zm2-3h4v3h-4V8Zm2 4v10M7 13h3m8 0h3M7 17h3m8 0h3m-10 5 2-2m5 0 2 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="studio-brand-name">LibreBugBounty</span><span class="studio-brand-tag">STUDIO</span>
      </a>
      <a class="studio-classic-link" href="/findings">Zum Bestand <span aria-hidden="true">↗</span></a>
    </header>

    <main class="review-workspace" id="review-main">
      <div class="review-content">
        <div class="review-heading">
          <div><p class="studio-eyebrow">MANUELLE PRÜFUNG</p><h1>Review</h1><p>Bild ansehen. Angaben prüfen. Entscheiden.</p></div>
          <div class="review-supply" aria-label="Gesamter Review-Vorrat">
            <span class="review-supply-label">Gesamter Vorrat</span>
            <span><i class="review-dot" aria-hidden="true"></i><strong data-review-count="ready"><?= $formatNumber($view->counts['ready']) ?></strong> bildbereit</span>
            <span><strong data-review-count="missing"><?= $formatNumber($view->counts['missing']) ?></strong> ohne lesbares Bild</span>
          </div>
        </div>

        <details class="review-filter-panel"><summary><span>Vorrat auswählen</span><span><?= $escape($kindLabels[$view->kind].' · '.$imageLabels[$view->images]) ?></span></summary>
        <form method="get" action="/review" class="review-filters" aria-label="Review-Vorrat auswählen">
          <label for="review-kind"><span>Technische Beobachtung</span><select id="review-kind" name="kind"><?php foreach ($kindLabels as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $view->kind === $value ? ' selected' : '' ?>><?= $escape($label.' · '.$formatNumber($view->counts[$value])) ?></option><?php endforeach; ?></select></label>
          <label for="review-images"><span>Bildbelege</span><select id="review-images" name="images"><?php foreach ($imageLabels as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $view->images === $value ? ' selected' : '' ?>><?= $escape($label.' · '.$formatNumber($view->counts[$value])) ?></option><?php endforeach; ?></select></label>
          <button class="review-button review-filter-button" type="submit">Auswahl anwenden</button>
          <p class="review-filter-note">Unbewertete aktive Fälle mit uneindeutiger, fehlgeschlagener oder fehlender technischer Prüfung. Zähler für den gesamten Review-Vorrat.</p>
        </form>
        </details>

        <?php if ($message !== null && $message !== ''): ?>
          <div class="review-feedback" data-tone="success" role="status"><span><?= $escape($message) ?></span><?php if ($lastReviewedLink !== null): ?><a href="<?= $escape($lastReviewedLink) ?>" data-review-return-link>Letzten Fall öffnen <span aria-hidden="true">↗</span></a><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($hasError): ?><p class="review-feedback" data-tone="error" role="alert" tabindex="-1" data-review-error><?= $escape($error) ?></p><?php endif; ?>

        <div class="review-round">
          <p><strong><?= $formatNumber($view->remaining) ?></strong> noch in dieser Runde <span>· <?= $formatNumber($view->total) ?> offene Fälle in der Auswahl</span></p>
          <div class="review-round-links"><a href="<?= $escape($view->currentPath) ?>">Aktualisieren <span aria-hidden="true">↻</span></a><?php if ($view->after !== null): ?><a href="<?= $escape($view->restartPath) ?>">Runde von vorn</a><?php endif; ?></div>
        </div>

        <?php if ($finding === null): ?>
          <section class="review-empty" aria-labelledby="review-empty-title">
            <div class="review-empty-symbol" aria-hidden="true"><svg width="36" height="36" viewBox="0 0 40 40" fill="none"><rect x="6" y="7" width="28" height="27" rx="5" stroke="currentColor" stroke-width="1.4"/><path d="m13 20 5 5 9-10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            <p class="studio-eyebrow"><?= $view->total > 0 ? 'RUNDE BEENDET' : ($waitingForImages ? 'BILDBELEGE FEHLEN' : 'KEINE PASSENDEN FÄLLE') ?></p>
            <h2 id="review-empty-title" tabindex="-1" data-review-focus><?= $view->total > 0 ? 'Ende dieser Runde erreicht.' : ($waitingForImages ? 'Noch keine bildbereiten Fälle.' : 'Hier gibt es gerade nichts zu prüfen.') ?></h2>
            <p><?= $view->total > 0 ? 'Übersprungene Fälle bleiben offen. Du kannst sie in einer neuen Runde wieder ansehen.' : ($waitingForImages ? 'In dieser Auswahl sind noch Fälle ohne lesbares Bild offen. Laufende Aufnahmen erscheinen nach dem Neuladen. Du kannst die Fälle auch ohne Bild ansehen.' : 'Ändere die Auswahl oder schau später noch einmal vorbei.') ?></p>
            <div><?php if ($view->total > 0): ?><a class="review-button review-button-primary" href="<?= $escape($view->restartPath) ?>">Neue Runde starten</a><?php elseif ($waitingForImages): ?><a class="review-button review-button-primary" href="<?= $escape($view->restartPath) ?>">Vorrat neu laden</a><a class="review-text-link" href="<?= $escape($reviewUrl(['images' => 'all', 'after' => ''])) ?>">Auch ohne Bild ansehen</a><?php elseif ($view->images !== 'all' && $view->counts['all'] > 0): ?><a class="review-button" href="<?= $escape($reviewUrl(['images' => 'all', 'after' => ''])) ?>">Alle Bildzustände ansehen</a><?php endif; ?><a class="review-text-link" href="/findings">Zum Bestand <span aria-hidden="true">↗</span></a></div>
          </section>
        <?php else: ?>
          <article class="review-card" data-review-card data-current-id="<?= $escape($finding->getId()) ?>">
            <header class="review-case-heading">
              <div class="review-case-title"><p class="studio-eyebrow">FALL <?= $escape(substr($finding->getId(), 0, 8)) ?> · <?= $escape($finding->getType()) ?></p><h2 tabindex="-1" data-review-focus><?= $escape($finding->getDomain()->getHostname()) ?></h2><p><?= $escape($finding->getTitle()) ?></p></div>
              <a class="review-text-link" href="<?= $escape($findingLink) ?>" data-review-return-link>Fall öffnen <span aria-hidden="true">↗</span></a>
            </header>
            <div class="review-card-layout">
              <section class="review-evidence" aria-labelledby="review-evidence-title">
                <div class="review-section-heading"><h3 id="review-evidence-title">Bildbeleg</h3><span><?= count($detail->screenshots) ?> gespeichert</span></div>
                <?php if ($latestJob !== null): ?>
                  <div class="review-capture-state" data-tone="<?= $escape($jobTone($latestJob->getStatus())) ?>">
                    <span class="studio-status" data-tone="<?= $escape($jobTone($latestJob->getStatus())) ?>"><?= $escape($jobLabel($latestJob->getStatus())) ?></span>
                    <span>Beauftragt: <?= $escape($formatTime($latestJob->getRequestedAt())) ?></span>
                    <?php if ($latestJob->getStatus() === 'failed'): ?><p><?= $escape($latestJob->getErrorMessage() ?? 'Die Aufnahme konnte nicht abgeschlossen werden.') ?></p><?php elseif (in_array($latestJob->getStatus(), ['queued', 'running'], true)): ?><p>Neue Aufnahme läuft im Hintergrund. Nach Abschluss die Seite neu laden.</p><?php endif; ?>
                    <?php $metadata = $latestJob->getCaptureMetadata() ?? []; ?>
                    <?php if (($metadata['challengeDetected'] ?? false) === true): ?><p class="review-challenge"><?= ($metadata['challengeCleared'] ?? false) === true ? 'Browser-Schutz wurde erkannt und beendet.' : 'Browser-Schutz blieb aktiv; die Aufnahme kann eine Schutzseite zeigen.' ?></p><?php endif; ?>
                  </div>
                <?php endif; ?>
                <?php if (count($detail->screenshots) > 1): ?>
                  <nav class="review-shot-picker" aria-label="Bildbeleg auswählen">
                    <?php foreach ($detail->screenshots as $index => $shot): ?>
                      <a href="<?= $escape($reviewUrl(['evidence' => $shot['evidence']->getId()])) ?>" data-review-shot-link="<?= $escape($shot['evidence']->getId()) ?>"<?= $selectedEvidenceId === $shot['evidence']->getId() ? ' aria-current="true"' : '' ?>><span>Bild <?= $index + 1 ?></span><small><?= $shot['available'] ? $escape($shot['capturedAt'] !== null ? $formatTime($shot['capturedAt']) : 'Aufnahmezeit unbekannt') : 'Datei nicht verfügbar' ?></small></a>
                    <?php endforeach; ?>
                  </nav>
                <?php endif; ?>
                <?php if ($detail->screenshots === []): ?>
                  <div class="review-image-empty"><svg width="42" height="42" viewBox="0 0 48 48" fill="none" aria-hidden="true"><rect x="7" y="8" width="34" height="31" rx="3" stroke="currentColor" stroke-width="1.4"/><circle cx="17" cy="18" r="3" stroke="currentColor" stroke-width="1.4"/><path d="m8 34 10-10 8 7 6-6 9 9" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg><h4>Noch kein Bildbeleg</h4><p><?= $latestJob !== null && in_array($latestJob->getStatus(), ['queued', 'running'], true) ? 'Der Screenshot entsteht im Hintergrund. Vorhandene Angaben kannst du schon ansehen.' : 'Für diesen Fall ist kein Screenshot gespeichert. Du kannst ihn überspringen oder anhand eigener Prüfung bewerten.' ?></p></div>
                <?php else: ?>
                  <?php foreach ($detail->screenshots as $index => $shot): ?>
                    <?php $localImage = $shot['available'] && is_string($shot['url']) && str_starts_with($shot['url'], '/artifacts/'); ?>
                    <figure class="review-shot" data-review-shot="<?= $escape($shot['evidence']->getId()) ?>"<?= $selectedEvidenceId !== $shot['evidence']->getId() ? ' hidden' : '' ?>>
                      <?php if ($localImage): ?>
                        <a class="review-image-link" href="<?= $escape($shot['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="Bild <?= $index + 1 ?> in Originalgröße öffnen"><img src="<?= $escape($shot['url']) ?>" alt="Gespeicherter Screenshot <?= $index + 1 ?> für <?= $escape($finding->getDomain()->getHostname()) ?>" loading="<?= $selectedEvidenceId === $shot['evidence']->getId() ? 'eager' : 'lazy' ?>" decoding="async" data-review-image></a>
                      <?php else: ?>
                        <div class="review-image-empty"><span class="review-missing-symbol" aria-hidden="true">⊘</span><h4>Bilddatei nicht verfügbar</h4><p>Ein Bildbeleg ist gespeichert, aber seine Datei fehlt oder kann nicht gelesen werden.</p></div>
                      <?php endif; ?>
                      <figcaption data-review-image-caption><div><strong>Bild <?= $index + 1 ?></strong><span>Aufnahme: <?= $escape($formatTime($shot['capturedAt'])) ?></span><span>Ablage: <?= $escape($formatTime($shot['evidence']->getCreatedAt())) ?></span></div><?php if ($localImage): ?><a class="review-text-link" href="<?= $escape($shot['url']) ?>" target="_blank" rel="noopener noreferrer">Original öffnen <span aria-hidden="true">↗</span></a><?php endif; ?></figcaption>
                    </figure>
                  <?php endforeach; ?>
                <?php endif; ?>
                <p class="review-image-hint">Bild und technische Beobachtung sind getrennte Belege. Eine zeitliche Nähe belegt keinen gemeinsamen Prüflauf.</p>
                <div class="review-gesture" data-review-gesture hidden aria-label="Hier nach links für Not vulnerable oder nach rechts für Vulnerable wischen."><span><span aria-hidden="true">←</span> Not vulnerable</span><span class="review-gesture-grip">Wischen</span><span>Vulnerable <span aria-hidden="true">→</span></span></div>
              </section>

              <aside class="review-source" aria-labelledby="review-source-title" data-review-poc>
                <div class="review-section-heading"><h3 id="review-source-title">PoC &amp; Angaben</h3><span class="review-method"><?= $escape($finding->getMethod()) ?></span></div>
                <section class="review-source-block"><div class="review-source-label"><h4>Gemeldete URL</h4><button class="review-copy" type="button" data-review-copy="review-url" hidden>Kopieren</button></div><code id="review-url" class="review-code"><?= $escape($finding->getUrl()) ?></code></section>
                <section class="review-source-block"><h4>Erwartetes Kennzeichen</h4><?php if ($finding->getExpectedEvidence() !== null && $finding->getExpectedEvidence() !== ''): ?><code class="review-code"><?= $escape($finding->getExpectedEvidence()) ?></code><?php else: ?><p class="review-absent">Kein Kennzeichen gespeichert.</p><?php endif; ?></section>
                <section class="review-source-block"><h4>Payload</h4><?php if ($finding->getPayload() !== null && $finding->getPayload() !== ''): ?><pre class="review-code"><?= $escape($finding->getPayload()) ?></pre><?php else: ?><p class="review-absent">Keine Payload gespeichert.</p><?php endif; ?></section>
                <section class="review-source-block"><h4>Request-Parameter</h4><?php if ($finding->getRequestParams() !== null && $finding->getRequestParams() !== []): ?><pre class="review-code"><?= $escape(json_encode($finding->getRequestParams(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php else: ?><p class="review-absent">Keine zusätzlichen Request-Parameter gespeichert.</p><?php endif; ?></section>
                <p class="review-copy-status" role="status" aria-live="polite" data-review-copy-status></p>

                <section class="review-observation" aria-labelledby="review-observation-title">
                  <h4 id="review-observation-title">Letzte technische Beobachtung</h4><p class="review-observation-value" data-result="<?= $escape($latestRun?->getResult() ?? 'unchecked') ?>"><?= $escape(FindingReadLabels::observation($latestRun?->getResult())) ?></p>
                  <?php if ($latestRun !== null): ?><p class="review-muted"><?= $escape($formatTime($state->latestAt)) ?> · <?= $escape($latestRun->getMode()) ?></p><?php endif; ?>
                  <?php if ($latestRun?->getErrorMessage() !== null && $latestRun->getErrorMessage() !== ''): ?><p class="review-observation-error"><?= $escape($latestRun->getErrorMessage()) ?></p><?php endif; ?>
                  <p class="review-observation-note"><?= $latestRun === null ? 'Beim Eingang wird keine automatische technische Prüfung gestartet.' : 'Ein uneindeutiges Ergebnis oder ein Fehler beweist weder einen Befund noch eine Behebung.' ?></p>
                </section>
                <section class="review-manual-state"><h4>Manuelle Bewertung</h4><p><?= $escape(FindingReadLabels::assessment($finding->getManualAssessment(), $finding->getDiscardReason())) ?></p><?php if ($finding->getManualAssessment() !== null): ?><p class="review-muted"><?= $escape($formatTime($finding->getAssessedAt())) ?></p><?php elseif ($finding->getStatus() !== 'new' || $finding->getReviewState() !== null): ?><p class="review-legacy">Altbestand · Herkunft und Entscheidungsgrundlage unbekannt. Status: <?= $escape($finding->getStatus()) ?> · Review: <?= $escape($finding->getReviewState() ?? 'unbekannt') ?>.</p><?php endif; ?></section>
                <section class="review-note"><h4>Notiz</h4><p<?= $notes === null || $notes === '' ? ' class="review-absent"' : '' ?>><?= $escape($notes === null || $notes === '' ? 'Keine Notiz gespeichert.' : $notes) ?></p></section>
              </aside>
            </div>

            <form method="post" action="<?= $escape('/review/'.$finding->getId().'/assessment') ?>" id="review-assessment-form" class="review-decision" data-review-form>
              <?= $csrfField('review_assessment_'.$finding->getId()) ?>
              <input type="hidden" name="context_token" value="<?= $escape($contextToken) ?>">
              <input type="hidden" name="kind" value="<?= $escape($view->kind) ?>">
              <input type="hidden" name="images" value="<?= $escape($view->images) ?>">
              <input type="hidden" name="after" value="<?= $escape($view->after ?? '') ?>">
              <input type="hidden" name="displayed_evidence_id" value="<?= $escape($selectedEvidenceId ?? '') ?>" data-review-displayed-evidence>
              <div class="review-decision-heading"><div><h3>Deine Entscheidung</h3><p>„Not vulnerable“ speichert „Behoben“. „Vulnerable“ bestätigt den Befund. Überspringen lässt den Fall unverändert.</p></div><p class="review-keyboard" data-review-keyboard hidden><kbd>←</kbd> Not vulnerable <kbd>→</kbd> Vulnerable</p></div>
              <?php if (!$canAssess): ?><p class="review-stale">Dieser Fall gehört nicht mehr zum offenen Review-Vorrat. Öffne den Fall für eine Korrektur oder gehe zum nächsten.</p><?php endif; ?>
              <details class="review-basis"<?= (($submitted['observation_id'] ?? '') !== '' || ($submitted['evidence_id'] ?? '') !== '') ? ' open' : '' ?>><summary>Bewertungsgrundlage <span>optional · ohne Auswahl unbekannt</span></summary><p>Wähle nur einen Beleg oder eine Beobachtung, die du tatsächlich beurteilt hast. Das angezeigte Bild wird nicht automatisch ausgewählt.</p><div class="review-basis-fields">
                <label for="review-evidence-basis">Beurteilter Beleg<select name="evidence_id" id="review-evidence-basis"<?= !$canAssess ? ' disabled' : '' ?>><option value="">Unbekannt / kein konkreter Beleg</option><?php foreach ($detail->evidence as $item): ?><option value="<?= $escape($item->getId()) ?>"<?= ($submitted['evidence_id'] ?? '') === $item->getId() ? ' selected' : '' ?><?= isset($unavailableEvidence[$item->getId()]) && ($submitted['evidence_id'] ?? '') !== $item->getId() ? ' disabled' : '' ?>><?= $escape($evidenceLabels[$item->getId()] ?? $item->getKind().' · Ablage: '.$formatTime($item->getCreatedAt()).' · '.substr($item->getId(), 0, 8)) ?></option><?php endforeach; ?></select></label>
                <label for="review-observation-basis">Beurteilte Beobachtung<select name="observation_id" id="review-observation-basis"<?= !$canAssess ? ' disabled' : '' ?>><option value="">Unbekannt / keine konkrete Beobachtung</option><?php foreach ($detail->runs as $run): ?><option value="<?= $escape($run->getId()) ?>"<?= ($submitted['observation_id'] ?? '') === $run->getId() ? ' selected' : '' ?>><?= $escape(FindingReadLabels::observation($run->getResult()).' · '.$formatTime($run->getFinishedAt() ?? $run->getStartedAt()).' · '.substr($run->getId(), 0, 8)) ?></option><?php endforeach; ?></select></label>
              </div></details>
              <p class="review-decision-hint">Auch ohne Bild kannst du anhand einer eigenen Prüfung entscheiden. Nach dem Speichern folgt der nächste Fall.</p>
              <details class="review-discard" data-review-discard<?= ($submitted['assessment'] ?? '') === 'discarded' ? ' open' : '' ?>><summary>Fall verwerfen</summary><div><p>Der Fall bleibt gespeichert und wird im normalen Arbeiten ignoriert. Ein unzureichendes Bild kannst du stattdessen überspringen.</p><label for="review-discard-reason">Grund<select id="review-discard-reason" name="discard_reason"<?= !$canAssess ? ' disabled' : '' ?>><option value="">Ohne besonderen Grund</option><option value="duplicate"<?= ($submitted['discard_reason'] ?? '') === 'duplicate' ? ' selected' : '' ?>>Duplikat</option></select></label><button class="review-button review-button-discard" type="submit" name="assessment" value="discarded"<?= !$canAssess ? ' disabled' : '' ?>>Verwerfen speichern</button></div></details>
            </form>
          </article>
        <?php endif; ?>
      </div>
    </main>
    <?php if ($finding !== null): ?>
      <section class="review-action-dock" aria-label="Entscheidung für den angezeigten Fall" data-review-dock>
        <div class="review-dock-inner">
          <p class="review-dock-case"><strong><?= $escape($finding->getDomain()->getHostname()) ?></strong><span>Not vulnerable speichert Behoben. Vulnerable bestätigt den Befund.</span></p>
          <div class="review-dock-controls"><div class="review-actions"><button class="review-button review-button-fixed" type="submit" form="review-assessment-form" name="assessment" value="fixed" data-review-fixed<?= !$canAssess ? ' disabled' : '' ?>><span aria-hidden="true">←</span> Not vulnerable</button><button class="review-button review-button-primary" type="submit" form="review-assessment-form" name="assessment" value="confirmed" data-review-confirm<?= !$canAssess ? ' disabled' : '' ?>>Vulnerable <span aria-hidden="true">→</span></button></div><a class="review-dock-skip" href="<?= $escape($view->nextPath) ?>" data-review-skip><span>Überspringen</span><small>ohne Bewertung</small></a></div>
          <p class="review-submit-status" role="status" aria-live="polite" data-review-submit-status></p>
        </div>
      </section>
    <?php endif; ?>
    <?php $activeWorkspace = 'review'; require __DIR__.'/navigation.php'; ?>
  </div>
</body>
</html>
