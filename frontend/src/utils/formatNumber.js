// Formátování částek pro zobrazení — jediné sdílené místo (dřívější
// call-sites měly ad-hoc Intl.NumberFormat kopie). Locale se řídí zvoleným
// jazykem UI, ne prohlížečem — čísla musí ladit se zbytkem obrazovky.

import { language } from '../i18n/index.js';

const formatters = new Map();

function formatter(locale, fractionDigits) {
  const key = `${locale}:${fractionDigits}`;
  let fmt = formatters.get(key);
  if (!fmt) {
    fmt = new Intl.NumberFormat(locale, {
      minimumFractionDigits: fractionDigits,
      maximumFractionDigits: fractionDigits,
    });
    formatters.set(key, fmt);
  }
  return fmt;
}

/**
 * @param {number} value
 * @param {{thousands?: boolean}} [options] thousands = zobrazení v tisících
 *        (dělení 1000 + zaokrouhlení, bez desetinných míst)
 * @returns {string}
 */
export function formatAmount(value, { thousands = false } = {}) {
  const locale = language.current === 'en' ? 'en-US' : 'cs-CZ';
  if (thousands) {
    return formatter(locale, 0).format(Math.round(value / 1000));
  }
  return formatter(locale, 2).format(value);
}
