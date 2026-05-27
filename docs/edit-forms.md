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
                {"state": 40, "actionName": "V pořádku", "stateStyle": "done", "close_form": true}
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

**Poznámka:** Všechny klíče jsou snake_case — `full_size`, `title_new`, `doc_states`, `read_only`, `close_form`, `foreign_key`, `form_id`, `input_type`, `table_id`, `component_name`.

| Pole | Typ | Popis |
|------|-----|-------|
| `table` | string | DB název tabulky |
| `title` | string | Nadpis pro editaci existujícího záznamu |
| `title_new` | string | Nadpis pro nový záznam |
| `full_size` | bool | true = velký modal (1200×900px) pro hlavní entity, false = malý modal (960px, výška dle obsahu) pro sub-záznamy |
| `tabs` | Tab[] | Seznam tabů (min. 1) |
| `doc_states` | DocStatesInfo \| null | Info o stavech; přítomno i pro nový záznam (výchozí stav 10) |

### Tab — tři typy

Každý tab má `type`: `"fields"` (výchozí), `"subtable"`, nebo `"attachments"`.

#### `type: "fields"` — formulářová pole v sekcích a sloupcích

```json
{
    "id": "basic",
    "label": "Základní údaje",
    "type": "fields",
    "sections": [
        {
            "title": null,
            "columns": [
                {"elements": [
                    {"type": "input", "column": "person_id", "label": "ID", "required": true}
                ]}
            ]
        },
        {
            "title": "Identifikace firmy",
            "columns": [
                {"elements": [{"type": "input", "column": "company_id", "label": "IČO"}]},
                {"elements": [{"type": "input", "column": "tax_id", "label": "DIČ"}]}
            ]
        }
    ]
}
```

- **Sekce** je vizuální karta s pozadím. Volitelný `title` (zobrazí se jako malý nadpis nahoře). `hidden: true` celou sekci skryje.
- **Sloupce** uvnitř sekce jsou vertikální dráhy (1 a více). Šířka labelu se v rámci jednoho sloupce automaticky synchronizuje — CSS Grid `max-content 1fr`.
- **Elementy** žijí ve sloupcích, ne přímo v tabu. Element nemá `cols`; jeho šířka vyplývá z toho, ve kterém sloupci je.

#### `type: "subtable"` — vlastní záložka pro child tabulku

```json
{
    "id": "contacts",
    "label": "Kontakty",
    "type": "subtable",
    "subtable": {
        "table": "base_persons_contacts",
        "foreign_key": "person",
        "form_id": "base.persons.contacts"
    }
}
```

Frontend vykreslí tabulku řádků s toolbarem (Přidat / Upravit / Smazat). Sub-záznamy se otevírají v dalším modalu.

#### `type: "attachments"` — záložka s přílohami

```json
{
    "id": "attachments",
    "label": "Přílohy",
    "type": "attachments",
    "table_id": 110
}
```

Renderuje `AttachmentPanel` napojený na `core_attachments` filtrované podle `table_id` a `recordId` (= parentId).

Formulář má vždy alespoň jeden tab. Je-li tab jen jeden, tab bar se nezobrazí.

---

## 4. Elementy formuláře

Element žije uvnitř sloupce (`section.columns[i].elements[]`). Sám si nediktuje šířku — tu určuje sloupec.

Povolené typy: `input`, `select`, `separator`, `inline`, `html`, `component`.

### 4.1 `input`

