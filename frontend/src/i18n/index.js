// Barrel export for the i18n module. Other code should import from here
// (`'../i18n'`), not directly from `stores/language.svelte.js`, so the
// store's location stays an implementation detail.

export { language, t, tn } from '../stores/language.svelte.js';
