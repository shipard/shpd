# Task: Hlavička formuláře — strukturované info (HeaderInfo)

## Motivace

Dnešní hlavička editačního modalu obsahuje jen statický titulek (`title` /
`title_new` z `FormDefinition`), badge stavu a křížek. To je u nového záznamu
v pořádku — uživatel ještě nemá co zobrazit — ale u existujícího záznamu
hlavička neunese žádný identifikační kontext. Konkrétně:

- Formulář **Osoba** v editaci firmy zobrazuje jen „Osoba" — uživatel musí
  scrollovat na tab „Základní údaje" a tam si přečíst název / IČO / kód.
- Stejný problém budou mít všechny budoucí formuláře (Faktury, Doklady,
  Adresy…) — všude se nabízí pár klíčových identifikátorů zobrazit nahoře.

Tento task přidává **strukturovaný HeaderInfo** do `FormDefinition` — server
ho volitelně dodá u existujícího záznamu, klient ho renderuje v hlavičce
modalu pod hlavním titulkem. Pro nové záznamy se nezobrazuje (FormDefinition
ho neposílá → fallback na `title_new`).

Kanonický příklad — Osoba typu Firma:

```
┌───────────────────────────────────────────────────────────────────────┐
│ Beta Software, a.s.                                  [Koncept]    [×] │
│ IČO 68253848 · Kód osoby TEST-0098                                    │
├───────────────────────────────────────────────────────────────────────┤
│ [Základní údaje] [Kontaktní údaje] [Kontakty] [Adresy] …              │
```

---

## Před implementací přečti

- `docs/edit-forms.md` — celá architektura formulářů, zejména:
  - kapitola 3 (FormDefinition — datová struktura)
  - kapitola 9 (fullSize flag — chování modalu)
  - kapitola 11 (PHP třída TableForm)
  - kapitola 16 (Svelte komponenty)
- `frontend/src/components/ui/Modal.svelte` — současný header (`title`,
  `headerExtra` snippet, křížek)
- `frontend/src/components/form/FormDialog.svelte` — orchestrace headeru,
  callback `onFormLoaded`
- `frontend/src/components/form/FormEditor.svelte` — kdy a jak se volá
  `onFormLoaded` (z `$effect` na změnu `formDef`)
- `modules/base/persons/src/PersonsForm.php` — bude se rozšiřovat

---

## Cíl

Po dokončení tohoto tasku platí:

- `FormDefinition` má volitelný strukturovaný field `headerInfo` (wire
  `header_info`)
- `TableForm` má virtuální metodu `buildHeaderInfo(array $data): ?FormHeaderInfo`
  (default vrací `null`)
- `FormController` vkládá `headerInfo` do FormDefinition **jen** pro existující
  záznamy v meta a save endpointech. Pro nové záznamy a recalculate `header_info`
  zůstává `null`.
- `PersonsForm::buildHeaderInfo()` vrací data podle typu osoby (Firma /
  Fyzická osoba / Neurčeno) — viz Specifikace níže
- `Modal.svelte` umí dvouřádkový header (titulek + volitelný subtitle)
- `FormDialog.svelte` zobrazuje header s `headerInfo.title` + řádkem
  `Label1 hodnota1 · Label2 hodnota2 · …`
- Header **neodráží neuložené změny** — aktualizuje se jen po `load` a po
  úspěšném `save`, ne po `recalculate`
- `docs/edit-forms.md` má novou sekci „21. Hlavička formuláře (HeaderInfo)"

---

## Specifikace — PersonsForm

`PersonsForm::buildHeaderInfo(array $data): ?FormHeaderInfo` vrací:

| `person_type` | Výstup |
|---------------|--------|
| `Undefined` (0) nebo null | `null` |
| `Company` (2) | `title = full_name`; `info = [{label: "IČO", value: company_id}, {label: "Kód osoby", value: person_id}]` |
| `Person` (1) | `title = full_name`; `info = [{label: "Datum narození", value: birth_date ve formátu d.m.Y}, {label: "Kód osoby", value: person_id}]` |

Pravidla:

- Pokud `full_name` je prázdné → vrátí `null` (žádný header info, fallback na
  `title`).
- Pokud konkrétní hodnota v `info` je prázdná (např. firma nemá IČO) → daná
  položka se z pole `info` **vynechá**. Pole `info` může být prázdné, title
  pak stojí samostatně.
- Datum narození ve formátu `d.m.Y`. DB DATE přichází jako string `Y-m-d` (viz
  `DataSourceConnection`); na transformaci použij
  `\DateTimeImmutable::createFromFormat('Y-m-d', $value)`. Pokud parsing
  selže, položku vynech.

