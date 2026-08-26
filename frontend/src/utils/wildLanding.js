// Přistání při vstupu do sekce/domečku wild shellu (R6/D3/D7) — čistá
// funkce bez runes (unit-testovatelná přes node --test).

/**
 * @param {{tab: string}|null} lastTab záznam z lastTabBySection; null = první vstup
 * @param {string|null} section        id sekce; null = domeček
 * @returns {'ai'|string|null} 'ai' = AI záložka; jiný string = id leafu
 *   (může být stale — volající validuje proti stromu, miss = default);
 *   null = výchozí leaf (domeček → dashboard). Domeček 'ai' nikdy nevrací
 *   (D3 — bez scope), ani defenzivně při vadném záznamu.
 */
export function resolveLanding(lastTab, section) {
  const tab = lastTab?.tab ?? null;
  if (tab === 'ai') return section === null ? null : 'ai';
  if (tab !== null) return tab;
  return section === null ? null : 'ai';
}
