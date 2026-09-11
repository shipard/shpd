# Task: Kontrolní hlášení — ruční režim zařazení dokladu (`cs_mode`) — #77

**Stav:** naplánováno — 2026-09-11, rozhodnutí R1–R4 potvrzená
**Issue:** #77
**Návaznost:** `economy.vat` živé KH (`ControlStatementCalculator`, `tasks/taxes-phase01.md`),
snapshot podání (`economy_vat_filing_items.kh_section`, `tasks/vat-filings.md`), canonical
import `shpd.docs.document.v1` (`modules/core/exchange`). Zlatý test KH 01/2026 zdroje 689089
(`tasks/vat-filing-xml.md` F3-7) se uzavře až s tímto taskem, opravou importu
`partner_doc_number` (F3-1, `old_shipard`) a reimportem `btpg-p`.

## Cíl

Účetní může doklad ručně zařadit do detailní (A4/B2) nebo souhrnné (A5/B3) sekce
kontrolního hlášení bez ohledu na limit 10 000 Kč, nebo ho z hlášení vyřadit — přesně
jako starý Shipard (`heads.vatCS`), aby import migrovaných dokladů dal stejné hlášení.

## Rozhodnutí (R1–R4)

- **R1 — Názvy anglicky, v dlouhém tvaru.** Sloupec `docs_core_heads.cs_mode` (vedle
  `cs_period`; `cs` = control statement jako typ tvrzení), číselník
  `economy.vat.controlStatementModes`, canonical pole `vat.controlStatementMode`
  s hodnotami `auto` / `detail` / `aggregate` / `exclude` (řetězce jako `vat.mode`).
  Hodnoty sloupce 0–3 jsou 1:1 se starým `vatCS`.
- **R2 — Sloupec je extension `economy.vat`**, ne sloupec `docs.core`
  (`modules/economy/vat/extensions/docs_core_heads.jsonc`, stejně jako `cs_period`):
  `docs.core` na DPH výstupech nezávisí.
- **R3 — Sémantika podle `VatCSEngine::rowKind` starého Shipardu, 1:1:**
  `1` vynutí detail (A4/B2) i pod limitem a **i bez CZ DIČ** — A4 bez DIČ dostane měkkou
  chybu `missingVatId` (živý report varuje, podání soubor nevygeneruje, protože
  `dic_odb` je povinný); `2` vynutí souhrn (A5/B3) i nad limitem; `3` vyřadí doklad
  z KH úplně, **včetně A1/A2/B1** (právně sporné, ale tak to dělal starý a import musí
  být věrný — živý report na počet vyřazených dokladů upozorní). Doklad v DP3 zůstává;
  snapshot podání dostane `kh_section = NULL`.
- **R4 — Pole jen u faktur** (FVB sekce Ostatní, FPB sekce DPH, `hidden` bez DPH).
  Pokladní a ostatní doklady zůstávají na 0 (Automaticky).

## Scope

1. Extension `cs_mode` (enumInt, default 0, cfgItem) + `config/controlStatementModes.jsonc`
   + registrace; `VatDocumentSelection` sloupec načte; `ControlStatementCalculator`
   režim respektuje v `groupRecap()` (3) a `resolveSection()` (1/2), `sectionForCode()`
   vrací pro 3 `null`; testy kalkulátoru + `FilingComposerTest` (režim 1 → B2 pod limitem,
   režim 3 → `kh_section` NULL a `dp3_row` plný).
2. Formuláře FVB/FPB (`select('cs_mode')`), `DocDocument::validate()` hlídá 0–3;
   help `dph-zive-vystupy.md` (ruční zařazení, ověřené názvy hodnot).
3. Živé KH: sloupec „Zařazení" u detailních řádků (jen ručně zařazené), info zpráva
   s počtem vyřazených dokladů.
4. Canonical schéma `vat.controlStatementMode` (+ `.json` dvojče + kopie v mail profilu
   `czech_general.jsonc`), `DocumentApplier` → `cs_mode`, `DocumentExporter` zpět
   (round-trip datových sad), testy exchange.
5. `old_shipard` `DocsRunner`: `vatCS` → `vat.controlStatementMode` (David), reimport
   `btpg-p`, přepočet konceptů KH, zlatý test.

## Mimo scope

- Zobrazení režimu v detailu dokladu ve vieweru a v opisu podání (PDF).
- Automatické návrhy režimu (opakovaná plnění pod limitem od téhož dodavatele).

## Hotovo když

- [ ] Testy zelené; `ds-upgrade` na 4l3j a btpg-p; režim vidět a nastavit ve formuláři FVB/FPB.
- [ ] Živé KH i snapshot podání respektují režim 1/2/3, A4 bez DIČ varuje.
- [ ] Dataset dump → seed zachová režim; canonical `exclude` → `cs_mode = 3`.
- [ ] Po reimportu `btpg-p` zlatý test KH 01/2026 bez rozdílů mimo F3-1 a známou výjimku
      (nulový doklad).
