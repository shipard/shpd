# Task: Validační hlášky formulářů — zobrazení a kontrakt

## Motivace

Dnes uživatel při kliknutí na **Potvrdit** (případně **V pořádku** nebo
**Opravit**) u dokladu často vidí jen generické „Validace selhala"
v červeném banneru a nemá tušení, co je špatně. Konkrétní hlášky z
`ValidationResult` přitom backend posílá — frontend je buď nepřečte
vůbec, nebo nemá kam je vykreslit. Jsou to dvě samostatné mezery v UI a
implicitní (nedokumentovaný) kontrakt mezi backendem a frontendem.

### Bug č. 1 — druhý PUT v `FormEditor.handleTransition` ignoruje `error.details`

Při přechodu stavu u **existujícího** záznamu pošle FormEditor dva PUT
requesty:

1. `PUT /save/{id}` s daty (`docState` v payloadu chybí → validace běží
   s `newState === oldState`, takže přísnější pravidla typu „rows musí
   být neprázdné" se přeskočí — viz `DocDocument::validate`).
2. `PUT /save/{id}` jen s `{docState: targetState}` → jde přes
   `applyStateTransitionViaDocument`, který loaduje záznam, nastaví
   nový stav a teprve teď validace běží naostro.

Druhá větev v současném kódu vypadá takto:

```js
const res = await put(`/_ui/form/${table}/save/${currentId}`, { docState: targetState });
if (res?.success) { ... }
else {
  loadError = res?.error ? translateError(res.error) : t('form.transitionFailed');
}
```

Žádné rozbalení `error.details`, žádné nastavení `fieldErrors`, jen
banner s generickou hláškou („Validace selhala"). Větve pro `handleSave`
a pro nový záznam v `handleTransition` (POST) detaily rozbalují
správně — tahle jediná větev to přeskakuje.

### Bug č. 2 — chyby bez sloupce nemají v UI místo

I když by `details` rozbalené byly, dnešní UI počítá výhradně s tím,
že `field` odpovídá nějakému `column` ve formuláři. Backend ale často
posílá:

- `field: "_form"` — chyby nepatřící konkrétnímu poli
  (`partner_bank_required`, `no_own_company`, …)
- `field: "rows"` — „doklad musí mít alespoň jeden řádek"; `rows` je
  název tabu, ne sloupec

Pro tyto hodnoty:

- `tabHasError()` je nikam nepřiřadí — žádná tečka na tabu
- `switchToErrorTab()` nepřepne tab
- `FormElement` nemá kam vykreslit error prop (chyba pole se ukazuje
  jen vedle inputu)

Výsledek: chyba existuje, ale je v UI **neviditelná**.

### Cíl tohoto tasku

Sjednotit rozbalování `details` ve všech save/transition větvích a
zavést explicitní místo v UI pro form-level chyby. Zároveň zformalizovat
kontrakt — backend posílá field-level chyby přes konkrétní `column`,
form-level chyby přes `_form`.

---

## Před implementací přečti

- `docs/edit-forms.md` — celá architektura formulářů, zejména:
  - kapitola 8 (Validace a chybové stavy)
  - kapitola 10 (API endpointy + detekce přechodu stavu)
  - kapitola 14 (Document lifecycle ve FormController)
- `docs/document-system.md`, kapitoly 3–4 — `Document::validate()`,
  `ValidationResult`, `ValidationError`
- `docs/rest-api.md`, sekce 4 — error envelope a obecné error kódy
- `src/Core/Document/ValidationError.php` — současný kontrakt
- `src/Core/Document/ValidationResult.php`
- `src/Api/Controller/FormController.php` — metody `save`,
  `applyStateTransition`, `applyStateTransitionViaDocument` (všechny
  tři mapují `ValidationResult` na `Response::error(...details)`)
- `modules/docs/core/src/DocDocument.php` — `validate()` s `_form` a
  `rows` errory
- `modules/docs/invoicesIn/src/ReceivedInvoiceDocument.php` — další
  `_form` errory
- `frontend/src/components/form/FormEditor.svelte` — všechny tři
  save/transition větve, `fieldErrors` state, `switchToErrorTab`,
  `tabHasError`, banner `__error-banner`
- `frontend/src/components/form/FormElement.svelte` — `error` prop na
  inputech, `FormFieldRow`
- `frontend/src/i18n/cs.js` + `en.js` — `error.VALIDATION_ERROR`,
  `form.saveFailed`, `form.transitionFailed`
- `frontend/src/i18n/errors.js` — `translateError()` (poznámka na konci
  o field-level vs banner mechanismu)

---

## Cíl

Po dokončení tohoto tasku platí:

- `ValidationError` má konstantu `FIELD_FORM = '_form'` a `docs/document-system.md`
  popisuje konvenci pro `column` (konkrétní sloupec / `_form` / případné
  budoucí `_rows`).
- `FormController` v žádné větvi nemění mapování `ValidationError` → wire
  formát (zůstává `{field, code, message}`), jen je dokumentace
  o tom, co jednotlivé hodnoty `field` znamenají.
- `FormEditor.svelte` má jeden sdílený helper `extractValidationErrors`,
  který používají všechny tři save/transition větve (handleSave,
  handleTransition pro nový záznam, handleTransition pro existující
  záznam — **včetně druhého PUT**). Helper rozdělí `error.details` na
  field-bound (`fieldErrors`) a form-level (`formErrors`).
- `FormEditor.svelte` zobrazuje **banner nad tabbarem**, který se ukáže,
  když je `fieldErrors` neprázdné NEBO `formErrors` neprázdné. Banner
  obsahuje nadpis a seznam všech chyb. Field-level chyby v banneru
  obsahují label pole (např. „Partner: Partner je povinný"), form-level
  chyby se vykreslí jen jako text hlášky.
- Field-bound chyby se nadále zobrazují i vedle inputů (současné chování
  zachováno).
- Tabové tečky (`tabHasError`) nadále fungují pro field-bound chyby.
  Form-level chyby tabové tečky neaktivují — od toho je banner.
- Banner i `fieldErrors` se vyčistí při startu dalšího save/transition
  pokusu (současné chování). Po úspěšném save zmizí (současné chování:
  reload formuláře → vyresetuje state).
- `docs/edit-forms.md` má aktualizovanou sekci 8 (Validace a chybové stavy)
  s novým UI modelem a konvencí `_form`.

---

## Specifikace

### Kontrakt `field` v `ValidationError`

| Hodnota `field` | Význam | UI chování |
|-----------------|--------|------------|
| Konkrétní `column` formuláře | Field-level chyba | `fieldErrors[column] = message`; error prop u inputu, tabová tečka, banner s prefixem labelu pole |
| `_form` | Form-level chyba | `formErrors.push({message, code})`; jen banner, žádné field-level efekty |
| Cokoli jiného (`rows`, neznámý sloupec, prázdný string) | Fallback na form-level | Stejně jako `_form` — banner s holou hláškou |

Frontend rozpoznává „field-level" testem proti mapě sloupců formuláře
(`buildElementMap()`). Pokud `field` v mapě není, putuje to do
`formErrors`. To je robustní — backend může používat `_form`, `rows`
nebo cokoli jiného a banner ho odbaví.

Backend doporučená konvence: pro nové validace používat `_form` jako
kanonický form-level marker (constantou
`ValidationError::FIELD_FORM`). Existující `rows` v
`DocDocument::validate()` se může nechat, ale ideálně by ho upravil
follow-up commit na `_form` (mimo rozsah tohoto tasku).

### State model ve `FormEditor.svelte`

Dva oddělené `$state`:

```js
// Pole → hláška; jen pro field, který existuje ve formuláři
let fieldErrors = $state({});

// Form-level chyby (`_form`, neznámé pole, prázdný field)
// {message: string, code: string}
let formErrors = $state([]);
```

Helper, který obě state nastaví ze serverové odpovědi:

```js
/**
 * Rozbalí `error.details` z VALIDATION_ERROR response do field-level
 * a form-level chyb. Field je „field-level" jen pokud odpovídá nějakému
 * sloupci ve formuláři (přes buildElementMap). Vše ostatní (`_form`,
 * `rows`, prázdný field, neznámý sloupec) jde do formErrors.
 */
function extractValidationErrors(details) {
  const elMap = buildElementMap();
  const fieldErrs = {};
  const formErrs = [];
  for (const d of details ?? []) {
    if (d.field && elMap[d.field]) {
      fieldErrs[d.field] = d.message;
    } else {
      formErrs.push({ message: d.message ?? '', code: d.code ?? '' });
    }
  }
  return { fieldErrors: fieldErrs, formErrors: formErrs };
}
```

Helper, který se volá na začátku každého save/transition pokusu —
vyčistí oba state před novým pokusem:

```js
function clearValidationErrors() {
  fieldErrors = {};
  formErrors = [];
}
```

Použití ve všech třech větvích — `handleSave`, `handleTransition` pro
nový záznam (POST), `handleTransition` pro existující záznam (oba dva
PUTy):

```js
// Při startu pokusu
clearValidationErrors();
loadError = null;

// Při VALIDATION_ERROR response
if (res?.error?.code === 'VALIDATION_ERROR' && res?.error?.details) {
  const extracted = extractValidationErrors(res.error.details);
  fieldErrors = extracted.fieldErrors;
  formErrors = extracted.formErrors;
  switchToErrorTab(fieldErrors);
}
```

Klíčové: **i druhý PUT v handleTransition pro existující záznam musí
tento helper použít.** To je bugfix oproti dnešnímu stavu.

### Banner UI

Banner žije v `FormEditor.svelte`, **mezi tabbarem a tab-content**, nebo
ještě výš nad tabbarem. Doporučení: **nad tabbarem** — je to globální
o formuláři, ne o aktuálním tabu.

Struktura:

```svelte
{#if formErrors.length > 0 || Object.keys(fieldErrors).length > 0}
  <div class="shpd-form-editor__validation-banner" role="alert">
    <div class="shpd-form-editor__validation-banner-title">
      {t('form.validation.bannerTitle')}
    </div>
    <ul class="shpd-form-editor__validation-banner-list">
      {#each formErrors as err}
        <li>{err.message}</li>
      {/each}
      {#each fieldEntriesForBanner() as { label, message }}
        <li><strong>{label}:</strong> {message}</li>
      {/each}
    </ul>
  </div>
{/if}
```

Helper pro field entries v banneru — sestaví seznam s labelem pole:

```js
function fieldEntriesForBanner() {
  const elMap = buildElementMap();
  const out = [];
  for (const [column, message] of Object.entries(fieldErrors)) {
    const el = elMap[column];
    const label = el?.label ?? column;
    out.push({ column, label, message });
  }
  return out;
}
```

Styling — sladěn s existujícím `__error-banner` (červené téma), ale jako
samostatná CSS třída `__validation-banner` aby šel nezávisle styliovat:

```css
.shpd-form-editor__validation-banner {
  margin: var(--shpd-space-md);
  padding: var(--shpd-space-sm) var(--shpd-space-md);
  background: var(--shpd-color-danger-soft);
  border: 1px solid var(--shpd-color-danger);
  border-radius: var(--shpd-radius-md);
  color: var(--shpd-color-danger);
  font-size: var(--shpd-font-size-sm);
  flex-shrink: 0;
}

.shpd-form-editor__validation-banner-title {
  font-weight: 600;
  margin-bottom: var(--shpd-space-xs);
}

.shpd-form-editor__validation-banner-list {
  margin: 0;
  padding-left: var(--shpd-space-lg);
  list-style: disc;
}

.shpd-form-editor__validation-banner-list li + li {
  margin-top: 2px;
}

.shpd-form-editor__validation-banner-list strong {
  font-weight: 600;
}
```

Umístění v markupu — **mimo** `.shpd-form-editor__content` (aby
nescrolloval s obsahem). Ideálně pod tabbarem před content divem:

```svelte
<div class="shpd-form-editor">
  {#if formDef && formDef.tabs.length > 1}
    <div class="shpd-form-editor__tab-bar">...</div>
  {/if}

  {#if formErrors.length > 0 || Object.keys(fieldErrors).length > 0}
    <div class="shpd-form-editor__validation-banner">...</div>
  {/if}

  <div class="shpd-form-editor__content">
    {#if loadError}
      <div class="shpd-form-editor__error-banner">{loadError}</div>
    {/if}
    ...
```

`loadError` banner (pro non-VALIDATION chyby) ponechat tam, kde je —
slouží jiným typům chyb (síťové, transition non-validation,
RECORD_NOT_FOUND, …).

### i18n stringy

Přidat do `cs.js` a `en.js`:

```js
// cs.js
'form.validation.bannerTitle': 'Formulář obsahuje chyby:',

// en.js
'form.validation.bannerTitle': 'The form contains errors:',
```

Nadpis je záměrně neutrální — banner zobrazuje při Uložit i při Potvrdit
i při Opravit. Nepoužíváme „Doklad nelze potvrdit" protože komponenta
nezná konkrétní akci.

---

## Změny souborů — backend

### 1. Konstanta `ValidationError::FIELD_FORM`

**Soubor:** `src/Core/Document/ValidationError.php`

Přidat konstantu:

```php
class ValidationError
{
    /**
     * Konvenční hodnota `column` pro chyby, které nepatří konkrétnímu poli.
     * Frontend je vykreslí v top-level banneru formuláře, ne vedle inputu.
     */
    public const FIELD_FORM = '_form';

    public function __construct(
        public readonly string $column,
        public readonly string $message,
        public readonly string $code = '',
    ) {}
    ...
}
```

Existující call sites (`ReceivedInvoiceDocument`, `DocDocument`) **NEMĚNÍ
SE** v tomto tasku — string `'_form'` je už používaný a kontrakt zůstává
identický. Konstanta je tu jako kanonická reference pro nové callsites
a pro dokumentaci.

Pokud chceš, můžeš v rámci tohoto tasku volitelně přepsat existující
`'_form'` literály na `ValidationError::FIELD_FORM` (nejsou jich
desítky — je to ~3 místa). Není to nutné, ale je to čisté.

### 2. Žádné jiné PHP změny

`FormController` mapování `column → field` v response je už ve formátu,
který frontend čeká (`['field' => $e->column, 'code' => $e->code,
'message' => $e->message]`). Nezasahovat.

---

## Změny souborů — frontend

### 3. `FormEditor.svelte` — split state + extractor

**Soubor:** `frontend/src/components/form/FormEditor.svelte`

Změny ve `<script>` části:

a) Nahradit `let fieldErrors = $state({});` dvěma states:

```js
let fieldErrors = $state({});
let formErrors = $state([]);
```

b) Přidat helpery `extractValidationErrors`, `clearValidationErrors`,
`fieldEntriesForBanner` (viz Specifikace výše).

c) Refactor `handleSave`:

```js
async function handleSave() {
  saving = true;
  clearValidationErrors();
  loadError = null;
  // ... existující POST/PUT logika ...
  if (res?.success) {
    onSaved?.(res.data);
    currentId = res.data?.id ?? currentId;
    await loadForm(table, currentId);
  } else if (res?.error?.code === 'VALIDATION_ERROR' && res?.error?.details) {
    const extracted = extractValidationErrors(res.error.details);
    fieldErrors = extracted.fieldErrors;
    formErrors = extracted.formErrors;
    switchToErrorTab(fieldErrors);
  } else {
    loadError = res?.error ? translateError(res.error) : t('form.saveFailed');
  }
  saving = false;
}
```

d) Refactor `handleTransition` — obě větve (nový i existující záznam):

```js
async function handleTransition(targetState, closeForm = false) {
  saving = true;
  clearValidationErrors();
  loadError = null;

  if (currentId == null) {
    // Nový záznam: ulož celý formulář s požadovaným stavem
    const data = { ...sanitizeFormData(formData), docState: targetState };
    const res = await post(`/_ui/form/${table}/save`, data);
    if (res?.success) {
      // ... existující success branch ...
    } else if (res?.error?.code === 'VALIDATION_ERROR' && res?.error?.details) {
      const extracted = extractValidationErrors(res.error.details);
      fieldErrors = extracted.fieldErrors;
      formErrors = extracted.formErrors;
      switchToErrorTab(fieldErrors);
    } else {
      loadError = res?.error ? translateError(res.error) : t('form.saveFailed');
    }
  } else {
    // Existující záznam: nejdřív ulož data, pak přechod stavu
    const saveRes = await put(`/_ui/form/${table}/save/${currentId}`, sanitizeFormData(formData));
    if (!saveRes?.success) {
      if (saveRes?.error?.code === 'VALIDATION_ERROR' && saveRes?.error?.details) {
        const extracted = extractValidationErrors(saveRes.error.details);
        fieldErrors = extracted.fieldErrors;
        formErrors = extracted.formErrors;
        switchToErrorTab(fieldErrors);
      } else {
        loadError = saveRes?.error ? translateError(saveRes.error) : t('form.saveFailed');
      }
      saving = false;
      return;
    }
    // Přechod stavu (DRUHÝ PUT — TADY BYL BUG!)
    const res = await put(`/_ui/form/${table}/save/${currentId}`, { docState: targetState });
    if (res?.success) {
      // ... existující success branch ...
    } else if (res?.error?.code === 'VALIDATION_ERROR' && res?.error?.details) {
      const extracted = extractValidationErrors(res.error.details);
      fieldErrors = extracted.fieldErrors;
      formErrors = extracted.formErrors;
      switchToErrorTab(fieldErrors);
    } else {
      loadError = res?.error ? translateError(res.error) : t('form.transitionFailed');
    }
  }

  saving = false;
}
```

