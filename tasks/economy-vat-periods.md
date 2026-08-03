# Task: Období DPH — registrace a období

**Stav:** hotovo

## Kontext

Implementujeme dvě nové tabulky v existujícím modulu `economy.codebooks` jako poslední číselník před spuštěním práce na dokladech:

- **`economy_codebooks_vat_registrations`** — seznam registrací k DPH se stavy dokumentů (obvykle 0 nebo 1, ale modeluje i diskontinuity a vícenásobná DIČ pro EU OSS apod.)
- **`economy_codebooks_vat_periods`** — jednotlivá období DPH (měsíční/čtvrtletní), navázaná na konkrétní registraci

Až se začne dělat dokladový systém, hlavička dokladu bude obsahovat referenci na konkrétní registraci, a podle data uskutečnění zdanitelného plnění „spadne" do konkrétního období DPH. Přiznání DPH se sestavují pro každou registraci zvlášť.

Před implementací **přečti**:

- `docs/modules.md` — modulový systém, JSONC, i18n
- `docs/table-definitions.md` — formát definice tabulek
- `docs/edit-forms.md` — editační formuláře (`TableForm`, `TabBuilder`, sub-tabulky)
- `docs/doc-states.md` — stavy dokumentů, `docStatesArchive`
- `docs/frontend.md` — viewers, sidebar, ikony
- `docs/documentation.md` — formát README a `tables/*.md`

Vzorové existující implementace **přímo v tomto modulu** k nastudování (analogická struktura — provisioner volaný z `ds-upgrade`, sub-form pro child tabulku):

- `modules/economy/codebooks/src/FiscalYearsForm.php` — vzor `TableForm` se 2 taby a sub-tabulkou
- `modules/economy/codebooks/src/FiscalYearsViewer.php` — vzor vieweru s detail tabem zobrazujícím child tabulku
- `modules/economy/codebooks/src/FiscalYearsProvisioner.php` — vzor idempotentního provisioneru s `$db->begin()/commit()/rollback()`, `insertRow()` pattern
- `modules/economy/codebooks/src/FiscalYearDocument.php`, `FiscalMonthDocument.php` — vzory Document tříd s `validate()`
- `modules/economy/codebooks/forms/economy_codebooks_fiscal_months.jsonc` — vzor deklarativního JSONC sub-formu

## Cíl

Po dokončení této fáze platí:

- Modul `economy.codebooks` má novou položku v navigaci **Registrace DPH** s vlastním viewerem
- Uživatel může přes UI vytvořit registraci DPH (vzniká jako `Koncept` (10), pak ji manuálně přepne do `V pořádku` (40))
- V editačním formuláři registrace je v záložce **Období DPH** sub-tabulka s období; lze je editovat (přidat/upravit/smazat) přes JSONC sub-formulář
- `bin/shpd-ds ds-upgrade` projde aktivní (`docState IN (10, 40, 80)`) registrace a pro každou doplní chybějící období v aktuálním + příštím kalendářním roce, omezené `valid_from`/`valid_to` registrace
- Generátor respektuje `tax_period_kind` (měsíční / čtvrtletní) a názvy období generuje ve formátu `"MM/YYYY"` resp. `"QN/YYYY"`
- Validace v `VatRegistrationDocument` a `VatPeriodDocument` zachytí základní nesmysly (neplatný `region`/`country` proti cfgItem, `date_begin > date_end`, prázdné povinné pole)

## Návaznost

- Závisí na: `core.system`, `world.base` (cfgItem countries — už je v deps `economy.codebooks` z fiscal periods fáze), `world.trade` (cfgItem unions — **nově přidat**), funkční edit-forms, doc-states
- Otevírá: dokladový systém (faktury, objednávky), kde každý doklad podle data zdanitelného plnění najde svou registraci a období DPH; přiznání DPH a kontrolní hlášení per registrace

## Scope

### V rozsahu

- Tabulky `economy_codebooks_vat_registrations` (tableId **315**) a `economy_codebooks_vat_periods` (tableId **316**)
- Standardní `core.system.docStatesArchive` stavy pro obě tabulky
- PHP třídy: `VatRegistrationDocument`, `VatPeriodDocument`, `VatRegistrationsForm` (TableForm subclass), `VatRegistrationsViewer`, `VatPeriodsProvisioner`
- Deklarativní JSONC sub-form pro `economy_codebooks_vat_periods`
- cfgItemy: `economy.codebooks.vatTaxpayerKinds` (druhy plátce), `economy.codebooks.vatPeriodKinds` (frekvence — sdíleno mezi `tax_period_kind` a `report_period_kind`)
- Provisioner volaný z `DsUpgradeCommand` po `provisionFiscalYears`, idempotentní (lookup ignoruje `docState`)
- Závislost `economy.codebooks` rozšířená o `world.trade`
- Frontend ikona: `iconVat` (`faPercent`)
- Documentation: rozšíření existujícího `README.md` modulu o sekci VAT, `tables/economy_codebooks_vat_registrations.md`, `tables/economy_codebooks_vat_periods.md`

