# Task: Doklady — Rozdělení editačního formuláře na per-typ varianty

## Motivace

Faktury vydané (`docs.invoicesOut`, `doc_type = 'invno'`) a faktury přijaté
(`docs.invoicesIn`, `doc_type = 'invni'`) sdílejí jednu DB tabulku
`docs_core_heads` a v `Fázi 6` dostaly per-typ `Document` třídu (validace) i
per-typ `Viewer` (fixní filtr `_doc_type`, `getNewRecordDefaults()`).
**Editační formulář je ale stále jeden** — `DocsHeadsForm` registrovaný
v `docs.core` zpracovává oba typy společně. To je v pořádku, dokud se obě
varianty liší jen kosmetikou, ale s rozšiřováním funkcionality budou per-typ
specifika přibývat (PDP-specifická pole, výzvy k úhradě, párování pošty,
splátkové kalendáře u FVB; AI extrakce a schvalovací workflow u FPB) a
spojený formulář se brzy stane patchworkem `if ($docType === 'invno')`.

Tento task přidá polymorfismus formulářů **přesně podle vzoru
`DocumentRegistry`** — registrace v `module.jsonc` přes `typeColumn` +
`classes` + `defaultClass`, merge napříč moduly v `FormLoader`, dispatch
v `FormRegistry::createForm($table, $data, ...)`. Pak rozdělí `DocsHeadsForm`
na abstraktní `DocsHeadsFormBase` (shared logika) + tři tenké sourozenecké
třídy:

- `DocsHeadsForm` — generický pro `docs.core.heads` viewer „Doklady"
  (defaultClass, používá se pro neznámé / prázdné `doc_type`)
- `IssuedInvoiceForm` — pro `doc_type = 'invno'`, v `docs.invoicesOut`
- `ReceivedInvoiceForm` — pro `doc_type = 'invni'`, v `docs.invoicesIn`

Hierarchie zrcadlí `DocsHeadsDocument` ↔ `IssuedInvoiceDocument` ↔
`ReceivedInvoiceDocument` (per modul) — `docs.invoicesOut` a
`docs.invoicesIn` budou po dokončení tasku vlastnit `Form` + `Document` +
`Viewer` symetricky a budoucí typy dokladů (proforma, dobropis, bankovní
výpis, pokladní doklad…) se zapojí stejným způsobem.

Generický viewer `docs.core.heads` zůstává — bez `doc_type` filtru funguje
jako reportní pohled napříč typy a používá `DocsHeadsForm` (defaultClass)
jako fallback formulář.

---

## Před implementací přečti

- `docs/edit-forms.md` — celá architektura formulářů, zejména:
  - kap. 11 (`TableForm`)
  - kap. 13 (registrace v `module.jsonc`)
  - kap. 17 (`FormRegistry`, `FormController`, `FormLoader`)
- `docs/document-system.md` — `DocumentRegistry` polymorfismus přes
  `typeColumn` (vzor, který kopírujeme pro formuláře)
- `tasks/docs-invoices.md` (hotovo, Fáze 6) — referenční zdroj pravidel pro
  polymorfní dispatch a slévání registrací z více modulů
- `src/Api/DocumentLoader.php` — implementace `mergeDocumentClasses()`,
  kterou budeme zrcadlit v novém `FormLoader::mergeForms()`
- `src/Core/Document/DocumentRegistry.php` — dispatch logika podle
  `typeColumn` (kopírujeme do `FormRegistry::createForm()`)
- `src/Core/Form/FormRegistry.php` — aktuální stav, který přepisujeme
- `src/Api/FormLoader.php` — aktuální stav, který přepisujeme
- `src/Api/Controller/FormController.php` — kde se `createForm()` volá
  (`resolveFormDefinition`, `recalculate`, `enrichHeaderInfo`)
- `modules/docs/core/src/DocsHeadsForm.php` — formulář, který refaktorujeme
  na `DocsHeadsFormBase` + tenký `DocsHeadsForm`
- `modules/docs/invoicesOut/module.jsonc`, `modules/docs/invoicesIn/module.jsonc`
  — sem přibyde `forms[]` sekce

---

## Cíl

Po dokončení tasku platí:

- `FormRegistry` podporuje **per-table polymorfismus přes `typeColumn`**,
  stejně jako `DocumentRegistry`:
  - `createForm(string $table, array $data, ...)` — pokud má registrace
    `typeColumn`, dispatchuje podle `$data[$typeColumn]` přes mapu
    `classes`, fallback na `defaultClass`
  - Pro registrace bez `typeColumn` (`{table, class}` shape) chování beze
    změny — backwards compatible se všemi existujícími formuláři
    (`PersonsForm`, `NumberSeriesForm`, `DocRowsForm`, …)
- `FormLoader` slévá `forms[]` registrace napříč moduly per-table —
  paralela `DocumentLoader::mergeDocumentClasses()`. Statická metoda
  `FormLoader::mergeForms()` pokrytá unit testy.
- `module.jsonc` schema podporuje pro `forms[]` jak prostý zápis
  `{table, class}`, tak polymorfní `{table, typeColumn, classes, defaultClass}`.
- `FormController::resolveFormDefinition`, `recalculate` a `enrichHeaderInfo`
  předávají `$data` do `createForm()`. Pro nový záznam z per-typ vieweru
  je `$data['doc_type']` k dispozici skrz `newRecordDefaults` z
  `getNewRecordDefaults()` → polymorfní dispatch funguje i pro Add.
- `DocsHeadsForm` (cca 500 řádků současné formuláře) je refaktorovaný na:
  - **`DocsHeadsFormBase`** (abstract, `modules/docs/core/src/`) — veškerá
    společná logika (build tabů, recalculate, options resolvery, HTML
    renderery, applyClientDefaults). Per-typ subclassy override pouze tam,
    kde se chování má lišit. Titulky (`title`, `titleNew`) jsou virtuální
    metody `getFormTitle()` / `getNewFormTitle()`.
  - **`DocsHeadsForm extends DocsHeadsFormBase`** (`modules/docs/core/src/`)
    — tenká třída pro generický „Doklady" viewer. Default titulky („Doklad" /
    „Nový doklad"). Žádné per-typ override.
  - **`IssuedInvoiceForm extends DocsHeadsFormBase`** (`modules/docs/invoicesOut/src/`)
    — titulky „Faktura vydaná" / „Nová faktura vydaná". Strukturně připravená
    pro budoucí FVB-specifická pole / sekce.
  - **`ReceivedInvoiceForm extends DocsHeadsFormBase`** (`modules/docs/invoicesIn/src/`)
    — titulky „Faktura přijatá" / „Nová faktura přijatá". Strukturně
    připravená pro budoucí FPB-specifická pole / sekce.
