# Shipard — Editační formuláře (Edit Forms)

## 1. Přehled a cíle

Editační formuláře jsou klíčovým prvkem UX celého systému. Uživatel s nimi tráví nejvíce času — každá osoba, faktura, bankovní účet či nastavení se edituje přes formulář. Architektura musí být:

- **Server-driven** — server definuje obsah, layout i chování formuláře; klient ho jen renderuje
- **Generická na klientovi** — jedna Svelte komponenta zvládne formuláře pro všechny tabulky
- **Flexibilní na serveru** — jednodušší tabulky = JSONC definice bez PHP kódu; složitější = PHP třída
- **Responzivní** — grid systém řídí layout na různých šířkách obrazovky
- **Konzistentní** — stavová tlačítka, validace, taby, sub-tabulky — vždy stejné UX

---

## 2. Architektura — přehled

```
FormEditor.svelte          (frontend — hlavní shell: taby, toolbar, doc state)
  ├─ FormTab.svelte        (jeden tab s elementy)
  │   └─ FormElement.svelte (dynamický renderer elementu)
  └─ FormSubTable.svelte   (sub-editor pro related tabulku)
       ↕ REST API
FormController             (PHP — meta, load, save, recalculate, doc-state-options)
  ↕
TableForm (abstract)       (PHP — bázová třída; metody addInput, addTab, addSeparator…)
  ↕
PersonsForm                (PHP — konkrétní Form pro base.persons)
  — nebo —
form.jsonc                 (JSONC — deklarativní definice pro jednoduché formuláře)
```

### Srovnání s Viewer systémem

Viewer a Form systémy jsou záměrně symetrické:

| Viewer | Form |
|--------|------|
| `TableViewer` (abstraktní) | `TableForm` (abstraktní) |
| `PersonsViewer extends TableViewer` | `PersonsForm extends TableForm` |
| `GET /_ui/viewer/{id}/meta` | `GET /_ui/form/{table}/meta` |
| `GET /_ui/viewer/{id}/rows` | `GET /_ui/form/{table}/load/{id}` |
| `GET /_ui/viewer/{id}/detail/{id}` | — |
| Registrace v `module.jsonc` → `viewers` | Registrace v `module.jsonc` → `forms` |

---

## 3. FormDefinition — datová struktura

Server vrací `FormDefinition` — kompletní popis obsahu a chování formuláře. Klient ho renderuje bez jakékoliv per-formulář logiky.

### Kořenová struktura

```json
{
    "table": "base_persons_persons",
    "title": "Osoba",
    "titleNew": "Nová osoba",
    "tabs": [
        { "...tab..." }
    ],
    "docStates": {
        "currentState": 10,
        "stateName": "Koncept",
        "stateStyle": "concept",
        "readOnly": false,
        "transitions": [
            {"state": 40, "actionName": "V pořádku", "stateStyle": "done"},
            {"state": 70, "actionName": "Ukončit platnost", "stateStyle": "archive"},
            {"state": 90, "actionName": "Smazat", "stateStyle": "trash"}
        ]
    }
}
```

| Pole | Typ | Popis |
|------|-----|-------|
| `table` | string | ID tabulky |
| `title` | string | Nadpis formuláře pro editaci existujícího záznamu |
| `titleNew` | string | Nadpis formuláře pro nový záznam |
| `tabs` | Tab[] | Seznam tabů (min. 1) |
| `docStates` | DocStatesInfo \| null | Info o doc states; null pokud tabulka nemá doc states |

### Tab

```json
{
    "id": "basic",
    "label": "Základní údaje",
    "elements": [ { "...element..." } ]
}
```

| Pole | Typ | Popis |
|------|-----|-------|
| `id` | string | Unikátní ID tabu v rámci formuláře |
| `label` | string | Popis tabu v tab baru |
| `elements` | Element[] | Seznam elementů (viz sekce 4) |

Formulář má vždy alespoň jeden tab. Je-li tab jen jeden, tab bar se nezobrazí — layout je plynulý.

---

## 4. Elementy formuláře

Každý element má povinné pole `type`. Ostatní pole závisí na typu.

### 4.1 `input` — textový vstup

```json
{
    "type": "input",
    "column": "full_name",
    "label": "Celý název",
    "placeholder": "Název firmy nebo celé jméno",
    "cols": 2,
    "required": true,
    "triggers": "reload"
}
```

| Pole | Typ | Výchozí | Popis |
|------|-----|---------|-------|
| `column` | string | — | ID sloupce v DB |
| `label` | string | — | Popisek nad inputem |
| `placeholder` | string | null | Placeholder text |
| `cols` | 1–4 | 1 | Šířka v grid systému (viz sekce 6) |
| `required` | bool | false | Označí pole jako povinné (hvězdička) |
| `readOnly` | bool | false | Pole zobrazeno, ale nelze editovat |
| `hidden` | bool | false | Pole je skryté (přítomno v DOM, ale display:none) |
| `triggers` | string \| null | null | `"reload"` = při změně hodnoty spustit recalculate |
| `hint` | string | null | Pomocný text pod inputem |

