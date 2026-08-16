# AI extrakce dokladů — integrita řádků a rekapitulace DPH (prompt v4.2.0 + validator)

**Stav:** částečně — kód, testy a docs hotové 16. 8. 2026; zbývá nasazení (ds-upgrade + reload profilu, koordinace s ai_analyzer max_tokens) a re-analýza obou referenčních zpráv
**Repo:** nov_shipard (souběžný task v ai_analyzer: `tasks/max-tokens-stop-reason.md`)

## Cíl

Odstranit dvě třídy chyb AI extrakce došlých dokladů, ověřené na reálných
případech (DS `4l3j-z0bz-kz39-echj`, dev server):

1. **Neúplné řádky** (MSG-20260816-0001, faktura ARTEX, 3 strany): model
   extrahoval 8 z 57 položkových řádků, rekapitulaci DPH opsal z dokladu →
   doklad „vypadá OK" (totals sedí), ale chybí položky za ~14 425 Kč bez DPH.
   Stávající `checkTotals` to nechytí, protože porovnává deklarovanou částku
   i proti `sumVatRecap` — a rekapitulace opsaná z téhož dokladu si vždy sedne.
2. **Vymyšlená rekapitulace** (MSG-20260816-0006, účtenka PLECA): ceny položek
   bez DPH (3 × 156,20 = 468,60; na dokladu Zákl. 468,60 / DPH 98,40 /
   Celkem 567,00). Model chybně určil `fromTotal` (součet položek si spároval
   s řádkem Zákl. místo s částkou k úhradě) a rekapitulaci **dopočítal
   pozpátku** (base 387, tax 81,40) místo opsání → podplaceno o 98,40 Kč.
   Dopočtená čísla jsou navíc vnitřně nekonzistentní (387 + 81,40 ≠ 468,60;
   387 × 0,21 ≠ 81,40) — aritmeticky odhalitelné.

Obrana je dvouvrstvá: prompt (D1, D4) snižuje četnost chyb, serverový
validator (D3, D5) je zachytí, i když prompt selže. Serverová bariéra je
skutečná hranice (D9/D10 filosofie).

## Návaznost

- `modules/core/mail/profiles/czech_general.jsonc` — profil s prompt šablonou
  (v4.1.0) a output_schema; do DS se propisuje přes provisioner /
  `AiProfileReloadCommand`.
- `modules/core/exchange/src/Document/DocumentValidator.php` — validace
  kanonického dokumentu; warnings jdou do UI, errors blokují /apply.
- `modules/core/exchange/src/Document/VatModeDerivation.php` —
  `sumItemRows()`, `tolerance()`; derivace `vat.mode` z recap/totals.
- Testy: `tests/Unit/Module/Core/Exchange/Document/DocumentValidatorTest.php`.
- Dokumentace promptů: `modules/core/mail/docs/ai-prompts.md` (zmiňuje v4.1.0).
- ai_analyzer strana (max_tokens, stop_reason) je samostatný task v repu
  ai_analyzer — **bez něj D1 u dlouhých faktur narazí na strop 4096 tokenů**,
  nasazovat společně.

## Scope

### 1. `modules/core/mail/profiles/czech_general.jsonc` — prompt v4.2.0 (D1, D4)

Bump `prompt_version` na `v4.2.0` (řádek 24, pravidlo `source.promptVersion
je "v4.1.0"` v šabloně i hodnota v ukázkovém JSONu — celkem 3 výskyty).

**D1 — úplnost řádků.** Do sekce PRAVIDLA doplnit:

- „rows" MUSÍ obsahovat VŠECHNY položkové řádky dokladu — u vícestránkových
  dokladů ze všech stran. NIKDY řádky nezkracuj, neshrnuj ani nevybírej
  „reprezentativní" podmnožinu; doklad s 50 položkami má 50 řádků v „rows".