- `module.jsonc` registrace:
  - `docs.core`: `{table: docs_core_heads, typeColumn: doc_type, defaultClass: DocsHeadsForm}`
  - `docs.invoicesOut`: `{table: docs_core_heads, typeColumn: doc_type, classes: {invno: IssuedInvoiceForm}}`
  - `docs.invoicesIn`: `{table: docs_core_heads, typeColumn: doc_type, classes: {invni: ReceivedInvoiceForm}}`
  - Slévání zajišťuje `FormLoader::mergeForms()`.
- Funkční chování:
  - Otevření **existující FVB** v jakémkoli vieweru → `IssuedInvoiceForm`,
    nadpis modalu „Faktura vydaná".
  - Otevření **existující FPB** → `ReceivedInvoiceForm`, nadpis „Faktura přijatá".
  - **Nový záznam** z per-typ vieweru „Faktury vydané" → `IssuedInvoiceForm`
    (`doc_type='invno'` dorazí přes `newRecordDefaults`), nadpis
    „Nová faktura vydaná".
  - Stejně pro „Faktury přijaté" → `ReceivedInvoiceForm`.
  - **Nový záznam** z generického „Doklady" vieweru (bez `doc_type` hintu)
    → `DocsHeadsForm` (defaultClass), nadpis „Nový doklad".
  - Existující doklad s neznámým / prázdným `doc_type` → `DocsHeadsForm`
    (defaultClass).
- Testy:
  - `FormLoaderTest::mergeForms()` — merge polymorfních registrací (sada testů
    paralelní k `DocumentLoaderTest`).
  - `FormRegistryTest` — dispatch `createForm()` podle `typeColumn`, `classes`,
    `defaultClass`; backwards compat pro `{table, class}` formuláře.
  - Sanity test, že integrační behavior `FormController` neregreduje
    (otevření existujícího `economy_items` záznamu projde — `ItemsForm` je
    `{table, class}` registrace bez typeColumn).
- Dokumentace:
  - `docs/edit-forms.md` — nová sekce **„23. Polymorfní dispatch formulářů
    přes `typeColumn`"**. Updaty kap. 11 (`createForm($table, $data, ...)`),
    kap. 13 (zápis polymorfní registrace), kap. 17 (`FormLoader::mergeForms()`).
  - `modules/docs/invoicesIn/README.md`, `modules/docs/invoicesOut/README.md`
    — odstranit řádek „Žádné nové forms" a doplnit do „Co modul přidává"
    bullet o `*InvoiceForm`.
  - `CLAUDE.md` — krátká poznámka v sekci editačních formulářů s odkazem
    na sekci 23.

---

## Návaznost

- Závisí na: Fáze 6 dokladového MVP (`tasks/docs-invoices.md` — hotovo)
- Otevírá cestu pro: budoucí per-typ moduly dokladů (proforma `prfmin`,
  dobropis, bankovní výpis `bank`, pokladní doklady `cash`, …) — všechny
  se zapojí stejným polymorfním mechanismem
- Spadá do: editační formuláře (`docs/edit-forms.md`), polymorfismus
  modulárního systému

---

## Scope

### V rozsahu

- Refaktor `src/Core/Form/FormRegistry.php`: podpora `typeColumn` + `classes`
  + `defaultClass`, `createForm()` přijímá `$data`
- Refaktor `src/Api/FormLoader.php`: přidání `mergeForms()` statické metody
  (paralela k `DocumentLoader::mergeDocumentClasses()`)
- Úprava `src/Api/Controller/FormController.php`: `createForm()` se volá
  s `$data` na všech třech místech (`resolveFormDefinition`, `recalculate`,
  `enrichHeaderInfo`)
- Refaktor `modules/docs/core/src/DocsHeadsForm.php` → rozdělení na
  `DocsHeadsFormBase` (abstract) + `DocsHeadsForm` (default) v
  `modules/docs/core/src/`
- Nové `modules/docs/invoicesOut/src/IssuedInvoiceForm.php` (extends
  `DocsHeadsFormBase`)
- Nové `modules/docs/invoicesIn/src/ReceivedInvoiceForm.php` (extends
  `DocsHeadsFormBase`)
- Updaty `module.jsonc` ve všech třech modulech (`docs.core`,
  `docs.invoicesOut`, `docs.invoicesIn`)
- Updaty README v `docs.invoicesIn` a `docs.invoicesOut`
- Update `docs/edit-forms.md` — nová sekce 23 + drobné úpravy 11/13/17
- Update `CLAUDE.md`
- Testy: `FormLoaderTest`, `FormRegistryTest`, sanity test, že existující
  `{table, class}` formuláře dál fungují

### Mimo rozsah

- **Per-typ skrývání / přidávání polí v subclasech** — task pouze nastavuje
  strukturu (tenké subclassy s lišícími se titulky). Skutečné FVB-/FPB-
  specifické pole se přidají v navazujících taskech, jak budou vznikat
  features. Cílem **není** v tomto tasku přepracovat obsah formuláře — jen
  připravit půdu, aby přepracování bylo možné per modul nezávisle.
- **Polymorfismus pro `JsoncFormLoader`** — JSONC formuláře nepotřebují
  per-typ variantu (jsou deklarativní a jednoduché). Polymorfismus se týká
  výhradně PHP `TableForm` subclassů. Pokud někdo později bude chtít
  per-typ JSONC, půjde to doplnit (např. `{table, typeColumn, jsoncByType}`),
  ale teď to není potřeba.
- **Per-typ `getFormId()`** — v současné implementaci je `formId` per-table
  (pro `subtable.form_id` referenci v JSONC). To zůstává — `id` se ignoruje
  v polymorfních registracích, žádný use case pro per-typ form_id.
- **`DocRowsForm` per-typ varianta** — řádky dokladu jsou pro všechny typy
  stejné. Žádný split.
- **Lokalizace per-typ titulků** přes cfgItem — titulky `"Faktura vydaná"`
  zůstávají hardcoded v PHP (jako jiné UI texty v PHP). I18n vrstva pro PHP
  texty se řeší v jiné iteraci.