```json
{
    "type": "input",
    "column": "full_name",
    "label": "Celý název",
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
| `required` | false | Hvězdička u labelu |
| `hidden` | false | Skryto (`display: none`), pole zůstává v DOM |
| `read_only` | false | Disabled input |
| `triggers` | null | `"reload"` = při změně spustit recalculate |
| `input_type` | derived | Typ UI komponenty |

**Checkbox je výjimka:** vykresluje se přes obě grid kolony (label + input) jako `<label><Checkbox/></label>`, kde text je popis vedle boxu. Externí label se v gridu nevykresluje.

### 4.2 `select`

```json
{
    "type": "select",
    "column": "person_type",
    "label": "Typ osoby",
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

Horizontální linka s volitelným textem. Pokrývá obě grid kolony (label + input). `hidden` se nastavuje automaticky v `TabBuilder::build()` pokud jsou všechny elementy za separátorem **v daném sloupci** skryté (`autoHideSeparators` — operuje per-column).

### 4.4 `inline`

```json
{
    "type": "inline",
    "elements": [
        {"type": "input", "column": "date_tax", "label": "DUZP", "input_type": "date"},
        {"type": "input", "column": "date_tax_duty", "label": "DPPD", "input_type": "date"}
    ]
}
```

Více polí v jedné řádce. Label prvního pole slouží jako „velký" label řádky (vlevo, v label dráze gridu). Ostatní pole mají vlastní mini-label vedle inputu. Uvnitř `inline.elements` jsou povoleny pouze `input` a `select`.

### 4.5 `html`

```json
{ "type": "html", "content": "<p>Poznámka</p>" }
```

Vlastní HTML uvnitř sloupce; rendruje se přes obě kolony.

### 4.6 `component`

```json
{ "type": "component", "component_name": "recapitulation" }
```

Pojmenovaná Svelte komponenta (např. VAT rekapitulace dokladu). Rendruje se přes obě kolony.

### 4.7 `lookup`

```json
{
    "type": "lookup",
    "column": "partner",
    "label": "Partner",
    "placeholder": "Hledat partnera…",
    "lookup": {
        "table": "base_persons_persons",
        "filter": null
    }
}
```

Inline combobox pro FK na velkou tabulku (Osoby, Adresy, Položky…). Klient si průběžně dohledává záznamy přes endpoint `GET /_ui/lookup/{table}/search`. Server pre-resolvuje vybrané hodnoty do `dataResolved` v response — žádný extra fetch při otevření formuláře.

Detailně viz [kapitolu 22](#22-lookup-pole). Pravidla:

- `lookup` element **nemůže** být uvnitř `inline` skupiny.
- `select` ponechte pro enumy a malé cfgItem-based číselníky; `lookup` je pro velké tabulky se search-driven UX.

### Zaniklé typy

`group` a `subtable` (jako element uvnitř tabu) byly v novém systému odstraněny. `subtable` je vždy vlastní tab (`type: "subtable"`); pro grupování polí se používají sekce nebo `inline`.

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

Formuláře se vykreslují ve dvou vrstvách CSS Gridu:

### Sekce → sloupce

`FormSection` vytvoří horizontální grid podle počtu sloupců (`section.columns.length`). Vlevo i vpravo stejně široké:

```css
.shpd-form-section__columns {
  display: grid;
  grid-template-columns: repeat(var(--shpd-form-section-cols), 1fr);
  gap: var(--shpd-space-xl);
}
```

Na úzkém viewportu (<700px) se sloupce lámou pod sebe (`grid-template-columns: 1fr`).

### Sloupec → label/input track

`FormColumn` má vlastní dvouwidth grid: `max-content 1fr`. To znamená, že **všechny labely v daném sloupci jsou stejně široké** (podle nejdelšího), inputy zaberou zbytek. `FormFieldRow` emituje DVA sourozence (`<label>` a `<div>`) přímo do tohoto gridu, aby labely sdílely jednu dráhu.

```css
.shpd-form-column {
  display: grid;
  grid-template-columns: max-content 1fr;
  column-gap: var(--shpd-space-md);
  row-gap: var(--shpd-space-sm);
  align-items: baseline;
}
```

Šířka labelu je **per-sloupec** — dva vedlejší sloupce v jedné sekci mohou mít různě široké labely. To je záměr; pokud by bylo třeba synchronizovat napříč sloupci, musel by se použít CSS subgrid.

### Full-span elementy

`separator`, `html`, `component` a `checkbox` se rendují přes obě grid kolony (`grid-column: 1 / -1`). `inline` má vlastní label + flex container, takže do gridu vchází jako dvě běžné kolony.

### Vizuál sekce

`FormSection` je „karta" s vlastním pozadím a jemnou hranou:

```css
.shpd-form-section {
  background: var(--shpd-color-bg-secondary);
  border: 1px solid var(--shpd-color-border-subtle);
  border-radius: var(--shpd-radius-md);
  padding: var(--shpd-space-md) var(--shpd-space-lg);
}
```

Volitelný `title` se vykreslí jako malý uppercase nadpis vlevo nahoře sekce.

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

Server vrátí při chybě validace `VALIDATION_ERROR` s polem `details[]`. Každá
položka má `{field, code, message}` (wire formát je snake_case; `field` mapuje
`ValidationError::column` z backendu):

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed",
    "details": [
      {"field": "partner",          "code": "required",              "message": "Partner je povinný"},
      {"field": "vat_registration", "code": "required",              "message": "Registrace DPH je povinná"},
      {"field": "partner_bank",     "code": "partner_bank_required", "message": "Bankovní spojení dodavatele…"},
      {"field": "rows",             "code": "no_rows",               "message": "Doklad musí mít alespoň jeden řádek"},
      {"field": "_form",            "code": "no_own_company",        "message": "Není nastavena vlastní firma…"}
    ]
  }
}
```

### Kontrakt `field`

| Hodnota `field` | Význam | UI chování |
|-----------------|--------|------------|
| Konkrétní `column` formuláře | Field-level chyba | error vedle inputu, tabová tečka, řádek v banneru s prefixem labelu pole |
| `field` = id nějakého tabu (typicky subtable, např. `rows` → tab „Řádky") | Tab-level chyba | banner + tabová tečka na tom tabu + `switchToErrorTab` na něj přeskočí |
| `_form` (konstanta `ValidationError::FIELD_FORM`) | Form-level chyba | jen banner, holá hláška |
| Cokoli jiného (neznámý sloupec, prázdný string) | Fallback na form-level | jako `_form` — jen banner |

Frontend rozlišuje field-level a form-level přes `buildElementMap()`: pokud
`field` odpovídá nějakému sloupci ve formuláři, je field-level; jinak putuje
do `formErrors`. Tím je robustní — backend může používat `_form`, `rows` nebo
cokoli jiného a banner ho odbaví. Z `formErrors` se navíc vyzobnou ty, jejichž
`field` odpovídá id tabu (`errorTabIds`), a aktivují tabovou tečku / přeskok na
ten tab — tak `rows` ukáže na subtable tab „Řádky", aniž by `rows` byl sloupec.
Nové form-level validace by měly používat kanonický marker
`ValidationError::FIELD_FORM`.

### Zobrazení (FormEditor)

Sdílený helper `extractValidationErrors(details)` rozdělí `details[]` na
`fieldErrors` (mapa column → hláška) a `formErrors` (seznam `{message, code}`).
Stejný helper (`applyValidationErrors`) používají **všechny** save/transition
větve — `handleSave`, `handleTransition` pro nový záznam i **oba PUTy** pro
existující záznam (přechod stavu naostro je až druhý PUT s `{docState}`).

- **Banner nad tabbarem** (`__validation-banner`) se ukáže, když je neprázdné
  `fieldErrors` nebo `formErrors`. Obsahuje nadpis (`form.validation.bannerTitle`,
  neutrální „Formulář obsahuje chyby:") a seznam: form-level chyby holé,
  field-level s prefixem labelu pole („Partner: Partner je povinný").
- **Field-level** chyby se navíc zobrazují vedle inputu a aktivují **tabovou
  tečku**; je-li chybné pole na neaktivním tabu, `switchToErrorTab` přepne tab.
- **Tab-level** chyby (form-level chyba, jejíž `field` = id tabu, např. `rows`)
  také aktivují tabovou tečku a přeskok na ten tab. Čistě form-level chyby
  (`_form`, neznámý field) tabové tečky neaktivují — od toho je banner.
- Oba state se vyčistí na začátku každého save/transition pokusu
  (`clearValidationErrors`); po úspěšném save zmizí přirozeně (reload formuláře).

### Sanitizace dat před odesláním

`FormEditor` provádí `sanitizeFormData()` před každým odesláním:
- `select` s numerickými options → převede string na number (HTML `<select>` vždy vrací string)
- `input_type: date/number/datetime...` s prázdnou hodnotou → převede `""` na `null`

---

## 9. fullSize flag — velikost modalu

`FormDialog.svelte` vždy renderuje formulář v Modal komponentě (centrovaný popup nad tmavým overlayem). `full_size` určuje pouze **velikost** modalu:

- `true` → velký modal: šířka `1200px`, výška `min(900px, 90vh)`. Pro hlavní entity (Osoby, Faktury…).
- `false` → malý modal: šířka `960px`, výška dle obsahu (max `90vh`). Pro sub-záznamy (Kontakt, Adresa, Bankovní účet…).

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

### Vrstvení modalů (Esc handling a depth shrink)

`Modal.svelte` používá module-level stack otevřených modalů. Slouží dvěma účelům:

**Esc handling** — Esc handler reaguje pouze na modal na vrcholu stacku. Bez tohoto by Esc v subdialogu Kontaktu zavřel současně Kontakt i nadřazenou Osobu (oba modaly poslouchají window keydown). Klik na overlay tento problém nemá — overlay každého modalu zachytí jen kliky na vlastní plochu. Tlačítko `×` je per-modal element. Esc je ale globální event, proto vyžaduje stack.

**Depth-based shrink** — každý modal si při `pushModal()` zjistí svoji hloubku ve stacku (0 = kořenový, 1 = vnořený, atd.). Podle hloubky se `cardStyle` zmenší o 30 px na každé straně (60 px celkem na šířku i výšku). Vnořený modal je tak vycentrovaný a všechny strany rodičovského modalu rovnoměrně vyčnívají — uživatel vidí hierarchii. Funguje pro libovolnou hloubku vnoření (např. Doklad → Řádek → Položka = depth 2, položka modal je o 120 px užší/nižší než doklad).

Mechanismus je generický na úrovni `Modal.svelte` — žádný kontext o tom, kdo je rodič/dítě. Funguje pro všechny vnořené modaly (FormSubTable child rows, LookupInput edit/create dialog, budoucí scenáře).

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

    protected function tab(string $id, string $label, ?string $icon = null): TabBuilder;
    protected function subtableTab(
        string $id, string $label,
        string $table, string $foreignKey,
        ?string $formId = null, ?string $sort = null, ?string $icon = null,
    ): FormTab;
    protected function attachmentsTab(string $id = 'attachments', string $label = 'Přílohy'): FormTab;
}
```

