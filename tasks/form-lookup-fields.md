# Task: Lookup pole ve formulářích — typeahead pro FK na velké tabulky

## Motivace

Dnes se odkazové (FK) sloupce ve formulářích řeší jako `select` s předem
natáhnutými `options[]`. Server v `DocsHeadsForm::resolvePartnerOptions()`
provede `SELECT id, full_name FROM base_persons_persons LIMIT 500` a celý
seznam vloží do `FormDefinition`. Pro pár desítek záznamů (číselné řady,
číselník bank…) je to v pořádku. Pro **Osoby** je to ale nepoužitelné:

- seznam roste, payload roste, render se zpomaluje
- nativní `<select>` neumí rozumně hledat — uživatel scrolluje pět set
  záznamů, aby našel partnera
- u 500+ osob limit oseká data a uživatel ani nezjistí, že hledaná osoba
  v seznamu chybí

Kanonický příklad — Partner na faktuře přijaté:

```
┌─────────────────────────────────────────────────────────────────┐
│ Partner   │ Testování 999                              [×]   ▾  │
│           │ IČO 12345678                                        │
└─────────────────────────────────────────────────────────────────┘
        ↓ (klik / fokus / psaní)
┌─────────────────────────────────────────────────────────────────┐
│ Partner   │ teszt|                                              │
│           ├─────────────────────────────────────────────────────┤
│           │ Testování 22                                        │
│           │   IČO 87654321                                      │
│           │ Testování 777                                       │
│           │   IČO 11122233                                      │
│           │ Testování 999             ← aktuální výběr          │
│           │   IČO 12345678                                      │
│           │ Test Odběratel test                                 │
│           │   IČO 99988877                                      │
└─────────────────────────────────────────────────────────────────┘
```

Tento task zavádí **nový typ elementu `lookup`** + backend endpoint, kde
si klient hledá záznamy průběžně. UX vzor je inline combobox (žádný druhý
modal nad formulářem). Mechanismus pak budou postupně používat všechna
podobná pole — v této fázi se portuje hlavička dokladu (`partner`,
`partner_address`, `partner_bank`); item lookup v řádcích je v navazujícím
tasku.

---

## Před implementací přečti

- `docs/edit-forms.md` — celá architektura formulářů, zejména:
  - kapitola 3 (FormDefinition — datová struktura)
  - kapitola 4 (Elementy formuláře — přidává se nový typ)
  - kapitola 7 (Recalculate — dynamické přepočítání, cascade filterů)
  - kapitola 11 (PHP třída TableForm + TabBuilder)
  - kapitola 12 (JSONC definice)
  - kapitola 16 (Svelte komponenty)
- `src/Core/Form/FormElement.php` — `ALLOWED_TYPES` whitelist, konstruktor
  validace
- `src/Core/Form/TabBuilder.php` — fluent API (`->select()`, `->input()`, …)
- `src/Core/Form/JsoncFormLoader.php` — `buildElement()` + detekce legacy
  formátu
- `src/Api/Controller/FormController.php` — `meta`, `save`, `recalculate` —
  vidíme, jak je struktura odpovědi `{formDefinition, data}`
- `src/Api/Router.php` — `resolveFormRoute()` jako vzor pro lookup routing
- `frontend/src/components/form/FormElement.svelte` — switch podle `type`,
  vidíme jak se přidává nová větev
- `frontend/src/components/form/FormEditor.svelte` — drží `formDef`,
  `formData`, propagace dirty stavu — bude držet i `dataResolved`
- `frontend/src/components/ui/EntityPicker.svelte` — **NE pro tuto fázi.**
  Je to modal-based picker pro exchange resoluci; v lookup polích
  formulářů ho nebudeme používat. Lookup komponenta je vlastní, inline.
  Zachovej ho beze změny.
- `modules/docs/core/src/DocsHeadsForm.php` — `resolvePartnerOptions`,
  `resolvePartnerAddressOptions`, `resolvePartnerBankOptions` a
  `recalculate()` — tato místa se po portu zjednoduší
- `modules/base/persons/module.jsonc` — sem se přidá `lookups[]` registrace

---

## Cíl

Po dokončení tohoto tasku platí:

- Nový typ formulářového elementu `"lookup"` se serverem-zapečenou
  konfigurací (cílová tabulka, filter, placeholder)
- Nový endpoint `GET /_ui/lookup/{table}/search` pro průběžné vyhledávání
- Nový endpoint `GET /_ui/lookup/{table}/resolve` pro načtení display
  popisu konkrétních ID (klient ho používá výjimečně — typicky se hodnoty
  pre-resolvují serverem v meta/save/recalculate response)
- Abstraktní třída `TableLookup` s konkrétními implementacemi pro
  `PersonsLookup`, `AddressesLookup`, `BankAccountsLookup`
- `FormController` v `meta`, `save`, `recalculate` doplňuje top-level
  `dataResolved` paralelně k `data` — klient ví, jak zobrazit vybrané
  hodnoty bez extra round-tripu
- Frontend `LookupInput.svelte` — inline combobox s debounce searchem,
  klávesnicovou navigací, sekundárním řádkem v dropdownu
- `DocsHeadsForm` má `partner`, `partner_address`, `partner_bank`
  předělaný ze `select` na `lookup`. Cascade (změna partnera → filter
  pro adresu/banku) jde dál přes `recalculate` — žádný nový mechanismus
- Stávající `Select`/`select` typ zůstává netknutý a slouží dál pro enumy
  a malé `cfgItem`-based číselníky
- `docs/edit-forms.md` má novou kapitolu „22. Lookup pole"
- `CLAUDE.md` má krátkou zmínku v sekci o formulářích

---

## Wire formát (datový kontrakt)

### Nový typ elementu

```json
{
    "type": "lookup",
    "column": "partner",
    "label": "Partner",
    "required": false,
    "hidden": false,
    "read_only": false,
    "triggers": "reload",
    "placeholder": "Hledat partnera…",
    "lookup": {
        "table": "base_persons_persons",
        "filter": null
    }
}
```

Cascade-filtrovaný variant (po vybrání partnera):

```json
{
    "type": "lookup",
    "column": "partner_address",
    "label": "Adresa partnera",
    "lookup": {
        "table": "base_persons_addresses",
        "filter": {"person": 42}
    }
}
```

Pole uvnitř `lookup`:

| Klíč | Typ | Popis |
|------|-----|-------|
| `table` | string | DB název cílové tabulky |
| `filter` | object \| null | Server-zapečené filtry (např. `{person: 42}`). Frontend je předá do query stringu volání. |

Pravidla:

- `lookup` element **nemůže** být uvnitř `inline` skupiny (analogie s
  `select`/inline — povolujeme jen primitivní inputy). Validace v
  `FormElement` konstruktoru.
- `lookup` element neexistuje uvnitř `attachments` ani `subtable` tabu —
  ty mají vlastní rendering.

### Endpoint — search

```
GET /_ui/lookup/{table}/search?q={term}&limit={n}&filter[col]={val}
```