- **Refaktor stávajících non-typeColumn formulářů** — `PersonsForm`,
  `NumberSeriesForm`, `ItemsForm` atd. zůstávají beze změny. Jen
  `DocsHeadsForm` (resp. nově `DocsHeadsFormBase`) se mění.

---

## Architektonická rozhodnutí

### Polymorfní `FormRegistry::createForm($table, $data, ...)`

Aktuální `FormRegistry` indexuje formuláře jen podle table name. `createForm`
neví o `$data`, takže nemůže rozhodnout, kterou subclass instantiovat. Změna
zrcadlí `DocumentRegistry::getDocument(string $tableId, array $data)`:

```php
// Nové
class FormRegistry
{
    /** @var array<string, array<string, mixed>> tableId → merged registration */
    private array $registrations = [];

    /** @var array<string, string> tableId → form id (per-table, ne per-type) */
    private array $formIds = [];

    /**
     * @param list<array<string, mixed>> $registrations
     *     Pre-merged form registrations (output of FormLoader::mergeForms).
     */
    public function __construct(array $registrations = [])
    {
        foreach ($registrations as $reg) {
            $table = $reg['table'] ?? null;
            if (!is_string($table) || $table === '') {
                continue;
            }
            $this->registrations[$table] = $reg;
            if (isset($reg['id']) && is_string($reg['id'])) {
                $this->formIds[$table] = $reg['id'];
            }
        }
    }

    public function getFormId(string $table): ?string
    {
        return $this->formIds[$table] ?? null;
    }

    /**
     * Resolve form class for given $table and $data.
     *
     * For typeColumn-based registrations: dispatch via $data[$typeColumn]
     * through `classes` map, fallback to `defaultClass`. For simple
     * `{table, class}` registrations: return `class` regardless of $data.
     *
     * @param array<string, mixed> $data Row data — typically from DB SELECT
     *     for existing records, or newRecordDefaults for new ones.
     */
    public function createForm(
        string $table,
        array $data = [],
        ?DataSourceConnection $db = null,
        ?ConfigRuntime $config = null,
    ): ?TableForm {
        $reg = $this->registrations[$table] ?? null;
        if ($reg === null) {
            return null;
        }

        $class = null;
        if (isset($reg['typeColumn'])) {
            $typeValue = $data[$reg['typeColumn']] ?? '';
            $class = $reg['classes'][$typeValue] ?? $reg['defaultClass'] ?? null;
        } elseif (isset($reg['class'])) {
            $class = $reg['class'];
        }

        if ($class === null || !class_exists($class)) {
            return null;
        }

        $form = new $class($table);
        if ($db !== null) {
            $form->setDb($db);
        }
        if ($config !== null) {
            $form->setConfig($config);
        }
        return $form;
    }
}
```

**Drop:** existující metoda `getFormClass(string $table): ?string` se ruší —
sémanticky nemá pro polymorfismus smysl (jedna tabulka, N tříd). Místo ní
existuje `createForm()`, který už třídu rozhodnout umí. Pokud něco mimo
`FormController` `getFormClass` volá (zkontrolovat během implementace), je
to call site, který nezná data — pravděpodobně artefakt, který se dá smazat
nebo přepsat na `createForm()`.

**Drop:** `FormRegistry::loadFromModules(array $modules)` — slévání se
přesouvá do `FormLoader::mergeForms()`. Konstruktor přijímá pre-merged
registrace (jako `DocumentRegistry`).

### `FormLoader::mergeForms()` — slévání napříč moduly

Paralela k `DocumentLoader::mergeDocumentClasses()`. Pravidla:

- Jeden záznam per `table`. Pokud více modulů registruje stejnou tabulku,
  slévá se:
  - `typeColumn`: musí být shodný (jinak `LogicException`), bere se z první
    registrace, která ho má
  - `classes` map: union, klíčové kolize (stejný `doc_type` ve dvou
    modulech) → `LogicException`, ledaže by hodnota byla identická (idempotence)
  - `defaultClass`: bere se z první registrace, která ho má (first wins)
  - `class`: kompat s existujícími prostými registracemi; pokud cílová
    registrace má `typeColumn`, `class` se ignoruje (smíšený zápis nemá
    smysl)
  - `id`: bere se z první registrace, která ho má (jako dosud — per-table
    form id pro subtable reference)
- Registrace bez `table` jsou silently skipnuté (kompat s existujícím
  chováním v `FormRegistry::loadFromModules()`).

Nová `src/Api/FormLoader.php`:

```php
<?php
declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Form\FormRegistry;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;

class FormLoader
{
    public static function load(DataSourceConfig $config, ModulePathResolver $resolver): FormRegistry
    {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        return new FormRegistry(self::mergeForms($resolvedModules));
    }

    /**
     * Merge `forms` registrations from multiple modules per table.
     *
     * Mirrors DocumentLoader::mergeDocumentClasses. Multi-module registrations
     * for the same table are valid when using `typeColumn` polymorphism —
     * docs.core registers the default class, docs.invoicesOut adds invno →
     * IssuedInvoiceForm, docs.invoicesIn adds invni → ReceivedInvoiceForm.
     *
     * @param array<int, ModuleDefinition> $modules
     * @return list<array<string, mixed>>
     */
    public static function mergeForms(array $modules): array
    {
        /** @var array<string, array<string, mixed>> */
        $byTable = [];

        foreach ($modules as $module) {
            foreach ($module->forms as $reg) {
                $table = $reg['table'] ?? null;
                if (!is_string($table) || $table === '') {
                    continue;
                }

                if (!isset($byTable[$table])) {
                    $byTable[$table] = $reg;
                    continue;
                }

                $existing = $byTable[$table];

                if (isset($reg['typeColumn'], $existing['typeColumn'])
                    && $reg['typeColumn'] !== $existing['typeColumn']
                ) {
                    throw new \LogicException(
                        "Conflicting typeColumn for table '{$table}': "
                        . "'{$existing['typeColumn']}' vs '{$reg['typeColumn']}' (module {$module->id})",
                    );
                }

                $merged = $existing;

                if (isset($reg['typeColumn']) && !isset($merged['typeColumn'])) {
                    $merged['typeColumn'] = $reg['typeColumn'];
                }

                if (isset($reg['classes']) && is_array($reg['classes'])) {
                    $merged['classes'] = $merged['classes'] ?? [];
                    foreach ($reg['classes'] as $typeKey => $className) {
                        if (isset($merged['classes'][$typeKey])
                            && $merged['classes'][$typeKey] !== $className
                        ) {
                            throw new \LogicException(
                                "Duplicate form class registration for table '{$table}', "
                                . "type '{$typeKey}': '{$merged['classes'][$typeKey]}' vs '{$className}' "
                                . "(module {$module->id})",
                            );
                        }
                        $merged['classes'][$typeKey] = $className;
                    }
                }

                if (isset($reg['defaultClass']) && !isset($merged['defaultClass'])) {
                    $merged['defaultClass'] = $reg['defaultClass'];
                }

                if (isset($reg['class']) && !isset($merged['class']) && !isset($merged['typeColumn'])) {
                    $merged['class'] = $reg['class'];
                }

                if (isset($reg['id']) && !isset($merged['id'])) {
                    $merged['id'] = $reg['id'];
                }

                $byTable[$table] = $merged;
            }
        }

        return array_values($byTable);
    }
}
```

