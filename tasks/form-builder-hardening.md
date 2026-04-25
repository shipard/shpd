# Task: Form builder hardening

**Motivace:** V `IncomingMessagesForm.php` vznikly dva tiché bugy:

1. `inputType: 'datetime-local'` místo `'datetime'` → frontend switch nenašel
   match, fallback na `<input type="text">`, formulář nejde uložit kvůli
   backend validaci datetime sloupce.
2. `body_plain` bez `inputType: 'textarea'` → tichý fallback na single-line
   input pro `longtext` sloupec.

Oba problémy mají společnou příčinu: **`addInput()` akceptuje libovolný
`inputType` string a frontend tiše spadne na default**, když hodnotu nezná.
Tento task problém řeší dvěma komplementárními způsoby — fail-fast validací
a dedikovanými builder metodami pro neprimitivní typy.

---

## Co udělat

### 1. Validace `inputType` (fail-fast na backendu)

**Soubor:** `src/Core/Form/FormElement.php`

V konstruktoru `FormElement` přidat whitelist pro `inputType`:

```php
private const ALLOWED_INPUT_TYPES = [
    null,        // default = text
    'text',
    'email',
    'tel',
    'url',
    'password',
    'number',
    'checkbox',
    'date',
    'datetime',
    'time',
    'textarea',
];
```

V konstruktoru:

```php
if (!in_array($inputType, self::ALLOWED_INPUT_TYPES, true)) {
    throw new \InvalidArgumentException(sprintf(
        'Invalid inputType "%s". Allowed: %s',
        $inputType,
        implode(', ', array_map(fn($t) => $t ?? 'null', self::ALLOWED_INPUT_TYPES)),
    ));
}
```

Validace se aplikuje jen pro `type === 'input'`; ostatní typy (`separator`,
`group`, `select`, …) `inputType` nepoužívají.

### 2. Rozšířit frontend o semantic text varianty

**Soubor:** `frontend/src/components/form/FormElement.svelte`

Přidat explicitní větve pro `email`, `tel`, `url`, `password`. Všechny
renderují `<Input>` se správným `type` atributem:

```svelte
{:else if element.input_type === 'email'}
  <Input type="email" ... />
{:else if element.input_type === 'tel'}
  <Input type="tel" ... />
{:else if element.input_type === 'url'}
  <Input type="url" ... />
{:else if element.input_type === 'password'}
  <Input type="password" ... />
```

Default větev (pro `input_type === 'text'` nebo `null`) zůstává beze změny.

### 3. Dedikované builder metody pro neprimitivní typy

**Soubor:** `src/Core/Form/TabBuilder.php`

Přidat tenké wrappery nad `addInput()`. Metody nepřidávají žádnou logiku —
jen nastavují `inputType`:

```php
public function addTextArea(
    string $column,
    int $cols = 4,
    ?string $label = null,
    bool $required = false,
    bool $readOnly = false,
    bool $hidden = false,
    ?string $hint = null,
): static {
    return $this->addInput(
        column: $column, cols: $cols, label: $label,
        required: $required, readOnly: $readOnly, hidden: $hidden,
        hint: $hint, inputType: 'textarea',
    );
}

public function addDate(string $column, int $cols = 1, ... ): static
public function addDateTime(string $column, int $cols = 1, ... ): static
public function addTime(string $column, int $cols = 1, ... ): static
public function addNumber(string $column, int $cols = 1, ... ): static
public function addCheckbox(string $column, int $cols = 1, ... ): static
```

Všechny s konzistentní signaturou (jen bez `inputType` a bez `placeholder`,
`triggers` — ty u widgetů nedávají smysl; nechat jen to, co se reálně používá).

### 4. Omezit `addInput()` na text varianty

Po zavedení dedikovaných metod zúžit dovolené `inputType` u `addInput()`
pouze na `null | 'text' | 'email' | 'tel' | 'url' | 'password'`. Validace
v runtime (stačí jednoduchý `if`-guard v `addInput` před předáním do
`FormElement`).

Rationale: `addInput()` zůstává jako general-purpose text primitive;
všechno ostatní má vlastní builder, který je sebedokumentující.

### 5. Migrace existujícího kódu

Projít všechna volání `addInput(..., inputType: ...)` v `modules/` a
převést na dedikované metody:

- `modules/core/mail/src/IncomingMessagesForm.php` —
  `inputType: 'datetime'` → `addDateTime`,
  `inputType: 'textarea'` → `addTextArea`,
  `inputType: 'email'` → zůstává (text varianta)
- `modules/base/persons/src/PersonsForm.php` —
  `inputType: 'date'` u `birth_date` → `addDate`

Grep na potvrzení, že jsou všechna volání převedena:

```bash
grep -rn "inputType:" modules/
```

Zbýt mohou jen hodnoty z whitelistu text variant
(`text`, `email`, `tel`, `url`, `password`).

### 6. Testy

**`tests/Core/Form/FormElementTest.php`:**
- Test: valid `inputType` hodnoty (všechny z whitelistu) projdou konstrukcí
- Test: `inputType: 'datetime-local'` vyhodí `InvalidArgumentException`
- Test: `inputType: 'bogus'` vyhodí `InvalidArgumentException`

**`tests/Core/Form/TabBuilderTest.php`:**
- Test: `addTextArea('x')` produkuje `FormElement` s `type='input'` a
  `inputType='textarea'`
- Test: `addDate`, `addDateTime`, `addTime`, `addNumber`, `addCheckbox`
  produkují očekávané `FormElement` instance
- Test: `addInput(..., inputType: 'textarea')` vyhodí exception
  (po omezení v bodě 4)

### 7. Dokumentace

**Soubor:** `docs/edit-forms.md` — sekce „Builder API" / „Typy polí":

- Tabulka s přehledem všech builder metod a kdy je použít
- Explicitní poznámka: `addInput()` je pro text; pro všechno ostatní
  jsou dedikované metody
- Poznámka o fail-fast validaci `inputType`

---

## Akceptace

- `vendor/bin/phpunit` — všechny testy procházejí
- `grep -rn "inputType:" modules/` vrací pouze text varianty (nebo nic)
- Formulář došlé zprávy a osoby stále fungují (manuální smoke test po
  `seed-mail` a `seed-persons`)
- Neplatný `inputType` vyhodí `InvalidArgumentException` při konstrukci
  formuláře, ne tiše až v UI
- `docs/edit-forms.md` obsahuje přehled builder metod

---

## Mimo rozsah

- Změny layout semantiky (grid, cols) — neřešíme, funguje
- Přidání úplně nových widgetů (rich text, file upload přímo v poli,
  datepicker s customizací) — samostatný úkol
- Migrace JSONC-definovaných formulářů, pokud existují — tam se `inputType`
  píše jako string, whitelist validace se tam musí aplikovat při loadu
  (`JsoncFormLoader`) stejným mechanismem. Ověřit a případně přidat.