### Mimo rozsah (odložené)

- Validace `locked = true` při vytváření/editaci dokladů (přijde s dokladovým systémem)
- Samostatný viewer pro **Období DPH** — primárně přes sub-tabulku v Registraci; samostatný viewer přijde s reporty
- Auto-přegenerování období při změně `tax_period_kind` u existující registrace — uživatel si to ošetří sám (manuálně smaže nesedící, další `ds-upgrade` doplní chybějící podle aktuální frekvence)
- Auto-úprava existujících období při změně `valid_from`/`valid_to` registrace — provisioner do existujících záznamů nesahá
- Validace formátu `vat_id` (jen non-empty pokud zadáno)
- Per-DS locale-aware default `country` (zatím natvrdo `'cz'`)
- Cross-record validace (překryvy období v rámci jedné registrace, mezery)

## Datový model

### Nový: `economy_codebooks_vat_registrations` (tableId 315)

`docStates`: `core.system.docStatesArchive`. `displayPattern`: `"{name}"`.

**Skupina `identity`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `id` | int PK autoIncrement | |
| `name` | varchar(50) NOT NULL | "ČR DPH", "SK DPH OSS" |
| `region` | enumString(10) NOT NULL default `'eu'` | `cfgItem: "world.trade.unions"`, klíč lowercase |
| `country` | enumString(2) NOT NULL default `'cz'` | `cfgItem: "world.base.countries"`, ISO alpha-2 lowercase |
| `taxpayer_kind` | enumInt NOT NULL default 0 | `cfgItem: "economy.codebooks.vatTaxpayerKinds"` (0=Klasický plátce, 1=OSS) |
| `vat_id` | varchar(30) NULL | DIČ pro DPH; necháváme nullable kvůli stavu „v procesu registrace" |

**Skupina `period`:**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `tax_period_kind` | enumInt NOT NULL default 1 | `cfgItem: "economy.codebooks.vatPeriodKinds"` (1=měsíční, 2=čtvrtletní) — frekvence přiznání DPH |
| `report_period_kind` | enumInt NOT NULL default 1 | stejný cfgItem — frekvence kontrolního hlášení |
| `valid_from` | date NOT NULL | |
| `valid_to` | date NULL | NULL = bez konce platnosti |

**Systémové (bez group):**

| Sloupec | Typ | Pozn. |
|---|---|---|
| `docState` | tinyint default 10 | system: true |
| `docStateMain` | tinyint default 1 | system: true |

Indexy:

- `idx_country` na `country`
- `idx_validity` na `valid_from`, `valid_to`
- `idx_doc_state` na `docStateMain ASC`, `name ASC`

### Nový: `economy_codebooks_vat_periods` (tableId 316)

`docStates`: `core.system.docStatesArchive`. `displayPattern`: `"{name}"`.

| Sloupec | Typ | Pozn. |
|---|---|---|
| `id` | int PK autoIncrement | |
| `vat_registration` | int NOT NULL | `reference: "economy_codebooks_vat_registrations"` |
| `name` | varchar(20) NOT NULL | `"01/2026"` měsíční / `"Q1/2026"` čtvrtletní |
| `date_begin` | date NOT NULL | |
| `date_end` | date NOT NULL | |
| `locked` | boolean NOT NULL default 0 | true = doklady v tomto období nelze editovat |
| `docState` | tinyint default 10 | system: true |
| `docStateMain` | tinyint default 1 | system: true |

Indexy:

- `idx_vat_registration` na `vat_registration`, `date_begin`
- `idx_dates` na `date_begin`, `date_end` (pro budoucí lookup dle data zdanitelného plnění)
- `idx_doc_state` na `docStateMain ASC`, `date_begin DESC`

### Nový cfgItem: `economy.codebooks.vatTaxpayerKinds`

Soubor `modules/economy/codebooks/config/vatTaxpayerKinds.jsonc`:

```jsonc
{
    // Hodnoty odpovídají enumInt taxpayer_kind v vat_registrations
    "0": {
        "name": "Standard taxpayer",
        "name:cs": "Klasický plátce",
        "name:en": "Standard taxpayer"
    },
    "1": {
        "name": "OSS",
        "name:cs": "OSS",
        "name:en": "OSS"
    }
}
```

### Nový cfgItem: `economy.codebooks.vatPeriodKinds`

Soubor `modules/economy/codebooks/config/vatPeriodKinds.jsonc` — sdíleno pro `tax_period_kind` i `report_period_kind`:

```jsonc
{
    // Hodnoty odpovídají enumInt tax_period_kind/report_period_kind
    // Pozor: hodnota 0 se nepoužívá (zachováno pro budoucnost)
    "1": {
        "name": "Monthly",
        "name:cs": "Měsíční",
        "name:en": "Monthly"
    },
    "2": {
        "name": "Quarterly",
        "name:cs": "Čtvrtletní",
        "name:en": "Quarterly"
    }
}
```

