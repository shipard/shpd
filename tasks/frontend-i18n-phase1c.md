# Backend i18n — Fáze 1C (lokalizace server-driven labels)

## Status / Cíl fáze

Po Fázi 1B zůstávají v UI tři kategorie textů, které **negeneruje
frontend**, ale server v PHP, a které proto nejdou přeložit přes `t()`
ve Svelte. Tato fáze je zajistí kompletně:

1. **Toolbar tlačítka ve vieweru** — `Add`, `Open`, `Reanalyze` apod.
   Generuje `TableViewer::getToolbarActions()`.
2. **Záložky a titulky editačních formulářů** — `Kontakt`, `Obecné`,
   `Nový kontakt`. Generuje `JsoncFormLoader` z jsonc + `AutoFormBuilder`
   pro tabulky bez vlastního forms.jsonc.
3. **Záložky detailu vieweru** — `Obsah`, `Přílohy`, `Analýzy`,
   `Originál`. Generují individuální Viewer třídy (např.
   `IncomingMessagesViewer`).

Souběžně se touto fází uklidí drobné nedodělky:

- 12 jsonc souborů bez `name:en` / `label:en` (forms, doc states,
  fiscalConfig).
- `DataSourceConfig::getDefaultLanguage()` — dokumentace ho slibuje,
  kód ho neimplementuje. Použít ho v `resolveLanguage()` jako fallback,
  když chybí `Accept-Language`.
- Mapping `error.code → překlad` na frontendu pro validační hlášky ze
  serveru.

Po dokončení této fáze je **přepínač jazyka kompletní** — neexistuje
hardcoded národní text v UI ani v server-driven obsahu.

## Návaznost

- **Fáze 1A** + **Fáze 1B** kompletní (`tasks/frontend-i18n-phase1a.md`,
  `tasks/frontend-i18n-phase1b.md`).
- Backend i18n infrastruktura (`ConfigLocalizer`,
  `LocalizedFieldResolver`, `JsoncParser`) je hotová a používá se na
  TableDefinition, ColumnDefinition, ModuleDefinition, viewer registry.
  Tato fáze ji **rozšiřuje** na další typy obsahu.
- 62 ze 74 jsonc souborů už má `name:cs` + `name:en`. Tato fáze doplní
  zbylých 12.

## Scope

### V rozsahu

#### Backend — lokalizace server-driven labels

- **`TableViewer::getToolbarActions()`** — refaktor tak, aby labels šly
  z lokalizovaných zdrojů (preferenčně z jsonc, ne hardcoded v PHP).
  Konkrétně:
  - Defaultní akce `create` / `edit` v `TableViewer` přesunout
    z hardcoded `'Add'` / `'Open'` na lokalizované klíče
    (viz *Implementační detaily* níže).
  - Konkrétní viewery (`PersonsViewer`, `IncomingMessagesViewer`)
    revidovat — jejich custom labels jako `'Reanalyze'` lokalizovat
    přes stejný mechanismus.
- **`JsoncFormLoader`** — load `title`, `titleNew`, `tabs[].label`
  z jsonc s aplikací `LocalizedFieldResolver`. Tedy přidat podporu
  `title:cs`, `title:en`, `titleNew:cs`, ..., `tabs[].label:cs`,
  `tabs[].label:en`.
- **`AutoFormBuilder`** — string `'Obecné'` přesunout na
  konfigurovatelný / lokalizovaný klíč. Nejjednodušší: přidat
  konfigurační položku `core.system.formDefaults` v jsonc s polem
  `generalTabLabel` se standardním `name:cs` / `name:en`. Builder ho
  čte přes `ConfigRuntime`.
- **Detail taby ve viewerech** — labels v `renderDetail()` v každé
  Viewer třídě nahradit za hodnoty z jsonc / configu, ne hardcoded
  v PHP.
