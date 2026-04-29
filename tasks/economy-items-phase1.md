# Task: Položky a měrné jednotky — Fáze 1

## Kontext

Implementujeme dva nové moduly jako základ pro budoucí dokladový systém (faktury, objednávky, sklad):

- **`core.units`** — číselník měrných jednotek (kg, m, l, hod, ks, …) s podporou koeficientů pro převody mezi jednotkami stejné veličiny
- **`economy.items`** — katalog položek (zboží, služby) + číselník druhů položek

Oba moduly následují existující patterny v projektu. Před implementací **přečti**:

- `docs/modules.md` — modulový systém, JSONC, i18n
- `docs/table-definitions.md` — formát definice tabulek
- `docs/edit-forms.md` — editační formuláře (`TableForm`, `TabBuilder`, JSONC formy)
- `docs/doc-states.md` — stavy dokumentů, `docStatesArchive`
- `docs/frontend.md` — viewers, sidebar, ikony
- `docs/documentation.md` — formát README a `tables/*.md`

Vzorové existující moduly k nastudování:

- `modules/base/persons/` — komplexní modul s `TableForm`, `Viewer`, `Document`, vícenásobnými tabulkami
- `modules/core/attachments/` — jednodušší modul s jednou tabulkou + Document
- `modules/core/mail/src/MailRouterProvisioner.php` — vzor pro seedovací provisioner volaný z `ds-upgrade`

## Cíl Fáze 1

Po dokončení této fáze platí:

- `bin/shpd-ds ds-upgrade` na čistém DS vytvoří tabulky `core_units`, `economy_items_kinds`, `economy_items` a naseeduje 18 systémových jednotek a 4 systémové druhy položek
- V navigaci se objeví tři nové položky: **Měrné jednotky**, **Druhy položek**, **Položky** s vlastními viewery
- Uživatel může přes editační formuláře vytvářet/editovat všechny tři entity, včetně cenotvorby (cena bez DPH) a vazeb na druh + jednotku
- Změna `item_type` u druhu položky, který je už použitý, je odmítnuta
- Auto-generování 6-znakového hex kódu položky funguje s detekcí kolizí

## Návaznost

- Závisí na: `core.system`, `core.attachments` (přílohy ve formech), funkční edit-forms infrastruktura (Fáze 1–3 hotové), funkční doc-states (Fáze 1–3 hotové)
- Otevírá: budoucí modul `economy.docs` (faktury), skladová evidence, ceníkový mechanismus, sazby DPH per země

## Scope

### V rozsahu

- Tabulky `core_units` (tableId 310), `economy_items_kinds` (tableId 312), `economy_items` (tableId 311)
- Standardní `docStatesArchive` stavy pro všechny tři tabulky
- PHP `TableForm`, `Viewer`, `Document` třídy pro všechny tři tabulky
- Provisionery pro seed jednotek a druhů položek, idempotentní při opakovaných `ds-upgrade`
- Cena položky **pouze bez DPH** (`sales_price_no_vat`); cena s DPH dorazí s VAT modulem
- Měna jen jedna (domácí měna DS), bez sloupce `currency` na položce
- `item_type` na položce je denormalizace z `item_kind` (read-only v UI, plněn serverem v `beforeSave` + `recalculate`)
- Kód položky `code`: 6-znakový hex auto-gen pokud uživatel nezadá; jinak respektuje uživatelský vstup; UNIQUE napříč DS
- Frontend ikony: `iconRuler` (units), `iconBox` (items); `iconTags` se použije pro item kinds (už existuje)
- Documentation per modul: `README.md` + `tables/{table_id}.md`

### Mimo rozsah (odložené)

- VAT sazby per země a cena s DPH na položce
- Skladová evidence (sklady, pohyby, stavy)
- Ceníkový mechanismus (více cen na jednu položku, slevy)
- Inline editace pro sub-tabulky
- Per-form JavaScript za rámec `recalculate` endpointu
- Inteligentnější UX pro změnu `item_type` u druhu (zatím vždy readOnly v UI, validace v Document)