Typ inputu (text, number, date, checkbox…) se odvozuje automaticky z typu DB sloupce, stejně jako dnes v `FormField.svelte`.

### 4.2 `select` — výběrový seznam

```json
{
    "type": "select",
    "column": "person_type",
    "label": "Typ osoby",
    "options": [
        {"value": 0, "label": "Neurčeno"},
        {"value": 1, "label": "Fyzická osoba"},
        {"value": 2, "label": "Firma"}
    ],
    "cols": 1,
    "triggers": "reload"
}
```

Pole `options` se generuje na serveru z cfgItem. Klient je renderuje do `<select>`.

### 4.3 `separator` — horizontální oddělovač se štítkem

```json
{
    "type": "separator",
    "label": "Jméno osoby"
}
```

Odděluje logické sekce uvnitř tabu. Zabírá celou šířku gridu bez ohledu na nastavení `cols`.

### 4.4 `group` — skupina s nadpisem

```json
{
    "type": "group",
    "label": "Jméno",
    "cols": 2,
    "elements": [
        {"type": "input", "column": "first_name", "label": "Jméno", "cols": 1},
        {"type": "input", "column": "last_name", "label": "Příjmení", "cols": 1}
    ]
}
```

Skupina tvoří vizuální blok (rámeček nebo tučný nadpis) a má vlastní vnitřní grid. Umožňuje hierarchické skládání layoutu.

### 4.5 `subtable` — sub-editor pro related tabulku

```json
{
    "type": "subtable",
    "label": "Kontakty",
    "table": "base_persons_contacts",
    "foreignKey": "person",
    "formId": "base.persons.contacts",
    "cols": 4
}
```

Tento element se používá **výhradně v tabulkových tabech** (tab je plně věnován sub-editoru). Klient ho renderuje jako `FormSubTable.svelte` — tabulka s řádky, tlačítka Přidat / Upravit / Smazat, otevírá mini-FormDialog pro editaci jednoho řádku.

| Pole | Typ | Popis |
|------|-----|-------|
| `table` | string | ID sub-tabulky |
| `foreignKey` | string | Sloupec v sub-tabulce odkazující na rodičovský záznam |
| `formId` | string | ID formuláře pro editaci jednoho řádku (registrováno v `forms`) |

### 4.6 `html` — statický HTML blok

```json
{
    "type": "html",
    "content": "<p class=\"shpd-form-note\">Tato pole se vyplní automaticky po uložení.</p>",
    "cols": 4
}
```

Pro poznámky, nápovědu nebo custom obsah. Server generuje bezpečné HTML (žádné skripty).

---

## 5. DocStates v FormDefinition

Pokud tabulka má `docStates`, `FormDefinition` obsahuje pole `docStates`:

```json
{
    "currentState": 40,
    "stateName": "V pořádku",
    "stateStyle": "done",
    "readOnly": true,
    "transitions": [
        {"state": 80, "actionName": "Opravit", "stateStyle": "edit"},
        {"state": 70, "actionName": "Ukončit platnost", "stateStyle": "archive"},
        {"state": 90, "actionName": "Smazat", "stateStyle": "trash"}
    ]
}
```

Pro **nový záznam** (před prvním uložením) je `docStates` přítomno s `currentState` = výchozí stav (obvykle 10) a `transitions` obsahuje dostupné přechody z výchozího stavu.

### Chování formuláře dle doc states

| Situace | Chování formuláře |
|---------|-------------------|
| `readOnly: false` | Formulář je editovatelný, zobrazí se tlačítka přechodů |
| `readOnly: true` | Všechna pole jsou disabled, zobrazí se pouze `transitions` tlačítka |
| Přechod na readOnly stav | Po úspěšném uložení se formulář překreslí s novými daty (reload FormDefinition) |
| Přechod "Opravit" (readOnly→edit) | Server vrátí novou FormDefinition s `readOnly: false` |

### Toolbar formuláře

Spodní toolbar (nebo horní — viz sekce 9) obsahuje:

- **Tlačítko Uložit** (viditelné jen je-li `readOnly: false`, nebo u nového záznamu)
- **Tlačítka přechodů** ze `transitions` — každé volá `PATCH /{table}/{id}` s `{docState: X}` a poté reload
- **Badge aktuálního stavu** v záhlaví formuláře (`stateName` + CSS třída `docState_{stateStyle}`)

---

## 6. Grid systém — responzivní layout

