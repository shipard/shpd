# Task: Rekapitulace DPH — výpočet na úrovni dokladu a převzatá rekapitulace — #75

**Stav:** PRD
**Issue:** #75 — komentář „Rozhodnutí (2026-09-09)" C1–C6, R1–R6
**Spec:** `docs/vat-calculation.md` (napsáno předem jako design dokument — **je
autoritativní**, tento task ho implementuje)
**Návaznost:** `docs.core` (`DocDocument`, `DocRowCalculator`, formuláře hlavičky),
`core.exchange` (canonical, `DocumentApplier`, `DocumentValidator`), stará strana
`old_shipard` `DocsRunner` (samostatný task tam), reimport, pak zlatý test
`tasks/vat-filings.md`. Souvisí #73 (hotovostní úhrada na Kč — jiná věc).

## Cíl

Dvě části, dva bloky commitů:

**A — výpočet (C1–C6):** rekapitulace v režimu z ceny celkem se dnes sčítá z per-řádek
zaokrouhlených základů; má se počítat na úrovni dokladu ze součtu cen. `vat_calc_source`
existuje, ale nečte se — implementovat obě metody, default `0 z hlavičky`.

**B — autorita (R1–R6):** rekapitulace importovaného a přijatého dokladu je fakt;
`vat_recap_source` `převzatá` ji uloží, jak přišla, a nechá ji editovat. Stará strana
začne posílat `vatRecap` z `e10doc_core_taxes` + `recapSource: declared`.

Před implementací **přečti**:

- `docs/vat-calculation.md` celý; issue #75 vč. komentářů
- `modules/docs/core/src/DocDocument.php`: docblock třídy (kroky 5–7 `beforeSave`),
  `calculateRowVat`, `buildVatRecapitulation`, `sumTotals`, `applyTotalRounding`,
  `applyRounding`, `applyDomesticAmounts` (invarianty `_dom`); `DocRowCalculator::computeVat`
- `modules/docs/core/tables/docs_core_heads.jsonc` skupina `vat` (`vat_mode`,
  `vat_calc_source`, `vat_rounding_mode`), `docs_core_vat_recap.jsonc`;
  `config/vatModes.jsonc`, `vatCalcSources.jsonc`, `roundingModes.jsonc`
- `DocsHeadsFormBase.php` (select `vat_calc_source`, defaulty), `IssuedInvoiceForm`,
  `ReceivedInvoiceForm`, `CashDoc*Form`, `CashRegister*Form` — kde je recap zobrazený
- `tests/Unit/Module/Docs/Core/DocDocumentVatRecapTest.php` — zejména testy mode 2
  (víceřádková skupina se zbytky): **mění se očekávání**, viz §A3
- `docs/exchange-format.md` §`vat`, §`vatRecap a totals — vstup vs. autorita`, tabulka
  issues; `modules/core/exchange/src/Document/DocumentApplier.php` (kde volá
  `DocDocument`, `VatModeDerivation`, `deriveTotalRoundingMode`),
  `DocumentValidator.php` (`checkRowsVsRecap`, `checkVatRecapArithmetic`)
- old_shipard: `modules/imports/newShipard/libs/runners/DocsRunner.php` (`vat` objekt,
  `VAT_MODE_MAP`, `totals`), tabulka `e10doc_core_taxes` (sumBase/sumTax/sumTotal/
  sumPrice per taxCode, `*Hc`), `e10doc/core/tables/heads.php::calcTaxes` (referenční
  sémantika `taxMethod`, `taxManual`)
- `docs/docs-mvp.md` §7.4, §8 — po implementaci odkázat na `vat-calculation.md`

## Rozhodnutí navíc (implementační)

- **I1** Převzatá rekapitulace se **neváže na řádky**: `docs_core_vat_recap` zůstává
  bez FK na řádky; párování při `rows_recap_mismatch` je jen per (kód, sazba).
- **I2** Při `převzatá` se `_dom` počítá stejně jako dnes (`round(cur × kurz)` na
  rekapitulaci, top-down dorovnání řádků) — invarianty `applyDomesticAmounts` platí
  beze změny. Import ze staré strany nese `vatRecap` v měně dokladu; staré `*Hc`
  se **nepřenáší** (nový kurz × haléřové dorovnání dá totéž až na dorovnání, které
  chceme mít konzistentní s ostatními doklady). Ověřit v migrační smyčce.
