# Task: Editační formuláře — Fáze 1 (Backend jádro)

## Kontext

Implementujeme nový systém editačních formulářů dle `docs/edit-forms.md`. Tato task pokrývá **Fázi 1** — backend jádro bez konkrétních formulářů.

Přečti si nejprve:
- `docs/edit-forms.md` — celý PRD
- `docs/architecture.md` — přehled architektury
- `docs/frontend.md` — sekce 6 (stávající FormRenderer) a sekce 8 (UI API endpointy)
- `src/Core/Viewer/TableViewer.php` a `src/Api/Controller/ViewerController.php` — jako vzor pro symetrický Form systém

## Cíl Fáze 1

Funkční `FormController` s těmito endpointy:

```
GET  /_ui/form/{table}/meta
GET  /_ui/form/{table}/meta/{id}
POST /_ui/form/{table}/save
PUT  /_ui/form/{table}/save/{id}
POST /_ui/form/{table}/recalculate
```

Formuláře zatím pouze přes `AutoFormBuilder` (automatické generování z TableDefinition). PHP třídy a JSONC definice konkrétních formulářů přijdou v Fázích 2 a 3.

## Nové soubory — `src/Core/Form/`

### `FormElement.php`

Datová třída pro jeden element formuláře. Viz `docs/edit-forms.md` sekce 4 pro všechny typy a pole.

Povinná pole:
- `type: string` — 'input' | 'select' | 'separator' | 'group' | 'subtable' | 'html'
- `cols: int` — 1–4

Volitelná pole (dle typu):
- `column: ?string` — pro input, select
- `label: ?string`
- `placeholder: ?string`
- `required: bool`
- `readOnly: bool`
- `hidden: bool`
- `triggers: ?string` — 'reload' nebo null
- `hint: ?string`
- `options: ?array` — pro select: [{value, label}]
- `elements: ?array` — pro group: vnořené elementy
- `table: ?string` — pro subtable
- `foreignKey: ?string` — pro subtable
- `formId: ?string` — pro subtable
- `content: ?string` — pro html

Musí mít `toArray(): array` metodu pro JSON serializaci.

### `FormTab.php`

Datová třída pro jeden tab.

Pole:
- `id: string`
- `label: string`
- `elements: FormElement[]`

Metoda `toArray(): array`.

### `FormDefinition.php`

Datová třída pro celou definici formuláře.

Pole:
- `table: string`
- `title: string`
- `titleNew: string`
- `tabs: FormTab[]`
- `fullSize: bool` — true = formulář se otevře jako full-size stránka, false = modální dialog (výchozí)
- `docStates: ?array` — viz `docs/edit-forms.md` sekce 5, generuje se z TableDefinition + aktuálního záznamu

Metoda `toArray(): array`.

Metoda `withDocStates(array $docStatesInfo): static`.

### `TabBuilder.php`

Fluent builder pro `FormTab`. Viz `docs/edit-forms.md` sekce 11 (Builder API).

Metody vrací `static` pro řetězení. Metoda `build(): FormTab` vytvoří výsledný `FormTab`.

### `TableForm.php`

Abstraktní bázová třída. Viz `docs/edit-forms.md` sekce 11.

```php
abstract class TableForm
{
    protected string $table;
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConnection $db = null;

    public function setConfig(ConfigRuntime $config): void { ... }
    public function setDb(DataSourceConnection $db): void { ... }
    public function setTable(string $table): void { ... }

    abstract public function buildFormDefinition(array $data, bool $isNew): FormDefinition;

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        // Výchozí: vrátí nezměněnou FormDefinition + data
        return new RecalculateResult($this->buildFormDefinition($data, empty($data['id'])), $data);
    }

    // Builder helper
    protected function tab(string $id, string $label): TabBuilder { ... }
}
```

### `RecalculateResult.php`

```php
class RecalculateResult
{
    public function __construct(
        public readonly FormDefinition $formDefinition,
        public readonly array $data,
    ) {}
}
```

### `AutoFormBuilder.php`

Automaticky generuje `FormDefinition` z `TableDefinition`. Logika:

1. Skupiny sloupců (`columnGroups`) z TableDefinition → taby (každá skupina = jeden tab)
2. Sloupce bez skupiny → tab "Obecné" (pokud existují)
3. Sloupce se `system: true` → přeskočit
4. Sloupce `id`, `created`, `modified` → přeskočit
5. Sloupec `password_hash` nebo obsahující 'password' v názvu → přeskočit
6. Typ sloupce → `cols`: varchar krátký (length ≤ 30) = 1, varchar střední = 2, text/longtext = 4, ostatní = 1
7. Žádné `triggers`, žádné `hidden`

```php
class AutoFormBuilder
{
    public function build(TableDefinition $tableDef, string $lang = 'cs'): FormDefinition { ... }
}
```

### `JsoncFormLoader.php`