Parametry:

| Parametr | Default | Limity |
|----------|---------|--------|
| `q` | `""` | Prázdné = první stránka záznamů (browseable) |
| `limit` | 20 | Max 50; větší se sřízne, log warning |
| `filter[<col>]` | — | Whitelistovaný `TableLookup::getAllowedFilterKeys()`; ostatní se silently ignorují |

Response:

```json
{
    "success": true,
    "data": {
        "items": [
            {"id": 42, "primary": "Testování 999", "secondary": "IČO 12345678"},
            {"id": 17, "primary": "Testování 22",  "secondary": "IČO 87654321"}
        ],
        "limit": 20,
        "total": null
    }
}
```

- `items[].id` může být int nebo string (FK typu enumString); nemodelujeme,
  bere se z DB jak je
- `items[].secondary` může být `null` (např. fyzická osoba bez data
  narození) — frontend pak druhý řádek nevykreslí
- `total` je vždy `null` v MVP (nepočítáme — paginate-friendly žádný klient
  zatím nepotřebuje); zachováváme klíč pro budoucí rozšíření

Chyby:

```json
{"success": false, "error": {"code": "LOOKUP_NOT_REGISTERED",
                              "message": "No TableLookup registered for 'foo_bar'"}}
```

Kódy: `LOOKUP_NOT_REGISTERED` (404), `TABLE_NOT_FOUND` (404),
`BAD_REQUEST` (400 pro neplatné parametry — záporný limit, q překračující
nějaký rozumný strop atd.).

### Endpoint — resolve

```
GET /_ui/lookup/{table}/resolve?ids=42,17,3
```

Vrátí display popis pro konkrétní ID. Klient ho typicky nevolá — používá
se v okrajových situacích (např. pokud nějak dataResolved zmizí z lokálního
stavu). Response je stejný tvar jako search bez `total`.

Pokud nějaké ID neexistuje, prostě se v `items[]` vynechá — žádná chyba.

### Pre-resolved data v meta/save/recalculate response

`FormController::meta` (pro existující záznam):

```json
{
    "success": true,
    "data": {
        "formDefinition": { ... },
        "data": {
            "partner": 42,
            "partner_address": 17,
            "partner_bank": null,
            "issue_date": "2025-05-18"
        },
        "dataResolved": {
            "partner":         {"id": 42, "primary": "Testování 999",  "secondary": "IČO 12345678"},
            "partner_address": {"id": 17, "primary": "Hlavní 12, Praha", "secondary": null}
        }
    }
}
```

- `dataResolved` je vždy přítomné (i pro nový záznam — pak je `{}`)
- Klíče jsou pouze ty `column` z lookup elementů, kde má `data[column]`
  ne-null hodnotu a kde resolve uspěl (záznam v cílové tabulce existuje)
- Klíče se NEpřidávají pro lookup elementy, kde je `data[column]` null
- camelCase top-level (`dataResolved`) — drží konzistenci s
  `formDefinition`

Stejná struktura je v response z `save` a `recalculate` endpointů.

---

## Backend — datové třídy

### 1. `src/Core/Form/Lookup/LookupItem.php` (nový)

```php
<?php

declare(strict_types=1);

namespace Shipard\Core\Form\Lookup;

/**
 * Display popis jedné položky v lookup výsledku.
 *
 * `primary` je hlavní řádek (např. „Testování 999"), `secondary` je
 * volitelný caption pod ním (např. „IČO 12345678" nebo „Datum narození
 * 14.05.1990"). Strukturu řeší konkrétní TableLookup — frontend ji jen
 * renderuje.
 */
final class LookupItem
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $primary,
        public readonly ?string $secondary = null,
    ) {}

    /**
     * @return array{id: int|string, primary: string, secondary: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'primary' => $this->primary,
            'secondary' => $this->secondary,
        ];
    }
}
```

### 2. `src/Core/Form/Lookup/TableLookup.php` (nový)

```php
<?php

declare(strict_types=1);

namespace Shipard\Core\Form\Lookup;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;

/**
 * Abstraktní bázová třída pro lookup endpoint na konkrétní tabulce.
 *
 * Konkrétní implementace (např. `PersonsLookup`) sahá do své tabulky,
 * sestaví SQL hledání podle `$q`, případně aplikuje whitelistované
 * filtery (`getAllowedFilterKeys`) a vrátí `LookupItem[]` s display popisem.
 *
 * Registrace v `module.jsonc` → `lookups: [{table, class}]`. Načítá
 * `LookupLoader` (analogie `FormLoader`).
 */
abstract class TableLookup
{
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConnection $db = null;
    protected ?TableDefinition $tableDef = null;

    final public function setDb(DataSourceConnection $db): void { $this->db = $db; }
    final public function setConfig(?ConfigRuntime $config): void { $this->config = $config; }
    final public function setTableDef(TableDefinition $def): void { $this->tableDef = $def; }

    /**
     * Hledá záznamy podle volného textu.
     *
     * @param string                  $q      Volně psaný term; prázdný = první stránka záznamů
     * @param array<string, scalar>   $filter Whitelistované filter páry (sloupec → hodnota)
     * @param int                     $limit  1..50; controller už hodnotu sřízl
     * @return list<LookupItem>
     */
    abstract public function search(string $q, array $filter, int $limit): array;

    /**
     * Vrátí display popisy pro seznam ID.
     *
     * Pořadí výstupu nemusí odpovídat vstupu. Neexistující ID se prostě
     * v poli nevyskytnou — žádná chyba.
     *
     * @param list<int|string> $ids
     * @return list<LookupItem>
     */
    abstract public function resolve(array $ids): array;

    /**
     * Whitelist filter klíčů, které smí klient v `?filter[…]` poslat.
     * Default: žádné. Subclassy s cascade overridují.
     *
     * @return list<string>
     */
    public function getAllowedFilterKeys(): array
    {
        return [];
    }
}
```

### 3. `src/Core/Form/Lookup/LookupRegistry.php` (nový)

Analogie `FormRegistry`:

```php
<?php

declare(strict_types=1);

namespace Shipard\Core\Form\Lookup;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;

class LookupRegistry
{
    /** @var array<string, class-string<TableLookup>> table => class */
    private array $map = [];

    public function register(string $table, string $class): void
    {
        if (!is_subclass_of($class, TableLookup::class)) {
            throw new \InvalidArgumentException(sprintf(
                'Class %s is not a TableLookup', $class,
            ));
        }
        $this->map[$table] = $class;
    }

    public function has(string $table): bool
    {
        return isset($this->map[$table]);
    }

    public function create(
        string $table,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        ?TableDefinition $tableDef,
    ): ?TableLookup {
        $class = $this->map[$table] ?? null;
        if ($class === null) {
            return null;
        }
        $instance = new $class();
        $instance->setDb($db);
        $instance->setConfig($config);
        if ($tableDef !== null) {
            $instance->setTableDef($tableDef);
        }
        return $instance;
    }
}
```

