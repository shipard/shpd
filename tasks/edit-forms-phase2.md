# Task: Editační formuláře — Fáze 2 (PersonsForm + JSONC sub-formy)

## Kontext

Fáze 1 (backend jádro) je hotová. Nyní implementujeme konkrétní formuláře pro modul `base.persons`:

- **`PersonsForm`** — PHP třída s plnou logikou pro tabulku `base_persons_persons`
- **JSONC formy** — deklarativní definice pro sub-tabulky (Kontakty, Adresy, Bankovní účty)
- **Aktualizace `module.jsonc`** — registrace všech nových formulářů

Přečti si před začátkem:
- `docs/edit-forms.md` — celý PRD, zejména sekce 11 (TableForm builder API), 12 (JSONC), 13 (registrace)
- `src/Core/Form/TableForm.php` — bázová třída s builder helpers
- `src/Core/Form/TabBuilder.php` — fluent builder (addInput, addSelect, addSeparator, addSubtable…)
- `modules/base/persons/tables/base_persons_persons.jsonc` — definice tabulky Osoby
- `modules/base/persons/tables/base_persons_contacts.jsonc` — definice Kontaktů
- `modules/base/persons/tables/base_persons_addresses.jsonc` — definice Adres
- `modules/base/persons/tables/base_persons_bank_accounts.jsonc` — definice Bankovních účtů
- `modules/base/persons/src/PersonType.php` — PHP enum PersonType (Undefined=0, Person=1, Company=2)
- `modules/base/persons/src/PersonDocument.php` — stávající Document třída (validate + beforeSave logika)
- `modules/base/persons/module.jsonc` — stávající registrace modulu

---

## 1. `PersonsForm` — PHP třída

**Soubor:** `modules/base/persons/src/PersonsForm.php`

**Namespace:** `Shipard\Module\Base\Persons`

Třída dědí `Shipard\Core\Form\TableForm` a implementuje dvě metody: `buildFormDefinition` a `recalculate`.

### Taby formuláře

#### Tab „Základní údaje" (`basic`)

Vždy přítomná pole (nezávisle na typu osoby):
- `person_id` — Kód osoby, `cols: 1`, required
- `person_type` — Typ osoby, `select`, `cols: 1`, `triggers: 'reload'` — options se doplní z cfgItem
- `full_name` — Celý název / jméno, `cols: 2`
  - required pokud `$isCompany` (u firmy se zadává přímo)
  - readOnly pokud `$isPerson` (u osoby se počítá automaticky z jména)

Sekce „Identifikace" — jen pro firmu (`hidden: $isPerson`):
- separator „Identifikace firmy"
- `company_id` — IČO, `cols: 1`, `hidden: $isPerson`
- `tax_id` — DIČ, `cols: 1`, `hidden: $isPerson`
- `vat_id` — DIČ pro DPH, `cols: 1`, `hidden: $isPerson`

Sekce „Jméno" — jen pro osobu (`hidden: $isCompany || $isUndefined`):
- separator „Jméno"
- `title_before` — Tituly před jménem, `cols: 1`, `hidden: $isCompany || $isUndefined`
- `first_name` — Jméno, `cols: 1`, `hidden: $isCompany || $isUndefined`, required pokud `$isPerson`
- `middle_name` — Prostřední jméno, `cols: 1`, `hidden: $isCompany || $isUndefined`
- `last_name` — Příjmení, `cols: 1`, `hidden: $isCompany || $isUndefined`, required pokud `$isPerson`
- `title_after` — Tituly za jménem, `cols: 1`, `hidden: $isCompany || $isUndefined`

Sekce „Osobní údaje" — jen pro osobu (`hidden: $isCompany || $isUndefined`):
- separator „Osobní údaje"
- `birth_date` — Datum narození, `cols: 1`, `hidden: $isCompany || $isUndefined`
- `national_id` — Rodné číslo, `cols: 1`, `hidden: $isCompany || $isUndefined`
- `id_card_number` — Číslo dokladu, `cols: 1`, `hidden: $isCompany || $isUndefined`

#### Tab „Kontaktní údaje" (`contact`)

Vždy viditelná pole:
- `email` — E-mail, `cols: 2`
- `phone` — Telefon, `cols: 1`
- `web` — Web, `cols: 2`

#### Tab „Kontakty" (`contacts`)