## Datový model

### Nový: `core_units` (tableId 310)

`docStates`: standardní `core.system.docStatesArchive`. `displayPattern`: `"{name} ({shortcut})"`.

Sloupce ve dvou skupinách + systémové:

**`identity`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `id` | int PK autoIncrement | |
| `name` | varchar(50) NOT NULL | "Kilogram" |
| `shortcut` | varchar(20) NOT NULL | "kg" |
| `system_code` | varchar(25) NULL | UNIQUE; NULL = uživatelská jednotka |

**`quantity`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `quantity` | enumString(10) NOT NULL | `cfgItem: "core.units.quantities"` |
| `coefficient` | numeric(20, 10) NULL | koef. k základní jednotce; NULL = nepřevoditelné |
| `is_base` | boolean default 0 | true = základní jednotka v rámci `quantity` |

**Systémové (bez group):**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `docState` | tinyint default 10 | system: true |
| `docStateMain` | tinyint default 1 | system: true |

Indexy:

- `unq_system_code` UNIQUE na `system_code`
- `idx_quantity` na `quantity`, `is_base`
- `idx_doc_state` na `docStateMain`, `name`

### Nový: `economy_items_kinds` (tableId 312)

`docStates`: standardní `core.system.docStatesArchive`. `displayPattern`: `"{name}"`.

| Sloupec | Typ | Pozn. |
|---|---|---|
| `id` | int PK autoIncrement | |
| `name` | varchar(100) NOT NULL | např. "Konzultace IT" |
| `item_type` | enumInt NOT NULL default 3 | `cfgItem: "economy.items.itemTypes"` |
| `valid_from` | date NULL | |
| `valid_to` | date NULL | |
| `system_code` | varchar(25) NULL | UNIQUE |
| `docState` | tinyint default 10 | system: true |
| `docStateMain` | tinyint default 1 | system: true |

Indexy:

- `unq_system_code` UNIQUE na `system_code`
- `idx_item_type` na `item_type`
- `idx_doc_state` na `docStateMain`, `name`
- `ft_name` FULLTEXT na `name`

### Nový: `economy_items` (tableId 311)

`docStates`: standardní `core.system.docStatesArchive`. `displayPattern`: `"{code} — {name}"`.

**`identity`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `id` | int PK autoIncrement | |
| `code` | varchar(25) NOT NULL | UNIQUE; auto-gen 6-hex pokud prázdný |
| `name` | varchar(200) NOT NULL | |

**`classification`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `item_kind` | int NOT NULL | `reference: "economy_items_kinds"` |
| `item_type` | enumInt NOT NULL default 3 | `cfgItem: "economy.items.itemTypes"`; **bez** `system: true`, ale ve formu readOnly + plní `beforeSave` |

**`details`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `description` | varchar(200) NULL | |
| `valid_from` | date NULL | |
| `valid_to` | date NULL | |

**`pricing`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `sales_price_no_vat` | numeric(15, 4) NULL | prodejní cena bez DPH |
| `unit` | int NOT NULL | `reference: "core_units"` |

**Systémové:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `docState` | tinyint default 10 | system: true |
| `docStateMain` | tinyint default 1 | system: true |

Indexy:

- `unq_code` UNIQUE na `code`
- `idx_item_kind` na `item_kind`
- `idx_item_type` na `item_type`
- `idx_unit` na `unit`
- `idx_doc_state` na `docStateMain`, `name`
- `ft_name` FULLTEXT na `name`, `description`

### Nový cfgItem: `core.units.quantities`

Soubor `modules/core/units/config/quantities.jsonc`. Klíče lowercase ASCII pro `enumString quantity`:

```jsonc
{
    "weight":  {"name": "Weight",   "name:cs": "Hmotnost",  "name:en": "Weight"},
    "volume":  {"name": "Volume",   "name:cs": "Objem",     "name:en": "Volume"},
    "length":  {"name": "Length",   "name:cs": "Délka",     "name:en": "Length"},
    "area":    {"name": "Area",     "name:cs": "Plocha",    "name:en": "Area"},
    "time":    {"name": "Time",     "name:cs": "Čas",       "name:en": "Time"},
    "energy":  {"name": "Energy",   "name:cs": "Energie",   "name:en": "Energy"},
    "count":   {"name": "Count",    "name:cs": "Počet",     "name:en": "Count"},
    "other":   {"name": "Other",    "name:cs": "Ostatní",   "name:en": "Other"}
}
```

### Nový cfgItem: `economy.items.itemTypes`

Soubor `modules/economy/items/config/itemTypes.jsonc` pro `enumInt item_type`:

```jsonc
{
    "0": {"name": "Service",     "name:cs": "Služba",          "name:en": "Service"},
    "1": {"name": "Stock",       "name:cs": "Zásoba",          "name:en": "Stock"},
    "2": {"name": "Accounting",  "name:cs": "Účetní položka",  "name:en": "Accounting item"},
    "3": {"name": "Other",       "name:cs": "Ostatní",         "name:en": "Other"}
}
```

### Seed: `core/units/config/unitsSeed.jsonc`

Pole 18 záznamů. Každý má `system_code`, `name`, `name:cs`, `name:en`, `shortcut`, `quantity`, `coefficient` (nebo null), `is_base`. Konkrétní hodnoty:

| system_code | name (en) | name (cs) | shortcut | quantity | coefficient | is_base |
|---|---|---|---|---|---|---|
| `pcs` | Piece | Kus | ks | count | 1 | true |
| `hr` | Hour | Hodina | hod | time | null | false |
| `hr_2` | Half hour | Půlhodina | 30min | time | null | false |
| `hr_4` | Quarter hour | Čtvrthodina | 15min | time | null | false |
| `day` | Day | Den | den | time | null | false |
| `mnth` | Month | Měsíc | měs | time | null | false |
| `year` | Year | Rok | rok | time | null | false |
| `m` | Meter | Metr | m | length | 1 | true |
| `km` | Kilometer | Kilometr | km | length | 1000 | false |
| `m2` | Square meter | Metr čtvereční | m² | area | 1 | true |
| `m3` | Cubic meter | Metr krychlový | m³ | volume | 1000 | false |
| `l` | Liter | Litr | l | volume | 1 | true |
| `kg` | Kilogram | Kilogram | kg | weight | 1 | true |
| `g` | Gram | Gram | g | weight | 0.001 | false |
| `t` | Tonne | Tuna | t | weight | 1000 | false |
| `kwh` | Kilowatt-hour | Kilowatthodina | kWh | energy | 1 | true |
| `mwh` | Megawatt-hour | Megawatthodina | MWh | energy | 1000 | false |
| `gj` | Gigajoule | Gigajoule | GJ | energy | 277.7777777778 | false |

### Seed: `economy/items/config/itemKindsSeed.jsonc`

Pole 4 záznamů — jeden per `item_type`:

```jsonc
[
    {"system_code": "service",    "name:cs": "Služba",         "name:en": "Service",         "item_type": 0},
    {"system_code": "stock",      "name:cs": "Zásoba",         "name:en": "Stock",           "item_type": 1},
    {"system_code": "accounting", "name:cs": "Účetní položka", "name:en": "Accounting item", "item_type": 2},
    {"system_code": "other",      "name:cs": "Ostatní",        "name:en": "Other",           "item_type": 3}
]
```

## Adresářová struktura modulů

