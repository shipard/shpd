// Maps server error responses to user-facing localized strings.
//
// Server returns `{code, message, details?}` on failure. We try to translate
// `code` via the dictionary (`error.<CODE>` keys); if the code is unknown,
// we fall back to the server's `message` (English by convention) so the
// user still sees something rather than a raw key.
//
// Validation errors carry `details[]` — currently we surface only the first
// detail's `field` / `value` as ICU params for translations that want them.
// Field-level error display (in form fields) is handled separately in
// FormEditor.svelte; this helper is for the "banner" / `alert()` line.

import { t } from './index.js';

/**
 * @param {{code?: string, message?: string, details?: Array}|null|undefined} error
 * @returns {string} Localized error message, with sensible fallbacks.
 */
export function translateError(error) {
  if (!error) return t('common.unknownError');

  const code = error.code;
  if (!code) {
    return error.message ?? t('common.unknownError');
  }

  const params = {
    message: error.message ?? '',
    field: error.details?.[0]?.field ?? '',
    value: error.details?.[0]?.value ?? '',
  };

  const key = `error.${code}`;
  const translated = t(key, params);

  // t() returns the key string when the dictionary has no entry — that's
  // our "unknown code" signal. Fall back to the server message (which the
  // backend writes in English) rather than show "error.SOMETHING_OBSCURE"
  // to the user.
  if (translated === key) {
    return error.message ?? t('common.unknownError');
  }
  return translated;
}
