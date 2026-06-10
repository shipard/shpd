// Sdílená volba zobrazení příloh — 'full' (velké náhledy) / 'grid'
// (miniatury). Explicitní volba uživatele se persistuje v localStorage
// pod jedním klíčem, takže platí napříč detaily (doklady, došlá pošta)
// i sezeními.
//
// Bez uložené volby se default odvozuje reaktivně v getteru: velké
// náhledy na desktopu, miniatury na mobilu (PDF v <iframe> na iOS
// Safari vykreslí jen první stránku, viz AttachmentGrid). Getter proto
// nesmí číst layoutStore při module-level inicializaci — initLayout()
// běží až z main.js po mountu.

import { layoutStore } from './layout.svelte.js';

const ATT_VIEW_KEY = 'shpd_att_view';

// Jen explicitně uložená volba; null = uživatel zatím nepřepínal.
function loadStoredView() {
  try {
    const stored = localStorage.getItem(ATT_VIEW_KEY);
    if (stored === 'full' || stored === 'grid') return stored;
  } catch {
    // localStorage nedostupné -> jen default
  }
  return null;
}

let mode = $state(loadStoredView());

export const attachmentViewStore = {
  get mode() {
    return mode ?? (layoutStore.isMobile ? 'grid' : 'full');
  },
  toggle() {
    const next = this.mode === 'full' ? 'grid' : 'full';
    mode = next;
    try {
      localStorage.setItem(ATT_VIEW_KEY, next);
    } catch {
      // noop — volba platí jen pro aktuální sezení
    }
  },
};