**Klíčové: druhý PUT v existující-záznam větvi má teď úplnou
VALIDATION_ERROR větev.** To je bugfix.

### 4. `FormEditor.svelte` — banner markup + CSS

V template části vložit banner mezi tabbar a content (viz Specifikace).
V CSS přidat třídy `__validation-banner`, `__validation-banner-title`,
`__validation-banner-list` (viz Specifikace).

### 5. i18n stringy

**Soubory:** `frontend/src/i18n/cs.js`, `frontend/src/i18n/en.js`

Přidat klíč `form.validation.bannerTitle` viz Specifikace. Synchronizaci
ověřit přes `npm run check:i18n`.

### 6. Volitelně: čištění komentáře v `errors.js`

**Soubor:** `frontend/src/i18n/errors.js` — komentář na začátku zmiňuje
„Field-level error display (in form fields) is handled separately in
FormEditor.svelte; this helper is for the 'banner' / `alert()` line."

Rozšířit komentář o zmínku, že banner v FormEditoru má dvě úrovně:
form-level a field-level chyby z VALIDATION_ERROR `details[]`.
`translateError()` se z toho nemění — pořád obsluhuje top-level
`error.code` → text.

---

## Testy

### Backend — `tests/Core/Document/ValidationErrorTest.php` (případně rozšířit existující)

