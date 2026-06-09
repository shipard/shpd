# Task: Economy.accounting — Účtový rozvrh (Fáze 1)

## Kontext

Zakládáme nový modul **`economy.accounting`** a jeho první tabulku
**`economy_accounting_accounts`** — účtový rozvrh (seznam účtů podvojného
účetnictví). Je to modernizovaná obdoba staré tabulky
`e10doc.debs.accounts` ze Starého Shipardu
(`modules/e10doc/debs/tables/accounts.json`).

Z designu uzavřeno:

- **D-1 = A:** hierarchie i účty žijí v **jedné tabulce**, rozlišené enumem
  `account_level` = {1 Třída, 2 Skupina, 3 Syntetický účet, 4 Analytický účet}.
  Účtovat se bude **jen na úroveň `účet` (4)** — přímé účtování na syntetiku
  nepoužíváme (vynucení je věc budoucího dokladového modulu, ne této tabulky).
- **`g1`/`g2`/`g3` zachovat** — denormalizované prefixy čísla účtu
  (1/2/3 znaky). Osvědčily se pro `GROUP BY` v SQL sestavách. Dopočítávají se
  z `number`, nezadávají se ručně.
- **D-2 (sloupce):**
  - **Zrušit** oproti staré tabulce: `accMethod`, `excludeFromReports`,
    `toBalance`, `accItem`, `useFor`, `useBalance` a **`nontax`** (redundantní
    s `costs_type`).
  - **Zachovat:** `account_kind` (Povaha účtu), `costs_type` (Druh nákladu),
    `results_type` (Druh výsledku) — **včetně hodnoty „Mimořádný"**, kvůli
    historickým datům před r. 2016.
  - Staré inline `enumValues` → v Novém Shipardu **cfgItem** soubory.
- **D-3 = A (seed):** výchozí obsah (standardní osnova) se sype přes
  **`AccountChartProvisioner`** (vzor `UnitsProvisioner`/`ItemKindsProvisioner`),
  spouštěný z `DsUpgradeCommand`. Idempotence klíčovaná na **`number`**.
  Varianta osnovy (firemní vs. nezisková) se čte **per-DS z `config/main.json`**
  polem `accountChart` (`"default"` | `"npo"`, default `"default"`). Tabulka má
  sloupec **`is_system`** (1 = pochází ze standardní osnovy).

## Návaznost

- **Vzor modulu:** `economy.codebooks` — `cost_centers` je nejbližší
  jednoduchá top-level entita (Document + Viewer + JSONC form + registrace
  v `module.jsonc`).
- **Vzor seedu:** `core.units` (`UnitsProvisioner` + `config/unitsSeed.jsonc`)
  a `economy.items` (`ItemKindsProvisioner`) — statický seed, idempotence přes
  přirozený klíč, INSERT s `docState=40, docStateMain=3`. Zapojení provisioneru
  v `src/Command/DataSource/DsUpgradeCommand.php` (metody `provisionUnits`,
  `provisionItemKinds`).
- **Per-DS config:** `Shipard\Core\Config\DataSourceConfig` (vzory
  `getDefaultLanguage()`, `getDefaultCurrency()`, `shouldSkipProvisioning()`).
- **Doc states:** `core.system.docStatesArchive`.
- **Zdroj dat pro seed (Starý Shipard, projekt `old_shipard`):**
  `modules/install/data/countries/cz/debs/debs-accounts-*.json`.

## Před implementací přečti

- `modules/economy/codebooks/module.jsonc` — registrace tabulky, vieweru,
  formy, documentClass, settingsItems, config.
- `modules/economy/codebooks/tables/economy_codebooks_cost_centers.jsonc`
  a `…/economy_codebooks_vat_periods.jsonc` — vzor tabulky s `docStates`
  blokem a s enumInt+cfgItem.
- `modules/economy/codebooks/src/CostCenterDocument.php` a
  `CostCentersViewer.php` — vzor Document (validate/beforeSave) a Vieweru
  (selectRows/renderRow/renderDetail).
- `modules/economy/codebooks/forms/economy_codebooks_cost_centers.jsonc` —
  vzor JSONC formy.