Každý element má pole `cols` (1–4). Grid je 4-sloupcový. Responzivní chování:

| Breakpoint | Grid | Element `cols` |
|------------|------|----------------|
| Desktop (≥ 900px) | 4 sloupce | dle `cols` (1–4) |
| Tablet (600–899px) | 2 sloupce | min(`cols`, 2) |
| Mobil (< 600px) | 1 sloupec | vždy 1 |

**Implementace:** CSS Grid s `grid-template-columns: repeat(4, 1fr)` na desktopu. Element s `cols: 2` dostane `grid-column: span 2`. `separator` a `group` s `cols: 4` = `grid-column: 1 / -1` (plná šířka).

**Typické hodnoty `cols`:**
- `cols: 1` — úzká pole (PSČ, telefon, kód osoby)
- `cols: 2` — středně široká pole (název, e-mail, ulice)
- `cols: 4` — plná šířka (poznámka jako textarea, sub-tabulka)

Breakpointy jsou definovány jako CSS custom properties v `variables.css`.

---

## 7. Recalculate — dynamické přepočítání formuláře

### Princip

Určité elementy mají `"triggers": "reload"`. Když uživatel změní hodnotu takového pole, klient:

1. Sbere aktuální stav formuláře (všechna data ze všech tabů, i neaktivních)
2. Odešle `POST /_ui/form/{table}/recalculate` s tělem:
   ```json
   {
       "id": 42,
       "changedColumn": "person_type",
       "data": { "...všechna aktuální data formuláře..." }
   }
   ```
3. Server vrátí novou `FormDefinition` (s případně skrytými/zobrazenými poli) a přepočítaná data:
   ```json
   {
       "success": true,
       "formDefinition": { "...nová FormDefinition..." },
       "data": { "...přepočítaná data..." }
   }
   ```
4. Klient překreslí formulář s novou definicí a naplní pole novými daty

### Příklad — přepínač Firma / Fyzická osoba

Pole `person_type` má `"triggers": "reload"`. Po přepnutí na "Fyzická osoba" server vrátí FormDefinition, kde:
- Jsou skryta pole `company_id`, `tax_id`, `vat_id` (nastaví `"hidden": true`)
- Jsou zobrazena pole `first_name`, `last_name`, `birth_date`, `national_id`
- Jsou skryta nebo zobrazena odpovídající sekce

Po přepnutí na "Firma":
- Jsou skryta `first_name`, `last_name`, `birth_date`, `national_id`
- Jsou zobrazena `company_id`, `tax_id`, `vat_id`

Server zároveň může přepočítat `full_name` z `first_name` + `last_name`.

### Kdy použít `triggers: reload`

- Přepínač typu záznamu (firma/osoba, typ dokladu…)
- Přepínač, který ovlivňuje viditelnost jiných polí
- Pole, jehož změna spouští dopočítání dalších hodnot na serveru
- **Nepoužívat** pro běžná textová pole — zbytečný round-trip

---

## 8. Validace a chybové stavy

### Postup při uložení

1. Klient sbere data ze všech tabů
2. `POST /_ui/form/{table}/save` nebo `PUT /_ui/form/{table}/save/{id}`
3. Server vrátí:
   - **Úspěch** → `{"success": true, "id": 42, "data": {...}}` → reload formuláře s novými daty
   - **Chyba validace** → `{"success": false, "errors": [{"column": "last_name", "message": "Příjmení je povinné"}, ...]}`

### Zobrazení chyb na klientovi

- Každá chyba má `column` → klient najde odpovídající element a zobrazí error stav (červený rámeček + text)
- Pokud je pole s chybou na **neaktivním tabu**, klient automaticky přepne na tab, kde se pole nachází
- Více chyb na různých tabech → přepne na první tab s chybou (dle pořadí tabů)
- Chyby bez `column` (globální) se zobrazí nad formulářem jako alert

### Mapování column → tab

Klient při renderování si sestaví mapu `{column_id → tab_id}` ze všech elementů všech tabů. Při validačních chybách ze serveru použije tuto mapu k nalezení správného tabu.

---

## 9. FormEditor.svelte — layout

```
┌─ FormEditor ─────────────────────────────────────────────┐
│ ┌─ Záhlaví ──────────────────────────────────────────┐  │
│ │  [←] Nová osoba               [KONCEPT ●]         │  │
│ └─────────────────────────────────────────────────────┘  │
│ ┌─ Tab bar (pokud > 1 tab) ─────────────────────────┐    │
│ │  [Základní] [Identifikace] [Kontakty] [Adresy]    │    │
│ └────────────────────────────────────────────────────┘    │
│ ┌─ Tab obsah (scrollovatelný) ──────────────────────┐    │
│ │  [col 1/4] [col 2/4] [col 3/4] [col 4/4]         │    │
│ │  ...                                               │    │
│ └────────────────────────────────────────────────────┘    │
│ ┌─ Toolbar (fixní dole) ──────────────────────────── ┐   │
│ │  [Uložit]  [V pořádku] [Ukončit platnost] [Smazat] │   │
│ └─────────────────────────────────────────────────────┘   │
└───────────────────────────────────────────────────────────┘
```

