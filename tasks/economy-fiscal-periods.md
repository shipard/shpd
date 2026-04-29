# Task: Fiskální období — roky a měsíce

## Kontext

Implementujeme dvě nové tabulky v existujícím modulu `economy.codebooks` jako poslední číselník před spuštěním práce na dokladech:

- **`economy_codebooks_fiscal_years`** — fiskální (účetní) roky se stavy dokumentů, prohlížečem a editačním formulářem
- **`economy_codebooks_fiscal_months`** — fiskální měsíce navázané na rok (1× Otevření + 12× Běžné období + 1× Uzavření = 14 záznamů na rok)

Až se začne dělat dokladový systém, každý doklad podle účetního data „spadne" do konkrétního fiskálního roku a měsíce — proto potřebujeme tyto tabulky dřív než cokoliv dokladového.

Před implementací **přečti**:

- `docs/modules.md` — modulový systém, JSONC, i18n
- `docs/table-definitions.md` — formát definice tabulek
- `docs/edit-forms.md` — editační formuláře (`TableForm`, `TabBuilder`, sub-tabulky)
- `docs/doc-states.md` — stavy dokumentů, `docStatesArchive`
- `docs/frontend.md` — viewers, sidebar, ikony
- `docs/documentation.md` — formát README a `tables/*.md`

Vzorové existující implementace k nastudování:

- `modules/base/persons/` — `PersonsForm` s sub-tabulkami (vzor pro Měsíce ve formu Roku), `PersonsViewer`, `PersonDocument`
- `modules/economy/items/` — `ItemKindsProvisioner` (vzor pro `FiscalYearsProvisioner`), idempotentní seed s lookupem před insertem
- `modules/base/persons/forms/base_persons_bank_accounts.jsonc` — vzor deklarativního JSONC sub-formu
- `modules/core/system/config/docStatesArchive.jsonc` — standardní sada stavů, kterou roky používají

## Cíl

Po dokončení této fáze platí:

- `bin/shpd-ds ds-upgrade` na DS bez fiskálních roků vygeneruje aktuální fiskální rok (`docState = 40`) + 14 měsíců
- Každý další běh `ds-upgrade` zkontroluje, zda existuje rok pro „příští" období; pokud ne, vygeneruje ho
- V navigaci se objeví položka **Fiskální období** s vlastním viewerem
- Uživatel může přes UI vytvářet/editovat fiskální roky (vznikají jako `Koncept` (10), uživatel je manuálně přepne do `V pořádku` (40))
- V editačním formuláři roku jsou v záložce **Měsíce** vidět jednotlivé měsíce; lze je editovat (přidat/upravit/smazat) přes sub-formulář
- Validace v `FiscalYearDocument` a `FiscalMonthDocument` zachytí základní nesmysly (`date_begin > date_end`, prázdné povinné pole)

## Návaznost

- Závisí na: `core.system`, funkční edit-forms infrastruktura, funkční doc-states, `core.attachments` (pokud bude form mít attachmentsTab)
- Modul `economy.codebooks` už existuje s tableId 101 (`warehouses`) a 102 (`cost_centers`) — placeholder stuby, které tato fáze neřeší
- Otevírá: dokladový systém (faktury, objednávky), kde každý doklad podle `účetního data` najde svůj fiskální měsíc a rok

## Scope

### V rozsahu

- Tabulky `economy_codebooks_fiscal_years` (tableId **313**) a `economy_codebooks_fiscal_months` (tableId **314**)
- Standardní `core.system.docStatesArchive` stavy pro `fiscal_years`; `fiscal_months` bez vlastních stavů (lifecycle dědí přes rodiče)
- PHP třídy: `FiscalYearDocument`, `FiscalMonthDocument`, `FiscalYearsForm` (TableForm subclass), `FiscalYearsViewer`, `FiscalYearsProvisioner`
- Deklarativní JSONC sub-form pro `economy_codebooks_fiscal_months`
- cfgItemy: `economy.codebooks.fiscalPeriodTypes` (typy období), `economy.codebooks.fiscalConfig` (`yearStartMonth`)
- Provisioner volaný z `DsUpgradeCommand` po `provisionItemKinds`, idempotentní
- Frontend ikona: `iconCalendar` (`faCalendarDays`)
- Documentation: `README.md` modulu (rozšíření existujícího), `tables/economy_codebooks_fiscal_years.md`, `tables/economy_codebooks_fiscal_months.md`
- Závislost `economy.codebooks` na `world.base` (pro budoucí currency picker — zatím nepoužíváme, ale modul ji potřebuje pro konzistenci)