Texty labelů (`IČO`, `Kód osoby`, `Datum narození`) zapsat česky — `PersonsForm`
je dnes plně český a i18n vrstva pro PHP texty zatím neexistuje. Konzistentní
s ostatními českými stringy v `PersonsForm`.

---

## Změny souborů — backend

### 1. Nová třída `src/Core/Form/FormHeaderInfo.php`

```php
<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

/**
 * Strukturovaná hlavička editačního formuláře — zobrazuje se v modalu
 * pod hlavním titulkem u existujícího záznamu.
 *
 * `title` je hlavní řádek (např. název firmy nebo celé jméno osoby).
 * `info` je seznam štítkovaných hodnot zobrazených na druhém řádku oddělených
 * tečkou (např. „IČO 68253848 · Kód osoby TEST-0098").
 *
 * Sestavuje ji `TableForm::buildHeaderInfo()`; klient ji jen renderuje.
 */
final class FormHeaderInfo
{
    /**
     * @param list<array{label: string, value: string}> $info
     */
    public function __construct(
        public readonly string $title,
        public readonly array $info = [],
    ) {}

    /**
     * @return array{title: string, info: list<array{label: string, value: string}>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'info' => $this->info,
        ];
    }
}
```

### 2. Rozšíření `FormDefinition`

**Soubor:** `src/Core/Form/FormDefinition.php`

Přidat:
- nový konstruktorový parametr `?FormHeaderInfo $headerInfo = null`
- public readonly property `?FormHeaderInfo $headerInfo`
- v `toArray()` snake_case klíč `header_info` (přítomný **vždy**; `null` když
  nenastaven — klient se na hodnotu spoléhá)
- immutable wither: `public function withHeaderInfo(?FormHeaderInfo $info): self`
  (klonuje instanci s novým `headerInfo`); použije ji `FormController`

```php
public function withHeaderInfo(?FormHeaderInfo $headerInfo): self
{
    return new self(
        table: $this->table,
        title: $this->title,
        titleNew: $this->titleNew,
        tabs: $this->tabs,
        fullSize: $this->fullSize,
        docStates: $this->docStates,
        headerInfo: $headerInfo,
    );
}
```

Pokud `FormDefinition` má více konstruktorových parametrů, než je v ukázce —
zachovat existující signaturu, jen na konec přidat `?FormHeaderInfo $headerInfo = null`.

### 3. Rozšíření `TableForm`

**Soubor:** `src/Core/Form/TableForm.php`

Přidat virtuální metodu:

```php
/**
 * Volitelná strukturovaná hlavička formuláře pro existující záznam.
 *
 * Default: žádná hlavička (modal zobrazí jen `title` z FormDefinition).
 * Subclassy mohou přepsat a vrátit `FormHeaderInfo` s identifikačními údaji
 * (např. název firmy, IČO, kód).
 *
 * Tato metoda se volá v `FormController` pro `GET /meta/{id}` a po úspěšném
 * `save` — NE pro `GET /meta` (nový záznam) ani pro `recalculate`. Hodnoty
 * v `$data` jsou tedy data uložená v DB, ne živá data z formuláře.
 */
public function buildHeaderInfo(array $data): ?FormHeaderInfo
{
    return null;
}
```

### 4. Rozšíření `FormController`

**Soubor:** `src/Core/Form/FormController.php`

V endpointu **`GET /_ui/form/{table}/meta/{id}`** (existující záznam):
- po sestavení `formDefinition` a načtení `$data` z DB zavolat
  `$form->buildHeaderInfo($data)`
- pokud výsledek není `null`, volat `$formDefinition = $formDefinition->withHeaderInfo($headerInfo)`

V endpointu **`GET /_ui/form/{table}/meta`** (nový záznam):
- `buildHeaderInfo` se **nevolá**, header_info zůstává `null`

V endpointech **`POST/PUT /_ui/form/{table}/save[/{id}]`**:
- po úspěšném INSERT/UPDATE načíst čerstvá data z DB (`fetchRow`) — toto se
  typicky už děje, aby se vrátil `data` v response
- na těchto čerstvých datech zavolat `$form->buildHeaderInfo($data)`
- vložit do FormDefinition stejným způsobem jako u meta endpointu
- response struktura: stejná jako dnes, jen `formDefinition.header_info`
  bude vyplněno