### `FormController` — předávání `$data` do `createForm()`

Tři volání `$formRegistry->createForm($table, $db, $config)` v
`FormController.php` dostanou navíc `$data` parametr:

```php
// src/Api/Controller/FormController.php

// 1. resolveFormDefinition() — uvnitř už má $data k dispozici
$tableForm = $formRegistry->createForm($table, $data, $db, $config);

// 2. recalculate() — uvnitř už má $data k dispozici (z request body)
$tableForm = $formRegistry->createForm($table, $data, $db, $config);

// 3. enrichHeaderInfo() — uvnitř už má $data k dispozici (parametr)
$tableForm = $formRegistry->createForm($table, $data, $db, $config);
```

Pro **nový záznam** je `$data` sestavená z column defaults + `newRecordDefaults`
(z per-typ vieweru přes `getNewRecordDefaults()`). To znamená, že
`$data['doc_type']` je k dispozici i pro nový záznam otevřený z per-typ
vieweru, takže polymorfní dispatch funguje stejně jako pro existující záznam.

Pro nový záznam z generického „Doklady" vieweru je `$data['doc_type']` prázdné
(žádný hint), dispatch tedy padá na `defaultClass` → `DocsHeadsForm`. ✓

### Refaktor `DocsHeadsForm` → `DocsHeadsFormBase` + `DocsHeadsForm`

Aktuální `modules/docs/core/src/DocsHeadsForm.php` (~500 řádků) se rozdělí na:

**`modules/docs/core/src/DocsHeadsFormBase.php`** (nový soubor, abstract):
- Veškerá aktuální logika `DocsHeadsForm` se přesouvá sem.
- Třída je `abstract` — nelze ji instantiovat přímo, vždy přes subclass.
- Metody, které dnes jsou `private`, se mění na `protected`, aby je
  subclassy mohly override-ovat. Konkrétně:
  - `applyClientDefaults(array &$data, bool $isNew): void`
  - `buildHeaderTab(array $data, bool $isNew): FormTab`
  - `buildRowsTab(): FormTab`
  - `buildRecapTab(array $data): FormTab`
  - `buildSnapshotsTab(array $data): FormTab`
  - `buildNotesTab(): FormTab`
  - `resolveNumberSeriesOptions(?string $docType): array`
  - `resolveVatRegistrationOptions(): array`
  - `resolveBankAccountOptions(string $docCurrency): array`
  - `resolveCurrencyOptions(): array`
  - `resolveCfgItemOptions(string $cfgItemId): array`
  - `renderRecapHtml`, `renderSnapshotsHtml`, `decodeSnapshot`,
    `renderPersonSnapshot`, `formatMoney`, `formatExchangeRate`,
    `vatCodeLabel`, `resolveRecapForRender` — všechny `protected`
- `recalculate(string $changedColumn, array $data): RecalculateResult` zůstává
  `public` (override TableForm).
- **Titulky se vytahují do virtuálních metod:**
  ```php
  protected function getFormTitle(): string
  {
      return 'Doklad';
  }

  protected function getNewFormTitle(): string
  {
      return 'Nový doklad';
  }
  ```
- `buildFormDefinition` použije tyto metody místo hardcoded stringů:
  ```php
  return new FormDefinition(
      table: $this->table,
      title: $this->getFormTitle(),
      titleNew: $this->getNewFormTitle(),
      tabs: $tabs,
      fullSize: true,
  );
  ```

**`modules/docs/core/src/DocsHeadsForm.php`** (refaktorovaný soubor — drasticky
zkrácený):
- Zůstává jen třída `DocsHeadsForm extends DocsHeadsFormBase`.
- Žádný override — používá default titulky „Doklad" / „Nový doklad" z base.
- Smysl: být fallback pro generický `docs.core.heads` viewer a pro doklady
  s neznámým / prázdným `doc_type`.
- Doc-comment vysvětlující roli:
  ```php
  /**
   * Generic form for `docs_core_heads` — used as defaultClass when no
   * per-type form is registered for the given doc_type. Backs the
   * `docs.core.heads` viewer ("Doklady" — all types). Per-type forms
   * (IssuedInvoiceForm, ReceivedInvoiceForm) live in their respective
   * docs.invoicesOut / docs.invoicesIn modules.
   */
  class DocsHeadsForm extends DocsHeadsFormBase
  {
      // No overrides — inherits default titles ("Doklad" / "Nový doklad")
      // and all behavior from DocsHeadsFormBase.
  }
  ```

**Důležité:** žádná změna chování pro generický viewer. Vše, co dnes
`DocsHeadsForm` dělá, dělá stejně i po refaktoru — jen je to v base třídě
a `DocsHeadsForm` je tenký facade.

### Nové `IssuedInvoiceForm` a `ReceivedInvoiceForm`

**`modules/docs/invoicesOut/src/IssuedInvoiceForm.php`:**

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesOut;

use Shipard\Module\Docs\Core\DocsHeadsFormBase;

/**
 * Editační formulář pro Faktury vydané (FVB) — `doc_type = 'invno'`.
 *
 * Dědí veškerou logiku z DocsHeadsFormBase. V MVP přepisuje pouze titulky;
 * slouží jako rozšiřovací bod pro FVB-specifické změny formuláře
 * (např. extra sekce pro splátkový kalendář, výzva k úhradě, AI checks,
 * skrytí polí relevantních jen pro FPB atd.).
 */
