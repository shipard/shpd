# Tabulka: economy_vat_filings

Podání DPH — hlavička (issue #55, rozhodnutí D14–D18). Persistovaný záznam
o tom, **co jsme za období podali**: snapshot obsahu instance tvrzení
v okamžiku sestavení. Živé reporty se vždy přepočítávají a lifecycle
nemají; podání je opak — jednou podané se nemění.

Kotví se na **instanci tvrzení** (`report_period`), z níž plyne typ,
registrace i rozsah období (D14). Obsah snapshotu žije ve čtyřech
systémových tabulkách, které plní výhradně `FilingComposer`:

- [economy_vat_filing_items](economy_vat_filing_items.md) — dokladová úroveň
- [economy_vat_filing_return_rows](economy_vat_filing_return_rows.md) — řádky DPHDP3
- [economy_vat_filing_cs_rows](economy_vat_filing_cs_rows.md) — řádky DPHKH1
- [economy_vat_filing_rs_rows](economy_vat_filing_rs_rows.md) — řádky DPHSHV

Přílohy (XML, PDF opis) jdou přes `core.attachments` s `table_id` = 443;
Fáze 2 je jen deklaruje, plní je Fáze 3.

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `report_period` | int, reference `economy_vat_report_periods` | Instance tvrzení — kotva podání |
| `report_type` | enumString(10), cfgItem `economy.vat.reportTypes` | Denormalizace typu z instance (viewer, indexy); `FilingDocument` ji plní a hlídá shodu |
| `filing_kind` | enumString(15), cfgItem `economy.vat.filingKinds` | `regular` / `corrective` / `supplementary` / `subsequent`; povolené druhy per typ říká `reportTypes.{type}.filingKinds` v `vat-reports-cz.jsonc` |
| `sequence` | smallint default 1, system | Pořadí v instanci (1 = první řádné); max + 1 přes všechna podání včetně zrušených |
| `name` | varchar(80), system | `"{název instance} — {druh} {pořadí}"`, skládá `FilingDocument` |
| `date_issue` | date | Sestaveno |
| `date_filed` | date, nullable | Podáno; přechod do stavu Podáno doplní dnešek, když je prázdné |
| `date_found` | date, nullable | Datum zjištění důvodů pro podání — povinné u druhů z `dateFoundRequiredFor` a vždy u dodatečného přiznání |
| `previous_filing` | int, nullable, reference `economy_vat_filings` | Poslední podané podání instance = základ pro rozdíly; u řádného NULL, u dodatečného povinné |
| `header` | json, nullable, system | Snapshot hlavičky XML (D19) — Fáze 2 sloupec jen deklaruje, schéma a editaci doplní Fáze 3 po #74 |
| `result` | json, nullable, system | Souhrn podaných hodnot (DP3 ř. 62–66, počty řádků sekcí KH, hodnota SH, stav křížové kontroly, `isEmpty`). **NULL = snapshot nesestaven** — podat takové podání nelze |
| `messages` | json, nullable, system | Měkké chyby kalkulátorů v okamžiku sestavení — historický záznam, ne živý stav |
| `note` | text, nullable | Jediný sloupec editovatelný i po podání |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `docState` | tinyint default 10 | Stav dokumentu (`economy.vat.docStatesFilings`) |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `idx_period_sequence` **unique** na `report_period, sequence` — pořadí je
  monotónní počítadlo, ne business klíč: zrušené podání svoje číslo drží
  a nové dostane další, takže unique nemůže blokovat nový záznam
- `idx_doc_state` na `docStateMain ASC, date_issue DESC`

## Pravidla

Vynucuje [FilingDocument](../src/FilingDocument.php):

- **Druh podání** musí být povolený u typu tvrzení instance
  (`VatOutputsMapping::filingKinds()`); `report_type` se musí shodovat
  s typem instance. Za zrušenou instanci nelze podat.
- **Pořadí druhů** (D18): `regular` jen dokud za instanci není nic podané;
  `corrective` / `supplementary` / `subsequent` naopak jen když už podané
  podání existuje. V instanci smí být nejvýš **jeden živý koncept**.
- **Datum zjištění důvodů** je povinné pro druhy z
  `reportTypes.{type}.dateFoundRequiredFor` (KH následné) a vždy pro
  dodatečné přiznání (§ 141 odst. 1 DŘ).
- **`previous_filing`** dopočítává Document u konceptu při každém uložení
  (poslední podání instance ve stavu Podáno); po podání se zmrazí.
- **Přechod do Podáno** doplní `date_filed` (dnes, pokud prázdné)
  a vyžaduje sestavený snapshot (`result` není NULL).
- **Podané podání je immutable** — změnit smí jen `note`, smazat se nedá.
  Zrušené podání je zmrazené stejně; zrušený koncept se nevrací, sestaví
  se nový.
- Smazání konceptu (nebo zrušeného podání) uklidí i snapshot ve čtyřech
  systémových tabulkách (`beforeDelete`); `previous_filing` na mazatelné
  podání nikdy nemíří, takže nezůstane visící odkaz.
- Přechody stavů běží přes Document (`stateTransitionsRunDocumentHooks`).

## Související

- [economy_vat_report_periods](economy_vat_report_periods.md) — instance tvrzení, kotva podání
- [FilingDocument](../src/FilingDocument.php) — druhy, pořadí, lifecycle, guardy
- [ReportPeriodDocument](../src/ReportPeriodDocument.php) — guard zrušení instance a zámek rozsahu
- [docs/README.md](../docs/README.md) — model podání, druhy, zaokrouhlení, hranice vůči Fázím 3–4