V endpointu **`POST /_ui/form/{table}/recalculate`**:
- `buildHeaderInfo` se **nevolá** — recalculate operuje s neuloženými daty,
  hlavičku schválně neaktualizujeme

**Pozor:** `recalculate()` v `TableForm` subclassech (např. `PersonsForm`)
typicky volá `buildFormDefinition()` → ten může vracet FormDefinition bez
header_info (default null konstruktoru). To je v pořádku. `header_info` se
plní výhradně přes `withHeaderInfo()` v `FormController` v load/save cestách.

### 5. Implementace v `PersonsForm`

**Soubor:** `modules/base/persons/src/PersonsForm.php`

Přidat metodu `buildHeaderInfo` viz Specifikace výše. Skeleton:

```php
public function buildHeaderInfo(array $data): ?FormHeaderInfo
{
    $fullName = trim((string) ($data['full_name'] ?? ''));
    if ($fullName === '') {
        return null;
    }

    $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));

    $info = [];
    if ($personType === PersonType::Company) {
        $companyId = trim((string) ($data['company_id'] ?? ''));
        if ($companyId !== '') {
            $info[] = ['label' => 'IČO', 'value' => $companyId];
        }
    } elseif ($personType === PersonType::Person) {
        $birthDate = $data['birth_date'] ?? null;
        if ($birthDate) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $birthDate);
            if ($dt instanceof \DateTimeImmutable) {
                $info[] = ['label' => 'Datum narození', 'value' => $dt->format('d.m.Y')];
            }
        }
    } else {
        // Undefined → nezobrazujeme nic
        return null;
    }

    $personId = trim((string) ($data['person_id'] ?? ''));
    if ($personId !== '') {
        $info[] = ['label' => 'Kód osoby', 'value' => $personId];
    }

    return new FormHeaderInfo(title: $fullName, info: $info);
}
```

Importy doplnit (`use Shipard\Core\Form\FormHeaderInfo;`).

---

## Změny souborů — frontend

### 6. `Modal.svelte` — dvouřádkový header

**Soubor:** `frontend/src/components/ui/Modal.svelte`

Přidat volitelný `subtitle` Snippet do `Props`. Pokud je předaný, renderuje
se pod `title` jako druhý řádek headeru.

```svelte
interface Props {
  title: string;
  /** Optional second header line, rendered below the title in a smaller,
   *  lighter style. Useful for record identifiers (e.g. „IČO 68253848 ·
   *  Kód osoby TEST-0098"). */
  subtitle?: Snippet;
  // ... ostatní props ...
}
```

Strukturu headeru přerovnat na flex sloupec uvnitř hlavní řádky:

```svelte
<div class="shpd-modal__header">
  <div class="shpd-modal__header-main">
    <span class="shpd-modal__title">{title}</span>
    {#if subtitle}
      <span class="shpd-modal__subtitle">{@render subtitle()}</span>
    {/if}
  </div>
  {#if headerExtra}
    <span class="shpd-modal__header-extra">{@render headerExtra()}</span>
  {/if}
  <button class="shpd-modal__close" onclick={onClose} aria-label={t('common.close')}>×</button>
</div>
```

CSS — header zůstává flex-row, ale title+subtitle jsou v nested flex-column
kontejneru. Subtitle: menší font, sekundární barva, truncate na overflow.

```css
.shpd-modal__header-main {
  flex: 1;
  min-width: 0;          /* aby ellipsis fungoval uvnitř flex */
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.shpd-modal__subtitle {
  font-size: var(--shpd-font-size-sm);
  color: var(--shpd-color-text-secondary);
  font-weight: 400;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
```

Existující `.shpd-modal__title` přesunout dovnitř `.shpd-modal__header-main`
(stejný styl, jen už není flex item hlavní řádky). Vrchol `align-items: center`
na `.shpd-modal__header` ponech — vertikálně centruje title-bloku, badge a křížek.

### 7. `FormDialog.svelte` — render headerInfo

**Soubor:** `frontend/src/components/form/FormDialog.svelte`

Přidat state:

```ts
let savedHeaderInfo = $state<{ title: string; info: Array<{ label: string; value: string }> } | null>(null);
```

Reset při otevření modalu (vedle `metaLoaded`, `currentTitle`, atd.):

```ts
savedHeaderInfo = null;
```

Rozšířit `handleFormLoaded`:

```ts
function handleFormLoaded(info: {
  title: string;
  docStates: Record<string, unknown> | null;
  headerInfo: { title: string; info: Array<{ label: string; value: string }> } | null;
}) {
  currentTitle = info.title;
  currentDocStates = info.docStates;
  savedHeaderInfo = info.headerInfo;
}
```