- `modules/core/units/src/UnitsProvisioner.php` +
  `modules/core/units/config/unitsSeed.jsonc` — vzor statického seed
  provisioneru a seed souboru.
- `src/Command/DataSource/DsUpgradeCommand.php` — `provisionUnits` /
  `provisionItemKinds` a provisioning blok v `execute()`.
- `src/Core/Config/DataSourceConfig.php` — přidání accessoru.
- `modules/economy/codebooks/config/vatPeriodKinds.jsonc` — vzor cfgItem
  s vícejazyčnými `name`.

---

## 1. Tabulka `economy_accounting_accounts`

Soubor: `modules/economy/accounting/tables/economy_accounting_accounts.jsonc`

- `tableId`: ověř `bin/shpd-server next-table-id` — předpoklad **105**
  (101–104 zabírá `economy.codebooks`).
- `displayPattern`: `"{number} — {name}"`
- `docStates`: `stateColumn=docState`, `mainColumn=docStateMain`,
  `cfgItem=core.system.docStatesArchive`.

### Skupiny sloupců (`columnGroups`)

- `identity` — Identifikace
- `classification` — Zatřídění
- `settings` — Nastavení

### Sloupce

| id | typ | nullable | group | name:cs | poznámka |
|---|---|---|---|---|---|
| `id` | int, autoIncrement, primaryKey | — | — | ID | |
| `number` | varchar(12) | ne | identity | Číslo účtu | unikátní; přirozený klíč |
| `name` | varchar(180) | ne | identity | Název | |
| `short_name` | varchar(100) | ano | identity | Název zkrácený | |
| `account_level` | enumInt, cfgItem `economy.accounting.accountLevels` | ne | classification | Úroveň | dopočítává se z `number` |
| `g1` | varchar(1) | ano | classification | Třída (prefix) | computed z `number` |
| `g2` | varchar(2) | ano | classification | Skupina (prefix) | computed z `number` |
| `g3` | varchar(3) | ano | classification | Syntetika (prefix) | computed z `number` |
| `account_kind` | enumInt, cfgItem `economy.accounting.accountKinds` | ano | classification | Povaha účtu | |
| `costs_type` | enumInt, cfgItem `economy.accounting.costsTypes` | ano | classification | Druh nákladu | |
| `results_type` | enumInt, cfgItem `economy.accounting.resultsTypes` | ano | classification | Druh výsledku | |
| `valid_from` | date | ano | settings | Platnost od | |
| `valid_to` | date | ano | settings | Platnost do | |
| `is_system` | boolean, default 0 | ne | settings | Systémový | 1 = ze standardní osnovy |
| `note` | text | ano | — | Popis | |
| `docState` | tinyint, default 10, `system: true` | ne | — | Stav dokumentu | |
| `docStateMain` | tinyint, default 1, `system: true` | ne | — | Stav dokumentu (řazení) | |

> Všechny sloupce kromě `id`, `g*` a systémových mají i `name:en`
> (Account number, Name, Short name, Level, Account kind, Cost type,
> Result type, Valid from, Valid to, System, Note). U `g1/g2/g3` stačí
> holé `name` + `:cs`.

### Indexy

- `unq_number` UNIQUE na `number`
- `idx_account_kind` na `account_kind`
- `idx_level` na `account_level`
- `idx_g3` na `g3` — pro `GROUP BY` přes syntetiku
- `idx_doc_state` na `docStateMain ASC, number ASC`

---

## 2. cfgItem soubory (enumy)

Soubory v `modules/economy/accounting/config/`, registrované v `module.jsonc`.
Formát klíč→`{name, name:cs, name:en}` (vzor `vatPeriodKinds.jsonc`).
**Číselné hodnoty `account_kind`, `costs_type`, `results_type` ponech shodné
se Starým Shipardem** kvůli bezeztrátové migraci.

**`accountLevels.jsonc`** → `economy.accounting.accountLevels`
- `1` Třída / Class
- `2` Skupina / Group
- `3` Syntetický účet / Synthetic account
- `4` Analytický účet / Analytic account

