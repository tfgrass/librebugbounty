<?php /** @var \App\Dto\ScreenshotComparisonView $comparison */ ?>
<section class="studio-comparison" id="vergleich" aria-labelledby="studio-comparison-title" tabindex="-1" data-screenshot-comparison>
  <div class="studio-section-heading"><h2 id="studio-comparison-title"><?= $escape($t('Bildvergleich')) ?></h2></div>
  <p class="studio-detail-hint"><?= $escape($t('Nur gespeicherte Bilder. Die Auswahl ändert weder Bewertung noch Exportgrundlage. Layoutänderungen sind kein Nachweis einer Behebung.')) ?></p>
  <p class="studio-detail-hint" data-comparison-basis>
    <?= $escape($t('Beleg der Bewertung')) ?>:
    <?php if ($comparison->basisId() !== null): ?><code><?= $escape($comparison->basisId()) ?></code> · <?= $escape($formatTime($comparison->assessment->getAssessedAt())) ?>
    <?php else: ?><?= $escape($t('Unbekannt / kein konkreter Beleg')) ?><?php endif; ?>
  </p>
  <?php if ($comparison->before === null || $comparison->after === null): ?>
    <p class="studio-detail-hint"><?= $escape($t('Für einen Vergleich werden mindestens zwei gespeicherte Bildbelege benötigt.')) ?></p>
  <?php else: ?>
    <form method="get" action="<?= $escape($findingPath) ?>#vergleich" class="studio-comparison-controls" data-comparison-form>
      <?= $returnField ?>
      <?php foreach (['before' => 'Links / Vorher', 'after' => 'Rechts / Nachher'] as $side => $label): ?>
      <label><?= $escape($t($label)) ?>
        <select name="compare_<?= $escape($side) ?>">
          <?php foreach ($view->screenshots as $index => $option): ?>
          <option value="<?= $escape($option['evidence']->getId()) ?>"<?= $comparison->$side['evidence']->getId() === $option['evidence']->getId() ? ' selected' : '' ?>><?= $escape($t('Bild {number}', ['number' => $formatNumber($index + 1)]).' · '.$formatTime($option['capturedAt'] ?? $option['evidence']->getCreatedAt()).($option['evidence']->getId() === $comparison->basisId() ? ' · '.$t('Beleg der Bewertung') : '').(!$option['available'] ? ' · '.$t('Datei nicht verfügbar') : '')) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endforeach; ?>
      <button class="studio-detail-button" type="submit"><?= $escape($t('Bilder vergleichen')) ?></button>
    </form>
    <div class="studio-comparison-grid">
      <?php foreach (['before' => 'Links / Vorher', 'after' => 'Rechts / Nachher'] as $side => $label): $shot = $comparison->$side; ?>
      <figure class="studio-shot studio-comparison-shot" data-comparison-side="<?= $escape($side) ?>" data-evidence-id="<?= $escape($shot['evidence']->getId()) ?>">
        <figcaption>
          <div>
            <strong><?= $escape($t($label)) ?></strong>
            <?php if ($shot['evidence']->getId() === $comparison->basisId()): ?><strong class="studio-basis-badge" data-assessment-basis><?= $escape($t('Beleg der Bewertung')) ?></strong><?php endif; ?>
            <span><?= $escape($t('Aufnahme')) ?>: <?= $escape($shot['capturedAt'] !== null ? $formatTime($shot['capturedAt']) : $t('Aufnahmezeit unbekannt')) ?></span>
            <span><?= $escape($t('Ablage')) ?>: <?= $escape($formatTime($shot['evidence']->getCreatedAt())) ?></span>
            <span><?= $escape($t('Herkunft')) ?>: <?= $shot['job'] !== null ? $escape($t('Screenshot-Auftrag').' · '.$shot['job']->getId()) : $escape($t('Herkunft unbekannt')) ?></span>
            <code><?= $escape($shot['evidence']->getId()) ?></code>
          </div>
          <?php if ($shot['available']): ?><a class="studio-detail-text-link" href="<?= $escape($shot['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($t('Original öffnen')) ?> ↗</a><?php endif; ?>
        </figcaption>
        <?php if ($shot['available']): ?>
          <a class="studio-shot-image-link" href="<?= $escape($shot['url']) ?>" target="_blank" rel="noopener noreferrer" data-comparison-image-link>
            <img src="<?= $escape($shot['url']) ?>" alt="<?= $escape($t('Gespeicherter Bildbeleg für {side}', ['side' => $t($label)])) ?>" decoding="async" data-comparison-image>
          </a>
        <?php endif; ?>
        <div class="studio-shot-missing" data-comparison-missing<?= $shot['available'] ? ' hidden' : '' ?>>
          <h3><?= $escape($t('Bilddatei nicht verfügbar')) ?></h3>
          <p><?= $escape($t($shot['problem'] === 'too_large' ? 'Bilddatei zu groß für diese Prüfung (maximal 25 MiB).' : 'Die Datei fehlt, ist nicht lesbar oder hat kein unterstütztes Bildformat. Der gespeicherte Beleg und die Bewertung bleiben erhalten.')) ?></p>
        </div>
      </figure>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
