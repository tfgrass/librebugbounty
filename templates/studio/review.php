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
$isNotice = $view->notice ?? false;
$latestJob = $detail?->screenshotJobs[0] ?? null;
$selectedEvidenceId = $view->selectedEvidenceId;
$lastReviewedId = $view->lastReviewedId;
$submitted ??= [];
$kindLabels = ['all' => 'Alle offenen', 'changed' => 'Neue Hinweise', 'inconclusive' => 'Uneindeutig', 'error' => 'Technischer Fehler', 'unchecked' => 'Ohne technische Prüfung'];
$noticeReasonLabels = ['contradiction' => 'Widerspruch zum Urteil', 'inconclusive' => 'Uneindeutiges Ergebnis', 'error' => 'Technischer Fehler'];
$storedTime = static function (?string $value) use ($formatTime): string {
    return $formatTime($value === null || $value === '' ? null : new \DateTimeImmutable($value, new \DateTimeZone('UTC')), true);
};
$imageLabels = ['ready' => 'Bildbereit', 'all' => 'Alle Bildzustände', 'missing' => 'Ohne lesbares Bild'];
$jobLabel = static fn (string $status): string => $t(match ($status) {
    'queued' => 'Screenshot vorgemerkt',
    'running' => 'Screenshot wird aufgenommen',
    'available' => 'Letzte Aufnahme abgeschlossen',
    'failed' => 'Letzte Aufnahme fehlgeschlagen',
    default => 'Screenshot · '.$status,
});
$jobTone = static fn (string $status): string => match ($status) {
    'queued', 'running' => 'pending',
    'available' => 'success',
    'failed' => 'error',
    default => 'neutral',
};
$reviewUrl = static function (array $changes) use ($view, $reviewTrailId): string {
    $query = ['kind' => $view->kind, 'images' => $view->images, 'trail' => $reviewTrailId];
    parse_str((string) parse_url($view->currentPath, PHP_URL_QUERY), $currentQuery);
    if (isset($currentQuery['card'])) {
        $query['card'] = $currentQuery['card'];
    }
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
$pocUrl = $finding?->getUrl();
$pocParts = is_string($pocUrl) ? parse_url($pocUrl) : false;
$canOpenPoc = is_array($pocParts) && in_array(strtolower($pocParts['scheme'] ?? ''), ['http', 'https'], true) && ($pocParts['host'] ?? '') !== '' && !preg_match('/[\x00-\x20\x7f]/', $pocUrl);
$hasError = $error !== null && $error !== '';
$waitingForImages = $view->total === 0 && $view->images === 'ready' && $view->counts[$view->kind] > 0;
$evidenceLabels = [];
$unavailableEvidence = [];
foreach ($detail?->screenshots ?? [] as $index => $shot) {
    $id = $shot['evidence']->getId();
    $evidenceLabels[$id] = $t('Bild {number}', ['number' => $formatNumber($index + 1)]).' · '.$t('Aufnahme').': '.$formatTime($shot['capturedAt'], true);
    if (!$shot['available']) {
        $evidenceLabels[$id] .= ' · '.$t('Datei nicht lesbar');
        $unavailableEvidence[$id] = true;
    }
}
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($t('Review')) ?> · <?= $escape(\App\AppInfo::NAME.' '.\App\AppInfo::RELEASE_NAME) ?></title>
  <link rel="stylesheet" href="/css/studio.css">
  <link rel="stylesheet" href="/css/studio-review.css">
  <script src="/js/i18n.js" defer></script>
  <script src="/js/studio-review.js" defer></script>
</head>
<body data-studio data-studio-review data-review-trail="<?= $escape($reviewTrailId) ?>" data-review-decision-delay="<?= $reviewDecisionDelaySeconds ?>" data-total="<?= $view->total ?>" data-remaining="<?= $view->remaining ?>" data-current-id="<?= $escape($finding?->getId() ?? '') ?>">
  <div class="studio-shell studio-review-shell<?= $finding !== null || $reviewBackAvailable ? ' review-has-card' : '' ?><?= $isNotice ? ' review-has-notice' : '' ?>">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
      <div class="studio-header-tools">
        <a class="studio-header-link" href="/findings"><?= $escape($t('Zum Bestand')) ?> <span aria-hidden="true">↗</span></a>
        <?php require __DIR__.'/language.php'; ?>
      </div>
    </header>

    <main class="review-workspace" id="review-main">
      <div class="review-content">
        <div class="review-heading">
          <div><p class="studio-eyebrow"><?= $escape($t('Manuelle Prüfung')) ?></p><h1>Review</h1><p><?= $escape($t('Bild ansehen. Angaben prüfen. Entscheiden.')) ?></p></div>
          <div class="review-supply" aria-label="<?= $escape($t('Gesamter Review-Vorrat')) ?>">
            <span class="review-supply-label"><?= $escape($t('Gesamter Vorrat')) ?></span>
            <span><i class="review-dot" aria-hidden="true"></i><strong data-review-count="ready"><?= $formatNumber($view->counts['ready']) ?></strong> <?= $escape($t('bildbereit')) ?></span>
            <span><strong data-review-count="missing"><?= $formatNumber($view->counts['missing']) ?></strong> <?= $escape($t('ohne lesbares Bild')) ?></span>
            <a href="/review?kind=changed&amp;images=all"><strong data-review-count="changed"><?= $formatNumber($view->counts['changed'] ?? 0) ?></strong> <?= $escape($t('neue Hinweise')) ?></a>
          </div>
        </div>

        <details class="review-filter-panel"><summary><span><?= $escape($t('Vorrat auswählen')) ?></span><span><?= $escape($t($kindLabels[$view->kind]).' · '.$t($imageLabels[$view->images])) ?></span></summary>
        <form method="get" action="/review" class="review-filters" aria-label="<?= $escape($t('Review-Vorrat auswählen')) ?>">
          <input type="hidden" name="trail" value="<?= $escape($reviewTrailId) ?>">
          <label for="review-kind"><span><?= $escape($t('Review-Anlass')) ?></span><select id="review-kind" name="kind"><?php foreach ($kindLabels as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $view->kind === $value ? ' selected' : '' ?>><?= $escape($t($label).' · '.$formatNumber($view->counts[$value] ?? 0)) ?></option><?php endforeach; ?></select></label>
          <label for="review-images"><span><?= $escape($t('Bildbelege')) ?></span><select id="review-images" name="images"><?php foreach ($imageLabels as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $view->images === $value ? ' selected' : '' ?>><?= $escape($t($label).' · '.$formatNumber($view->counts[$value])) ?></option><?php endforeach; ?></select></label>
          <button class="review-button review-filter-button" type="submit"><?= $escape($t('Auswahl anwenden')) ?></button>
          <p class="review-filter-note"><?= $escape($t('Unbewertete Fälle und neue Widersprüche, Unklarheiten oder Fehler nach einem Urteil. Passende Ergebnisse bleiben ohne neuen Hinweis. Zähler für den gesamten Review-Vorrat.')) ?></p>
        </form>
        </details>

        <?php if ($message !== null && $message !== ''): ?>
          <div class="review-feedback" data-tone="success" role="status"><span><?= $escape($message) ?></span><?php if ($lastReviewedLink !== null): ?><a href="<?= $escape($lastReviewedLink) ?>" data-review-return-link><?= $escape($t('Letzten Fall öffnen')) ?> <span aria-hidden="true">↗</span></a><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($hasError): ?><p class="review-feedback" data-tone="error" role="alert" tabindex="-1" data-review-error><?= $escape($error) ?></p><?php endif; ?>

        <div class="review-round">
          <p><strong><?= $formatNumber($view->remaining) ?></strong> <?= $escape($t('noch in dieser Runde')) ?> <span>· <?= $formatNumber($view->total) ?> <?= $escape($t('offene Fälle in der Auswahl')) ?></span><?php if ($reviewTrailTruncated ?? false): ?><small class="review-trail-limit"><?= $escape($t('Der Verlauf enthält die letzten {count} Schritte.', ['count' => $formatNumber($reviewTrailLimit ?? 200)])) ?></small><?php endif; ?></p>
          <div class="review-round-links"><a href="<?= $escape($view->currentPath) ?>"><?= $escape($t('Aktualisieren')) ?> <span aria-hidden="true">↻</span></a><?php if ($view->after !== null): ?><a href="<?= $escape($view->restartPath) ?>"><?= $escape($t('Runde von vorn')) ?></a><?php endif; ?></div>
        </div>

        <?php if ($finding === null): ?>
          <section class="review-empty" aria-labelledby="review-empty-title"<?= $isFirstStart ? ' data-first-start' : '' ?>>
            <div class="review-empty-symbol" aria-hidden="true"><svg width="36" height="36" viewBox="0 0 40 40" fill="none"><rect x="6" y="7" width="28" height="27" rx="5" stroke="currentColor" stroke-width="1.4"/><path d="m13 20 5 5 9-10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            <p class="studio-eyebrow"><?= $escape($t($isFirstStart ? 'Manuelle Prüfung' : ($view->total > 0 ? 'Runde beendet' : ($waitingForImages ? 'Bildbelege fehlen' : 'Keine passenden Fälle')))) ?></p>
            <h2 id="review-empty-title" tabindex="-1" data-review-focus><?= $escape($t($isFirstStart ? 'Noch keine Fälle' : ($view->total > 0 ? 'Ende dieser Runde erreicht.' : ($waitingForImages ? 'Noch keine bildbereiten Fälle.' : 'Hier gibt es gerade nichts zu prüfen.')))) ?></h2>
            <p><?= $escape($t($isFirstStart ? 'Erfasse eine URL. Der Screenshot entsteht im Hintergrund; danach kannst du den Fall prüfen, bewerten und exportieren.' : ($view->total > 0 ? 'Übersprungene Fälle bleiben offen. Du kannst sie in einer neuen Runde wieder ansehen.' : ($waitingForImages ? 'In dieser Auswahl sind noch Fälle ohne lesbares Bild offen. Laufende Aufnahmen erscheinen nach dem Neuladen. Du kannst die Fälle auch ohne Bild ansehen.' : 'Ändere die Auswahl oder schau später noch einmal vorbei.')))) ?></p>
            <div><?php if ($isFirstStart): ?><a class="review-button review-button-primary" data-first-start-cta href="/"><?= $escape($t('URL erfassen')) ?></a><?php elseif ($view->total > 0): ?><a class="review-button review-button-primary" href="<?= $escape($view->restartPath) ?>"><?= $escape($t('Neue Runde starten')) ?></a><?php elseif ($waitingForImages): ?><a class="review-button review-button-primary" href="<?= $escape($view->restartPath) ?>"><?= $escape($t('Vorrat neu laden')) ?></a><a class="review-text-link" href="<?= $escape($reviewUrl(['images' => 'all', 'after' => ''])) ?>"><?= $escape($t('Auch ohne Bild ansehen')) ?></a><?php elseif ($view->images !== 'all' && $view->counts['all'] > 0): ?><a class="review-button" href="<?= $escape($reviewUrl(['images' => 'all', 'after' => ''])) ?>"><?= $escape($t('Alle Bildzustände ansehen')) ?></a><?php endif; ?><a class="review-text-link" href="/findings"><?= $escape($t('Zum Bestand')) ?> <span aria-hidden="true">↗</span></a></div>
          </section>
        <?php else: ?>
          <article class="review-card" data-review-card data-current-id="<?= $escape($finding->getId()) ?>">
            <header class="review-case-heading">
              <div class="review-case-title"><p class="studio-eyebrow"><?= $escape($t('Fall')) ?> <?= $escape(substr($finding->getId(), 0, 8)) ?> · <?= $escape($finding->getType()) ?></p><h2 tabindex="-1" data-review-focus><?= $escape($finding->getDomain()->getHostname()) ?></h2><p><?= $escape($finding->getTitle()) ?></p></div>
              <a class="review-text-link" href="<?= $escape($findingLink) ?>" data-review-return-link><?= $escape($t('Fall öffnen')) ?> <span aria-hidden="true">↗</span></a>
            </header>
            <div class="review-card-layout">
              <section class="review-evidence" aria-labelledby="review-evidence-title">
                <div class="review-section-heading"><h3 id="review-evidence-title"><?= $escape($t('Bildbeleg')) ?></h3><span><?= $escape($t('{count} gespeichert', ['count' => $formatNumber(count($detail->screenshots))])) ?></span></div>
                <?php if ($latestJob !== null): ?>
                  <div class="review-capture-state" data-tone="<?= $escape($jobTone($latestJob->getStatus())) ?>">
                    <span class="studio-status" data-tone="<?= $escape($jobTone($latestJob->getStatus())) ?>"><?= $escape($jobLabel($latestJob->getStatus())) ?></span>
                    <span><?= $escape($t('Beauftragt: {value}', ['value' => $formatTime($latestJob->getRequestedAt(), true)])) ?></span>
                    <?php if ($latestJob->getStatus() === 'failed'): ?><p><?= $escape($latestJob->getErrorMessage() ?? $t('Die Aufnahme konnte nicht abgeschlossen werden.')) ?></p><?php elseif (in_array($latestJob->getStatus(), ['queued', 'running'], true)): ?><p><?= $escape($t('Neue Aufnahme läuft im Hintergrund. Nach Abschluss die Seite neu laden.')) ?></p><?php endif; ?>
                    <?php $metadata = $latestJob->getCaptureMetadata() ?? []; ?>
                    <?php if (($metadata['challengeDetected'] ?? false) === true): ?><p class="review-challenge"><?= $escape($t(($metadata['challengeCleared'] ?? false) === true ? 'Browser-Schutz wurde erkannt und beendet.' : 'Browser-Schutz blieb aktiv; die Aufnahme kann eine Schutzseite zeigen.')) ?></p><?php endif; ?>
                  </div>
                <?php endif; ?>
                <?php if (count($detail->screenshots) > 1): ?>
                  <nav class="review-shot-picker" aria-label="<?= $escape($t('Bildbeleg auswählen')) ?>">
                    <?php foreach ($detail->screenshots as $index => $shot): ?>
                      <a href="<?= $escape($reviewUrl(['evidence' => $shot['evidence']->getId()])) ?>" data-review-shot-link="<?= $escape($shot['evidence']->getId()) ?>"<?= $selectedEvidenceId === $shot['evidence']->getId() ? ' aria-current="true"' : '' ?>><span><?= $escape($t('Bild {number}', ['number' => $formatNumber($index + 1)])) ?></span><small><?= $escape($shot['available'] ? ($shot['capturedAt'] !== null ? $formatTime($shot['capturedAt'], true) : $t('Aufnahmezeit unbekannt')) : $t('Datei nicht verfügbar')) ?></small></a>
                    <?php endforeach; ?>
                  </nav>
                <?php endif; ?>
                <?php if ($detail->screenshots === []): ?>
                  <div class="review-image-empty"><svg width="42" height="42" viewBox="0 0 48 48" fill="none" aria-hidden="true"><rect x="7" y="8" width="34" height="31" rx="3" stroke="currentColor" stroke-width="1.4"/><circle cx="17" cy="18" r="3" stroke="currentColor" stroke-width="1.4"/><path d="m8 34 10-10 8 7 6-6 9 9" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg><h4><?= $escape($t('Noch kein Bildbeleg')) ?></h4><p><?= $escape($t($latestJob !== null && in_array($latestJob->getStatus(), ['queued', 'running'], true) ? 'Der Screenshot entsteht im Hintergrund. Vorhandene Angaben kannst du schon ansehen.' : 'Für diesen Fall ist kein Screenshot gespeichert. Du kannst ihn überspringen oder anhand eigener Prüfung bewerten.')) ?></p></div>
                <?php else: ?>
                  <?php foreach ($detail->screenshots as $index => $shot): ?>
                    <?php $localImage = $shot['available'] && is_string($shot['url']) && str_starts_with($shot['url'], '/artifacts/'); ?>
                    <figure class="review-shot" data-review-shot="<?= $escape($shot['evidence']->getId()) ?>"<?= $selectedEvidenceId !== $shot['evidence']->getId() ? ' hidden' : '' ?>>
                      <?php if ($localImage): ?>
                        <a class="review-image-link" href="<?= $escape($shot['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= $escape($t('Bild {number} in Originalgröße öffnen', ['number' => $formatNumber($index + 1)])) ?>"><img src="<?= $escape($shot['url']) ?>" alt="<?= $escape($t('Gespeicherter Screenshot {number} für {host}', ['number' => $formatNumber($index + 1), 'host' => $finding->getDomain()->getHostname()])) ?>" loading="<?= $selectedEvidenceId === $shot['evidence']->getId() ? 'eager' : 'lazy' ?>" decoding="async" data-review-image></a>
                      <?php else: ?>
                        <div class="review-image-empty"><span class="review-missing-symbol" aria-hidden="true">⊘</span><h4><?= $escape($t('Bilddatei nicht verfügbar')) ?></h4><p><?= $escape($t('Ein Bildbeleg ist gespeichert, aber seine Datei fehlt oder kann nicht gelesen werden.')) ?></p></div>
                      <?php endif; ?>
                      <figcaption data-review-image-caption><div><strong><?= $escape($t('Bild {number}', ['number' => $formatNumber($index + 1)])) ?></strong><span><?= $escape($t('Aufnahme')) ?>: <?= $escape($formatTime($shot['capturedAt'], true)) ?></span><span><?= $escape($t('Ablage')) ?>: <?= $escape($formatTime($shot['evidence']->getCreatedAt(), true)) ?></span></div><?php if ($localImage): ?><a class="review-text-link" href="<?= $escape($shot['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($t('Original öffnen')) ?> <span aria-hidden="true">↗</span></a><?php endif; ?></figcaption>
                    </figure>
                  <?php endforeach; ?>
                <?php endif; ?>
              </section>

              <details class="review-inspector" open data-review-inspector><summary><?= $escape($t('Angaben & Optionen')) ?></summary>
              <aside class="review-source" aria-labelledby="review-source-title" data-review-poc>
            <?php if ($isNotice): ?>
              <section class="review-notice" aria-labelledby="review-notice-title" data-review-notice>
                <div class="review-notice-heading"><div><p class="studio-eyebrow"><?= $escape($t('Erneut prüfen')) ?></p><h3 id="review-notice-title"><?= $escape($t('Neue Hinweise nach deinem Urteil')) ?></h3></div><span class="studio-status" data-tone="warning"><?= $escape($t(FindingReadLabels::assessment($finding->getManualAssessment(), $finding->getDiscardReason()))) ?></span></div>
                <p><?= $escape($t('Bewertet: {value}. Deine Bewertung bleibt erhalten, bis du ausdrücklich neu entscheidest.', ['value' => $formatTime($finding->getAssessedAt(), true)])) ?></p>
                <?php if ($view->lastAcknowledgedAt !== null): ?><p class="review-muted"><?= $escape($t('Zuletzt gesichtet: {value}', ['value' => $formatTime($view->lastAcknowledgedAt, true)])) ?></p><?php endif; ?>
                <?php if (!$view->baselineKnown): ?><p class="review-history"><?= $escape($t('Frühere Bewertung ohne vollständig bekannten Beobachtungsstand. Zeitlich nicht eindeutig zugeordnete Hinweise werden zur Prüfung gezeigt.')) ?></p><?php endif; ?>
                <ul class="review-notice-observations" aria-label="<?= $escape($t('Offene technische Hinweise')) ?>">
                  <?php foreach ($view->triggeringObservations as $observation): ?>
                    <li data-review-trigger="<?= $escape($observation['id']) ?>"><div><strong><?= $escape($t(FindingReadLabels::observation($observation['result']))) ?></strong><span><?= $escape($t($noticeReasonLabels[$observation['reason']])) ?></span></div><p class="review-muted"><?= $escape($storedTime($observation['finished_at'] ?? $observation['started_at'] ?? null)) ?> · <?= $escape($observation['mode']) ?> · <?= $escape(substr($observation['id'], 0, 8)) ?></p><?php if (($observation['error_message'] ?? '') !== ''): ?><p class="review-observation-error"><?= $escape($observation['error_message']) ?></p><?php endif; ?></li>
                  <?php endforeach; ?>
                </ul>
                <p class="review-notice-hint"><?= $escape($t('Alle offenen Hinweise stehen hier; die letzte technische Beobachtung wird separat gezeigt. Mit „Geprüft · Bewertung behalten“ erledigst du diese Sichtung und behältst Urteil und Bewertungsdatum.')) ?></p>
              </section>
            <?php endif; ?>
                <div class="review-section-heading"><h3 id="review-source-title"><?= $escape($t('PoC & Angaben')) ?></h3><span class="review-method"><?= $escape($finding->getMethod()) ?></span></div>
                <section class="review-source-block"><div class="review-source-label"><h4><?= $escape($t('Gemeldete URL')) ?></h4><span class="review-url-actions"><?php if ($canOpenPoc): ?><a class="review-text-link" href="<?= $escape($pocUrl) ?>" target="_blank" rel="noopener noreferrer" data-review-poc-open><?= $escape($t('Öffnen')) ?> ↗</a><?php endif; ?><button class="review-copy" type="button" data-review-copy="review-url" hidden><?= $escape($t('Kopieren')) ?></button></span></div><code id="review-url" class="review-code"><?= $escape($finding->getUrl()) ?></code></section>
                <?php if ($canOpenPoc && strtoupper($finding->getMethod()) !== 'GET'): ?><p class="review-muted"><?= $escape($t('Öffnen ruft nur die URL auf; Methode und Request-Parameter werden nicht nachgebildet.')) ?></p><?php endif; ?>
                <section class="review-source-block"><h4><?= $escape($t('Erwartetes Kennzeichen')) ?></h4><?php if ($finding->getExpectedEvidence() !== null && $finding->getExpectedEvidence() !== ''): ?><code class="review-code"><?= $escape($finding->getExpectedEvidence()) ?></code><?php else: ?><p class="review-absent"><?= $escape($t('Kein Kennzeichen gespeichert.')) ?></p><?php endif; ?></section>
                <section class="review-source-block"><h4>Payload</h4><?php if ($finding->getPayload() !== null && $finding->getPayload() !== ''): ?><pre class="review-code"><?= $escape($finding->getPayload()) ?></pre><?php else: ?><p class="review-absent"><?= $escape($t('Keine Payload gespeichert.')) ?></p><?php endif; ?></section>
                <section class="review-source-block"><h4><?= $escape($t('Request-Parameter')) ?></h4><?php if ($finding->getRequestParams() !== null && $finding->getRequestParams() !== []): ?><pre class="review-code"><?= $escape(json_encode($finding->getRequestParams(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php else: ?><p class="review-absent"><?= $escape($t('Keine zusätzlichen Request-Parameter gespeichert.')) ?></p><?php endif; ?></section>
                <p class="review-copy-status" role="status" aria-live="polite" data-review-copy-status></p>

                <section class="review-observation" aria-labelledby="review-observation-title">
                  <h4 id="review-observation-title"><?= $escape($t('Letzte technische Beobachtung')) ?></h4><p class="review-observation-value" data-result="<?= $escape($latestRun?->getResult() ?? 'unchecked') ?>"><?= $escape($t(FindingReadLabels::observation($latestRun?->getResult()))) ?></p>
                  <?php if ($latestRun !== null): ?><p class="review-muted"><?= $escape($formatTime($state->latestAt)) ?> · <?= $escape($latestRun->getMode()) ?></p><?php endif; ?>
                  <?php if ($latestRun?->getErrorMessage() !== null && $latestRun->getErrorMessage() !== ''): ?><p class="review-observation-error"><?= $escape($latestRun->getErrorMessage()) ?></p><?php endif; ?>
                  <p class="review-observation-note"><?= $escape($t($latestRun === null ? 'Beim Eingang wird keine automatische technische Prüfung gestartet.' : 'Ein uneindeutiges Ergebnis oder ein Fehler beweist weder einen Befund noch eine Behebung.')) ?></p>
                </section>
                <section class="review-manual-state"><h4><?= $escape($t('Manuelle Bewertung')) ?></h4><p><?= $escape($t(FindingReadLabels::assessment($finding->getManualAssessment(), $finding->getDiscardReason()))) ?></p><?php if ($finding->getManualAssessment() !== null): ?><p class="review-muted"><?= $escape($formatTime($finding->getAssessedAt(), true)) ?></p><?php elseif ($finding->getStatus() !== 'new' || $finding->getReviewState() !== null): ?><p class="review-history"><?= $escape($t('Historischer Bestand · Herkunft und Entscheidungsgrundlage unbekannt. Status: {status} · Review: {review}.', ['status' => $finding->getStatus(), 'review' => $finding->getReviewState() ?? $t('unbekannt')])) ?></p><?php endif; ?></section>
                <section class="review-note"><h4><?= $escape($t('Notiz')) ?></h4><p<?= $notes === null || $notes === '' ? ' class="review-absent"' : '' ?>><?= $escape($notes === null || $notes === '' ? $t('Keine Notiz gespeichert.') : $notes) ?></p></section>
            <form method="post" action="<?= $escape('/review/'.$finding->getId().'/assessment') ?>" id="review-assessment-form" class="review-decision" data-review-form>
              <?= $csrfField('review_assessment_'.$finding->getId()) ?>
              <input type="hidden" name="trail_id" value="<?= $escape($reviewTrailId) ?>">
              <input type="hidden" name="trail_version" value="<?= $reviewTrailVersion ?>">
              <input type="hidden" name="context_token" value="<?= $escape($contextToken) ?>">
              <input type="hidden" name="kind" value="<?= $escape($view->kind) ?>">
              <input type="hidden" name="images" value="<?= $escape($view->images) ?>">
              <input type="hidden" name="after" value="<?= $escape($view->after ?? '') ?>">
              <input type="hidden" name="displayed_evidence_id" value="<?= $escape($selectedEvidenceId ?? '') ?>" data-review-displayed-evidence>
              <div class="review-decision-heading"><div><h3><?= $escape($t('Deine Entscheidung')) ?></h3><p><?= $escape($t('„Not vulnerable“ speichert „Behoben“. „Vulnerable“ bestätigt den Befund. {keep}Überspringen lässt den Fall unverändert.', ['keep' => $isNotice ? $t('„Bewertung behalten“ erledigt die Hinweise ohne neues Urteil. ') : ''])) ?></p></div><p class="review-keyboard" data-review-keyboard hidden><kbd>←</kbd> Not vulnerable <kbd>→</kbd> Vulnerable</p></div>
              <?php if (!$canAssess): ?><p class="review-stale"><?= $escape($t('Dieser Fall gehört nicht mehr zum offenen Review-Vorrat. Öffne den Fall für eine Korrektur oder gehe zum nächsten.')) ?></p><?php endif; ?>
              <details class="review-basis"<?= (($submitted['observation_id'] ?? '') !== '' || ($submitted['evidence_id'] ?? '') !== '') ? ' open' : '' ?>><summary><?= $escape($t('Bewertungsgrundlage')) ?> <span><?= $escape($t('optional · ohne Auswahl unbekannt')) ?></span></summary><p><?= $escape($t('Wähle nur einen Beleg oder eine Beobachtung, die du tatsächlich beurteilt hast. Das angezeigte Bild wird nicht automatisch ausgewählt.')) ?></p><div class="review-basis-fields">
                <label for="review-evidence-basis"><?= $escape($t('Beurteilter Beleg')) ?><select name="evidence_id" id="review-evidence-basis"<?= !$canAssess ? ' disabled' : '' ?>><option value=""><?= $escape($t('Unbekannt / kein konkreter Beleg')) ?></option><?php foreach ($detail->evidence as $item): ?><option value="<?= $escape($item->getId()) ?>"<?= ($submitted['evidence_id'] ?? '') === $item->getId() ? ' selected' : '' ?><?= isset($unavailableEvidence[$item->getId()]) && ($submitted['evidence_id'] ?? '') !== $item->getId() ? ' disabled' : '' ?>><?= $escape($evidenceLabels[$item->getId()] ?? $item->getKind().' · '.$t('Ablage').': '.$formatTime($item->getCreatedAt(), true).' · '.substr($item->getId(), 0, 8)) ?></option><?php endforeach; ?></select></label>
                <label for="review-observation-basis"><?= $escape($t('Beurteilte Beobachtung')) ?><select name="observation_id" id="review-observation-basis"<?= !$canAssess ? ' disabled' : '' ?>><option value=""><?= $escape($t('Unbekannt / keine konkrete Beobachtung')) ?></option><?php foreach ($detail->runs as $run): ?><option value="<?= $escape($run->getId()) ?>"<?= ($submitted['observation_id'] ?? '') === $run->getId() ? ' selected' : '' ?>><?= $escape($t(FindingReadLabels::observation($run->getResult())).' · '.$formatTime($run->getFinishedAt() ?? $run->getStartedAt(), true).' · '.substr($run->getId(), 0, 8)) ?></option><?php endforeach; ?></select></label>
              </div></details>
              <p class="review-decision-hint"><?= $escape($t('Auch ohne Bild kannst du anhand einer eigenen Prüfung entscheiden. Nach dem Speichern folgt der nächste Fall.')) ?></p>
              <details class="review-discard" data-review-discard<?= ($submitted['assessment'] ?? '') === 'discarded' ? ' open' : '' ?>><summary><?= $escape($t('Fall verwerfen')) ?></summary><div><p><?= $escape($t('Der Fall bleibt gespeichert und wird im normalen Arbeiten ignoriert. Ein unzureichendes Bild kannst du stattdessen überspringen.')) ?></p><label for="review-discard-reason"><?= $escape($t('Grund')) ?><select id="review-discard-reason" name="discard_reason"<?= !$canAssess ? ' disabled' : '' ?>><option value=""><?= $escape($t('Ohne besonderen Grund')) ?></option><option value="duplicate"<?= ($submitted['discard_reason'] ?? '') === 'duplicate' ? ' selected' : '' ?>><?= $escape($t('Duplikat')) ?></option></select></label><button class="review-button review-button-discard" type="submit" name="assessment" value="discarded"<?= !$canAssess ? ' disabled' : '' ?>><?= $escape($t('Verwerfen speichern')) ?></button></div></details>
            </form>
                <p class="review-image-hint"><?= $escape($t('Bild und technische Beobachtung sind getrennte Belege. Eine zeitliche Nähe belegt keinen gemeinsamen Prüflauf.')) ?></p>
                <div class="review-gesture" data-review-gesture hidden aria-label="<?= $escape($t('Hier nach links für Not vulnerable oder nach rechts für Vulnerable wischen.')) ?>"><span><span aria-hidden="true">←</span> Not vulnerable</span><span class="review-gesture-grip"><?= $escape($t('Wischen')) ?></span><span>Vulnerable <span aria-hidden="true">→</span></span></div>
              </aside>
              </details>
            </div>


          </article>
        <?php endif; ?>
      </div>
    </main>
    <?php if ($finding !== null || $reviewBackAvailable): ?>
      <section class="review-action-dock" aria-label="<?= $escape($t('Entscheidung für den angezeigten Fall')) ?>" data-review-dock>
        <div class="review-dock-inner">
          <div class="review-dock-case"><strong><?= $escape($finding?->getDomain()->getHostname() ?? $t('Runde beendet')) ?></strong><span><?= $escape($t('Zurück setzt den vorherigen Fall wieder auf unbewertet.')) ?></span>
            <?php if ($isNotice): ?><button class="review-button review-button-keep" type="submit" form="review-assessment-form" name="assessment" value="keep" data-review-keep<?= !$canAssess ? ' disabled' : '' ?>><?= $escape($t('Geprüft · Bewertung behalten')) ?></button><?php endif; ?>
            <p class="review-delay-status" role="status" aria-live="polite" data-review-delay-status></p><progress class="review-delay-progress" data-review-delay-progress max="1" value="0" hidden></progress>
          </div>
          <div class="review-keypad" aria-label="<?= $escape($t('Review-Tasten')) ?>">
            <?php if ($canOpenPoc): ?><a class="review-button review-key-poc" href="<?= $escape($pocUrl) ?>" target="_blank" rel="noopener noreferrer" data-review-poc-open><kbd>↑</kbd><span><?= $escape($t('PoC öffnen')) ?></span></a><?php else: ?><button class="review-button review-key-poc" type="button" disabled><kbd>↑</kbd><span><?= $escape($t('PoC öffnen')) ?></span></button><?php endif; ?>
            <button class="review-button review-key-skip" type="submit" form="review-skip-form" data-review-skip<?= $finding === null ? ' disabled' : '' ?>><kbd>Enter</kbd><span><?= $escape($t('Überspringen')) ?></span></button>
            <button class="review-button review-button-fixed review-key-fixed" type="submit" form="review-assessment-form" name="assessment" value="fixed" data-review-fixed<?= !$canAssess ? ' disabled' : '' ?>><kbd>←</kbd><span>Not vulnerable</span></button>
            <button class="review-button review-key-back" type="submit" form="review-back-form" data-review-back<?= !$reviewBackAvailable ? ' disabled' : '' ?>><kbd>↓</kbd><span><?= $escape($t('Zurück & Reset')) ?></span></button>
            <button class="review-button review-button-primary review-key-confirm" type="submit" form="review-assessment-form" name="assessment" value="confirmed" data-review-confirm<?= !$canAssess ? ' disabled' : '' ?>><span>Vulnerable</span><kbd>→</kbd></button>
          </div>
          <p class="review-submit-status" role="status" aria-live="polite" data-review-submit-status></p>
        </div>
      </section>
      <form method="post" action="<?= $escape($reviewBackPath) ?>" id="review-back-form" data-review-back-form hidden>
        <input type="hidden" name="_token" value="<?= $escape($reviewBackToken) ?>"><input type="hidden" name="trail_id" value="<?= $escape($reviewTrailId) ?>"><input type="hidden" name="trail_version" value="<?= $reviewTrailVersion ?>">
      </form>
      <?php if ($finding !== null): ?><form method="post" action="<?= $escape($reviewSkipPath) ?>" id="review-skip-form" data-review-skip-form hidden>
        <input type="hidden" name="_token" value="<?= $escape($reviewSkipToken) ?>"><input type="hidden" name="trail_id" value="<?= $escape($reviewTrailId) ?>"><input type="hidden" name="trail_version" value="<?= $reviewTrailVersion ?>"><input type="hidden" name="context_token" value="<?= $escape($contextToken) ?>"><input type="hidden" name="displayed_evidence_id" value="<?= $escape($selectedEvidenceId ?? '') ?>" data-review-displayed-evidence>
      </form><?php endif; ?>
    <?php endif; ?>
    <?php $activeWorkspace = 'review'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
</body>
</html>