- Test: `ValidationError::FIELD_FORM === '_form'`
- Test: konstrukce s `column = ValidationError::FIELD_FORM` produkuje
  `toArray()` s `column: '_form'`

(Pokud testy pro ValidationError zatím neexistují, lze pokrýt v rámci
`ValidationResultTest`.)

### Frontend — manuální smoke test

Backend test data: vezmi FPB (přijatá faktura) v Konceptu, který má jen
částečně vyplněnou hlavičku — chybí partner, vat_registration, žádné
řádky, nemá vlastní firmu nakonfigurovanou.

1. **Klik Potvrdit (10 → 20).**
   - Před fixem: „Validace selhala" banner, žádné detaily.
   - Po fixu: banner „Formulář obsahuje chyby:" se seznamem:
     - „Není nastavena vlastní firma. …" (form-level)
     - „Bankovní spojení dodavatele je povinné — …" (form-level)
     - „Doklad musí mít alespoň jeden řádek" (form-level — `rows`
       není sloupec)
     - „Partner: Partner je povinný" (field-level, s labelem)
     - „Registrace DPH: Registrace DPH je povinná" (field-level)
   - Field-level chyby současně vykresleny i vedle inputů.
   - Tab obsahující field s chybou má červenou tečku.
   - Aktivní tab přepnutý na ten s chybou.

