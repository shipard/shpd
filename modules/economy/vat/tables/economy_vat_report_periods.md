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
| `locked` | boolean default 0 | Zámek instance (#55 D23/D25) — doklad s rekapitulací DPH, jehož kterýkoli ukazatel (`vat_period`/`cs_period`/`rs_period`, původní i nový) míří na zamčenou instanci, nejde uložit, změnit stav ani smazat (`VatPeriodLockProvider`). Import mód zámek obchází |
| `locked_at` | datetime, nullable, system | Kdy byla instance zamčena; nastavuje `ReportPeriodDocument` při `locked` 0→1, maže při 1→0. NULL u zámku převzatého importem („Uzamčeno (import)") |
| `locked_by` | int, reference `core_system_users`, nullable, system | Kdo zamkl (uživatel requestu, `CurrentUser`); NULL ve strojovém kontextu |

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
  instance, u instance s přiřazenými doklady (`docs_core_heads.vat_period`
  / `cs_period` / `rs_period`) ani u instance, za kterou existuje nezrušené
  **podání** (#55 D14 — instance je jeho kotva). Doklady uživatel nejdřív
  přepřiřadí (založí správné instance, doklady se při přepočtu chytí jich).
- **Zámek rozsahu**: instanci s podaným podáním nelze změnit `date_begin`
  ani `date_end` — podaný obsah odpovídá rozsahu, ve kterém se sestavil.
  Opravný postup je nové podání jiného druhu, ne editace rozsahu.
- **Zamčená instance** (`locked = 1`, #55 D25): jediná povolená mutace je
  přepnutí `locked` (a `name`); změna rozsahu, stavu, registrace nebo typu
  je chyba `locked`. Zrušit ji nelze (guardy výš). Sestavit nad ní podání
  **lze** — snapshot je nad neměnnými daty. Zamknout lze i instanci bez
  podání (historicky podané ve starém systému).
- **Zámek dokladů** (`VatPeriodLockProvider`, D23): doklad s rekapitulací
  DPH v původním nebo novém stavu, jehož kterýkoli ukazatel míří na
  zamčenou instanci, nejde uložit, změnit stav (40→80/30/90) ani smazat;
  nový doklad s DUZP v zamčeném rozsahu se neuloží a koncept instance
  nevznikne (lookup find-only). Bezdaňový doklad (bez rekapitulace) je
  volný — o něj se stará zámek fiskálního měsíce. Import mód (`_importNumber`)
  providery nevolá (D26).
- **Přepočet přes zámek** (`VatPeriodRecalculator`, D26): změna rozsahu
  sousední instance, která by přepsala ukazatel dokladu ze zamčené nebo do
  zamčené instance, spadne doménovou chybou s výčtem dokladů — uložení
  sousední instance se odroluje.
- Změna `date_begin`/`date_end` spouští přepočet přiřazení dotčených
  dokladů (`ReportPeriodDocument::afterPersist`) — viz README modulu.
- Přechody stavů běží přes Document (`stateTransitionsRunDocumentHooks`).

## Související

- [economy_codebooks_vat_registrations](../../codebooks/tables/economy_codebooks_vat_registrations.md) — rodičovská registrace
- [ReportPeriodDocument](../src/ReportPeriodDocument.php) — validace, guardy, přepočet
- [ReportPeriodsProvisioner](../src/ReportPeriodsProvisioner.php) — seed, cron, on-demand
- [economy_vat_filings](economy_vat_filings.md) — podání za instanci
- [docs/README.md](../docs/README.md) — model instancí a pravidla přiřazení