### 4. `src/Api/LookupLoader.php` (nový)

Analogie `FormLoader`. Čte `module.jsonc` všech modulů a hledá klíč
`lookups: [{table, class}]`. Vrátí naplněný `LookupRegistry`.

Skeleton — kopíruj strukturu z existujícího `FormLoader.php`:

```php
<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Module\ModulePathResolver;

class LookupLoader
{
    public static function load(
        ConfigRuntime $config,
        ModulePathResolver $resolver,
    ): LookupRegistry {
        $registry = new LookupRegistry();
        // Iterate $resolver->allModuleIds(), read $moduleDir/module.jsonc,
        // pull out $module['lookups'] (array of {table, class}), call
        // $registry->register(...). Same pattern as FormLoader.
        // ...
        return $registry;
    }
}
```

---

## Backend — element + builder

### 5. `FormElement` rozšíření

**Soubor:** `src/Core/Form/FormElement.php`

- Přidat `'lookup'` do `ALLOWED_TYPES`
- Přidat nový pole konstruktoru: `public readonly ?array $lookup = null`
  s tvarem `['table' => string, 'filter' => array|null]`
- Validace v konstruktoru:
  - pokud `type === 'lookup'`: `$lookup['table']` musí být neprázdný string
    a `$column` musí být zadaný
  - pokud `type === 'lookup'`: prvek nesmí být uvnitř `inline` (analogie s
    existujícími pravidly v `TabBuilder::pushElement`)
- `toArray()`: pokud `$lookup !== null`, přidá klíč `lookup` do výstupu.
  `filter` se serializuje jako `null` pokud je prázdný, jinak jako objekt
  (mapa `col → val`). Pro JSON je to přirozené.

### 6. `TabBuilder` rozšíření

**Soubor:** `src/Core/Form/TabBuilder.php`

Přidat metodu:

```php
public function lookup(
    string $column,
    string $table,
    ?array $filter = null,
    ?string $label = null,
    ?string $placeholder = null,
    bool $required = false,
    bool $readOnly = false,
    bool $hidden = false,
    ?string $triggers = null,
    ?string $hint = null,
): static {
    $this->pushElement(new FormElement(
        type: 'lookup',
        column: $column,
        label: $this->resolveLabel($column, $label),
        placeholder: $placeholder,
        required: $required,
        readOnly: $readOnly,
        hidden: $hidden,
        triggers: $triggers,
        hint: $hint,
        lookup: ['table' => $table, 'filter' => $filter],
    ));
    return $this;
}
```

`pushElement` v `TabBuilder` už zamítá ne-input/select uvnitř inline —
přidat `'lookup'` taky NEdo `INLINE_INNER_ALLOWED_TYPES` (zůstane jen
`input`, `select`). Tím je to ošetřené.

### 7. `JsoncFormLoader` rozšíření

**Soubor:** `src/Core/Form/JsoncFormLoader.php`

V `buildElement()` — pokud `type === 'lookup'`:
- vytáhnout `lookup` klíč z `$elData` (camelCase v JSONC source)
- validovat strukturu (`table` non-empty string, `filter` array|null)
- předat do `FormElement` konstruktoru jako `lookup: [...]`

Příklad JSONC:

```jsonc
{
    "type": "lookup",
    "column": "partner",
    "lookup": {
        "table": "base_persons_persons"
    }
}
```

`filter` u deklarativního JSONC v drtivé většině nemá smysl psát staticky
— filtry se generují v `recalculate` (PHP form). Pro úplnost ale loader
přijímá `filter: {col: val}` taky.

---

## Backend — controller a routing

### 8. `src/Api/Controller/LookupController.php` (nový)

```php
<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\Lookup\LookupRegistry;

class LookupController
{
    /**
     * GET /_ui/lookup/{table}/search?q=...&limit=...&filter[col]=...
     *
     * @param array<string, TableDefinition> $tables
     */
    public function search(
        string $table,
        Request $request,
        array $tables,
        DataSourceConnection $db,
        LookupRegistry $lookupRegistry,
        ?ConfigRuntime $config,
    ): Response {
        $def = $tables[$table] ?? null;
        if ($def === null) {
            return Response::error('TABLE_NOT_FOUND', "Table '{$table}' not found", 404);
        }

        $lookup = $lookupRegistry->create($table, $db, $config, $def);
        if ($lookup === null) {
            return Response::error(
                'LOOKUP_NOT_REGISTERED',
                "No TableLookup registered for '{$table}'",
                404,
            );
        }

        $qp = $request->getQueryParams();
        $q  = (string) ($qp['q'] ?? '');
        $limit = max(1, min(50, (int) ($qp['limit'] ?? 20)));

        $filterIn = $qp['filter'] ?? [];
        $filter = $this->validateFilter(is_array($filterIn) ? $filterIn : [], $lookup->getAllowedFilterKeys());

        $items = $lookup->search($q, $filter, $limit);

        return Response::success([
            'items' => array_map(fn($i) => $i->toArray(), $items),
            'limit' => $limit,
            'total' => null,
        ]);
    }

    /**
     * GET /_ui/lookup/{table}/resolve?ids=42,17,3
     *
     * @param array<string, TableDefinition> $tables
     */
    public function resolve(
        string $table,
        Request $request,
        array $tables,
        DataSourceConnection $db,
        LookupRegistry $lookupRegistry,
        ?ConfigRuntime $config,
    ): Response {
        $def = $tables[$table] ?? null;
        if ($def === null) {
            return Response::error('TABLE_NOT_FOUND', "Table '{$table}' not found", 404);
        }

        $lookup = $lookupRegistry->create($table, $db, $config, $def);
        if ($lookup === null) {
            return Response::error(
                'LOOKUP_NOT_REGISTERED',
                "No TableLookup registered for '{$table}'",
                404,
            );
        }

        $qp = $request->getQueryParams();
        $idsRaw = (string) ($qp['ids'] ?? '');
        $ids = $this->parseIds($idsRaw);
        if ($ids === []) {
            return Response::success(['items' => []]);
        }

        $items = $lookup->resolve($ids);
        return Response::success([
            'items' => array_map(fn($i) => $i->toArray(), $items),
        ]);
    }

    /**
     * @param array<string, mixed> $filterIn
     * @param list<string> $allowedKeys
     * @return array<string, scalar>
     */
    private function validateFilter(array $filterIn, array $allowedKeys): array
    {
        $result = [];
        $allowed = array_flip($allowedKeys);
        foreach ($filterIn as $k => $v) {
            $k = (string) $k;
            if (!isset($allowed[$k])) {
                continue; // silently ignore unknown keys
            }
            if (!is_scalar($v)) {
                continue; // only scalars allowed
            }
            $result[$k] = $v;
        }
        return $result;
    }

    /**
     * @return list<int|string>
     */
    private function parseIds(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $parts = array_filter(array_map('trim', explode(',', $raw)), fn($p) => $p !== '');
        // Cast pure-digit parts to int (typical FK case), keep others as
        // string (FK on enumString columns).
        return array_map(
            fn($p) => ctype_digit($p) ? (int) $p : $p,
            array_values($parts),
        );
    }
}
```

