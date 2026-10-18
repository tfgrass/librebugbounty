(() => {
  'use strict';

  const i18n = window.LibreBugBountyI18n || { t: (key, parameters = {}) => String(key).replace(/\{([^}]+)\}/g, (match, name) => parameters[name] ?? match), dateTime: (value) => value };
  const t = i18n.t;

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
    const submitLabel = submitButton.textContent.trim();
    const countLabel = root.querySelector('[data-intake-count]');
    const payloadLabel = root.querySelector('[data-intake-payload-label]');
    const storageKey = root.dataset.intakeStorageKey || 'librebugbounty.intake.v1';
    const createEndpoint = root.dataset.intakeCreateUrl || '/api/findings';
    const statusEndpoint = root.dataset.intakeStatusUrl || '/api/findings/status';
    const activityEndpoint = root.dataset.intakeActivityUrl || '/api/intake/activity';
    const activity = root.querySelector('[data-intake-activity]');
    const activityToggle = root.querySelector('[data-intake-activity-toggle]');
    const activityDetails = root.querySelector('#intake-activity-details');
    const activityControls = root.querySelector('#intake-activity-controls');
    const compactSummary = root.querySelector('[data-intake-compact-summary]');
    const sessionOnly = root.querySelector('[data-intake-session-only]');
    const periodControl = root.querySelector('[data-intake-period]');
    const minutesControl = root.querySelector('[data-intake-minutes]');
    const activityNotice = root.querySelector('[data-intake-activity-notice]');
    const endSessionButton = root.querySelector('[data-intake-session-end]');
    const resumeSessionButton = root.querySelector('[data-intake-session-resume]');
    const preferencesKey = 'librebugbounty.intake.preferences.v1';
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
    let activityController;
    let activityTimer;
    let activitySequence = 0;
    let activityActionPending = false;
    let activitySnapshot = null;
    let activityEntries = [];
    let knownGeneration = 0;
    let knownScope = '';
    let renderedListSignature = '';
    let previousMilestoneCount = null;
    let announceMilestone = false;
    let statsExpanded = true;
    let statusReadSequence = 0;
    const latestStatuses = new Map();
    let channel;
    const restored = new Set();
    const text = (value) => typeof value === 'string' ? value : '';
    const findingLink = (id) => uuidPattern.test(text(id)) ? '/findings/' + encodeURIComponent(id) : null;
    const draft = () => ({ url: urlInput.value, payload: payloadInput.value, defaultPayload: payloadInput.defaultValue, notes: notesInput.value });
    const updatePayloadLabel = () => { if (payloadLabel) payloadLabel.textContent = payloadInput.value.trim() || payloadInput.placeholder; };
    const observationLabelKeys = {
      still_vulnerable: 'Nachweis (still_vulnerable)',
      fixed: 'Kein Nachweis (fixed)',
      inconclusive: 'Uneindeutig (inconclusive)',
      error: 'Fehler (error)',
      pending: 'Ausstehend (pending)',
    };
    const screenshotLabelKeys = {
      queued: 'Screenshot in Warteschlange',
      running: 'Screenshot wird erstellt',
      available: 'Screenshot verfügbar',
      failed: 'Screenshot fehlgeschlagen',
      none: 'Kein Screenshot-Auftrag gespeichert',
      discarded: 'Verworfen',
    };

    function persist() {
      updatePayloadLabel();
      try {
        sessionStorage.setItem(storageKey, JSON.stringify({ version: 1, entries, draft: draft() }));
      } catch (_) {
        storageNotice.textContent = t('Der Sitzungsverlauf kann in diesem Browser gerade nicht gespeichert werden. Er bleibt bis zum Neuladen sichtbar.');
      }
    }

    function normalizeStatus(value, fromStorage = false) {
      if (!value || !findingLink(value.id)) return null;
      const assessment = value.assessment || {};
      const screenshot = value.screenshot || {};
      const observation = value.observation;
      const screenshotState = ['queued', 'running', 'available', 'failed', 'none', 'discarded'].includes(screenshot.state) ? screenshot.state : 'none';
      const screenshotErrorKey = text(screenshot.errorKey) || (fromStorage && text(screenshot.error)
        ? 'Für diesen Screenshot-Auftrag wurde kein Bild gespeichert.' : '');
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
          observedAt: text(observation.observedAt),
          labelKey: text(observation.labelKey) || observationLabelKeys[text(observation.result)] || 'Technische Beobachtung',
          label: text(observation.label),
        } : null,
        screenshot: {
          state: screenshotState,
          labelKey: text(screenshot.labelKey) || screenshotLabelKeys[screenshotState],
          label: text(screenshot.label), requestedAt: text(screenshot.requestedAt),
          capturedAt: text(screenshot.capturedAt), errorKey: screenshotErrorKey,
          error: screenshotErrorKey ? t(screenshotErrorKey) : text(screenshot.error),
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
            const errorKey = text(entry.errorKey) || (saveState === 'failed'
              ? 'Die Eingabe wurde nicht gespeichert. Bitte prüfen oder die Seite neu laden.' : '');
            restored.add(entry.localId);
            return {
              localId: entry.localId, url: entry.url, notes: text(entry.notes), payload: text(entry.payload),
              sessionId: text(entry.sessionId) || null,
              legacy: entry.legacy === true || !Object.prototype.hasOwnProperty.call(entry, 'sessionId'),
              pendingHistoryScope: text(entry.pendingHistoryScope),
              globalHistoryScope: text(entry.globalHistoryScope),
              submittedAt: text(entry.submittedAt), findingId, outcome: entry.outcome === 'duplicate' ? 'duplicate' : 'stored',
              saveState: saveState === 'confirmed' && !findingId ? 'unconfirmed' : saveState,
              missing: entry.missing === true, status: normalizeStatus(entry.status, true),
              errorKey,
              error: saveState === 'unconfirmed'
                ? t('Die frühere Speicheranfrage wurde nicht bestätigt. Du kannst die Eingabe bewusst erneut übernehmen.')
                : saveState === 'failed'
                  ? t(errorKey)
                  : '',
            };
          });
          if (stored.draft && typeof stored.draft.url === 'string') {
            urlInput.value = stored.draft.url;
            notesInput.value = text(stored.draft.notes);
            if (typeof stored.draft.payload === 'string') {
              // Follow changed settings when the saved marker was the default.
              // Legacy idle drafts have no reliable evidence of a per-case edit.
              const followsDefault = typeof stored.draft.defaultPayload === 'string'
                ? stored.draft.payload === stored.draft.defaultPayload
                : !stored.draft.url.trim() && !text(stored.draft.notes).trim();
              payloadInput.value = followsDefault ? payloadInput.defaultValue : stored.draft.payload;
            }
          }
        }
      } catch (_) {
        storageNotice.textContent = t('Der frühere Sitzungsverlauf konnte nicht gelesen werden. Gespeicherte Fälle findest du weiterhin im Bestand.');
      }
    }
    restoreState();

    try {
      const preferences = JSON.parse(localStorage.getItem(preferencesKey) || 'null');
      if (preferences && sessionOnly && periodControl && minutesControl) {
        sessionOnly.checked = preferences.sessionOnly !== false;
        if (['session', 'today', 'window'].includes(preferences.period)) periodControl.value = preferences.period;
        if ([5, 10, 15, 30, 60, 120].includes(Number(preferences.minutes))) minutesControl.value = String(preferences.minutes);
        statsExpanded = preferences.statsExpanded !== false;
      }
    } catch (_) { /* Preferences are optional; server-backed sessions do not depend on web storage. */ }
    applyStatsExpansion();

    function applyStatsExpansion() {
      if (!activityToggle || !activityDetails || !activityControls || !compactSummary) return;
      activityToggle.setAttribute('aria-expanded', String(statsExpanded));
      activityDetails.hidden = !statsExpanded;
      activityControls.hidden = !statsExpanded;
      compactSummary.hidden = statsExpanded;
      activity.dataset.collapsed = String(!statsExpanded);
      if (!statsExpanded) root.querySelector('[data-intake-milestone-notice]').textContent = '';
    }

    function persistPreferences() {
      try {
        localStorage.setItem(preferencesKey, JSON.stringify({ sessionOnly: sessionOnly.checked, period: periodControl.value, minutes: Number(minutesControl.value), statsExpanded }));
      } catch (_) { /* Keep the selected controls usable in memory. */ }
    }

    function element(tag, value, className) {
      const node = document.createElement(tag);
      if (value !== undefined) node.textContent = value;
      if (className) node.className = className;
      return node;
    }

    function assessmentLabel(value) {
      if (value.value === 'confirmed') return t('Befund bestätigt');
      if (value.value === 'fixed') return t('Behoben');
      if (value.value === 'discarded') return t(value.reason === 'duplicate' ? 'Verworfen · Duplikat' : 'Verworfen');
      return t('Keine aufgezeichnete manuelle Bewertung');
    }

    function retryButton(entry, className) {
      const retry = element('button', t('Eingabe ins Formular übernehmen'), className);
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
      const stateLabels = { saving: t('Speichert …'), failed: t('Nicht gespeichert'), unconfirmed: t('Nicht bestätigt') };
      const saved = entry.saveState === 'confirmed';
      const label = entry.missing ? t('Nicht auffindbar') : saved
        ? t(entry.outcome === 'duplicate' ? 'Schon vorhanden' : 'Gespeichert') : stateLabels[entry.saveState];
      const badge = element('span', label, 'studio-status');
      badge.dataset.tone = entry.missing || ['failed', 'unconfirmed'].includes(entry.saveState)
        ? 'error' : saved && entry.outcome !== 'duplicate' ? 'success' : 'pending';
      heading.append(badge);
      const url = element('p', entry.url, 'studio-entry-url');
      url.title = entry.url;
      main.append(heading, url);
      if (saved && !entry.missing) {
        const screenshot = entry.status?.screenshot;
        const screenshotLabels = { queued: t('Screenshot wartet'), running: t('Screenshot wird erstellt'), available: t('Screenshot verfügbar') };
        const statusText = screenshot ? screenshotLabels[screenshot.state] || t(screenshot.labelKey || screenshot.label) : t('Ergebnisstand wird geladen');
        const meta = element('p', statusText, 'studio-entry-meta');
        if (screenshot) {
          row.dataset.screenshotState = screenshot.state;
          meta.dataset.tone = screenshot.state === 'failed' ? 'error' : screenshot.state === 'available' ? 'success' : 'muted';
        }
        main.append(meta);
        if (screenshot?.error) main.append(element('p', t(screenshot.errorKey || screenshot.error), 'studio-entry-error'));
      }
      if (entry.error && !saved) main.append(element('p', entry.error, 'studio-entry-error'));
      if (entry.notes) {
        const note = element('details', undefined, 'studio-entry-note');
        note.append(element('summary', t('Notiz')), element('p', entry.notes));
        main.append(note);
      }
      const actions = element('div', undefined, 'studio-entry-actions');
      if (entry.findingId && !entry.missing) {
        const link = element('a', t('Fall öffnen') + ' ↗', 'studio-entry-link');
        link.href = findingLink(entry.findingId);
        link.title = t('Fall öffnen');
        actions.append(link);
      }
      if (['failed', 'unconfirmed'].includes(entry.saveState)) actions.append(retryButton(entry, 'studio-entry-retry'));
      row.append(main, actions);
      return row;
    }

    function currentSession() {
      return activitySnapshot?.activeSession || activitySnapshot?.resumableSession || null;
    }

    function sessionEntries() {
      const session = currentSession();
      const local = entries.filter((entry) => {
        if (entry.legacy) return activityEntries.some((item) => item.findingId === entry.findingId);
        if (entry.sessionId) return entry.sessionId === session?.id;
        // An interrupted request may have reached the server without returning its session ID.
        return !session || Date.parse(entry.submittedAt) >= Date.parse(session.startedAt);
      });
      const localIds = new Set(local.map((entry) => entry.findingId).filter(Boolean));
      const combined = [...local, ...activityEntries.filter((entry) => !localIds.has(entry.findingId))];
      return combined.sort((a, b) => (Date.parse(b.submittedAt) || 0) - (Date.parse(a.submittedAt) || 0)).slice(0, maxEntries);
    }

    function visibleEntries() {
      const recovering = entries.filter((entry) => ['failed', 'unconfirmed', 'saving'].includes(entry.saveState));
      const recoveringIds = new Set(recovering.map((entry) => entry.localId));
      const offlineGlobal = !activitySnapshot && sessionOnly?.checked === false;
      const globalHistory = [...activityEntries];
      const globalIds = new Set(globalHistory.map((entry) => entry.findingId));
      for (const entry of entries) {
        const pending = entry.outcome === 'stored' && entry.pendingHistoryScope && (!activitySnapshot || entry.pendingHistoryScope === knownScope);
        const cached = offlineGlobal && entry.globalHistoryScope;
        if (entry.legacy || entry.saveState !== 'confirmed' || entry.missing || (!pending && !cached) || globalIds.has(entry.findingId)) continue;
        globalHistory.push(entry);
        globalIds.add(entry.findingId);
      }
      globalHistory.sort((a, b) => (Date.parse(b.submittedAt) || 0) - (Date.parse(a.submittedAt) || 0));
      const history = offlineGlobal || activitySnapshot?.history.scope === 'all' ? globalHistory
        : !activitySnapshot ? entries.filter((entry) => !entry.legacy) : sessionEntries();
      // Keep explicit recovery available in the single list, even when the
      // server already has 50 newer cases. An uncertain save is never matched
      // to a stored case merely because they share a URL.
      return [...recovering, ...history.filter((entry) => !recoveringIds.has(entry.localId))]
        .slice(0, maxEntries)
        .sort((a, b) => (Date.parse(b.submittedAt) || 0) - (Date.parse(a.submittedAt) || 0));
    }

    function render() {
      const displayed = visibleEntries();
      const globalHistory = activitySnapshot ? activitySnapshot.history.scope === 'all' : sessionOnly?.checked === false;
      if (countLabel) countLabel.textContent = t('Letzte {count}', { count: displayed.length });
      const emptyState = root.querySelector('[data-intake-empty]');
      if (emptyState) {
        emptyState.hidden = displayed.length > 0;
        const emptyTitle = emptyState.querySelector('p');
        const emptyHint = emptyState.querySelector('span');
        if (emptyTitle) emptyTitle.textContent = t(globalHistory ? 'Noch keine Fälle im Bestand' : 'Bereit für die erste URL');
        if (emptyHint) emptyHint.textContent = t(globalHistory ? 'Neue gespeicherte Fälle erscheinen hier.' : 'Einfügen, Enter. Dein Verlauf erscheint hier.');
      }
      const nextSignature = JSON.stringify(displayed);
      if (nextSignature !== renderedListSignature) {
        const fragment = document.createDocumentFragment();
        for (const entry of displayed) fragment.append(studioEntry(entry));
        list.replaceChildren(fragment);
        renderedListSignature = nextSignature;
      }
      list.setAttribute('aria-label', t(globalHistory ? 'Letzte {count} Einträge' : 'Letzte {count} Eingaben dieser Session', { count: displayed.length }));
      const hint = root.querySelector('[data-intake-history-hint]');
      if (hint) hint.textContent = t(!activitySnapshot ? 'Der lokale Verlauf bleibt sichtbar, bis die Session geladen werden kann.' : globalHistory
        ? 'Bis zu 50 Einträge · unabhängig von Session und Alter.'
        : 'Bis zu 50 Eingaben dieser Session · neue Fälle bleiben über Tabs und Neuladen erhalten.');
    }

    const formatNumber = (value, decimals = 0) => new Intl.NumberFormat(i18n.languageTag || 'de-DE', { maximumFractionDigits: decimals }).format(Math.max(0, Number(value) || 0));
    const formatTime = (value) => {
      const date = new Date(value);
      return Number.isFinite(date.getTime()) ? new Intl.DateTimeFormat(i18n.languageTag || 'de-DE', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Berlin' }).format(date) : '—';
    };
    const formatDuration = (seconds) => {
      const minutes = Math.max(0, Math.floor((Number(seconds) || 0) / 60));
      if (minutes < 1) return t('unter 1 Min');
      if (minutes < 60) return t('{minutes} Min', { minutes });
      return t('{hours} Std {minutes} Min', { hours: Math.floor(minutes / 60), minutes: minutes % 60 });
    };

    function renderActivity() {
      if (!activity || !activitySnapshot) return;
      const stats = activitySnapshot.stats || {};
      const session = currentSession();
      const active = activitySnapshot.activeSession;
      const count = Math.max(0, Number(stats.count) || 0);
      const minutes = Number(stats.minutes) || Number(minutesControl?.value) || 15;
      root.querySelector('[data-intake-stat-count]').textContent = formatNumber(count);
      if (compactSummary) compactSummary.textContent = t('{count} neu · {period}', {
        count: formatNumber(count),
        period: stats.period === 'today' ? t('Heute') : stats.period === 'window' ? t('Letzte {minutes} Min', { minutes }) : t('Session'),
      });
      const milestone = [10, 25, 50, 100].find((goal) => count < goal) || (Math.floor(count / 100) + 1) * 100;
      const progress = root.querySelector('[data-intake-milestone]');
      progress.max = milestone;
      progress.value = count;
      root.querySelector('[data-intake-milestone-label]').textContent = [10, 25, 50, 100].includes(count)
        ? t('{count} geschafft · weiter bis {next}', { count: formatNumber(count), next: formatNumber(milestone) })
        : t('Etappe {count}', { count: formatNumber(milestone) });
      root.querySelector('[data-intake-stat-pace]').textContent = t('{count} letzte {minutes} Min · davor {previous}', { count: formatNumber(stats.currentCount), minutes, previous: formatNumber(stats.previousCount) });
      const partialComparison = ['session', 'today'].includes(stats.period) && Number(stats.durationSeconds) < minutes * 120;
      root.querySelector('[data-intake-stat-average]').textContent = stats.averagePerWindow === null
        ? t('Anlaufphase · Ø ab {minutes} Min', { minutes })
        : partialComparison
          ? t('Ø {count}/{minutes} Min · Anlaufphase', { count: formatNumber(stats.averagePerWindow, 1), minutes })
          : t('Ø {count} pro {minutes} Min', { count: formatNumber(stats.averagePerWindow, 1), minutes });
      root.querySelector('[data-intake-stat-time]').textContent = session
        ? t('{duration} · seit {time}', { duration: formatDuration(session.durationSeconds), time: formatTime(session.startedAt) })
        : t('Noch keine Session');
      endSessionButton.hidden = !active;
      endSessionButton.disabled = activityActionPending || saving;
      const sessionHint = root.querySelector('[data-intake-session-hint]');
      sessionHint.hidden = Boolean(active);
      sessionHint.textContent = t(session ? 'Session abgeschlossen' : 'Startet mit deiner ersten Erfassung');
      root.querySelector('[data-intake-activity-context]').textContent = t(stats.period === 'session'
        ? 'Nur deine URL-Erfassungen · 2 Std. 30 Min. ohne Erfassung trennen Sessions.'
        : 'Gesamter Bestand · inklusive Importe · Tagesgrenze Europe/Berlin.');
      const summary = root.querySelector('[data-intake-session-summary]');
      const resumable = activitySnapshot.resumableSession;
      summary.hidden = !resumable;
      if (resumable) {
        root.querySelector('[data-intake-session-summary-text]').textContent = t('{count} neue Fälle in {duration}. {ending}', {
          count: formatNumber(resumable.count), duration: formatDuration(resumable.durationSeconds),
          ending: t(resumable.state === 'idle' ? 'Nach 2 Std. 30 Min. ohne Erfassung pausiert.' : 'Session abgeschlossen.'),
        });
      }
      resumeSessionButton.disabled = activityActionPending || saving;
      activity.hidden = false;
    }

    function rememberStatus(id, status, missing, sequence) {
      const previous = latestStatuses.get(id);
      if (previous && previous.sequence > sequence) return previous;
      const current = { status, missing, sequence };
      latestStatuses.delete(id);
      latestStatuses.set(id, current);
      while (latestStatuses.size > maxEntries * 3) latestStatuses.delete(latestStatuses.keys().next().value);
      return current;
    }

    function applyActivity(data, statusRead) {
      if (!data || !data.stats || !data.history || !Array.isArray(data.history.findings)) throw new Error('activity unavailable');
      const generation = Number(data.sessionGeneration) || 0;
      if (Object.prototype.hasOwnProperty.call(data, 'scopeId') && text(data.scopeId) !== knownScope) {
        knownScope = text(data.scopeId);
        knownGeneration = 0;
      }
      if (generation < knownGeneration) return false;
      knownGeneration = generation;
      activitySnapshot = data;
      const session = data.activeSession || data.resumableSession;
      if (statsExpanded && announceMilestone && session && previousMilestoneCount?.id === session.id) {
        const crossed = [10, 25, 50, 100].filter((goal) => previousMilestoneCount.count < goal && Number(session.count) >= goal).pop();
        if (crossed) root.querySelector('[data-intake-milestone-notice]').textContent = t('Etappe {count} erreicht. Gut erfasst!', { count: crossed });
      }
      previousMilestoneCount = session ? { id: session.id, count: Number(session.count) || 0 } : null;
      announceMilestone = false;
      activityEntries = data.history.findings.filter((finding) => findingLink(finding.id)).map((finding) => {
        const current = rememberStatus(finding.id, normalizeStatus(finding.status), false, statusRead);
        return {
          localId: 'finding-' + finding.id, findingId: finding.id, url: text(finding.url), notes: '', payload: '',
          submittedAt: text(finding.createdAt), saveState: 'confirmed', outcome: 'stored', missing: current.missing,
          status: current.status, errorKey: '', error: '',
        };
      });
      // Keep this tab's richer attempt details, while taking current status from the server.
      let historyReconciled = false;
      for (const entry of entries) {
        const finding = activityEntries.find((item) => item.findingId === entry.findingId);
        // Only a successful, accepted snapshot retires this save receipt. A
        // global snapshot is authoritative even when the case is now absent
        // (deleted or outside the latest 50); an outage must retain the receipt.
        if (entry.pendingHistoryScope && (entry.pendingHistoryScope !== knownScope || finding || data.history.scope === 'all')) {
          entry.pendingHistoryScope = '';
          historyReconciled = true;
        }
        // Offline global history may reuse only receipts that this scope's
        // last global snapshot included, never every old local confirmation.
        const globalScope = data.history.scope === 'all'
          ? finding && entry.saveState === 'confirmed' ? knownScope : ''
          : entry.globalHistoryScope === knownScope ? knownScope : '';
        if (text(entry.globalHistoryScope) !== globalScope) {
          entry.globalHistoryScope = globalScope;
          historyReconciled = true;
        }
        if (finding && entry.saveState === 'confirmed') {
          entry.status = finding.status;
          entry.missing = finding.missing;
          if (data.history.scope === 'session' && session) entry.sessionId = session.id;
        }
      }
      if (historyReconciled) persist();
      const filter = root.querySelector('[data-intake-history-filter]');
      if (filter) filter.hidden = false;
      renderActivity();
      render();
      schedulePoll(0);
      return true;
    }

    function activityQuery() {
      return new URLSearchParams({ period: periodControl?.value || 'session', minutes: minutesControl?.value || '15', history: sessionOnly?.checked === false ? 'all' : 'session' });
    }

    function scheduleActivity() {
      clearTimeout(activityTimer);
      if (document.visibilityState === 'visible') activityTimer = setTimeout(refreshActivity, idlePollDelay);
    }

    async function refreshActivity() {
      if (!activity || saving || activityActionPending || document.visibilityState !== 'visible') return;
      clearTimeout(activityTimer);
      activityController?.abort();
      const controller = new AbortController();
      activityController = controller;
      const sequence = ++activitySequence;
      const statusRead = ++statusReadSequence;
      const requestLifecycle = lifecycle;
      const timeout = setTimeout(() => controller.abort(), 10000);
      activity.setAttribute('aria-busy', 'true');
      try {
        const response = await fetch(activityEndpoint + '?' + activityQuery(), { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
        const data = await response.json();
        if (requestLifecycle !== lifecycle || sequence !== activitySequence) return;
        if (!response.ok) throw new Error('activity unavailable');
        if (!applyActivity(data, statusRead)) return;
        activityNotice.textContent = '';
      } catch (_) {
        if (requestLifecycle !== lifecycle || sequence !== activitySequence) return;
        activity.hidden = false;
        if (activitySnapshot) {
          sessionOnly.checked = activitySnapshot.history.scope !== 'all';
          periodControl.value = activitySnapshot.stats.period;
          minutesControl.value = String(activitySnapshot.stats.minutes);
          persistPreferences();
        }
        activityNotice.textContent = t('Fortschritt und gemeinsamer Verlauf sind gerade nicht erreichbar. Deine Eingabe bleibt benutzbar.');
      } finally {
        clearTimeout(timeout);
        if (requestLifecycle === lifecycle && sequence === activitySequence) {
          activity.removeAttribute('aria-busy');
          scheduleActivity();
        }
      }
    }

    async function changeSession(action) {
      const target = action === 'end' ? activitySnapshot?.activeSession : activitySnapshot?.resumableSession;
      if (!target || saving || activityActionPending) return;
      activityActionPending = true;
      ++activitySequence;
      activityController?.abort();
      clearTimeout(activityTimer);
      renderActivity();
      const controller = new AbortController();
      activityController = controller;
      const requestLifecycle = lifecycle;
      const statusRead = ++statusReadSequence;
      const timeout = setTimeout(() => controller.abort(), 10000);
      try {
        const body = { _token: form.elements.namedItem('_token').value, action, sessionId: target.id,
          expectedSessionGeneration: Number(activitySnapshot?.sessionGeneration) || 0,
          expectedActiveSessionId: activitySnapshot?.activeSession?.id || null };
        const response = await fetch(activityEndpoint + '?' + activityQuery(), { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, credentials: 'same-origin', cache: 'no-store', body: JSON.stringify(body), signal: controller.signal });
        const data = await response.json();
        if (requestLifecycle !== lifecycle) return;
        if (!response.ok) {
          if (response.status === 409) activityNotice.textContent = t('Die Session hat sich in einem anderen Tab geändert. Der aktuelle Stand wird geladen.');
          else throw new Error('session action unavailable');
        } else {
          applyActivity(data, statusRead);
          activityNotice.textContent = t(action === 'end' ? 'Session beendet. Die nächste Erfassung startet eine neue Session.' : 'Vorige Session wird fortgesetzt.');
          channel?.postMessage({ type: 'activity' });
        }
      } catch (_) {
        if (requestLifecycle !== lifecycle) return;
        activityNotice.textContent = t('Die Sessionänderung wurde nicht bestätigt. Der aktuelle Stand wird neu geladen.');
      } finally {
        clearTimeout(timeout);
        if (requestLifecycle !== lifecycle) return;
        activityActionPending = false;
        renderActivity();
        refreshActivity();
      }
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
      let message = t('Fall aktualisiert');
      if (entry.missing) message = t('Fall gelöscht oder nicht auffindbar');
      else if (wasMissing) message = t('Fall wieder verfügbar');
      else if (!previousStatus && status) message = t('Ergebnisstand verfügbar');
      else if (!sameStatusPart(previousStatus?.observation, status?.observation)) {
        message = t('Technische Beobachtung') + ': ' + t(status?.observation?.labelKey || status?.observation?.label || 'Keine gespeicherte technische Beobachtung');
      } else if (!sameStatusPart(previousStatus?.assessment, status?.assessment)) {
        message = t('Manuelle Bewertung') + ': ' + assessmentLabel(status.assessment);
      } else if (previousStatus?.contactedAt !== status?.contactedAt) {
        message = t(status?.contactedAt ? 'Kontaktstatus: Kontaktiert' : 'Kontaktstatus aktualisiert');
      } else if (!sameStatusPart(previousStatus?.screenshot, status?.screenshot)) {
        message = 'Screenshot: ' + t(status?.screenshot.labelKey || status?.screenshot.label || status?.screenshot.state || 'Stand aktualisiert');
      }
      const isError = entry.missing || status?.screenshot.state === 'failed' || status?.observation?.result === 'error';
      const box = element('div', undefined, 'studio-toast');
      box.dataset.tone = isError ? 'error' : 'success';
      box.style.pointerEvents = 'auto';
      box.append(element('strong', message, 'studio-toast-title'), element('p', entry.url));
      if (!entry.missing && entry.findingId) {
        const link = element('a', t('Fall öffnen'), 'studio-toast-link');
        link.href = findingLink(entry.findingId);
        box.append(link);
      }
      // Bound visual notifications while the persistent rows retain every result.
      while (toasts.childElementCount >= 2) toasts.firstElementChild.remove();
      toasts.append(box);
      setTimeout(() => box.remove(), 7000);
    }

    function idsToRefresh() {
      return [...new Set([...visibleEntries(), ...entries].filter((entry) => entry.findingId && entry.saveState === 'confirmed'
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
      if ([...visibleEntries(), ...entries].some((entry) => entry.findingId && entry.saveState === 'confirmed')) {
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
      const statusRead = ++statusReadSequence;
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
        if (!response.ok || !Array.isArray(data.findings) || !Array.isArray(data.missingIds)) throw new Error('status unavailable');
        const statuses = new Map(data.findings.map(normalizeStatus).filter(Boolean).map((status) => [status.id, status]));
        const missing = new Set(data.missingIds.filter((id) => ids.includes(id)));
        const currentStatuses = new Map();
        ids.forEach((id) => {
          if (statuses.has(id) || missing.has(id)) currentStatuses.set(id, rememberStatus(id, statuses.get(id) || null, missing.has(id), statusRead));
        });
        const changes = new Map();
        let stateChanged = false;
        for (const entry of [...entries, ...activityEntries]) {
          const current = currentStatuses.get(entry.findingId);
          if (!current) continue;
          const previousStatus = entry.status;
          const previous = signature(entry.status);
          const wasMissing = entry.missing;
          entry.missing = current.missing;
          entry.status = current.status;
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
        statusNotice.textContent = t('Der Ergebnisstand konnte gerade nicht aktualisiert werden. Der letzte bekannte Stand bleibt sichtbar.');
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
      if (activityActionPending) {
        notice.textContent = t('Die Session wird gerade aktualisiert. Bitte danach erneut erfassen.');
        return;
      }
      const submitted = draft();
      const entry = {
        localId: globalThis.crypto?.randomUUID?.() || Date.now() + '-' + Math.random().toString(16).slice(2),
        sessionId: activitySnapshot?.activeSession?.id || null, legacy: false,
        url: submitted.url.trim(), notes: submitted.notes, payload: submitted.payload,
        submittedAt: new Date().toISOString(), saveState: 'saving', findingId: null,
        outcome: null, status: null, missing: false, errorKey: '', error: '',
      };
      entries.unshift(entry);
      entries = entries.slice(0, maxEntries);
      saving = true;
      ++activitySequence;
      activityController?.abort();
      clearTimeout(activityTimer);
      submitButton.disabled = true;
      submitButton.textContent = t('Speichert …');
      form.setAttribute('aria-busy', 'true');
      notice.textContent = t('Wird gespeichert …');
      render();
      renderActivity();
      persist();
      const controller = new AbortController();
      submissionController = controller;
      const requestLifecycle = lifecycle;
      const statusRead = ++statusReadSequence;
      const timeout = setTimeout(() => controller.abort(), 15000);
      try {
        const body = new URLSearchParams({ url: entry.url, payload: entry.payload, annotate: entry.notes, browserIntake: '1', _token: form.elements.namedItem('_token').value });
        const response = await fetch(createEndpoint, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, credentials: 'same-origin', body, signal: controller.signal });
        const data = await response.json();
        if (requestLifecycle !== lifecycle) return;
        if (!response.ok) {
          if ([422, 403].includes(response.status)) {
            entry.saveState = 'failed';
            entry.errorKey = text(data.errorKey);
            entry.error = t(entry.errorKey || text(data.error)) || t('Die Eingabe wurde nicht gespeichert. Bitte prüfen oder die Seite neu laden.');
            notice.textContent = entry.error;
            return;
          }
          throw new Error('save unconfirmed');
        }
        if (!['stored', 'duplicate'].includes(data.outcome) || !findingLink(data.finding?.id)) throw new Error('save unconfirmed');
        entry.saveState = 'confirmed';
        entry.findingId = data.finding.id;
        entry.outcome = data.outcome;
        entry.sessionId = text(data.intakeSessionId) || entry.sessionId;
        entry.pendingHistoryScope = data.outcome === 'stored' ? text(data.intakeScopeId) : '';
        if (text(data.intakeScopeId) && data.intakeScopeId !== knownScope) {
          knownScope = data.intakeScopeId;
          knownGeneration = 0;
        }
        knownGeneration = Math.max(knownGeneration, Number(data.intakeSessionGeneration) || 0);
        announceMilestone = data.outcome === 'stored';
        const status = normalizeStatus(data.status);
        const current = rememberStatus(entry.findingId, status?.id === entry.findingId ? status : null, false, statusRead);
        entry.status = current.status;
        entry.missing = current.missing;
        const activeField = document.activeElement;
        // Preserve edits typed while the request was still in flight.
        if (urlInput.value === submitted.url) urlInput.value = '';
        if (notesInput.value === submitted.notes) notesInput.value = '';
        notice.textContent = t(data.outcome === 'duplicate' ? 'Bereits vorhanden. Der bestehende Fall bleibt erhalten.'
          : 'Gespeichert. Bereit für die nächste URL.');
        if (![urlInput, payloadInput, notesInput].includes(activeField)) urlInput.focus();
      } catch (_) {
        if (requestLifecycle !== lifecycle) return;
        entry.saveState = 'unconfirmed';
        entry.error = t('Die Speicherung wurde nicht bestätigt. Die Anfrage kann angekommen sein. Deine Eingabe bleibt erhalten; eine erneute Übernahme erfolgt nur auf deinen Klick.');
        notice.textContent = t('Speicherung nicht bestätigt. Deine Eingabe bleibt erhalten.');
      } finally {
        clearTimeout(timeout);
        if (requestLifecycle !== lifecycle) return;
        saving = false;
        submitButton.disabled = false;
        submitButton.textContent = submitLabel;
        form.removeAttribute('aria-busy');
        render();
        renderActivity();
        persist();
        if (entry.saveState === 'confirmed') {
          poll();
          channel?.postMessage({ type: 'activity' });
        }
        refreshActivity();
      }
    });

    form.addEventListener('input', persist);
    const refreshVisible = () => {
      if (document.visibilityState !== 'visible') {
        clearTimeout(pollTimer);
        clearTimeout(activityTimer);
        return;
      }
      refreshAll = true;
      poll();
      refreshActivity();
    };
    document.addEventListener('visibilitychange', refreshVisible);
    window.addEventListener('focus', refreshVisible);
    window.addEventListener('pageshow', (event) => {
      const historyNavigation = performance.getEntriesByType('navigation')[0]?.type === 'back_forward';
      if (!event.persisted && !historyNavigation) return;
      // Another page in this tab may have changed the shared draft/history.
      // Both BFCache and native history form restoration can revive stale values.
      // Re-read the shared state after the browser finishes restoring its DOM.
      lifecycle++;
      statusController?.abort();
      activityController?.abort();
      ++activitySequence;
      restoreState();
      saving = false;
      polling = false;
      activityActionPending = false;
      openChannel();
      submitButton.disabled = false;
      submitButton.textContent = submitLabel;
      form.removeAttribute('aria-busy');
      notice.textContent = '';
      toasts.replaceChildren();
      updatePayloadLabel();
      render();
      refreshVisible();
    });
    window.addEventListener('pagehide', () => {
      persist();
      lifecycle++;
      submissionController?.abort();
      statusController?.abort();
      activityController?.abort();
      clearTimeout(pollTimer);
      clearTimeout(activityTimer);
      channel?.close();
      channel = null;
    });
    if (sessionOnly && periodControl && minutesControl) {
      activityToggle?.addEventListener('click', () => {
        statsExpanded = !statsExpanded;
        applyStatsExpansion();
        persistPreferences();
      });
      [sessionOnly, periodControl, minutesControl].forEach((control) => control.addEventListener('change', () => {
        persistPreferences();
        refreshActivity();
      }));
      endSessionButton?.addEventListener('click', () => changeSession('end'));
      resumeSessionButton?.addEventListener('click', () => changeSession('resume'));
    }
    function openChannel() {
      if (channel) return;
      try {
        channel = new BroadcastChannel('librebugbounty.intake.activity');
        channel.addEventListener('message', (event) => {
          if (event.data?.type === 'activity') refreshActivity();
        });
      } catch (_) { /* Focus and periodic refresh still synchronize tabs. */ }
    }
    openChannel();
    history.hidden = false;
    updatePayloadLabel();
    render();
    poll();
    refreshActivity();
  }
})();