### Záhlaví

- Tlačítko zpět `←` (zavře formulář, vrátí do Vieweru)
- Název formuláře (`title` nebo `titleNew` z FormDefinition)
- Badge stavu dokumentu: text + CSS třída `docState_{stateStyle}` (jen pokud tabulka má docStates)

### Tab bar

- Zobrazí se jen pokud je tabů > 1
- Aktivní tab je zvýrazněn
- Tab s validační chybou dostane vizuální indikátor (červená tečka/podtržení)

### Obsah tabu

- CSS Grid (`grid-template-columns: repeat(4, 1fr)`)
- Scrollovatelný (overflow-y: auto)
- Toolbar je fixní (nevyroluje se pryč)

### Toolbar

- **Uložit** — viditelné jen je-li formulář editovatelný (ne readOnly stav)
- **Přechodová tlačítka** — vždy viditelná (je-li tabulka se stavy)
- ReadOnly formulář: tlačítko Uložit skryto, jen přechodová tlačítka

---

## 10. API endpointy

### Přehled

| Endpoint | Popis |
|----------|-------|
| `GET /_ui/form/{table}/meta` | FormDefinition pro nový záznam |
| `GET /_ui/form/{table}/meta/{id}` | FormDefinition pro existující záznam |
| `POST /_ui/form/{table}/save` | Uložení nového záznamu |
| `PUT /_ui/form/{table}/save/{id}` | Uložení existujícího záznamu |
| `POST /_ui/form/{table}/recalculate` | Přepočítání formuláře po změně triggering pole |

### `GET /_ui/form/{table}/meta[/{id}]`

Vrátí `FormDefinition` včetně aktuálních dat záznamu (je-li `id` zadáno):

```json
{
    "success": true,
    "formDefinition": {
        "table": "base_persons_persons",
        "title": "Osoba",
        "titleNew": "Nová osoba",
        "tabs": [ ... ],
        "docStates": { ... }
    },
    "data": {
        "id": 42,
        "full_name": "Jan Novák",
        "person_type": 1,
        "..."
    }
}
```

Pro nový záznam (`/meta` bez ID): `data` je prázdné nebo obsahuje výchozí hodnoty.

### `POST /_ui/form/{table}/save`

Request body: flat JSON se všemi poli formuláře.

Odpovědi:
- `201 Created` + `{"success": true, "id": 42, "data": {...}}`
- `422` + `{"success": false, "errors": [{"column": "...", "message": "..."}]}`

### `PUT /_ui/form/{table}/save/{id}`

Request body: flat JSON se všemi poli. Přepíše celý záznam.

Odpovědi:
- `200 OK` + `{"success": true, "id": 42, "data": {...}}`
- `422` + validační chyby
- `409` pokud je záznam `readOnly` a tělo obsahuje více než jen `docState`

### `POST /_ui/form/{table}/recalculate`

Request body:
```json
{
    "id": 42,
    "changedColumn": "person_type",
    "data": { "...aktuální data formuláře..." }
}
```

Odpověď:
```json
{
    "success": true,
    "formDefinition": { "...nová FormDefinition..." },
    "data": { "...přepočítaná data..." }
}
```

Recalculate **neukládá** data do DB. Je to čistě výpočetní operace.

---

## 11. Backend — PHP třída `TableForm`

Analogie `TableViewer`. Každý formulář je buď PHP třída dědící `TableForm`, nebo JSONC soubor (viz sekce 12).

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Form;

abstract class TableForm
{
    protected string $table;
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConnection $db = null;

    /** Vrátí FormDefinition pro daná data záznamu. */
    abstract public function buildFormDefinition(array $data, bool $isNew): FormDefinition;

    /**
     * Volitelné přepočítání po změně triggering pole.
     * Výchozí implementace vrátí nezměněnou FormDefinition a data.
     */
    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        return new RecalculateResult($this->buildFormDefinition($data, false), $data);
    }

    // --- Builder helpers ---

    protected function tab(string $id, string $label): TabBuilder { ... }

    // Použití v buildFormDefinition:
    // $tab = $this->tab('basic', 'Základní údaje');
    // $tab->addInput('full_name', cols: 2, triggers: 'reload');
    // $tab->addSelect('person_type', cols: 1, triggers: 'reload');
    // $tab->addSeparator('Jméno');
    // $tab->addInput('first_name', cols: 1);
    // $tab->addInput('last_name', cols: 1);
    // return new FormDefinition([$tab->build()]);
}
```

### Builder API

`TabBuilder` je fluent builder:

```php
$tab->addInput(string $column, int $cols = 1, ?string $label = null, bool $required = false, ?string $triggers = null, bool $readOnly = false, bool $hidden = false, ?string $placeholder = null, ?string $hint = null): static

