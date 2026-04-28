# Shipard — Editační formuláře (Edit Forms)

## 1. Přehled a cíle

Editační formuláře jsou klíčovým prvkem UX celého systému. Architektura je:

- **Server-driven** — server definuje obsah, layout i chování formuláře; klient ho jen renderuje
- **Generická na klientovi** — jedna sada Svelte komponent zvládne formuláře pro všechny tabulky
- **Flexibilní na serveru** — jednodušší tabulky = JSONC definice bez PHP kódu; složitější = PHP třída `TableForm`
- **Responzivní** — 4-sloupcový grid systém, řídí layout na různých šířkách obrazovky
- **Integrovaná s doc states** — stavová tlačítka, readOnly formuláře, `closeForm` chování

---

## 2. Architektura — přehled

```
FormEditor.svelte          (frontend — hlavní shell: záhlaví, taby, toolbar)
  ├─ FormTab.svelte        (jeden tab — CSS Grid 4 sloupce)
  │   └─ FormElement.svelte (dynamický renderer elementu)
  └─ FormSubTable.svelte   (sub-editor related tabulky)
       ↕ REST API
FormController             (PHP — meta, save, recalculate)
  ↕
TableForm (abstract)       (PHP — bázová třída; TableDefinition, TabBuilder)
  ↕
PersonsForm                (PHP — konkrétní Form pro base.persons)
  — nebo —
forms/{table}.jsonc        (JSONC — deklarativní definice pro jednoduché formuláře)
  — nebo —
AutoFormBuilder            (PHP — automatická generace z TableDefinition)
```

### Srovnání s Viewer systémem

| Viewer | Form |
|--------|------|
| `TableViewer` (abstraktní) | `TableForm` (abstraktní) |
| `PersonsViewer extends TableViewer` | `PersonsForm extends TableForm` |
| `GET /_ui/viewer/{id}/meta` | `GET /_ui/form/{table}/meta[/{id}]` |
| Registrace v `module.jsonc` → `viewers` | Registrace v `module.jsonc` → `forms` |

---

## 3. FormDefinition — datová struktura

Server vrací `FormDefinition` z endpointu `/_ui/form/{table}/meta`. Klient ji renderuje bez per-formulář JS logiky.

### Kořenová struktura (JSON)

```json
{
    "success": true,
    "formDefinition": {
        "table": "base_persons_persons",
        "title": "Osoba",
        "title_new": "Nová osoba",
        "full_size": true,
        "tabs": [ { "...tab..." } ],
        "doc_states": {
            "currentState": 10,
            "stateName": "Koncept",
            "stateStyle": "concept",
            "read_only": false,
            "transitions": [
                {"state": 40, "actionName": "V pořádku", "stateStyle": "done", "close_form": true},
                {"state": 70, "actionName": "Ukončit platnost", "stateStyle": "archive", "close_form": true},
                {"state": 90, "actionName": "Smazat", "stateStyle": "trash", "close_form": true}
            ]
        }
    },
    "data": {
        "id": 42,
        "full_name": "Jan Novák",
        "person_type": 1
    }
}
```

**Poznámka:** Všechny klíče jsou snake_case — `full_size`, `title_new`, `doc_states`, `read_only`, `close_form`, `foreign_key`, `form_id`, `input_type`, `read_only`.

| Pole | Typ | Popis |
|------|-----|-------|
| `table` | string | DB název tabulky |
| `title` | string | Nadpis pro editaci existujícího záznamu |
| `title_new` | string | Nadpis pro nový záznam |
| `full_size` | bool | true = velký modal (1200×900px) pro hlavní entity, false = malý modal (720px, výška dle obsahu) pro sub-záznamy |
| `tabs` | Tab[] | Seznam tabů (min. 1) |
| `doc_states` | DocStatesInfo \| null | Info o stavech; přítomno i pro nový záznam (výchozí stav 10) |

### Tab

```json
{
    "id": "basic",
    "label": "Základní údaje",
    "elements": [ { "...element..." } ]
}
```

Formulář má vždy alespoň jeden tab. Je-li tab jen jeden, tab bar se nezobrazí.

---

## 4. Elementy formuláře

### 4.1 `input`

```json
{
    "type": "input",
    "column": "full_name",
    "label": "Celý název",
    "cols": 2,
    "required": true,
    "hidden": false,
    "read_only": false,
    "triggers": "reload",
    "input_type": "text"
}
```