- **Doplnění `name:en` / `label:en` ve 12 jsonc souborech**:
  ```
  modules/base/persons/forms/base_persons_addresses.jsonc
  modules/base/persons/forms/base_persons_bank_accounts.jsonc
  modules/base/persons/forms/base_persons_contacts.jsonc
  modules/core/system/config/docStatesArchive.jsonc
  modules/core/mail/config/docStatesIncoming.jsonc
  modules/economy/codebooks/config/fiscalConfig.jsonc
  modules/economy/codebooks/forms/economy_codebooks_fiscal_months.jsonc
  modules/economy/codebooks/forms/economy_codebooks_vat_periods.jsonc
  modules/economy/codebooks/forms/economy_codebooks_bank_accounts.jsonc
  modules/economy/codebooks/forms/economy_codebooks_cash_desks.jsonc
  modules/economy/codebooks/forms/economy_codebooks_warehouses.jsonc
  modules/economy/codebooks/forms/economy_codebooks_cost_centers.jsonc
  ```
  - Procházet jeden po druhém. Každé pole `"name"` / `"label"` /
    `"title"` / `"titleNew"` doplnit o `:cs` (zachovat původní text,
    je to čeština) a `:en` (přeložit).

#### Backend — `DataSourceConfig::getDefaultLanguage()`

- Přidat metodu `getDefaultLanguage(): string` (default `'en'`).
- V `public/index.php` v `resolveLanguage()` přijmout volitelný
  parametr `?DataSourceConfig $config` a použít `getDefaultLanguage()`
  jako fallback místo hardcoded `'en'`.
- `config/main.json` schema: pole `"defaultLanguage"` na top-level,
  string ISO 639-1.

#### Frontend — error.code mapping

- Vytvořit modul `frontend/src/i18n/errors.js` s mapováním
  `error.code → translation key`.
- Wrapper funkce `translateError(error: { code, message, details })`
  vrací lokalizovaný text. Pro neznámé kódy fallback na
  `error.message` (anglicky ze serveru).
- Použít v `api/client.js` (centrální místo, kde se chyby zpracovávají),
  případně v individuálních volání (`alert()` v Viewer / Detail).
- Konkrétní chyby k přeložit:
  - `VALIDATION_ERROR`, `NOT_FOUND`, `UNAUTHORIZED`, `FORBIDDEN`,
    `CONFLICT`, `RATE_LIMITED`, `BAD_REQUEST`, `INTERNAL_ERROR`,
    `TABLE_NOT_FOUND`
  - V `details[].code`: `REQUIRED`, `EMPTY`, `UNIQUE`, `OUT_OF_RANGE`,
    `INVALID_FORMAT`, `INVALID_VALUE`
- Chybové hlášky obsahují substituce — `{field}`, `{value}`, `{min}`,
  `{max}`. ICU MessageFormat je řeší automaticky.

### Mimo rozsah

- **Refaktor toolbar API** na samostatný `actions:` blok v jsonc — to
  je velký redesign, který by se měl řešit zvlášť. Tato fáze řeší
  **stávající** mechanismus tak, aby fungoval s i18n.
- **Refaktor `renderDetail()` API** — viewery dál vrací labels jako
  stringy v `tabs[].label`. Tato fáze řeší jen, aby ty stringy byly
  lokalizované, ne aby se změnil tvar struktury.
- **Refaktor alert() na toast notifikace** — out of scope.
  `translateError()` vrací string, který se předá do existujícího
  `alert()`.
- **Sloupec `preferred_language` v `core_system_users`** — odloženo,
  per-zařízení volba je dle dohody dostatečná.
- **Persistence módu Settings při reloadu** — out of scope, viz
  `docs/frontend.md` sekce *Mode systém*.

## Rozhodnutí k designu (potvrzená)

- ✓ **PHP labels nejdou do hardcoded stringů, ale do jsonc / configu.**
  `'Add'`, `'Obecné'`, `'Reanalyze'` se nikdy nesmějí objevit jako
  stringové literály v PHP. Buď jsou v jsonc se `name:cs/en`, nebo
  v konfigurační položce, nebo (poslední fallback) v zvláštním
  překladovém slovníku v jsonc, který spadá pod `core.system`.
