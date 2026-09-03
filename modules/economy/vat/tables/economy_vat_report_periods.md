# Tabulka: economy_vat_report_periods

Instance daňových tvrzení (issue #55, rozhodnutí D7–D13). Pro každou
registraci DPH a každý typ výstupu (přiznání, kontrolní hlášení, souhrnné
hlášení) jeden záznam s rozsahem s denní přesností. Nahrazuje kalendářní
mřížku období DPH z `economy.codebooks`: rozdílné frekvence KH a SH, změny
periodicity v čase i vznik/zánik plátcovství uprostřed období jsou prostě
jiné rozsahy v datech.

Záznamy vznikají (a) seedem po uložení registrace a denním cronem
(`ReportPeriodsProvisioner`, stav V pořádku), (b) on-demand při uložení
dokladu, pro který instance chybí (Koncept + alert), (c) importem ze
starého Shipardu (reálné rozsahy podaných tvrzení), (d) ručně přes viewer
**Daňová tvrzení**.

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `vat_registration` | int, reference `economy_codebooks_vat_registrations` | Vlastnická registrace |
| `report_type` | enumString(10), cfgItem `economy.vat.reportTypes` | `return` = přiznání k DPH, `cs` = kontrolní hlášení, `rs` = souhrnné hlášení |
| `name` | varchar(20) | `"MM/YYYY"` měsíční, `"QN/YYYY"` čtvrtletní; import a uživatel mohou mít vlastní názvy |
| `date_begin` | date | Začátek období |
| `date_end` | date | Konec období |
| `locked` | boolean default 0 | Zámek — sloupec existuje pro všechny typy, vynucení (gate mutací dokladů) je Fáze 4 dle #55 |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `docState` | tinyint default 10 | Stav dokumentu (`core.system.docStatesArchive`) |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `idx_registration_type_begin` na `vat_registration, report_type, date_begin`
- `idx_doc_state` na `docStateMain ASC, date_begin DESC`

## Pravidla

- **Bez překryvu** v rámci (registrace, typ) mezi živými instancemi
  (`docState != 90`) — tvrdá validační chyba. Díra mezi sousedními
  instancemi je jen varování (`ValidationResult::addWarning`).
- **Guardy zrušení** (přechod do 90 i tvrdé smazání): nelze u zamčené
  instance ani u instance s přiřazenými doklady (`docs_core_heads.vat_period`
  / `cs_period` / `rs_period`). Uživatel nejdřív doklady přepřiřadí (založí
  správné instance, doklady se při přepočtu chytí jich). Guard na podání
  (tabulky podání zatím neexistují) je připravený bod rozšíření
  v `ReportPeriodDocument::cancellationBlockers()`.
- Změna `date_begin`/`date_end` spouští přepočet přiřazení dotčených
  dokladů (`ReportPeriodDocument::afterPersist`) — viz README modulu.
- Přechody stavů běží přes Document (`stateTransitionsRunDocumentHooks`).

## Související

- [economy_codebooks_vat_registrations](../../codebooks/tables/economy_codebooks_vat_registrations.md) — rodičovská registrace
- [ReportPeriodDocument](../src/ReportPeriodDocument.php) — validace, guardy, přepočet
- [ReportPeriodsProvisioner](../src/ReportPeriodsProvisioner.php) — seed, cron, on-demand
- [docs/README.md](../docs/README.md) — model instancí a pravidla přiřazení