class IssuedInvoiceForm extends DocsHeadsFormBase
{
    protected function getFormTitle(): string
    {
        return 'Faktura vydaná';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nová faktura vydaná';
    }
}
```

**`modules/docs/invoicesIn/src/ReceivedInvoiceForm.php`:**

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesIn;

use Shipard\Module\Docs\Core\DocsHeadsFormBase;

/**
 * Editační formulář pro Faktury přijaté (FPB) — `doc_type = 'invni'`.
 *
 * Dědí veškerou logiku z DocsHeadsFormBase. V MVP přepisuje pouze titulky;
 * slouží jako rozšiřovací bod pro FPB-specifické změny formuláře
 * (např. schvalovací workflow, vazba na příchozí poštu, AI extrakce,
 * DPH-PDP-specifické přepínače, skrytí polí relevantních jen pro FVB atd.).
 */
class ReceivedInvoiceForm extends DocsHeadsFormBase
{
    protected function getFormTitle(): string
    {
        return 'Faktura přijatá';
    }

    protected function getNewFormTitle(): string
    {
        return 'Nová faktura přijatá';
    }
}
```

---

## Implementace

### 1. `src/Core/Form/FormRegistry.php` — přepis

Přepiš celou třídu podle vzoru v sekci „Architektonická rozhodnutí".
Klíčové změny:

- Konstruktor přijímá pre-merged registrace (`list<array<string, mixed>>`).
- Drop `loadFromModules()` (přesun do `FormLoader::mergeForms()`).
- Drop `getFormClass(string $table): ?string` — semanticky nezvládá
  polymorfismus. Pokud něco mimo `FormController` tuto metodu volá,
  smaž / přepiš to call site na `createForm()` (běž přes celý codebase —
  `grep -rn 'getFormClass' src/ modules/`).
- `createForm(string $table, array $data, ?DataSourceConnection $db, ?ConfigRuntime $config)`
  — implementace podle vzoru v sekci „Architektonická rozhodnutí".

### 2. `src/Api/FormLoader.php` — přepis

Přepiš celou třídu podle vzoru v sekci „Architektonická rozhodnutí" /
`FormLoader::mergeForms()`. Vzorem 1:1 je `DocumentLoader::mergeDocumentClasses()`
v `src/Api/DocumentLoader.php`.

### 3. `src/Api/Controller/FormController.php` — předání `$data`

Tři call sites — všude jen přidat `$data` jako druhý parametr:

```diff
- $tableForm = $formRegistry->createForm($table, $db, $config);
+ $tableForm = $formRegistry->createForm($table, $data, $db, $config);
```

Konkrétně v metodách:
- `resolveFormDefinition()` (private helper)
- `recalculate()` — `$data` je `$body['data']`
- `enrichHeaderInfo()` (private helper) — `$data` je parametr

Před patchováním celý soubor přečti (~700 řádků), patch_file vyžaduje
přesný whitespace.

### 4. Refaktor `modules/docs/core/src/DocsHeadsForm.php`

Postup:

1. Přečti celý `DocsHeadsForm.php` (~500 řádků).
2. Vytvoř nový soubor `modules/docs/core/src/DocsHeadsFormBase.php`:
   - Namespace `Shipard\Module\Docs\Core`
   - `abstract class DocsHeadsFormBase extends TableForm`
   - Zkopíruj veškerý obsah z `DocsHeadsForm` (kromě hlavičky souboru a
     deklarace třídy).
   - Změň všechny `private function` na `protected function`.
   - Přidej `protected function getFormTitle(): string { return 'Doklad'; }`
     a `protected function getNewFormTitle(): string { return 'Nový doklad'; }`.
   - V `buildFormDefinition` nahraď hardcoded `'Doklad'` / `'Nový doklad'`
     za `$this->getFormTitle()` / `$this->getNewFormTitle()`.
3. Přepiš `modules/docs/core/src/DocsHeadsForm.php` na tenkou
   `class DocsHeadsForm extends DocsHeadsFormBase` s doc-commentem
   vysvětlujícím její roli jako defaultClass.

### 5. `modules/docs/invoicesOut/src/IssuedInvoiceForm.php` — nový soubor

Viz „Architektonická rozhodnutí" / sekce IssuedInvoiceForm.

### 6. `modules/docs/invoicesIn/src/ReceivedInvoiceForm.php` — nový soubor

Viz „Architektonická rozhodnutí" / sekce ReceivedInvoiceForm.

### 7. `modules/docs/core/module.jsonc` — update forms[]

Změna:

```diff
 "forms": [
     {
         "table": "docs_core_number_series",
         "class": "Shipard\\Module\\Docs\\Core\\NumberSeriesForm"
     },
     {
         "table": "docs_core_heads",
-        "class": "Shipard\\Module\\Docs\\Core\\DocsHeadsForm"
+        "typeColumn": "doc_type",
+        "defaultClass": "Shipard\\Module\\Docs\\Core\\DocsHeadsForm"
+        // `classes` map is filled by per-type modules (docs.invoicesOut,
+        // docs.invoicesIn) via FormLoader::mergeForms() — same pattern as
+        // documentClasses polymorphism.
     },
     {
         "table": "docs_core_rows",
         "id": "docs.core.rows",
         "class": "Shipard\\Module\\Docs\\Core\\DocRowsForm"
     }
 ],
```

### 8. `modules/docs/invoicesOut/module.jsonc` — přidání forms[]

Přidat novou sekci `forms` vedle stávajících `viewers` / `documentClasses`:

```jsonc
"forms": [
    {
        "table": "docs_core_heads",
        "typeColumn": "doc_type",
        "classes": {
            "invno": "Shipard\\Module\\Docs\\InvoicesOut\\IssuedInvoiceForm"
        }
    }
],
```

### 9. `modules/docs/invoicesIn/module.jsonc` — přidání forms[]

Symetricky:

```jsonc
"forms": [
    {
        "table": "docs_core_heads",
        "typeColumn": "doc_type",
        "classes": {
            "invni": "Shipard\\Module\\Docs\\InvoicesIn\\ReceivedInvoiceForm"
        }
    }
],
```

### 10. README updaty

`modules/docs/invoicesOut/README.md` — v sekci „Co modul přidává" doplnit:

```markdown
- **Editační formulář** `IssuedInvoiceForm extends DocsHeadsFormBase` —
  per-typ formulář pro Faktury vydané. V MVP přepisuje pouze titulky
  („Faktura vydaná" / „Nová faktura vydaná"); slouží jako rozšiřovací
  bod pro budoucí FVB-specifické změny (splátkový kalendář, výzvy k úhradě,
  ...).
```