```
modules/core/units/
├── module.jsonc
├── README.md
├── tables/
│   ├── core_units.jsonc
│   └── core_units.md
├── config/
│   ├── quantities.jsonc
│   └── unitsSeed.jsonc
└── src/
    ├── UnitDocument.php
    ├── UnitsForm.php
    ├── UnitsViewer.php
    └── UnitsProvisioner.php

modules/economy/items/
├── module.jsonc
├── README.md
├── tables/
│   ├── economy_items.jsonc
│   ├── economy_items.md
│   ├── economy_items_kinds.jsonc
│   └── economy_items_kinds.md
├── config/
│   ├── itemTypes.jsonc
│   └── itemKindsSeed.jsonc
└── src/
    ├── ItemDocument.php
    ├── ItemKindDocument.php
    ├── ItemsForm.php
    ├── ItemKindsForm.php
    ├── ItemsViewer.php
    ├── ItemKindsViewer.php
    └── ItemKindsProvisioner.php
```

Namespace: `Shipard\Module\Core\Units\*` a `Shipard\Module\Economy\Items\*`.

## Task breakdown

### Krok 1: Modul `core.units` — schéma a UI

Vytvoř kompletní strukturu modulu:

- `module.jsonc` s deps `["core.system"]`, registrací vieweru `core.units` (icon `ruler`), formu pro `core_units`, document class, config items pro `core.units.quantities`
- `tables/core_units.jsonc` + `.md` dokumentace
- `config/quantities.jsonc` (cfgItem)
- `src/UnitDocument.php`:
  - `validate`: `name`/`shortcut`/`quantity` povinné; `quantity` musí být platný klíč v cfgItem; `coefficient` pokud zadaný, musí být `> 0`
  - `beforeSave`: žádná transformace (placeholder pro budoucnost)
- `src/UnitsForm.php`: jeden tab `basic`, layout `[name 2c, shortcut 1c, system_code 1c readOnly] [quantity 1c, coefficient 1c, is_base 1c]` + `attachmentsTab()`. `fullSize: false`. `system_code` je readOnly pokud má hodnotu (= seedovaný záznam), jinak skrytý.
- `src/UnitsViewer.php`: viewGroup filter, search přes `name`/`shortcut`/`system_code`, ORDER BY `docStateMain ASC, quantity ASC, is_base DESC, name ASC`. Render row: `t1` = name, `i1` = shortcut, `t2` = [veličina z cfgItem, badge "základní" pokud `is_base`, badge stavu], `t3` = `"Koef.: {coefficient}"` pokud non-NULL.

### Krok 2: Provisioner pro `core.units`

- `config/unitsSeed.jsonc` se všemi 18 jednotkami
- `src/UnitsProvisioner.php`:
  - Konstruktor: `DataSourceConnection $db, string $seedFilePath`
  - `provision(): array` — pro každý záznam ze seedu: `SELECT id FROM core_units WHERE system_code = :code`; pokud existuje (bez ohledu na docState) → skip; pokud ne → INSERT s `docState = 40, docStateMain = 3, name = name:cs`
  - Vrací `['units' => ['created' => N, 'existing' => M]]`
  - Vzor: `modules/core/mail/src/MailRouterProvisioner.php`

### Krok 3: Hook do `DsUpgradeCommand` pro units

V `src/Command/DataSource/DsUpgradeCommand.php::execute()` před `provisionMailRouter()` přidej:

```php
$this->provisionUnits($resolvedModules, $dsConnection, $output);
```

Nová private metoda kontroluje, že modul je aktivní (`in_array('core.units', array_map(fn($m) => $m->id, $resolvedModules))`); jinak vypíše `[SKIP] core.units module not active` a skončí. Jinak instancuje `UnitsProvisioner` a vypíše souhrn.

**Ověř na testovacím DS**: spusť `bin/shpd-ds ds-upgrade`, zkontroluj že tabulka vznikla a 18 řádků naseedovalo. Druhý běh musí být no-op (`existing: 18, created: 0`).

### Krok 4: Modul `economy.items` — kinds