### 9. Routing

**Soubor:** `src/Api/Router.php`

V hlavním `resolve()` po existující `if (str_starts_with($subpath, '/_ui/form/'))`:

```php
if (str_starts_with($subpath, '/_ui/lookup/')) {
    return $this->resolveLookupRoute($subpath, $method);
}
```

Nová metoda:

```php
private function resolveLookupRoute(string $subpath, string $method): Route|Response
{
    $rest = substr($subpath, strlen('/_ui/lookup/'));
    $parts = explode('/', $rest);
    if (count($parts) !== 2 || $parts[0] === '') {
        return Response::error('NOT_FOUND', 'Not found', 404);
    }
    [$table, $action] = $parts;

    if ($method !== 'GET') {
        return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
    }
    if ($action !== 'search' && $action !== 'resolve') {
        return Response::error('NOT_FOUND', 'Not found', 404);
    }

    return new Route('lookup', $action, $table);
}
```

Dispatch v `public/index.php`:

```php
'lookup' => dispatchLookup($route, $request, $tables, $db, $lookupRegistry, $configRuntime),
```

Plus přidat `dispatchLookup()` funkci a hostit `$lookupRegistry` v
top-level scope (load `LookupLoader::load($resolved->config, $modulePathResolver)`
hned po existujícím `FormLoader::load(...)`).

**Pozor:** funkce `dispatch()` (top-level) musí mít `$lookupRegistry`
explicitně v signatuře (analogie s `$documentRegistry`).

---

## Backend — pre-resolved data v FormController

### 10. `FormController` rozšíření

**Soubor:** `src/Api/Controller/FormController.php`

V `meta`, `save`, `recalculate` po sestavení FormDefinition zavolat helper:

```php
private function buildDataResolved(
    FormDefinition $formDef,
    array $data,
    LookupRegistry $lookupRegistry,
    DataSourceConnection $db,
    ?ConfigRuntime $config,
    array $tables,
): array {
    $result = [];
    foreach ($this->collectLookupElements($formDef) as $element) {
        $column = $element->column;
        $value = $data[$column] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        $lookupCfg = $element->lookup;
        $targetTable = $lookupCfg['table'] ?? null;
        if ($targetTable === null) {
            continue;
        }
        $targetDef = $tables[$targetTable] ?? null;
        $lookup = $lookupRegistry->create($targetTable, $db, $config, $targetDef);
        if ($lookup === null) {
            continue;
        }
        $items = $lookup->resolve([$value]);
        if ($items === []) {
            continue;
        }
        $result[$column] = $items[0]->toArray();
    }
    return $result;
}

/**
 * Vrací všechny `lookup` elementy z FormDefinition (rekurzivně přes
 * tabs → sections → columns → elements). Inline groups přeskakuje
 * — lookup uvnitř inline není povolen.
 *
 * @return list<FormElement>
 */
private function collectLookupElements(FormDefinition $formDef): array
{
    $out = [];
    foreach ($formDef->tabs as $tab) {
        if ($tab->type !== 'fields') {
            continue;
        }
        foreach ($tab->sections as $section) {
            foreach ($section->columns as $column) {
                foreach ($column->elements as $el) {
                    if ($el->type === 'lookup') {
                        $out[] = $el;
                    }
                }
            }
        }
    }
    return $out;
}
```

V `meta`, `save`, `recalculate` přidat:

```php
$dataResolved = $this->buildDataResolved(
    $formDefinition, $data, $lookupRegistry, $db, $config, $tables,
);

return Response::success([
    'formDefinition' => $formDefinition->toArray(),
    'data'           => $data,
    'dataResolved'   => $dataResolved,
]);
```

`LookupRegistry` se předá do controlleru přes signaturu metod — buď v
konstruktoru `FormController` (pak aktualizovat dispatchForm), nebo
přidat jako parametr metod `meta/save/recalculate`. Konzistentně s tím,
jak je dnes předaný `$formRegistry`. Doporučuji parametr metod — drží to
controller bez stavu.

**Pro nový záznam (`isNew = true`):** `dataResolved` je typicky `{}`
— nemáme co resolvovat. Klient to očekává a renderuje lookup input
s placeholderem.

**Pro `save`:** po úspěšném INSERT/UPDATE se načítají čerstvá data
(`fetchRow`), na nich se buildDataResolved — viz strukturu níže.

```php
// V save() po úspěchu:
$savedId = $saved['id'] ?? $id;
$record  = $db->fetchRow("SELECT * FROM `{$table}` WHERE `id` = %i", $savedId);

// Need FormDefinition to enumerate lookup elements. Best: build it from
// the same path resolveFormDefinition() uses. Helper to extract.
$formDef = $this->resolveFormDefinition(
    $table, $def, $record, /*$isNew*/ false,
    $formRegistry, $db, $config, $modulePathResolver, $language,
);
$dataResolved = $this->buildDataResolved(
    $formDef, $record, $lookupRegistry, $db, $config, $tables,
);

return Response::success(
    ['id' => $savedId, 'data' => $record, 'dataResolved' => $dataResolved],
    $httpStatus,
);
```

Save tedy potřebuje navíc `$formRegistry`, `$modulePathResolver`, `$lookupRegistry`,
`$language` — rozšířit signaturu.

---

## Backend — konkrétní lookupy

### 11. `modules/base/persons/src/PersonsLookup.php` (nový)

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup pro tabulku osob s vyhledáváním přes několik polí (název, IČO,
 * kód osoby) a typově citlivým secondary řádkem (firma = IČO, FO = datum
 * narození).
 */
class PersonsLookup extends TableLookup
{
    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $q = trim($q);