- **I3** Přepnutí `převzatá → přepočítaná` ve formuláři = recap se při uložení
  přegeneruje z řádků (žádný dialog); `přepočítaná → převzatá` = aktuální
  přepočítaná se zkopíruje jako startovní převzatá a odemkne editaci.
- **I4** Přijatý doklad z AI (R3): `převzatá` jen když `vatRecap` má ≥ 1 řádek a **každý**
  řádek projde `checkVatRecapArithmetic` bez `vat_recap_inconsistent`; jinak
  `přepočítaná` + dnešní chování. Applier zapíše důvod do `_resolve.issues`
  (`recap_source_computed_fallback`, info).
- **I5 — Dorovnání řádků v obou měnách (spec § 7, rozhodnutí 2026-09-09).** Řádkové
  `vat_base`/`vat_amount` se dorovnávají na rekapitulaci top-down **i v měně dokladu**,
  ne jen v `_dom` — stejný algoritmus jako dnešní krok 3 `applyDomesticAmounts`,
  aplikovaný dvakrát nezávisle (cur → recap cur; dom → recap dom). Důvod: deník účtuje
  výnos/náklad z řádků a 311/321 z hlavičky v obou měnách; bez dorovnání cur je
  sloupec měny dokladu rozjetý o haléře (a u kurzu 1 se dva sloupce téhož řádku liší);
  tisk řádkových DPH musí dávat rekapitulaci. Cena řádku (`total_price`) se nemění.
  U `převzatá` jen do tolerance `max(0,02; 0,01 × počet řádků skupiny)` — nad ni řádky
  zůstanou a vydá se `rows_recap_mismatch`; u `přepočítaná` je rozdíl konstrukčně v mezi.

## Scope A — výpočet

### A1. `buildVatRecapitulation` (C1–C3)

- Číst `vat_calc_source` z `$data` (default 0).
- **`0 z hlavičky`:** skupina sčítá **řádkové ceny** (`total_price` po slevě), ne
  `vat_base`/`vat_total`. Mode 1: `base = Σ`, `tax = applyRounding(base × pct/100,
  vat_rounding_mode)`; mode 2: `total = Σ`, `base = applyRounding(total / (1 + pct/100),
  vat_rounding_mode)`, `tax = round(total − base, 2)`. `noPayTax` / reverse-charge /
  0 % větve beze změny sémantiky (základ = Σ cen, daň dle dnešních pravidel).
- **`1 z řádků`:** dnešní chování (Σ `vat_base`, Σ `vat_total`, daň rozdílem v mode 2,
  `round(Σ base × pct)` v mode 1) — vyčlenit do samostatné privátní metody, aby
  obě větve byly čitelné.
- `DocRowCalculator::computeVat` beze změny (řádkové hodnoty zůstávají informativní).
- **Dorovnání cur (I5):** z kroku 3 `applyDomesticAmounts` vyčlenit čistou metodu
  `reconcileRowsToRecap(array &$rows, array $recap, string $suffix, ?float $tolerance)`
  (`$suffix` `''` / `'_dom'`), volat pro obě měny; `vat_total` řádku v mode 1 =
  `vat_base + vat_amount` po dorovnání, v mode 2 zůstává = cena. Pořadí kroků
  `beforeSave` beze změny (recap → součty → zaokrouhlení → dom + dorovnání obou měn).

### A2. Formuláře

- `vat_calc_source` default 0 zůstává; hint u selectu: „Z hlavičky = daň ze součtu
  řádků v sazbě (norma). Z řádků = součet řádkových daní (historický režim)."
- Prodejky a pokladní doklady (`docs.cashRegister`, `docs.cashDocs`): pole skrýt,
  hodnota 0 (C2 — časem odstranit i z FVB; teď jen skrýt tam, kde nikdy nemá smysl).

### A3. Testy