$tab->addSelect(string $column, int $cols = 1, ?string $label = null, ?string $triggers = null): static
// options se doplní automaticky z cfgItem tabulky

$tab->addSeparator(string $label): static

$tab->openGroup(string $label, int $cols = 4): static
$tab->closeGroup(): static

$tab->addSubtable(string $table, string $foreignKey, string $formId, string $label): static

$tab->addHtml(string $content, int $cols = 4): static
```

### Příklad — PersonsForm

```php
<?php
declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Form\TableForm;
use Shipard\Core\Form\FormDefinition;

class PersonsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));
        $isCompany = $personType === PersonType::Company;
        $isPerson  = $personType === PersonType::Person;

        // Tab 1: Základní údaje
        $basic = $this->tab('basic', 'Základní údaje');
        $basic->addInput('person_id', cols: 1, label: 'Kód osoby', required: true);
        $basic->addSelect('person_type', cols: 1, triggers: 'reload');
        $basic->addInput('full_name', cols: 2, label: 'Celý název / jméno', required: $isCompany);

        // Sekce Identifikace firmy (pouze pro firmu)
        $basic->addSeparator('Identifikace firmy');
        $basic->addInput('company_id', cols: 1, hidden: $isPerson);
        $basic->addInput('tax_id', cols: 1, hidden: $isPerson);
        $basic->addInput('vat_id', cols: 1, hidden: $isPerson);

        // Sekce Jméno fyzické osoby (pouze pro osobu)
        $basic->addSeparator('Jméno');
        $basic->addInput('title_before', cols: 1, hidden: $isCompany);
        $basic->addInput('first_name',   cols: 1, hidden: $isCompany, required: $isPerson);
        $basic->addInput('middle_name',  cols: 1, hidden: $isCompany);
        $basic->addInput('last_name',    cols: 1, hidden: $isCompany, required: $isPerson);
        $basic->addInput('title_after',  cols: 1, hidden: $isCompany);

        // Tab 2: Kontaktní údaje
        $contact = $this->tab('contact', 'Kontaktní údaje');
        $contact->addInput('email', cols: 2);
        $contact->addInput('phone', cols: 1);
        $contact->addInput('web',   cols: 2);

        // Tab 3: Kontakty (sub-tabulka)
        $contacts = $this->tab('contacts', 'Kontakty');
        $contacts->addSubtable(
            table: 'base_persons_contacts',
            foreignKey: 'person',
            formId: 'base.persons.contacts',
            label: 'Kontakty'
        );

        // Tab 4: Adresy (sub-tabulka)
        $addresses = $this->tab('addresses', 'Adresy');
        $addresses->addSubtable(
            table: 'base_persons_addresses',
            foreignKey: 'person',
            formId: 'base.persons.addresses',
            label: 'Adresy'
        );

        // Tab 5: Bankovní účty (sub-tabulka)
        $bankAccounts = $this->tab('bank_accounts', 'Bankovní účty');
        $bankAccounts->addSubtable(
            table: 'base_persons_bank_accounts',
            foreignKey: 'person',
            formId: 'base.persons.bank_accounts',
            label: 'Bankovní účty'
        );

        return new FormDefinition(
            table: $this->table,
            title: 'Osoba',
            titleNew: 'Nová osoba',
            tabs: [
                $basic->build(),
                $contact->build(),
                $contacts->build(),
                $addresses->build(),
                $bankAccounts->build(),
            ],
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        // Pokud se změnil typ osoby, přepočítat full_name
        if ($changedColumn === 'person_type') {
            $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));
            if ($personType === PersonType::Person) {
                $data['full_name'] = trim(
                    ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')
                );
            }
        }
        return new RecalculateResult($this->buildFormDefinition($data, empty($data['id'])), $data);
    }
}
```

---

## 12. Deklarativní JSONC definice (jednoduché formuláře)

Pro tabulky bez složité logiky — stačí JSONC soubor, žádný PHP kód.

**Umístění:** `modules/{skupina}/{modul}/forms/{table}.jsonc`

```jsonc
{
    // Volitelné — nadpis formuláře (výchozí: název tabulky z definice)
    "title": "Uživatel",
    "titleNew": "Nový uživatel",

    "tabs": [
        {
            "id": "basic",
            "label": "Základní údaje",
            "elements": [
                {"type": "input", "column": "login", "cols": 2, "required": true},
                {"type": "input", "column": "full_name", "cols": 2, "required": true},
                {"type": "input", "column": "email", "cols": 2},
                {"type": "separator", "label": "Nastavení"},
                {"type": "input", "column": "is_active", "cols": 1}
            ]
        }
    ]
}
```

Labely, typy inputů, placeholder atd. se doplní z definice tabulky (metadata sloupce), pokud nejsou v JSONC explicitně uvedeny.

### Priorita (FormController algoritmus)

1. Existuje registrace v `module.jsonc` → `forms` sekce s PHP třídou? → použij PHP třídu
2. Existuje `forms/{table}.jsonc`? → použij JSONC definici
3. Jinak → **automaticky vygeneruj** FormDefinition ze sloupců tabulky (stávající chování)

---

## 13. Registrace v `module.jsonc`

```jsonc
{
    "id": "base.persons",
    "name:cs": "Osoby",

    "forms": [
        {
            "table": "base_persons_persons",
            "class": "Shipard\\Module\\Base\\Persons\\PersonsForm"
        },
        {
            "table": "base_persons_contacts",
            "id": "base.persons.contacts"
            // Bez "class" → hledá se forms/base_persons_contacts.jsonc
        },
        {
            "table": "base_persons_addresses",
            "id": "base.persons.addresses"
        },
        {
            "table": "base_persons_bank_accounts",
            "id": "base.persons.bank_accounts"
        }
    ]
}
```

Pole `id` se používá pro sub-tabulky — `FormSubTable.svelte` žádá konkrétní formulář přes `formId`.

---

## 14. `FormSubTable.svelte` — editor sub-tabulky

Sub-tabulka je tab, ve kterém je přehledová tabulka + akce.

```
┌─ Tab: Kontakty ─────────────────────────────────────────┐
│  [+ Přidat kontakt]                                     │
│  ┌──────────────────────────────────────────────────┐   │
│  │ Název          │ Funkce │ E-mail    │ Telefon    │   │
│  ├──────────────────────────────────────────────────┤   │
│  │ Jan Novák      │ CEO    │ jan@...   │ +420...    │ [✎][✕] │
│  │ Petra Svobodová│ CFO    │ petra@... │            │ [✎][✕] │
│  └──────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
```

### Chování

- Tlačítko **Přidat** otevře `FormDialog` s prázdným formulářem pro sub-tabulku
- Ikona **editovat** (`✎`) otevře `FormDialog` s vyplněnými daty řádku
- Ikona **smazat** (`✕`) vyžádá potvrzení a smaže řádek (volá `DELETE /{sub-table}/{id}`)
- Po uložení sub-záznamu se sub-tabulka refreshne (volá `GET /{sub-table}?filter[{foreignKey}]=eq:{parentId}`)

### Poznámka k pořadí

Sub-tabulky s `order_pos` sloupcem zobrazují řádky seřazené dle `order_pos`. Drag-and-drop přeřazení je budoucí rozšíření.

### Ukládání rodičovského záznamu a sub-tabulky

Sub-záznamy se ukládají **okamžitě** (při potvrzení mini-dialogu), ne až s hlavním formulářem. Tedy:
- Uživatel otevře formulář Osoby (ID 42)
- Přidá kontakt → dialog → Uložit → `POST /base_persons_contacts` s `person=42`
- Změna je okamžitě v DB

Výjimka: pro **nový záznam** (rodič ještě nemá ID) musí klient nejprve uložit rodiče (`POST save`) a teprve pak odemknout taby se sub-tabulkami. Taby se sub-tabulkami jsou proto u nových záznamů **disabled** s informací „Nejprve uložte záznam".

---

## 15. Nové Svelte komponenty

### Stávající komponenty (zůstávají / rozšíří se)

| Komponenta | Stav |
|------------|------|
| `FormField.svelte` | Zůstává — mapuje typ sloupce na UI komponentu |
| `FormDialog.svelte` | Rozšíří se — obalí `FormEditor` místo `FormRenderer` |

### Nové komponenty

| Komponenta | Popis |
|------------|-------|
| `FormEditor.svelte` | Hlavní shell: záhlaví, tab bar, obsah, toolbar |
| `FormTab.svelte` | Obsah jednoho tabu — CSS Grid, iteruje elementy |
| `FormElement.svelte` | Dynamický renderer elementu (input/select/separator/group/subtable/html) |
| `FormSubTable.svelte` | Editor sub-tabulky v tabu (přehledová tabulka + CRUD akce) |
| `FormStateBar.svelte` | Spodní toolbar s tlačítky doc states přechodů + Uložit |
| `FormStateBadge.svelte` | Badge stavu v záhlaví (`[KONCEPT ●]`) |

### Adresář

```
frontend/src/components/form/
├── FormEditor.svelte       ← nový hlavní shell
├── FormTab.svelte          ← nový
├── FormElement.svelte      ← nový (nahrazuje/rozšiřuje FormField.svelte)
├── FormSubTable.svelte     ← nový
├── FormStateBar.svelte     ← nový
├── FormStateBadge.svelte   ← nový
├── FormField.svelte        ← existující (zachován pro primitivní typy)
├── FormRenderer.svelte     ← existující (zachován jako fallback)
└── FormDialog.svelte       ← existující (rozšířen)
```

---

## 16. Nové PHP třídy

### Jádro (`src/Core/Form/`)

| Třída | Popis |
|-------|-------|
| `TableForm` | Abstraktní bázová třída; builder helpers |
| `TabBuilder` | Fluent builder pro jeden tab |
| `FormDefinition` | Datová třída — celá definice formuláře |
| `FormTab` | Datová třída — jeden tab |
| `FormElement` | Datová třída — jeden element (type, column, cols, …) |
| `FormRegistry` | Registr tříd formulářů (analogie ViewerRegistry) |
| `FormController` | HTTP controller — meta, save, recalculate |
| `RecalculateResult` | Výsledek recalculate operace (FormDefinition + data) |
| `JsoncFormLoader` | Načítá a validuje JSONC definice formulářů |
| `AutoFormBuilder` | Automaticky generuje FormDefinition z TableDefinition (fallback) |

### Umístění v modulech

```
modules/base/persons/
└── src/
    ├── PersonsForm.php         ← TableForm subclass
    ├── PersonDocument.php      ← existující
    ├── PersonsViewer.php       ← existující
    └── PersonType.php          ← existující