V sekci „Co modul NEpřidává" **smazat** řádek
`- Žádné nové forms — používáme DocsHeadsForm z docs.core` (už neplatí).

`modules/docs/invoicesIn/README.md` symetricky:

```markdown
- **Editační formulář** `ReceivedInvoiceForm extends DocsHeadsFormBase` —
  per-typ formulář pro Faktury přijaté. V MVP přepisuje pouze titulky
  („Faktura přijatá" / „Nová faktura přijatá"); slouží jako rozšiřovací
  bod pro budoucí FPB-specifické změny (schvalovací workflow, AI extrakce,
  ...).
```

A stejně smazat řádek „Žádné nové forms".

---

## Testy

### `tests/Unit/Api/FormLoaderTest.php` — nový soubor

Zrcadlo `tests/Unit/Api/DocumentLoaderTest.php`. Pokrývá:

- `testMergesTypeColumnRegistrationsFromMultipleModules()` — `docs.core`
  (defaultClass) + `docs.invoicesOut` (`invno`) + `docs.invoicesIn` (`invni`)
  → merged registrace má všechny tři. Test ověří `typeColumn`,
  `defaultClass`, kompletní `classes` map.
- `testSimpleClassRegistrationsPassThroughUnchanged()` — dva moduly s
  prostými `{table, class}` registracemi (různé tabulky) → merged výstup
  obsahuje obě nezměněné.
- `testThrowsOnConflictingTypeColumn()` — dva moduly registrují stejnou
  tabulku s různými `typeColumn` → `\LogicException`.
- `testThrowsOnDuplicateClassesEntry()` — dva moduly registrují stejný
  `(table, typeKey)` s různými classes → `\LogicException`.
- `testIdenticalDuplicateClassesEntryIsAllowed()` — dva moduly registrují
  stejný `(table, typeKey)` se stejnou class → idempotentní, merge projde.
- `testRegistrationWithoutTableIsSkipped()` — registrace bez `table`
  silently skipnutá.
- `testIdFieldIsPreserved()` — `{table, class, id}` registrace prochází
  s `id` zachovaným (kompat s `subtable.form_id` referencí).

**Šablona:** kopíruj strukturu `DocumentLoaderTest.php` (helper metody
`module()` a `indexByTable()`), jen přejmenuj na `forms` místo
`documentClasses`.

### `tests/Unit/Core/Form/FormRegistryTest.php` — nový soubor

Pokrývá `createForm()` dispatch:

- `testCreatesSimpleClassForm()` — `{table, class}` registrace bez
  typeColumn → `createForm($table, [], ...)` vrátí instanci dané třídy.
- `testDispatchesByTypeColumn()` — typeColumn-based registrace s `classes`
  map → `createForm($table, ['doc_type' => 'invno'], ...)` vrátí
  `IssuedInvoiceForm`, `createForm($table, ['doc_type' => 'invni'], ...)`
  vrátí `ReceivedInvoiceForm`.
- `testFallsBackToDefaultClassForUnknownType()` — typeColumn-based
  registrace s nereexistujícím `doc_type` v `$data` → `defaultClass`.
- `testFallsBackToDefaultClassForMissingTypeKey()` — `$data` neobsahuje
  klíč `doc_type` vůbec → `defaultClass`.
- `testReturnsNullForUnregisteredTable()` — `createForm('unknown_table', [], ...)`
  → `null`.
- `testReturnsNullForNonexistentClass()` — registrace ukazuje na třídu,
  která neexistuje → `null` (defensive).
- `testGetFormIdReturnsRegisteredId()` — `{table, class, id}` registrace
  → `getFormId($table)` vrátí `id`. Pro tabulku bez `id` vrátí `null`.

Použij stub `TableForm` třídy přímo v test souboru (nebo v
`tests/Fixtures/Core/Form/`), aby test nevisel na produkčních formulářích.

### Sanity ověření

- `vendor/bin/phpunit 2>&1` — všechny existující testy musí dál procházet,
  zejména testy, které volají `FormController` nebo `FormRegistry`
  nepřímo (např. testy `PersonsForm`, integration testy formulářů).
- Manuální smoke test po `ds-upgrade`:
  - Otevři viewer „Faktury vydané" → existující FVB → titulek modalu
    „Faktura vydaná"
  - Klik „Přidat" v „Faktury vydané" → titulek „Nová faktura vydaná"
  - Otevři viewer „Faktury přijaté" → existující FPB → titulek
    „Faktura přijatá"
  - Klik „Přidat" v „Faktury přijaté" → titulek „Nová faktura přijatá"
  - Otevři generický viewer „Doklady" → FVB → stále titulek
    „Faktura vydaná" (dispatch funguje i z generického vieweru, protože
    data v DB mají `doc_type='invno'`)
  - Otevři generický viewer „Doklady" → FPB → titulek „Faktura přijatá"
  - Klik „Přidat" v generickém „Doklady" → titulek „Nový doklad"
    (defaultClass — bez per-typ hintu)
  - Existující záznam jiné tabulky (Osoba, Položka, Číselná řada) → modal
    se otevře normálně, žádná regrese

---

## Dokumentace

### `docs/edit-forms.md` — nová sekce 23

Přidej za kapitolu 22 (Lookup pole) novou kapitolu **„23. Polymorfní
dispatch formulářů přes `typeColumn`"**. Obsah pokryje:

- **Kdy použít** — tabulky, kde jeden physický řádek může reprezentovat
  více logických typů (typicky `docs_core_heads` s `doc_type`), a per-typ
  chování formuláře se má lišit (titulky, sekce, validace). Pro tabulky
  s jediným typem dál stačí prostý `{table, class}` zápis.
- **Registrace v `module.jsonc`** — vzor pro tři moduly:

  ```jsonc
  // docs.core (vlastní tabulky + default)
  "forms": [
      {
          "table": "docs_core_heads",
          "typeColumn": "doc_type",
          "defaultClass": "Shipard\\Module\\Docs\\Core\\DocsHeadsForm"
      }
  ]

  // docs.invoicesOut (per-typ subclass)
  "forms": [
      {
          "table": "docs_core_heads",
          "typeColumn": "doc_type",
          "classes": {
              "invno": "Shipard\\Module\\Docs\\InvoicesOut\\IssuedInvoiceForm"
          }
      }
  ]

  // docs.invoicesIn (per-typ subclass)
  "forms": [
      {
          "table": "docs_core_heads",
          "typeColumn": "doc_type",
          "classes": {
              "invni": "Shipard\\Module\\Docs\\InvoicesIn\\ReceivedInvoiceForm"
          }
      }
  ]
  ```