- Před vrácením výsledku ověř, že součet „totalPrice" položkových řádků
  odpovídá základům (fromBase) resp. celkům (fromTotal) v rekapitulaci
  dokladu. Pokud nesedí, řádky doplň — nesedící součet znamená, že jsi
  nějaké řádky vynechal.

Do ukázkového JSONu přidat druhý položkový řádek (prolomení kotvy
jednořádkové ukázky); `vatRecap`/`totals` v ukázce přepočítat, aby ukázka
zůstala konzistentní.

**D4 — rozhodování fromTotal a zákaz dopočítávání.** Nahradit stávající
pravidlo `"vat".mode:` přesnějším zněním:

- „fromTotal" vrať POUZE tehdy, když součet položkových řádků odpovídá
  ČÁSTCE K ÚHRADĚ (řádky „Celkem", „K úhradě", „Zaplaceno", „Total").
  Pokud součet položek sedí na řádek označený „Základ" / „Zákl." / „bez
  DPH", jsou ceny bez daně → „fromBase" — i na účtence. Samotný fakt, že
  jde o účtenku, NENÍ důvod pro „fromTotal".
- „vatRecap" a „totals" VŽDY opisuj z rekapitulačního bloku dokladu
  (základ / DPH / celkem). NIKDY je nedopočítávej z cen položek — pokud na
  dokladu rekapitulace není, pole vynech. Vrácená rekapitulace musí být
  slovo od slova to, co je na dokladu.

Zachovat stávající větu „Čísla z dokladu vždy opisuj…" (obecné pravidlo),
nové znění ji konkretizuje pro recap/totals.

### 2. `modules/core/exchange/src/Document/DocumentValidator.php` (D3, D5)

**D3 — `checkRowsVsRecap()` → warning `rows_recap_mismatch`.**

- Vstup: `rows` (přes `VatModeDerivation::sumItemRows()`), `vatRecap`
  (fallback `totals`).
- Očekávaná hodnota podle `vat.mode`: `fromBase` → Σ `vatRecap[].base`
  (fallback `totals.totalBase`); `fromTotal` → Σ `vatRecap[].total`
  (fallback `totals.totalAmount` − `totalRounding`).
- Tolerance: `VatModeDerivation::tolerance($rowCount)` (stejná logika jako
  derivace — per-řádkové zaokrouhlení).
- Skip: chybí rows, chybí recap i totals, nebo `sumItemRows()` vrací null.
- Severity **warning** (recap z dokladu je autoritativní pro účtování;
  mismatch signalizuje neúplné/špatné řádky, ne špatný doklad). Message
  česky s oběma hodnotami, path `rows`.
- Pozor na interakci s `checkVatModeSuspect`/derivací: check volat až po
  případné korekci `vat_mode` Applierem, resp. počítat s režimem po
  derivaci — ověřit pořadí volání ve stávajícím flow a testem pokrýt
  ARTEX konstelaci (fromBase, recap sedí na totals, rows ne).

**D5 — `checkVatRecapArithmetic()` → warning `vat_recap_inconsistent`.**

Pro každý řádek `vatRecap` (skip `isReversePair === true` a řádky s
`pct == 0`):

- `|base + tax − total| ≤ 0.02`
- `|tax − base × pct/100| ≤ max(0.05, |base| × 0.001)` — kryje haléřové
  zaokrouhlení i výpočet koeficientem u dokladů s cenami s DPH.
- Severity **warning**, path `vatRecap[i]`, message česky s konkrétními
  čísly. PLECA případ (387 / 81,40 / 468,60, 21 %) musí selhat na obou
  podmínkách.

### 3. Testy — `DocumentValidatorTest.php`

- D3: ARTEX konstelace (8 řádků Σ 7 657,78; recap base 22 082,55; fromBase)
  → `rows_recap_mismatch`. Konzistentní doklad → bez warningu. fromTotal
  varianta (řádky s DPH vs Σ recap total). Chybějící recap/rows → skip.
  Haléřová odchylka v toleranci → bez warningu.
