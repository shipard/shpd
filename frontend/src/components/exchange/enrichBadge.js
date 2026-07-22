// Čisté helpery pro enrichment badge v preview extrahovaného dokladu
// (Row History Enrichment — _resolve.rows[i].enrichment). Bez závislostí
// na Svelte, testovatelné přes `node --test`. Sestavení finálního tooltip
// stringu (t() interpolace) zůstává v komponentě.

/** Počet řádků doplněných z historie (enrichment.matchedBy !== null). */
export function enrichedRowCount(resolveRows) {
  if (!Array.isArray(resolveRows)) return 0;
  return resolveRows.filter((r) => r?.enrichment?.matchedBy != null).length;
}

/**
 * Klíč stupně shody pro i18n:
 * 'exact' (ExactRaw/ExactNorm) | 'fuzzy' | 'dominant' (DominantItem).
 */
export function matchKindKey(matchedBy) {
  if (matchedBy === 'historyDominantItem') return 'dominant';
  return matchedBy === 'historyFuzzy' ? 'fuzzy' : 'exact';
}

/**
 * Seznam i18n klíčů skutečně doplněných polí dle `suggested`,
 * v deterministickém pořadí: ourCode → 'item', vatCode → 'vat',
 * account → 'account'.
 */
export function suggestedFieldKeys(suggested) {
  const order = [
    ['ourCode', 'item'],
    ['vatCode', 'vat'],
    ['account', 'account'],
  ];
  return order
    .filter(([prop]) => suggested?.[prop] != null && suggested[prop] !== '')
    .map(([, key]) => key);
}