**`accountKinds.jsonc`** → `economy.accounting.accountKinds`
(přesně staré hodnoty `accountKind`, vynech `99 ---`)
- `0` Aktiva / Assets
- `1` Pasiva / Liabilities
- `2` Náklady / Expenses
- `3` Výnosy / Revenue
- `4` Otevření období / Period opening
- `5` Aktivně pasivní / Mixed (assets & liabilities)
- `6` Podrozvaha / Off-balance-sheet
- `7` Vnitropodnikové náklady / Internal expenses
- `8` Vnitropodnikové výnosy / Internal revenue
- `9` Uzavření období / Period closing

**`costsTypes.jsonc`** → `economy.accounting.costsTypes`
- `1` Daňově uznatelný / Tax-deductible
- `2` Daňově neuznatelný / Non-deductible

**`resultsTypes.jsonc`** → `economy.accounting.resultsTypes`
- `1` Provozní / Operating
- `2` Finanční / Financial
- `3` Mimořádný / Extraordinary  *(ponechat — historická data < 2016)*

> Hodnota „0 / ---" u `costs_type`/`results_type` se v Novém Shipardu
> nereprezentuje hodnotou v enumu — používá se `NULL` (sloupce jsou nullable),
> takže pro tyto dva sloupce seed `0` vůbec nepíše. **Pozor: u `account_kind`
> je `0` = Aktiva (platná hodnota) a vkládá se normálně; jako „nic" se chápe
> jen vynechané `99`.** (viz §7, §8)

---

## 3. `AccountDocument`

Soubor: `modules/economy/accounting/src/AccountDocument.php`
(namespace `Shipard\Module\Economy\Accounting`).

### Sdílený helper pro odvození struktury

Statická metoda, kterou používá **i `beforeSave`, i provisioner** (jediný zdroj
pravdy):

```php
/**
 * @return array{account_level:int, g1:?string, g2:?string, g3:?string}
 */
public static function deriveStructure(string $number): array
{
    $n = trim($number);
    $len = strlen($n);
    $level = match (true) {
        $len === 1 => 1, // třída
        $len === 2 => 2, // skupina
        $len === 3 => 3, // syntetika
        default    => 4, // analytický účet (typicky 6 znaků)
    };
    return [
        'account_level' => $level,
        'g1' => $len >= 1 ? substr($n, 0, 1) : null,
        'g2' => $len >= 2 ? substr($n, 0, 2) : null,
        'g3' => $len >= 3 ? substr($n, 0, 3) : null,
    ];
}
```

### `validate(array &$data)`

- `number` povinné, regex `^[0-9]{1,12}$` (kód `required` / `invalid`).
- `name` povinné (`required`).
- `valid_from <= valid_to`, jinak chyba na `valid_to` (`invalid_range`) —
  vzor `CostCenterDocument`.

### `beforeSave(array &$data, ?array $originalData = null)`

- `trim` na `number`, `name`, `short_name`.
- pokud je `number` nastaveno, doplň `account_level`, `g1`, `g2`, `g3`
  z `deriveStructure()`.

---

## 4. `AccountsViewer`

Soubor: `modules/economy/accounting/src/AccountsViewer.php` — vzor
`CostCentersViewer` (`docStatesCfgItem = 'core.system.docStatesArchive'`,
view-group filtr, search, renderRow, renderDetail).

- `selectRows`: SELECT `id, number, name, short_name, account_level,
  account_kind, valid_from, valid_to, docState, docStateMain`.
  Search přes `number`, `name`, `short_name`.
  **`ORDER BY docStateMain ASC, number ASC, id ASC`** (číslo účtu je přirozené
  řazení — žádný `sort_order` sloupec nezavádět).
- `renderRow`: `t1 = name`, `i1 = number`. Do `t2` přidat popisek úrovně
  (z `accountLevels` cfgItem) a u ne-`Koncept` stavů badge stavu (vzor).
- `renderDetail`: skupiny Identifikace / Zatřídění (Úroveň, Povaha účtu, Druh
  nákladu, Druh výsledku — překlad přes cfgItem) / Nastavení (platnosti,
  Systémový).