Titulek modalu se rozhoduje takto:

```ts
const headerTitle = $derived(
  savedHeaderInfo?.title || currentTitle || t('common.loading')
);
```

Subtitle snippet pro Modal — render `headerInfo.info` jako spojený řádek
s `·` separátorem:

```svelte
<Modal
  title={headerTitle}
  ...
>
  {#snippet subtitle()}
    {#if savedHeaderInfo && savedHeaderInfo.info.length > 0}
      {savedHeaderInfo.info.map(i => `${i.label} ${i.value}`).join(' · ')}
    {/if}
  {/snippet}

  {#snippet headerExtra()}
    ...
  {/snippet}
  ...
</Modal>
```

Snippet se vykreslí jen tehdy, pokud `info` má aspoň jednu položku — jinak
zůstane druhý řádek prázdný a CSS gap to zvládne (snippet nesmí být předaný
do Modalu, pokud nemá co renderovat — místo conditional snippetu lze předat
snippet vždy a `{#if}` mít uvnitř, viz výše).

### 8. `FormEditor.svelte` — propagace headerInfo

**Soubor:** `frontend/src/components/form/FormEditor.svelte`

Přidat state, který drží header_info **uložené** v DB (ne živé z formData):

```js
// Header info ze serveru — aktualizuje se jen v loadForm (z formDef.header_info),
// NE v handleTrigger. Tím hlavička modalu odráží uložená data, ne neuložené změny.
let savedHeaderInfo = $state(null);
```

V `loadForm` po úspěšném načtení:

```js
formDef = res.data.formDefinition;
savedHeaderInfo = formDef.header_info ?? null;
```

V `handleTrigger` (recalculate) — **NIC nepřidávat**. `savedHeaderInfo`
zůstane jaký byl. (`formDef` se aktualizuje, ale `formDef.header_info` se
ignoruje. Po recalculate server header_info beztak neposílá — bude `null` —
ale i kdyby ho omylem poslal, ignorujeme.)

Rozšířit `$effect` s callbackem `onFormLoaded`:

```js
$effect(() => {
  if (formDef) {
    onFormLoaded?.({
      title: formTitle,
      docStates: formDef.doc_states ?? null,
      headerInfo: savedHeaderInfo,
    });
  }
});
```

`$effect` se trigger i při změně `savedHeaderInfo` (load nebo save → loadForm),
takže callback se zavolá s aktuální hodnotou.

---

## Testy

### Backend — `tests/Core/Form/FormHeaderInfoTest.php`

- Konstruktor se vyplněnými poli → `toArray()` vrátí `{title, info}` strukturu
- `info` default prázdné pole
- Imutabilita: `withHeaderInfo` u FormDefinition vrací novou instanci, původní
  je nezměněná

### Backend — `tests/Module/Base/Persons/PersonsFormHeaderInfoTest.php`

- Prázdný `full_name` → vrátí `null`
- `person_type = Undefined` (0) → vrátí `null`
- `person_type = Person` s vyplněným `full_name` a `birth_date = "1990-05-14"` →
  title = full_name, info obsahuje položku `{Datum narození, 14.05.1990}` a
  `{Kód osoby, person_id}`
- `person_type = Person` s vyplněným `full_name` ale prázdným `birth_date` →
  info obsahuje pouze `Kód osoby`
- `person_type = Company` s vyplněnými všemi poli → info obsahuje
  `{IČO, …}` a `{Kód osoby, …}` v tomto pořadí
- `person_type = Company` s prázdným `company_id` → info obsahuje pouze
  `Kód osoby`

### Backend — `tests/Core/Form/FormControllerTest.php` (nebo integrační test)

- `GET /meta/{id}` u Osoby typu Firma vrací `header_info` ne-null
- `GET /meta` (nový záznam) vrací `header_info: null`
- `POST /recalculate` vrací `header_info: null`
- `POST /save` (nový záznam) vrací v response `formDefinition.header_info`
  ne-null po uložení

(Pokud testy pro FormController v tomto stylu zatím neexistují, stačí pokrýt
mechanismus na úrovni unit testů `FormHeaderInfo` + `PersonsForm`.)

### Frontend — manuální smoke test

- Nová Osoba (žádný `person_type`) → header „Nová osoba", bez subtitle
- Existující firma (Beta Software) → header „Beta Software, a.s." + řádek
  „IČO 68253848 · Kód osoby TEST-0098", vpravo badge stavu + křížek