        if ($q === '') {
            $rows = $this->db->fetchAll(
                'SELECT `id`, `full_name`, `person_type`, `company_id`, `birth_date`, `person_id`'
                . ' FROM `base_persons_persons`'
                . ' WHERE `docState` IN (10, 40, 80)'
                . ' ORDER BY `full_name` ASC'
                . ' LIMIT %i', $limit,
            );
        } else {
            $like = '%' . $q . '%';
            $rows = $this->db->fetchAll(
                'SELECT `id`, `full_name`, `person_type`, `company_id`, `birth_date`, `person_id`'
                . ' FROM `base_persons_persons`'
                . ' WHERE `docState` IN (10, 40, 80)'
                . '   AND (`full_name` LIKE %s OR `company_id` LIKE %s OR `person_id` LIKE %s)'
                . ' ORDER BY `full_name` ASC'
                . ' LIMIT %i',
                $like, $like, $like, $limit,
            );
        }
        return array_map(fn($r) => $this->buildItem($r), $rows);
    }

    public function resolve(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $intIds = array_filter(array_map('intval', $ids), fn($v) => $v > 0);
        if ($intIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `full_name`, `person_type`, `company_id`, `birth_date`, `person_id`'
            . ' FROM `base_persons_persons`'
            . ' WHERE `id` IN %in', array_values($intIds),
        );
        return array_map(fn($r) => $this->buildItem($r), $rows);
    }

    private function buildItem(array $row): LookupItem
    {
        $primary = trim((string) ($row['full_name'] ?? '')) ?: ('#' . $row['id']);
        $personType = (int) ($row['person_type'] ?? 0);

        $secondary = null;
        if ($personType === 2 /* Company */ && !empty($row['company_id'])) {
            $secondary = 'IČO ' . $row['company_id'];
        } elseif ($personType === 1 /* Person */ && !empty($row['birth_date'])) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $row['birth_date']);
            if ($dt instanceof \DateTimeImmutable) {
                $secondary = 'Datum narození ' . $dt->format('d.m.Y');
            }
        }

        return new LookupItem(
            id: (int) $row['id'],
            primary: $primary,
            secondary: $secondary,
        );
    }
}
```

### 12. `modules/base/persons/src/AddressesLookup.php` (nový)

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

class AddressesLookup extends TableLookup
{
    public function getAllowedFilterKeys(): array
    {
        return ['person'];
    }

    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $personId = isset($filter['person']) ? (int) $filter['person'] : 0;
        if ($personId === 0) {
            return []; // Address lookup is only meaningful per-person.
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `display_line` FROM `base_persons_addresses`'
            . ' WHERE `person` = %i'
            . ' AND (`valid_from` IS NULL OR `valid_from` <= CURDATE())'
            . ' AND (`valid_to`   IS NULL OR `valid_to`   >= CURDATE())'
            . ' ORDER BY `order_pos` ASC, `id` ASC'
            . ' LIMIT %i', $personId, $limit,
        );
        // Volné `q` (typeahead) si dosadí klient — pro adresy je per-person
        // seznam malý, klient může jen filtrovat lokálně. Pokud by chtěl
        // backend filter, přidá se sem AND `display_line` LIKE …
        return array_map(fn($r) => new LookupItem(
            id: (int) $r['id'],
            primary: (string) ($r['display_line'] ?? ('#' . $r['id'])),
        ), $rows);
    }

    public function resolve(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $intIds = array_filter(array_map('intval', $ids), fn($v) => $v > 0);
        if ($intIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `display_line` FROM `base_persons_addresses`'
            . ' WHERE `id` IN %in', array_values($intIds),
        );
        return array_map(fn($r) => new LookupItem(
            id: (int) $r['id'],
            primary: (string) ($r['display_line'] ?? ('#' . $r['id'])),
        ), $rows);
    }
}
```

### 13. `modules/base/persons/src/BankAccountsLookup.php` (nový)

Analogie `AddressesLookup` — allowedFilterKeys: `['person']`, primární
řádek `account_number`, secondary `IBAN xxx` pokud je vyplněn. Detail
sleduj vzor z `DocsHeadsForm::resolvePartnerBankOptions`.

### 14. Registrace v `module.jsonc`

**Soubor:** `modules/base/persons/module.jsonc`

Přidat nový top-level klíč:

```jsonc
"lookups": [
    {
        "table": "base_persons_persons",
        "class": "Shipard\\Module\\Base\\Persons\\PersonsLookup"
    },
    {
        "table": "base_persons_addresses",
        "class": "Shipard\\Module\\Base\\Persons\\AddressesLookup"
    },
    {
        "table": "base_persons_bank_accounts",
        "class": "Shipard\\Module\\Base\\Persons\\BankAccountsLookup"
    }
],
```

---

## Backend — porting DocsHeadsForm

### 15. `DocsHeadsForm` přechod ze `select` na `lookup`

**Soubor:** `modules/docs/core/src/DocsHeadsForm.php`

V `buildHeaderTab()`:

```php
->separator('Partner')
->lookup('partner',
    table: 'base_persons_persons',
    placeholder: 'Hledat partnera…',
    triggers: 'reload',
)
->lookup('partner_address',
    table: 'base_persons_addresses',
    filter: $partnerId !== 0 ? ['person' => $partnerId] : null,
    placeholder: $partnerId !== 0 ? 'Vyberte adresu…' : 'Nejdřív vyberte partnera',
    readOnly: $partnerId === 0,
)
->lookup('partner_bank',
    table: 'base_persons_bank_accounts',
    filter: $partnerId !== 0 ? ['person' => $partnerId] : null,
    placeholder: $partnerId !== 0 ? 'Vyberte bankovní účet…' : 'Nejdřív vyberte partnera',
    readOnly: $partnerId === 0,
)
->input('partner_bank_account', label: 'Číslo účtu')
->input('partner_bank_iban', label: 'IBAN')
->input('partner_bank_bic', label: 'BIC/SWIFT')
```

Smaž metody `resolvePartnerOptions()`, `resolvePartnerAddressOptions()`,
`resolvePartnerBankOptions()` — už nejsou potřeba. Konstruktor / dispatch
do `recalculate()` zůstává; metoda dál po změně partnera dopočítá
`due_date` a předvyplní `partner_address` (to je business logika v
recalculate, nezávislá na renderingu).

`recalculate` po změně partnera taky předvyplní `partner_address` na default
adresu. To zůstává — jen místo `select.options` se po `recalculate` v
nové `FormDefinition` partnerova adresa vyplní jako `data.partner_address`
a `dataResolved.partner_address` doplní `FormController` automaticky.

---

## Frontend — komponenta `LookupInput.svelte`

### 16. `frontend/src/components/ui/LookupInput.svelte` (nový)

Specifikace chování:

- **Render value:** pokud `value !== null` a `resolved !== null`, input
  zobrazí `resolved.primary`. Pod inputem (nebo v menším řádku napravo)
  caption `resolved.secondary` pokud existuje.
- **Klik / fokus na input:** otevře dropdown, text v inputu se vyselectuje
  (uživatel může rovnou začít přepisovat).
- **Search:** debounce 300ms; `q` aktuální obsah inputu; po dokončení
  fetchu zobrazí výsledky v dropdownu.
- **Dropdown položka:** dva řádky — `primary` tučný/normální + `secondary`
  menším fontem, sekundární barvou. Hover/aktivní položka má pozadí.
- **Klávesnice:** ↓ otevře dropdown a najede na první výsledek; ↓/↑
  posouvá; Enter vybere; Esc zavře (bez výběru); Backspace na prázdném
  inputu nuluje `value` (clear).
- **Tlačítko `×`** vpravo v inputu — clear (jen pokud `value !== null`
  a komponenta není disabled).
- **Loading state:** dropdown zobrazí „Načítám…" zatímco běží fetch.
- **Empty state:** „Žádné výsledky" pokud výsledky jsou prázdné.
- **Error state:** „Chyba: …" pokud fetch selhal.
- **Filter:** propojen z `lookup.filter` v props; klient ho zkopíruje
  do query stringu volání jako `filter[col]=val`.