2. **Oprav partner a vat_registration, klik znovu Potvrdit.**
   - Banner se zaktualizuje — chyby pro partner a vat_registration zmizí,
     ostatní zůstanou.
   - Field-level chyby u opravených polí zmizí.

3. **Klik Uložit (handleSave) s prázdným partner.**
   - Stejný banner mechanismus, jen bez chyby „vlastní firma" (protože
     newState zůstává 10, validace tu pravidla přeskakuje).

4. **Klik Opravit (40 → 80) na záznamu V pořádku s chybějící vlastní
   firmou.**
   - Banner s form-level chybou „Není nastavena vlastní firma…" (toto
     je transition 40 → 80, který spadá pod `in_array([20, 40, 80])` v
     DocDocument::validate).
   - Tohle ověřuje, že **druhý PUT v existující-záznam větvi** teď
     details rozbaluje. Bez fixu by se zobrazilo jen „Validace selhala".

5. **Úspěšný save / transition.**
   - Banner zmizí.
   - Field-level chyby u všech polí zmizí.
   - Formulář se reloadne (existující chování).

6. **Banner ne při successful save → loadForm reload.**
   - `loadForm` neresetuje state explicitně — `clearValidationErrors()`
     se volá až při dalším save/transition pokusu. Po úspěšném save
     ale `formErrors` a `fieldErrors` jsou prázdné z předchozího
     pokusu, takže banner zmizí přirozeně.