`input_type` určuje typ UI komponenty: `text` (výchozí), `email`, `tel`, `url`, `password`, `number`, `date`, `datetime`, `time`, `textarea`, `checkbox`. Odvozuje se automaticky z DB typu sloupce.

Hodnota je validována v konstruktoru `FormElement` proti whitelistu — neplatný řetězec (např. `datetime-local`) vyhodí `InvalidArgumentException`. Platí pro PHP builder i pro JSONC (`JsoncFormLoader` předává hodnotu do stejného konstruktoru, whitelist se aplikuje automaticky).

| Pole | Výchozí | Popis |
|------|---------|-------|
| `column` | — | ID sloupce v DB |
| `label` | z TableDefinition | Automaticky doplněn z názvu sloupce pokud chybí |
| `cols` | 1 | Šířka 1–4 v 4-sloupcovém gridu |
| `required` | false | Hvězdička u labelu |
| `hidden` | false | Skryto (`display: none`), pole zůstává v DOM |
| `read_only` | false | Disabled input |
| `triggers` | null | `"reload"` = při změně spustit recalculate |
| `input_type` | `"text"` | Typ UI komponenty |

### 4.2 `select`

```json
{
    "type": "select",
    "column": "person_type",
    "label": "Typ osoby",
    "cols": 1,
    "triggers": "reload",
    "options": [
        {"value": 0, "label": "Neurčeno"},
        {"value": 1, "label": "Fyzická osoba"},
        {"value": 2, "label": "Firma"}
    ]
}
```

`options` se generují na serveru z `cfgItem` sloupce.

### 4.3 `separator`

```json
{
    "type": "separator",
    "label": "Jméno osoby",
    "hidden": false
}
```

Horizontální linka s textem přes celou šířku gridu. `hidden` se nastavuje automaticky v `TabBuilder::build()` pokud jsou všechny elementy za separátorem skryté (`autoHideSeparators`).

### 4.4 `group`

```json
{
    "type": "group",
    "label": "Jméno",
    "cols": 4,
    "elements": [ { "...vnořené elementy..." } ]
}
```

Vnořený grid s nadpisem.

### 4.5 `subtable`

```json
{
    "type": "subtable",
    "table": "base_persons_contacts",
    "foreign_key": "person",
    "form_id": "base.persons.contacts",
    "cols": 4
}
```

Tab věnovaný sub-editoru. Renderuje tabulku řádků + Přidat/Upravit/Smazat.

### 4.6 `html`

```json
{
    "type": "html",
    "content": "<p>Poznámka</p>",
    "cols": 4
}
```

---

## 5. DocStates v FormDefinition

`doc_states` je přítomno vždy pokud tabulka má doc states — i pro nový záznam (výchozí stav 10).

```json
{
    "currentState": 40,
    "stateName": "V pořádku",
    "stateStyle": "done",
    "read_only": true,
    "transitions": [
        {"state": 80, "actionName": "Opravit", "stateStyle": "edit", "close_form": false},
        {"state": 70, "actionName": "Ukončit platnost", "stateStyle": "archive", "close_form": true},
        {"state": 90, "actionName": "Smazat", "stateStyle": "trash", "close_form": true}
    ]
}
```

### `close_form` flag

Každý přechod stavu má `close_form: bool` (výchozí `false`). Definuje se v cfgItem konfigurace stavů (`docStatesArchive.jsonc` apod.).

| `close_form` | Chování po přechodu |
|---|---|
| `false` | Formulář zůstane otevřený, data se reloadnou |
| `true` | Formulář se zavře a vrátí do Vieweru |

Standardní nastavení v `core.system.docStatesArchive`:
- Koncept (10), V opravě (80) → `close_form: 0`
- V pořádku (40), V archívu (70), Smazáno (90) → `close_form: 1`

### Toolbar formuláře (FormStateBar)

- **Tlačítko Uložit** — viditelné pokud `!read_only`. Uloží data, ale formulář nezavře.
- **Přechodová tlačítka** — ze `transitions`. U existujících záznamů nejdříve uloží data, pak přepne stav. Pokud `close_form`, zavře formulář.
- **ReadOnly formulář** — všechna pole disabled, tlačítko Uložit skryto, jen přechodová tlačítka.

---

## 6. Grid systém

4-sloupcový CSS Grid. Responzivní breakpointy:

| Breakpoint | Grid |
|------------|------|
| Desktop ≥ 900px | 4 sloupce (`repeat(4, 1fr)`) |
| Tablet 600–899px | 2 sloupce |
| Mobil < 600px | 1 sloupec |