---

## 5. JSONC form

Soubor: `modules/economy/accounting/forms/economy_accounting_accounts.jsonc`
— vzor `cost_centers` form.

- title „Účet" / titleNew „Nový účet".
- pole: `number` (required), `name` (required), `short_name`; separator
  „Zatřídění" → `account_kind`, `costs_type`, `results_type` (typ `select`,
  enumy se vyřeší přes FormDefinition); separator „Nastavení" → `valid_from`,
  `valid_to`, `note`.
- **`account_level`, `g1`, `g2`, `g3`, `is_system` ve formě nejsou** —
  dopočítávané/systémové.

---

## 6. `module.jsonc`

Soubor: `modules/economy/accounting/module.jsonc`

- `id`: `economy.accounting`, `name:cs` „Účetnictví", `name:en` „Accounting".
- `dependencies`: `["core.system"]`.
- `tables`: `["economy_accounting_accounts"]`.
- `settingsItems`: `[{ "viewer": "economy.accounting.accounts", "section": "accounting" }]`
  (sekce `accounting` už existuje — používá ji `economy.codebooks`).
- `viewers`: `economy.accounting.accounts` → `AccountsViewer`, ikona např.
  `list` nebo `book` (ověř v `frontend/src/icons.js`; fallback `iconTable`).
- `forms`, `documentClasses`: registrace tabulky → třída/JSONC id.
- `config`: 4 cfgItemy z §2.

---

## 7. Seed provisioner + zapojení

### `AccountChartProvisioner`

Soubor: `modules/economy/accounting/src/AccountChartProvisioner.php`
— vzor `ItemKindsProvisioner`. Konstruktor `(DataSourceConnection $db,
string $seedFilePath)`. `provision()`:

- načti seed (`JsoncParser::parseFile`), musí být pole.
- pro každý záznam: `number` povinné; když řádek s tímto `number` už existuje
  (libovolný stav — `SELECT id … WHERE number = %s`), `existing++` a přeskoč
  (respektuje uživatelovo zarchivování/úpravu).
- jinak INSERT:
  - `number`, `name`, `short_name` ze seedu,
  - `account_kind`, `costs_type`, `results_type` — vlož pole **pokud je klíč
    v seed záznamu přítomen** (`account_kind` MŮŽE být `0` = Aktiva a v tom
    případě se `0` vkládá; `costs_type`/`results_type` se hodnotou `0` = „---"
    do seedu nepíšou, takže chybějící klíč → NULL),
  - `account_level`, `g1`, `g2`, `g3` z `AccountDocument::deriveStructure($number)`,
  - `is_system = 1`, `docState = 40`, `docStateMain = 3`.
- vrať `['accountChart' => ['created' => …, 'existing' => …]]`.

### `DataSourceConfig`

Přidat accessor (vzor `getDefaultLanguage`):

```php
/** Standardní účtová osnova k naseedování: 'default' (firemní) | 'npo'. */
public function getAccountChart(): string
{
    return $this->data['accountChart'] ?? 'default';
}
```

### `DsUpgradeCommand`

- `use Shipard\Module\Economy\Accounting\AccountChartProvisioner;`
- v provisioning bloku `execute()` (uvnitř `else` větve, za
  `$this->provisionItemKinds(...)`) přidat
  `$this->provisionAccountChart($resolvedModules, $dsConfig, $dsConnection, $output);`
- nová metoda `provisionAccountChart(...)`:
  - guard `isModuleActive($resolvedModules, 'economy.accounting')`, jinak SKIP.
  - varianta `$variant = $dsConfig->getAccountChart();`
    → soubor `accountChartDefault.jsonc` (default) nebo `accountChartNpo.jsonc`
    (pro `'npo'`). Neznámá hodnota → fallback `default` + `<comment>` warning.
  - `$seedFile = $this->getModulePathResolver()->getPath('economy.accounting')
    . '/config/' . $file;`
  - `new AccountChartProvisioner($dsConnection, $seedFile); ->provision();`
  - log přes `logProvisioningResult($output, 'account chart', $result['accountChart']);`