### Frontend — komponentní test (volitelné)

Pokud má `FormEditor` v projektu komponentní testy (zkontrolovat —
typicky `frontend/src/**/*.test.js` nebo Vitest setup), přidat test:

- Mock fetch vrací VALIDATION_ERROR s mix `_form` + field-level details.
- Po kliknutí na Uložit:
  - `fieldErrors` obsahuje jen field-level pole, která existují ve
    formuláři.
  - `formErrors` obsahuje zbytek (s `_form` field i s neznámým fieldem).
  - Banner se vykreslil v DOM.
  - Tab s field-level chybou má `--error` třídu.

(Pokud komponentní testy v projektu nejsou, manuální smoke test stačí.)

---

## Dokumentace

### `docs/edit-forms.md` — sekce 8 (Validace a chybové stavy)

Aktualizovat / rozšířit. Současný obsah popisuje jen wire formát
(`{code, details: [{field, code, message}]}`) a větu „Klient zobrazí
chyby u příslušných polí." To rozšířit o:

- Konvence `field`: konkrétní sloupec / `_form` / fallback (neznámý
  sloupec, prázdný string) — viz tabulka v Specifikaci tohoto tasku.
- Frontend rozlišuje field-level a form-level chyby pomocí
  `buildElementMap()` — neznámé `field` jde do form-level.
- Form-level chyby se zobrazují v banneru nad tabbarem se seznamem
  všech chyb (form-level holé, field-level s prefixem labelu pole).
- Field-level chyby se navíc zobrazují vedle inputu a aktivují tabovou
  tečku.

