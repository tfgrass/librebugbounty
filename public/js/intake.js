(() => {
  'use strict';

  if (typeof fetch !== 'function') return;
  document.querySelectorAll('[data-intake]').forEach(mountIntake);

  function mountIntake(root) {
    const form = root.querySelector('#intake-form');
    if (!form) return;
    const urlInput = form.elements.namedItem('url');
    const payloadInput = form.elements.namedItem('payload');
    const notesInput = form.elements.namedItem('annotate');
    const submitButton = form.querySelector('button[type="submit"]');
    const list = root.querySelector('#intake-history-list');
    const notice = root.querySelector('#intake-notice');
    const statusNotice = root.querySelector('#intake-status-notice');
    const storageNotice = root.querySelector('#intake-storage-notice');
    const history = root.querySelector('#intake-history');
    const toasts = root.querySelector('#intake-toasts');
    if (![urlInput, payloadInput, notesInput, submitButton, list, notice, statusNotice, storageNotice, history, toasts].every(Boolean)) return;
    const compact = root.dataset.intakeView === 'studio';
    const submitLabel = submitButton.textContent.trim();
    const countLabel = root.querySelector('[data-intake-count]');
    const payloadLabel = root.querySelector('[data-intake-payload-label]');
    const storageKey = root.dataset.intakeStorageKey || 'librebugbounty.intake.v1';
    const createEndpoint = root.dataset.intakeCreateUrl || '/api/findings';
    const statusEndpoint = root.dataset.intakeStatusUrl || '/api/findings/status';
    const maxEntries = 50;
    const idlePollDelay = 30000;
    const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    let entries = [];
    let saving = false;
    let polling = false;
    let pollTimer;
    let refreshAll = true;
    let lifecycle = 0;
    let submissionController;
    let statusController;
    const restored = new Set();
    const text = (value) => typeof value === 'string' ? value : '';
    const findingLink = (id) => uuidPattern.test(text(id)) ? '/findings/' + encodeURIComponent(id) : null;
    const draft = () => ({ url: urlInput.value, payload: payloadInput.value, notes: notesInput.value });

    function persist() {
      if (payloadLabel) payloadLabel.textContent = payloadInput.value || payloadInput.placeholder;
      try {
        sessionStorage.setItem(storageKey, JSON.stringify({ version: 1, entries, draft: draft() }));
      } catch (_) {
        storageNotice.textContent = 'Der Sitzungsverlauf kann in diesem Browser gerade nicht gespeichert werden. Er bleibt bis zum Neuladen sichtbar.';
      }
    }

    function normalizeStatus(value) {
      if (!value || !findingLink(value.id)) return null;
      const assessment = value.assessment || {};
      const screenshot = value.screenshot || {};
      const observation = value.observation;
      return {
        id: value.id,
        url: text(value.url),
        discarded: value.discarded === true,
        assessment: {
          value: ['confirmed', 'fixed', 'discarded'].includes(assessment.value) ? assessment.value : null,
          reason: assessment.reason === 'duplicate' ? 'duplicate' : null,
          assessedAt: text(assessment.assessedAt),
        },
        observation: observation && findingLink(observation.id) ? {
          id: observation.id, result: text(observation.result), mode: text(observation.mode),
          observedAt: text(observation.observedAt), label: text(observation.label),
        } : null,
        screenshot: {
          state: ['queued', 'running', 'available', 'failed', 'none', 'discarded'].includes(screenshot.state) ? screenshot.state : 'none',
          label: text(screenshot.label), requestedAt: text(screenshot.requestedAt),
          capturedAt: text(screenshot.capturedAt), error: text(screenshot.error),
        },
        contactedAt: text(value.contactedAt),
      };
    }

    function restoreState() {
      try {
        const stored = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
        if (stored && stored.version === 1 && Array.isArray(stored.entries)) {
          restored.clear();
          entries = stored.entries.slice(0, maxEntries).filter((entry) => entry && typeof entry.localId === 'string' && typeof entry.url === 'string').map((entry) => {
            const findingId = findingLink(entry.findingId) ? entry.findingId : null;
            const saveState = ['confirmed', 'failed', 'unconfirmed'].includes(entry.saveState) ? entry.saveState : 'unconfirmed';
            restored.add(entry.localId);
            return {
              localId: entry.localId, url: entry.url, notes: text(entry.notes), payload: text(entry.payload),
              submittedAt: text(entry.submittedAt), findingId, outcome: entry.outcome === 'duplicate' ? 'duplicate' : 'stored',
              saveState: saveState === 'confirmed' && !findingId ? 'unconfirmed' : saveState,
              missing: entry.missing === true, status: normalizeStatus(entry.status),
              error: saveState === 'unconfirmed' ? 'Die frühere Speicheranfrage wurde nicht bestätigt. Du kannst die Eingabe bewusst erneut übernehmen.' : text(entry.error),
            };
          });
          if (stored.draft && typeof stored.draft.url === 'string') {
            urlInput.value = stored.draft.url;
            notesInput.value = text(stored.draft.notes);
            if (typeof stored.draft.payload === 'string') payloadInput.value = stored.draft.payload;
          }
        }
      } catch (_) {
        storageNotice.textContent = 'Der frühere Sitzungsverlauf konnte nicht gelesen werden. Gespeicherte Fälle findest du weiterhin im Bestand.';
      }
    }
    restoreState();

    function element(tag, value, className) {
      const node = document.createElement(tag);
      if (value !== undefined) node.textContent = value;
      if (className) node.className = className;
      return node;
    }

    function assessmentLabel(value) {
      if (value.value === 'confirmed') return 'Befund bestätigt';
      if (value.value === 'fixed') return 'Behoben';
      if (value.value === 'discarded') return value.reason === 'duplicate' ? 'Verworfen · Duplikat' : 'Verworfen';
      return 'Keine aufgezeichnete manuelle Bewertung';
    }

    function retryButton(entry, className) {
      const retry = element('button', 'Eingabe ins Formular übernehmen', className);
      retry.type = 'button';
      retry.addEventListener('click', () => {
        if (saving) return;
        urlInput.value = entry.url;
        notesInput.value = entry.notes;
        payloadInput.value = entry.payload;
        persist();
        urlInput.focus();
        form.scrollIntoView({ block: 'nearest' });
      });
      return retry;
    }

    function studioEntry(entry) {
      const row = element('article', undefined, 'studio-entry');
      row.dataset.intakeEntry = entry.localId;
      row.dataset.saveState = entry.saveState;
      if (entry.findingId) row.dataset.findingId = entry.findingId;
      const main = element('div', undefined, 'studio-entry-main');
      const heading = element('div', undefined, 'studio-entry-heading');
      let domain = entry.url;
      try { domain = new URL(entry.url).hostname; } catch (_) { /* Keep invalid restored input readable. */ }
      heading.append(element('strong', domain, 'studio-entry-domain'));
      const stateLabels = { saving: 'Speichert …', failed: 'Nicht gespeichert', unconfirmed: 'Nicht bestätigt' };
      const saved = entry.saveState === 'confirmed';
      const label = entry.missing ? 'Nicht auffindbar' : saved
        ? (entry.outcome === 'duplicate' ? 'Schon vorhanden' : 'Gespeichert') : stateLabels[entry.saveState];
      const badge = element('span', label, 'studio-status');
      badge.dataset.tone = entry.missing || ['failed', 'unconfirmed'].includes(entry.saveState)
        ? 'error' : saved && entry.outcome !== 'duplicate' ? 'success' : 'pending';
      heading.append(badge);
      const url = element('p', entry.url, 'studio-entry-url');
      url.title = entry.url;
      main.append(heading, url);
      if (saved && !entry.missing) {
        const screenshot = entry.status?.screenshot;
        const screenshotLabels = { queued: 'Screenshot wartet', running: 'Screenshot wird erstellt', available: 'Screenshot verfügbar' };
        const statusText = screenshot ? screenshotLabels[screenshot.state] || screenshot.label : 'Ergebnisstand wird geladen';
        const meta = element('p', statusText, 'studio-entry-meta');
        if (screenshot) {
          row.dataset.screenshotState = screenshot.state;
          meta.dataset.tone = screenshot.state === 'failed' ? 'error' : screenshot.state === 'available' ? 'success' : 'muted';
        }
        main.append(meta);
        if (screenshot?.error) main.append(element('p', screenshot.error, 'studio-entry-error'));
      }
      if (entry.error && !saved) main.append(element('p', entry.error, 'studio-entry-error'));
      if (entry.notes) {
        const note = element('details', undefined, 'studio-entry-note');
        note.append(element('summary', 'Notiz'), element('p', entry.notes));
        main.append(note);
      }
      const actions = element('div', undefined, 'studio-entry-actions');
      if (entry.findingId && !entry.missing) {
        const link = element('a', 'Fall öffnen ↗', 'studio-entry-link');
        link.href = findingLink(entry.findingId);
        link.title = 'Fall in der klassischen Ansicht öffnen';
        actions.append(link);
      }
      if (['failed', 'unconfirmed'].includes(entry.saveState)) actions.append(retryButton(entry, 'studio-entry-retry'));
      row.append(main, actions);
      return row;
    }

    function render() {
      const fragment = document.createDocumentFragment();
      if (countLabel) countLabel.textContent = String(entries.length);
      const emptyState = root.querySelector('[data-intake-empty]');
      if (emptyState) emptyState.hidden = entries.length > 0;
      if (entries.length === 0 && !emptyState) fragment.append(element('p', 'Noch keine Eingaben in dieser Sitzung.', compact ? 'studio-empty' : 'hint'));
      for (const entry of entries) {
        if (compact) {
          fragment.append(studioEntry(entry));
          continue;
        }
        const card = element('article', undefined, 'shot-card');
        card.dataset.intakeEntry = entry.localId;
        card.dataset.saveState = entry.saveState;
        if (entry.findingId) card.dataset.findingId = entry.findingId;
        card.append(element('p', entry.url));
        const saveLabels = { saving: 'Speicherung läuft', failed: 'Nicht gespeichert', unconfirmed: 'Speicherung nicht bestätigt' };
        const heading = entry.missing ? 'Fall gelöscht oder nicht auffindbar' : entry.saveState === 'confirmed'
          ? (entry.outcome === 'duplicate' ? 'Bereits vorhanden – bestehender Fall bleibt erhalten' : 'Gespeichert')
          : saveLabels[entry.saveState];
        card.append(element('strong', heading));
        if (entry.error && entry.saveState !== 'confirmed') card.append(element('p', entry.error, 'notice error'));
        if (entry.findingId && !entry.missing) {
          const link = element('a', 'Fall öffnen', 'button ghost');
          link.href = findingLink(entry.findingId);
          const paragraph = element('p');
          paragraph.append(link);
          card.append(paragraph);
        }
        if (entry.notes) {
          const note = element('details');
          note.append(element('summary', 'Eingabenotiz dieser Sitzung'), element('p', entry.notes));
          card.append(note);
        }
        if (entry.status && !entry.missing) {
          const status = entry.status;
          card.dataset.screenshotState = status.screenshot.state;
          card.append(element('p', 'Manuelle Bewertung: ' + assessmentLabel(status.assessment) + (status.assessment.assessedAt ? ' · ' + status.assessment.assessedAt : ''), 'hint'));
          card.append(element('p', 'Technische Beobachtung: ' + (status.observation ? status.observation.label + ' · ' + status.observation.observedAt : 'Keine gespeicherte technische Beobachtung'), 'hint'));
          card.append(element('p', 'Screenshot: ' + (status.screenshot.label || status.screenshot.state) + (status.screenshot.capturedAt ? ' · ' + status.screenshot.capturedAt : ''), 'hint'));
          if (status.screenshot.error) card.append(element('p', status.screenshot.error, 'notice error'));
          if (status.contactedAt) card.append(element('p', 'Kontaktiert · ' + status.contactedAt, 'hint'));
        } else if (entry.saveState === 'confirmed' && !entry.missing) {
          card.append(element('p', 'Ergebnisstand wird geladen.', 'hint'));
        }
        if (['failed', 'unconfirmed'].includes(entry.saveState)) {
          card.append(retryButton(entry, 'ghost'));
        }
        fragment.append(card);
      }
      list.replaceChildren(fragment);
    }

    function signature(status) {
      if (!status) return '';
      return JSON.stringify([status.discarded, status.assessment, status.observation, status.screenshot, status.contactedAt]);
    }

    function sameStatusPart(first, second) {
      return JSON.stringify(first ?? null) === JSON.stringify(second ?? null);
    }

    function toast(entry, previousStatus, wasMissing) {
      if (document.visibilityState !== 'visible' || !document.hasFocus()) return;
      const status = entry.status;
      let message = 'Fall aktualisiert';
      if (entry.missing) message = 'Fall gelöscht oder nicht auffindbar';
      else if (wasMissing) message = 'Fall wieder verfügbar';
      else if (!previousStatus && status) message = 'Ergebnisstand verfügbar';
      else if (!sameStatusPart(previousStatus?.observation, status?.observation)) {
        message = 'Technische Beobachtung: ' + (status?.observation?.label || 'Keine gespeicherte technische Beobachtung');
      } else if (!sameStatusPart(previousStatus?.assessment, status?.assessment)) {
        message = 'Manuelle Bewertung: ' + assessmentLabel(status.assessment);
      } else if (previousStatus?.contactedAt !== status?.contactedAt) {
        message = status?.contactedAt ? 'Kontaktstatus: Kontaktiert' : 'Kontaktstatus aktualisiert';
      } else if (!sameStatusPart(previousStatus?.screenshot, status?.screenshot)) {
        message = 'Screenshot: ' + (status?.screenshot.label || status?.screenshot.state || 'Stand aktualisiert');
      }
      const isError = entry.missing || status?.screenshot.state === 'failed' || status?.observation?.result === 'error';
      const box = element('div', undefined, compact ? 'studio-toast' : isError ? 'notice error' : 'notice success');
      if (compact) box.dataset.tone = isError ? 'error' : 'success';
      box.style.pointerEvents = 'auto';
      box.append(element('strong', message, compact ? 'studio-toast-title' : undefined), element('p', entry.url));
      if (!entry.missing && entry.findingId) {
        const link = element('a', 'Fall öffnen', compact ? 'studio-toast-link' : undefined);
        link.href = findingLink(entry.findingId);
        box.append(link);
      }
      // Bound visual notifications while the persistent rows retain every result.
      if (compact) while (toasts.childElementCount >= 2) toasts.firstElementChild.remove();
      toasts.append(box);
      setTimeout(() => box.remove(), 7000);
    }

    function idsToRefresh() {
      return [...new Set(entries.filter((entry) => entry.findingId && entry.saveState === 'confirmed'
        && (refreshAll || (!entry.missing && (!entry.status || ['queued', 'running'].includes(entry.status.screenshot.state)))))
        .map((entry) => entry.findingId))].slice(0, maxEntries);
    }

    function schedulePoll(delay) {
      clearTimeout(pollTimer);
      if (document.visibilityState !== 'visible') return;
      if (idsToRefresh().length > 0) {
        pollTimer = setTimeout(poll, delay);
        return;
      }
      if (entries.some((entry) => entry.findingId && entry.saveState === 'confirmed')) {
        pollTimer = setTimeout(() => {
          refreshAll = true;
          poll();
        }, idlePollDelay);
      }
    }

    async function poll() {
      if (polling || document.visibilityState !== 'visible') return;
      clearTimeout(pollTimer);
      const fullRefresh = refreshAll;
      const ids = idsToRefresh();
      if (ids.length === 0) {
        schedulePoll(idlePollDelay);
        return;
      }
      polling = true;
      const requestLifecycle = lifecycle;
      const controller = new AbortController();
      statusController = controller;
      const timeout = setTimeout(() => controller.abort(), 10000);
      let nextDelay = 2000;
      try {
        const query = new URLSearchParams();
        ids.forEach((id) => query.append('ids[]', id));
        const response = await fetch(statusEndpoint + '?' + query, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
        const data = await response.json();
        if (requestLifecycle !== lifecycle) return;
        if (!response.ok || !Array.isArray(data.findings) || !Array.isArray(data.missingIds)) throw new Error('Status nicht verfügbar');
        const statuses = new Map(data.findings.map(normalizeStatus).filter(Boolean).map((status) => [status.id, status]));
        const missing = new Set(data.missingIds.filter((id) => ids.includes(id)));
        const changes = new Map();
        let stateChanged = false;
        for (const entry of entries) {
          if (!ids.includes(entry.findingId) || (!statuses.has(entry.findingId) && !missing.has(entry.findingId))) continue;
          const previousStatus = entry.status;
          const previous = signature(entry.status);
          const wasMissing = entry.missing;
          entry.missing = missing.has(entry.findingId);
          entry.status = entry.missing ? null : statuses.get(entry.findingId);
          const changed = wasMissing !== entry.missing || previous !== signature(entry.status);
          stateChanged ||= changed;
          if (!restored.has(entry.localId) && changed) {
            changes.set(entry.findingId, { entry, previousStatus, wasMissing });
          }
          restored.delete(entry.localId);
        }
        if (refreshAll === fullRefresh) refreshAll = false;
        statusNotice.textContent = '';
        if (stateChanged) {
          render();
          persist();
        }
        changes.forEach((change) => toast(change.entry, change.previousStatus, change.wasMissing));
      } catch (_) {
        if (requestLifecycle !== lifecycle) return;
        statusNotice.textContent = 'Der Ergebnisstand konnte gerade nicht aktualisiert werden. Der letzte bekannte Stand bleibt sichtbar.';
        nextDelay = 5000;
      } finally {
        clearTimeout(timeout);
        if (requestLifecycle !== lifecycle) return;
        polling = false;
        schedulePoll(nextDelay);
      }
    }

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (saving || !form.reportValidity()) return;
      const submitted = draft();
      const entry = {
        localId: globalThis.crypto?.randomUUID?.() || Date.now() + '-' + Math.random().toString(16).slice(2),
        url: submitted.url.trim(), notes: submitted.notes, payload: submitted.payload,
        submittedAt: new Date().toISOString(), saveState: 'saving', findingId: null,
        outcome: null, status: null, missing: false, error: '',
      };
      entries.unshift(entry);
      entries = entries.slice(0, maxEntries);
      saving = true;
      submitButton.disabled = true;
      submitButton.textContent = 'Speichert …';
      form.setAttribute('aria-busy', 'true');
      notice.textContent = compact ? 'Wird gespeichert …' : 'Speicherung läuft. Das spätere Screenshot-Ergebnis hält die nächste Eingabe nicht auf.';
      render();
      persist();
      const controller = new AbortController();
      submissionController = controller;
      const requestLifecycle = lifecycle;
      const timeout = setTimeout(() => controller.abort(), 15000);
      try {
        const body = new URLSearchParams({ url: entry.url, payload: entry.payload, annotate: entry.notes, _token: form.elements.namedItem('_token').value });
        const response = await fetch(createEndpoint, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, credentials: 'same-origin', body, signal: controller.signal });
        const data = await response.json();
        if (requestLifecycle !== lifecycle) return;
        if (!response.ok) {
          if ([422, 403].includes(response.status)) {
            entry.saveState = 'failed';
            entry.error = text(data.error) || 'Die Eingabe wurde nicht gespeichert. Bitte prüfen oder die Seite neu laden.';
            notice.textContent = entry.error;
            return;
          }
          throw new Error('Speicherung nicht bestätigt');
        }
        if (!['stored', 'duplicate'].includes(data.outcome) || !findingLink(data.finding?.id)) throw new Error('Speicherung nicht bestätigt');
        entry.saveState = 'confirmed';
        entry.findingId = data.finding.id;
        entry.outcome = data.outcome;
        const status = normalizeStatus(data.status);
        entry.status = status?.id === entry.findingId ? status : null;
        const activeField = document.activeElement;
        // Preserve edits typed while the request was still in flight.
        if (urlInput.value === submitted.url) urlInput.value = '';
        if (notesInput.value === submitted.notes) notesInput.value = '';
        notice.textContent = data.outcome === 'duplicate' ? 'Bereits vorhanden. Der bestehende Fall bleibt erhalten.'
          : compact ? 'Gespeichert. Bereit für die nächste URL.' : 'Fall gespeichert. Der Screenshot entsteht separat.';
        if (![urlInput, payloadInput, notesInput].includes(activeField)) urlInput.focus();
      } catch (_) {
        if (requestLifecycle !== lifecycle) return;
        entry.saveState = 'unconfirmed';
        entry.error = 'Die Speicherung wurde nicht bestätigt. Die Anfrage kann angekommen sein. Deine Eingabe bleibt erhalten; eine erneute Übernahme erfolgt nur auf deinen Klick.';
        notice.textContent = compact ? 'Speicherung nicht bestätigt. Deine Eingabe bleibt erhalten.' : entry.error;
      } finally {
        clearTimeout(timeout);
        if (requestLifecycle !== lifecycle) return;
        saving = false;
        submitButton.disabled = false;
        submitButton.textContent = submitLabel;
        form.removeAttribute('aria-busy');
        render();
        persist();
        if (entry.saveState === 'confirmed') poll();
      }
    });

    form.addEventListener('input', persist);
    const refreshVisible = () => {
      if (document.visibilityState !== 'visible') {
        clearTimeout(pollTimer);
        return;
      }
      refreshAll = true;
      poll();
    };
    document.addEventListener('visibilitychange', refreshVisible);
    window.addEventListener('focus', refreshVisible);
    window.addEventListener('pageshow', (event) => {
      const historyNavigation = performance.getEntriesByType('navigation')[0]?.type === 'back_forward';
      if (!event.persisted && !historyNavigation) return;
      // Another surface in this tab may have changed the shared draft/history.
      // Both BFCache and native history form restoration can revive stale values.
      // Re-read the shared state after the browser finishes restoring its DOM.
      lifecycle++;
      statusController?.abort();
      restoreState();
      saving = false;
      polling = false;
      submitButton.disabled = false;
      submitButton.textContent = submitLabel;
      form.removeAttribute('aria-busy');
      notice.textContent = '';
      toasts.replaceChildren();
      if (payloadLabel) payloadLabel.textContent = payloadInput.value || payloadInput.placeholder;
      render();
      refreshVisible();
    });
    window.addEventListener('pagehide', () => {
      persist();
      lifecycle++;
      submissionController?.abort();
      statusController?.abort();
      clearTimeout(pollTimer);
    });
    history.hidden = false;
    if (payloadLabel) payloadLabel.textContent = payloadInput.value || payloadInput.placeholder;
    render();
    poll();
  }
})();