- `modules/economy/items/module.jsonc` s deps `["core.system", "core.units"]`
- `tables/economy_items_kinds.jsonc` + `.md`
- `config/itemTypes.jsonc` (cfgItem)
- `config/itemKindsSeed.jsonc`
- `src/ItemKindDocument.php`:
  - `validate`: `name` povinné; `item_type` musí být platná hodnota cfgItem (0–3); **pokud existující záznam (`!empty($data['id'])`) a `item_type` se mění oproti DB**: zkontroluj počet referencí z `economy_items` a vrať error `'in_use'` pokud `> 0` (zpráva: "Typ položky nelze změnit — druh je již použit u N položek.")
  - `beforeSave`: žádná transformace
- `src/ItemKindsForm.php`: tab `basic` s `[name 2c required, item_type 1c select required readOnly-při-existujícím] [valid_from 1c, valid_to 1c, system_code 1c readOnly-pokud-má]` + `attachmentsTab()`. `fullSize: false`. `item_type` je vždy readOnly pro existující záznam (zjednodušení UX; budoucí iterace může povolit pokud nepoužitý).
- `src/ItemKindsViewer.php`: viewGroup filter, search přes `name`/`system_code`. Render: `t1` = name, `i1` = label `item_type` z cfgItem, `t2` = [badge "systémový" pokud `system_code !== null`, badge stavu]. Detail tab "Přehled" + tab "Položky" zobrazující až 100 položek s tímto druhem.
- `src/ItemKindsProvisioner.php`: stejný pattern jako `UnitsProvisioner`. Hook do `DsUpgradeCommand` po `provisionUnits`.

**Ověř na testovacím DS**: po `ds-upgrade` jsou 4 systémové druhy.

### Krok 5: Modul `economy.items` — items

- `tables/economy_items.jsonc` + `.md`
- `src/ItemDocument.php`:
  - `validate`: `name` povinné; `item_kind` povinné a musí existovat v `economy_items_kinds`; `unit` povinné a musí existovat v `core_units`; `sales_price_no_vat` pokud zadaná, musí být `>= 0`; `code` pokud zadaný uživatelem, musí být unikátní (lookup s `id <> $data['id']` pokud update)
  - `beforeSave`:
    - **Auto-gen `code`**: pokud prázdný → smyčka 10 pokusů `bin2hex(random_bytes(3))` s lookupem `SELECT id FROM economy_items WHERE code = %s`; fallback `bin2hex(random_bytes(4))` (8 znaků)
    - **Denormalizace `item_type`**: lookup `SELECT item_type FROM economy_items_kinds WHERE id = %i` podle `data['item_kind']`, výsledek dosaď do `data['item_type']`
- `src/ItemsForm.php`:
  - Tab `basic`: `[code 1c hint:"Necháte-li prázdné, kód se vygeneruje automaticky", name 3c required]`, separator "Klasifikace", `[item_kind 2c select required triggers:reload, item_type 1c select readOnly, unit 1c select required]`, separator "Cena", `[sales_price_no_vat 1c number step=0.01]`, separator "Platnost", `[valid_from 1c, valid_to 1c]`
  - Tab `description`: `[description 4c textarea]`
  - + `attachmentsTab()`. `fullSize: true`.
  - **Options pro `item_kind`**: dynamicky z DB (`SELECT id, name FROM economy_items_kinds WHERE docState IN (10, 40, 80) ORDER BY name`)
  - **Options pro `unit`**: dynamicky z DB (`SELECT id, name, shortcut FROM core_units WHERE docState IN (10, 40, 80) ORDER BY name`); label = `"{name} ({shortcut})"`
  - **Options pro `item_type`**: z cfgItem (jen pro display, je readOnly)
  - **Default `unit` pro nový záznam**: pokud `$isNew && empty($data['unit'])`, načti `id` jednotky `pcs` (`WHERE system_code = 'pcs'`) a vlož do `$data['unit']` před vrácením FormDefinition
  - **`recalculate`**: při změně `item_kind` lookup `item_type` v DB a aktualizuj `data['item_type']`, pak rebuild FormDefinition