- **Disabled:** input je read-only, klik neotevírá dropdown, žádný clear.

Skeleton (Svelte 5 runes):

```svelte
<script>
  import { get } from '../../api/client.js';
  import { t } from '../../i18n/index.js';

  let {
    id,
    value = $bindable(null),
    /** Iniciální display popis z dataResolved — `{id, primary, secondary}`. */
    resolved = null,
    /** Lookup konfigurace — `{table, filter}`. */
    lookup,
    required = false,
    disabled = false,
    error = null,
    placeholder = '',
    onchange,
    /** Callback po výběru — předá nově resolved popis, ať volající
     *  může aktualizovat svůj dataResolved cache. */
    onResolveChange,
  } = $props();

  let inputEl = $state(null);
  let open = $state(false);
  let searchTerm = $state('');
  let results = $state([]);
  let loading = $state(false);
  let lastError = $state(null);
  let activeIndex = $state(-1);
  let debounceTimer = null;

  // Display label v inputu (mimo search mode).
  let displayLabel = $derived(resolved?.primary ?? '');

  // ... fetch, klávesnicová navigace, výběr ...
</script>
```

Layout:

```svelte
<div class="shpd-lookup" class:shpd-lookup--open={open} class:shpd-lookup--error={!!error}>
  <div class="shpd-lookup__field">
    <input
      bind:this={inputEl}
      {id}
      type="text"
      class="shpd-lookup__input"
      value={open ? searchTerm : displayLabel}
      placeholder={placeholder}
      {required}
      {disabled}
      readonly={disabled}
      oninput={handleInput}
      onfocus={handleFocus}
      onkeydown={handleKeydown}
    />
    {#if value !== null && !disabled}
      <button type="button" class="shpd-lookup__clear" onclick={handleClear} aria-label={t('common.clear')}>×</button>
    {/if}
  </div>

  {#if resolved?.secondary && !open}
    <div class="shpd-lookup__caption">{resolved.secondary}</div>
  {/if}

  {#if open}
    <div class="shpd-lookup__dropdown" role="listbox">
      {#if loading}
        <div class="shpd-lookup__status">{t('lookup.loading')}</div>
      {:else if lastError}
        <div class="shpd-lookup__status shpd-lookup__status--error">{lastError}</div>
      {:else if results.length === 0}
        <div class="shpd-lookup__status">{t('lookup.empty')}</div>
      {:else}
        {#each results as item, i (item.id)}
          <button
            type="button"
            class="shpd-lookup__item"
            class:shpd-lookup__item--active={i === activeIndex}
            onmouseenter={() => (activeIndex = i)}
            onmousedown={(e) => { e.preventDefault(); handleSelect(item); }}
          >
            <span class="shpd-lookup__item-primary">{item.primary}</span>
            {#if item.secondary}
              <span class="shpd-lookup__item-secondary">{item.secondary}</span>
            {/if}
          </button>
        {/each}
      {/if}
    </div>
  {/if}

  {#if error}
    <span class="shpd-lookup__error">{error}</span>
  {/if}
</div>
```

CSS pravidla:

- `.shpd-lookup` — `position: relative`
- `.shpd-lookup__field` — flex, input + clear button
- `.shpd-lookup__dropdown` — `position: absolute`, plná šířka inputu,
  `z-index` nad ostatními poli (`z-index` vyšší než modální obsah, ale
  uvnitř modálního stack contextu — typicky `z-index: 10` stačí, protože
  modal je vlastní stacking context)
- `.shpd-lookup__item` — full-width tlačítko, dva řádky (`display: flex;
  flex-direction: column;`)
- `.shpd-lookup__item--active` — pozadí `var(--shpd-color-primary-soft)`
- `.shpd-lookup__caption` — pod inputem, menším fontem, sekundární barvou

Detaily na pozor:

- **Click outside zavírá dropdown** — připoj listener na `document`
  v `$effect` při `open === true`, odpoj při zavření.
- **Click na položku ale dropdown NESMÍ zavřít před výběrem** — proto
  `onmousedown` (před blur eventem) místo `onclick`, a `e.preventDefault()`
  aby input neztratil fokus.
- **Fokus zpět na input po výběru / clear** — `inputEl?.focus()`.
- **Reset searchTerm při zavření** — když uživatel zavře dropdown bez
  výběru, `searchTerm` se nuluje.
- **Filter propagace do fetch URL** — zkopíruj objekty z `lookup.filter`
  do `URLSearchParams` s prefixem `filter[col]`:
  ```js
  if (lookup.filter) {
    for (const [k, v] of Object.entries(lookup.filter)) {
      params.set(`filter[${k}]`, String(v));
    }
  }
  ```

### 17. i18n — nové stringy

**Soubory:** `frontend/src/i18n/cs.js`, `frontend/src/i18n/en.js`

Přidat klíče:

```js
lookup: {
  loading: 'Načítám…' / 'Loading…',
  empty: 'Žádné výsledky' / 'No results',
  error: 'Chyba načítání' / 'Loading error',
}
```

`common.clear` (`Vymazat` / `Clear`) — pokud ještě neexistuje, přidat
do `common.*`.

### 18. `FormElement.svelte` — větvení pro `lookup`

**Soubor:** `frontend/src/components/form/FormElement.svelte`

Přidat `LookupInput` import a novou větev:

```svelte
{:else if element.type === 'lookup'}
  <FormFieldRow {element} id={inputId}>
    <LookupInput
      id={inputId}
      bind:value={formData[element.column]}
      resolved={dataResolved[element.column] ?? null}
      lookup={element.lookup}
      required={element.required ?? false}
      disabled={elDisabled}
      placeholder={element.placeholder}
      {error}
      onchange={handleChange}
      onResolveChange={(item) => onResolveChange?.(element.column, item)}
    />
  </FormFieldRow>
```

Komponenta dostane nový `dataResolved` prop a nový callback `onResolveChange`
— propaguje výběr nahoru, ať `FormEditor` může aktualizovat svůj keš.

### 19. `FormEditor.svelte` — keš `dataResolved`

**Soubor:** `frontend/src/components/form/FormEditor.svelte`

Přidat state:

```js
let dataResolved = $state({});
```

V `loadForm`:

```js
const res = await get(`/_ui/form/${table}/meta${id ? `/${id}` : ''}`);
if (res?.success) {
  formDef = res.data.formDefinition;
  formData = res.data.data ?? {};
  dataResolved = res.data.dataResolved ?? {};
  // ... snapshot, savedHeaderInfo, etc.
}
```

V `handleTrigger` (recalculate response):

```js
formDef = res.data.formDefinition;
formData = res.data.data;
dataResolved = { ...dataResolved, ...(res.data.dataResolved ?? {}) };
// Merge, ne replace — recalculate vrací resolved jen pro lookup pole,
// jiná setrvávají.
```

Při výběru uživatelem (callback `onResolveChange`):

