# Task: Roletky ve formulářích — prázdná možnost jen u nullable polí (Issue #61)

**Stav:** naplánováno

## Status / cíl

Spousta roletek v editačních modalech má navrchu prázdnou možnost, i když
sloupec v DB nemůže být NULL a hodnota je vždy předvyplněná (na hlavičce
dokladu `vat_mode`, `vat_calc_source`, `vat_place`, `doc_currency`,
`payment_method`, oba `*_rounding_mode`; na řádku `price_calc_mode`;
u položky `item_type`…). Uživatel může vybrat prázdno, odeslat `null` a
skončit na chybě DB/validace — jde tedy o **správnost**, ne jen o UX.

Cíl: **prázdná možnost existuje jen u polí, kde smí být NULL, a je
pojmenovaná („nevybráno")**. Odvozuje se ze schématu, ne z ručního
`required: true` v každém formuláři.

GitHub Issue: shipard/shpd#61. Navazuje `#60` (defaulty nového záznamu
u zbylých nullable polí — `vat_registration`, `vat_code`) — **řešit až po
tomto tasku**, teprve po něm je vidět, kde default skutečně chybí.

## Potvrzená designová rozhodnutí (Anna, 2026-09-07)

1. **Odvození na serveru, ze schématu.** `TabBuilder` dostane definice
   sloupců (dnes zná jen labely) a `select()` bez explicitního `required`
   odvodí povinnost ze sloupce. Ne oprava symptomu ve frontendu.
2. **Pravidlo pro select: `required = !nullable`** — bez podmínky na
   `default`. U selectu default nic neřeší; prázdná možnost vždy znamená
   NULL, který do NOT NULL sloupce nejde. Odvození pro `input` zůstává
   stávající (`!nullable && default === null`).
3. **Prázdná možnost u nullable polí zůstává a má globální text
   „nevybráno"** (frontend i18n; explicitní `placeholder` elementu má
   přednost — stávající chování).
4. **Rozsah: všechny formuláře najednou** — 21 souborů s `->select()`
   (83 volání) + 22 selectů v JSONC formulářích. Odvození je globální
   změna; audit dopadů je součástí tasku.