Pouze jeden element:
- `subtable` pro `base_persons_contacts`, foreignKey `person`, formId `base.persons.contacts`

#### Tab „Adresy" (`addresses`)

Pouze jeden element:
- `subtable` pro `base_persons_addresses`, foreignKey `person`, formId `base.persons.addresses`

#### Tab „Bankovní účty" (`bank_accounts`)

Pouze jeden element:
- `subtable` pro `base_persons_bank_accounts`, foreignKey `person`, formId `base.persons.bank_accounts`

### `buildFormDefinition` — výstup

```php
return new FormDefinition(
    table: $this->table,
    title: 'Osoba',
    titleNew: 'Nová osoba',
    tabs: [...],
    fullSize: true,   // Osoby jsou velký formulář — otevírá se full-size
);
```

### Jak zjistit typ osoby z `$data`

```php
$personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));
$isCompany   = $personType === PersonType::Company;
$isPerson    = $personType === PersonType::Person;
$isUndefined = $personType === null || $personType === PersonType::Undefined;
```

### `recalculate` — logika

Volá se po každé změně pole s `triggers: 'reload'` — v tomto formuláři je to `person_type`.

Co se má stát při recalculate:
1. Zjisti nový typ osoby z `$data['person_type']`
2. Pokud `$isPerson`:
   - Přepočítej `$data['full_name']` jako `trim(first_name . ' ' . last_name)` (stejná logika jako v `PersonDocument::beforeSave`)
   - Vynuluj `company_id`, `tax_id`, `vat_id` pokud jsou prázdné (nemazat existující hodnoty při editaci — ponech, co tam je)
3. Pokud `$isCompany`:
   - Vynuluj `first_name`, `last_name`, `middle_name`, `title_before`, `title_after`, `birth_date`, `national_id`, `id_card_number` jen pokud jsou prázdné
4. Zavolej `$this->buildFormDefinition($data, empty($data['id']))` s upraveným `$data`
5. Vrať `new RecalculateResult($formDefinition, $data)`

Poznámka: recalculate **neukládá** do DB — jen přepočítá a vrátí nová data + novou FormDefinition.

### Jak doplnit options pro `person_type` select

V `buildFormDefinition` použij `$this->config` (dostupný přes `TableForm::$config`, nastavený při inicializaci):

```php
$personTypeOptions = [];
if ($this->config !== null) {
    $cfgData = $this->config->cfgItem('base.persons.personTypes');
    if (is_array($cfgData)) {
        foreach ($cfgData as $key => $entry) {
            $personTypeOptions[] = ['value' => (int) $key, 'label' => $entry['name'] ?? ''];
        }
    }
}
// Pak předat do addSelect:
$basic->addSelect('person_type', cols: 1, options: $personTypeOptions, triggers: 'reload');
```

---

## 2. JSONC formy pro sub-tabulky

### 2a. Kontakty — `base_persons_contacts.jsonc`

**Soubor:** `modules/base/persons/forms/base_persons_contacts.jsonc`

Formulář pro editaci jednoho kontaktního záznamu. Tab je jeden — `"basic"`.

Pole (v pořadí):
- `name` — Název, `cols: 2`, required (not nullable, no default)
- `role` — Funkce, `cols: 2`
- `email` — E-mail, `cols: 2`
- `phone` — Telefon, `cols: 1`
- `note` — Poznámka, `cols: 4` (text → textarea)
- `valid_from` — Platnost od, `cols: 1`
- `valid_to` — Platnost do, `cols: 1`
- `order_pos` — Pořadí, `cols: 1`

Vynech: `id`, `person` (FK — nastavuje se automaticky)

Kořenová metadata:
```jsonc
{
    "title": "Kontakt",
    "titleNew": "Nový kontakt",
    "fullSize": false,
    "tabs": [...]
}
```

### 2b. Adresy — `base_persons_addresses.jsonc`

**Soubor:** `modules/base/persons/forms/base_persons_addresses.jsonc`

Formulář pro editaci jedné adresy. Dva taby:

**Tab „Adresa" (`address`):**
- `address_type` — Typ adresy, `select`, `cols: 1` (cfgItem `base.persons.addressTypes` → options se doplní automaticky z TableDefinition)
- `name` — Název adresy, `cols: 2`
- separator „Adresa"
- `street` — Ulice, `cols: 2`
- `house_number` — Číslo popisné, `cols: 1`
- `orientation_number` — Číslo orientační, `cols: 1`
- `city` — Obec, `cols: 2`
- `city_part` — Část obce, `cols: 1`
- `zip` — PSČ, `cols: 1`
- `country` — Země (2-písmenný kód), `cols: 1`
- separator „Platnost"
- `valid_from` — Platnost od, `cols: 1`
- `valid_to` — Platnost do, `cols: 1`
- `order_pos` — Pořadí, `cols: 1`
- `note` — Poznámka, `cols: 4`

**Tab „Upřesnění" (`detail`):**
- `place_reg_type` — Typ registru místa, `select`, `cols: 1`
- `place_reg_id` — Identifikátor místa, `cols: 1`
- separator „Geolokace"
- `latitude` — Zeměpisná šířka, `cols: 1`
- `longitude` — Zeměpisná délka, `cols: 1`
- `manual_gps` — Manuální zaměření, `cols: 1`

Vynech: `id`, `person`, `is_standardized`, `registry_code`, `division`, `display_line`, `display_block` (tyto jsou systémové/computed)

Kořenová metadata: `"title": "Adresa"`, `"titleNew": "Nová adresa"`, `"fullSize": false`

### 2c. Bankovní účty — `base_persons_bank_accounts.jsonc`

**Soubor:** `modules/base/persons/forms/base_persons_bank_accounts.jsonc`

Formulář pro editaci jednoho bankovního účtu. Jeden tab `"basic"`:

- `name` — Název účtu, `cols: 2`
- `account_number` — Číslo účtu, `cols: 2`, required
- `iban` — IBAN, `cols: 2`
- `bic` — BIC/SWIFT, `cols: 1`
- `currency` — Měna (3-písmenný kód), `cols: 1`
- `source` — Zdroj, `select`, `cols: 1`
- `valid_from` — Platnost od, `cols: 1`
- `valid_to` — Platnost do, `cols: 1`
- `order_pos` — Pořadí, `cols: 1`

Vynech: `id`, `person`

Kořenová metadata: `"title": "Bankovní účet"`, `"titleNew": "Nový bankovní účet"`, `"fullSize": false`

---

## 3. Aktualizace `module.jsonc`

**Soubor:** `modules/base/persons/module.jsonc`

Přidej sekci `forms` za stávající sekci `viewers`:

```jsonc
"forms": [
    {
        "table": "base_persons_persons",
        "class": "Shipard\\Module\\Base\\Persons\\PersonsForm"
    },
    {
        "table": "base_persons_contacts",
        "id": "base.persons.contacts"
    },
    {
        "table": "base_persons_addresses",
        "id": "base.persons.addresses"
    },
    {
        "table": "base_persons_bank_accounts",
        "id": "base.persons.bank_accounts"
    }
],
```

Poznámka: záznamy bez `"class"` signalizují JSONC formu. `FormRegistry` hledá JSONC soubor automaticky jako `modules/{skupina}/{modul}/forms/{table}.jsonc`. Záznamy s `"id"` umožňují odkazovat na formulář jako sub-table formId.

### Aktualizace `ModuleDefinition.php`

Zkontroluj, zda `src/Core/Module/ModuleDefinition.php` obsahuje property `forms`. Pokud ne, přidej ji analogicky jako `viewers` a `documentClasses`:

```php
public readonly array $forms,      // pole registrací formulářů z module.jsonc
```

A v `fromArray()` ji naplň:
```php
forms: $data['forms'] ?? [],
```

---

## 4. `FormRegistry` — načítání `id` registrací

Zkontroluj `src/Core/Form/FormRegistry.php`, metodu `loadFromModules`. Stávající implementace registruje pouze záznamy s `"class"`. Rozšiř ji tak, aby registrovala i záznamy s `"id"` (bez class) — ty slouží pro vyhledávání JSONC souboru a pro adresování přes `formId` v subtable elementech.

Přidej interní mapu `$formIds: array<string, string>` (table → formId), naplňuj ji při `loadFromModules` a přidej getter:

```php
public function getFormId(string $table): ?string
{
    return $this->formIds[$table] ?? null;
}
```

`FormController` tuto mapu nyní nevyužívá, ale bude potřeba pro frontend až bude adresovat sub-formy přes `formId`.

---

## 5. Testy

**Soubor:** `tests/Unit/Module/Base/Persons/PersonsFormTest.php`