Element s `cols: 2` → `grid-column: span 2`. `separator`, `subtable`, plný `group` → `grid-column: 1 / -1`.

---

## 7. Recalculate — dynamické přepočítání

Elementy s `"triggers": "reload"` spustí recalculate při změně hodnoty.

### Flow

1. Uživatel změní hodnotu (např. `person_type`)
2. Klient pošle `POST /_ui/form/{table}/recalculate`:
   ```json
   {
       "id": 42,
       "changedColumn": "person_type",
       "data": { "...aktuální data všech polí..." }
   }
   ```
3. Server zavolá `TableForm::recalculate()`, vrátí novou FormDefinition + přepočítaná data
4. Klient překreslí formulář

Recalculate **neukládá** do DB.

### Automatické skrývání separátorů

`TabBuilder::build()` volá `autoHideSeparators()` — separátor se automaticky skryje pokud jsou všechny elementy za ním (do dalšího separátoru) skryté. Vývojář nemusí ručně nastavovat `hidden` na separátory, ale může to udělat explicitně pro přehlednost.

---

## 8. Validace a chybové stavy

Server vrátí při chybě:
```json
{
    "success": false,
    "error": {
        "code": "VALIDATION_ERROR",
        "details": [
            {"field": "last_name", "code": "required", "message": "Příjmení je povinné"}
        ]
    }
}
```

Klient zobrazí chyby u příslušných polí. Pokud je chybné pole na neaktivním tabu, klient automaticky přepne na ten tab.

### Sanitizace dat před odesláním

`FormEditor` provádí `sanitizeFormData()` před každým odesláním:
- `select` s numerickými options → převede string na number (HTML `<select>` vždy vrací string)
- `input_type: date/number/datetime...` s prázdnou hodnotou → převede `""` na `null`

---

## 9. fullSize flag — velikost modalu

`FormDialog.svelte` vždy renderuje formulář v Modal komponentě (centrovaný popup nad tmavým overlayem). `full_size` určuje pouze **velikost** modalu:

- `true` → velký modal: šířka `1200px`, výška `min(900px, 90vh)`. Pro hlavní entity (Osoby, Faktury…).
- `false` → malý modal: šířka `720px`, výška dle obsahu (max `90vh`). Pro sub-záznamy (Kontakt, Adresa…).

### Chování modalu

- **Header** — Modal vlastní header s titulkem (`formDef.title` / `formDef.title_new`), `FormStateBadge` (přes `headerExtra` snippet) a tlačítkem `×` vpravo nahoře. FormEditor vlastní header nemá.
- **Body skroluje** — header a `FormStateBar` zůstávají fixní, skroluje pouze tělo formuláře.
- **Zavření** — `Esc` nebo klik na overlay (mimo kartu modalu) nebo tlačítko `×`. Všechny tři způsoby volají stejný `onClose` callback.
- **Body scroll lock** — modal blokuje scrollování stránky pod sebou.

### Detekce neuložených změn (dirty state)

FormEditor sleduje změny dat oproti snapshotu pořízenému při posledním načtení nebo uložení. Stav propaguje do FormDialogu přes `onDirtyChange` callback. Když je formulář dirty a uživatel se ho pokusí zavřít (Esc, klik na overlay, tlačítko `×`), zobrazí se nativní `window.confirm` s textem „Máte neuložené změny. Opravdu chcete zavřít formulář?". Tlačítka Uložit a stavová tlačítka kontrolu obcházejí — ta změny ukládají, ne ztrácejí.

- **ReadOnly formuláře nikdy nejsou dirty** — uživatel nemůže nic změnit.
- **Recalculate NEaktualizuje snapshot** — recalculate neukládá do DB, takže přepočítaná data jsou stále neuložená změna. Po triggeru je formulář dirty (server typicky přepočítá hodnoty) a uživatel musí změnu explicitně uložit. Pokud zavře bez uložení, confirm dialog ho upozorní.
- **`null` vs `''`** — porovnání tyto dvě hodnoty považuje za rovné (server vrací `null` u nullable polí, formulář je interně reprezentuje jako `''`).
- **Subtables** — každá instance FormDialogu (Osoba, Kontakt v Osobě) má vlastní dirty check. Otevřený subdialog Kontaktu sleduje změny svých polí nezávisle na rodičovské Osobě.

### Force close — bypass dirty kontroly