Příklad response pro Potvrdit u FPB v ostře neplatném stavu (mix
form-level i field-level chyb):

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed",
    "details": [
      {"field": "partner",          "code": "required",              "message": "Partner je povinný"},
      {"field": "vat_registration", "code": "required",              "message": "Registrace DPH je povinná"},
      {"field": "rows",             "code": "no_rows",               "message": "Doklad musí mít alespoň jeden řádek"},
      {"field": "_form",            "code": "no_own_company",        "message": "Není nastavena vlastní firma…"},
      {"field": "_form",            "code": "partner_bank_required", "message": "Bankovní spojení dodavatele…"}
    ]
  }
}
```

### `docs/document-system.md` — sekce 4 (ValidationResult)

Přidat poznámku o konstantě `ValidationError::FIELD_FORM` a o významu
hodnot `column`:

- Konkrétní sloupec → chyba se zobrazí vedle pole ve formuláři.
- `_form` (konstanta `ValidationError::FIELD_FORM`) → chyba bez vazby
  na konkrétní pole; vykreslí se v top-level banneru.
- Cokoli jiného → frontend fallbackne na form-level (banner). Doporučená
  cesta pro nové validace je explicitně používat `FIELD_FORM`.

Aktualizovat příklad v sekci 11 (PersonDocument) — žádná změna v
implementaci, ale lze zmínit, že form-level validace by používala
`$result->addError(ValidationError::FIELD_FORM, '...', '...')`.

### `CLAUDE.md`

Krátká poznámka (1–2 věty) v sekci o formulářích / validaci — odkaz
na sekci 8 v `docs/edit-forms.md`.

---

## Hotovo když

- [ ] `vendor/bin/phpunit 2>&1` — všechny testy procházejí
- [ ] `cd frontend && npm run build 2>&1` — build prochází bez chyb
      a warningů
- [ ] `cd frontend && npm run check:i18n` — i18n synchronizace OK
- [ ] Manuální smoke test #1 (Potvrdit FPB s mix chybami) — banner se
      zobrazí se všemi položkami
- [ ] Manuální smoke test #2 (postupná oprava chyb) — banner se průběžně
      aktualizuje
- [ ] Manuální smoke test #4 (Opravit na readOnly záznamu) — druhý PUT
      teď rozbaluje details (klíčový bugfix)
- [ ] Manuální smoke test #5 (úspěch) — banner zmizí
- [ ] `docs/edit-forms.md` má aktualizovanou sekci 8
- [ ] `docs/document-system.md` má poznámku o `FIELD_FORM` konstantě
- [ ] `CLAUDE.md` zmiňuje konvenci `_form` (odkaz na docs)
- [ ] V `FormEditor.svelte` nezůstaly duplicitní VALIDATION_ERROR
      blocks — všechny tři větve používají `extractValidationErrors`

---

## Mimo rozsah

- **Plná i18n vrstva pro validační hlášky** — texty v PHP zůstávají
  hardcoded česky. Přesun do FE slovníků (kód + parametry → překlad)
  je samostatný task. Pro EN UI budou validační hlášky zatím česky.
- **Severity** — všechny chyby jsou stejně závažné (blokující). Warning
  level (`severity: 'warning'`) je výhled, ne tento task.
- **Row-level chyby** (`rows.N.col`) — kontrakt je v dokumentaci od
  začátku, ale dnes nikde nepoužitý. Frontend je zatím odbaví jako
  form-level (přes fallback v `buildElementMap()` lookupu). Specifická
  navigace na řádek subtable tabu je samostatný task.
- **Změna existujícího `addError('rows', ...)`** v `DocDocument` na
  `_form` — funguje i s `rows`, takže zbytečně refactorovat.
- **Tooltip / popover na tlačítku Potvrdit** s počtem chyb — banner
  stačí, tlačítko zůstává plain.
- **Animace banneru** (fade-in) — nice-to-have, ne nutné.

---

## Doporučené pořadí

1. Backend — konstanta `ValidationError::FIELD_FORM` + dokumentace v
   `docs/document-system.md`. Phpunit projde (žádná funkční změna).
2. Frontend — refactor `FormEditor.svelte`:
   1. Přidat `formErrors` state vedle `fieldErrors`.
   2. Přidat helpery `extractValidationErrors`, `clearValidationErrors`,
      `fieldEntriesForBanner`.
   3. Refactor `handleSave` na helpery (testovatelné samostatně —
      Uložit u nového záznamu s chybami).
   4. Refactor `handleTransition` (nový záznam) na helpery.
   5. Refactor `handleTransition` (existující záznam) — **klíčový krok,
      tady byl bug**. Druhý PUT teď taky rozbaluje details.
3. Frontend — banner markup + CSS + i18n stringy.
4. Manuální smoke test — všechny scénáře z testovací sekce.
5. Dokumentace — `docs/edit-forms.md` sekce 8, `docs/document-system.md`
   poznámka, `CLAUDE.md`.

**Před úpravou `FormEditor.svelte` přečíst celý soubor.** `patch_file`
vyžaduje přesné whitespace a `FormEditor` má hodně komentářů s
diakritikou a em-dashes — patche jsou choulostivé.

---

## Konvence

- **Jazyk**: UI texty česky (cs.js source), kód a komentáře v PHP
  anglicky, komentáře ve Svelte mix podle stávajícího stylu.
- **PHP 8.3** strict_types, readonly properties, konstanty na třídách
  veřejné a typované.
- **Snake_case na drátě** (`error.details[].field`), camelCase v PHP
  a JS state. Žádná změna existujícího wire formátu.
- **Svelte 5 runes** (`$state`, `$derived`, `$effect`, `$props`).
- **Helpery v FormEditor** — žádné nové utility soubory, helpery jako
  funkce dovnitř `<script>` (konzistentní s `buildElementMap`,
  `sanitizeFormData`, `tabHasError`, …).
- **CSS** — BEM s prefixem `shpd-`, CSS proměnné (`--shpd-color-danger`,
  `--shpd-color-danger-soft`, `--shpd-space-*`, `--shpd-radius-*`).
- **Před patchováním Svelte komponent přečíst celý soubor** —
  `patch_file` je citlivý na whitespace a speciální znaky (em-dashes
  v komentářích).
