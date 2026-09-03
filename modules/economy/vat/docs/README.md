# Modul `economy.vat` — daňové výstupy

Živé výstupy DPH počítané **on-demand** z potvrzených dokladů — Fáze 0+1
milníku M1 (issue #55, zadání `tasks/taxes-phase01.md`, rozhodnutí D1–D6).

## Co modul dělá

Tři reporty v doméně `report` (docs/reports.md), registrované
v `config/reports.jsonc` s `periodSource: "vatPeriod"`:

| Report | Výstup |
|---|---|
| `economy.vat.returnLive` | Přiznání k DPH (DPHDP3) — řádky formuláře + dopočty 46/62–65, operativní stav (ř. 64/65) a křížová kontrola proti deníku v messages |
| `economy.vat.controlStatementLive` | Kontrolní hlášení (DPHKH1) — sekce A1/A2/A4/A5/B1/B2/B3, detailní řádky s ev. číslem / DIČ / DPPD, agregáty A5/B3 |
| `economy.vat.recapitulativeStatementLive` | Souhrnné hlášení (DPHSHV) — agregace per (kód plnění, DIČ odběratele) |

Nic se nepersistuje (D1) — persistence přijde až s Podáním (Fáze 2,
doména `filing`).

## Mapovací konfigurace

`config/vat-reports-cz.jsonc` (cfgItem `economy.vat.reports.cz`) mapuje
**každý** kód `world.vat.cz` na výstupy: `dp3 {row, col?}`, `kh {group,
kodPredPl?}`, `sh {kod}` — i vyloučení je explicitní `null`. Princip
shodný s rozhodnutím #8 účtování: `world.vat` zůstává legislativní vrstva
bez výkaznických konvencí (D3), výkaznictví per country žije tady.
Úplnost vůči číselníku a shodu s `vatReturnRow` hlídá
`VatReportsMappingCompletenessTest`. Sekce `dp3Rows` nese lokalizované
popisky řádků přiznání pro živý report.

Po změně configu je potřeba `vendor/bin/shpd-ds ds-upgrade` (rekompilace
cfgItem); samotné deklarace reportů se čtou z modulu za requestu.

## Architektura

```
src/
├── VatOutputsMapping.php                  # resolver cfgItem; neznámý kód = výjimka
├── VatDocumentSelection.php               # heads (docState 40) přes date containment
│                                          #   vat_periods (D5) + recap + DIČ ze snapshotů
├── VatReturnCalculator.php                # DP3: sumace per (řádek, sloupec) + dopočty
├── ControlStatementCalculator.php         # KH (CS): rozpad sekcí, limit 10 000, pásma, měkké chyby
├── RecapitulativeStatementCalculator.php  # SH (RS): agregace (kod, DIČ) → počet + hodnota
├── VatJournalCrossCheck.php               # recap tax_dom vs 343 analytiky deníku
└── Reports/
    ├── VatReportSupport.php               # sdílené kusy builderů (kompozice)
    └── Vat*LiveBuilder.php                # tenké ReportBuilder adaptéry
```

Kalkulátory jsou **čisté** (vstup = pole z `VatDocumentSelection`) a testují
se na syntetických datech bez DB. Referenční logika sekcí KH a dopočtů DP3
pochází ze starého Shipardu (`modules/e10doc/taxes`), detaily pravidel jsou
v docblocích kalkulátorů a v zadání.

## Hranice report vs. filing

Živé výstupy jsou **reporty** — vždy přepočtené, bez lifecycle. Podání
(snapshot, opravné/dodatečné, XML, PDF opis, zámek období) je doména
`filing` a přijde ve Fázích 2–4 dle issue #55. Do snapshot řádků se pak
materializuje i výsledek mapování, aby šlo XML regenerovat i po změně
konfigurace.

## Mimo scope Fáze 0+1

Per-doklad override zařazení do KH, oprava dle § 44 v A4, investiční
zlato (A3), ř. 45/47, koeficient kráceného odpočtu (ř. 52/53), OSS.