Vytvoř adresář `tests/Unit/Module/Base/Persons/` a soubor s těmito testy:

### Test: buildFormDefinition pro firmu

```
- data['person_type'] = 2 (Company)
- Zkontroluj: tabs obsahuje 'basic', 'contact', 'contacts', 'addresses', 'bank_accounts'
- Zkontroluj: v tabu 'basic' je element pro 'company_id' s hidden=false
- Zkontroluj: v tabu 'basic' je element pro 'first_name' s hidden=true
- Zkontroluj: v tabu 'basic' je element pro 'full_name' s required=true, readOnly=false
- Zkontroluj: fullSize=true
```

### Test: buildFormDefinition pro fyzickou osobu

```
- data['person_type'] = 1 (Person)
- Zkontroluj: element 'company_id' má hidden=true
- Zkontroluj: element 'first_name' má hidden=false, required=true
- Zkontroluj: element 'last_name' má hidden=false, required=true
- Zkontroluj: element 'full_name' má readOnly=true, required=false
```

### Test: buildFormDefinition pro neurčeno

```
- data = [] (prázdná data, neurčeno)
- Zkontroluj: element 'company_id' má hidden=true (neurčeno = skryj firemní pole)
- Zkontroluj: element 'first_name' má hidden=true (neurčeno = skryj osobní pole)
```

### Test: recalculate přepnutí na Person přepočítá full_name

```
- changedColumn = 'person_type'
- data = ['person_type' => 1, 'first_name' => 'Jan', 'last_name' => 'Novák', 'full_name' => '']
- Zkontroluj: result->data['full_name'] === 'Jan Novák'
- Zkontroluj: result->formDefinition není null
```

### Test: recalculate přepnutí na Company nezmění full_name

```
- changedColumn = 'person_type'
- data = ['person_type' => 2, 'full_name' => 'ACME s.r.o.']
- Zkontroluj: result->data['full_name'] === 'ACME s.r.o.' (nezměněno)
```

### Pomocná metoda v testu

Pro snazší hledání elementů v tabu:

```php
private function findElement(FormDefinition $def, string $tabId, string $column): ?FormElement
{
    foreach ($def->tabs as $tab) {
        if ($tab->id !== $tabId) continue;
        foreach ($tab->elements as $el) {
            if ($el->column === $column) return $el;
        }
    }
    return null;
}
```

---

## Adresářová struktura po dokončení

```
modules/base/persons/
├── module.jsonc                      ← aktualizováno (přidána sekce forms)
├── config/
│   └── ...                           ← beze změny
├── forms/                            ← nový adresář
│   ├── base_persons_contacts.jsonc   ← nový
│   ├── base_persons_addresses.jsonc  ← nový
│   └── base_persons_bank_accounts.jsonc ← nový
├── src/
│   ├── PersonsForm.php               ← nový
│   ├── PersonDocument.php            ← beze změny
│   ├── PersonsViewer.php             ← beze změny
│   └── PersonType.php                ← beze změny
└── tables/
    └── ...                           ← beze změny

src/Core/Module/
└── ModuleDefinition.php              ← přidána property forms (pokud chybí)

src/Core/Form/
└── FormRegistry.php                  ← rozšířeno o formIds mapu a getFormId()

tests/Unit/Module/Base/Persons/
└── PersonsFormTest.php               ← nový
```

---

## Hotovo když

- [ ] `GET /_ui/form/base_persons_persons/meta` vrátí FormDefinition s 5 taby, `fullSize: true`
- [ ] `GET /_ui/form/base_persons_persons/meta` pro nový záznam (bez ID) — pole `company_id` má `hidden: false` nebo `true` dle výchozího person_type (Undefined → obě sekce skryté)
- [ ] `POST /_ui/form/base_persons_persons/recalculate` s `changedColumn: "person_type"` a `data.person_type: 1` vrátí správně skrytá/zobrazená pole a přepočítané `full_name`
- [ ] `GET /_ui/form/base_persons_contacts/meta` vrátí FormDefinition z JSONC, `fullSize: false`
- [ ] `GET /_ui/form/base_persons_addresses/meta` vrátí FormDefinition se 2 taby z JSONC
- [ ] `GET /_ui/form/base_persons_bank_accounts/meta` vrátí FormDefinition z JSONC
- [ ] Testy projdou