## Adresářová struktura

Modul `economy.codebooks` je už zaplněný — přidáváme do něj:

```
modules/economy/codebooks/
├── module.jsonc                  # ROZŠÍŘIT — nové tables, forms, viewers, config, dep
├── README.md                     # ROZŠÍŘIT — sekce o VAT registrations + periods
├── config/
│   ├── fiscalConfig.jsonc        # beze změny
│   ├── fiscalPeriodTypes.jsonc   # beze změny
│   ├── vatTaxpayerKinds.jsonc    # NOVÝ
│   └── vatPeriodKinds.jsonc      # NOVÝ
├── forms/
│   ├── economy_codebooks_fiscal_months.jsonc   # beze změny
│   └── economy_codebooks_vat_periods.jsonc     # NOVÝ
├── tables/
│   ├── economy_codebooks_warehouses.jsonc      # beze změny
│   ├── economy_codebooks_cost_centers.jsonc    # beze změny
│   ├── economy_codebooks_fiscal_years.jsonc    # beze změny
│   ├── economy_codebooks_fiscal_years.md       # beze změny
│   ├── economy_codebooks_fiscal_months.jsonc   # beze změny
│   ├── economy_codebooks_fiscal_months.md      # beze změny
│   ├── economy_codebooks_vat_registrations.jsonc   # NOVÝ
│   ├── economy_codebooks_vat_registrations.md      # NOVÝ
│   ├── economy_codebooks_vat_periods.jsonc         # NOVÝ
│   └── economy_codebooks_vat_periods.md            # NOVÝ
└── src/
    ├── FiscalYearDocument.php       # beze změny
    ├── FiscalMonthDocument.php      # beze změny
    ├── FiscalYearsForm.php          # beze změny
    ├── FiscalYearsViewer.php        # beze změny
    ├── FiscalYearsProvisioner.php   # beze změny
    ├── VatRegistrationDocument.php  # NOVÝ
    ├── VatPeriodDocument.php        # NOVÝ
    ├── VatRegistrationsForm.php     # NOVÝ
    ├── VatRegistrationsViewer.php   # NOVÝ
    └── VatPeriodsProvisioner.php    # NOVÝ
```

Namespace: `Shipard\Module\Economy\Codebooks\*` (sdílený s fiscal classes).

## Task breakdown

### Krok 1: cfgItemy a tabulky

1. Vytvoř `modules/economy/codebooks/config/vatTaxpayerKinds.jsonc` (2 typy plátce, viz výše)
2. Vytvoř `modules/economy/codebooks/config/vatPeriodKinds.jsonc` (2 frekvence, viz výše — pozor, klíče `"1"` a `"2"`, ne `"0"` a `"1"`)
3. Vytvoř `modules/economy/codebooks/tables/economy_codebooks_vat_registrations.jsonc` se schématem výše. Standardní `docStates` blok jako v `base_persons_persons`. `columnGroups`: `identity`, `period`. `enumString` pro `region` (length 10) a `country` (length 2).
4. Vytvoř `modules/economy/codebooks/tables/economy_codebooks_vat_periods.jsonc` se schématem výše. Standardní `docStates` blok.

### Krok 2: Document classes

`src/VatRegistrationDocument.php` — `validate()`:

- `name`, `region`, `country`, `taxpayer_kind`, `tax_period_kind`, `report_period_kind`, `valid_from` jsou povinné
- `region` musí být platný klíč v cfgItem `world.trade.unions` (lookup přes `$this->config->cfgItem(...)`)
- `country` musí být platný klíč v cfgItem `world.base.countries`
- `taxpayer_kind` musí být platný klíč v cfgItem `economy.codebooks.vatTaxpayerKinds` (0 nebo 1)
- `tax_period_kind` a `report_period_kind` musí být platné klíče v cfgItem `economy.codebooks.vatPeriodKinds` (1 nebo 2)
- `valid_to` pokud zadané, musí být `>= valid_from` (error na sloupec `valid_to` s kódem `'invalid_range'`, message „Konec platnosti musí být později nebo stejný den jako začátek.")
- `vat_id` zůstává bez validace (jen non-empty pokud uživatel zadá — ale tu hlídá normální DB constraint, takže nic explicitně neděláme)

Žádné `beforeSave` — `docState`/`docStateMain` zařizuje `CrudController`.

`src/VatPeriodDocument.php` — `validate()`:

- `vat_registration`, `name`, `date_begin`, `date_end` povinné
- `date_begin <= date_end` (error na `date_end` s kódem `invalid_range`)
- Žádná validace `name` formátu (uživatel může mít speciální období „Likvidace 2027" apod.)

Vzor: `modules/economy/codebooks/src/FiscalYearDocument.php` (struktura `validate`).

### Krok 3: VatRegistrationsForm (TableForm subclass)

`src/VatRegistrationsForm.php`:

- `buildFormDefinition($data, $isNew)`:
  - **Defaults pro nový záznam**: pokud `$isNew`:
    - `region` → `'eu'` pokud prázdné
    - `country` → `'cz'` pokud prázdné
    - `taxpayer_kind` → `0` pokud null/prázdné
    - `tax_period_kind` → `1` pokud null/prázdné
    - `report_period_kind` → `1` pokud null/prázdné
  - Tab `basic` „Obecné":
    ```
    [name 2c required, vat_id 2c]
    [region 1c select required, country 1c select required, taxpayer_kind 1c select required]
    --- separator "Periodicita" ---
    [tax_period_kind 1c select required, report_period_kind 1c select required]
    --- separator "Platnost" ---
    [valid_from 1c date required, valid_to 1c date]
    ```
  - Tab `periods` „Období DPH":
    `addSubtable('economy_codebooks_vat_periods', 'vat_registration', formId: 'economy.codebooks.vat_periods', sort: 'date_begin:asc')`
  - **Options pro `region`**: dynamicky z cfgItem `world.trade.unions`. Klíče jsou lowercase identifikátory unie (`'eu'`, `'gcc'`); label = `entry['name']`. Pattern stejný jako `PersonsForm::resolvePersonTypeOptions`.
  - **Options pro `country`**: dynamicky z cfgItem `world.base.countries`. Klíče jsou lowercase ISO alpha-2 (`'cz'`, `'sk'`, …); label = `entry['name']`. Seznam je dlouhý (cca 200 zemí) — pattern je stejný, jen sorted by label asc pro UX.
  - **Options pro `taxpayer_kind`**, `tax_period_kind`, `report_period_kind`: z cfgItem `economy.codebooks.vatTaxpayerKinds` resp. `economy.codebooks.vatPeriodKinds`. Hodnoty jsou intové.
  - `fullSize: true`, `title: 'Registrace DPH'`, `titleNew: 'Nová registrace DPH'`

Žádný `recalculate` (v této fázi není potřeba).

Vzor: `modules/economy/codebooks/src/FiscalYearsForm.php` (struktura tabů, sub-table tab, default-pro-nový-záznam) + `modules/base/persons/src/PersonsForm.php` (resolvePersonTypeOptions pro options z cfgItem).

### Krok 4: JSONC sub-form pro vat_periods

`modules/economy/codebooks/forms/economy_codebooks_vat_periods.jsonc`:

```jsonc
{
    "title": "Období DPH",
    "titleNew": "Nové období DPH",
    "fullSize": false,

    "tabs": [
        {
            "id": "basic",
            "label": "Období",
            "elements": [
                {"type": "input", "column": "name", "cols": 2, "required": true},
                {"type": "input", "column": "date_begin", "cols": 1, "required": true},
                {"type": "input", "column": "date_end", "cols": 1, "required": true},
                {"type": "input", "column": "locked", "cols": 1}
            ]
        }
    ]
}
```

Vzor: `modules/economy/codebooks/forms/economy_codebooks_fiscal_months.jsonc`.

### Krok 5: VatRegistrationsViewer

`src/VatRegistrationsViewer.php`:

- `protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';`
- Konstanta `STATE_SPAN_CLASS` jako v `FiscalYearsViewer`
- `selectRows`: SELECT z `economy_codebooks_vat_registrations` se sloupci `id, name, region, country, taxpayer_kind, vat_id, tax_period_kind, valid_from, valid_to, docState, docStateMain`. viewGroup filter, search nad `name` a `vat_id`. ORDER BY `docStateMain ASC, valid_from DESC, id ASC`.
- `renderRow`:
  - `t1` = `name`
  - `i1` = `vat_id` (může být null)
  - `t2` = pole prvků:
    - `{country uppercase}` jako text
    - label `taxpayer_kind` z cfgItem
    - `"{valid_from} – {valid_to}"` (pokud valid_to null, zobraz jen `"od {valid_from}"`)
    - badge `Měsíční`/`Čtvrtletní` (label `tax_period_kind` z cfgItem) s class `'muted'`
    - badge stavu pokud `docState !== 10`
  - `stateStyle` = z cfgItem
- `renderDetail`:
  - Tab `overview` „Přehled" — properties ve dvou groups:
    - **Identifikace**: `name`, `region` (label z cfgItem), `country` (uppercase + label z cfgItem v závorce), `vat_id`, `taxpayer_kind` (label)
    - **Periodicita a platnost**: `tax_period_kind` (label), `report_period_kind` (label), `valid_from`, `valid_to` (nebo „bez konce")
  - Tab `periods` „Období DPH" — tabulka období (`SELECT name, date_begin, date_end, locked FROM economy_codebooks_vat_periods WHERE vat_registration = %i ORDER BY date_begin ASC, id ASC`); `locked` jako Ano/Ne
- `getToolbarActions`: standardní `create` + `edit`

Helpery `formatDate`, `addItem`, `resolveCountryLabels`, `resolveRegionLabels`, `resolveTaxpayerKindLabels`, `resolvePeriodKindLabels` — analogicky k `FiscalYearsViewer::resolvePeriodTypeLabels`.

Vzor: `modules/economy/codebooks/src/FiscalYearsViewer.php` (kompletní struktura).

### Krok 6: VatPeriodsProvisioner

`src/VatPeriodsProvisioner.php` — analogicky `FiscalYearsProvisioner`. Konstruktor: `DataSourceConnection $db` (bez ConfigRuntime — generátor čte vše z DB).

`provision(?\DateTimeImmutable $referenceDate = null): array` logika:

```
$referenceDate ??= new \DateTimeImmutable('today');
$currentYear = (int) $referenceDate->format('Y');
$years = [$currentYear, $currentYear + 1];

$registrations = SELECT * FROM economy_codebooks_vat_registrations
                 WHERE docState IN (10, 40, 80)
                 ORDER BY id

$created = 0;
$existing = 0;

foreach ($registrations as $reg):
  $validFrom = new \DateTimeImmutable($reg['valid_from']);
  $validTo = $reg['valid_to'] ? new \DateTimeImmutable($reg['valid_to']) : null;

  $this->db->begin();
  try:
    foreach ($years as $year):
      $genResult = $this->generatePeriodsForYear(
        regId: $reg['id'],
        kind: (int) $reg['tax_period_kind'],
        year: $year,
        validFrom: $validFrom,
        validTo: $validTo,
      );
      $created += $genResult['created'];
      $existing += $genResult['existing'];
    $this->db->commit();
  catch (\Throwable $e):
    $this->db->rollback();
    throw $e;

return ['vatPeriods' => ['created' => $created, 'existing' => $existing]];
```

`generatePeriodsForYear(int $regId, int $kind, int $year, \DateTimeImmutable $validFrom, ?\DateTimeImmutable $validTo): array{created: int, existing: int}`:

- Spočítej list `$candidates` (každý je `['date_begin' => DateTimeImmutable, 'date_end' => DateTimeImmutable, 'name' => string]`):
  - `$kind === 1` (měsíční): 12 měsíců
    ```
    for ($m = 1; $m <= 12; $m++):
      $dateBegin = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
      $dateEnd = $dateBegin->modify('+1 month -1 day');
      $name = sprintf('%02d/%04d', $m, $year);
    ```
  - `$kind === 2` (čtvrtletní): 4 čtvrtletí
    ```
    for ($q = 1; $q <= 4; $q++):
      $startMonth = ($q - 1) * 3 + 1;  // Q1=1, Q2=4, Q3=7, Q4=10
      $dateBegin = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $startMonth));
      $dateEnd = $dateBegin->modify('+3 months -1 day');
      $name = sprintf('Q%d/%04d', $q, $year);
    ```
- Pro každý kandidát:
  - **Filtr platnosti**: pokud `$dateEnd < $validFrom`, skip; pokud `$validTo !== null && $dateBegin > $validTo`, skip
  - **Idempotence (lookup ignoruje docState)**:
    ```
    $row = $db->fetchRow(
      'SELECT id FROM economy_codebooks_vat_periods WHERE vat_registration = %i AND date_begin = %d',
      $regId, $dateBegin->format('Y-m-d'),
    );
    if ($row !== null) { $existing++; continue; }
    ```
  - **INSERT**:
    ```
    $db->insertRow('economy_codebooks_vat_periods', [
      'vat_registration' => $regId,
      'name'             => $name,
      'date_begin'       => $dateBegin->format('Y-m-d'),
      'date_end'         => $dateEnd->format('Y-m-d'),
      'locked'           => 0,
      'docState'         => 40,
      'docStateMain'     => 3,
    ]);
    $created++;
    ```

Vzor: `modules/economy/codebooks/src/FiscalYearsProvisioner.php` (struktura `provision`, `db->begin/commit/rollback` pattern, `insertRow` use).

### Krok 7: Hook do `DsUpgradeCommand`

V `src/Command/DataSource/DsUpgradeCommand.php`:

1. Přidej v `execute()` po `provisionFiscalYears(...)`:
   ```php
   $this->provisionVatPeriods($resolvedModules, $dsConnection, $output);
   ```
2. Přidej import:
   ```php
   use Shipard\Module\Economy\Codebooks\VatPeriodsProvisioner;
   ```
3. Přidej private metodu `provisionVatPeriods` analogicky `provisionFiscalYears`:
   - Pokud `economy.codebooks` není aktivní modul → `[SKIP] economy.codebooks module not active`
   - Instancuj `VatPeriodsProvisioner($dsConnection)`, zavolej `provision()`
   - Vypiš `[OK] vat periods — created: N, existing: M`

Vzor: `provisionFiscalYears` ve stejném souboru.

### Krok 8: module.jsonc rozšíření

V `modules/economy/codebooks/module.jsonc`:

1. Aktualizuj `description`/`description:cs`/`description:en` o zmínku o DPH
2. Do `dependencies` přidej `"world.trade"` (vedle `"core.system"` a `"world.base"`)
3. Do `tables` přidej:
   ```jsonc
   "economy_codebooks_vat_registrations",
   "economy_codebooks_vat_periods"
   ```
4. Do `viewers` přidej:
   ```jsonc
   {
       "id": "economy.codebooks.vatRegistrations",
       "name": "VAT registrations",
       "name:cs": "Registrace DPH",
       "name:en": "VAT registrations",
       "icon": "vat",
       "table": "economy_codebooks_vat_registrations",
       "class": "Shipard\\Module\\Economy\\Codebooks\\VatRegistrationsViewer"
   }
   ```
5. Do `forms` přidej:
   ```jsonc
   {
       "table": "economy_codebooks_vat_registrations",
       "class": "Shipard\\Module\\Economy\\Codebooks\\VatRegistrationsForm"
   },
   {
       "table": "economy_codebooks_vat_periods",
       "id": "economy.codebooks.vat_periods"
   }
   ```
6. Do `documentClasses` přidej:
   ```jsonc
   {
       "table": "economy_codebooks_vat_registrations",
       "class": "Shipard\\Module\\Economy\\Codebooks\\VatRegistrationDocument"
   },
   {
       "table": "economy_codebooks_vat_periods",
       "class": "Shipard\\Module\\Economy\\Codebooks\\VatPeriodDocument"
   }
   ```
7. Do `config` přidej:
   ```jsonc
   {
       "id": "economy.codebooks.vatTaxpayerKinds",
       "file": "config/vatTaxpayerKinds.jsonc"
   },
   {
       "id": "economy.codebooks.vatPeriodKinds",
       "file": "config/vatPeriodKinds.jsonc"
   }
   ```

### Krok 9: Frontend — ikona

V `frontend/src/icons.js`:

1. Přidej do importů `faPercent`:
   ```js
   import {
     // ... existující ...
     faPercent,
   } from '@fortawesome/free-solid-svg-icons';
   ```
2. Expose:
   ```js
   export const iconVat = faPercent;
   ```
3. Do `iconMap`:
   ```js
   'vat': iconVat,
   ```

Spusť `npm run build` v `frontend/`.

### Krok 10: install.base aktualizace

V `modules/install/base/module.jsonc` ověř, že `economy.codebooks` je v `dependencies` (může už tam být z fiscal periods fáze) — pokud ano, nic neměň. Pokud chybí, doplň.

### Krok 11: Testy

`tests/Unit/Module/Economy/Codebooks/`:

**`VatRegistrationDocumentTest.php`** — pokrýt:
- chybějící `name`/`region`/`country`/`taxpayer_kind`/`tax_period_kind`/`report_period_kind`/`valid_from` → error per pole
- neplatný `region` (`'xxx'` neexistuje v cfgItem) → error
- neplatný `country` (`'xx'` neexistuje v cfgItem) → error
- neplatný `taxpayer_kind` (např. 5) → error
- neplatný `tax_period_kind` (např. 0 nebo 5) → error
- `valid_to < valid_from` → error na `valid_to` s kódem `invalid_range`
- `valid_to = null` (open-ended) → OK
- všechno OK → valid

**`VatPeriodDocumentTest.php`** — pokrýt:
- chybějící povinná pole → error per pole
- `date_begin > date_end` → error na `date_end`
- všechno OK → valid

**`VatPeriodsProvisionerTest.php`** — pokrýt logiku generování (mock `DataSourceConnection` analogicky existujícím provisioner testům):
- Žádné registrace → `created: 0, existing: 0`
- 1 registrace, měsíční (`tax_period_kind=1`), `valid_from=2026-01-01`, `valid_to=null`, refDate `2026-04-15` → 24 období (12 v 2026 + 12 v 2027), všechna s formátem `"MM/YYYY"`, `docState=40`
- 1 registrace, čtvrtletní (`tax_period_kind=2`), `valid_from=2026-01-01`, `valid_to=null`, refDate `2026-04-15` → 8 období (4 v 2026 + 4 v 2027), formát `"QN/YYYY"`, Q1=`2026-01-01..2026-03-31`, Q2=`2026-04-01..2026-06-30`, atd.
- 1 registrace, měsíční, `valid_from=2026-06-01`, `valid_to=null` → období generováno od června 2026 (5+12 = 17 období), nikoli od ledna
- 1 registrace, měsíční, `valid_from=2026-01-01`, `valid_to=2026-08-31` → 8 období v 2026 (leden–srpen), 0 v 2027
- 1 registrace, ale `docState=90` (smazaná) → 0 období (provisioner ji ignoruje)
- Idempotence: druhý běh → `created: 0, existing: 24` (pro měsíční případ)
- Idempotence po smazání: jedno období v DB existuje s `docState=90` → další běh skipne, `existing` se nezvýší o nulu, ale lookup vrátí ID → counted as existing, **NE** re-created
- Generované období má `docState=40, docStateMain=3, locked=0`

Vzor: existující provisioner testy v `tests/Unit/Module/Economy/Codebooks/` (FiscalYearsProvisionerTest pokud byl napsán; jinak `tests/Unit/Module/Base/Persons/PersonDocumentTest.php` pro Documents pattern).

**Lokálně ověř na testovacím DS**:
1. `bin/shpd-ds ds-upgrade` — bez registrací: `vat periods — created: 0, existing: 0`
2. Vytvoř manuálně novou registraci v UI — vznikne jako Koncept (10), zatím se pro ni nic negeneruje
3. Přepni registraci na V pořádku (40), pak `bin/shpd-ds ds-upgrade` — output `vat periods — created: N, existing: 0` (N = počet období podle frekvence × 2 roky × omezené `valid_from`/`valid_to`)
4. Druhý běh → `created: 0, existing: N`
5. Otevři viewer **Registrace DPH** v UI — vidíš svou registraci, ve formu v tabu Období DPH jsou všechny vygenerované záznamy
6. Smaž jedno období přes UI (přepni na Smazáno → 90), pak `ds-upgrade` znovu → smazané období se NEvrací (lookup ignoruje docState)
7. Vytvoř druhou registraci pro jinou zemi (např. SK) — po `ds-upgrade` se vygenerují období i pro ni, nezávisle

### Krok 12: Documentation

**Rozšíření `modules/economy/codebooks/README.md`** — přidej sekci „Registrace DPH a období DPH":
- Vysvětlení, že firma může mít 0, 1, nebo více DPH registrací (různé země, OSS, diskontinuity)
- Vztah Registrace ↔ Období: každé období patří jedné registraci
- Sekce „Auto-generování období DPH" — popis chování `VatPeriodsProvisioner` (kdy se generuje, omezení podle `valid_from`/`valid_to`, idempotence ignoruje `docState`, kalendářní rok ne fiskální)
- Sekce „Manuální správa" — uživatel může v UI přidat/smazat období pro speciální případy (zánik plátcovství, mimořádná období); změna frekvence (`tax_period_kind`) si vyžaduje manuální úklid existujících období

**`modules/economy/codebooks/tables/economy_codebooks_vat_registrations.md`** — sloupce per skupina, vysvětlení:
- `region` jako klíč v `world.trade.unions` (default `eu`)
- `country` jako ISO alpha-2 (default `cz`)
- `taxpayer_kind` (klasický plátce vs. OSS — One-Stop-Shop pro EU služby)
- `tax_period_kind` vs `report_period_kind` (přiznání DPH vs. kontrolní hlášení; v CZ může být KH měsíční i u čtvrtletního plátce)
- `vat_id` nullable kvůli stavu „v procesu registrace"
- `valid_to = NULL` znamená bez konce platnosti

**`modules/economy/codebooks/tables/economy_codebooks_vat_periods.md`** — sloupce, vysvětlení:
- Vazba na registraci přes `vat_registration`
- Kalendářní (ne fiskální) periody
- Formát `name`: `"MM/YYYY"` měsíční, `"QN/YYYY"` čtvrtletní; uživatel může mít vlastní speciální názvy
- `locked` blokuje editaci dokladů (přijde s dokladovým systémem)

## Rozhodnutí k designu (potvrzená)

✓ **TableId 315 (vat_registrations), 316 (vat_periods)** — navazují na fiscal_years (313) a fiscal_months (314)

✓ **Modul `economy.codebooks`** — pohromadě s fiskálními obdobími pod ekonomickými číselníky

✓ **Závislost `economy.codebooks` rozšířena o `world.trade`** — kvůli cfgItem `world.trade.unions` pro pole `region`

✓ **`vat_id` nullable** — registrace může vzniknout v Konceptu „v procesu registrace"; bez state-závislé validace

✓ **`country` default `'cz'` napevno** — per-DS locale-aware default přijde později

✓ **`region` default `'eu'`** — modeluje pouze EU/GCC apod.; pro mimo-unionové DPH se přidá další klíč do `world.trade.unions`

✓ **Formát názvu období DPH**: `"MM/YYYY"` měsíční (`"01/2026"`), `"QN/YYYY"` čtvrtletní (`"Q1/2026"`)

✓ **Generátor počítá podle kalendářního roku**, ne fiskálního — období DPH jsou kalendářní entita; pokud má firma fiskal jiný než kalendář, je to nezávislé

✓ **Auto-generování při změně `tax_period_kind` u existující registrace** se neřeší — uživatel manuálně vyklidí staré, další `ds-upgrade` doplní podle nové frekvence

✓ **Idempotence ignoruje `docState`** — lookup `WHERE vat_registration AND date_begin` vrátí i smazaná období; smazané období zůstává smazané, znovu se nevytvoří. Klíčová vlastnost: pokud uživatel smaže období, má se respektovat

✓ **Editace `valid_from`/`valid_to` po generování období** — provisioner do existujících záznamů nesahá; uživatel si přebytečné/chybějící období řeší ručně

✓ **Sub-tabulka v Registraci** pro Období DPH — primární UI; samostatný viewer Období DPH zatím není (přijde s reporty)

✓ **Generátor pokrývá aktuální + příští kalendářní rok**, omezený `valid_from`/`valid_to` registrace

✓ **Generovaná období: `docState=40` (V pořádku)**, manuálně přes UI vznikají jako `Koncept` (10)

✓ **Generátor zpracuje aktivní registrace** (`docState IN (10, 40, 80)`) — i Koncept generuje období, aby uživatel viděl náhled; pokud nechce, smaže registraci a období se přestanou generovat

✓ **Žádný attachmentsTab ve `VatRegistrationsForm`** — k registraci nedávají přílohy smysl

✓ **Ikona `faPercent` (`iconVat`)** pro viewer Registrace DPH

✓ **`vatPeriodKinds` cfgItem začíná klíčem `"1"`** (nikoli `"0"`) — odpovídá uživatelskému zadání (1=měsíční, 2=čtvrtletní)

## Hotovo když

- [ ] `bin/shpd-ds ds-upgrade` na DS bez registrací proběhne čistě, output `vat periods — created: 0, existing: 0`
- [ ] V navigaci se objeví **Registrace DPH** s ikonou procenta
- [ ] Uživatel může v UI vytvořit novou registraci — vzniká jako Koncept (10), pak ji lze přepnout do V pořádku (40)
- [ ] Po vytvoření aktivní registrace (Koncept nebo V pořádku) další `ds-upgrade` vygeneruje příslušná období — měsíční nebo čtvrtletní podle `tax_period_kind`, omezená `valid_from`/`valid_to`
- [ ] Druhý běh `ds-upgrade` je no-op (`created: 0`)
- [ ] Po přepnutí systémového data o rok dopředu (lokální test) další `ds-upgrade` doplní období pro nový rok
- [ ] V editačním formuláři registrace jsou v záložce Období DPH všechna vygenerovaná období, lze je upravit přes sub-form
- [ ] Smazání období v UI (`docState=90`) přežije další `ds-upgrade` — období se NErecyklují
- [ ] Provisioner-vytvořené období má `docState = 40` (V pořádku), `docStateMain = 3`, `locked = 0`
- [ ] Validace `region`/`country`/`taxpayer_kind`/`*_period_kind` proti cfgItem ve formu vrátí 422 chybu pro neplatné hodnoty
- [ ] Validace `valid_to < valid_from` ve formu registrace → 422 chyba
- [ ] Validace `date_begin > date_end` ve formu období → 422 chyba
- [ ] PHPUnit testy prochází: `vendor/bin/phpunit tests/Unit/Module/Economy/Codebooks`
- [ ] Frontend build prochází bez chyb po přidání `iconVat`
- [ ] Documentation napsaná: rozšíření `README.md` modulu + `.md` per nová tabulka

## Konvence a upozornění

- **Jazyk**: UI texty čeština, kód a komentáře angličtina
- **Vícejazyčnost**: každé `name` v JSONC musí mít `:cs` a `:en` variantu
- **PHP 8.5** strict_types, readonly properties kde možné
- **`Dibi\DateTime` normalizace** už řeší `DataSourceConnection::fetchRow/fetchAll` — `$data['valid_from']` přijde jako `"YYYY-MM-DD"` string; pro výpočty použij `new \DateTimeImmutable($data['valid_from'])`
- **`insertRow()`** v provisioneru vrací real auto-increment ID — pro období to nepotřebujeme (žádný child záznam), ale pattern je stejný jako u `FiscalYearsProvisioner`
- **Transakce v provisioneru** — generování všech období per registrace v jedné transakci přes `$db->begin()/commit()/rollback()` (existující pattern v `FiscalYearsProvisioner`)
- **enumString length** — `region` má length 10 (rezerva pro budoucí prefixy delší než 3 znaky), `country` má length 2 (ISO alpha-2)
- **Composer autoload** — po vytvoření nových souborů `composer dump-autoload`
- **Po každém kroku ověř** na testovacím DS, ať se chyby nehromadí

## Doporučené pořadí implementace

Krok 1 (cfgItemy + tabulky) → Krok 2 (Documenty) → Krok 8 (module.jsonc — aby `ds-upgrade` viděl tabulky a config) → ověřit `ds-upgrade` (vytvoří prázdné tabulky, načte cfgItemy) → Krok 3 + 4 + 5 (Form, JSONC sub-form, Viewer) → ověřit UI ručně (vytvořit registraci) → Krok 6 (Provisioner) → Krok 7 (hook) → ověřit `ds-upgrade` (vygeneruje období pro existující aktivní registraci) → Krok 9 (frontend ikona) → Krok 10 (install.base) → Krok 11 (testy) → Krok 12 (docs).