- D5: PLECA konstelace → `vat_recap_inconsistent`. Korektní recap (např.
  10 330,58 / 2 169,42 / 12 500,00) → bez warningu. Reverse-pair a 0% řádky
  → skip. Hraniční tolerance.
- PHPUnit s úzkým `--filter DocumentValidatorTest`, `timeout_sec: 120`.

### 4. Dokumentace

- `modules/core/mail/docs/ai-prompts.md`: changelog v4.2.0 (D1, D4) s odkazem
  na oba reálné případy.
- `modules/core/exchange/` docs (kde je popsán validator): doplnit oba nové
  checky do přehledu warningů.

## Nasazení

1. JSONC změna → rebuild compiled cfg + `ds-upgrade` (jinak `cfgItem()`
   nová data nevidí).
2. Reload profilu do DS (`AiProfileReloadCommand`) — jinak v DB zůstane
   v4.1.0.
3. Koordinace s ai_analyzer taskem (max_tokens) — deploy analyzeru před
   nebo současně s reloadem profilu.

## Hotovo když

- [x] `czech_general.jsonc` nese v4.2.0 se zněním D1 + D4, ukázka má ≥2 řádky
      a konzistentní recap/totals
- [x] `DocumentValidator` emituje `rows_recap_mismatch` (D3) a
      `vat_recap_inconsistent` (D5) dle specifikace
- [x] Testy pokrývají ARTEX i PLECA konstelaci a hraniční případy; celý
      `DocumentValidatorTest` zelený. Pozn.: PLECA selhává s jistotou na
      `base + tax ≠ total` (odchylka 0,20); odchylka daně 0,13 je uvnitř
      tolerance `max(0.05, |base|×0.001)` = 0,387 — warning vystřelí tak
      jako tak, druhou podmínku kryje samostatný test
- [ ] Re-analýza MSG-20260816-0001: buď kompletních 57 řádků, nebo warning
      `rows_recap_mismatch` na návrhu
- [ ] Re-analýza MSG-20260816-0006: `fromBase`, recap 468,60 / 98,40 / 567,00
      opsaný z dokladu, totalAmount 567,00 — nebo warningy z D3/D5
- [x] `ai-prompts.md` + docs validatoru aktualizované (`exchange-format.md`
      nově nese tabulku issue kódů dokumentů, `ai-analysis.md` odkazuje
      na oba nové warningy)
- [x] i18n check n/a (bez frontend změn); nové warning kódy prochází stávajícím
      zobrazením issues

## Commit strategie

1. `feat(mail): prompt v4.2.0 — úplnost řádků, fromTotal test, zákaz dopočítávání rekapitulace`
2. `feat(exchange): DocumentValidator — rows_recap_mismatch + vat_recap_inconsistent` (vč. testů)
3. `docs: ai-prompts changelog v4.2.0, přehled nových validator warningů`

## Potvrzená rozhodnutí

- **D1** — prompt: povinná extrakce všech řádků + self-check součtu proti
  rekapitulaci; vícerádková ukázka. (potvrzeno 16. 8. 2026)
- **D2** — ai_analyzer: zvýšit max_tokens, kontrolovat stop_reason —
  samostatný task v repu ai_analyzer. (potvrzeno 16. 8. 2026)
- **D3** — validator: warning `rows_recap_mismatch` — součet item řádků vs.
  rekapitulace/totals dle vat.mode. (potvrzeno 16. 8. 2026)
- **D4** — prompt: fromTotal jen při shodě součtu položek s částkou k úhradě;
  recap/totals výhradně opisem, nikdy dopočtem. (potvrzeno 16. 8. 2026)
- **D5** — validator: warning `vat_recap_inconsistent` — vnitřní aritmetika
  řádků rekapitulace (base+tax=total, tax=base×pct). (potvrzeno 16. 8. 2026)
