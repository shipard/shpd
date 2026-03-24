# Tabulka: Adresy (base_persons_addresses)

## Účel

Tabulka eviduje adresy přiřazené k osobám a firmám v tabulce
`base_persons_persons`. Jedna osoba může mít libovolný počet adres
různých typů — sídlo firmy, doručovací adresy, provozovny, zařízení.

Tabulka řeší dva odlišné režimy zadávání:

- **Standardizovaná adresa** — vybraná z adresního registru přes API
  (RÚIAN v ČR, obdobné registry v jiných zemích). Jednotlivá pole
  jsou pro uživatele read-only, adresa obsahuje kompletní strukturu
  včetně kódu adresního místa.
- **Nestandardizovaná adresa** — zadávaná ručně. Zjednodušená struktura
  (ulice včetně čísla v jednom poli), bez registrových kódů. Typicky
  zahraniční adresy nebo případy, kdy standardizace není potřeba.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `person` | int, NOT NULL | Vazba na osobu/firmu (`base_persons_persons`) |
| `address_type` | enumInt | Typ adresy — viz [addressTypes.jsonc](../config/addressTypes.jsonc) |
| `name` | varchar(200) | Popisný název — „Sídlo firmy", „Sklad Brno", „Provozovna Praha 4" |
| `place_reg_type` | enumString(5) | Typ registru místa — viz [placeRegTypes.jsonc](../config/placeRegTypes.jsonc). Vyplňuje se jen u provozoven a zařízení |
| `place_reg_id` | varchar(20) | Identifikátor místa z registru — IČP, IČZ apod. |

### Adresa (address)

| Sloupec | Typ | Popis |
|---|---|---|
| `is_standardized` | boolean | Přepíná režim UI: 1 = výběr z API + read-only pole, 0 = ruční zadání |
| `street` | varchar(200) | Ulice — u nestandardizovaných adres včetně čísla |
| `house_number` | varchar(20) | Číslo popisné (č.p.) — jen u standardizovaných |
| `orientation_number` | varchar(20) | Číslo orientační (č.o.) — jen u standardizovaných |
| `city` | varchar(100) | Obec / město |
| `city_part` | varchar(100) | Část obce — jen u standardizovaných |
| `district` | varchar(100) | Městský obvod / městská část — jen u standardizovaných |
| `zip` | varchar(20) | PSČ |
| `country` | varchar(2) | ISO 3166-1 alpha-2 kód země |
| `registry_code` | varchar(30) | Kód adresního místa z registru — v ČR kód ADM z RÚIAN |
| `division` | int, FK → world_divisions | Administrativní jednotka — typicky obec (ZÚJ v ČR). Řeší duplicitní názvy obcí, umožňuje regionální reporty |

### Geolokace (geo)

| Sloupec | Typ | Popis |
|---|---|---|
| `latitude` | numeric(9,6) | Zeměpisná šířka (WGS 84) |
| `longitude` | numeric(9,6) | Zeměpisná délka (WGS 84) |
| `manual_gps` | boolean | Manuální zaměření — 1 pokud souřadnice zadány ručně (neúplná adresa, parcela) |

### Zobrazení (display)

| Sloupec | Typ | Popis |
|---|---|---|
| `display_line` | varchar(500) | Jednořádková adresa pro UI — `Karlova 15/3, 110 00 Praha 1, Česko` |
| `display_block` | text | Víceřádková adresa pro tiskové sestavy, řádky oddělené `\n` |

### Platnost (validity)

| Sloupec | Typ | Popis |
|---|---|---|
| `order_pos` | smallint | Pořadí zobrazení — nižší = vyšší priorita |
| `valid_from` | date | Platnost od |
| `valid_to` | date | Platnost do |
| `note` | text | Poznámka |

## Obchodní logika

### Standardizovaná vs nestandardizovaná adresa

Sloupec `is_standardized` přepíná režim formuláře:

**is_standardized = 1:**
- Adresa se vybírá z našeptávače napojení na adresní registr (RÚIAN API
  v ČR, obdobně v jiných zemích).
- Po „odklepnutí" uživatelem se přenesou všechny sloupce — `street`,
  `house_number`, `orientation_number`, `city`, `city_part`, `district`,
  `zip`, `registry_code`, `division`, `latitude`, `longitude`.
- Adresní pole jsou v UI read-only — uživatel nesmí ručně měnit
  jednotlivé složky, protože by se rozbila vazba na registr.