```

---

## 17. Nové API endpointy — routing

Přidá se do `src/Api/Router.php` a `src/Api/Controller/FormController.php`:

```
GET  /_ui/form/{table}/meta           → FormController::meta(table, id=null)
GET  /_ui/form/{table}/meta/{id}      → FormController::meta(table, id)
POST /_ui/form/{table}/save           → FormController::save(table, id=null)
PUT  /_ui/form/{table}/save/{id}      → FormController::save(table, id)
POST /_ui/form/{table}/recalculate    → FormController::recalculate(table)
```

Stávající CRUD endpointy (`CrudController`) zůstávají beze změny — `FormController` je vyšší vrstva, která interně volá `TableGateway` / `Document` systém.

---

## 18. Implementační plán

### Fáze 1 — Backend: jádro (bez integrací)

**Cíl:** Funkční `FormController` s ručně testovatelným API.

**Soubory:**
- `src/Core/Form/FormElement.php` — datová třída
- `src/Core/Form/FormTab.php` — datová třída
- `src/Core/Form/FormDefinition.php` — datová třída s serializací do JSON
- `src/Core/Form/TabBuilder.php` — fluent builder
- `src/Core/Form/TableForm.php` — abstraktní třída + builder helpers
- `src/Core/Form/RecalculateResult.php` — datová třída
- `src/Core/Form/AutoFormBuilder.php` — generuje FormDefinition z TableDefinition
- `src/Core/Form/JsoncFormLoader.php` — načítá JSONC formy
- `src/Core/Form/FormRegistry.php` — registr PHP tříd
- `src/Core/Form/FormController.php` — HTTP controller
- Aktualizace `src/Api/Router.php` — nové routy `/_ui/form/...`
- Aktualizace `public/index.php` — FormController do pipeline

**Testy:**
- `AutoFormBuilder` — generuje korektní FormDefinition z jednoduché TableDefinition
- `FormController::meta` — vrací FormDefinition + data pro existující záznam
- `FormController::save` — POST i PUT, validační chyby ze serveru

### Fáze 2 — Backend: PersonsForm

**Cíl:** Konkrétní formulář pro Osoby s recalculate a doc states.

**Soubory:**
- `modules/base/persons/src/PersonsForm.php`
- Aktualizace `modules/base/persons/module.jsonc` — sekce `forms`

**Testy:**
- Recalculate při změně `person_type` → správně skrytá/zobrazená pole
- Přepočítání `full_name` při změně jmen

### Fáze 3 — Backend: JSONC formy pro sub-tabulky

**Cíl:** Formuláře pro Kontakty, Adresy, Bankovní účty jako JSONC.

**Soubory:**
- `modules/base/persons/forms/base_persons_contacts.jsonc`
- `modules/base/persons/forms/base_persons_addresses.jsonc`
- `modules/base/persons/forms/base_persons_bank_accounts.jsonc`
- Aktualizace `modules/base/persons/module.jsonc`

### Fáze 4 — Frontend: FormEditor a FormTab

**Cíl:** Renderování FormDefinition z API; editace bez sub-tabulek a doc states.

**Soubory:**
- `frontend/src/components/form/FormEditor.svelte`
- `frontend/src/components/form/FormTab.svelte`
- `frontend/src/components/form/FormElement.svelte`
- Aktualizace `FormDialog.svelte` — použije FormEditor místo FormRenderer

**Testovat ručně:** Formulář Osoby (tab Základní údaje) se otevře a data se uloží.

### Fáze 5 — Frontend: Recalculate

**Cíl:** Dynamické přepínání polí při změně triggering hodnoty.

**Soubory:**
- Rozšíření `FormEditor.svelte` — sleduje změny, volá recalculate endpoint
- Rozšíření `FormElement.svelte` — `hidden` prop, animace skrytí/zobrazení

**Testovat ručně:** Přepínač Firma/Fyzická osoba skryje/zobrazí správná pole.

### Fáze 6 — Frontend: Doc states

**Cíl:** Badge stavu, toolbar s přechodovými tlačítky, readOnly formulář.

**Soubory:**
- `frontend/src/components/form/FormStateBar.svelte`
- `frontend/src/components/form/FormStateBadge.svelte`
- Rozšíření `FormEditor.svelte` — integrace StateBar + StateBadge

**Testovat ručně:** Formulář Osoby ve stavu "V pořádku" je readOnly; tlačítko "Opravit" ho přepne do editovatelného stavu.

### Fáze 7 — Frontend: Sub-tabulky

**Cíl:** Taby Kontakty, Adresy, Bankovní účty v PersonsForm.

**Soubory:**
- `frontend/src/components/form/FormSubTable.svelte`
- Rozšíření `FormElement.svelte` — case 'subtable'

**Testovat ručně:** Přidání / editace / smazání kontaktu v sub-tabulce.

### Fáze 8 — Integrace do Viewer systému

**Cíl:** Tlačítka "Přidat" / "Otevřít" v Vieweru otevřou FormEditor (ne starý FormRenderer).

**Soubory:**
- Aktualizace `ViewerToolbar.svelte` — akce volají `FormEditor` přes `FormDialog`
- Aktualizace `ContentArea.svelte` nebo `Viewer.svelte` — správa stavu otevřeného formuláře

---

## 19. Otevření formuláře — fullSize flag

Formulář se může otevřít ve dvou režimech:

| Režim | Popis |
|-------|-------|
| **Modální dialog** | Formulář překryje obsah jako overlay. Výchozí režim. |
| **Full size** | Formulář nahradí celý ContentArea (jako by to byla nová stránka). Pro velké formuláře. |

### Řízení přes `fullSize` flag v FormDefinition

Server vrátí v kořeni `FormDefinition` volitelné pole:

```json
{
    "table": "base_persons_persons",
    "fullSize": true,
    "..."
}
```

Klient při otevírání formuláře zkontroluje tento flag:
- `fullSize: true` → formulář se zobrazí jako full-size stránka v ContentArea
- `fullSize: false` (výchozí) → formulář se zobrazí jako modální dialog

### Motivace a pravidla

- Formuláře hlavních entit (Osoby, Faktury, Objednávky…) mají `fullSize: true` — mají mnoho tabů, sub-tabulek a polí, modal by byl příliš stísněný
- Formuláře sub-záznamů (Kontakt, Adresa, Bankovní účet…) mají `fullSize: false` — jsou malé a přirozeně patří do dialogu
- Pokud je velký formulář otevřen z jiného formuláře (např. Osoba z výběrového pole na Faktuře), `fullSize: true` stále platí — otevře se jako overlay přes celou ContentArea
- Uživatel nemůže přepínat režim ručně — rozhoduje server

### Implementace na klientovi

`FormDialog.svelte` zkontroluje `formDefinition.fullSize`:
- `true` → předá řízení `ContentArea` (nebo speciálnímu full-size overlaye nad celou ContentArea)
- `false` → zobrazí standardní `<Modal>` dialog

## 20. Otevřené otázky (k řešení při implementaci)

1. **Full-size overlay z nested kontextu** — pokud je Faktura otevřena jako dialog a z ní se otevře výběr Osoby (fullSize), jak přesně se má full-size overlay zobrazit? Možnosti: (a) nad celou aplikací (z-index přes vše), (b) pouze nad ContentArea. Doporučení: nad celou aplikací — jednodušší implementace.

2. **Auto-save draft** — má formulář automaticky ukládat rozpracovaný stav do localStorage pro případ nechtěného zavření? Zatím ne — zbytečná komplexita.

3. **Drag-and-drop pořadí v sub-tabulkách** — odloženo na pozdější fázi.

4. **Inline editace řádků (faktury)** — odloženo; řeší se separátně pro ekonomický modul.

5. **Oprávnění (permissions)** — server může v budoucnu vracet `readOnly: true` nebo skrývat pole na základě role uživatele, bez změny klientské logiky (server-driven).
