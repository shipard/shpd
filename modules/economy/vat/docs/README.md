# Modul `economy.vat` — daňové výstupy

Živé výstupy DPH počítané **on-demand** z potvrzených dokladů (Fáze 0+1
milníku M1, `tasks/taxes-phase01.md`, D1–D6) a **instance daňových tvrzení**,
do kterých se doklady zařazují při uložení (revize po fázi 1,
`tasks/vat-report-periods.md`, D7–D13). Vše v issue #55.

## Co modul dělá

Tři reporty v doméně `report` (docs/reports.md), registrované
v `config/reports.jsonc` s `periodSource: "vatPeriod"` a `vatReportType`
(`return` / `cs` / `rs`) — parametr běhu je `period` = id instance tvrzení
odpovídajícího typu:

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

## Instance daňových tvrzení (D7–D13)

Tabulka `economy_vat_report_periods` (`tables/*.md`): per registrace DPH
a typ (`return` přiznání / `cs` kontrolní hlášení / `rs` souhrnné hlášení)
jeden záznam s rozsahem s denní přesností, uživatelsky editovatelný
(viewer **Daňová tvrzení** v sekci účetnictví, `ReportPeriodsViewer` +
`ReportPeriodsForm`). Nahrazuje kalendářní mřížku období DPH: rozdílné
frekvence KH/SH, změny periodicity i vznik/zánik plátcovství uprostřed
období jsou jen jiné rozsahy v datech; historii dodá import reálných
rozsahů podaných tvrzení (task 30 ve starém Shipardu).

**Pravidla instance** (`ReportPeriodDocument`): bez překryvu v rámci
(registrace, typ) — tvrdá chyba; díra k sousedům = varování. Zrušení
(přechod do 90 přes `stateTransitionsRunDocumentHooks`) i tvrdé smazání
blokuje zámek a přiřazené doklady; guard na podání je připravený bod
rozšíření. Zámek (`locked`) se zatím nevynucuje (Fáze 4).

**Zařazení dokladu** — sloupce `vat_period` / `cs_period` / `rs_period`
na `docs_core_heads` (extension `extensions/docs_core_heads.jsonc`; docs.core
na economy.vat nezávisí). Plní `DocsHeadsVatPeriodHandler` jako
`beforeSave` documentEventHandler (uvnitř save transakce, s recapem už
spočítaným) pravidlem `VatPeriodAssigner`:

- `vat_period` = instance `return` registrace dokladu obsahující **DUZP**;
- `cs_period` / `rs_period` jen má-li recap aspoň jeden kód s mapováním
  `kh` resp. `sh` ≠ null; instance `cs`/`rs` obsahující **clamped efektivní
  datum** = `COALESCE(vat_dppd, vat_duzp)` oříznuté do rozsahu instance
  přiznání dokladu. Jinak NULL (= doklad do hlášení nespadá).
- **Invarianta**: sjednocení dokladů měsíčních `cs` instancí čtvrtletí =
  doklady `return` instance, beze zbytku a bez průniku
  (`VatPeriodAssignerTest::testMonthlyCsInstancesPartitionQuarterlyReturn`).
- **Ruční přesun**: pole změněné v payloadu oproti původnímu řádku handler
  respektuje (ověří existenci, typ a registraci instance); nedotčené pole
  přepočítá. Ruční hodnota tedy drží, dokud doklad neuloží někdo s jinou
  hodnotou v payloadu — formulář posílá aktuální hodnotu, takže běžné
  uložení formulářem ji **přepočítá pravidlem** (limit bez markeru
  „ručně upraveno").
- Import mód žádnou výjimku nemá — věrnost dávají reálné rozsahy
  importovaných instancí (D12).

**On-demand koncepty (D9)**: chybí-li při uložení instance pro datum,
`ReportPeriodsProvisioner` založí koncept (docState 10) dle periodicity
registrace (`tax/cs/rs_period_kind` — D10: už jen defaulty generátoru),
oříznutý do platnosti registrace a o sousední instance. Alert check
`economy.vat.draft_report_periods` (15 min) nabídne koncepty ke kontrole.
Seed běžného období: `VatRegistrationSeedHandler` (`afterSave` na
registraci) a denní cron `shpd-ds vat-periods-ensure` (+ `ds-upgrade`)
zakládají instance pokrývající dnešek a zítřek ve stavu V pořádku.
Dopředu se negeneruje nic.

**Přepočet po změně rozsahu** (`VatPeriodRecalculator`, `afterPersist`
instance, atomicky): dávka = doklady registrace, které na instanci míří
nebo jejichž DUZP / efektivní datum spadá do nového rozsahu. Přepíše se
jen **nekonzistentní** ukazatel (NULL, nebo instance, která datum dokladu
pro svůj typ neobsahuje); konzistentní ukazatel jinam zůstane — tím
přežije ruční přesun do instance, kam datum dokladu spadá. Instance se
při přepočtu nezakládají (find-only), doklad s NULL se dorovná při svém
příštím uložení.

## Architektura

```
src/
├── VatOutputsMapping.php                  # resolver cfgItem; neznámý kód = výjimka
├── VatDocumentSelection.php               # heads (docState 40) WHERE <xx>_period = instance
│                                          #   + recap + DIČ ze snapshotů
├── VatReturnCalculator.php                # DP3: sumace per (řádek, sloupec) + dopočty
├── ControlStatementCalculator.php         # KH (CS): rozpad sekcí, limit 10 000, pásma, měkké chyby
├── RecapitulativeStatementCalculator.php  # SH (RS): agregace (kod, DIČ) → počet + hodnota
├── VatJournalCrossCheck.php               # recap tax_dom vs 343 analytiky deníku
├── ReportPeriodDocument.php               # instance: validace, guardy, přepočet po změně rozsahu
├── ReportPeriodsViewer.php / ReportPeriodsForm.php
├── ReportPeriodLookup.php                 # rozhraní „instance pokrývající datum" pro assigner
├── ReportPeriodsProvisioner.php           # find/create (koncept), seed, cron; čistý kandidát
├── VatPeriodAssigner.php                  # pravidlo zařazení (DUZP, clamped DPPD, membership)
├── VatPeriodRecalculator.php              # dávkový přepočet po změně rozsahu
├── DocsHeadsVatPeriodHandler.php          # beforeSave handler na docs_core_heads
├── VatRegistrationSeedHandler.php         # afterSave handler na registraci
├── Checks/DraftReportPeriodsCheck.php     # alert: koncepty instancí
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

## Mimo scope

Podání a snapshoty (Fáze 2), vynucení zámku (Fáze 4), oprava dle § 44
v A4, investiční zlato (A3), ř. 45/47, koeficient kráceného odpočtu
(ř. 52/53), OSS a registrace jako samostatný parametr reportů (přijde
s OSS / více DIČ).