`FormDialog.handleClose` přijímá volitelný parametr `{ force?: boolean }`. Když je `force: true`, dirty kontrola se přeskočí. Používá se po úspěšném save + closeForm v stavovém přechodu (např. „V pořádku" u nového záznamu): FormEditor sám ví, že data jsou uložená, a confirm dialog je nežádoucí.

Důvod existence tohoto mechanismu je timing Svelte reaktivity. Když FormEditor po uspěšném save aktualizuje snapshot, `isDirty` derived state se přepočítá až v dalším mikrotasku. Pokud by FormEditor okamžitě synchronně zavolal `onClose()`, FormDialog by ještě viděl starý `isDirty: true` a zobrazil by zbytečný confirm. `force: true` to obchází bez závislosti na pořadí reaktivních updatů.

Modal komponenta (Esc, klik na overlay, `×`) volá `onClose()` bez parametru — tyto akce **mají** procházet dirty kontrolou. `force: true` posílá pouze FormEditor po vlastním úspěšném uložení.

### Vrstvení modalů (Esc handling)

`Modal.svelte` používá module-level stack otevřených modalů. Esc handler reaguje pouze na modal na vrcholu stacku. Bez tohoto by Esc v subdialogu Kontaktu zavřel současně Kontakt i nadřazenou Osobu (oba modaly poslouchají window keydown).

Klik na overlay tento problém nemá — overlay každého modalu zachytí jen kliky na vlastní plochu. Tlačítko `×` je per-modal element. Esc je ale globální event, proto vyžaduje stack.

---

## 10. API endpointy

| Endpoint | Metoda | Popis |
|----------|--------|-------|
| `/_ui/form/{table}/meta` | GET | FormDefinition pro nový záznam |
| `/_ui/form/{table}/meta/{id}` | GET | FormDefinition + data pro existující záznam |
| `/_ui/form/{table}/save` | POST | Uložení nového záznamu |
| `/_ui/form/{table}/save/{id}` | PUT | Uložení existujícího záznamu |
| `/_ui/form/{table}/recalculate` | POST | Přepočítání bez uložení |

### Detekce přechodu stavu

`PUT /save/{id}` s tělem obsahujícím **pouze** `docState` se zpracuje jako čistý přechod stavu (přes `applyStateTransition` bez Document lifecycle). Běžné uložení jde přes `TableGateway` + `Document::validate/beforeSave`.

---

## 11. PHP třída `TableForm`

```php
abstract class TableForm
{
    protected string $table;
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConnection $db = null;
    protected ?TableDefinition $tableDef = null;  // pro auto-label

    abstract public function buildFormDefinition(array $data, bool $isNew): FormDefinition;

    public function recalculate(string $changedColumn, array $data): RecalculateResult { ... }

    protected function tab(string $id, string $label): TabBuilder { ... }
    // tab() automaticky předá colLabels z TableDefinition do TabBuilder
}
```

### Auto-label z TableDefinition

`TableForm` dostane `TableDefinition` přes `setTableDef()` před voláním `buildFormDefinition`. Helper `tab()` sestaví mapu `column_id => name` a předá ji `TabBuilder`. `addInput`/`addSelect` pak doplní `label` automaticky z této mapy pokud není zadán explicitně.

### TabBuilder API

`addInput()` je jen pro **text varianty** (`text`, `email`, `tel`, `url`, `password`). Pro všechno ostatní jsou **dedikované metody** — jsou sebedokumentující a builder se nedá tiše rozbít překlepem v `inputType`. Pokus o `addInput(..., inputType: 'textarea')` vyhodí `InvalidArgumentException`.

| Metoda | `inputType` | Použití pro DB typ |
|--------|-------------|--------------------|
| `addInput` | `null`/`text`/`email`/`tel`/`url`/`password` | `char`, `varchar` (krátký text, kontaktní údaje) |
| `addTextArea` | `textarea` | `text`, `longtext` |
| `addDate` | `date` | `date` |
| `addDateTime` | `datetime` | `datetime` |
| `addTime` | `time` | `time` |
| `addNumber` | `number` | `int`, `smallint`, `bigint`, `tinyint`, `numeric`, `float` |
| `addCheckbox` | `checkbox` | `boolean` |
| `addSelect` | — | `enumInt`, `enumString` (options z cfgItem) |

```php
$tab->addInput(
    string $column,
    int $cols = 1,
    ?string $label = null,      // null = auto z TableDefinition
    bool $required = false,
    ?string $triggers = null,   // 'reload' nebo null
    bool $readOnly = false,
    bool $hidden = false,
    ?string $placeholder = null,
    ?string $hint = null,
    ?string $inputType = null,  // jen text varianty: null, text, email, tel, url, password
): static

// Dedikované widgety — konzistentní signatura (bez placeholder/triggers)
$tab->addTextArea(string $column, int $cols = 4, ?string $label = null, bool $required = false, bool $readOnly = false, bool $hidden = false, ?string $hint = null): static
$tab->addDate(string $column, int $cols = 1, ...): static
$tab->addDateTime(string $column, int $cols = 1, ...): static
$tab->addTime(string $column, int $cols = 1, ...): static
$tab->addNumber(string $column, int $cols = 1, ...): static
$tab->addCheckbox(string $column, int $cols = 1, ...): static

$tab->addSelect(
    string $column,
    int $cols = 1,
    ?string $label = null,
    ?array $options = null,     // null = auto z cfgItem
    ?string $triggers = null,
    bool $required = false,
    bool $readOnly = false,
    bool $hidden = false,
): static

$tab->addSeparator(?string $label = null, bool $hidden = false): static
$tab->openGroup(string $label, int $cols = 4): static
$tab->closeGroup(): static
$tab->addSubtable(string $table, string $foreignKey, ?string $formId, ?string $label, int $cols = 4): static
$tab->addHtml(string $content, int $cols = 4): static
$tab->build(): FormTab  // volá autoHideSeparators()
```

---

## 12. Deklarativní JSONC definice

Pro jednoduché formuláře bez business logiky.

**Umístění:** `modules/{skupina}/{modul}/forms/{table}.jsonc`

```jsonc
{
    "title": "Kontakt",
    "titleNew": "Nový kontakt",
    "fullSize": false,
    "tabs": [
        {
            "id": "basic",
            "label": "Kontakt",
            "elements": [
                {"type": "input", "column": "name", "cols": 2, "required": true},
                {"type": "input", "column": "email", "cols": 2},
                {"type": "select", "column": "address_type", "cols": 1}
            ]
        }
    ]
}
```

Labely a typy inputů se doplní z TableDefinition pokud chybí.

### Priorita výběru formuláře

1. PHP třída registrovaná v `module.jsonc` → `forms[].class`
2. JSONC soubor `forms/{table}.jsonc` v adresáři modulu
3. Automatická generace z `TableDefinition` (AutoFormBuilder)

---

## 13. Registrace v `module.jsonc`

```jsonc
{
    "forms": [
        {
            "table": "base_persons_persons",
            "class": "Shipard\\Module\\Base\\Persons\\PersonsForm"
        },
        {
            "table": "base_persons_contacts",
            "id": "base.persons.contacts"
            // bez "class" → hledá se forms/base_persons_contacts.jsonc
        }
    ]
}
```

`id` umožňuje odkazovat na formulář jako `form_id` v `subtable` elementu.

---

## 14. Document lifecycle ve FormController

`FormController::save` prochází přes `TableGateway`, který volá:
1. `Document::validate()` — business validace (povinná pole, podmínky dle typu)
2. `Document::beforeSave()` — transformace (generování `person_id`, dopočítání `full_name`)
3. INSERT/UPDATE
4. `Document::afterSave()`

`Document` dostane přístup k DB přes `setDb()` volaný z `TableGateway` — lze použít pro generování unikátních kódů apod.

`DocumentRegistry` se načítá z `module.jsonc` → `documentClasses` přes `DocumentLoader` a musí být předán jako parametr funkci `dispatch()` v `index.php`.

---

## 15. Sub-tabulky (FormSubTable)

- Sub-záznamy se ukládají **okamžitě** při potvrzení mini-dialogu (ne s hlavním formulářem)
- Pro **nový záznam** (rodič nemá ID) jsou taby se sub-tabulkami disabled s informací „Nejprve uložte záznam"
- Po uložení rodiče se `currentId` aktualizuje a sub-tabulky se odemknou

---

## 16. Svelte komponenty

| Komponenta | Popis |
|------------|-------|
| `Modal.svelte` (ui/) | Generický modal: header s titulkem a `×`, tělo, overlay, body scroll lock, modal stack pro Esc handling. Volitelný `headerExtra` snippet pro badge, `width` a `height` props. |
| `FormDialog.svelte` | Orchestrátor — načte meta, vybere velikost modalu (large/small), poskytuje header (titulek + badge), drží dirty stav, zobrazí confirm při zavření |
| `FormEditor.svelte` | Hlavní shell: tab bar, obsah, toolbar (header je v Modal). Sleduje dirty stav (snapshot vs aktuální data), propaguje titulek/doc_states/dirty zpět do FormDialog přes callbacky `onFormLoaded` a `onDirtyChange` |
| `FormTab.svelte` | Jeden tab — CSS Grid 4 sloupce |
| `FormElement.svelte` | Renderer elementu; rekurzivní pro `group` |
| `FormSubTable.svelte` | Editor sub-tabulky s CRUD |
| `FormStateBar.svelte` | Spodní toolbar: Uložit + přechodová tlačítka |
| `FormStateBadge.svelte` | Badge stavu v záhlaví Modalu |

---

## 17. PHP třídy a soubory

### `src/Core/Form/`
| Třída | Popis |
|-------|-------|
| `TableForm` | Abstraktní bázová třída; auto-label z TableDefinition |
| `TabBuilder` | Fluent builder; autoHideSeparators |
| `FormDefinition` | Datová třída; `toArray()` → snake_case JSON |
| `FormTab` | Datová třída |
| `FormElement` | Datová třída; `input_type`, `hidden`, `triggers` |
| `FormRegistry` | Registr PHP tříd formulářů |
| `FormController` | HTTP controller |
| `AutoFormBuilder` | Generuje FormDefinition z TableDefinition |
| `JsoncFormLoader` | Načítá JSONC formy |
| `RecalculateResult` | Výsledek recalculate |

### `src/Api/`
| Soubor | Popis |
|--------|-------|
| `FormLoader.php` | Načte FormRegistry z modulů |
| `DocumentLoader.php` | Načte DocumentRegistry z modulů |

---

## 18. `closeForm` v definici stavů dokumentů

V cfgItem konfigurace stavů (např. `docStatesArchive.jsonc`) lze u každého stavu nastavit:

```jsonc
"40": {
    "stateName": "V pořádku",
    "closeForm": 1,
    "goto": [80, 70, 90]
}
```

`closeForm: 1` → po přechodu do tohoto stavu se formulář zavře. `closeForm: 0` (výchozí) → formulář zůstane otevřený.

`DocStateConfig::getAvailableTransitions()` vrátí `close_form: bool` u každého přechodu v API odpovědi.

---

## 19. Implementační poznámky a pastče

### `dispatch()` a sdílené proměnné v `index.php`

Funkce `dispatch()` je globální PHP funkce — nemá přístup k lokálním proměnným z `try` bloku. Každý kontext musí být explicitně předán jako parametr i do signatury funkce.

```php
// Špatně — $documentRegistry existuje v try bloku, ale dispatch() ho nevidí
dispatch($route, ..., $formRegistry);

// Správně
dispatch($route, ..., $formRegistry, $documentRegistry);
function dispatch(..., ?DocumentRegistry $documentRegistry = null): Response { ... }
```

### `{#each}` klíče ve Svelte 5

`Math.random()` jako klíč způsobuje destrukci komponent při každém re-renderu. Pro elementy bez unikátního ID:
```js
{#each tab.elements as element, i (element.column ?? `${element.type}-${i}`)}
```

### `Select` a callback props ve Svelte 5

Svelte 5 komponenty nepředávají DOM eventy automaticky. `onchange` musí být explicitní prop v interface + předán na interní `<select>`.

### Validace `enumInt` v `InputValidator`

cfgItem je mapa klíčů `{"0": {...}, "1": {...}}`. Správná validace:
```php
array_key_exists((string) $value, $cfgData)  // ✓
in_array($value, $cfgData)                    // ✗ hodnoty jsou objekty, ne skalary
```

### Business logika vs. DB constraints

`InputValidator` validuje pouze DB constraints. Sloupce vyplňované automaticky v `beforeSave` musí být v DB `nullable` — jinak validátor odmítne data dřív než se `beforeSave` zavolá.

### `Document` má přístup k DB

`TableGateway` volá `$doc->setDb($this->db)` před každým hookem. Document subclassy mohou používat `$this->db` pro vlastní dotazy (generování unikátních kódů, `person_id` apod.).

### Datum/čas z Dibi

Dibi vrací DATE a DATETIME sloupce jako `Dibi\DateTime` objekty. `DataSourceConnection::fetchRow/fetchAll` je normalizuje na stringy: DATE → `"YYYY-MM-DD"`, DATETIME → `"YYYY-MM-DDTHH:MM:SS"`.

### Envelope konvence

Všechny API odpovědi mají tvar `{ success, data, meta? }`. Data jsou vždy v `res.data`, nikdy přímo v `res`. Např. `res.data.formDefinition`, ne `res.formDefinition`.
