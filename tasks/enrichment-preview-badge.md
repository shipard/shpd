# Enrichment badge v preview extrahovaného dokladu

**Stav:** hotovo

**Cíl:** Uživatel v náhledu extrahovaného dokladu vidí, které řádky byly
doplněny z historie (Row History Enrichment), odkud návrh pochází a co přesně
bylo doplněno — včetně účtu, který tabulka řádků jinak nezobrazuje. Data už
tečou v `_resolve.rows[i].enrichment`; tento task je pouze zviditelňuje.

---

## Návaznost

- Navazuje na `tasks/row-history-enrichment.md` (hotovo, nasazeno na ns-alpha).
- Enrichment blok (`RowHistoryEnricher`, ~ř. 122–172):
  `{ matchedBy: "historyExactRaw"|"historyExactNorm"|"historyFuzzy"|null,
     confidence: "high"|"medium"|null, sourceDocId, suggested: {ourCode?, vatCode?, account?},
     skipped?: "noItemRow"|"hasOurCode" }` — zapisuje se vždy, i pro
  nenapárované řádky.
- Frontend: `DocumentExchangePreview.svelte` — tabulka řádků ř. ~369–413,
  resolve `statusBadge` snippet ř. ~216 (glyphy ✓/+/?/✗, třídy
  `shpd-exchange__status--*`). Komponenta je sdílená (modal, feed karta) —
  badge pojede všude automaticky, řízeno čistě přítomností dat.
- Frontend testy: `node --test 'tests/Unit/**/*.test.mjs'` pro čisté JS moduly
  (vzor `components/chat/toolLabels.js` + `tests/Unit/toolLabels.test.mjs`);
  `npm run check:i18n` hlídá paritu cs/en klíčů.

## Potvrzená rozhodnutí

| D | Rozhodnutí |
|---|---|
| D1 | Badge v item buňce vedle resolve badge, glyph `⟲`, třídy `shpd-exchange__enrich--high` / `--medium`, **neinteraktivní**, vizuálně tišší než resolve badge. Renderuje se jen při `matchedBy !== null`. |
| D2 | Tooltip: „Doplněno z historie — doklad {docNumber}, {přesná shoda\|podobný text}" + výčet doplněných polí dle `suggested` (položka / DPH / účet). Zviditelňuje i doplněný účet bez přidávání sloupce. |
| D3 | Backend: `RowHistoryEnricher` přidá `sourceDocNumber` (z `docs_core_heads.doc_number`) do enrichment bloku. Surové `sourceDocId` uživateli nic neřekne. |
| D4 | Souhrn v hlavičce sekce Řádky vpravo: „Z historie: N řádků", jen když N > 0. Prostý počet, žádný zlomek (jmenovatel komplikují `skipped` varianty). |
| D5 | i18n klíče `exchange.preview.enrich.*` v `cs.js` + `en.js`. |
| D6 | Bez prokliku na zdrojový doklad — tooltip stačí, link případný follow-up. |
| D7 | Mimo scope: sloupec s účtem, editace enrichment návrhů, změny v jiných konzumentech komponenty. |

## Scope

**In:**
1. `sourceDocNumber` v enrichment bloku (backend + testy).
2. Čistý JS modul `enrichBadge.js` (počet, tooltip text) + node testy.
3. Badge snippet + souhrn v `DocumentExchangePreview.svelte` + CSS.
4. i18n cs + en.

**Out:** D6/D7 body; jakékoli změny resolve pipeline, matching algoritmu nebo
JSON schématu (`_resolve` je `additionalProperties: true`, přidání
`sourceDocNumber` žádnou změnu schématu nevyžaduje).

---

## Změny soubor po souboru

### 1. `modules/core/exchange/src/Enrich/RowHistoryEnricher.php` (D3)

- Do history SELECTu přidat `h.doc_number AS doc_number` (JOIN na
  `docs_core_heads h` už existuje).
- Do výchozího enrichment bloku `'sourceDocNumber' => null`; při matchi
  `$enrichment['sourceDocNumber'] = (string) ($hist['doc_number'] ?? '') ?: null`
  (historické doklady z migrace mají doc_number vždy, ale defenzivně).
- Determinismus nedotčen — jen další sloupec z téhož dotazu.

### 2. `tests/Unit/Module/Core/Exchange/Enrich/RowHistoryEnricherTest.php`

- Rozšířit fixture history rows o `doc_number`; asserty na
  `sourceDocNumber` u matchnutého řádku a `null` u nenapárovaného.

### 3. `frontend/src/components/exchange/enrichBadge.js` (nový, čistý modul)

```js
/** Počet řádků doplněných z historie (matchedBy !== null). */
export function enrichedRowCount(resolveRows)

/** Klíč stupně shody pro i18n: 'exact' (ExactRaw/ExactNorm) | 'fuzzy'. */
export function matchKindKey(matchedBy)

/** Seznam i18n klíčů doplněných polí dle `suggested`:
 *  ourCode → 'item', vatCode → 'vat', account → 'account'. */
export function suggestedFieldKeys(suggested)
```

