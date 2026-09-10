# Task: Podání DPH — snapshot tvrzení, druhy podání, lifecycle (M1 Fáze 2) — #55 D14–D22

**Stav:** hotovo (2026-09-10) — všechna kritéria splněna po #75 a reimportu; zlatý test DP3 01–04/2026 přesně
**Issue:** #55 — komentář „Fáze 2 — Podání: rozhodnutí D14–D22 (2026-09-08)"
**Návaznost:** staví na `economy.vat` Fáze 1 (živé reporty, kalkulátory,
`VatDocumentSelection`) a instancích tvrzení (`economy_vat_report_periods`,
`tasks/vat-report-periods.md`). Hlavička XML a profil podatele jsou strukturovaná
pole se schématem (#74) — **prerekvizita Fáze 3 (XML), ne této fáze**: sloupce
zde jen deklarujeme. Import starých podání = navazující `old_shipard` task 34 (D21).
Zámek instance a zaúčtování přiznání = Fáze 4 (D18, D22).

## Cíl

Z živého výpočtu udělat **podání**: persistovaný snapshot obsahu instance tvrzení
v okamžiku sestavení (dokladová úroveň + výstupní řádky s podanými hodnotami),
druhy podání dle legislativy per typ výstupu (řádné / opravné / dodatečné /
následné), rozdíly proti předchozímu podání a lifecycle sestaveno → podáno bez
EPO. Po této fázi má firma trvalý záznam „co jsme za období podali" a Fáze 3
nad ním jen generuje XML.

Zaokrouhlení převzato ze starého `VatReturnReport` (ověřeno proti podaným XML
zdroje 689089): každý řádek DP3 samostatně na Kč, dopočty 46/62/63 ze
zaokrouhlených řádků, 64/65 = 62 − 63; dodatečné = rozdíl zaokrouhlených řádků,
ř. 66 = změna daňové povinnosti.

Před implementací **přečti**:

- issue #55 vč. komentářů (D1–D22); #74 (strukturovaná pole — jen kontext D19)
- `modules/economy/vat/docs/README.md` — celý; `tables/economy_vat_report_periods.*`
  (vzor tabulky s docStates a guardy), `src/ReportPeriodDocument.php` (guard (b)
  na řádku s komentářem „Bod rozšíření: podání")
- `src/VatDocumentSelection.php` (tvar dokladů + recap), `VatReturnCalculator.php`
  (`rows`/`computed`, `roundRow`), `ControlStatementCalculator.php` (`sections`,
  `errors`), `RecapitulativeStatementCalculator.php`, `VatJournalCrossCheck.php`,
  `DeductionCoefficientResolver.php`, `Reports/VatReportSupport.php` a
  `Vat*LiveBuilder.php` (jak se dnes skládá výpočet — snapshot musí jít **stejnou
  cestou**, žádná druhá výpočetní větev)
- `config/vat-reports-cz.jsonc` sekce `reportTypes` (#58) — rozšíří se
- `docs/table-definitions.md` (json sloupce, docStates cfgItem vlastní modulu —
  vzor `core.mail.docStatesIncoming`, `tasks.core.docStatesTasks`),
  `docs/edit-forms.md` (tab `attachments`, `doc_states`, transitions),
  `docs/viewer-grid.md`, `docs/document-system.md` (Document hooky, `afterPersist`,
  `stateTransitionsRunDocumentHooks`), `docs/cli.md`
- `modules/core/attachments/tables/core_attachments_files.jsonc` (`table_id`/`record_id`)
- old_shipard: `modules/e10doc/taxes/VatReturn/VatReturnReport.php` ř. 55–125
  (zaokrouhlení, dodatečné, ř. 66), `VatCS/VatCSReportAll.php` (`khdph_forma`,
  `d_zjist`), `tables/filings.json`, `tables/reportsRows*.json` — referenční
  sémantika, ne architektura

## Co vznikne

### 1. Config — druhy podání a zaokrouhlení per typ (D16, D17)

`vat-reports-cz.jsonc` → `reportTypes` (vedle `validFrom` z #58):

```jsonc
"reportTypes": {
    "return": { "filingKinds": ["regular", "corrective", "supplementary"],
                "supplementaryMode": "diff", "roundingUnit": 1 },
    "cs":     { "validFrom": "2016-01-01",
                "filingKinds": ["regular", "corrective", "subsequent"],
                "dateFoundRequiredFor": ["subsequent"], "roundingUnit": 0.01 },
    "rs":     { "filingKinds": ["regular", "subsequent"], "roundingUnit": 1 }
}
```

`VatOutputsMapping`: `filingKinds(type)`, `supplementaryMode(type)`
(`diff` | `full`, default `full`), `dateFoundRequiredFor(type)`,
`roundingUnit(type)`. Neznámý druh v configu = výjimka v konstruktoru.
Druhy: `regular` řádné, `corrective` opravné (ve lhůtě, plná náhrada),
`supplementary` dodatečné (DP3, po lhůtě), `subsequent` následné (KH/SH).
Lokalizované názvy druhů v `config/filingKinds.jsonc` (cfgItem
`economy.vat.filingKinds`).

### 2. Tabulky (D14, D15) — modul `economy.vat`, tableId od 443

**`economy_vat_filings`** — hlavička podání, docStates vlastní cfgItem
`economy.vat.docStatesFilings` (§4):

| sloupec | typ | pozn. |
|---|---|---|
| `report_period` | int FK → `economy_vat_report_periods` | D14; typ, registrace, interval z instance |
| `report_type` | enumString `return`/`cs`/`rs` | denormalizace pro viewer/indexy; Document ověří shodu s instancí |
| `filing_kind` | enumString | §1 |
| `sequence` | smallint | pořadí v instanci (1 = první řádné) |
| `name` | varchar | `{název instance} — {druh} {sequence}` |
| `date_issue` | date | sestaveno |
| `date_filed` | date, nullable | podáno (D18) |
| `date_found` | date, nullable | datum zjištění důvodů (KH následné, DP3 dodatečné) |
| `previous_filing` | int FK self, nullable | základ pro rozdíly; povinné u `supplementary` |
| `header` | json, nullable | snapshot hlavičky XML (D19) — F2 jen deklaruje (`schema` doplní F3 po #74) |
| `result` | json | souhrn: DP3 ř. 62/63/64/65/66 podané; KH počty řádků per sekce; SH počet/hodnota; stav křížové kontroly |
| `messages` | json, nullable | měkké chyby kalkulátorů v okamžiku sestavení |
| `note` | text, nullable | |
| docState / docStateMain | | |

Indexy: `(report_period, sequence)` unique; `(docStateMain, date_issue DESC)`.
Přílohy přes `core.attachments` (`table_id` = tableId podání) — F3 tam uloží
XML a PDF opis.

**`economy_vat_filing_items`** — dokladová úroveň, společná (D15/1):
`filing` FK, `doc_head` int (id hlavičky; bez FK constraintu — snapshot přežije
zrušení dokladu), `doc_type`, `doc_number`, `partner_doc_number`,
`total_amount_dom`, `vat_duzp`, `vat_dppd`, `partner_vat_id`, `vat_code`,
`vat_pct`, `base_dom`, `tax_dom`, `is_reverse_pair`, a **materializované mapování**:
`dp3_row` smallint null, `dp3_col` varchar null, `kh_group` varchar null,
`kh_section` varchar null (A1/A2/A4/A5/B1/B2/B3 po rozpadu enginem),
`kh_kod_pred_pl` tinyint null, `sh_kod` tinyint null.
Index `(filing, doc_head)`. Žádné docStates (systémová tabulka, vlastník = podání).

**`economy_vat_filing_return_rows`** (D15/2, DP3): `filing`, `row` smallint,
`is_computed` boolean (46/52/62–66), `base`, `tax_full`, `tax_reduced` (přesné)
+ `base_filed`, `tax_full_filed`, `tax_reduced_filed` (podané = zaokrouhlené,
u dodatečného rozdíl). Unique `(filing, row)`.

**`economy_vat_filing_cs_rows`** (KH): `filing`, `section`, `row_kind`
(`detail`/`aggregate`), `doc_head` null, `doc_number` (ev. číslo), `partner_vat_id`,
`vat_dppd`, `kod_pred_pl`, `base1`,`tax1`,`base2`,`tax2`,`base3`,`tax3`
(`roundingUnit` 0.01 → podané = přesné, sloupce `_filed` se u KH **nezakládají**).
Index `(filing, section)`.

**`economy_vat_filing_rs_rows`** (SH): `filing`, `kod`, `partner_vat_id`, `count`,
`value`, `value_filed`. Unique `(filing, kod, partner_vat_id)`.

Tabulkové `.md` soubory dle konvence. `keepOnReset` **ne** — podání je uživatelská
data, ne infrastruktura.

### 3. Sestavení snapshotu — `FilingComposer`

Jediná autorita, která zapisuje do items/rows. Vstup: id podání ve stavu 10.
Kroky, atomicky (transakce; smaž předchozí items/rows podání a regeneruj):

1. Instance → typ, registrace, rozsah; doklady `VatDocumentSelection::load(period, <xx>_period)`
   — **totéž co živý report**.
2. **Items**: per doklad × řádek recapu; mapování z `VatOutputsMapping`; `kh_section`
   z rozpadu `ControlStatementCalculator` (engine vrací sekce per doklad — vyexponuj
   pomocnou metodu, nekopíruj pravidla). Kód bez mapování = **tvrdá chyba**
   sestavení (u podání nesmí nic tiše vypadnout; živý report to jen hlásí).
3. **Výstupní řádky** stejnými kalkulátory: DP3 `VatReturnCalculator::calculate($docs,
   $coefficient)` (koeficient přes `DeductionCoefficientResolver`, rok instance),
   KH `ControlStatementCalculator`, SH `RecapitulativeStatementCalculator`.
4. **Podané hodnoty** — `FilingRounding` (čistá třída, testovaná samostatně):
   - `roundingUnit 1` (DP3): `rows` řádek po řádku `round()` na Kč; `computed`
     46/62/63 **přepočítat ze zaokrouhlených řádků** (ne zaokrouhlit computed);
     64/65 = 62 − 63 podle znaménka; ř. 52 = round(Σ krácený × koef).
   - `0.01` (KH): beze změny. SH: `round()` hodnoty.
   Přesné hodnoty vedle podaných.
5. **Dodatečné** (`supplementaryMode: diff`): `previous_filing` = poslední podání
   instance ve stavu 40 (automaticky; Document ověří). `*_filed` = aktuální podané
   − předchozí podané per řádek; ř. 64/65 = 0; **ř. 66** = (62−63)ₙₒᵥé − (62−63)ₚřₑd.
   Items zůstávají úplné (D15).
6. `result` + `messages` (kalkulátorové chyby, `VatJournalCrossCheck`).

Volání: `afterPersist` nového podání (stav 10) a explicitní akce **Přepočítat**
na konceptu; ve stavu 40 composer odmítne (výjimka). CLI `shpd-ds vat-filing-compose
--period=<id> [--kind=regular] [--recompute=<filingId>]` pro E2E a testy.

### 4. Lifecycle a validace — `FilingDocument` (D18)

cfgItem `economy.vat.docStatesFilings` (`config/docStatesFilings.jsonc`):
`10` Sestaveno (koncept, `goto [40, 90]`), `40` Podáno (`readOnly`, `goto []`),
`90` Zrušeno. Přechod 10→40 přes `stateTransitionsRunDocumentHooks`.

Validace při uložení:
- `filing_kind` ∈ `filingKinds(type)`; `report_type` = typ instance.
- `date_found` povinné pro `dateFoundRequiredFor(type)` a pro `supplementary`.
- `supplementary`/`subsequent`/`corrective` jen když v instanci existuje podání
  ve stavu 40; `regular` jen jako první (sequence 1) nebo když žádné 40 neexistuje.
- V instanci max. jedno živé podání ve stavu 10.
- `sequence` = max + 1 (Document, ne formulář).

Přechod na 40 („Podat"): vyžaduje `date_filed` (default dnes, editovatelné před
přechodem); snapshot musí existovat (items > 0 nebo prázdné podání explicitně
potvrzené — prázdné KH se podává také; `result` nese `isEmpty`).
Stav 40: **immutable** — mutace hlavičky (kromě `note`), items, rows i příloh
guard blokuje; smazání blokuje. Zrušit (90) lze jen 10.
**Instance se nezamyká** (D18) — `locked` zůstává F4.

`ReportPeriodDocument`: aktivovat guard (b) — instanci s podáním ve stavu ≠ 90
nelze zrušit ani smazat; **změna rozsahu** instance s podáním ve stavu 40 = tvrdá
chyba (podaný obsah odpovídá rozsahu; opravný postup = nové podání jiného druhu,
ne editace rozsahu).

### 5. UI (D20)

- **Viewer `economy.vat.filings`** „Podání DPH" (navSection accounting, za Daňovými
  tvrzeními): sloupce instance, typ, druh, pořadí, sestaveno, podáno, výsledek
  (DP3 ř. 64/65/66 podané; KH počty A/B; SH hodnota), stav. Detail: výstupní řádky
  (podané + přesné), messages, **Rozdíly** proti `previous_filing` po dokladech
  (items: přidané / odebrané / změněné, dle `doc_head` + `vat_code` + `vat_pct`).
- **`ReportPeriodsViewer`** detail instance: seznam podání + akce „Sestavit podání"
  (předvyplní instanci, nabídne jen povolené druhy).
- **`FilingsForm`**: instance (select, jen ve stavu 40/10 příslušné registrace), druh
  (options dle typu instance, `triggers: reload`), `date_found` (zobrazit jen když
  povinné), `date_filed` (jen před přechodem na 40), `note`; tab `attachments`;
  tab `header` **zatím ne** (#74, F3). `doc_states` transitions dle §4.
- Živé reporty: hlavička reportu ukáže poslední podání instance (druh, datum,
  podaná daňová povinnost) a — umožňuje-li to reportová infrastruktura akci —
  „Sestavit podání"; jinak odkaz do vieweru Daňová tvrzení. Nerozšiřovat reportový
  systém kvůli tomu.

### 6. Testy

- `FilingRoundingTest`: řádkové zaokrouhlení, 46/62/63 ze zaokrouhlených,
  64/65 dle znaménka, ř. 52; syntetický případ, kde součet zaokrouhlených ≠
  zaokrouhlený součet (to je celý smysl pravidla).
- `FilingComposerTest` (fake selection, bez DB, nebo integrační nad dev DS):
  items nesou mapování a `kh_section`; DP3/KH/SH výstupní řádky = výstup živých
  kalkulátorů; kód bez mapování = výjimka; dodatečné = rozdíl + ř. 66, 64/65 = 0;
  přepočet regeneruje (žádné duplicity).
- `FilingDocumentTest`: druhy per typ, `date_found`, pořadí (regular první,
  supplementary jen po 40), jediný koncept, immutabilita 40, `sequence`.
- `ReportPeriodDocumentTest`: guard (b) zrušení/smazání, změna rozsahu s podáním 40.
- Integrační (`SHIPARD_INTEGRATION_DS_PATH`, dev DS 4l3j): CLI compose → podat →
  compose supplementary → viewer detail rozdílů.

### 7. Dokumentace

`modules/economy/vat/docs/README.md` — nová sekce „Podání (Fáze 2)": model dvou
úrovní, druhy podání, zaokrouhlení, lifecycle, hranice vůči F3/F4; tabulkové `.md`;
`docs/reports.md` §1 — `filing` odkaz na modul; help pro viewer Podání DPH.
`tasks/README.md`, `docs/README.md` neaktualizuj (David).

## Mimo scope

- XML (DPHDP3/DPHKH1/DPHSHV), PDF opis, editace hlavičky a profil podatele (F3, po #74).
- Zámek instance a vynucení proti změnám dokladů, zaúčtování přiznání (F4).
- Import starých podání (`old_shipard` task 34, D21).
- OSS, registrace jako samostatný parametr, ř. 53/60.
- Rozdíly mezi dvěma libovolnými podáními (jen proti `previous_filing`).

## Commity

1. Config `reportTypes` (druhy, režim, zaokrouhlení) + `filingKinds` + `docStatesFilings` + `VatOutputsMapping` + testy.
2. Tabulky (5×) + `.md` + `FilingDocument` (validace, guardy, sequence) + aktivace guardu (b) v `ReportPeriodDocument` + testy.
3. `FilingRounding` + `FilingComposer` (items, rows, diff, result) + CLI + testy.
4. Viewer Podání DPH + detail (řádky, rozdíly) + `FilingsForm` + akce v Daňových tvrzeních + hlavička živých reportů.
5. Dokumentace + help.

## Hotovo když

- [x] Testy zelené (rounding, composer, document, guardy) — unit sada
      5 344 testů, integrační `FilingComposerTest` 7 testů nad 4l3j.
- [x] `ds-upgrade` na dev DS 4l3j projde (5 tabulek); viewer Podání DPH,
      formulář, akce Sestavit podání / Přepočítat a přechod Podat ověřeny
      přes HTTP.
- [x] Dev DS `btpg-p` (zdroj 689089): řádné podání DP3 za instance 01–04/2026 dává
      **podané** ř. 64 = 260 864 / 135 796 / 120 203 / 143 583 Kč — přesně hodnoty
      z podaných XML (živý report dává 260 865,94 / 135 796,54 / 120 202,50 / 143 584,20).
      **Čeká na reimport zdroje** (ops).
- [x] Na téže instanci po změně dokladu: dodatečné podání má ř. 64/65 = 0 a ř. 66 =
      rozdíl daňové povinnosti; detail Rozdíly ukáže dotčený doklad — ověřeno
      na 4l3j (změna rekapitulace o 100/21 Kč → ř. 66 = −21, Rozdíly ukázaly
      dotčený doklad).
- [x] Podané podání nelze změnit ani smazat; instanci s podáním nelze zrušit ani
      změnit rozsah; koncept je v instanci nejvýš jeden.
- [x] KH řádné podání za měsíční instanci: počty řádků sekcí a součty = živý report
      (integrační test rozpadu A4/A5).

## Odchylky od zadání

- **SH zaokrouhluje `ceil`, ne `round`** (§3/4 zadání uvádělo `round()`).
  Referenční `VatRSReport` i README modulu mluví o zaokrouhlení nahoru
  a to je i to, co je v podaných XML.
- **Dodatečné přiznání diffuje proti kumulativnímu podanému stavu**, ne
  proti hodnotám jednoho `previous_filing` — druhé dodatečné v řadě by
  jinak vykázalo nesmysl (předchozí podání samo nese jen deltu).
  `FilingComposer::cumulativeFiledRows` skládá řetěz: řádné a opravné je
  plná náhrada, dodatečné se přičítá.
- **Rozdíl dodatečného jde přes sjednocení řádků** obou podání. Starý kód
  iteroval jen řádky nového výpočtu, takže vypadlý řádek se do rozdílu
  nedostal — reprodukovat tuhle chybu nemá smysl.
- **Prázdné podání se nepotvrzuje zvlášť** (§4 „prázdné podání explicitně
  potvrzené"): přechod do Podáno vyžaduje jen existující snapshot
  (`result` není NULL), `result.isEmpty` se propíše do vieweru. Bez
  dalšího dialogu.
- **Immutabilita příloh podaného podání není vynucená.** `core.attachments`
  nemá hook „rodičovský záznam je read-only" a zavádět ho kvůli sloupci,
  který Fáze 2 jen deklaruje (XML a PDF opis plní Fáze 3), by byl zásah do
  cizího modulu bez užitku. Guard patří k Fázi 3 spolu s obsahem příloh.
- **`sequence`, `name`, `header`, `result` a `messages` jsou `system`**
  sloupce — klient je neposílá, plní je Document a composer.
- `RECEIVED_SECTIONS` v `ControlStatementCalculator` doplněno o **B3**:
  agregát přijatých plnění do detailních řádků nikdy nedojde, ale
  snapshot podle něj určuje DIČ protistrany.