- `DocDocumentVatRecapTest`: test „víceřádková skupina v mode 2 se zbytky" se
  **přejmenuje a přepne** na `vat_calc_source = 1`; přidat zrcadlový test pro `0`
  s očekáváním dokladové úrovně (2 × 55 → 90,91 / 19,09 / 110,00). Mode 1: potvrdit,
  že `0` a `1` dávají totéž při jednom řádku a liší se při více řádcích se zbytky.
  `vat_rounding_mode` na celé Kč v mode 2 (základ na Kč, daň rozdílem).
- **Invarianty v obou měnách (I5, spec § 7):** nový test nad dokladovou úrovní
  (mode 1 i 2, tuzemský kurz 1 i cizí měna, více řádků se zbytky): Σ řádků = recap
  per skupina v cur i dom; Σ recap = hlavička; `total_base + total_vat + total_rounding
  = total_amount` v obou; u kurzu 1 `vat_base == vat_base_dom` na každém řádku.
  Dorovnání dopadá na poslední nenulový řádek skupiny; `total_price` řádků nezměněno.
- Regres: existující testy `_dom` invariant, reverse-charge, `noPayTax` zelené.

### A4. Dokumentace

`docs/docs-mvp.md` §7.4 a §8.x: zkrátit na odkaz na `docs/vat-calculation.md`
(neduplikovat). Help formuláře dokladu: odkaz na sekci.

## Scope B — převzatá rekapitulace

### B1. Schéma

`docs_core_heads.vat_recap_source` enumInt, default 0, cfgItem
`docs.core.vatRecapSources` (`0 Přepočítaná` / `1 Převzatá`, `name:cs/en`), skupina
`vat`. Bez indexu. `ds-upgrade` doplní sloupec s defaultem (existující doklady =
přepočítaná; reimport je přepíše na převzatou).

### B2. `DocDocument::beforeSave` (R1, I2, I3)

- Při `vat_recap_source = 1`: krok 6 **nevolá** `buildVatRecapitulation`; načte
  recap z `$data['vat_recap']` (formulář / applier) nebo z DB (uložení bez změny
  recapu), doplní `sum_*` z definice kódu (autorita definice, ne vstupu),
  `is_reverse_pair` zachová, `_dom` přepočítá jako dnes. Kroky 7 beze změny.
- Validace `vat_recap` při převzaté: kód existuje pro zemi registrace (jinak
  `DomainException` jako dnes u neznámého kódu), `pct` ∈ sazbám kódu k DUZP
  (warning, ne blok — historické sazby), `base + tax = total` ± 0,02 (warning
  `vat_recap_inconsistent`).
- Přechod zdroje (I3) řeší `beforeSave` porovnáním staré a nové hodnoty
  `vat_recap_source`.
- **`rows_recap_mismatch` jako warning při uložení** (R4): Σ řádkových cen per (kód,
  sazba) vs. recap `base` (mode 1) / `total` (mode 2), tolerance `max(0,02; 0,01 ×
  počet řádků skupiny)` — **stejná mez jako pro dorovnání (I5)**: v mezi se řádky
  dorovnají a warning není; nad mez se řádky nedotknou a warning je. Warning
  mechanismus formuláře: `docs/edit-forms.md` §8 „Warningy (neblokující)".

### B3. Formuláře (R4)

- Přepínač `vat_recap_source` v sekci DPH hlavičky (`DocsHeadsFormBase`), `triggers:
  reload`.
- Tab „Rekapitulace DPH": při `převzatá` subtable `docs_core_vat_recap` editovatelná
  (`vat_code` select dle země, `vat_pct`, `base`, `tax`, `total`; `_dom` a `sum_*`
  read-only, dopočítané); při `přepočítaná` read-only jako dnes.
- Zobrazit warningy (`rows_recap_mismatch`, `vat_recap_inconsistent`) v bannneru
  formuláře.

### B4. Exchange (R3, I4)

- Canonical `vat.recapSource`: `"computed"` | `"declared"` (nullable → applier
  odvodí dle I4). `vat.calcSource`: `"header"` | `"rows"` (nullable → 0).
- `DocumentApplier`: `declared` → `vat_recap_source = 1`, `vatRecap` předá do
  `DocDocument` jako `vat_recap` (mapování polí canonical → tabulka; `_dom` nechá
  spočítat). `computed`/null u přijatých s konzistentním `vatRecap` → `declared`
  (I4); u vystavených → computed. `VatModeDerivation` a `deriveTotalRoundingMode`
  beze změny.
