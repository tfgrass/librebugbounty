(() => {
  'use strict';

  const node = document.getElementById('studio-i18n');
  let config = { locale: 'de', messages: {} };
  try {
    const parsed = JSON.parse(node?.textContent || '{}');
    if (parsed && (parsed.locale === 'de' || parsed.locale === 'en') && parsed.messages && typeof parsed.messages === 'object') config = parsed;
  } catch (_) {}

  const replace = (message, parameters) => String(message).replace(/\{([^}]+)\}/g, (match, name) => Object.prototype.hasOwnProperty.call(parameters, name) ? String(parameters[name] ?? '') : match);
  const t = (key, parameters = {}) => replace(config.messages[key] ?? key, parameters);
  const languageTag = config.locale === 'en' ? 'en-GB' : 'de-DE';
  const numberFormatter = new Intl.NumberFormat(languageTag);
  const dateFormatter = new Intl.DateTimeFormat(languageTag, { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'Europe/Berlin' });
  const dateTimeFormatter = new Intl.DateTimeFormat(languageTag, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Berlin' });

  window.LibreBugBountyI18n = Object.freeze({
    locale: config.locale,
    languageTag,
    t,
    number: (value) => numberFormatter.format(Math.max(0, Number(value) || 0)),
    date: (value) => dateFormatter.format(value),
    dateTime: (value) => dateTimeFormatter.format(value),
  });
})();