5. Explicitní `required: true` na nullable sloupci zůstává respektován
   (`vat_code` na řádku, po #60 i `vat_registration`) — explicitní hodnota
   vždy vyhrává nad odvozením.

## Před implementací přečti

- `frontend/src/components/ui/Select.svelte` — jediné místo, kde prázdná
  možnost vzniká: `{#if !required || placeholder}<option value={null}>…`.
- `frontend/src/components/form/FormElement.svelte` ř. 68 a
  `FormInline.svelte` ř. 38–65 — jak se `element.required` předává.
- `src/Core/Form/TabBuilder.php` — konstruktor (ř. 39, `array $colLabels`),
  `select()` (ř. 225, `bool $required = false`), `resolveLabel()` (ř. 514).
- `src/Core/Form/TableForm.php` ř. 113–121 — `tab()` staví `$colLabels`
  z `$this->tableDef->columns`; tady se předají celé definice.
- `src/Core/Form/JsoncFormLoader.php` ř. 231 a 305 — resolve options a
  dnešní odvození `required: $elData['required'] ?? ($col !== null && !$col->nullable && $col->default === null)`.
- `src/Core/Database/ColumnDefinition.php` — `nullable`, `default`, `type`.
- `src/Api/Controller/FormController.php` ř. 69–78 — nový záznam dostává
  column defaults ze schématu (proto je u NOT NULL enumů s defaultem
  hodnota vždy k dispozici a odstranění prázdné možnosti nic nerozbije).
- `modules/docs/core/src/DocsHeadsFormBase.php` ř. 357–500 — hlavička
  dokladu, největší koncentrace dotčených selectů; `applyClientDefaults`
  ř. 300.
- `docs/edit-forms.md` ř. 185 (tabulka atributů, `required`) a ř. 808–820
  (signatury TabBuilderu) — aktualizovat.
- `tests/Unit/Core/Form/TabBuilderTest.php`,
  `tests/Unit/Core/Form/JsoncFormLoaderTest.php`.

## Rozsah

### 1. `TabBuilder` — definice sloupců a odvození `required` u selectu

- Konstruktor: přidat parametr s mapou `column => ColumnDefinition`
  (např. `array $colDefs = []`), **zpětně kompatibilně** — `TabBuilderTest`
  staví `new TabBuilder('basic', 'Basic')` bez dalších argumentů a
  `$colLabels` se dnes předává zvlášť. Varianta: nechat `$colLabels` a
  přidat `$colDefs` jako další volitelný parametr; nebo přijímat definice
  a labely z nich odvodit uvnitř — ale pak nutno zachovat chování
  `formLabel ?? name` z `TableForm::tab()`.
- `select(...)`: `bool $required = false` → `?bool $required = null`.
  Při `null` odvodit `!$colDefs[$column]->nullable`, pokud definice
  existuje; jinak `false` (dnešní chování). Explicitní `true`/`false`
  vyhrává.
- **Jen `select()`.** `multiselect()` (hodnota je seznam, prázdný seznam ≠
  NULL) a `input`/`date`/`number`… beze změny.
- `TableForm::tab()`: předat definice sloupců z `$this->tableDef`.
  Ověřit, že `$tableDef` v `FormController` už obsahuje **sloučené
  extensions** (`vat_period`/`cs_period`/`rs_period` na `docs_core_heads`
  přidává `modules/economy/vat/extensions/docs_core_heads.jsonc`) —
  `SchemaLoader` extensions aplikuje, ale potvrdit v kódu, kterou instanci
  `TableDefinition` controller formu předává.

### 2. `JsoncFormLoader` — pravidlo pro select

- Ř. 305: pro `$type === 'select'` odvozovat `!$col->nullable` (bez
  `&& $col->default === null`). Ostatní typy beze změny.
- Explicitní `"required"` v JSONC vyhrává (už dnes).

### 3. `Select.svelte` — globální text prázdné možnosti

- `import { t } from '../../i18n/index.js';`
- `<option value={null}>{placeholder ?? t('form.selectEmpty')}</option>`
- `frontend/src/i18n/cs.js`: `'form.selectEmpty': 'nevybráno'`;
  `en.js`: `'form.selectEmpty': 'not selected'`. Zařadit k ostatním
  `form.*` klíčům (cs.js ř. ~266).
- Podmínka `{#if !required || placeholder}` zůstává.
- Ostatní použití `<Select>` mimo `FormElement` (DsSetup,
  VatRegistrationPrefillDialog, NewDatasourceModal, ReportsPage,
  VatPeriodPicker) buď předávají vlastní `placeholder`, nebo jsou
  `required` — nový text se jich nedotkne. Ověřit grepem, ne z hlavy.

### 4. Audit dopadů (povinná součást tasku)

Projít **všech 21 souborů** s `->select()` a **všech 22** JSONC selectů a
sestavit tabulku `formulář / sloupec / nullable / dnes required / nově
required / poznámka`. Výsledek vložit do sekce „Poznámky k implementaci"
na konci tohoto souboru.

Předběžný obraz z hrubého skenu (ověřit, nejde o úplný seznam — regex
přeskočil jednořádková volání a celé soubory jako `PersonsForm`,
`CashDeskFormBase`, `IssuedInvoiceForm`…):

| formulář | sloupec | nullable | dnes | nově |
|---|---|---|---|---|
| DocsHeadsFormBase | `vat_mode`, `vat_calc_source`, `vat_place`, `doc_currency`, `total_rounding_mode`, `vat_rounding_mode`, `payment_method` | ne | – | **povinné** |
| DocsHeadsFormBase | `vat_registration`, `vat_period`, `cs_period`, `rs_period`, `bank_account` | ano | – | – („nevybráno"; `vat_registration` řeší #60) |
| DocRowsForm | `price_calc_mode` | ne | – | **povinné** |
| DocRowsForm | `unit` | ano | – | – („nevybráno") |
| DocRowsForm | `vat_code` | ano | `$showVat` | `$showVat` (explicitní, beze změny) |
| ItemsForm | `item_type` | ne (default 3) | – | **povinné** |
| RegistryDocumentsForm | `binder` | ano | – | – („nevybráno") |
| TasksForm | `priority` | ano | – | – („nevybráno") |

U každého pole, které **nově** vyjde povinné, ověřit, že nový záznam má
hodnotu (column default ze schématu přes `FormController`, nebo
`applyClientDefaults`/`applyNewRecordDefaults`). Pokud ne → **nepřidávat
default v tomto tasku**, jen zapsat do auditu — je to vstup pro #60.

Kde dnes stojí ruční `required: true` na NOT NULL sloupci, lze ho nechat
(explicitní = dokumentace záměru) — nečistit plošně, není to cíl tasku.

### 5. Testy

- `TabBuilderTest`: select bez `required` na NOT NULL sloupci → `true`;
  na nullable → `false`; bez definic sloupců → `false`; explicitní
  `required: false` na NOT NULL sloupci → `false`; explicitní `true` na
  nullable → `true`. `multiselect` bez `required` na NOT NULL → `false`
  (beze změny).
- `JsoncFormLoaderTest`: select na `enumInt` NOT NULL **s defaultem** →
  `required: true`; `input` na tomtéž typu sloupce → `false` (stávající
  pravidlo nedotčeno).
- `php -l` + `vendor/bin/phpunit --filter 'TabBuilder|JsoncFormLoader'`.

### 6. Dokumentace

- `docs/edit-forms.md`: tabulka atributů (ř. 185) — `required`: default
  se u `select` odvozuje ze schématu (`!nullable`), u ostatních typů
  `!nullable && default === null`; explicitní hodnota vyhrává. Signatura
  `select()` (ř. ~817): `?bool $required = null`. Zmínit globální text
  prázdné možnosti a přednost `placeholder`.

### Mimo rozsah

- Defaulty nového záznamu pro nullable pole (`vat_registration` — první
  podle `country, id`; `vat_code` — otevřené, viz reload/`vat_pct`) → #60.
- `multiselect`, `lookup` — jiná sémantika prázdna, beze změny.
- Plošné odstranění dnes ručně napsaných `required: true`.
- Klientská validace povinnosti (dnes jen hvězdička + HTML atribut bez
  `<form>`; validace je serverová v Document třídách) — beze změny.

## Pasti

- **`patch_file` a diakritika** — task file, `cs.js`, `docs/edit-forms.md`
  i komentáře v PHP obsahují češtinu; editovat přes Python heredoc
  s `assert s.count(old) == 1`.
- **Zpětná kompatibilita konstruktoru `TabBuilder`** — testy i
  případná další místa volají `new TabBuilder(id, label, colLabels, icon)`;
  nový parametr nesmí rozbít pořadí (přidat na konec, nebo pojmenované
  argumenty všude).
- **Extensions sloupců** — pokud by `$tableDef` ve formu neobsahoval
  sloupce z extensions, `vat_period` a spol. by odvození přeskočilo
  (→ `false`, prázdná možnost zůstane — což je u nich správně, ale ze
  špatného důvodu). Ověřit, ne předpokládat.
- **Svelte `bind:value` bez shody** — když required select nemá prázdnou
  možnost a hodnota je `null`/neshodná (int vs string), `<select>` zobrazí
  prázdno, ale model je dál `null`. Není to nová chyba (dnes stejné u
  `vat_code`), ale po změně bude vidět víc — proto audit bodu 4 a odkaz
  na #60. Neřešit tady doplňováním defaultů.
- **`resolveCfgItemOptions` může vrátit `[]`** (chybějící cfgItem v DS) —
  required select s prázdnými options se zobrazí prázdný. Dnes stejné,
  jen bez prázdné možnosti navrchu; nezhoršuje se.
- **`hidden` selecty s `options: []`** (`vat_registration` u skryté sekce
  DPH, `vat_period` u nového dokladu) — odvození `required` na ně nemá
  vliv, jsou skryté. Netřeba ošetřovat.
- **Nesahat na `Select.svelte` podmínku** `{#if !required || placeholder}`
  — `placeholder` u required selectu (DsSetup „Nerozhodnuto") musí dál
  prázdnou možnost vykreslit.

## Hotovo když

1. `vendor/bin/phpunit --filter 'TabBuilder|JsoncFormLoader'` zelené, nové
   testy z bodu 5 přidány.
2. `cd frontend && timeout 90 npm run build` bez chyb.
3. E2E na dev DS — **nová faktura vydaná**: v sekci DPH nemají `Režim DPH`,
   `DPH počítat z`, `Místo plnění` prázdnou možnost a jsou předvyplněné;
   `Měna dokladu`, `Zaokrouhlení částky`, `Zaokrouhlení DPH`, `Způsob platby`
   totéž; `Registrace DPH` a `Náš bankovní účet` mají první možnost
   „nevybráno".
   Uložení projde.
4. E2E — **existující doklad** otevřený z prohlížeče: všechny selecty
   ukazují uloženou hodnotu, žádná se nezměnila jen otevřením a zavřením
   (dirty guard se neaktivuje bez editace).
5. E2E — **nový řádek dokladu**: `Způsob výpočtu` bez prázdné
   možnosti; `Jednotka` s „nevybráno"; `Kód DPH` beze změny oproti dnešku.
6. E2E — **nová položka** (Položky): `Typ položky` předvyplněný, bez
   prázdné možnosti.
7. E2E — **Nastavení → Průvodce nastavením DS**: select s placeholderem
   „Nerozhodnuto" zobrazuje dál svůj text, ne „nevybráno".
8. Audit z bodu 4 vložený na konec tohoto souboru; pole bez defaultu
   (pokud se najdou) zapsaná jako vstup pro #60.
9. `docs/edit-forms.md` aktualizován; `python3 scripts/tasks-index.py &&
   python3 scripts/tasks-index.py --check && python3 scripts/check-sensitive.py`
   projde.