- `DocumentValidator`: `checkRowsVsRecap` zůstává warning; `checkVatRecapArithmetic`
  zůstává; nový info issue `recap_source_computed_fallback`.
- `docs/exchange-format.md`: přepsat §`vatRecap a totals — vstup vs. autorita`
  podle R3 (vatRecap **je** autorita pro `declared`), doplnit `vat.recapSource`,
  `vat.calcSource`, tabulku issues.

### B5. Stará strana — `old_shipard` task (samostatný, číslo dle README tam)

- `DocsRunner`: `vat.recapSource = "declared"` pro každý doklad, který má řádky
  v `e10doc_core_taxes`; `vatRecap[]` = `{ vatCode (mapa EUCZ→cz-), pct, base:
  sumBase, tax: sumTax, total: sumTotal }` (bez `*Hc`, I2); `vat.calcSource` z
  `taxMethod` (1 → `header`, 2 → `rows`). `taxManual` se nepřenáší (převzatá
  pokrývá oba případy).
- Migrační smyčka (task 33): L2 kontrola recapu per doklad proti `e10doc_core_taxes`
  (base/tax/total per kód, ± 0,00).

### B6. Testy

- `DocDocument`: převzatá se nepřepočítá; `_dom` invarianty platí; přepnutí obou
  směrů (I3); `rows_recap_mismatch` warning po změně částky řádku; `sum_*` z definice.
- Applier: `declared` uloží recap 1:1 (ověřit na 90,91 / 19,09 příkladu); přijatý
  s konzistentním recapem → declared; s nekonzistentním → computed + info issue.
- Integrační nad dev DS 4l3j: uložení FPB s převzatou rekapitulací → 343 v deníku
  = převzatá daň.

### B7. Dokumentace

`docs/vat-calculation.md` § 5 doplnit o implementační detaily (I1–I4) po hotovu;
`docs/exchange-format.md` (B4); help formuláře; `modules/docs/core/docs/` tabulka
`docs_core_heads.md` nový sloupec.

## Mimo scope

- #73 (hotovostní úhrada faktury na Kč) — jiná věc.
- Koeficientová metoda (C4).
- Odstranění `vat_calc_source` z FVB (C2 „časem").
- Reimport a zlatý test — provede David / navazuje `tasks/vat-filings.md`.

## Commity

A1. `buildVatRecapitulation`: dokladová úroveň + `vat_calc_source` obě metody + testy (A1, A3).
A2. Formuláře (hint, skrytí u prodejek/PD) + docs-mvp odkaz (A2, A4).
B1. Sloupec + cfgItem + `beforeSave` převzatá + validace + warning + testy (B1, B2, B6 část).
B2. Formuláře: přepínač + editovatelný recap (B3).
B3. Exchange: canonical pole, applier, validator, dokumentace (B4).
B4. Dokumentace (B7).
(old_shipard: samostatný task + commit, B5.)

## Hotovo když

- [ ] Testy zelené (recap obě metody, převzatá, applier, invarianty dorovnání v cur i dom).
- [ ] Tuzemský doklad v mode 2 se dvěma řádky se zbytky: řádkové `vat_base` a
      `vat_base_dom` jsou shodné, Σ řádků = rekapitulace, deník dokladu vyrovnaný
      v cur i dom.
- [ ] `ds-upgrade` na dev DS 4l3j projde; formulář FPB umí přepnout na převzatou
      a editovat rekapitulaci; změna částky řádku vydá warning, neblokuje.
- [ ] Nová prodejka v Novém Shipardu 2 × 55,00 s DPH 21 % má rekapitulaci
      90,91 / 19,09 / 110,00 (mode 2, `vat_calc_source 0`).
- [ ] Po reimportu zdroje 689089 (`btpg-p`): `docs_core_vat_recap` = `e10doc_core_taxes`
      per doklad na haléř (L2 kontrola, task 33); prodejka `1422600010` 90,91 / 19,09.
- [ ] Zlatý test `tasks/vat-filings.md`: řádné DP3 01–04/2026 dává podané ř. 64
      260 864 / 135 796 / 120 203 / 143 583.