Bez závislostí na Svelte — testovatelné přes `node --test`. Sestavení finálního
tooltip stringu (interpolace docNumber, join polí) zůstává v komponentě, kde
je `t()`.

### 4. `frontend/src/components/exchange/DocumentExchangePreview.svelte` (D1, D2, D4)

- Import helperů; `let enrichedCount = $derived(enrichedRowCount(resolve?.rows))`.
- Snippet `enrichBadge(enrichment)`:
  ```svelte
  {#snippet enrichBadge(e)}
    {#if e?.matchedBy}
      <span class="shpd-exchange__enrich shpd-exchange__enrich--{e.confidence}"
            title={enrichTitle(e)}>⟲</span>
    {/if}
  {/snippet}
  ```
  `enrichTitle(e)` — lokální funkce:
  `t('exchange.preview.enrich.tooltip', {docNumber, kind})` + výčet polí
  z `suggestedFieldKeys` přeložený přes `exchange.preview.enrich.field.*`,
  joinovaný `', '`.
- Render v item buňce hned za stávající
  `{@render statusBadge(resolve?.rows?.[i]?.item, …)}`:
  `{@render enrichBadge(resolve?.rows?.[i]?.enrichment)}`.
- Souhrn (D4) v hlavičce sekce Řádky:
  ```svelte
  <h3 class="shpd-exchange__section-heading shpd-exchange__section-heading--split">
    <span>{t('exchange.preview.section.rows')}</span>
    {#if enrichedCount > 0}
      <span class="shpd-exchange__enrich-summary">
        {t('exchange.preview.enrich.summary', { count: enrichedCount })}
      </span>
    {/if}
  </h3>
  ```
  (ověřit, jak `t()` interpoluje — pokud nepodporuje parametry, složit string
  v JS; zkontrolovat vzor v existujících klíčích).
- CSS: `.shpd-exchange__enrich` — menší font, kulatý, `--high` tlumená
  zelená / `--medium` tlumená žlutá, výrazně tišší než
  `.shpd-exchange__status--*`; `.shpd-exchange__enrich-summary` — menší,
  sekundární barva, vpravo (flex `space-between` na `--split` heading).

### 5. `frontend/src/i18n/cs.js` + `en.js` (D5)

Klíče (přesné znění doladit při implementaci):
- `exchange.preview.enrich.tooltip` — „Doplněno z historie — doklad {docNumber} ({kind})" / „Filled from history — document {docNumber} ({kind})"
- `exchange.preview.enrich.kind.exact` — „přesná shoda" / „exact match"
- `exchange.preview.enrich.kind.fuzzy` — „podobný text" / „similar text"
- `exchange.preview.enrich.field.item` — „položka" / „item"
- `exchange.preview.enrich.field.vat` — „DPH" / „VAT"
- `exchange.preview.enrich.field.account` — „účet" / „account"
- `exchange.preview.enrich.summary` — „Z historie: {count} ř." / „From history: {count} rows"

`npm run check:i18n` musí projít.

### 6. `frontend/tests/Unit/enrichBadge.test.mjs` (nový)

- `enrichedRowCount`: prázdné/null pole → 0; mix matchedBy null + skipped +
  matched → počítá jen matched; blok chybí úplně → 0.
- `matchKindKey`: ExactRaw i ExactNorm → 'exact'; Fuzzy → 'fuzzy'.
- `suggestedFieldKeys`: plná trojice → 3 klíče v deterministickém pořadí
  (item, vat, account); jen ourCode → 1; prázdné `{}` → [].

---

## Testy

- PHPUnit: `--filter RowHistoryEnricher` (rozšířené asserty na
  `sourceDocNumber`) — zelené.
- Frontend: `npm test` (nový `enrichBadge.test.mjs`) + `npm run check:i18n`.
- Ruční ověření na ns-alpha: opakovaná faktura → preview ukazuje ⟲ badge
  s tooltipem (číslo dokladu, stupeň shody, výčet polí vč. účtu) a souhrn
  „Z historie: N ř."; doklad bez historie → žádný badge, žádný souhrn;
  ai_failed a účetní doklady → beze změny renderu.

## Commit strategie

1. **Commit 1:** backend `sourceDocNumber` (`RowHistoryEnricher` + PHPUnit).
2. **Commit 2:** frontend — `enrichBadge.js` + node testy, badge + souhrn
   v `DocumentExchangePreview.svelte`, CSS, i18n cs/en.

## Hotovo když

- [ ] Enrichment blok nese `sourceDocNumber`; PHPUnit zelený.
- [ ] ⟲ badge se renderuje jen u řádků s `matchedBy !== null`, neinteraktivní,
      vizuálně tišší než resolve badge.
- [ ] Tooltip obsahuje číslo dokladu, stupeň shody a výčet skutečně
      doplněných polí (vč. účtu).
- [ ] Souhrn „Z historie: N ř." se zobrazí jen při N > 0.
- [ ] `npm test` i `npm run check:i18n` zelené.
- [ ] Ručně ověřeno na ns-alpha (badge, tooltip, souhrn, negativní případy).
