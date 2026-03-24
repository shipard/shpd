# Tabulka: Administrativní členění (world_divisions)

Hierarchická evidence administrativních jednotek zemí — regiony, kraje, okresy,
obce atd. Data se nevyplňují ručně, ale importují se z externích datasetů
(ČSÚ, Destatis, INSEE atd.).

Tabulka pokrývá administrativní členění více zemí v jedné struktuře. Definice
úrovní hierarchie pro jednotlivé země je v konfiguraci
[adminLevels.jsonc](../config/adminLevels.jsonc).

## Struktura

### Zařazení (classification)

| Sloupec | Typ | Popis |
|---|---|---|
| `country` | varchar(2), NOT NULL | ISO 3166-1 alpha-2 kód země |
| `level` | smallint, NOT NULL | Úroveň v hierarchii (viz `adminLevels.jsonc`) |
| `code` | varchar(20), NOT NULL | Kód jednotky — unikátní v rámci země |
| `name` | varchar(200), NOT NULL | Název jednotky |

Sloupec `code` je string, protože některé země používají alfanumerické kódy
(např. Německo). Kombinace `country` + `code` je unikátní.

### Hierarchie (hierarchy)

| Sloupec | Typ | Popis |
|---|---|---|
| `parent_id` | int, FK → world_divisions | Přímý nadřízený ve stromu (`null` u kořenových položek) |
| `path` | varchar(500), NOT NULL | Materializovaná cesta pro stromové dotazy |

Sloupec `path` obsahuje cestu od kořene k aktuální položce ve formátu
`/{id}/{id}/{id}/`. Příklad pro obec Konice (CZ):

```
Střední Morava (region)   → path = /6201/
Olomoucký kraj            → path = /6201/6242/
Prostějov (okres)         → path = /6201/6242/6305/
Konice (ORP)              → path = /6201/6242/6305/6400/
Konice (ZUJ)              → path = /6201/6242/6305/6400/6450/
```

Typické dotazy:

- **Všechny obce v Olomouckém kraji:**
  `WHERE path LIKE '/6201/6242/%' AND level = 11`
- **Celá nadřazená cesta pro danou obec:**
  `WHERE id IN (6201, 6242, 6305, 6400)` (extrahované z `path`)
- **Přímí potomci:**
  `WHERE parent_id = 6242`

### Detail

| Sloupec | Typ | Popis |
|---|---|---|
| `person_id` | int, FK → base_persons_persons | Právnická osoba jednotky (IČ obce) — relevantní u nejnižší úrovně |

### Geolokace (geo)

| Sloupec | Typ | Popis |
|---|---|---|
| `latitude` | decimal(9,6) | Zeměpisná šířka (WGS 84) |
| `longitude` | decimal(9,6) | Zeměpisná délka (WGS 84) |

GPS souřadnice představují centroid jednotky (střed obce, sídlo úřadu apod.).

### Platnost (validity)

| Sloupec | Typ | Popis |
|---|---|---|
| `valid_from` | date | Datum vzniku jednotky (`null` = existuje od nepaměti) |
| `valid_to` | date | Datum zániku jednotky (`null` = stále platná) |

Platnost umožňuje evidovat historické změny — sloučení obcí, přečíslování
okresů apod. Zrušená jednotka se nemaže, jen se nastaví `valid_to`.

## Obchodní logika

Tabulka nemá vlastní dokumentovou třídu — data se plní hromadným importem
z externích datasetů. Sloupec `path` se vypočítává při importu na základě
`parent_id`.

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_country_level` | index | `country`, `level` | Výpis všech jednotek dané úrovně v zemi |
| `unq_country_code` | unique | `country`, `code` | Vyhledání podle kódu — unikátní v rámci země |
| `idx_parent_id` | index | `parent_id` | Navigace ve stromu — přímí potomci |
| `idx_path` | index | `path` | Stromové dotazy přes `LIKE '/prefix/%'` |
| `idx_person_id` | index | `person_id` | Zpětný odkaz na právnickou osobu |
| `idx_country_name` | index | `country`, `name` | Vyhledávání podle názvu v rámci země |
| `ft_name` | fulltext | `name` | Fulltextové vyhledávání názvů |

## Návaznosti

- **base_persons_persons** — sloupec `person_id` odkazuje na právnickou
  osobu dané územní jednotky (typicky IČ obce).
- **Adresy osob** — v tabulce adres (bude v `base.persons`) bude sloupec
  s referencí na `world_divisions.id`, čímž se adresa jednoznačně přiřadí
  k územní jednotce. To řeší duplicitní názvy obcí a umožňuje reporty
  per kraj/okres.