- Pro změnu adresy musí uživatel vybrat novou adresu z našeptávače.

**is_standardized = 0:**
- Adresa se zadává ručně.
- Sloupce `house_number`, `orientation_number`, `city_part`, `district`,
  `registry_code` se v UI nezobrazují (nebo jsou skryté).
- Ulice včetně čísla se zadává do `street`.
- Sloupec `division` se může vyplnit ručním výběrem ze seznamu obcí.

### Předpočítané zobrazení

Sloupce `display_line` a `display_block` se sestavují automaticky
v `beforeSave` pomocí per-country formatteru. Pravidla se liší podle
země:

- **ČR:** `Ulice čp/čo, PSČ Město` (PSČ s mezerou: `110 00`)
- **SK:** `Ulica čs/čo, PSČ Mesto`
- **DE:** `Straße Nr, PLZ Stadt` (PLZ bez mezery: `10115`)
- **FR:** `Rue Nr, Code postal VILLE` (město velkými písmeny)
- **US/UK:** Město před PSČ, stát/county na dalším řádku

Díky předpočítání je čtení triviální — žádné skládání za běhu.

### GPS souřadnice

U standardizovaných adres se GPS přebírá z registru. U nestandardizovaných
nebo neúplných adres (parcela, hala bez čísla popisného) může uživatel
zadat souřadnice ručně — v tom případě nastaví `manual_gps = 1`.

Při `beforeSave`:
- Pokud `is_standardized = 1` a `manual_gps = 0`, souřadnice se
  nepřepisují (přišly z registru).
- Pokud `manual_gps = 1`, uživatelské souřadnice mají přednost.

### Typ adresy a registr místa

| address_type | place_reg_type | place_reg_id | Příklad |
|---|---|---|---|
| 1 — Sídlo | — | — | Sídlo firmy |
| 2 — Doručovací | — | — | Doručovací adresa |
| 3 — Provozovna | `ICP` | `1234567890` | Provozovna s IČP |
| 4 — Zařízení | `ICZ` | `CZS00123` | Zařízení s IČZ |

Sloupce `place_reg_type` a `place_reg_id` se vyplňují pouze u typů,
které mají přidělený identifikátor z vnějšího registru. U sídla
a doručovací adresy zůstávají prázdné.

### Vazba na administrativní členění

Sloupec `division` odkazuje na tabulku `world_divisions` — typicky na
záznam úrovně ZÚJ (obec) v ČR, Gemeinde v DE apod. Tím se řeší:

- **Duplicitní názvy obcí** — „Lhota" existuje v ČR mnohokrát, ale
  vazba na `world_divisions.id` je jednoznačná.
- **Regionální reporty** — přes hierarchii v `world_divisions` lze
  agregovat adresy za kraj, okres, region.
- **Legislativní požadavky** — kód ZÚJ je dostupný přes
  `world_divisions.code`, není potřeba ho duplikovat.

## Indexy

| Index | Typ | Sloupce | Účel |
|---|---|---|---|
| `idx_person` | index | `person`, `address_type`, `order_pos` ASC | Hlavní přístupová cesta — adresy osoby podle typu a priority |
| `idx_city_street` | index | `city`, `street` | Vyhledávání podle města a ulice |
| `idx_zip` | index | `zip` | Vyhledávání podle PSČ |
| `idx_registry_code` | index | `registry_code` | Vyhledání podle kódu adresního místa (RÚIAN ADM) |
| `idx_division` | index | `division` | Zpětný odkaz — všechny adresy v dané obci |
| `idx_place_reg` | index | `place_reg_type`, `place_reg_id` | Vyhledání provozovny/zařízení podle identifikátoru |
| `ft_display_line` | fulltext | `display_line` | Fulltextové vyhledávání v jednořádkové adrese |

## Návaznosti

- **Rodičovská tabulka:** `base_persons_persons` — přes sloupec `person`
- **Administrativní členění:** `world_divisions` — přes sloupec `division`
  ([dokumentace](../../../world/divisions/tables/world_divisions.md))
- **Konfigurace:**
  - `base.persons.addressTypes` — [config/addressTypes.jsonc](../config/addressTypes.jsonc)
  - `base.persons.placeRegTypes` — [config/placeRegTypes.jsonc](../config/placeRegTypes.jsonc)
- **Plánované vazby:** Modul ekonomiky bude na adresu odkazovat
  ze záhlaví dokladu (fakturační adresa, doručovací adresa).