- Existující fyzická osoba s datem narození → „Jméno Příjmení" + řádek
  „Datum narození 14.05.1990 · Kód osoby TEST-…"
- V existující firmě změnit `full_name` → header se **nezmění** (dirty stav
  ano, ale hlavička reflektuje uložená data)
- Uložit → header se aktualizuje na novou hodnotu
- Sub-formuláře (Kontakt z Osoby) — sub-form má vlastní `headerInfo`
  pravidla; default `TableForm::buildHeaderInfo` vrací `null`, takže Kontakt
  bude mít jen `title` „Kontakt" — což je správně (nebudeme to teď řešit)

---

## Dokumentace

### `docs/edit-forms.md` — nová sekce „21. Hlavička formuláře (HeaderInfo)"

Obsah (zhruba):

- Co to je a kdy se zobrazuje (existující záznam, ne nový, ne recalculate)
- Struktura `FormHeaderInfo` (title + info pole)
- Wire formát (`header_info` v `FormDefinition` JSON)
- Metoda `TableForm::buildHeaderInfo` — kdy se volá, jaké dostane data
  (uložená v DB, ne živá z formuláře)
- Příklad implementace (z `PersonsForm`)
- Vizuální layout (dva řádky, separátor `·` mezi info položkami)
- Pravidlo: vrátit `null` pokud nemáme co zobrazit (typicky prázdný hlavní
  identifikátor); prázdné položky `info` se vynechávají

### `CLAUDE.md`

Krátká poznámka v sekci o formulářích — odkaz na novou sekci v
`docs/edit-forms.md`.

---

## Hotovo když

- [ ] `vendor/bin/phpunit 2>&1` — všechny testy procházejí
- [ ] `cd frontend && npm run build 2>&1` — build prochází bez chyb a warningů
- [ ] Manuální smoke test (viz výše) — všechny scénáře fungují
- [ ] Nový záznam Osoby ukazuje jen titulek „Nová osoba"
- [ ] Existující firma ukazuje dvouřádkový header s názvem + IČO + kódem
- [ ] Existující fyzická osoba ukazuje dvouřádkový header s celým jménem +
      datem narození + kódem
- [ ] Změna pole `full_name` neaktualizuje hlavičku (čeká na uložení)
- [ ] Po uložení se hlavička aktualizuje
- [ ] Recalculate (změna `person_type`) hlavičku nepřepisuje
- [ ] `docs/edit-forms.md` má sekci 21 popisující HeaderInfo
- [ ] `CLAUDE.md` zmiňuje HeaderInfo

---

## Mimo rozsah

- HeaderInfo pro jiné formuláře než Osoby (Faktury, Doklady, Adresy…) — řeší
  se v navazujících taskech, mechanismus tu jen zavádíme
- JSONC deklarativní HeaderInfo (něco jako `"headerInfo": {"titleColumn":
  "full_name", "infoColumns": ["company_id", "person_id"]}`) — později,
  zatím stačí PHP override
- Klikatelné nebo akční prvky v headeru (např. tlačítka „Otevřít historii")
- I18n pro labely v HeaderInfo (`'IČO'`, `'Kód osoby'` jsou tvrdě česky) —
  řeší se v rámci celkové i18n pro PHP texty
- Mobilní layout — současný modal je primárně desktop, header se přizpůsobí
  automaticky

---

## Doporučené pořadí

1. Backend — `FormHeaderInfo`, `FormDefinition` rozšíření, `TableForm` default
   metoda. Unit testy.
2. Backend — `FormController` napojení (meta + save endpointy). Integrační /
   smoke test.
3. Backend — `PersonsForm::buildHeaderInfo` implementace + testy.
4. Frontend — `Modal.svelte` dvouřádkový header (s mock subtitle pro testy).
5. Frontend — `FormEditor.svelte` propagace headerInfo + `FormDialog.svelte`
   render.
6. Manuální smoke test na běžícím DS.
7. Dokumentace — `docs/edit-forms.md` sekce 21 + `CLAUDE.md`.

## Konvence

- **Jazyk**: UI texty / labely v HeaderInfo česky, kód a komentáře v PHP
  anglicky, komentáře ve Svelte mix (jak je dnes — česky pro vysvětlení
  business logiky, anglicky pro low-level)
- **PHP 8.3** strict_types, readonly properties
- **Snake_case na drátě** (`header_info`), camelCase v PHP a TS
- **Svelte 5 runes** (`$state`, `$derived`, `$effect`, `$props`)
- Před patchováním Svelte komponent **přečíst celý soubor** — patch_file
  vyžaduje přesné whitespace
