# Tabulka: Měrné jednotky (core_units)

Číselník měrných jednotek používaných napříč systémem. Každý záznam
patří do jedné **veličiny** (`quantity`) a může mít koeficient pro
převody na základní jednotku téže veličiny.

`tableId = 310`. Stavový model: `core.system.docStatesArchive`.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `name` | varchar(50), NOT NULL | Český / anglický název jednotky (Kilogram, Meter, …) |
| `shortcut` | varchar(20), NOT NULL | Zkratka pro UI a doklady (kg, m, l, hod, …) |
| `system_code` | varchar(25), UNIQUE | Stabilní identifikátor systémové jednotky. NULL = uživatelská jednotka. |

### Veličina (quantity)

| Sloupec | Typ | Popis |
|---|---|---|
| `quantity` | enumString(10), NOT NULL | Klíč v [`core.units.quantities`](../config/quantities.jsonc) — weight, volume, length, area, time, energy, count, other |
| `coefficient` | numeric(20, 10) | Koeficient pro převod na základní jednotku téže veličiny. NULL = nepřevoditelná jednotka (typicky `time`). |
| `is_base` | boolean | true = základní jednotka veličiny; v rámci jedné veličiny by měla být max jedna |

## Indexy

| Index | Typ | Sloupce |
|---|---|---|
| `unq_system_code` | unique | `system_code` |
| `idx_quantity` | index | `quantity` ASC, `is_base` DESC |
| `idx_doc_state` | index | `docStateMain` ASC, `name` ASC |

## Význam `system_code`

`NOT NULL` = systémová jednotka — záznam pochází ze
[`unitsSeed.jsonc`](../config/unitsSeed.jsonc), provisioner ho při
`ds-upgrade` zajistí (vloží, pokud chybí; respektuje, pokud uživatel
záznam zarchivoval). Hodnota `system_code` je v UI readOnly.

`NULL` = uživatelská jednotka — provisioner se jí nedotýká.

## Význam `coefficient` a `is_base`

Sloupec `coefficient` udává, kolik **základních** jednotek téže
veličiny tato jednotka představuje:

- `kg` (weight, base): `coefficient = 1`, `is_base = true`
- `g` (weight): `coefficient = 0.001` — 1 g = 0,001 kg
- `t` (weight): `coefficient = 1000` — 1 t = 1000 kg

`is_base = true` označuje "základní" jednotku veličiny — o její koeficient
by mělo být `1`. Pro veličinu `time` neexistuje žádná základní jednotka
(převod mezi hodinou, dnem a měsícem není exaktní); její členy mají
`coefficient = NULL`.

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [economy_items](../../../economy/items/tables/economy_items.md) | `items.unit → units.id` | Měrná jednotka pro položku katalogu |