- `src/ItemsViewer.php`: search přes `name`/`code`/`description`. JOIN na `economy_items_kinds` pro `kind_name`. Render: `t1` = name, `i1` = code, `t2` = [`item_type` label, `kind_name`, badge stavu], `t3` = formátovaná cena (např. `"199,00 Kč"` — měna natvrdo "Kč" pro v1) pokud `sales_price_no_vat` non-NULL.

### Krok 6: Frontend — ikony

V `frontend/src/icons.js`:

```js
import {
  // ... existující ...
  faRulerCombined,
  faCube,
} from '@fortawesome/free-solid-svg-icons';

export const iconRuler = faRulerCombined;
export const iconBox = faCube;
```

A do `iconMap`:

```js
'ruler': iconRuler,
'box': iconBox,
```

(`tags` pro `economy.items.kinds` už v iconMap existuje.)

Spusť `npm run build` v `frontend/` po úpravě.

### Krok 7: `install.base` aktualizace

V `modules/install/base/module.jsonc` přidej do `dependencies`:

```jsonc
"core.units",
"economy.items",
```

### Krok 8: Testy

Vytvoř testy v `tests/Unit/Module/Core/Units/UnitDocumentTest.php` a `tests/Unit/Module/Economy/Items/{ItemDocumentTest,ItemKindDocumentTest}.php`. Vzor: `tests/Unit/Module/Base/Persons/PersonDocumentTest.php`.

Pokrýt minimálně:

- `UnitDocumentTest`: chybějící name → error; chybějící shortcut → error; chybějící quantity → error; záporný coefficient → error; NULL coefficient → OK; všechno povinné OK → valid
- `ItemKindDocumentTest`: chybějící name → error; neplatný item_type → error; pro change-of-item_type test můžeš vynechat, pokud test bez DB infrastruktury není praktické
- `ItemDocumentTest`: chybějící name → error; chybějící item_kind → error; chybějící unit → error; záporná cena → error; všechno OK → valid; auto-gen code přes mock DB (vrací NULL na uniqueness lookup) → výsledek má 6 hex znaků

### Krok 9: Documentation

- `modules/core/units/README.md`: přehled modulu, závislosti, popis tabulky, sekce "Seedovaná data" se zmínkou o `unitsSeed.jsonc` a 18 systémových jednotkách, vysvětlení mechaniky `is_base + coefficient` (převody)
- `modules/core/units/tables/core_units.md`: sloupce per skupina, význam `system_code`, význam `coefficient` + `is_base`, poznámka že NULL coefficient = nepřevoditelná jednotka
- `modules/economy/items/README.md`: přehled, závislosti, dvě tabulky, 4 systémové druhy, vazby (`item_kind` → kinds, `unit` → core_units), poznámka o ceně bez DPH a budoucí VAT integraci
- `modules/economy/items/tables/economy_items.md`: sloupce, vysvětlení denormalizace `item_type`, auto-gen `code`, vazby
- `modules/economy/items/tables/economy_items_kinds.md`: sloupce, business rule "item_type nelze změnit u použitého druhu", systémové druhy a `system_code`

## Rozhodnutí k designu (potvrzená)

✓ **Modul jednotek je `core.units`** (ne `economy.units`) — jednotky jsou sdílené přes celý systém

✓ **Cena položky pouze bez DPH** — cena s DPH dorazí s VAT modulem

✓ **Změna `item_type` u druhu jen pokud nepoužitý** — validace v `ItemKindDocument`. UI v Fázi 1 vždy readOnly pro existující záznam (jednodušší, validace zachytí pokus o obejití)

✓ **Auto-gen kódu položky**: 6-znakový hex přes `bin2hex(random_bytes(3))`, kontrola unikátnosti, max 10 pokusů, fallback 8 znaků

✓ **Měna jen jedna** (domácí měna DS), ceníkový mechanismus později