Načítá JSONC definici formuláře a vrátí `FormDefinition`.

```php
class JsoncFormLoader
{
    public function load(string $jsonPath, TableDefinition $tableDef, string $lang = 'cs'): FormDefinition { ... }
}
```

JSONC soubor obsahuje taby a elementy — viz `docs/edit-forms.md` sekce 12. Pole jako `label`, `type inputu` se doplní z `TableDefinition` pokud chybí v JSONC.

### `FormRegistry.php`

Analogie `ViewerRegistry`. Načítá registrace z kompilované konfigurace (`forms` sekce `module.jsonc`).

```php
class FormRegistry
{
    public function getForm(string $tableId): ?TableForm { ... }
    // Vrátí TableForm instanci, nebo null pokud není registrace
}
```

### `FormController.php`

HTTP controller. Viz `docs/edit-forms.md` sekce 10 a 17.

```php
class FormController
{
    public function meta(Request $req, string $table, ?int $id, ...): Response { ... }
    public function save(Request $req, string $table, ?int $id, ...): Response { ... }
    public function recalculate(Request $req, string $table, ...): Response { ... }
}
```

#### `meta` logika:

1. Načti TableDefinition pro $table (z TableLoader)
2. Načti data záznamu z DB (pokud $id)
3. Rozhodni zdroj FormDefinition (viz priorita v sekci 12 PRD):
   a. FormRegistry → PHP třída → `buildFormDefinition($data, $isNew)`
   b. JsoncFormLoader → `forms/{table}.jsonc`
   c. AutoFormBuilder → automatická generace
4. Je-li tabulka se docStates: doplň `docStates` do FormDefinition (aktuální stav + transitions)
5. Vrátí `{"success": true, "formDefinition": {...}, "data": {...}}`

#### `save` logika:

1. Načti TableDefinition
2. POST → INSERT přes `CrudController` logiku (nebo lépe přes `TableGateway` pokud existuje)
3. PUT → UPDATE
4. Validační chyby ze serveru → `{"success": false, "errors": [...]}`
5. Úspěch → `{"success": true, "id": X, "data": {...}}`

Poznámka: `save` endpoint interně používá stávající validaci z `InputValidator`. Nestačí-li, Document třídy přidají business validaci.

#### `recalculate` logika:

1. Request body: `{id, changedColumn, data}`
2. Načti FormDefinition přes FormRegistry / JSONC / Auto
3. Zavolej `tableForm->recalculate($changedColumn, $data)`
4. Vrátí `{"success": true, "formDefinition": {...}, "data": {...}}`
5. Uloží se do DB? **NE** — recalculate je pouze výpočetní operace.

## Aktualizace stávajících souborů

### `src/Api/Router.php`

Přidej routy pro `/_ui/form/...`:

```
GET  /_ui/form/{table}/meta          → form:meta (bez ID)
GET  /_ui/form/{table}/meta/{id}     → form:meta (s ID)
POST /_ui/form/{table}/save          → form:save (nový)
PUT  /_ui/form/{table}/save/{id}     → form:save (existující)
POST /_ui/form/{table}/recalculate   → form:recalculate
```

Routy musí být před generickým `{table}` pattern.

### `public/index.php`

Přidej `FormController` do dispatch pipeline (analogicky jako `ViewerController`).

### `src/Core/Form/` — adresář

Vytvoř adresář a všechny třídy výše.

## Testy

Přidej unit testy do `tests/Unit/Core/Form/`:

- `AutoFormBuilderTest.php` — generuje korektní FormDefinition z jednoduché TableDefinition
- `FormDefinitionTest.php` — toArray() serializace je správná, docStates se přidají správně

Integrační test (pokud je infrastruktura k dispozici):
- `FormControllerTest.php` — GET `/_ui/form/core_system_users/meta` vrátí validní JSON

## Konvence a upozornění

- Vzor pro nové třídy: podívej se na `src/Core/Viewer/TableViewer.php` a `ViewerController.php`
- Všechny datové třídy: `readonly` properties kde možné
- `toArray()` metody: snake_case klíče (frontend očekává snake_case)
- Neměnit stávající `FormRenderer.svelte` ani `FormDialog.svelte` — to je Fáze 4
- Neměnit `CrudController.php` — FormController volá existující logiku, nepřepisuje ji

## Hotovo když

- [ ] `GET /_ui/form/core_system_users/meta` vrátí FormDefinition s automaticky generovanými poli
- [ ] `GET /_ui/form/base_persons_persons/meta/1` vrátí FormDefinition + data záznamu
- [ ] `POST /_ui/form/core_system_users/save` vytvoří nového uživatele
- [ ] `PUT /_ui/form/core_system_users/save/{id}` aktualizuje uživatele
- [ ] `POST /_ui/form/core_system_users/recalculate` vrátí FormDefinition + data (bez uložení)
- [ ] Testy projdou