### Mimo rozsah (odložené)

- Validace `locked = true` při vytváření/editaci dokladů (přijde s dokladovým systémem)
- Validace překrývání období / mezery mezi roky (zatím odpovědnost uživatele)
- UI currency picker — `currency` zůstává prostý `varchar(3)` text input s defaultem `czk`
- Per-DS override `yearStartMonth` (zatím modulový default)
- Tlačítko „Vygenerovat měsíce" v editačním formuláři manuálně vytvořeného roku (uživatel přidává měsíce po jednom přes sub-form)
- Stavy a viewer pro `fiscal_months` (lifecycle dědí přes rodiče)

## Datový model

### Nový: `economy_codebooks_fiscal_years` (tableId 313)

`docStates`: `core.system.docStatesArchive`. `displayPattern`: `"{name}"`.

**Skupina `identity`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `id` | int PK autoIncrement | |
| `name` | varchar(20) NOT NULL | UNIQUE; "2026" nebo "2026-2027" |
| `doc_number_prefix` | varchar(10) NOT NULL | "26", používá se v budoucnu pro číselné řady dokladů |

**Skupina `period`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `date_begin` | date NOT NULL | |
| `date_end` | date NOT NULL | |
| `currency` | varchar(3) NOT NULL default `'czk'` | ISO 4217 lowercase, řeší přechod CZK→EUR |
| `locked` | boolean NOT NULL default 0 | true = doklady v tomto období nelze editovat |

**Systémové (bez group):**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `docState` | tinyint default 10 | system: true |
| `docStateMain` | tinyint default 1 | system: true |

Indexy:

- `unq_name` UNIQUE na `name`
- `idx_dates` na `date_begin`, `date_end` (pro budoucí lookup dle účetního data)
- `idx_doc_state` na `docStateMain ASC`, `date_begin DESC`

### Nový: `economy_codebooks_fiscal_months` (tableId 314)

Bez `docStates`. `displayPattern`: `"{calendar_year}-{calendar_month}"`.

| Sloupec | Typ | Pozn. |
|---|---|---|
| `id` | int PK autoIncrement | |
| `fiscal_year` | int NOT NULL | `reference: "economy_codebooks_fiscal_years"` |
| `date_begin` | date NOT NULL | |
| `date_end` | date NOT NULL | |
| `period_type` | enumInt NOT NULL default 1 | `cfgItem: "economy.codebooks.fiscalPeriodTypes"` (0=Otevření, 1=Běžné, 2=Uzavření) |
| `calendar_year` | int NOT NULL | odvozeno z `date_begin` v `beforeSave` |
| `calendar_month` | smallint NOT NULL | odvozeno z `date_begin` v `beforeSave`, 1–12 |

Indexy:

- `idx_fiscal_year` na `fiscal_year`, `date_begin`
- `idx_dates` na `date_begin`, `date_end` (pro budoucí lookup dle účetního data)

### Nový cfgItem: `economy.codebooks.fiscalPeriodTypes`

Soubor `modules/economy/codebooks/config/fiscalPeriodTypes.jsonc`:

```jsonc
{
    // Hodnoty odpovídají enumInt period_type v fiscal_months
    "0": {
        "name": "Opening",
        "name:cs": "Otevření",
        "name:en": "Opening"
    },
    "1": {
        "name": "Regular period",
        "name:cs": "Běžné období",
        "name:en": "Regular period"
    },
    "2": {
        "name": "Closing",
        "name:cs": "Uzavření",
        "name:en": "Closing"
    }
}
```

### Nový cfgItem: `economy.codebooks.fiscalConfig`

Soubor `modules/economy/codebooks/config/fiscalConfig.jsonc`. Modulový default; pro v1 není per-DS override:

```jsonc
{
    // První měsíc fiskálního roku (1 = leden, 7 = červenec, …).
    // Pro CZ/SK je výchozí leden. Per-DS override přijde v budoucnu
    // (mechanismus zatím není implementován).
    "yearStartMonth": 1
}
```

## Adresářová struktura

Modul `economy.codebooks` už existuje, takže přidáváme do něj:

```
modules/economy/codebooks/
├── module.jsonc                  # ROZŠÍŘIT — nové tables, forms, viewers, config, dep
├── README.md                     # NOVÝ — modul zatím README nemá
├── config/                       # NOVÁ složka
│   ├── fiscalPeriodTypes.jsonc
│   └── fiscalConfig.jsonc
├── forms/                        # NOVÁ složka
│   └── economy_codebooks_fiscal_months.jsonc
├── tables/
│   ├── economy_codebooks_warehouses.jsonc      # beze změny
│   ├── economy_codebooks_cost_centers.jsonc    # beze změny
│   ├── economy_codebooks_fiscal_years.jsonc    # NOVÝ
│   ├── economy_codebooks_fiscal_years.md       # NOVÝ
│   ├── economy_codebooks_fiscal_months.jsonc   # NOVÝ
│   └── economy_codebooks_fiscal_months.md      # NOVÝ
└── src/                          # NOVÁ složka
    ├── FiscalYearDocument.php
    ├── FiscalMonthDocument.php
    ├── FiscalYearsForm.php
    ├── FiscalYearsViewer.php
    └── FiscalYearsProvisioner.php
```

Namespace: `Shipard\Module\Economy\Codebooks\*`.

## Task breakdown

### Krok 1: cfgItemy a tabulky

1. Vytvoř `modules/economy/codebooks/config/fiscalPeriodTypes.jsonc` (3 typy období, viz výše)
2. Vytvoř `modules/economy/codebooks/config/fiscalConfig.jsonc` (`yearStartMonth: 1`)
3. Vytvoř `modules/economy/codebooks/tables/economy_codebooks_fiscal_years.jsonc` se schématem výše. Standardní `docStates` blok jako v `base_persons_persons`. `columnGroups`: `identity`, `period`.
4. Vytvoř `modules/economy/codebooks/tables/economy_codebooks_fiscal_months.jsonc` se schématem výše (bez `docStates`, bez `columnGroups`).

### Krok 2: Document classes

`src/FiscalYearDocument.php` — `validate()`:

- `name`, `doc_number_prefix`, `date_begin`, `date_end`, `currency` jsou povinné
- `date_begin <= date_end` (vrátit error na sloupec `date_end` s kódem `'invalid_range'`, message „Konec období musí být později nebo stejný den jako začátek.")
- `currency` musí být přesně 3 znaky lowercase a-z (regex; nebudeme dělat lookup do `world.base.currencies` — to si necháme na v2 s currency pickerem)

Žádné `beforeSave` — `docState`/`docStateMain` zařizuje `CrudController::initDocState`/`processDocState`.

`src/FiscalMonthDocument.php` — `validate()` + `beforeSave()`:

- `validate`:
  - `fiscal_year`, `date_begin`, `date_end`, `period_type` povinné
  - `date_begin <= date_end`
  - `period_type` musí být platný klíč v cfgItem `economy.codebooks.fiscalPeriodTypes` (0, 1, 2)
- `beforeSave`:
  - Z `date_begin` (formát `YYYY-MM-DD`, normalizováno přes `DataSourceConnection`) odvoď `calendar_year` a `calendar_month` a dosaď do `$data` — vždy přepíše uživatelský vstup (pole jsou ve formu readOnly, ale defensivně)

Vzor: `modules/base/persons/src/PersonDocument.php` (struktura `validate` + `beforeSave`).

### Krok 3: FiscalYearsForm (TableForm subclass)

`src/FiscalYearsForm.php`:

- `buildFormDefinition($data, $isNew)`:
  - Tab `basic` „Obecné":
    ```
    [name 1c required, doc_number_prefix 1c required, currency 1c required, locked 1c]
    [date_begin 1c required, date_end 1c required]
    ```
    Použij `addInput` pro `name`, `doc_number_prefix`, `currency` (placeholder „czk"); `addCheckbox` pro `locked`; `addDate` pro `date_begin`/`date_end`.
  - Tab `months` „Měsíce":
    `addSubtable('economy_codebooks_fiscal_months', 'fiscal_year', formId: 'economy.codebooks.fiscal_months')`
  - **Default `currency` pro nový záznam**: pokud `$isNew && empty($data['currency'])`, vlož `$data['currency'] = 'czk'` před vrácením FormDefinition (totéž paterne jako default `unit = 'pcs'` v `ItemsForm`)
  - `fullSize: true`, `title: 'Fiskální rok'`, `titleNew: 'Nový fiskální rok'`

Žádný `recalculate` (v této fázi není potřeba).

Vzor: `modules/base/persons/src/PersonsForm.php` (struktura tabů, sub-table tab, default-pro-nový-záznam).

### Krok 4: JSONC sub-form pro fiscal_months

`modules/economy/codebooks/forms/economy_codebooks_fiscal_months.jsonc`:

```jsonc
{
    "title": "Fiskální měsíc",
    "titleNew": "Nový fiskální měsíc",
    "fullSize": false,

    "tabs": [
        {
            "id": "basic",
            "label": "Měsíc",
            "elements": [
                {"type": "input", "column": "date_begin", "cols": 1, "required": true},
                {"type": "input", "column": "date_end", "cols": 1, "required": true},
                {"type": "select", "column": "period_type", "cols": 1, "required": true},
                {"type": "input", "column": "calendar_year", "cols": 1, "readOnly": true},
                {"type": "input", "column": "calendar_month", "cols": 1, "readOnly": true}
            ]
        }
    ]
}
```

`calendar_year`/`calendar_month` se plní automaticky v `FiscalMonthDocument::beforeSave` z `date_begin`. Ve formu jsou jen pro orientaci — readOnly. Při novém záznamu jsou prázdné a doplní se po uložení.

Vzor: `modules/base/persons/forms/base_persons_bank_accounts.jsonc`.

### Krok 5: FiscalYearsViewer

`src/FiscalYearsViewer.php`:

- `protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';`
- `selectRows`: SELECT z `economy_codebooks_fiscal_years` se sloupci `id, name, doc_number_prefix, date_begin, date_end, currency, locked, docState, docStateMain`. viewGroup filter, search nad `name`. ORDER BY `docStateMain ASC, date_begin DESC` (aktivní nahoře, novější dříve).
- `renderRow`:
  - `t1` = `name`
  - `i1` = `doc_number_prefix`
  - `t2` = pole prvků: `["{date_begin} – {date_end}"]` + `[currency uppercase]` + (pokud `locked`: badge `["text" => "Uzamčeno", "class" => "warning"]`) + (badge stavu pokud `docState !== 10`)
  - `stateStyle` = z cfgItem
- `renderDetail`:
  - Tab `overview`: properties — `name`, `doc_number_prefix`, `date_begin`, `date_end`, `currency` (uppercase), `locked` (Ano/Ne)
  - Tab `months`: tabulka měsíců (`SELECT date_begin, date_end, period_type, calendar_year, calendar_month FROM economy_codebooks_fiscal_months WHERE fiscal_year = %i ORDER BY date_begin`); `period_type` přeložit přes cfgItem na text
- `getToolbarActions`: standardní `create` + `edit` (jako PersonsViewer)

Vzor: `modules/base/persons/src/PersonsViewer.php` (kompletní pattern).

### Krok 6: FiscalYearsProvisioner

`src/FiscalYearsProvisioner.php` — analogicky `ItemKindsProvisioner` a `UnitsProvisioner`. Konstruktor: `DataSourceConnection $db, ConfigRuntime $config`.

`provision(): array` logika:

```
1. Načti yearStartMonth z $config->cfgItem('economy.codebooks.fiscalConfig')['yearStartMonth'] ?? 1
2. Spočti rozsah aktuálního fiskálního roku (viz pseudokod níže)
3. Existuje-li v DB fiskální rok pokrývající 'now' (lookup přes WHERE date_begin <= today AND date_end >= today)?
   - NE → vygeneruj aktuální rok + 14 měsíců
   - ANO → spočti rozsah příštího fiskálního roku; existuje-li v DB? Ne → vygeneruj příští rok + 14 měsíců; Ano → no-op
4. Vrať ['fiscalYears' => ['created' => N, 'existing' => M]]
```

**Pseudokod pro výpočet rozsahu fiskálního roku** (vstup: `yearStartMonth`, `referenceDate`):

```php
$refYear = (int) $referenceDate->format('Y');
$refMonth = (int) $referenceDate->format('n');

if ($refMonth >= $yearStartMonth) {
    $fyStartYear = $refYear;
} else {
    $fyStartYear = $refYear - 1;
}

$dateBegin = new DateTimeImmutable(sprintf('%04d-%02d-01', $fyStartYear, $yearStartMonth));
$dateEnd = $dateBegin->modify('+1 year -1 day');

if ($yearStartMonth === 1) {
    $name = (string) $fyStartYear;            // "2026"
    $prefix = substr((string) $fyStartYear, -2); // "26"
} else {
    $fyEndYear = $fyStartYear + 1;
    $name = sprintf('%d-%d', $fyStartYear, $fyEndYear); // "2026-2027"
    $prefix = substr((string) $fyEndYear, -2);          // "27"
}
```

**Generování záznamů** (po určení, který rok generovat):

```
1. INSERT do economy_codebooks_fiscal_years:
   {name, doc_number_prefix, date_begin, date_end, currency: 'czk', locked: 0, docState: 40, docStateMain: 3}
   (insertRow vrátí real auto-increment ID — viz user-memories pattern)
2. INSERT 14× economy_codebooks_fiscal_months:
   - Otevření: fiscal_year=ID, date_begin=date_end=year.date_begin, period_type=0,
     calendar_year=year(year.date_begin), calendar_month=month(year.date_begin)
   - 12× Běžné: pro i=0..11:
     monthBegin = year.date_begin->modify("+{i} month")
     monthEnd = monthBegin->modify("+1 month -1 day")
     fiscal_year=ID, date_begin=monthBegin, date_end=monthEnd, period_type=1,
     calendar_year=year(monthBegin), calendar_month=month(monthBegin)
   - Uzavření: fiscal_year=ID, date_begin=date_end=year.date_end, period_type=2,
     calendar_year=year(year.date_end), calendar_month=month(year.date_end)
```

**Idempotence**: lookup před insertem — `SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin <= today AND date_end >= today` (resp. pro příští rok lookup analogicky podle vypočítaného `dateBegin`). Pokud existuje → skip.

**Ošetření**: vše v jedné DB transakci — pokud cokoliv selže, rollback.

### Krok 7: Hook do `DsUpgradeCommand`

V `src/Command/DataSource/DsUpgradeCommand.php`:

1. Přidej v `execute()` po `provisionItemKinds(...)`:
   ```php
   $this->provisionFiscalYears($resolvedModules, $dsConnection, $output);
   ```
2. Přidej importy:
   ```php
   use Shipard\Core\Config\ConfigRuntime;
   use Shipard\Module\Economy\Codebooks\FiscalYearsProvisioner;
   ```
3. Přidej private metodu `provisionFiscalYears` analogicky `provisionItemKinds`:
   - Pokud `economy.codebooks` není aktivní modul → `[SKIP] economy.codebooks module not active`
   - Načti `ConfigRuntime` z `$dsDir . '/config/configuration/'` (jazyk `cs`); pokud compiled config ještě neexistuje → `[SKIP] config not compiled yet` (nemělo by nastat, kompilace je krok 5 v `execute`, ale defensive)
   - Instancuj `FiscalYearsProvisioner($dsConnection, $config)`, zavolej `provision()`
   - Vypiš `[OK] fiscal years — created: N, existing: M`

Vzor: `provisionItemKinds` ve stejném souboru.

### Krok 8: module.jsonc rozšíření

Aktuální `modules/economy/codebooks/module.jsonc` má jen `dependencies: ["core.system"]` a 2 tabulky. Rozšiř na:

```jsonc
{
    "id": "economy.codebooks",
    "name": "Codebooks",
    "name:cs": "Číselníky",
    "name:en": "Codebooks",
    "description": "Warehouses, cost centers, fiscal periods and other codebooks",
    "description:cs": "Sklady, střediska, fiskální období a další číselníky",
    "description:en": "Warehouses, cost centers, fiscal periods and other codebooks",

    "dependencies": ["core.system", "world.base"],

    "tables": [
        "economy_codebooks_warehouses",
        "economy_codebooks_cost_centers",
        "economy_codebooks_fiscal_years",
        "economy_codebooks_fiscal_months"
    ],

    "viewers": [
        {
            "id": "economy.codebooks.fiscalYears",
            "name": "Fiscal periods",
            "name:cs": "Fiskální období",
            "name:en": "Fiscal periods",
            "icon": "calendar",
            "table": "economy_codebooks_fiscal_years",
            "class": "Shipard\\Module\\Economy\\Codebooks\\FiscalYearsViewer"
        }
    ],

    "forms": [
        {
            "table": "economy_codebooks_fiscal_years",
            "class": "Shipard\\Module\\Economy\\Codebooks\\FiscalYearsForm"
        },
        {
            "table": "economy_codebooks_fiscal_months",
            "id": "economy.codebooks.fiscal_months"
        }
    ],

    "documentClasses": [
        {
            "table": "economy_codebooks_fiscal_years",
            "class": "Shipard\\Module\\Economy\\Codebooks\\FiscalYearDocument"
        },
        {
            "table": "economy_codebooks_fiscal_months",
            "class": "Shipard\\Module\\Economy\\Codebooks\\FiscalMonthDocument"
        }
    ],

    "config": [
        {
            "id": "economy.codebooks.fiscalPeriodTypes",
            "file": "config/fiscalPeriodTypes.jsonc"
        },
        {
            "id": "economy.codebooks.fiscalConfig",
            "file": "config/fiscalConfig.jsonc"
        }
    ]
}
```

### Krok 9: Frontend — ikona

V `frontend/src/icons.js` přidej `faCalendarDays` do importů, expose jako `iconCalendar` a v `iconMap` jako `'calendar': iconCalendar`. Spusť `npm run build` v `frontend/`.

### Krok 10: install.base aktualizace

V `modules/install/base/module.jsonc` přidej `"economy.codebooks"` do `dependencies` (pokud tam ještě není — může tam už být kvůli `economy.items`, takže ji možná přidává transitivně; ověř a případně doplň).

### Krok 11: Testy

`tests/Unit/Module/Economy/Codebooks/`:

**`FiscalYearDocumentTest.php`** — pokrýt:
- chybějící `name` → error
- chybějící `date_begin`/`date_end`/`currency`/`doc_number_prefix` → error per pole
- `date_begin > date_end` → error na `date_end` s kódem `invalid_range`
- `currency` jiný než 3 znaky lowercase → error
- všechno OK → valid

**`FiscalMonthDocumentTest.php`** — pokrýt:
- chybějící povinná pole → error
- `date_begin > date_end` → error
- neplatný `period_type` (např. 5) → error
- `beforeSave` s `date_begin = '2026-03-15'` → `calendar_year = 2026`, `calendar_month = 3`
- `beforeSave` přepíše uživatelem zadané `calendar_year`/`calendar_month`, pokud se neshoduje s `date_begin`

**`FiscalYearsProvisionerTest.php`** — pokrýt logiku výpočtu rozsahu, ne nutně skutečné inserty (mock `DataSourceConnection`):
- Empty DS, `yearStartMonth=1`, refDate=`2026-04-15` → vygeneruje rok s `date_begin=2026-01-01`, `date_end=2026-12-31`, `name="2026"`, `doc_number_prefix="26"`, 14 měsíců
- Empty DS, `yearStartMonth=7`, refDate=`2026-09-15` → vygeneruje rok s `date_begin=2026-07-01`, `date_end=2027-06-30`, `name="2026-2027"`, `doc_number_prefix="27"`
- Empty DS, `yearStartMonth=7`, refDate=`2026-03-15` → vygeneruje rok s `date_begin=2025-07-01`, `date_end=2026-06-30`, `name="2025-2026"`, `doc_number_prefix="26"`
- Existuje aktuální rok → vygeneruje pouze následující
- Existují oba (aktuální i příští) → no-op (`created: 0, existing: 1` nebo `2`)
- Generovaný rok má `docState=40, docStateMain=3` (V pořádku)
- Měsíce: 1× period_type=0 (jednodenní), 12× period_type=1, 1× period_type=2 (jednodenní)

Vzor: `tests/Unit/Module/Base/Persons/PersonDocumentTest.php` pro Documents; `tests/Unit/Command/DataSource/DsUpgradeCommandTest.php` (pokud existuje) nebo jiný provisioner test pro mock pattern.

**Lokálně ověř na testovacím DS**:
1. `bin/shpd-ds ds-upgrade` — měl by vytvořit aktuální rok + 14 měsíců, output `fiscal years — created: 1, existing: 0`
2. Druhý běh — output `created: 0, existing: 1`
3. Otevři viewer **Fiskální období** v UI — vidíš jeden rok s `docState = V pořádku`, ve formu jsou v záložce Měsíce všech 14 záznamů
4. Vytvoř manuálně nový rok — vznikne jako `Koncept`, můžeš ho přepnout na V pořádku tlačítkem
5. Sub-form pro nový měsíc — vyplň `date_begin`, ulož, ověř že se `calendar_year`/`calendar_month` doplnily

### Krok 12: Documentation

**`modules/economy/codebooks/README.md`** (NOVÝ):
- Přehled modulu (aktuálně 4 tabulky: warehouses, cost_centers, fiscal_years, fiscal_months; první dvě jsou placeholdery, tato fáze řeší fiscal_*)
- Závislosti
- Tabulky (krátký popis každé)
- Sekce „Auto-generování fiskálních období" s vysvětlením `FiscalYearsProvisioner` chování (kdy se rok vygeneruje, kdy ne, výchozí `yearStartMonth = 1`)
- Sekce „Typy fiskálních měsíců" — vysvětlení Otevření/Běžné/Uzavření a proč jsou Otevření/Uzavření jednodenní (počáteční/závěrkové operace v účetnictví)

**`modules/economy/codebooks/tables/economy_codebooks_fiscal_years.md`** — sloupce per skupina, význam `locked`, význam `currency` (přechod CZK→EUR), poznámka že období může být i jiné než kalendářní rok.

**`modules/economy/codebooks/tables/economy_codebooks_fiscal_months.md`** — sloupce, denormalizace `calendar_year`/`calendar_month` z `date_begin` v `beforeSave`, jednodenní Otevření/Uzavření.

## Rozhodnutí k designu (potvrzená)

✓ **Modul `economy.codebooks`** — fiskální období patří mezi ekonomické číselníky vedle skladů a středisek

✓ **TableId 313 (fiscal_years), 314 (fiscal_months)** — navazují na `economy.items` 311/312

✓ **`fiscal_years` má `docStates`, `fiscal_months` ne** — měsíce dědí lifecycle přes rodiče. Při smazání roku se měsíce vyřeší později (pokud vůbec — Smazáno je jen stav, ne fyzické DELETE)

✓ **14 záznamů na rok** — 1× Otevření (jednodenní = `date_begin == date_end == year.date_begin`), 12× Běžné, 1× Uzavření (jednodenní = `date_begin == date_end == year.date_end`)

✓ **Default `currency = 'czk'`** napevno v provisioneru i ve formu — currency picker přijde později jako globální vylepšení

✓ **`yearStartMonth` jako modulový cfgItem** (`economy.codebooks.fiscalConfig`), default 1. Per-DS override zatím ne (mechanismus neexistuje); přijde, až bude potřeba

✓ **Provisioner generuje rok jako `V pořádku` (docState=40, docStateMain=3)**, manuálně přes UI vznikají roky jako `Koncept` (10) — `CrudController::initDocState` to dělá automaticky

✓ **Manuální editace měsíců povolena** — uživatel může přidat/upravit/smazat měsíc přes sub-form. `calendar_year`/`calendar_month` se plní automaticky v `beforeSave` z `date_begin`

✓ **Validace `date_begin <= date_end`** v Document; cross-record validace (překryvy mezi roky/měsíci, mezery) zatím ne

✓ **Validace `locked = true` pro doklady** — odložena, vyřeší se s dokladovým systémem

✓ **Název roku**: `"YYYY"` při `yearStartMonth=1`, `"YYYY-YYYY"` (rok začátku — rok konce) jinak

✓ **`doc_number_prefix`**: oddělené pole, default poslední 2 číslice roku konce (`"27"` pro `"2026-2027"`)

✓ **Závislost `economy.codebooks` na `world.base`** přidána pro budoucí currency picker (zatím nevyužitá ve form, ale konzistentní)

✓ **Žádný attachmentsTab ve `FiscalYearsForm`** — k fiskálnímu roku nedávají přílohy smysl

## Hotovo když

- [ ] `bin/shpd-ds ds-upgrade` na DS bez fiskálních roků vytvoří 1 rok + 14 měsíců, output `fiscal years — created: 1, existing: 0`
- [ ] Druhý běh `ds-upgrade` je no-op (`created: 0, existing: 1`)
- [ ] Po přepnutí systémového data o rok dopředu (lokální test) další `ds-upgrade` vygeneruje příští rok
- [ ] Provisioner-vytvořený rok má `docState = 40` (V pořádku), `docStateMain = 3`
- [ ] Měsíce mají správně `period_type` (0/1/2) a denormalizované `calendar_year`/`calendar_month`
- [ ] Otevření a Uzavření jsou jednodenní (`date_begin == date_end`)
- [ ] V navigaci se objeví **Fiskální období** s ikonou kalendáře
- [ ] Uživatel může v UI vytvořit nový rok — vzniká jako Koncept (10), pak ho lze přepnout do V pořádku
- [ ] V editačním formuláři roku jsou v záložce Měsíce všechny měsíce, lze je upravit přes sub-form
- [ ] Při uložení měsíce přes sub-form se `calendar_year`/`calendar_month` automaticky doplní z `date_begin`
- [ ] Validace `date_begin > date_end` ve formu (rok i měsíc) → 422 chyba
- [ ] PHPUnit testy prochází: `vendor/bin/phpunit tests/Unit/Module/Economy/Codebooks`
- [ ] Frontend build prochází bez chyb po přidání `iconCalendar`
- [ ] Documentation napsaná: `README.md` modulu + `.md` per nová tabulka

## Konvence a upozornění

- **Jazyk**: UI texty čeština, kód a komentáře angličtina
- **Vícejazyčnost**: každé `name` v JSONC musí mít `:cs` a `:en` variantu
- **PHP 8.5** strict_types, readonly properties kde možné
- **`Dibi\DateTime` normalizace** už řeší `DataSourceConnection::fetchRow/fetchAll` — `$data['date_begin']` přijde jako `"YYYY-MM-DD"` string, ne DateTime objekt; pro výpočty v `beforeSave` použij `new \DateTimeImmutable($data['date_begin'])`
- **`insertRow()`** pro provisioner — vrací real auto-increment ID, použij ho pro získání `fiscal_year` ID před vkládáním měsíců (viz `MailRouterProvisioner` pro pattern)
- **Transakce v provisioneru** — celé generování roku + měsíců v jedné transakci přes `$db->transaction()` (pokud existuje) nebo manuálně přes `BEGIN`/`COMMIT`/`ROLLBACK`; ověř existující pattern v jiných provisionerech
- **Composer autoload** — po vytvoření nové src složky `composer dump-autoload`
- **Po každém kroku ověř** na testovacím DS, ať se chyby nehromadí

## Doporučené pořadí implementace

Krok 1 (cfgItemy + tabulky) → Krok 2 (Documenty) → Krok 8 (module.jsonc — aby `ds-upgrade` viděl tabulky) → ověřit `ds-upgrade` (vytvoří prázdné tabulky) → Krok 3 + 4 + 5 (Form, JSONC sub-form, Viewer) → ověřit UI ručně → Krok 6 (Provisioner) → Krok 7 (hook) → ověřit `ds-upgrade` (vygeneruje rok) → Krok 9 (frontend ikona) → Krok 10 (install.base) → Krok 11 (testy) → Krok 12 (docs).