✓ **Skladová evidence až s doklady**, ne v této fázi

✓ **`isSystem` flag → jen `system_code`** (NOT NULL = systémový, NULL = uživatelský). Provisioner při `ds-upgrade` respektuje, pokud uživatel systémový záznam smazal/archivoval — bere to jako "skryl si ho"

✓ **`item_type` na položce: bez `system: true`, ale readOnly v UI + plněn v `beforeSave`** (přes `system: true` by ho `filterWritableFields` blokoval; `beforeSave` přepsání garantuje konzistenci)

✓ **`pcs` jako default jednotka** pro nové položky — předvyplnění v `buildFormDefinition` lookupem `WHERE system_code = 'pcs'`

✓ **`name` při seedování → vezme se `name:cs`** napevno pro v1 (DS jsou převážně české). Provisioner si může v budoucnu číst `defaultLanguage` z `DataSourceConfig`, pokud bude potřeba

✓ **Veličiny jednotek**: weight (base=kg), volume (base=l, m³ má coef 1000), length (base=m), area (base=m²), energy (base=kWh, GJ má coef 277.7778), count (base=ks), time (žádný base, všechny coef NULL = nepřevoditelné), other

✓ **Konvence názvu tabulky pro single-table modul**: `core_units` (jako `world_divisions`, ne `core_units_units`)

## Hotovo když

- [ ] `bin/shpd-ds ds-upgrade` na čistém DS vytvoří všechny tři tabulky bez chyb
- [ ] Po prvním upgrade je v `core_units` 18 systémových jednotek (`docState = 40`)
- [ ] Po prvním upgrade jsou v `economy_items_kinds` 4 systémové druhy (`docState = 40`)
- [ ] Druhý běh `ds-upgrade` neduplikuje seed (output: `existing: 18/4, created: 0`), ani když uživatel mezitím nějaký záznam smazal/archivoval
- [ ] V navigaci se objeví "Měrné jednotky", "Druhy položek", "Položky" s odpovídajícími ikonami (ruler, tags, box)
- [ ] Editační formulář pro položku auto-generuje `code` (6 hex znaků) když uživatel nevyplní
- [ ] Změna `item_kind` ve formuláři položky aktualizuje `item_type` přes recalculate
- [ ] `item_type` je ve formuláři položky readOnly
- [ ] Pokus změnit `item_type` u druhu položky, který má alespoň jednu položku, vrátí 422 s chybou `in_use`
- [ ] Default jednotka u nové položky je `ks`
- [ ] PHPUnit testy pro Document classes prochází: `vendor/bin/phpunit tests/Unit/Module/Core/Units tests/Unit/Module/Economy/Items`
- [ ] Frontend build prochází bez chyb po přidání ikon
- [ ] Documentation napsaná: README per modul + `.md` per tabulka

## Konvence a upozornění

- **Jazyk**: UI texty čeština, kód a komentáře angličtina
- **Vícejazyčnost**: každé `name` v JSONC musí mít `:cs` a `:en` variantu
- **PHP 8.3** strict_types, readonly properties kde možné
- **`Dibi\DateTime` normalizace** už řeší `DataSourceConnection::fetchRow/fetchAll` — neřešit ručně
- **Composer autoload**: pravděpodobně už nakonfigurován pro `modules/`, ale po vytvoření nových adresářů spusť `composer dump-autoload`
- **Testovací DS**: použij existující testovací DS nebo vytvoř nový přes `bin/shpd-server ds-create --name="test-items"`
- **Po každém kroku ověř** na testovacím DS, ať se chyby nehromadí; nejhorší je dotáhnout všechno až do testů a tam zjistit, že provisioner padá

## Doporučené pořadí implementace

Krok 1 → 2 → 3 (core.units kompletní + ověření) → 4 (kinds + ověření) → 5 (items + ověření) → 6, 7, 8, 9 (frontend, install.base, testy, docs).
