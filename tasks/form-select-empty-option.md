# Task: Roletky ve formulářích — prázdná možnost jen u nullable polí (Issue #61)

**Stav:** hotovo

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

## Poznámky k implementaci (2026-09-07)

### Co se změnilo proti zadání

Dvě odchylky, obě odsouhlasené před implementací:

1. **`placeholder` se k selectu ve formulářích do té doby vůbec nedostal.**
   `FormElement.svelte` a `FormInline.svelte` ho předávaly inputu a lookupu,
   ale ne `<Select>`, a `TabBuilder::select()` parametr neměl. Rozhodnutí 3
   („explicitní placeholder má přednost") tedy platilo jen pro přímá použití
   `Select.svelte` (DsSetup). Doplněno: `placeholder` prochází z elementu do
   selectu v obou komponentách a `TabBuilder::select()` dostal
   `?string $placeholder = null` na konci signatury (parita s `multiselect()`).
   Žádný dosavadní select placeholder nenesl, chování existujících forem se
   tím nemění.
2. **Pravidlo žilo na třech místech, ne dvou.** Vedle `TabBuilder` a
   `JsoncFormLoader` odvozuje `required` i `AutoFormBuilder` (auto-formuláře
   tabulek bez vlastní formy). Select tam nově `!nullable`, input beze změny.

### Jak je odvození implementované

- `TabBuilder`: pátý volitelný parametr konstruktoru `array $colDefs = []`
  (za `$icon`; `$colLabels` zůstává, testy `new TabBuilder('t', 'T', …)`
  beze změny). `select()` má `?bool $required = null`; při `null` vrací
  `inferSelectRequired()` = `!nullable`, bez definice sloupce `false`.
  `multiselect()` a ostatní typy nedotčené.
- `TableForm::tab()` předává definice sloupců z `$this->tableDef`, který
  `FormController` plní z `TableLoader::load()` — ten aplikuje extensions
  přes `TableMerger::merge`, takže `vat_period`/`cs_period`/`rs_period`
  (extension `economy.vat`) odvození vidí jako nullable. Ověřeno v kódu,
  ne předpokládáno.
- `JsoncFormLoader::deriveRequired($type, $col)`: select `!nullable`,
  ostatní `!nullable && default === null`. Explicitní `"required"` vyhrává.
- `Select.svelte`: `placeholder ?? t('form.selectEmpty')`, podmínka
  `{#if !required || placeholder}` beze změny. Klíč `form.selectEmpty`
  = „nevybráno" / „not selected".
- **Nález z E2E (Anna, bod 3):** u nového záznamu byl text „nevybráno" vidět
  až po rozbalení roletky, zavřená zůstávala prázdná. `FormEditor
  buildDefaultData()` předvyplní pole nového záznamu `''`, prázdná možnost má
  hodnotu `null`; `''` se s ní neshoduje, `<select>` nevybere nic. U
  existujícího záznamu server vrací `null`, tam to sedělo. Opraveno v
  `Select.svelte` funkční vazbou `bind:value={() => (value === '' ? null :
  value), (v) => (value = v)}` — formulář už `''` a `null` považuje za totéž
  (dirty porovnání, serializace při save), normalizace jen sjednocuje
  zobrazení. Nesahá na `buildDefaultData` ani na data posílaná serveru.
- Žádné volání `select()` nepoužívalo poziční argumenty za `label`, změna
  `bool` → `?bool` je bez dopadu na volající. Testy modulových forem
  `setTableDef()` nevolají, odvození se v nich neprojeví (colDefs prázdné →
  `false`); odvození kryjí `TabBuilderTest`, `JsoncFormLoaderTest`,
  `AutoFormBuilderTest`.

### Audit dopadů

Skript porovnal každé volání `->select()` (83 v 21 PHP souborech) a každý
JSONC select (22 ve 12 souborech) se sloučeným schématem včetně extensions.
Celkem 105 míst, 12 sloupců se stává povinnými (33 volání), **všech 12 má
column default** → nový záznam dostane hodnotu z `FormController` (u
`vat_mode` navíc `applyClientDefaults`). **Pro #60 z auditu nevzniká žádný
nový vstup**; otevřené zůstávají jen položky, které #60 už zná
(`vat_registration`, `vat_code`). Viditelný vedlejší efekt: hvězdička u dvou
read-only selectů (`item_type` u položky, `reconciliation_state` u výpisu),
stejně jako dnes u `number_series` existujícího dokladu.

Řádky `docs_core_heads` jsou sloučené přes všechny formy hlavičky (základ,
FVB, FPB, pokladní doklad, pokladna); `DocRowsForm.operation` je uveden
dvakrát, protože řádek dokladu (podmíněně `!$isText`) a kontace
(`required: true`) jsou dvě různá volání.

| formulář(e) | tabulka | sloupec | typ | nullable | default | dnes required | nově | poznámka |
|---|---|---|---|---|---|---|---|---|
| PersonsForm | `base_persons_persons` | `person_type` | enumInt | ne | `1` | true | true | explicitní, beze změny |
| RegistryDocumentsForm | `base_registry_documents` | `doc_kind` | enumString | ne | `'other'` | true | true | explicitní, beze změny |
| RegistryDocumentsForm | `base_registry_documents` | `binder` | int | ano | `null` | false | false | prázdná možnost „nevybráno" |
| IncomingMessagesForm | `core_mail_incoming_messages` | `primary_type` | enumString | ne | `'other'` | true | true | explicitní, beze změny |
| IncomingMessagesForm | `core_mail_incoming_messages` | `mailbox` | int | ne | `null` | true | true | explicitní, beze změny |
| UnitsForm | `core_units` | `quantity` | enumString | ne | `null` | true | true | explicitní, beze změny |
| FiscalYearsForm | `economy_codebooks_fiscal_years` | `currency` | enumString | ne | `'czk'` | true | true | explicitní, beze změny |
| VatRegistrationsForm | `economy_codebooks_vat_registrations` | `region` | enumString | ne | `'eu'` | true | true | explicitní, beze změny |
| VatRegistrationsForm | `economy_codebooks_vat_registrations` | `country` | enumString | ne | `'cz'` | true | true | explicitní, beze změny |
| VatRegistrationsForm | `economy_codebooks_vat_registrations` | `taxpayer_kind` | enumInt | ne | `0` | true | true | explicitní, beze změny |
| VatRegistrationsForm | `economy_codebooks_vat_registrations` | `tax_period_kind` | enumInt | ne | `1` | true | true | explicitní, beze změny |
| VatRegistrationsForm | `economy_codebooks_vat_registrations` | `cs_period_kind` | enumInt | ne | `1` | true | true | explicitní, beze změny |
| VatRegistrationsForm | `economy_codebooks_vat_registrations` | `rs_period_kind` | enumInt | ne | `1` | true | true | explicitní, beze změny |
| ItemKindsForm | `economy_items_kinds` | `item_type` | enumInt | ne | `3` | true | true | explicitní, beze změny |
| ItemsForm | `economy_items` | `item_kind` | int | ne | `null` | true | true | explicitní, beze změny |
| ItemsForm | `economy_items` | `item_type` | enumInt | ne | `3` | false | true | **nově povinné**; default `3` dodá novému záznamu hodnotu (pole readOnly, přibude hvězdička) |
| ItemsForm | `economy_items` | `unit` | int | ne | `null` | true | true | explicitní, beze změny |
| ReportPeriodsForm | `economy_vat_report_periods` | `vat_registration` | int | ne | `null` | true | true | explicitní, beze změny |
| ReportPeriodsForm | `economy_vat_report_periods` | `report_type` | enumString | ne | `null` | true | true | explicitní, beze změny |
| CashDeskFormBase, DocsHeadsFormBase, ReceivedInvoiceForm, IssuedInvoiceForm, AccountingDocsForm | `docs_core_heads` | `number_series` | int | ne | `null` | true | true | explicitní, beze změny |
| DocRowsForm | `docs_core_rows` | `row_kind` | enumInt | ne | `1` | true | true | explicitní, beze změny |
| DocRowsForm | `docs_core_rows` | `operation` | enumString | ano | `null` | !$isText | !$isText | explicitní, beze změny |
| DocRowsForm | `docs_core_rows` | `unit` | int | ano | `null` | false | false | prázdná možnost „nevybráno" |
| DocRowsForm | `docs_core_rows` | `price_calc_mode` | enumInt | ne | `0` | false | true | **nově povinné**; default `0` dodá novému záznamu hodnotu |
| DocRowsForm | `docs_core_rows` | `vat_code` | varchar | ano | `null` | $showVat | $showVat | explicitní, beze změny |
| DocRowsForm | `docs_core_rows` | `operation` | enumString | ano | `null` | true | true | explicitní, beze změny |
| DocRowsForm | `docs_core_rows` | `acc_side` | enumInt | ano | `null` | true | true | explicitní, beze změny |
| DocsHeadsFormBase, ReceivedInvoiceForm, IssuedInvoiceForm, CashDocForm, CashRegisterForm | `docs_core_heads` | `vat_mode` | enumInt | ne | `1` | false | true | **nově povinné**; default `1` dodá novému záznamu hodnotu |
| DocsHeadsFormBase, ReceivedInvoiceForm, IssuedInvoiceForm, CashDocForm | `docs_core_heads` | `vat_calc_source` | enumInt | ne | `0` | false | true | **nově povinné**; default `0` dodá novému záznamu hodnotu |
| DocsHeadsFormBase, ReceivedInvoiceForm, IssuedInvoiceForm | `docs_core_heads` | `vat_place` | enumInt | ne | `0` | false | true | **nově povinné**; default `0` dodá novému záznamu hodnotu |
| DocsHeadsFormBase, ReceivedInvoiceForm, IssuedInvoiceForm, CashDocForm, CashRegisterForm | `docs_core_heads` | `vat_registration` | int | ano | `null` | false | false | prázdná možnost „nevybráno" |
| DocsHeadsFormBase | `docs_core_heads` | `vat_period` | int | ano | `null` | false | false | prázdná možnost „nevybráno" |
| DocsHeadsFormBase | `docs_core_heads` | `cs_period` | int | ano | `null` | false | false | prázdná možnost „nevybráno" |
| DocsHeadsFormBase | `docs_core_heads` | `rs_period` | int | ano | `null` | false | false | prázdná možnost „nevybráno" |
| DocsHeadsFormBase, ReceivedInvoiceForm, IssuedInvoiceForm | `docs_core_heads` | `doc_currency` | enumString | ne | `'czk'` | false | true | **nově povinné**; default `'czk'` dodá novému záznamu hodnotu |
| DocsHeadsFormBase, ReceivedInvoiceForm, IssuedInvoiceForm, CashDocForm | `docs_core_heads` | `total_rounding_mode` | enumInt | ne | `0` | false | true | **nově povinné**; default `0` dodá novému záznamu hodnotu |
| DocsHeadsFormBase, ReceivedInvoiceForm, IssuedInvoiceForm, CashDocForm | `docs_core_heads` | `vat_rounding_mode` | enumInt | ne | `0` | false | true | **nově povinné**; default `0` dodá novému záznamu hodnotu |
| DocsHeadsFormBase, ReceivedInvoiceForm, IssuedInvoiceForm, CashDocForm, CashRegisterForm | `docs_core_heads` | `payment_method` | enumInt | ne | `1` | false | true | **nově povinné**; default `1` dodá novému záznamu hodnotu |
| DocsHeadsFormBase, ReceivedInvoiceForm, IssuedInvoiceForm | `docs_core_heads` | `bank_account` | int | ano | `null` | false | false | prázdná možnost „nevybráno" |
| NumberSeriesForm | `docs_core_number_series` | `doc_type` | enumString | ne | `null` | true | true | explicitní, beze změny |
| NumberSeriesForm | `docs_core_number_series` | `reset_scope` | enumString | ne | `'fiscal_year'` | true | true | explicitní, beze změny |
| CashDocForm | `docs_core_heads` | `cash_dir` | enumInt | ne | `0` | true | true | explicitní, beze změny |
| TasksForm | `tasks_core_tasks` | `priority` | enumString | ano | `null` | false | false | prázdná možnost „nevybráno" |
| DsUsersForm | `hosting_core_ds_users` | `user` | int | ne | `null` | true | true | explicitní, beze změny |
| DsUsersForm | `hosting_core_ds_users` | `data_source` | int | ne | `null` | true | true | explicitní, beze změny |
| DsUsersForm | `hosting_core_ds_users` | `role` | enumString | ne | `'member'` | true | true | explicitní, beze změny |
| DataSourcesForm | `hosting_core_data_sources` | `language` | enumString | ne | `'cs'` | true | true | explicitní, beze změny |
| DataSourcesForm | `hosting_core_data_sources` | `country` | enumString | ne | `'cz'` | true | true | explicitní, beze změny |
| DataSourcesForm | `hosting_core_data_sources` | `server` | int | ano | `null` | false | false | prázdná možnost „nevybráno" |
| DataSourcesForm | `hosting_core_data_sources` | `lifecycle` | enumString | ne | `'active'` | true | true | explicitní, beze změny |
| DataSourcesForm | `hosting_core_data_sources` | `owner` | int | ano | `null` | false | false | prázdná možnost „nevybráno" |
| base_persons_bank_accounts.jsonc (JSONC) | `base_persons_bank_accounts` | `source` | enumInt | ne | `0` | false | true | **nově povinné**; default `0` dodá novému záznamu hodnotu |
| base_persons_addresses.jsonc (JSONC) | `base_persons_addresses` | `address_type` | enumInt | ne | `0` | false | true | **nově povinné**; default `0` dodá novému záznamu hodnotu |
| base_persons_addresses.jsonc (JSONC) | `base_persons_addresses` | `country` | enumString | ano | `null` | false | false | prázdná možnost „nevybráno" |
| base_persons_addresses.jsonc (JSONC) | `base_persons_addresses` | `place_reg_type` | enumString | ano | `null` | false | false | prázdná možnost „nevybráno" |
| core_mail_sender_rules.jsonc (JSONC) | `core_mail_sender_rules` | `pattern_kind` | enumString | ne | `'email'` | true | true | explicitní, beze změny |
| core_mail_sender_rules.jsonc (JSONC) | `core_mail_sender_rules` | `disposition` | enumString | ne | `'archive'` | true | true | explicitní, beze změny |
| core_mail_mailboxes.jsonc (JSONC) | `core_mail_mailboxes` | `default_primary_type` | enumString | ano | `null` | false | false | prázdná možnost „nevybráno" |
| core_exchange_tag_rules.jsonc (JSONC) | `core_exchange_tag_rules` | `tag` | enumString | ne | `null` | true | true | explicitní, beze změny |
| economy_codebooks_fiscal_months.jsonc (JSONC) | `economy_codebooks_fiscal_months` | `period_type` | enumInt | ne | `1` | true | true | explicitní, beze změny |
| economy_codebooks_bank_accounts.jsonc (JSONC) | `economy_codebooks_bank_accounts` | `currency` | enumString | ne | `'czk'` | true | true | explicitní, beze změny |
| economy_codebooks_cash_desks.jsonc (JSONC) | `economy_codebooks_cash_desks` | `currency` | enumString | ne | `'czk'` | true | true | explicitní, beze změny |
| economy_accounting_accounts.jsonc (JSONC) | `economy_accounting_accounts` | `account_kind` | enumInt | ano | `null` | false | false | prázdná možnost „nevybráno" |
| economy_accounting_accounts.jsonc (JSONC) | `economy_accounting_accounts` | `costs_type` | enumInt | ano | `null` | false | false | prázdná možnost „nevybráno" |
| economy_accounting_accounts.jsonc (JSONC) | `economy_accounting_accounts` | `results_type` | enumInt | ano | `null` | false | false | prázdná možnost „nevybráno" |
| economy_bank_statements.jsonc (JSONC) | `economy_bank_statements` | `currency` | enumString | ne | `null` | true | true | explicitní, beze změny |
| economy_bank_statements.jsonc (JSONC) | `economy_bank_statements` | `reconciliation_state` | enumInt | ne | `0` | false | true | **nově povinné**; default `0` dodá novému záznamu hodnotu (pole readOnly, přibude hvězdička) |
| economy_bank_transactions.jsonc (JSONC) | `economy_bank_transactions` | `direction` | enumInt | ne | `null` | true odvozeno | true | beze změny (už dnes odvozené `true`) |
| economy_bank_transactions.jsonc (JSONC) | `economy_bank_transactions` | `currency` | enumString | ne | `null` | true odvozeno | true | beze změny (už dnes odvozené `true`) |
| economy_bank_transactions.jsonc (JSONC) | `economy_bank_transactions` | `operation` | enumString | ano | `null` | false | false | prázdná možnost „nevybráno" |
| economy_accbal_balance_accounts.jsonc (JSONC) | `economy_accbal_balance_accounts` | `acc_side` | enumInt | ne | `0` | true | true | explicitní, beze změny |
| economy_accbal_balance_accounts.jsonc (JSONC) | `economy_accbal_balance_accounts` | `amounts_sign` | enumInt | ne | `0` | true | true | explicitní, beze změny |
| economy_accbal_balance_accounts.jsonc (JSONC) | `economy_accbal_balance_accounts` | `bal_side` | enumInt | ne | `0` | true | true | explicitní, beze změny |

### Ověření

`vendor/bin/phpunit --filter 'TabBuilder|JsoncFormLoader|AutoFormBuilder'`
69 testů, celá sada 5161 testů zelená (1 skipped, předchozí stav);
`npm run check:i18n` 781 klíčů v paritě; `npm run build` bez chyb.
E2E body 3–7 z „Hotovo když" ověřuje Anna na dev DS před commitem.