- ✓ **Defaultní toolbar akce (`create`, `edit`) přidat do
  `core.system` konfigurace.** Konkrétně: nová cfgItem
  `core.system.viewerDefaults` s polem `toolbarActions`. Každá akce
  má `id`, `name:cs`, `name:en`, `variant`, `icon`. `TableViewer`
  čte přes injected `ConfigRuntime`.
- ✓ **`AutoFormBuilder.generalTabLabel` jde přes `core.system.formDefaults`
  cfgItem.** Konzistentní s viewer defaults.
- ✓ **Detail taby — preferenční řešení je `tabs[].label:cs`,
  `tabs[].label:en` přímo z jsonc / configu**, kde to lze. Pokud
  se label generuje dynamicky v PHP (např. „Přílohy ({count})"),
  použije se PHP wrapper, který načte template přes config a aplikuje
  parametry.
- ✓ **Defaulttní jazyk při chybějícím `Accept-Language` je
  `defaultLanguage` z `config/main.json`, fallback `'en'`.** To je už
  v dokumentaci `docs/modules.md`, nepřidáváme nové rozhodnutí, jen
  ho implementujeme.
- ✓ **`translateError()` na frontendu — chyby ze serveru přicházejí
  jako `{code, message, details}`.** Frontend překládá `code` přes
  slovník, ostatní pole používá jako parametry. Pro neznámé `code`
  fallback na `message` (anglicky).
- ✓ **`details[].field` (název sloupce v chybové hlášce) zůstává
  v podobě, jak ho posílá server** (anglické id sloupce, např.
  `email`). Pro UX je vhodné mapovat na lokalizovaný název sloupce
  z TableDefinition, ale to je nad rámec této fáze.

## Soubory

### Měněné

```
src/Core/Config/DataSourceConfig.php          # + getDefaultLanguage()
public/index.php                              # resolveLanguage() s fallbackem
src/Core/Viewer/TableViewer.php               # toolbar akce z configu
src/Core/Form/JsoncFormLoader.php             # localized title, tabs[].label
src/Core/Form/AutoFormBuilder.php             # 'Obecné' z configu
modules/base/persons/src/PersonsViewer.php    # custom toolbar/detail labels
modules/core/mail/src/IncomingMessagesViewer.php  # custom toolbar/detail labels

# 12 jsonc souborů — doplnění name:en / label:en (viz seznam výše)

# Frontend
frontend/src/i18n/errors.js                   # nový — error code mapping
frontend/src/i18n/cs.js                       # rozšíření o error klíče
frontend/src/i18n/en.js                       # rozšíření o error klíče
frontend/src/api/client.js                    # použití translateError
frontend/src/components/viewer/Viewer.svelte  # alert() s translateError
frontend/src/components/viewer/ViewerDetail.svelte  # alert() s translateError
frontend/src/components/form/FormEditor.svelte  # error display s translateError
```

### Nové

```
modules/core/system/config/viewerDefaults.jsonc   # default toolbar akce
modules/core/system/config/formDefaults.jsonc     # generalTabLabel
frontend/src/i18n/errors.js
```

## Implementační detaily

### Default toolbar akce — `core/system/config/viewerDefaults.jsonc`

```jsonc
{
    "toolbarActions": {
        "create": {
            "name": "Add",
            "name:cs": "Přidat",
            "name:en": "Add",
            "variant": "primary",
            "icon": "add"
        },
        "edit": {
            "name": "Open",
            "name:cs": "Otevřít",
            "name:en": "Open",
            "variant": "secondary",
            "icon": "edit"
        }
    }
}
```

V `module.jsonc` modulu `core.system`:

```jsonc
{
    "config": [
        // ... existující ...
        {
            "id": "core.system.viewerDefaults",
            "file": "config/viewerDefaults.jsonc"
        }
    ]
}
```

### `TableViewer::getToolbarActions()` — refaktor

```php
public function getToolbarActions(?array $selectedRow): array
{
    $defaults = $this->config?->cfgItem('core.system.viewerDefaults')
        ?? ['toolbarActions' => []];
    $defs = $defaults['toolbarActions'] ?? [];

    $actions = [];

    // create — vždy přítomné
    if (isset($defs['create'])) {
        $actions[] = $this->buildAction('create', $defs['create']);
    } else {
        // Fallback pokud config není kompilovaný — keep current
        // behavior, but flag with internal id rather than user-facing
        // English. Should not happen in practice.
        $actions[] = ['id' => 'create', 'label' => 'create', 'variant' => 'primary'];
    }

    // edit — jen když je selected row
    if ($selectedRow !== null && isset($defs['edit'])) {
        $actions[] = $this->buildAction('edit', $defs['edit']);
    }

    return $actions;
}

private function buildAction(string $id, array $def): array
{
    return [
        'id'      => $id,
        'label'   => $def['name'] ?? $id,  // Already localized by ConfigLocalizer
        'variant' => $def['variant'] ?? 'secondary',
        'icon'    => $def['icon'] ?? null,
    ];
}
```

Klíčové: `$def['name']` je už lokalizováno, protože `ConfigRuntime`
vrací jazykovou variantu (kompilovanou do `compiled.cs.json` /
`compiled.en.json`).

### `JsoncFormLoader` — localized title + tab labels

V `JsoncFormLoader::load()`, kolem řádku 24 (kde se parsuje `tabs`):

```php
public function load(
    string $jsonPath,
    TableDefinition $tableDef,
    ?ConfigRuntime $config = null,
    string $tableId = '',
    string $language = 'en',  // ← nový parametr
): FormDefinition {
    $data = $this->parseJsonc($jsonPath);

    // Apply i18n resolution to top-level fields and nested tabs.
    $data = ConfigLocalizer::localize($data, $language);

    // ... zbytek kódu beze změn — $data['title'], $data['titleNew'],
    //     $tabData['label'] už jsou lokalizované strings
}
```

`FormController` a `FormLoader` musí předat `$language` dolů. Zdroj
jazyka: stejný jako pro TableLoader — z `Accept-Language` přes
`resolveLanguage()`.

### `AutoFormBuilder` — `Obecné` z configu

Nový jsonc `modules/core/system/config/formDefaults.jsonc`:

```jsonc
{
    "generalTabLabel": {
        "name": "General",
        "name:cs": "Obecné",
        "name:en": "General"
    }
}
```

V `AutoFormBuilder::build()`:

```php
public function build(
    TableDefinition $tableDef,
    ?ConfigRuntime $config = null,
    string $tableId = '',
): FormDefinition {
    $generalLabel = 'General';  // fallback pokud chybí config
    if ($config !== null) {
        $defaults = $config->cfgItem('core.system.formDefaults');
        $generalLabel = $defaults['generalTabLabel']['name'] ?? $generalLabel;
    }

    // ... v kódu kolem řádku 39:
    if (isset($grouped['__general__'])) {
        $tabs[] = $this->buildTab('general', $generalLabel, $grouped['__general__'], $config);
    }
    // ...
}
```

### Detail taby — viewer-specific labels

V každé Viewer třídě s `renderDetail()` přesunout hardcoded labels do
nové cfgItem v daném modulu. Příklad pro `IncomingMessagesViewer`
(`modules/core/mail/`):

Nový `modules/core/mail/config/incomingDetailLabels.jsonc`:

```jsonc
{
    "tabs": {
        "content":     {"name": "Content",    "name:cs": "Obsah",      "name:en": "Content"},
        "attachments": {"name": "Attachments","name:cs": "Přílohy",    "name:en": "Attachments"},
        "analyses":    {"name": "Analyses",   "name:cs": "Analýzy",    "name:en": "Analyses"},
        "original":    {"name": "Original",   "name:cs": "Originál",   "name:en": "Original"}
    }
}
```

V `module.jsonc`:

```jsonc
{
    "config": [
        {"id": "core.mail.incomingDetailLabels", "file": "config/incomingDetailLabels.jsonc"}
    ]
}
```

V `IncomingMessagesViewer::renderDetail()`:

```php
public function renderDetail(int $recordId): array
{
    $labels = $this->config?->cfgItem('core.mail.incomingDetailLabels')['tabs'] ?? [];

    return ['tabs' => [
        ['id' => 'content',     'label' => $labels['content']['name']     ?? 'Content',     'content' => $this->buildContentTab($recordId)],
        ['id' => 'attachments', 'label' => $labels['attachments']['name'] ?? 'Attachments', 'content' => $this->buildAttachmentsTab($recordId)],
        // ...
    ]};
}
```

Stejné pro `PersonsViewer` a další viewery, které mají detail taby.

### `DataSourceConfig::getDefaultLanguage()`

```php
public function getDefaultLanguage(): string
{
    return $this->data['defaultLanguage'] ?? 'en';
}
```

V `public/index.php`:

```php
function resolveLanguage(Request $request, ?DataSourceConfig $config = null): string
{
    $header = $request->getHeader('Accept-Language');
    $fallback = $config?->getDefaultLanguage() ?? 'en';

    if ($header === null) {
        return $fallback;
    }
    $first = explode(',', $header)[0];
    $first = explode(';', $first)[0];
    $first = explode('-', trim($first))[0];
    return $first !== '' ? strtolower($first) : $fallback;
}
```

A v call sites (kolem řádku 58):

```php
$language = resolveLanguage($request, $resolved->config);
```

### Frontend — `errors.js`

```js
// frontend/src/i18n/errors.js
import { t } from './index.js';

/**
 * Translate a server error response to a user-facing string.
 *
 * @param {{code: string, message: string, details?: Array}} error
 * @returns {string} Localized error message
 */
export function translateError(error) {
  if (!error) return t('common.unknownError');

  const key = `error.${error.code}`;
  const params = {
    message: error.message ?? '',
    field: error.details?.[0]?.field ?? '',
    value: error.details?.[0]?.value ?? '',
  };

  // Try to translate with code; if dictionary doesn't have it,
  // fallback to server-provided message.
  const translated = t(key, params);
  if (translated === key) {
    // t() returns the key when not found — fallback to server message.
    return error.message ?? t('common.unknownError');
  }
  return translated;
}
```

V `cs.js` / `en.js` přidat sekci:

```js
// cs.js — výňatek
'error.VALIDATION_ERROR': 'Validace selhala',
'error.NOT_FOUND': 'Záznam nenalezen',
'error.UNAUTHORIZED': 'Pro tuto akci musíte být přihlášen',
'error.FORBIDDEN': 'Nedostatečná oprávnění',
'error.CONFLICT': 'Konflikt s existujícím záznamem',
'error.RATE_LIMITED': 'Příliš mnoho požadavků, zkuste to později',
'error.BAD_REQUEST': 'Chybný požadavek',
'error.INTERNAL_ERROR': 'Vnitřní chyba serveru',
'error.TABLE_NOT_FOUND': 'Tabulka neexistuje',
```

Použití v komponentě:

```svelte
<script>
  import { translateError } from '../../i18n/errors.js';

  // ... uvnitř handleru:
  if (!result?.success) {
    alert(translateError(result.error));
  }
</script>
```

## Task breakdown

### T1 — `DataSourceConfig::getDefaultLanguage()` + `resolveLanguage()`

- Přidat metodu, doplnit fallback do `resolveLanguage()`.
- Update tří test souborů, pokud existují (`tests/Unit/Core/Config/`).

**Hotovo když:** `vendor/bin/phpunit` projde. Volání API bez
`Accept-Language` hlavičky vrátí v aktuálním jazyce (default = `'en'`,
ale po nastavení `"defaultLanguage": "cs"` v `config/main.json` →
čeština).

### T2 — `viewerDefaults.jsonc` + `TableViewer` refaktor

- Vytvořit `modules/core/system/config/viewerDefaults.jsonc`.
- Zaregistrovat v `modules/core/system/module.jsonc`.
- Refaktor `TableViewer::getToolbarActions()` na čtení z config.
- Spustit `vendor/bin/shpd-ds ds-upgrade` v dev DS, aby se rekompilovala
  konfigurace.

**Hotovo když:** v anglickém UI vidíš ve viewerech `Add` / `Open`,
v českém `Přidat` / `Otevřít`. Žádný hardcoded English string v `TableViewer`.

### T3 — `JsoncFormLoader` localization

- Přidat parametr `string $language` do `JsoncFormLoader::load()`.
- Aplikovat `ConfigLocalizer::localize($data, $language)` na top-level
  data před parsováním tabs.
- Update všech volajících (`FormController`, `FormLoader` apod.).
- Doplnit chybějící `name:en`, `label:en`, `title:en`, `titleNew:en`
  do 6 form jsonc souborů (`base/persons/forms/*` +
  `economy/codebooks/forms/*`).

**Hotovo když:** `Kontakt` / `Nový kontakt` se v anglickém UI zobrazují
jako `Contact` / `New contact`. Tab labels (`Kontakt`, `Adresa`) se
přepínají.

### T4 — `AutoFormBuilder` + `formDefaults.jsonc`

- Vytvořit `modules/core/system/config/formDefaults.jsonc`.
- Zaregistrovat v `module.jsonc` (`core.system`).
- Refaktor `AutoFormBuilder` — `'Obecné'` → z configu.
- Spustit `ds-upgrade`.

**Hotovo když:** tabulka bez vlastního `forms/{table}.jsonc` (např.
`core_system_users`) se otevře — tab se v en jmenuje `General`,
v cs `Obecné`. Žádný hardcoded `'Obecné'` v PHP.

### T5 — Detail taby v individuálních viewerech

- Pro `PersonsViewer` (`modules/base/persons/`): vytvořit
  `config/personsDetailLabels.jsonc`, registrovat, refaktor
  `renderDetail()`.
- Pro `IncomingMessagesViewer` (`modules/core/mail/`): vytvořit
  `config/incomingDetailLabels.jsonc`, registrovat, refaktor
  `renderDetail()`.
- Pro další viewery, pokud existují (audit přes
  `grep -rn "renderDetail" modules/`).

**Hotovo když:** detail Persons / IncomingMessages má taby v aktuálním
jazyce. `grep -rn "'Obsah'\|'Přílohy'\|'Analýzy'\|'Originál'" modules/`
už nic nevrací (kromě komentářů).

### T6 — Doplnění `name:en` / `label:en` ve zbylých jsonc

12 souborů — viz seznam ve *Scope*. Mechanická práce:

- Otevřít soubor.
- Pro každé `"name": "..."` (nebo `"label"` / `"title"`) přidat varianty
  `:cs` (zachovat český text) a `:en` (přeložit).
- Spustit `vendor/bin/phpunit` po každé skupině.

**Hotovo když:**

```bash
find modules -name "*.jsonc" | xargs grep -L '"name:en"\|"label:en"\|"title:en"' 2>/dev/null
```

vrátí jen soubory, kde tyto klíče oprávněně chybí (např. extension files
bez human-facing labels — projít manuálně, doplnit kde je text pro UI).

### T7 — Frontend `errors.js`

- Vytvořit `frontend/src/i18n/errors.js` s `translateError()`.
- Doplnit `error.*` klíče do `cs.js` a `en.js`.
- V `Viewer.svelte`, `ViewerDetail.svelte`, `FormEditor.svelte`
  nahradit `result.error.message` přímo na alert za
  `translateError(result.error)`.

**Hotovo když:**

- `npm run check:i18n` projde.
- Vyvolaný validation error (např. uložení formuláře s chybějícím
  povinným polem) zobrazí česky/anglicky podle volby.
- Neznámý error code → fallback na anglickou message ze serveru.

### T8 — dokumentace

- Update `docs/modules.md` sekce 9 — popsat `defaultLanguage`
  v `config/main.json` jako už **implementovaný** (ne plánovaný).
- Update `docs/frontend.md` — sekce *Internacionalizace*: error code
  mapping přes `translateError()`, příklad použití.
- Update `docs/edit-forms.md` — i18n pro `title`, `titleNew`,
  `tabs[].label` v jsonc.
- Update `CLAUDE.md` — drobně rozšířit poznámku o i18n: i toolbar akce
  a form taby jdou přes config jsonc, ne přes hardcoded stringy.

**Hotovo když:** dokumentace odpovídá aktuálnímu stavu kódu.

## Akceptační kritéria celé fáze

1. `cd frontend && npm run build 2>&1` — projde bez chyb a varování.
2. `cd frontend && npm run check:i18n` — projde se zelenou.
3. `vendor/bin/phpunit 2>&1` — projde.
4. **Manuální průchod aplikace v anglickém UI**:
   - Sidebar navigace, toolbar tlačítka (`Add`, `Open`), prázdné stavy
     vieweru, taby formulářů (`General`, `Contact`), taby detailu
     (`Content`, `Attachments`), modální titulky — **všechno
     v angličtině**.
   - Žádný český text nikde, kromě uživatelských dat (jména osob, popisy
     atd.).
5. **Manuální průchod v českém UI**: vše funguje jako dřív, jen už ne
   z hardcoded stringů.
6. **Test default language**:
   - V `config/main.json` nastavit `"defaultLanguage": "cs"`.
   - Smazat `localStorage.shpd_language`.
   - Reload — UI je české i bez explicitní volby.
   - Změnit na `"defaultLanguage": "en"`, reload — UI je anglické.
7. **Test error mapping**:
   - Pokus uložit formulář s chybějícím povinným polem → česká hláška
     v `cs` UI, anglická v `en` UI.
   - Forced 500 (např. zlomený DB connection) → fallback na anglickou
     server message, ne na error key.

## Otevřené otázky

- **`renderDetail()` — kdo plní `tab.id` jako klíč pro lookup labelů?**
  V dnešním kódu `tab.id` je jeden řetězec, label druhý. Je vhodné
  navzájem propojit (např. label vycházet z konvence `tabs.{id}.name`)?
  Pokud je počet detail-tab IDs malý a stabilní, nejjednodušší je
  hardcoded mapping v jsonc (jako v ukázce výše). Pokud bude potřeba
  to dynamizovat, řešit zvlášť.
- **Mají jiné Viewer třídy než `Persons` a `IncomingMessages` vlastní
  detail taby?** Před začátkem T5 grep:
  `grep -rln "renderDetail" modules/` — ujistit se, že jsme nic
  nepřehlédli.

## Návazné fáze

Po dokončení této fáze je multilingual support frontendu **kompletní
pro produkční nasazení**. Možná pokračování:

- **Fáze 2**: třetí jazyk (DE/SK/PL). Architektura ho zvládne bez
  refaktoru — slovníky jsou per-jazyk, ICU MessageFormat má CLDR
  pravidla pro libovolný jazyk, jsonc soubory jen doplní `:de`
  varianty.
- **Per-uživatel volba jazyka** — sloupec `preferred_language`
  v `core_system_users`, frontend ho po loginu přečte z user objektu
  a nastaví `language.setMode()`. Per-zařízení volba pak slouží jen
  pro nepřihlášené (LoginScreen).
- **Validační detail mapping** — `details[].field` z anglického id
  sloupce na lokalizovaný název přes TableDefinition. UX vylepšení.
- **Toast notifikace místo `alert()`** — out of scope této fáze, ale
  vyplyne jako přirozený další krok.