```js
function handleResolveChange(column, item) {
  if (item === null) {
    const next = { ...dataResolved };
    delete next[column];
    dataResolved = next;
  } else {
    dataResolved = { ...dataResolved, [column]: item };
  }
}
```

Propaguj `dataResolved` a `onResolveChange` do `FormTab` →
`FormSection` → `FormColumn` → `FormElement`. Stejný drilldown už
existuje pro `formData`, `fieldErrors` — přidat dvě další props ve stejné
struktuře.

V `save` response server vrátí nové `dataResolved` — aktualizovat:

```js
const res = await post(...);
if (res?.success) {
  currentId = res.data.id;
  formData = res.data.data;
  dataResolved = res.data.dataResolved ?? {};
  // ... snapshot reset ...
}
```

---

## Mimo rozsah (řeší se v navazujících taskech)

- **`item` lookup v řádcích dokladu** (`DocRowsForm`) — vyžaduje
  `ItemsLookup` v `modules/economy/items` + porting `DocRowsForm`. Navazující
  task `form-lookup-items.md`. Item v řádcích je dnes nejspíš `select` s
  options, takže porting bude přímý, ale subtable kontext (formulář v
  modalu v modalu) si zaslouží samostatný smoke test.
- **`number_series` lookup** — záměrně zachováno jako `select`, série
  jsou v jednotkách / desítkách
- **Default `TableLookup` fallback** přes `TableDefinition.displayPattern`
  / `TableViewer.searchFields` — pokud Anna v review uvidí, že by se
  hodilo automaticky, oddělíme samostatně. MVP vyžaduje explicitní
  `TableLookup` registraci.
- **Lookup uvnitř inline groups** — odmítnuto schématem; pokud později
  vznikne use case (např. dva malé lookupy vedle sebe), řešíme
  samostatně. Validace v `FormElement` to teď zamítá.
- **AutoFormBuilder generování lookup elementů** — auto-form pro FK
  sloupce dnes generuje `select` (nebo nic). Pokud nemá registrovaný
  TableLookup, zůstává to tak. Změna v AutoFormBuilder je mimo rozsah.
- **Multi-select lookup** (vyber víc položek najednou) — řeší se až
  budeme mít první N:M pivot v UI.
- **„+ Nový" tlačítko v dropdownu** (rychlé založení Osoby přímo z
  Partner pickeru) — žádá si vlastní design (kolaborace s FormDialog
  pro otevření sub-formuláře, návrat nového ID); samostatný task.
- **Server-side OR multi-field search v `AddressesLookup`** — pro adresy
  je per-person seznam malý, neřešíme; klient si pole `q` může lokálně
  filtrovat (případně jednoduše ne — typicky 1-3 adresy).
- **Fulltext / fuzzy hledání** — LIKE s `%term%` je MVP-dostatečné.

---

## Testy

### Backend — unit

`tests/Core/Form/Lookup/LookupItemTest.php`:

- Konstruktor s int / string `id`, `secondary` defaultně null
- `toArray()` vrátí očekávanou strukturu

`tests/Core/Form/Lookup/LookupRegistryTest.php`:

- `register` + `has` + `create`
- `register` neplatné třídy (ne-subclass `TableLookup`) → `InvalidArgumentException`
- `create` na neexistujícím `table` → null

`tests/Module/Base/Persons/PersonsLookupTest.php`:

- `search` s prázdným `q` → vrátí top N podle abecedy
- `search` s neprázdným `q` → LIKE na `full_name`, `company_id`, `person_id`
- `resolve` s listy ID → vrátí položky odpovídající existujícím
  záznamům; neexistující ID se v `items` nevyskytnou
- `buildItem`: Company s `company_id` → secondary „IČO …";
  Person s `birth_date` → secondary „Datum narození d.m.Y";
  Person bez `birth_date` → secondary null;
  Undefined → secondary null

`tests/Module/Base/Persons/AddressesLookupTest.php`:

- `getAllowedFilterKeys()` vrátí `['person']`
- `search` bez `filter[person]` → vrátí prázdné pole
- `search` s `filter[person] = X` → vrátí adresy daného partnera, aktuální
  (validity window) seřazené podle `order_pos`

`tests/Core/Form/FormElementLookupTest.php`:

- Konstruktor `type=lookup` bez `column` → `InvalidArgumentException`
- Konstruktor `type=lookup` bez `lookup.table` → `InvalidArgumentException`
- `toArray()` obsahuje klíč `lookup` se strukturou `{table, filter}`
- `lookup` element nelze přidat do `inline.elements[]` → konstruktor vyhodí

`tests/Core/Form/TabBuilderLookupTest.php`:

- `->lookup('partner', table: 'base_persons_persons')` vyrobí FormElement
  typu lookup s odpovídajícím `lookup` polem

`tests/Core/Form/JsoncFormLoaderLookupTest.php`:

- JSONC s `{"type": "lookup", "column": "x", "lookup": {"table": "y"}}`
  se načte jako FormElement typu lookup
- JSONC bez `lookup.table` → `RuntimeException` s konkrétní hláškou

### Backend — integrační (Lookup endpoint)

`tests/Api/Controller/LookupControllerTest.php`:

- `GET /_ui/lookup/base_persons_persons/search?q=test` vrátí 200 a
  pole `items` s LookupItem strukturou
- `GET /_ui/lookup/unknown_table/search` vrátí 404 `TABLE_NOT_FOUND`
- `GET /_ui/lookup/core_system_settings/search` (tabulka existuje, ale
  není registrován `TableLookup`) vrátí 404 `LOOKUP_NOT_REGISTERED`
- `GET /_ui/lookup/base_persons_addresses/search?filter[person]=42` vrací
  jen adresy partnera 42
- `GET /_ui/lookup/base_persons_addresses/search?filter[foo]=1` ignoruje
  neznámý filter (nevyhodí chybu, jen ho přeskočí)
- `GET /_ui/lookup/base_persons_persons/resolve?ids=1,2,99999` vrací
  jen položky pro existující ID

### Backend — FormController dataResolved

`tests/Api/Controller/FormControllerDataResolvedTest.php`:

- `GET /_ui/form/docs_core_heads/meta` (nový doklad) → response má
  `dataResolved: {}`
- `GET /_ui/form/docs_core_heads/meta/{id}` (existující doklad s
  partnerem) → `dataResolved.partner` má strukturu `{id, primary, secondary}`
- `POST /_ui/form/docs_core_heads/recalculate` po změně partnera → response
  má `dataResolved.partner` pro nového partnera
- `PUT /_ui/form/docs_core_heads/save/{id}` → response má `dataResolved`
  pro všechny lookup sloupce

### Frontend — manuální smoke test

1. **Otevři Faktura přijatá → nová.** Klikni na pole „Partner" → otevře se
   dropdown, ukazuje top 20 osob seřazených podle jména. Vyber „Testování
   999" → input ukáže „Testování 999" + caption „IČO 12345678". Po výběru
   se filterují adresy a banky (cascade přes recalculate).