`TableForm` instance vyrábí `FormRegistry::createForm($table, $data, $db, $config)` — pro polymorfní tabulky (`docs_core_heads` přes `doc_type`) `$data` rozhodne o konkrétní subclass. Detaily viz [kapitola 23](#23-polymorfní-dispatch-formulářů-přes-typecolumn). Per-typ rodina formulářů typicky tvoří abstract base (`DocsHeadsFormBase`) se společnou logikou + tenké subclassy, které přepisují virtuální `getFormTitle()` / `getNewFormTitle()` (a do budoucna jednotlivé `buildXxxTab()` metody).

### Auto-label z TableDefinition

`TableForm` dostane `TableDefinition` přes `setTableDef()` před voláním `buildFormDefinition`. Helper `tab()` sestaví mapu `column_id => name` a předá ji `TabBuilder`. Element factory metody pak doplní `label` automaticky z této mapy pokud není zadán explicitně.

### TabBuilder API — scope management

Builder má **třípatrový stavový stroj**: `tab → section → col → [inline] → elements`. Volání musí být v pořadí; mimo otevřený scope vyhodí `LogicException`. `build()` automaticky uzavře otevřené scopy.

```php
$tab = $this->tab('basic', 'Základní údaje')
    ->section()                              // otevře sekci bez titulku
        ->col()                              // otevře první sloupec
            ->input('person_id', required: true)
            ->select('person_type', options: $opts, triggers: 'reload', required: true)
            ->input('full_name', required: $isCompany, readOnly: $isPerson)
    ->section('Identifikace firmy')          // další sekce s titulkem
        ->col()                              // levý sloupec
            ->input('company_id')
            ->input('tax_id')
        ->col()                              // pravý sloupec
            ->input('vat_id')
            ->input('court_registration')
    ->section('Termíny')
        ->col()
            ->inline()                       // víc polí v řádce
                ->date('date_tax', label: 'DUZP')
                ->date('date_tax_duty', label: 'DPPD')
            ->endInline()
    ->build();
```

`input()` je generická a přijímá `inputType` (text varianty `null/text/email/tel/url/password`); pro ostatní DB typy jsou dedikované metody — sebedokumentující a typově bezpečné.

| Metoda | `inputType` | DB typ |
|--------|-------------|--------|
| `input` | text varianty | `char`, `varchar` |
| `textarea` | `textarea` | `text`, `longtext` |
| `date` | `date` | `date` |
| `datetime` | `datetime` | `datetime` |
| `time` | `time` | `time` |
| `number` | `number` | `int`/`bigint`/`numeric`/`float` |
| `checkbox` | `checkbox` | `boolean` |
| `select` | — | `enumInt`, `enumString` |

```php
// Element factory metody (musí být uvnitř otevřeného col())
$col->input(string $column, ?string $label = null, bool $required = false,
    ?string $triggers = null, bool $readOnly = false, bool $hidden = false,
    ?string $placeholder = null, ?string $hint = null, ?string $inputType = null): static;

$col->textarea(string $column, ?string $label = null, ...): static;
$col->date($column, ...);  $col->datetime($column, ...);  $col->time($column, ...);
$col->number($column, ...);  $col->checkbox($column, ...);

$col->select(string $column, ?string $label = null, ?array $options = null,
    ?string $triggers = null, bool $required = false, ...): static;

$col->separator(?string $label = null, bool $hidden = false): static;
$col->html(string $content): static;
$col->component(string $name): static;

// Inline
$col->inline(): static;        // otevři inline; následné input()/select() jdou do něj
$col->endInline(): static;     // ukonči
$col->inlineFields(string ...$columns): static;  // shortcut: inline + N×input

// Závěr
$tab->build(): FormTab;        // auto-close inline → col → section
```

### Subtable a attachments taby

Tyto taby se nepostavují přes builder, ale přes helpery na `TableForm`:

```php
$contacts = $this->subtableTab('contacts', 'Kontakty',
    'base_persons_contacts', 'person', 'base.persons.contacts');

$attachments = $this->attachmentsTab();   // bere tableId z aktuální TableDefinition
```

### Auto-hide separátorů (per-column)

`autoHideSeparators` se spouští v `build()` per sloupec: separátor je automaticky skryt, pokud jsou všechny elementy za ním v daném sloupci skryté (do dalšího separátoru). Vývojář může `hidden: true` na separátoru zapnout explicitně, ale ručně to není potřeba — typický conditional pattern (skrytí celé sekce závisí na `person_type`) funguje automaticky.

---

## 12. Deklarativní JSONC definice

Pro jednoduché formuláře bez business logiky.

**Umístění:** `modules/{skupina}/{modul}/forms/{table}.jsonc`

JSONC source používá **camelCase** klíče (`titleNew`, `fullSize`, `readOnly`, `inputType`, `tableId`, `foreignKey`, `formId`). Loader je mapuje na snake_case wire formát při serializaci.

```jsonc
{
    "title": "Kontakt",
    "titleNew": "Nový kontakt",
    "fullSize": false,
    "tabs": [
        {
            "id": "basic",
            "label": "Kontakt",
            "sections": [
                {
                    "title": null,
                    "columns": [
                        {
                            "elements": [
                                {"type": "input", "column": "name", "required": true},
                                {"type": "input", "column": "email", "inputType": "email"},
                                {"type": "input", "column": "phone", "inputType": "tel"},
                                {"type": "separator"},
                                {"type": "input", "column": "valid_from", "inputType": "date"},
                                {"type": "input", "column": "valid_to", "inputType": "date"}
                            ]
                        }
                    ]
                }
            ]
        }
    ]
}
```

Labely a typy inputů se doplní z TableDefinition pokud chybí. `options` u `select` se auto-resolují z `cfgItem` sloupce.

### Taby s `type: subtable` / `attachments` v JSONC

```jsonc
{
    "id": "contacts",
    "label": "Kontakty",
    "type": "subtable",
    "subtable": {
        "table": "base_persons_contacts",
        "foreignKey": "person",
        "formId": "base.persons.contacts"
    }
}
```

```jsonc
{ "id": "attachments", "label": "Přílohy", "type": "attachments", "tableId": 110 }
```

### Vícejazyčnost v JSONC

`title`, `titleNew`, `tabs[].label` a inline `label` u `separator` elementů podporují jazykové varianty `:cs` / `:en`. `JsoncFormLoader::load()` aplikuje `ConfigLocalizer::localize($data, $language)` rekurzivně, takže `field:lang` varianty se redukují na holé `field` podle požadovaného jazyka. Holé pole (bez `:lang` suffixu) je povinný fallback.

```jsonc
{
    "title": "Kontakt",
    "title:cs": "Kontakt",
    "title:en": "Contact",
    "tabs": [
        {
            "id": "basic",
            "label": "Kontakt",
            "label:cs": "Kontakt",
            "label:en": "Contact",
            "sections": [
                {"columns": [{"elements": [
                    {"type": "input", "column": "name", "required": true},
                    {"type": "separator", "label": "Adresa", "label:cs": "Adresa", "label:en": "Address"}
                ]}]}
            ]
        }
    ]
}
```

`AutoFormBuilder` (fallback pro tabulky bez vlastního `forms/{table}.jsonc`) generuje pro každou skupinu sloupců jeden tab s jednou sekcí, jedním sloupcem a všemi poli. Label syntetického „General" tabu se čte z cfgItem `core.system.formDefaults.generalTabLabel.name`.

### Detekce starého formátu

`JsoncFormLoader` aktivně odmítá legacy konstrukce a vyhodí `RuntimeException` s odkazem na konkrétní místo:

- `tab.elements[]` přímo (bez `sections`)
- `element.cols` (šířka teď určuje sloupec sekce)
- `element.type: "group"` (zrušeno; použij sekce nebo inline)
- `element.type: "subtable"` (subtable je teď vlastní tab)

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

Pro polymorfní tabulky (jeden physický řádek může reprezentovat víc logických typů, typicky `docs_core_heads` s `doc_type`) místo prostého `{table, class}` použijte zápis `{table, typeColumn, classes, defaultClass}`. Detailně viz [kapitola 23](#23-polymorfní-dispatch-formulářů-přes-typecolumn).

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
| `FormTab.svelte` | Jeden tab — vykreslí sekce / subtable / attachments podle `tab.type` |
| `FormSection.svelte` | Karta s pozadím a volitelným titulkem; horizontální grid pro N sloupců |
| `FormColumn.svelte` | Sloupec se sdílenou auto-šířkou labelů (`max-content 1fr`) |
| `FormFieldRow.svelte` | Wrapper jedné label+input dvojice — emituje DVA sourozence do FormColumn gridu |
| `FormInline.svelte` | Inline skupina (víc polí v jedné řádce); první pole použije label řádky, ostatní mají mini-labely |
| `FormElement.svelte` | Renderer elementu — switch podle `type` + delegace na UI komponenty |
| `FormSubTable.svelte` | Editor child tabulky s CRUD (uvnitř subtable tabu) |
| `FormStateBar.svelte` | Spodní toolbar: Uložit + přechodová tlačítka |
| `FormStateBadge.svelte` | Badge stavu v záhlaví Modalu |

### UI komponenty (`components/ui/`)

`Input.svelte`, `TextArea.svelte`, `NumberInput.svelte`, `DateInput.svelte`, `Select.svelte` jsou „bezlabelové" — renderují pouze input a error hlášku. Label dodá obalující `FormFieldRow` / `FormInline`. Komponenty mají prop `id`, který se navazuje na `<label for>`.

`Checkbox.svelte` je výjimka: jeho interní `<label>` slouží jako UX text vedle boxu (např. „Plátce DPH"), takže prop `label` zůstává.

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
| `FormRegistry` | Registr PHP tříd formulářů; podporuje per-table polymorfismus přes `typeColumn` + `classes` + `defaultClass` (`createForm($table, $data, ...)`) — viz kap. 23 |
| `FormController` | HTTP controller; volá `createForm($table, $data, ...)` ve všech třech metodách (`resolveFormDefinition`, `recalculate`, `enrichHeaderInfo`) |
| `AutoFormBuilder` | Generuje FormDefinition z TableDefinition |
| `JsoncFormLoader` | Načítá JSONC formy |
| `RecalculateResult` | Výsledek recalculate |

### `src/Api/`
| Soubor | Popis |
|--------|-------|
| `FormLoader.php` | Načte FormRegistry z modulů; `mergeForms()` slévá per-table registrace přes moduly (paralela `DocumentLoader::mergeDocumentClasses`) |
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

### Reaktivní `$effect` a leaky dependencies přes async helpery

Svelte 5 `$effect` sleduje reaktivní reads v synchronní části svého těla — včetně readů uvnitř volných funkcí (až do prvního `await`). Snadno to vede ke skrytým závislostem, které nečekaně spouštějí re-runy efektu.

Konkrétním případem byl reload po Uložit ve `FormEditor.svelte`. Původní efekt:

```js
$effect(() => {
  const tbl = table;
  const id = recordId;
  currentId = id;
  loadForm(tbl, id);   // čte `defaultData` před prvním await
});
```

`loadForm` synchronně kontroluje `if (id == null && defaultData && Object.keys(defaultData).length > 0)` před `await get(path)`. Pro nový záznam (`id == null`) se `defaultData` přečte — a tím se stane sledovaným depem efektu. Po Uložit rodič (Viewer) typicky resetuje `formDefaultData = {}` (nová object reference), efekt se znovu spustí, přepíše `currentId` zpět na `recordId` (stále `null`) a spustí paralelní `loadForm(table, null)`. Ten dorazí jako poslední a přepisuje data čerstvě uloženého záznamu prázdným formulářem.

U existujících záznamů bug nenastane: `id == null` je `false`, AND short-circuit přes `id == null` `defaultData` v podmínce vůbec nečte — dep se nezaloží.

Fix — `untrack` ohraničí, co se nemá trackovat:

```js
import { untrack } from 'svelte';

$effect(() => {
  const tbl = table;
  const id = recordId;
  // Reload jen na změnu (table, recordId). Reads uvnitř loadForm
  // (defaultData prop) se nesmí stát skrytými dependencies.
  untrack(() => {
    currentId = id;
    loadForm(tbl, id);
  });
});
```

Obecné pravidlo: pokud `$effect` volá async helper, který před prvním `await` čte další reaktivní stav neuvedený na dependency listu efektu, obal volání do `untrack(() => ...)`. Nebo helper přestavte tak, aby reaktivní reads byly až za prvním `await` (anti-pattern, lepší je explicitní `untrack`).

Současně — `Viewer.handleFormSaved` zase nesmí resetovat `formTable` / `formDefaultData` při každém save, formulář může zůstat otevřený. Reset patří do `handleFormClose`.

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

---

## 20. Historie / Migrace

Layout systém byl v PR „new-forms-01" kompletně přepracován:

- **Pryč:** `cols: 1..4` na elementu, `type: "group"`, `type: "subtable"` jako element uvnitř tabu, label uvnitř UI komponent.
- **Přibylo:** `FormSection`, `FormColumn` jako explicitní vrstvy mezi tabem a elementy. `FormFieldRow` a `FormInline` pro label-vně vykreslování. Subtable a attachments jsou vlastní typy tabu.
- **Vizuál:** Sekce mají kartové pozadí (`--shpd-color-bg-secondary`) a jemnou hranu (`--shpd-color-border-subtle`). Labely vlevo s auto-šířkou v rámci sloupce (CSS Grid `max-content 1fr`).
- **Builder:** `TabBuilder` má scope management `section() → col() → elementy`. Bez `addInput`/`addSelect` prefixu; metody se jmenují podle widgetu (`input`, `select`, `date`, `textarea`, `checkbox`, …).
- **JSONC:** stará struktura `tabs[].elements[]` s `cols` čísly je odmítnuta `JsoncFormLoader`em s konkrétní hláškou.

Žádná backward compatibility — staré formy bylo nutné mechanicky portovat.

---

## 21. Hlavička formuláře (HeaderInfo)

Editační modal má v hlavičce dva řádky: hlavní titulek + volitelný strukturovaný „subtitle" s identifikačními údaji o záznamu.

```
┌───────────────────────────────────────────────────────────────────────┐
│ Beta Software, a.s.                                  [Koncept]    [×] │  ← title z header_info
│ IČO 68253848 · Kód osoby TEST-0098                                    │  ← info položky spojené " · "
├───────────────────────────────────────────────────────────────────────┤
│ [Základní údaje] [Kontaktní údaje] [Kontakty] [Adresy] …              │
```

### Kdy se zobrazuje

- **Existující záznam** (`GET /meta/{id}`) — pokud `TableForm::buildHeaderInfo()` vrátí non-null
- **Nový záznam** (`GET /meta`) — `header_info: null`, modal zobrazí jen `title_new`
- **Recalculate** (`POST /recalculate`) — `header_info: null`, klient ignoruje (hlavička neodráží neuložené změny)
- **Save** — server nevrací `header_info` přímo v save response. Klient po úspěšném save volá `loadForm()` (přes meta endpoint), čímž se header aktualizuje na novou uloženou hodnotu

### Struktura

`FormHeaderInfo` (`src/Core/Form/FormHeaderInfo.php`):

```php
final class FormHeaderInfo
{
    public function __construct(
        public readonly string $title,
        /** @var list<array{label: string, value: string}> */
        public readonly array $info = [],
    ) {}
}
```

Wire formát (`header_info` klíč ve `FormDefinition.toArray()`, vždy přítomný — `null` nebo objekt):

```json
{
  "header_info": {
    "title": "Beta Software, a.s.",
    "info": [
      { "label": "IČO",       "value": "68253848" },
      { "label": "Kód osoby", "value": "TEST-0098" }
    ]
  }
}
```

### Override v PHP

Subclass `TableForm` přepíše virtuální metodu `buildHeaderInfo()`:

```php
public function buildHeaderInfo(array $data): ?FormHeaderInfo
{
    $fullName = trim((string) ($data['full_name'] ?? ''));
    if ($fullName === '') {
        return null;
    }

    $info = [];
    $companyId = trim((string) ($data['company_id'] ?? ''));
    if ($companyId !== '') {
        $info[] = ['label' => 'IČO', 'value' => $companyId];
    }
    $personId = trim((string) ($data['person_id'] ?? ''));
    if ($personId !== '') {
        $info[] = ['label' => 'Kód osoby', 'value' => $personId];
    }

    return new FormHeaderInfo(title: $fullName, info: $info);
}
```

Pravidla pro implementaci:

- **Vrátit `null`**, pokud nemáme co zobrazit (typicky prázdný hlavní identifikátor).
- **Vynechat položky `info`** s prázdnou hodnotou — pole `info` může být prázdné, title pak stojí samostatně.
- **Data jsou z DB** (uložená), ne živá z formuláře — metoda dostává `array $data` z `fetchRow`.
- Lokalizace labelů (`IČO`, `Kód osoby`, …) zatím napevno v jazyce modulu; i18n vrstva pro PHP texty se řeší v navazujících taskech.

Default implementace v `TableForm` vrací `null` — JSONC/Auto formuláře a všechny moduly, které neoverrideují, hlavičku nezobrazí.

### Frontend render

- `Modal.svelte` přijímá volitelný `subtitle: Snippet` prop. Renderuje druhý řádek pod titulem ve menším fontu a sekundární barvě.
- `FormEditor.svelte` drží `savedHeaderInfo` state, aktualizuje ho jen v `loadForm` (NE v `handleTrigger`/recalculate), a propaguje přes `onFormLoaded` callback.
- `FormDialog.svelte` z `headerInfo.info` skládá řádek `"Label1 hodnota1 · Label2 hodnota2 · …"` a předává Modalu přes `{#snippet subtitle()}`.
- Title v Modalu se rozhoduje: `headerInfo.title || formDef.title || t('common.loading')` — strukturovaný title má přednost před generickým „Osoba" / „Faktura" apod.

### Proč ne živá data

Hlavička reflektuje **uložený stav v DB**, ne dirty formData. Důvody:

- Recalculate může změnit `person_type` z Person na Company — title by „blikal" mezi „Jan Novák" a názvem firmy podle rozpracovaného formuláře.
- Uživatel může mít rozpracované špatné jméno; po Cancel by header lhal, že záznam má jiný titulek, než ve skutečnosti.
- Server-side `buildHeaderInfo` má jasná pravidla a vstupuje do něj jen schválně načtená data (`SELECT * WHERE id = ?`).

---

## 22. Lookup pole

Inline combobox pro FK na velké tabulky. Klient si průběžně dohledává záznamy přes serverový endpoint — žádné statické `options[]` v `FormDefinition`.

### Kdy použít `lookup` vs `select`

- **`select`** — enumy (`enumInt`/`enumString`), malé cfgItem-based číselníky (jednotky, sazby DPH, typy adres). Options se předají v `FormDefinition` jako pole `{value, label}`.
- **`lookup`** — velké tabulky (Osoby, Adresy, Bankovní účty, Položky…), kde nativní `<select>` nezvládá vyhledávání a stažení tisíců záznamů do payloadu by zpomalilo render.

### Wire formát elementu

```json
{
    "type": "lookup",
    "column": "partner",
    "label": "Partner",
    "placeholder": "Hledat partnera…",
    "required": false,
    "read_only": false,
    "hidden": false,
    "triggers": "reload",
    "lookup": {
        "table": "base_persons_persons",
        "filter": null
    }
}
```

Cascade variant (po vybrání partnera filtruje adresy podle něj):

```json
{
    "type": "lookup",
    "column": "partner_address",
    "lookup": {
        "table": "base_persons_addresses",
        "filter": {"person": 42}
    }
}
```

Pravidla:

- `lookup.table` — DB název cílové tabulky; musí být registrovaná v `LookupRegistry` (jinak endpoint vrátí 404 `LOOKUP_NOT_REGISTERED`).
- `lookup.filter` — server-zapečené páry `{column: scalar}`. Frontend je zkopíruje do query stringu volání jako `filter[col]=val`. Whitelist klíčů je v `TableLookup::getAllowedFilterKeys()`; neznámé klíče controller silently zahodí.
- `lookup` element nelze umístit do `inline` skupiny (validace v `FormElement` konstruktoru, inline povoluje jen `input`/`select`).
- Cascade přes `triggers: 'reload'` — po změně partnera proběhne `recalculate`, server rebuildne FormDefinition s novým filtrem v adresách/bance.

### Endpoint `/_ui/lookup/{table}/search`

```
GET /_ui/lookup/{table}/search?q={term}&limit={n}&filter[col]={val}
```

| Parametr | Default | Limity |
|----------|---------|--------|
| `q` | `""` | Prázdné = první stránka záznamů (browseable) |
| `limit` | 20 | Max 50 (víc se srazí na 50) |
| `filter[<col>]` | — | Whitelist přes `TableLookup::getAllowedFilterKeys()`; ostatní se ignorují |

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

- `items[].id` může být int nebo string (FK na enumString)
- `items[].secondary` může být `null` — frontend pak druhý řádek nevykreslí
- `total` je v MVP vždy `null` (klíč je zachován pro budoucí stránkování)

Chybové kódy: `LOOKUP_NOT_REGISTERED` (404), `TABLE_NOT_FOUND` (404), `BAD_REQUEST` (400 — neplatný limit/parametry), `METHOD_NOT_ALLOWED` (405).

### Endpoint `/_ui/lookup/{table}/resolve`

```
GET /_ui/lookup/{table}/resolve?ids=42,17,3
```

Vrátí display popis pro konkrétní ID. Klient ho typicky nevolá — server pre-resolvuje hodnoty v meta/save/recalculate response. Použití je v okrajových situacích (kdyby `dataResolved` z lokálního stavu zmizel).

Response stejný tvar jako search bez `total`. Neexistující ID se v `items[]` prostě vynechá.

### `dataResolved` v meta/save/recalculate response

`FormController` v každé z metod (`meta`, `save`, `recalculate`, state-transition) sestaví top-level klíč `dataResolved` paralelně k `data`:

```json
{
    "success": true,
    "data": {
        "formDefinition": { ... },
        "data": {
            "partner": 42,
            "partner_address": 17,
            "partner_bank": null
        },
        "dataResolved": {
            "partner":         {"id": 42, "primary": "Testování 999",  "secondary": "IČO 12345678"},
            "partner_address": {"id": 17, "primary": "Hlavní 12, Praha", "secondary": null}
        }
    }
}
```

- `dataResolved` je vždy přítomné (pro nový záznam je `{}`)
- Klíče jsou pouze ty `column` z lookup elementů, kde má `data[column]` ne-null hodnotu a kde resolve uspěl
- camelCase top-level (drží konzistenci s `formDefinition`); uvnitř `formDefinition.tabs[].sections[]` zůstává snake_case

### `TableLookup` třída

Konkrétní lookupy dědí z abstraktní `Shipard\Core\Form\Lookup\TableLookup`:

```php
abstract class TableLookup
{
    /** @return list<LookupItem> */
    abstract public function search(string $q, array $filter, int $limit): array;

    /** @return list<LookupItem> */
    abstract public function resolve(array $ids): array;

    /** @return list<string> Whitelist filter keys (default: žádné) */
    public function getAllowedFilterKeys(): array { return []; }
}
```

Setter trojicí ze základní třídy dostávají instanci `DataSourceConnection`, `?ConfigRuntime`, `?TableDefinition` (volá `LookupRegistry::create()`).

### Registrace v `module.jsonc`

```jsonc
"lookups": [
    {
        "table": "base_persons_persons",
        "class": "Shipard\\Module\\Base\\Persons\\PersonsLookup"
    },
    {
        "table": "base_persons_addresses",
        "class": "Shipard\\Module\\Base\\Persons\\AddressesLookup"
    }
]
```

`LookupLoader` (analogie `FormLoader`) projde všechny moduly a naplní `LookupRegistry` při bootu. Tabulka bez registrace → endpoint vrací 404 `LOOKUP_NOT_REGISTERED`.

### PHP builder API

```php
$this->tab('basic', 'Hlavička')
    ->section()->col()
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
    ->build();
```

Signatura `TabBuilder::lookup()`:

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
): static
```

### Deklarativní JSONC

```jsonc
{
    "type": "lookup",
    "column": "partner",
    "lookup": {
        "table": "base_persons_persons"
    }
}
```

Statický `filter` lze v JSONC zapsat taky (`"filter": {"col": "val"}`), ale typicky se filtry generují dynamicky v PHP `recalculate()` — viz `DocsHeadsForm` jako kanonický vzor.

### Cascade přes recalculate — destruktivní reset

Vzor v `DocsHeadsForm`:

1. Uživatel změní partnera v `partner` lookup poli (`triggers: 'reload'`).
2. Frontend pošle `POST /_ui/form/{table}/recalculate` s body `{changedColumn: 'partner', data: {...}}`.
3. Server v `DocsHeadsForm::recalculate()` při `changedColumn === 'partner'` **vždy vynuluje `partner_address` a `partner_bank`** (cascade reset) — dřív vybraná adresa/banka patřily bývalému partnerovi a uživatel je musí vybrat znovu z filtrovaného dropdownu nového partnera. Pokud je zadaný nový partner, dopočítá `due_date` z `payment_term_days` (jen pokud ještě není). Žádný auto-fill na hlavní adresu — výběr je vždy explicitní.
4. Server zavolá `buildFormDefinition` — nový FormDef má `partner_address` lookup s `filter: {person: newPartnerId}` (místo původního null) a placeholder „Vyberte adresu…“ místo „Nejdřív vyberte partnera“.
5. `FormController` sestaví `dataResolved` — chybějí klíče `partner_address`, `partner_bank` (data jsou null), je přítomný `partner`.
6. Frontend v `handleTrigger` **replace** `dataResolved` ze serverové response — staré displaye adresy/banky zmizejí, inputy se vyprázdní (`hasValue` je false).

Klíčem je, že backend `recalculate` u destruktivní cascade explicitně vynuluje závislá pole, a frontend `dataResolved` přepisuje replace strategy (ne merge). Cascade nepotřebuje žádný nový mechanismus — funguje přes existující recalculate flow.

### Cascade přes recalculate — propisující (položka → řádek)

Protipolný vzor v `DocRowsForm`. Změna `item` vždy přepisuje `description`, `unit_price`, `unit` z dat položky (`economy_items`) — platí pro výběr z dropdownu, create nové položky i edit existující (viz `edit_triggers` flag v další sekci). Sémantika: **položka = řádek**, kompletně. Ruční slevy se řeší přes samostatná pole `discount_pct` / `discount_amount`, nikoli přepisem `unit_price` ručně — ten by se při příští změně položky beztak přepisal.

### Frontend chování (`LookupInput.svelte`)

- **Render value (dropdown zavřený):** input zobrazí `resolved.primary`. `resolved.secondary` se uvnitř inputu **nezobrazuje** — sekundární řádek (IČO, Datum narození…) se ukazuje jen u položek v rozevréném dropdownu.
- **Klik / fokus:** otevře dropdown s prázdným `q` (první stránka záznamů). Pokud má hodnotu, do inputu se vyplnil `displayLabel` (vybrané jméno) a `inputEl.select()` v `queueMicrotask` text vyselectuje. Uživatel tak vidí, kdo je vybraný, a první stisk klávesy text přepíše (standardní combobox UX).
- **Psaní:** debounce 300 ms; každý fetch má token — starší odpovědi se zahodí (race protection).
- **Klávesnice:**
  - `↓`/`↑` navigace s wrap-around
  - `Enter` vybírá aktivní položku
  - `Escape` zavírá dropdown
  - `Tab` zavře dropdown a **nechá default chování proběhnout** (`preventDefault` se nevolá) — fokus přejde na další pole formuláře. Položky dropdownu mají `tabindex="-1"`, takže do nich Tab nemůže zabloudit.
  - `Backspace` na prázdném inputu při nastavené hodnotě clearuje.
- **`×` tlačítko:** clear (jen pokud `hasValue && !disabled`, `tabindex="-1"`).
- **Klik mimo:** zavírá dropdown.
- **Klik na položku:** `onmousedown` + `preventDefault` — výběr proběhne před tím, než input ztratí fokus.
- **Disabled state:** input readonly, klávesnice/klik neotevírají dropdown, žádný `×` button.
- **States v dropdownu:** loading (`Načítám…`), error (`Chyba načítání`), empty (`Žádné výsledky`), nebo seznam položek (primary tučný, secondary menším fontem).

### Drilldown ve form komponentách

`FormEditor` drží state `dataResolved` (map column → `{id, primary, secondary}`), propaguje přes `FormTab → FormSection → FormColumn → FormElement` k jednotlivým `LookupInput` instancím. Callback `onResolveChange(column, item)` při výběru / clear aktualizuje keš:

- **Load (meta response):** `dataResolved = res.data.dataResolved ?? {}` — kompletní nahrazení.
- **Recalculate response:** `dataResolved = res.data.dataResolved ?? {}` — **replace**, ne merge. Server vrací autoritativní obraz pro všechna lookup pole v aktuálním form-state; klíče chybějící v response znamenají, že dané pole je null (resp. lookup neresolvoval) a display popis musí v UI zmizet. Bez tohoto by cascade reset (změna partnera → vynulování `partner_address`) zůstal v inputu — hodnota null, ale starý `resolved.primary` v keši.
- **User select:** `dataResolved = { ...dataResolved, [column]: item }` — okamžitý update z odpovědi `LookupInput`.
- **Save response:** explicitně neaktualizuje, ale `handleSave` volá `loadForm(...)`, který načte fresh `dataResolved` ze serveru.

### Edit a Create přes lookup

Lookup pole podporuje inline **edit** vybrané hodnoty a **create** úplně nového záznamu — obojí přes vnořený `FormDialog` z `LookupInput.svelte`. Opt-in přes flagy v lookup definici.

**Wire flagy** (součást `lookup` objektu, snake_case na drátě, camelCase v JSONC/PHP builderu):

| Flag | Default | Co dělá |
|------|---------|--------|
| `edit_form` / `editForm` | `false` | Zapne ikonu tužky vedle `×` u vyplněného pole. Klik otevře `FormDialog` s `recordId = value`. |
| `create_form` / `createForm` | `false` | Zapne tlačítko „+ Vytvořit nový záznam“ v patce dropdownu. Klik otevře `FormDialog` bez `recordId`. |
| `edit_triggers` / `editTriggers` | `false` | Po úspěšném save v **edit** modalu volá `onchange?.()` v rodiči (triggers recalculate). Default vypnuto, viz sémantika níže. |

**PHP builder API:**

```php
->lookup('partner',
    table: 'base_persons_persons',
    placeholder: 'Hledat partnera…',
    triggers: 'reload',
    editForm: true,
    createForm: true,
    // editTriggers nezapínáme — edit partnera nemá měnit řádek
)

->lookup('item',
    table: 'economy_items',
    placeholder: 'Hledat položku…',
    triggers: 'reload',
    editForm: true,
    createForm: true,
    editTriggers: true,  // ← položka = řádek, edit propisuje cenu/popis/jednotku
)
```

**Sémantika edit vs create:**

Obě akce sdílí pipeline v `LookupInput.handleSubDialogSaved`:

1. Přečíst `newId` z `record.id ?? record.data.id`.
2. **Edit (value se nemění, jen detaily):** refetch `/_ui/lookup/{table}/resolve?ids={newId}` → aktualizovat `resolved` → `onResolveChange`. Pokud `lookup.edit_triggers === true`, ještě zavolat `onchange?.()` (= recalculate v rodiči).
3. **Create (nové ID):** `value = newId` → refetch resolve → `onResolveChange` → vždy `onchange?.()`. Mode se přepne na 'edit' a `subDialogRecordId` na `newId`, aby případný další save v té samé modal session nešel cestou create.

Klíčové pro vyhodnocení `edit_triggers`:

- **Bez flagu (Partner):** edit detailů partnera jen aktualizuje display popis v inputu. Recalculate v rodiči se nevolá, takže `DocsHeadsForm::recalculate('partner')` neběží → cascade reset adresy/banky neproběhne. Správně: uživatel editoval partnera, neměnil ho.
- **S flagem (Item):** edit položky triggerne recalculate v rodiči → `DocRowsForm::recalculate('item')` přepiše `description`, `unit_price`, `unit` z aktualizovaných dat položky. Správně: položka určuje řádek.

**LookupInput NEzavírá modal po `onSaved`.** Modal se zavře až přes `onClose` — to znamená transition s `closeForm: 1` (`FormEditor` zavolá `onClose({force: true})`), `×`, Esc nebo overlay click. Tj. po prostém **Uložit** nebo po **Opravit** (40 → 80 s `closeForm: 0`) zůstává vnořený modal otevřený — stejně jako u primárních formulářů otevřených z vieweru. `onSaved` callback běží na pozadí: re-resolvuje display popis a (pokud `edit_triggers`) triggerne recalculate.

**Vnořený `FormDialog` v `LookupInput`:** přímý import (stejný vzor jako `FormSubTable.svelte`). Cyklická závislost `FormDialog → FormEditor → … → LookupInput → FormDialog` Vite zvládá, protože komponenta se instantuje až runtime. Modal-stack depth shrink v `Modal.svelte` (viz kap. 9) automaticky vykreslí vnořený modal o 30 px užší/nižší na každé straně, takže rodič vykřukuje a uživatel vidí hierarchii.

---

## 23. Polymorfní dispatch formulářů přes `typeColumn`

Pro tabulky, kde jeden physický řádek může reprezentovat víc logických typů (typicky `docs_core_heads` s `doc_type`), `FormRegistry` podporuje per-table polymorfismus — jedna tabulka, N PHP tříd, dispatch podle hodnoty diskriminačního sloupce. Mechanismus zrcadlí `DocumentRegistry::getDocument()` 1:1 (typeColumn + `classes` map + `defaultClass`).

### Kdy použít

- **Polymorfní zápis `{table, typeColumn, classes, defaultClass}`** — tabulka má diskriminační sloupec, jednotlivé hodnoty mají různé chování formuláře (titulky, sekce, validace).
- **Prostý zápis `{table, class}`** — jeden typ, jedna třída. Zůstává plně podporovaný a doporučený pro většinu tabulek (Osoby, Položky, Číselné řady, …). Nemá smysl ho zbytečně rozkládat.

### Registrace v `module.jsonc`

Vzor pro per-typ rodinu nad `docs_core_heads`:

```jsonc
// docs.core — vlastní tabulky + default
"forms": [
    {
        "table": "docs_core_heads",
        "typeColumn": "doc_type",
        "defaultClass": "Shipard\\Module\\Docs\\Core\\DocsHeadsForm"
    }
]

// docs.invoicesOut — per-typ subclass
"forms": [
    {
        "table": "docs_core_heads",
        "typeColumn": "doc_type",
        "classes": {
            "invno": "Shipard\\Module\\Docs\\InvoicesOut\\IssuedInvoiceForm"
        }
    }
]

// docs.invoicesIn — per-typ subclass
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

### Dispatch pravidla

`FormRegistry::createForm($table, $data, $db, $config)` vrací konkrétní instanci `TableForm`:

- Pokud má registrace `typeColumn`: vyhodnotí `$data[$typeColumn]`, výsledek hledá v mapě `classes`. Pokud klíč neexistuje → fallback na `defaultClass`. Pokud neexistuje ani `defaultClass` → `null`.
- Pokud má registrace prostý `class`: vrátí instanci té třídy bez ohledu na `$data`.
- Tabulka bez registrace v `FormRegistry` → fallback na JSONC formulář (`forms/{table}.jsonc`) nebo `AutoFormBuilder` (viz kap. 12).

### `$data` pro nový záznam

Per-typ viewer poskytuje `getNewRecordDefaults()` (např. `{doc_type: 'invno'}`). `FormController` to spojí s column defaults a předá do `createForm($table, $data, ...)`. Dispatch tedy funguje i pro nový záznam otevřený z per-typ vieweru.

Pro nový záznam otevřený z **generického vieweru** (bez hintu `doc_type`) je `$data[$typeColumn]` prázdné → dispatch padá na `defaultClass`. ✓

### Slévání napříč moduly

`FormLoader::mergeForms()` (paralela `DocumentLoader::mergeDocumentClasses()`) slévá per-table:

- `typeColumn` musí být shodný (jinak `LogicException`)
- `classes` mapy se mergují; kolize klíčů s **různými** hodnotami → `LogicException`, **identická** hodnota → idempotentní průchod
- `defaultClass` first-wins (typicky ho registruje base modul, např. `docs.core`)
- `id` first-wins (pro `subtable.form_id` referenci)
- Prostý `class` se ignoruje, pokud cílová registrace má `typeColumn` (smíšený zápis nedává smysl)

### Hierarchie tříd — doporučený vzor

```
TableForm (abstract, core)
    └── DocsHeadsFormBase (abstract, docs.core)
            ├── DocsHeadsForm        (docs.core)        — defaultClass
            ├── IssuedInvoiceForm    (docs.invoicesOut) — invno
            └── ReceivedInvoiceForm  (docs.invoicesIn)  — invni
```

Společná logika žije v base třídě (build tabů, recalculate, options resolvery, HTML renderery). Subclassy přepisují jen tam, kde se chování má lišit — v MVP typicky `getFormTitle()` / `getNewFormTitle()`. Do budoucna budou rozšiřovat o per-typ `buildXxxTab()` metody (FVB-specifický splátkový kalendář, FPB schvalovací workflow, …).

```php
abstract class DocsHeadsFormBase extends TableForm
{
    protected function getFormTitle(): string    { return 'Doklad'; }
    protected function getNewFormTitle(): string { return 'Nový doklad'; }
    // ... shared build* / recalculate / resolve* metody jsou `protected`,
    // aby je subclassy mohly override-ovat
}

class IssuedInvoiceForm extends DocsHeadsFormBase
{
    protected function getFormTitle(): string    { return 'Faktura vydaná'; }
    protected function getNewFormTitle(): string { return 'Nová faktura vydaná'; }
}
```

### Vztah k `DocumentRegistry`

Formulářová polymorfizace zrcadlí `DocumentRegistry` 1:1. Když přidáváš nový typ dokladu (proforma faktura, dobropis, bankovní výpis, pokladní doklad, …), typicky přidáváš tři věci společně:

- `documentClasses` entry pro per-typ `Document` třídu (validace, beforeSave)
- `forms` entry pro per-typ `Form` třídu (UI overrides)
- `viewers` entry pro per-typ filtered viewer s `getNewRecordDefaults()`

Symetrie není povinná, ale pro nový typ dokladu je defaultní cesta.

### Invariant: `doc_type` per-záznam fixní po vzniku

`recalculate` v `FormController` předává `$data` do `createForm($table, $data, ...)`, ale `$data` obsahuje aktuální stav formuláře z requestu. Teoreticky by uživatel změnou `doc_type` za běhu mohl forcenout změnu `TableForm` mid-flight. V praxi je `doc_type` v UI **neměnitelné po vytvoření záznamu** (řídí ho výběr číselné řady při kliku „Přidat" v per-typ vieweru). Implicitní invariant: hodnota diskriminačního sloupce je per-záznam fixní.

### Backwards compat

Všechny existující registrace `{table, class}` (`PersonsForm`, `NumberSeriesForm`, `ItemsForm`, JSONC `DocRowsForm`, …) fungují beze změny. Polymorfní mechanismus se týká výhradně PHP `TableForm` subclassů — pro JSONC formuláře (`forms/{table}.jsonc`) typeColumn dispatch neexistuje (deklarativní JSONC nemá motiv per-typ varianty).

### Hook `buildExtraTabs()` — per-typ rozšíření tabů

`DocsHeadsFormBase::buildFormDefinition()` nabízí rozšiřující hook `buildExtraTabs(array $data, bool $isNew): array`, který subclassy mohou přepisovat a vracet extra taby — ty se přidají **na konec** formuláře, za Přílohy. Default v base třídě vrací prázdné pole.

Vzor použití — `ReceivedInvoiceForm` (FPB) přidává tab „Nastavení“ s poli, která se u přijatých faktur nastavují zřídka (registrace DPH, náš bankovní účet, domácí měna readOnly):

```php
class ReceivedInvoiceForm extends DocsHeadsFormBase
{
    protected function buildExtraTabs(array $data, bool $isNew): array
    {
        return [$this->buildSettingsTab($data)];
    }

    protected function buildSettingsTab(array $data): FormTab
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;
        $docCurrency = strtolower((string) ($data['doc_currency'] ?? 'czk'));

        return $this->tab('settings', 'Nastavení')
            ->section(title: 'DPH', hidden: !$hasVat)
                ->col()
                    ->select('vat_registration',
                        options: $this->resolveVatRegistrationOptions(),
                        triggers: 'reload',
                    )
            ->section(title: 'Bankovní spojení')
                ->col()
                    ->select('bank_account',
                        options: $this->resolveBankAccountOptions($docCurrency),
                    )
            ->section(title: 'Měna')
                ->col()
                    ->input('home_currency', readOnly: true)
            ->build();
    }
}
```

Dopořučení:

- Tab Nastavení je umístěn za Přílohami stejně jako u `PersonsForm` — udržuje konzistentní UX napříč aplikací.
- Hook má stejnou signaturu jako `buildHeaderTab()` (`array $data, bool $isNew`), použitelnou pro větvení podle stavu formuláře (např. skrýt sekce „DPH“ když `vat_mode === 0`).
- Pro extra **subtable** nebo **attachments** taby použij stejný hook — vrací se z něj `list<FormTab>`, který může obsahovat i `$this->subtableTab(...)` nebo `$this->attachmentsTab(...)`.
- `IssuedInvoiceForm` (FVB) a generický `DocsHeadsForm` hook nepřepisují — nemění default `[]`. Můžou ho zapnout kdykoli bez úpravy base třídy.