- **Dispatch pravidla** v `FormRegistry::createForm($table, $data, ...)`:
  - Pokud má registrace `typeColumn`: vyhodnotí se `$data[$typeColumn]`,
    výsledek se hledá v mapě `classes`. Pokud klíč neexistuje, fallback
    na `defaultClass`. Pokud neexistuje ani `defaultClass`, vrátí `null`.
  - Pokud má registrace prostý `class`: vrátí ho bez ohledu na `$data`.
  - Tabulka bez registrace v `FormRegistry` → fallback na JSONC formulář
    (`forms/{table}.jsonc`) nebo `AutoFormBuilder` (viz kap. 12).
- **Slévání napříč moduly** — `FormLoader::mergeForms()` (paralela
  `DocumentLoader::mergeDocumentClasses()`):
  - `typeColumn` musí být shodný (jinak `LogicException`)
  - `classes` mapy se mergují, kolize klíčů s různými hodnotami →
    `LogicException`, identická hodnota → idempotentní průchod
  - `defaultClass` first-wins
- **Vztah k `DocumentRegistry`** — formulářová polymorfizace zrcadlí
  `DocumentRegistry` 1:1. Když přidáváš nový typ dokladu (např. proforma
  faktura), přidáváš typicky tři věci společně:
  - `documentClasses` entry pro per-typ `Document` třídu
  - `forms` entry pro per-typ `Form` třídu
  - `viewers` entry pro per-typ filtered viewer
- **Hierarchie tříd** — vzor pro per-typ rodiny:
  ```
  TableForm (abstract, core)
      └── DocsHeadsFormBase (abstract, docs.core)
              ├── DocsHeadsForm        (docs.core)        — defaultClass
              ├── IssuedInvoiceForm    (docs.invoicesOut) — invno
              └── ReceivedInvoiceForm  (docs.invoicesIn)  — invni
  ```
  Společná logika v base, subclassy jen overridují, co se má lišit
  (titulky, případně jednotlivé `buildXxxTab()` metody, případně
  `applyClientDefaults`).
- **`$data` pro nový záznam** — per-typ viewer poskytuje
  `getNewRecordDefaults()` (např. `{doc_type: 'invno'}`), `FormController`
  to spojí s column defaults a předá do `createForm($table, $data, ...)`.
  Dispatch tedy funguje i pro nový záznam otevřený z per-typ vieweru. Pro
  nový záznam z generického vieweru bez hintu se použije `defaultClass`.

### Updaty v `docs/edit-forms.md`

- **Kapitola 11 (`TableForm`)** — zmínit, že `createForm()` je nově
  data-aware a v subclasech base třídy lze override-ovat `getFormTitle()`
  a `getNewFormTitle()` (pokud se `DocsHeadsFormBase` přístup stane
  vzorem).
- **Kapitola 13 (Registrace v `module.jsonc`)** — přidat krátký odkaz na
  kap. 23 pro polymorfní zápis.
- **Kapitola 17 (PHP třídy a soubory)** — aktualizovat popis
  `FormRegistry` a `FormLoader`:
  - `FormRegistry` — registry PHP tříd formulářů, podporuje per-table
    polymorfismus přes `typeColumn` + `classes` + `defaultClass`
  - `FormLoader` — načte registrace ze všech modulů, slévá per-table
    přes `mergeForms()`

### `CLAUDE.md`

V sekci „Editační formuláře — `select` vs `lookup`" doplň druhou pod-položku:

```markdown
### Editační formuláře — polymorfismus per typ
- Formuláře nad polymorfní tabulkou (např. `docs_core_heads` s `doc_type`)
  se registrují přes `typeColumn` + `classes` + `defaultClass` v
  `module.jsonc` → `forms[]`. `FormLoader::mergeForms()` slévá registrace
  z více modulů per-table (paralela k `DocumentLoader::mergeDocumentClasses()`).
- Vzor: `docs.core` registruje `DocsHeadsForm` jako defaultClass,
  `docs.invoicesOut` přidává `invno → IssuedInvoiceForm`, `docs.invoicesIn`
  přidává `invni → ReceivedInvoiceForm`. Hierarchie tříd:
  `TableForm → DocsHeadsFormBase → {DocsHeadsForm, IssuedInvoiceForm, ReceivedInvoiceForm}`.
- Per-typ subclassy jsou tenké — overridují jen, co se má lišit (titulky,
  případně jednotlivé `buildXxxTab()` metody). Společná logika žije v base.
- Detailně viz `docs/edit-forms.md` kapitola 23.
```

---

## Hotovo když

- [ ] `vendor/bin/phpunit 2>&1` — všechny testy procházejí (existující + nové
      `FormLoaderTest` + `FormRegistryTest`)
- [ ] `cd frontend && npm run build 2>&1` — projde bez chyb / warningů
      (žádné změny ve frontend kódu nejsou potřeba, ale ověř, že nic
      nepukne — frontend čte `formDefinition.title` / `title_new`, ne
      class names, takže by mělo být OK)
- [ ] `bin/shpd-ds ds-upgrade` projde bez chyb (žádné nové tabulky, jen
      registrace tříd)
- [ ] Otevření existující FVB v jakémkoli vieweru → nadpis „Faktura vydaná"
- [ ] Otevření existující FPB v jakémkoli vieweru → nadpis „Faktura přijatá"
- [ ] Klik „Přidat" v per-typ vieweru „Faktury vydané" → nadpis
      „Nová faktura vydaná", `number_series` dropdown filtrovaný na řady
      typu `invno`
- [ ] Klik „Přidat" v per-typ vieweru „Faktury přijaté" → „Nová faktura
      přijatá", filtrovaný na `invni`
- [ ] Klik „Přidat" v generickém „Doklady" → nadpis „Nový doklad"
      (defaultClass `DocsHeadsForm`)
- [ ] Doklad s neznámým / prázdným `doc_type` (kdyby vznikl) → otevře se
      přes `DocsHeadsForm` (defaultClass)
- [ ] Otevření Osoby, Položky, Číselné řady, Mailu atd. → žádná regrese
      (`{table, class}` registrace fungují dál)
- [ ] Recalculate v IssuedInvoiceForm / ReceivedInvoiceForm funguje stejně
      jako dnes v DocsHeadsForm (cascade reset adres/banky při změně
      partnera, dopočítání due_date, …)