2. **Začni psát** v zavřeném inputu — dropdown se otevře, výsledky se
   debounce-filtrují. Šipky ↓/↑ navigují, Enter vybírá, Esc zavírá bez
   výběru.
3. **Backspace na prázdném inputu** zruší výběr. „×" tlačítko taky.
4. **Cascade: změň partnera** — pole „Adresa partnera" se vyresetuje
   (recalculate ho nuluje, pokud nová default adresa není default).
   „Adresa partnera" po výběru nového partnera má v filter `{person:
   newId}` a v dropdownu se zobrazí jeho adresy.
5. **Otevři existující doklad.** Pole „Partner" rovnou ukazuje „Testování
   999, IČO 12345678" bez extra round-tripu.
6. **Ulož doklad** — po úspěšném save zůstanou všechny lookup pole
   správně vyplněné (dataResolved se aktualizuje ze save response).
7. **Disabled state:** otevři doklad ve stavu „V pořádku" (read-only) →
   lookup input je read-only, klik neotevírá dropdown, žádný clear button.
8. **Fyzická osoba bez data narození** — vyber takovou osobu jako
   Partnera → input zobrazí jen primary, caption se nevykreslí.
9. **Chyba sítě** — odpoj DevTools / shoď backend a zkus hledat → dropdown
   ukáže „Chyba načítání…". Po obnovení sítě další search funguje.

---

## Dokumentace

### `docs/edit-forms.md` — nová kapitola „22. Lookup pole"

Obsah:

- Kdy použít lookup vs `select` (lookup = velká tabulka, search-driven;
  select = enum / malý cfgItem)
- Wire formát elementu (`type: lookup`, struktura `lookup.table`,
  `lookup.filter`)
- Endpoint kontrakt (`/_ui/lookup/{table}/search`, `/resolve`)
- `dataResolved` v meta/save/recalculate response
- `TableLookup` abstraktní třída + registrace v `module.jsonc` → `lookups[]`
- Cascade filtry: vzor v `DocsHeadsForm` (partner → partner_address)
- PHP builder API: `->lookup($column, table: …, filter: …)`
- JSONC source: `{"type": "lookup", "column": …, "lookup": {"table": …}}`
- Frontend chování: inline combobox, klávesnice, debounce, disabled state
- **Pravidlo:** lookup nelze umístit do `inline` skupiny

### `CLAUDE.md`

Krátká zmínka v sekci o formulářích — odkaz na kapitolu 22 a vysvětlení,
že `select` je pro enumy a malé číselníky, `lookup` pro velké tabulky s
vyhledáváním.

---

## Hotovo když

- [ ] `vendor/bin/phpunit 2>&1` — všechny testy procházejí, včetně nových
- [ ] `cd frontend && npm run build 2>&1` — bez chyb a warningů
- [ ] Manuální smoke test (viz výše) — všech 9 scénářů funguje
- [ ] `GET /_ui/lookup/base_persons_persons/search?q=test` vrací validní
      response
- [ ] `GET /_ui/lookup/base_persons_addresses/search?filter[person]=42`
      vrací jen adresy daného partnera
- [ ] Faktura přijatá: pole Partner funguje jako combobox; Adresa a Banka
      se kaskádově filtrují
- [ ] Otevření existující faktury okamžitě zobrazí display popis vybraného
      partnera (žádný extra fetch)
- [ ] `DocsHeadsForm` už neobsahuje `resolvePartnerOptions`,
      `resolvePartnerAddressOptions`, `resolvePartnerBankOptions` (smazané)
- [ ] Stávající testy `DocsHeadsForm` procházejí bez úprav (nebo s
      minimálními úpravami pro očekávaný `lookup` element místo `select`)
- [ ] `docs/edit-forms.md` má kapitolu 22
- [ ] `CLAUDE.md` zmiňuje lookup vs select

---

## Doporučené pořadí

1. **Backend datový kontrakt** — `LookupItem`, `TableLookup`, `LookupRegistry`,
   `LookupLoader`. Unit testy.
2. **Backend endpoint** — `LookupController`, routing, dispatch v
   `public/index.php`. Integrační test bez zaregistrovaných lookupů
   (jen `LOOKUP_NOT_REGISTERED` 404 cesta).
3. **Backend element** — rozšíření `FormElement`, `TabBuilder->lookup()`,
   `JsoncFormLoader`. Unit testy.
4. **Backend per-table lookups** — `PersonsLookup`, `AddressesLookup`,
   `BankAccountsLookup`. Registrace v `module.jsonc`. Unit testy.
   Endpoint integrační test je teď zelený pro tyto tabulky.
5. **Backend pre-resolved data** — `FormController::buildDataResolved`
   helper, napojení v `meta`, `save`, `recalculate`. Integrační test.
6. **Frontend `LookupInput.svelte`** — komponenta v izolaci. Manuálně
   ozkoušej s hardcoded `lookup={table: 'base_persons_persons'}` v
   nějaké sandbox view nebo přímo v `FormElement.svelte` pod
   feature flagem.
7. **Frontend napojení** — `FormElement.svelte` větvení,
   `FormEditor.svelte` keš `dataResolved`, propagace přes
   `FormTab/FormSection/FormColumn`.
8. **Porting `DocsHeadsForm`** — `partner`, `partner_address`,
   `partner_bank` ze `select` na `lookup`. Smaž obsoletní `resolve*Options`
   metody. Manuální smoke test.
9. **Dokumentace** — `docs/edit-forms.md` kapitola 22 + `CLAUDE.md`.

## Konvence

- **Jazyk**: UI texty / labely česky (i18n bude rozšiřovat); placeholder
  v `DocsHeadsForm` má `:cs`/`:en` variantu, pokud zavádíš v JSONC; pro
  PHP labely zatím česky (stejný stav jako `PersonsForm`)
- **PHP 8.3** strict_types, readonly properties, named args při volání
  TabBuilderu
- **Snake_case na drátě** (`data_resolved`? — NE, top-level je camelCase
  konzistentně s `formDefinition`; uvnitř `FormDefinition` zůstává
  snake_case pro `header_info` atd.). Tj. wire `dataResolved`,
  `formDefinition`, ale uvnitř `formDefinition.tabs[].sections[]...` dál
  snake_case.
- **Filter klíče jsou DB column names** — držíme se DB konvencí
- **Svelte 5 runes** (`$state`, `$derived`, `$effect`, `$props`,
  `$bindable`)
- Před patchováním Svelte komponent **přečíst celý soubor** — `patch_file`
  vyžaduje přesné whitespace
- **Smaž obsoletní kód** — `resolvePartnerOptions` a sourozenci v
  `DocsHeadsForm` po portingu zmizí, ne se nechávají jako dead code
- **Žádná backward compatibility** — typ `select` zůstává pro enumy;
  lookup je nová cesta. Pokud nějaký formulář dnes používá `select` pro
  něco, co by mělo být `lookup`, řeší se v navazujícím tasku, ne tady.