> Provisioner **nečte compiled config** (vkládá jen int hodnoty), takže nemá
> závislost na pořadí kompilace — může běžet kdekoli v provisioning bloku.

---

## 8. Seed data (standardní osnovy)

Dva soubory v `modules/economy/accounting/config/`:

- `accountChartDefault.jsonc` — firemní osnova
- `accountChartNpo.jsonc` — osnova pro neziskové organizace

Formát = **ploché JSONC pole** (jako `unitsSeed.jsonc`), položka:

```jsonc
{"number": "501100", "name": "Spotřeba materiálu", "short_name": "Spotřeba materiálu", "account_kind": 2, "costs_type": 1, "results_type": 1}
```

Pole `account_level`, `g1`, `g2`, `g3`, `is_system`, `docState*` se **do seedu
nepíšou** (dopočítá/nastaví provisioner).

### Konverze ze Starého Shipardu

Zdroj (projekt `old_shipard`):
`modules/install/data/countries/cz/debs/`:

- **default** = sjednoceně řádky z: `debs-accounts-default-groups.json`,
  `debs-accounts-default-vat.json` (**jen dataset `e10doc.debs.accounts`** —
  druhý dataset `e10.witems.items` ignorovat), `debs-accounts-default-class0`
  … `class7`.
- **npo** = `debs-accounts-npo-groups.json`, `debs-accounts-npo-class0,1,2,3,5,6,9`.

Transformace každého `datasets[].data[].rec`:

| starý → nový | pravidlo |
|---|---|
| `id` → `number` | beze změny |
| `fullName` → `name` | |
| `shortName` → `short_name` | |
| `accountKind` → `account_kind` | shodné kódy; vynech `99` (→ NULL) |
| `costsType` → `costs_type` | jen je-li `>0` |
| `resultsType` → `results_type` | jen je-li `>0` |
| `accGroup`, `accMethod`, `nontax`, `toBalance`, `g1/g2/g3`, … | **zahodit** (level/g* se dopočítá) |

Po sloučení **deduplikovat podle `number`** a **seřadit lexikálně podle
`number`** (přirozené řazení hierarchie i účtů).

> **Pozn. k dělbě práce:** vlastní vygenerování obou seed souborů z dat
> Starého Shipardu udělá Claude (má přístup k projektu `old_shipard`) —
> Claude Code v separátní session zdrojová data nemá. Claude Code implementuje
> §1–§7 a §9; seed soubory dostane vygenerované, případně doplní jednoduchý
> konverzní skript dle tabulky výše.

---

## 9. Dokumentace + testy

- **Doc soubor** `modules/economy/accounting/tables/economy_accounting_accounts.md`
  — vzor `economy_codebooks_bank_accounts.md` (popis, sloupce po skupinách,
  indexy, pravidla, související odkazy).
- **Testy** (`tests/Unit/…`, zrcadlí strukturu):
  - `AccountDocument::deriveStructure` — délky 1/2/3/6 → správné `account_level`
    + `g1/g2/g3`.
  - `AccountDocument::validate` — povinné `number`/`name`, regex čísla,
    `valid_from > valid_to` → chyba.
  - `AccountChartProvisioner` — idempotence (druhý běh `created=0`), že
    existující záznam v jiném stavu se nepřepíše, že `account_kind=0/null`
    skončí jako NULL a `is_system=1`.
- Po přidání cfgItem registrací spustit v dev DS
  `vendor/bin/shpd-ds ds-upgrade` (dostane cfgItemy do `compiled.{cs,en}.json`).
  Filtr testů úzký, např. `--filter 'Account|AccountChart'`.

## Co NENÍ v této fázi (budoucí)

- Účtování / účetní deník, předkontace, výběr účtu na dokladech.
- Vynucení „účtovat jen na úroveň `účet`" (až dokladový/účtovací modul).
- Saldokonto, vnitropodnikové okruhy, sestavy nad `g1/g2/g3`.
- Změna varianty osnovy za běhu DS (jednorázová volba při zakládání).