- [ ] Per-typ validace v Document subclasses (`IssuedInvoiceDocument`
      kontrola `bank_account`, `ReceivedInvoiceDocument` kontrola
      `partner_bank*`) dál funguje — žádný overlap / regrese s form
      dispatchem
- [ ] `modules/docs/invoicesIn/README.md` a
      `modules/docs/invoicesOut/README.md` aktualizované (nemají větu
      „Žádné nové forms", mají popis `*InvoiceForm`)
- [ ] `docs/edit-forms.md` má novou sekci 23 a updaty 11/13/17
- [ ] `CLAUDE.md` zmiňuje polymorfismus formulářů s odkazem na sekci 23

---

## Konvence

- **Jazyk:** UI texty (titulky formulářů) česky, kód a komentáře v PHP
  anglicky, doc-comments u tříd česky pro vysvětlení business kontextu
  (konzistentní s existujícími Document/Viewer subclasses v
  `docs.invoicesOut` / `docs.invoicesIn`)
- **PHP 8.3+** strict_types, readonly properties kde to dává smysl,
  named arguments u FormDefinition konstruktoru
- **Naming:** `IssuedInvoiceForm` / `ReceivedInvoiceForm` (singulár,
  konzistentní s `IssuedInvoiceDocument` / `ReceivedInvoiceDocument`;
  na rozdíl od viewerů, které jsou plurál — to je už zaběhnuté)
- **Per-typ subclassy jsou v MVP tenké** — gros logiky žije v base třídě.
  Subclassy přidávají jen specifický override (`getFormTitle` v MVP).
  Pokud objevíš během refaktoru „natural" společnou logiku napříč typy,
  patří do `DocsHeadsFormBase`.
- **Před patchováním Form/Controller souborů přečíst celý soubor** —
  `patch_file` vyžaduje přesný whitespace, em-dashes a české znaky
  v komentářích si zaslouží opatrnost
- **Backwards compat** — všechny existující registrace `{table, class}`
  musí dál fungovat beze změny. To je tvrdé akceptační kritérium —
  pokud po refaktoru `PersonsForm` nefunguje, refaktor je špatně.

---

## Doporučené pořadí implementace

1. **`FormRegistry` přepis** — nový konstruktor (přijímá merged
   registrace), nový `createForm($table, $data, ...)`, drop
   `getFormClass` / `loadFromModules`.
2. **`FormLoader::mergeForms()`** — implementace paralelní
   `DocumentLoader::mergeDocumentClasses()`. Unit testy
   (`FormLoaderTest.php`) — krok 1+2 lze udělat společně, protože
   FormLoader bez nového FormRegistry konstruktoru neprojde syntaktickou
   kontrolou.
3. **`FormRegistryTest`** — unit testy dispatch logiky.
4. **`FormController` update** — předat `$data` do tří call sites
   `createForm`. Spustit phpunit — všechny existující testy musí dál
   procházet (zejména `FormController` integration testy, pokud existují,
   a `PersonsForm` testy).
5. **Sanity smoke test po krocích 1–4** — `ds-upgrade` projde, generický
   viewer „Doklady" funguje s `DocsHeadsForm` jako dosud (žádná
   změna chování, jen jiný dispatch mechanismus za scénou).
6. **Refaktor `DocsHeadsForm` → `DocsHeadsFormBase` + `DocsHeadsForm`**:
   - Vytvoř `DocsHeadsFormBase.php` jako kopii `DocsHeadsForm` s
     abstract class, protected metodami a `getFormTitle()` / `getNewFormTitle()`.
   - Přepiš `DocsHeadsForm.php` na tenkou subclass.
   - Spustit phpunit — žádná regrese. Manuálně otevřít doklad přes
     generický viewer, ověřit identické chování.
7. **`docs.core/module.jsonc`** — přepnutí na `typeColumn + defaultClass`.
   Manuální smoke test — generický viewer dál funguje.
8. **`IssuedInvoiceForm`** — nový soubor, registrace v
   `docs.invoicesOut/module.jsonc`. Smoke test: otevřít FVB v per-typ
   vieweru → titulek „Faktura vydaná".
9. **`ReceivedInvoiceForm`** — symetricky pro `docs.invoicesIn`.
10. **README updaty** v obou modulech.
11. **`docs/edit-forms.md`** sekce 23 + updaty 11/13/17.
12. **`CLAUDE.md`** zmínka.
13. **Závěrečný full sanity test** — projít všechny scénáře z „Hotovo
    když" checklistu.

---

## Mimo rozsah (znovu zdůrazněno)

- Implementace skutečných per-typ rozdílů ve formuláři (skrytí polí,
  přidání sekcí, custom validace v rámci formuláře). MVP tasku má jen
  připravit hierarchii — divergování přijde v samostatných taskech.
- Polymorfismus pro JSONC formuláře nebo `AutoFormBuilder`. Type-column
  dispatch je výhradně pro PHP `TableForm` subclassy.
- Spodní tab bar s číselnými řadami v per-typ viewerech (samostatný
  frontend task — viz „Otevřené body" v `tasks/docs-invoices.md`).
- Lokalizace titulků (`„Faktura vydaná"` / `„Issued invoice"`) přes
  cfgItem nebo i18n vrstvu — texty zůstávají v PHP, českou variantu
  hardcoduje subclass override `getFormTitle()`. I18n pro PHP texty je
  samostatná iterace.

---

## Otevřené body

- **`recalculate` a polymorfismus:** `recalculate` v `FormController`
  předává `$data` do `createForm($table, $data, ...)`, ale `$data` obsahuje
  aktuální stav formuláře z requestu (před recalculate, ale po user
  interakci). Pokud by uživatel za běhu změnil `doc_type` (což by neměl
  smysl, ale teoreticky…) → vrátil by se jiný `TableForm`. V praxi
  `doc_type` v UI neměnitelné po vytvoření, takže to nehrozí. Ale stojí
  za zmínku v sekci 23 dokumentace — implicitní invariant: `doc_type` je
  per-záznam fixní po vzniku.
- **Form id (`id`) v polymorfních registracích:** task `id` ignoruje
  v polymorfních registracích. Pokud bys potřeboval per-typ `formId` pro
  něco jako subtable form referenci, dořešilo by se v navazujícím tasku
  (pravděpodobně přes `idsByType: {invno: 'docs.invoicesOut.head', ...}`).
  Není teď use case.
