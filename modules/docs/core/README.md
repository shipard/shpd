# Modul: docs.core

Polymorfní jádro dokladového systému — společné tabulky, číselné řady,
stavový model, konfigurační cfgItem soubory pro všechny typy dokladů.

## Účel

Drží **5 univerzálních tabulek** sdílených všemi typy dokladů (faktura
vydaná, faktura přijatá, …):

- `docs_core_heads` — hlavička dokladu (~40 sloupců, podle typu se používají
  různé)
- `docs_core_rows` — řádky dokladu
- `docs_core_vat_recap` — rekapitulace DPH (sestavovaná v `beforeSave` hlavičky)
- `docs_core_number_series` — číselné řady dokladů
- `docs_core_number_counters` — atomické countery pro generování čísla
  dokladu

Konkrétní typy dokladů žijí v navazujících modulech:

- `docs.invoicesOut` — faktura vydaná (Document subclass + viewer)
- `docs.invoicesIn` — faktura přijatá

Polymorfismus: hlavička má `doc_type` (enumString), který určuje konkrétní
Document třídu přes cfgItem `docs.core.docTypes`.

## Stav (Fáze 1)

V této fázi je implementovaná **kostra**:

- 5 tabulek + 10 cfgItem souborů
- Číselné řady — kompletní CRUD (Document + Form + Viewer + Provisioner)
- `OwnCompanyResolver` helper
- `DocDocument` abstract base s minimální logikou (init `doc_number`,
  denormalizace `doc_type`)

**Mimo rozsah Fáze 1** (přijde ve Fázi 2):

- Výpočty cen, DPH, rekapitulace, snapshoty
- Atomické přidělení čísla dokladu při Koncept → Potvrzeno
- Resolvery `fiscal_year` / `fiscal_month` / `vat_period`

**Mimo rozsah Fáze 1** (Fáze 3 / 5+ / 6):

- `DocsHeadsForm` (formulář faktury — hlavička + řádky + rekapitulace)
- Per-typ Document subclasses a viewers v navazujících modulech
  (`docs.invoicesOut`, `docs.invoicesIn`)

V této fázi lze uložit jen **prázdný Koncept dokladu** přes přímé volání
API (žádné UI). To stačí jako sanity check, že schéma a Document
infrastructure funguje.

## Konfigurace

Modul registruje 10 cfgItem souborů — viz `config/`:

- `docs.core.docTypes` — typy dokladů (zatím `invno`, `invni`)
- `docs.core.docStates` — stavový automat (Koncept → Potvrzeno → V pořádku
  → V opravě → Storno → Smazáno)
- `docs.core.vatModes` — režim DPH na hlavičce
- `docs.core.vatCalcSources` — odkud počítat DPH
- `docs.core.vatPlaces` — místo plnění
- `docs.core.priceCalcModes` — způsob výpočtu ceny na řádku
- `docs.core.rowKinds` — typ řádku (text / běžný)
- `docs.core.roundingModes` — zaokrouhlení
- `docs.core.paymentMethods` — způsoby platby
- `docs.core.resetScopes` — kdy se restartuje counter řady

## Závislosti

```
docs.core
├── core.system
├── core.units
├── core.attachments
├── base.persons (vyžaduje is_own + court_registration)
├── world.base
├── world.vat (vyžaduje vat-cz.jsonc)
├── economy.codebooks (fiscal_years, vat_periods, …)
└── economy.items (katalog položek)
```

## Číselné řady

Číselná řada je samostatný číselník (`docs_core_number_series`) editovatelný
přes UI v sekci Settings → Účtování. Každý typ dokladu má aspoň jednu řadu;
`NumberSeriesProvisioner` volaný z `ds-upgrade` automaticky založí default
řadu pro každý typ z cfgItem `docs.core.docTypes`.

Vzorec čísla dokladu používá `%X` placeholdery — viz cfgItem `docs.core.docTypes`
pro default vzorce a `docs/docs-mvp.md` sekce 5.4 pro popis placeholderů.

## Stavový model

Doklady mají vlastní rozšířenou sadu stavů (cfgItem `docs.core.docStates`),
**nikoli** standardní `core.system.docStatesArchive`. Klíčové rozdíly:

- **+ 20 Potvrzeno** — doklad má přidělené číslo, ale je stále editovatelný
- **+ 30 Storno** — náhrada za smazání po Potvrzení; zachovává číslo dokladu
  v sekvenci, sdílí `mainState=4` s V pořádku
- **− 70 V archívu** — u dokladů nadbytečné

Detaily v `docs/docs-mvp.md` sekce 3.

## Pro vývojáře

`OwnCompanyResolver` najde záznam vlastní firmy v `base_persons_persons`
(`is_own = 1`). Bez vlastní firmy nelze vystavovat doklady — od Fáze 2 se
to kontroluje při Potvrzení.

`DocDocument` (abstract) v této fázi pouze inicializuje `doc_number` jako
placeholder `!{id_padded}` (10 číslic) přes `afterPersist` a denormalizuje
`doc_type` z `number_series` v `beforeSave`. Reálné výpočty (cena, DPH,
rekapitulace, snapshoty) přijdou ve Fázi 2.
