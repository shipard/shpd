# Modul: Administrativní členění (world.divisions)

Hierarchická evidence administrativních jednotek zemí — regiony, kraje, okresy,
obce a další úrovně specifické pro danou zemi. Data se importují z externích
datasetů (ČSÚ, Destatis, INSEE atd.), nevyplňují se ručně.

Modul řeší několik problémů:

- **Duplicitní názvy obcí** — samotný název obce neurčuje jednoznačnou
  lokaci; vazba na administrativní jednotku ano.
- **Regionální reporty** — přehledy prodejů, tržeb apod. za jednotlivé
  kraje, okresy nebo regiony.
- **Legislativní požadavky** — např. Hlášení o nakládání s odpady vyžaduje
  uvádění ZUJ (Základní územní jednotka).

## Závislosti

- `world.base` — číselníky zemí
- `base.persons` — právnické osoby obcí (IČ)

## Tabulky

| Tabulka | Popis |
|---|---|
| [world_divisions](tables/world_divisions.md) | Administrativní jednotky — hierarchický strom |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `world.divisions.adminLevels` | [config/adminLevels.jsonc](config/adminLevels.jsonc) | Definice úrovní hierarchie per země |

## Principy

### Jedna tabulka pro všechny země

Administrativní jednotky všech zemí jsou v jedné tabulce `world_divisions`.
Země se rozlišuje sloupcem `country`. Úrovně hierarchie (co je "kraj",
co je "okres") se liší země od země a jsou definovány v `adminLevels.jsonc`.

### Stromová struktura

Každá jednotka má `parent_id` (přímý nadřízený) a `path` (materializovaná
cesta od kořene). Toto řešení umožňuje jak navigaci po přímých potomcích,
tak efektivní dotazy přes celý podstrom.

### Import dat

Data se plní hromadným importem — tabulka nemá vlastní dokumentovou třídu.
Sloupec `path` se vypočítává při importu na základě `parent_id`. Formát
importních dat bude specifikován samostatně.

### Aktuálně podporované země

| Země | Úrovně | Zdroj dat |
|---|---|---|
| Česko (CZ) | Region → Kraj → Okres → ORP → ZUJ | ČSÚ |
| Slovensko (SK) | Kraj → Okres → Obec | ŠÚ SR |
| Německo (DE) | Bundesland → Regierungsbezirk → Kreis → Gemeinde | Destatis |
| Rakousko (AT) | Bundesland → Bezirk → Gemeinde | Statistik Austria |
| Polsko (PL) | Województwo → Powiat → Gmina | GUS |
| Francie (FR) | Région → Département → Commune | INSEE |

Další země budou doplněny podle potřeby.
