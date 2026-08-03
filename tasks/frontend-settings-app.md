# Task: Nastavení aplikace

**Stav:** hotovo

## Kontext

Číselníky (Fiskální období, DPH, Pokladny, Bankovní spojení, Sklady,
Střediska, Druhy položek, Měrné jednotky) jsou aktuálně rozházené v
hlavním sidebaru pod různými skupinami (Ekonomika, Systém, …). To
přepňuje hlavní navigaci podružnými věcmi, se kterými uživatel
aktivně nepracuje.

Tato fáze zavádí **Nastavení aplikace** jako samostatný režim aplikace
(mode) — vstup je z dropdownu v patce sidebaru, výstup tlačítkem „Zpět
do aplikace" v hlavičce sidebaru. V režimu Nastavení sidebar zobrazuje
jiný strom položek (sekce + číselníky), hlavní obsah zůstává stejný
typ komponent (`TableBrowser` / `Viewer`).

Žádné URL routing — držíme dokumentovanou konvenci aplikace
(`docs/frontend.md` § 1: *„Žádné URL routing — stav navigace interně"*).
Mode + active item se drží v `navigation.svelte.js`, oba módy si pamatují
svou poslední aktivní položku.

Před implementací **přečti**:

- `docs/frontend.md` — frontend architektura, sidebar, ikony, navigation
  store
- `docs/modules.md` — modulový systém, JSONC, vícejazyčnost
- `docs/edit-forms.md` § 13 — deklarativní JSONC formy (Sklady/Střediska
  budou potřebovat formy)
- `docs/doc-states.md` — stavy dokumentů (Sklady/Střediska budou mít
  `core.system.docStatesArchive`)

Vzorové existující implementace k nastudování:

- `src/Api/Controller/NavigationController.php` — vzor pro stavění
  navigačního stromu z modulů
- `modules/economy/codebooks/module.jsonc` — vzor `module.jsonc`
  s `viewers`, `forms`, `documentClasses`, `config`
- `modules/economy/codebooks/src/CashDesksViewer.php` +
  `modules/economy/codebooks/src/CashDeskDocument.php` +
  `modules/economy/codebooks/forms/economy_codebooks_cash_desks.jsonc` —
  kompletní vzor pro číselník s kódem/názvem/datumy/sort_order/docStates
- `modules/economy/codebooks/tables/economy_codebooks_warehouses.jsonc`
  a `economy_codebooks_cost_centers.jsonc` — definice tabulek (zatím
  bez `docStates` blocku)
- `frontend/src/components/layout/Sidebar.svelte` — sidebar včetně
  dropdownu v patce
- `frontend/src/stores/navigation.svelte.js` — current navigation store
  (jen `activeItem`, žádný mode)

## Cíl

Po dokončení této fáze platí:

- V dropdownu v patce sidebaru je nová položka **„Nastavení aplikace"**
  (ikona ozubeného kola); klik přepne do režimu Nastavení
- V režimu Nastavení sidebar:
  - V hlavičce má pod logem tlačítko **„← Zpět do aplikace"**
  - Místo běžného navigačního stromu zobrazuje sekce z
    `settingsSections.jsonc` s položkami z `settingsItems[]` jednotlivých
    modulů (Účetnictví → Fiskální období, DPH, Pokladny, Bankovní
    spojení; Sklady → Sklady, Střediska; Položky → Druhy položek,
    Měrné jednotky)
  - V dropdownu v patce položka „Nastavení aplikace" buď chybí, nebo
    je disabled
- Číselníky převedené do Nastavení **mizí z hlavního navigačního stromu**
  (Pokladny, Bankovní spojení, Fiskální období, DPH, Sklady, Střediska,
  Druhy položek, Měrné jednotky)
- Sklady a Střediska mají vlastní viewery, Document třídy a deklarativní
  JSONC formy (analogicky ke CashDesks/BankAccounts) a docStates lifecycle
- Mode (`'app'` / `'settings'`) i active item per mode se drží v
  `navigation.svelte.js`; přepnutí z app → settings → app vrátí
  uživatele na poslední položku, kterou viděl v app
- `docs/frontend.md` a `CLAUDE.md` jsou aktualizované

## Návaznost

- Závisí na: existujících viewerech a formách v `economy.codebooks`,
  `economy.items`, `core.units`; funkční edit-forms; funkční doc-states
- Sousední tasky: `economy-cash-and-bank.md` (vzor pro Sklady/Střediska
  Document tříd a forms), `economy-fiscal-periods.md` (vzor pro
  rozsáhlejší codebook)
- Otevírá: budoucí sekce **Databáze** v Nastavení (nastavení DS,
  jazyk, …) — zatím out of scope; budoucí sekce **Uživatelé**, **Role**

## Scope

### V rozsahu

- Backend:
  - Nový globální config item `global.settingsSections` v
    `modules/install/base/config/settingsSections.jsonc`
  - Rozšíření `module.jsonc` o pole `settingsItems[]` v dotčených
    modulech (`economy.codebooks`, `economy.items`, `core.units`)
  - Rozšíření `ModuleDefinition` o pole `settingsItems` (loader, factory)
  - Nový `SettingsController` s endpointem `GET /_ui/settings/navigation`
  - Úprava `NavigationController` — skrývání tabulek/viewerů, které
    jsou v některém `settingsItems[]`
- Backend — Sklady a Střediska:
  - Doplnění `docStates` blocku do
    `economy_codebooks_warehouses.jsonc` a
    `economy_codebooks_cost_centers.jsonc`
  - Document třídy `WarehouseDocument` a `CostCenterDocument`
  - JSONC formy `forms/economy_codebooks_warehouses.jsonc` a
    `forms/economy_codebooks_cost_centers.jsonc`
  - Viewery `WarehousesViewer.php` a `CostCentersViewer.php`
  - Registrace v `module.jsonc` (`viewers`, `forms`, `documentClasses`)
- Frontend:
  - Rozšíření `navigation.svelte.js` — `mode`, `activeItem` per mode,
    `enterSettings()`, `exitSettings()`
  - Rozšíření `Sidebar.svelte`:
    - V režimu app: položka „Nastavení aplikace" v dropdownu patky
      (volá `enterSettings()`)
    - V režimu settings: tlačítko „← Zpět do aplikace" v hlavičce pod
      logem; načítání ze `/_ui/settings/navigation` místo `/_ui/navigation`;
      v dropdownu patky položku Nastavení skrýt nebo disabled
  - Frontend ikony: rozšíření `iconMap` o `'calculator'` (pro sekci
    Účetnictví) — `iconWarehouse` a `iconTags` už v mapě jsou
- Documentation:
  - `docs/frontend.md` — nová sekce o módech navigace (app/settings),
    update sekce o sidebaru
  - `CLAUDE.md` — krátká zmínka o Settings módu
  - `modules/install/base/README.md` — pokud existuje, jinak rozšíření
    `modules/install/base/module.jsonc` zmínkou v dokumentaci
- Testy:
  - Unit testy pro `WarehouseDocument` a `CostCenterDocument`
  - Unit test pro `ModuleDefinition::fromArray()` ověřující správné
    parsování `settingsItems`

### Mimo rozsah (odložené)

- Sekce **Databáze** v Nastavení (info o DS, jazyk, change tracking) —
  odkládáme, žádný UI
- Sekce **Uživatelé** / **Role** v Nastavení — odkládáme
- URL routing pro `/settings/*` — drží se konvence „bez URL routing"
- Persistence módu / aktivní položky napříč reloady (po F5 zpět do
  app módu) — out of scope pro tuto fázi
- Custom logika viewerů Skladů/Středisek nad rámec analogie s
  CashDesks (žádné speciální detail panely, kalkulace)
- Provisioner / seed pro Sklady/Střediska — uživatel si je nadefinuje sám
- Editovatelnost sekcí v Nastavení (drag-and-drop pořadí, custom sekce
  per uživatel) — sekce jsou statické z `settingsSections.jsonc`

## Datový model — definice sekcí a items

### Globální config item: `global.settingsSections`

**Soubor:** `modules/install/base/config/settingsSections.jsonc`

```jsonc
{
    "sections": [
        {
            "id": "accounting",
            "name": "Accounting",
            "name:cs": "Účetnictví",
            "name:en": "Accounting",
            "icon": "calculator",
            "order": 10
        },
        {
            "id": "warehouses",
            "name": "Warehouses",
            "name:cs": "Sklady",
            "name:en": "Warehouses",
            "icon": "warehouse",
            "order": 20
        },
        {
            "id": "items",
            "name": "Items",
            "name:cs": "Položky",
            "name:en": "Items",
            "icon": "tags",
            "order": 30
        }
    ]
}
```

V `modules/install/base/module.jsonc` přibude `config[]` s registrací
tohoto config itemu:

```jsonc
"config": [
    { "id": "global.settingsSections", "file": "config/settingsSections.jsonc" }
]
```

### Pole `settingsItems[]` v `module.jsonc`

Nové volitelné pole. Každá položka odkazuje buď na **viewer** (preferovaně)
nebo přímo na **tabulku**, a přiřazuje ji do **sekce**.

```jsonc
"settingsItems": [
    { "viewer": "economy.codebooks.fiscalYears",        "section": "accounting" },
    { "viewer": "economy.codebooks.vatRegistrations",   "section": "accounting" },
    { "viewer": "economy.codebooks.cashDesks",          "section": "accounting" },
    { "viewer": "economy.codebooks.bankAccounts",       "section": "accounting" },
    { "viewer": "economy.codebooks.warehouses",         "section": "warehouses" },
    { "viewer": "economy.codebooks.costCenters",        "section": "warehouses" }
]
```

Schema item:

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `viewer` | string | Pokud chybí `table` | ID vieweru (musí existovat v `viewers[]`) |
| `table` | string | Pokud chybí `viewer` | Název tabulky (musí existovat v `tables[]`) |
| `section` | string | Ano | ID sekce z `settingsSections.jsonc` |
| `order` | int | Ne | Pořadí v rámci sekce; pokud chybí, řadí se podle pořadí v poli |

**Pravidla:**

- Musí být uveden buď `viewer`, nebo `table`, nikdy oba ani žádný
- `section` musí odpovídat existujícímu `id` v `global.settingsSections`
- Pokud `viewer` neexistuje v modulu (typo), `SettingsController`
  vyhodí hlásí chybu v logu a položku ignoruje
- Pokud více modulů odkazuje stejný `viewer`/`table` (nemělo by se
  stát), bere se první výskyt v topologickém pořadí modulů

## Rozdělení existujících číselníků

Toto je závazný plán, jak rozhodit existující viewery do sekcí.

### Modul `economy.codebooks` — settingsItems

```jsonc
"settingsItems": [
    { "viewer": "economy.codebooks.fiscalYears",      "section": "accounting" },
    { "viewer": "economy.codebooks.vatRegistrations", "section": "accounting" },
    { "viewer": "economy.codebooks.cashDesks",        "section": "accounting" },
    { "viewer": "economy.codebooks.bankAccounts",     "section": "accounting" },
    { "viewer": "economy.codebooks.warehouses",       "section": "warehouses" },
    { "viewer": "economy.codebooks.costCenters",      "section": "warehouses" }
]
```

(Viewery `warehouses` a `costCenters` zatím neexistují — vznikají v
této fázi, viz Krok 4.)

### Modul `economy.items` — settingsItems

```jsonc
"settingsItems": [
    { "viewer": "economy.items.kinds", "section": "items" }
]
```

Pozn.: `economy.items` (viewer pro `economy_items` tabulku — samotný
katalog položek) **zůstává v hlavním menu** — to je hlavní pracovní
prostor uživatele, ne číselník.

### Modul `core.units` — settingsItems

```jsonc
"settingsItems": [
    { "viewer": "core.units", "section": "items" }
]
```

## API — endpoint `/_ui/settings/navigation`

Stejný formát jako `/_ui/navigation`, jen kořenem stromu jsou sekce
ze `settingsSections.jsonc` (seřazené podle `order`), a v každé sekci
jsou jen položky odpovídající `settingsItems[]` napříč všemi aktivními
moduly.

### Příklad odpovědi

```json
{
    "success": true,
    "data": [
        {
            "id": "accounting",
            "label": "Účetnictví",
            "icon": "calculator",
            "children": [
                {
                    "id": "viewer:economy.codebooks.fiscalYears",
                    "label": "Fiskální období",
                    "type": "viewer",
                    "viewerId": "economy.codebooks.fiscalYears",
                    "icon": "calendar"
                },
                {
                    "id": "viewer:economy.codebooks.vatRegistrations",
                    "label": "Registrace DPH",
                    "type": "viewer",
                    "viewerId": "economy.codebooks.vatRegistrations",
                    "icon": "vat"
                },
                {
                    "id": "viewer:economy.codebooks.cashDesks",
                    "label": "Pokladny",
                    "type": "viewer",
                    "viewerId": "economy.codebooks.cashDesks",
                    "icon": "wallet"
                },
                {
                    "id": "viewer:economy.codebooks.bankAccounts",
                    "label": "Bankovní spojení",
                    "type": "viewer",
                    "viewerId": "economy.codebooks.bankAccounts",
                    "icon": "bank"
                }
            ]
        },
        {
            "id": "warehouses",
            "label": "Sklady",
            "icon": "warehouse",
            "children": [
                { "id": "viewer:economy.codebooks.warehouses",  "label": "Sklady",     "type": "viewer", "viewerId": "economy.codebooks.warehouses",  "icon": "warehouse" },
                { "id": "viewer:economy.codebooks.costCenters", "label": "Střediska", "type": "viewer", "viewerId": "economy.codebooks.costCenters", "icon": "folder" }
            ]
        },
        {
            "id": "items",
            "label": "Položky",
            "icon": "tags",
            "children": [
                { "id": "viewer:economy.items.kinds", "label": "Druhy položek", "type": "viewer", "viewerId": "economy.items.kinds", "icon": "tags" },
                { "id": "viewer:core.units",          "label": "Měrné jednotky", "type": "viewer", "viewerId": "core.units",          "icon": "ruler" }
            ]
        }
    ]
}
```

**Tvary item objektů uvnitř `children`:**

- Pokud `settingsItem` referuje `viewer` — výsledný item má stejný
  tvar jako vrací `NavigationController` pro viewer (`type: "viewer"`,
  `viewerId`, `icon` z `module.jsonc.viewers[]`, `label` lokalizovaný
  z `name:{lang}`)
- Pokud `settingsItem` referuje `table` — `type: "table"`, `table:
  "..."`, `icon` z `tables/{name}.jsonc.icon` (pokud je), `label`
  lokalizovaný z definice tabulky

**Sekce bez položek** (žádný modul nemá `settingsItem` mířící do dané
sekce) **se ve výstupu vynechávají** — neukazujeme prázdné nadpisy.

**Empty response**: pokud žádný modul nemá `settingsItems[]`, vrací
se `data: []` (klient zobrazí prázdný stav: „Zatím žádné nastavení").

## Adresářová struktura

### Backend (PHP)

```
src/
├── Api/
│   └── Controller/
│       ├── NavigationController.php          # ROZŠÍŘIT — skrývání settings items z hlavního stromu
│       └── SettingsController.php            # NOVÝ — endpoint /_ui/settings/navigation
└── Core/
    └── Module/
        └── ModuleDefinition.php              # ROZŠÍŘIT — pole settingsItems

modules/
├── install/base/
│   ├── module.jsonc                          # ROZŠÍŘIT — config[] s global.settingsSections
│   └── config/
│       └── settingsSections.jsonc            # NOVÝ
├── economy/codebooks/
│   ├── module.jsonc                          # ROZŠÍŘIT — settingsItems[], + viewers/forms/documentClasses pro warehouses & cost_centers
│   ├── tables/
│   │   ├── economy_codebooks_warehouses.jsonc      # ROZŠÍŘIT — docStates blok
│   │   ├── economy_codebooks_warehouses.md         # NOVÝ (pokud nyní neexistuje)
│   │   ├── economy_codebooks_cost_centers.jsonc   # ROZŠÍŘIT — docStates blok
│   │   └── economy_codebooks_cost_centers.md      # NOVÝ (pokud nyní neexistuje)
│   ├── forms/
│   │   ├── economy_codebooks_warehouses.jsonc     # NOVÝ
│   │   └── economy_codebooks_cost_centers.jsonc   # NOVÝ
│   └── src/
│       ├── WarehouseDocument.php             # NOVÝ
│       ├── WarehousesViewer.php              # NOVÝ
│       ├── CostCenterDocument.php            # NOVÝ
│       └── CostCentersViewer.php             # NOVÝ
├── economy/items/
│   └── module.jsonc                          # ROZŠÍŘIT — settingsItems[]
└── core/units/
    └── module.jsonc                          # ROZŠÍŘIT — settingsItems[]
```

### Frontend

```
frontend/src/
├── api/
│   └── client.js                             # bez změn (existující GET stačí)
├── components/
│   └── layout/
│       └── Sidebar.svelte                    # ROZŠÍŘIT — mode-aware načítání + tlačítko zpět + dropdown položka
├── icons.js                                  # ROZŠÍŘIT — iconCalculator + iconMap
└── stores/
    └── navigation.svelte.js                  # ROZŠÍŘIT — mode, activeItem per mode, enter/exitSettings
```

### Docs

```
docs/
└── frontend.md                               # ROZŠÍŘIT — sekce o módech, sidebar update
CLAUDE.md                                     # ROZŠÍŘIT — zmínka o Settings módu
```

### Testy

```
tests/Unit/
├── Core/Module/
│   └── ModuleDefinitionTest.php              # ROZŠÍŘIT (pokud existuje) nebo NOVÝ — settingsItems parsování
└── Module/Economy/Codebooks/
    ├── WarehouseDocumentTest.php             # NOVÝ
    └── CostCenterDocumentTest.php            # NOVÝ
```

## Task breakdown

### Krok 1: Globální sekce a `settingsItems` v `module.jsonc`

Cíl: připravit data, na kterých budou stavět všechny ostatní kroky.
**V tomto kroku ještě nic nefunguje** — pole `settingsItems[]` zatím
nikdo nečte, ale moduly se musí načítat bez chyby (parser je má jen
ignorovat).

1. Vytvoř `modules/install/base/config/settingsSections.jsonc` podle
   schématu v sekci „Datový model" výše
2. V `modules/install/base/module.jsonc` přidej `config[]` s registrací
   `global.settingsSections`. Pokud `module.jsonc` zatím nemá `config`
   pole, přidej ho na konec
3. V `modules/economy/codebooks/module.jsonc` přidej `settingsItems[]`
   pole se 6 položkami (fiscalYears, vatRegistrations, cashDesks,
   bankAccounts, warehouses, costCenters). **Pozn.:** warehouses
   a costCenters viewery zatím neexistují — to je OK, vzniknou v
   Kroku 4 a `module.jsonc` necháme předem připravený
4. V `modules/economy/items/module.jsonc` přidej `settingsItems[]` s
   jednou položkou (`economy.items.kinds`)
5. V `modules/core/units/module.jsonc` přidej `settingsItems[]` s
   jednou položkou (`core.units`)
6. Spusť `bin/shpd-ds ds-upgrade` na testovacím DS — musí projít bez
   chyby (i když `settingsItems` ještě nikdo nečte; `ModuleLoader`
   nesmí havarovat na neznámém poli)

### Krok 2: `ModuleDefinition` — settingsItems v loaderu

V `src/Core/Module/ModuleDefinition.php`:

1. Přidej property `public readonly array $settingsItems` (s defaultem
   `[]`)
2. Uprav `fromArray()` factory:
   ```php
   $settingsItems = [];
   if (isset($data['settingsItems']) && is_array($data['settingsItems'])) {
       foreach ($data['settingsItems'] as $item) {
           if (!is_array($item)) continue;
           if (!isset($item['section'])) continue;
           if (!isset($item['viewer']) && !isset($item['table'])) continue;
           if (isset($item['viewer']) && isset($item['table'])) continue;
           $settingsItems[] = [
               'viewer'  => $item['viewer'] ?? null,
               'table'   => $item['table']  ?? null,
               'section' => (string) $item['section'],
               'order'   => isset($item['order']) ? (int) $item['order'] : null,
           ];
       }
   }
   // do konstruktoru
   ```
3. Uprav konstruktor / signaturu, aby přijímal `$settingsItems`

Test (rozšiř existující `ModuleDefinitionTest` nebo vytvoř):

- `fromArray` s validním `settingsItems` polem → načte všechny položky
- `fromArray` s chybějícím `section` → položka se ignoruje
- `fromArray` s oběma `viewer` i `table` → položka se ignoruje
- `fromArray` bez `settingsItems` → property je `[]`

### Krok 3: `SettingsController` + endpoint

V `src/Api/Controller/SettingsController.php` (nový soubor):

```php
<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Utils\JsoncParser;

class SettingsController
{
    public function navigation(
        DataSourceConfig $config,
        string $modulesBasePath,
        string $language,
        ConfigRuntime $configRuntime,
    ): Response {
        $allModules      = ModuleLoader::loadAllModules($modulesBasePath);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        // 1) Načti definici sekcí
        $sectionsCfg = $configRuntime->cfgItem('global.settingsSections');
        $sections    = $sectionsCfg['sections'] ?? [];
        // Seřaď podle order ASC
        usort($sections, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        // 2) Pro každou sekci sesbírej items napříč moduly
        $itemsBySection = $this->collectItems($resolvedModules, $modulesBasePath, $language);

        // 3) Postav výsledný strom — sekce bez items se přeskočí
        $tree = [];
        foreach ($sections as $section) {
            $sectionId = $section['id'];
            if (empty($itemsBySection[$sectionId])) continue;

            $tree[] = [
                'id'       => $sectionId,
                'label'    => $section['name'] ?? $sectionId,
                'icon'     => $section['icon'] ?? null,
                'children' => $itemsBySection[$sectionId],
            ];
        }

        return Response::success($tree);
    }

    // ... soukromé helpery: collectItems, buildViewerItem, buildTableItem
}
```

**Detaily `collectItems()`:**

- Iteruj přes `resolvedModules` v topologickém pořadí
- Pro každý modul iteruj `$module->settingsItems`
- Pro každý item:
  - Pokud `viewer`: najdi v `$module->viewers` záznam s `id === $item['viewer']`.
    Pokud neexistuje → log warning, skip
  - Pokud `table`: najdi v `$module->tables` (`in_array`). Pokud
    neexistuje → log warning, skip
- Vytvoř item dict ve stejném tvaru jako `NavigationController`
  (`id`, `label`, `type`, `viewerId`/`table`, `icon`); použij
  `ConfigLocalizer` pro lokalizaci `label`
- Přidej do `$itemsBySection[$item['section']][]`
- Pokud má `settingsItem` `order`, použij ho pro řazení v rámci sekce;
  jinak řaď podle vstupního pořadí

**Pomocná metoda — lokalizace názvu vieweru** (vzor z
`NavigationController::loadTableMeta`):

```php
private function localizeViewerName(array $viewer, string $language): string {
    return $viewer['name:' . $language] ?? $viewer['name:en'] ?? $viewer['name'] ?? $viewer['id'];
}
```

V `src/Api/Router.php` přidej route pro `/_ui/settings/navigation`:

```php
if ($subpath === '/_ui/settings/navigation') {
    if ($method !== 'GET') {
        return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
    }
    return new Route('settings', 'navigation');
}
```

V `public/index.php` přidej do dispatch funkce nový case:

```php
'settings' => dispatchSettings($route->action, $resolved->config, $modulesBasePath, $language, $configRuntime),
```

A funkci:

```php
function dispatchSettings(
    string $action,
    DataSourceConfig $config,
    string $modulesBasePath,
    string $language,
    ConfigRuntime $configRuntime,
): Response {
    $ctrl = new SettingsController();
    return match ($action) {
        'navigation' => $ctrl->navigation($config, $modulesBasePath, $language, $configRuntime),
        default => Response::error('INTERNAL_ERROR', "Unknown settings action: {$action}", 500),
    };
}
```

`ConfigRuntime` musí být v `index.php` k dispozici — obvykle už je
pro jiné controllery; pokud ne, nahlédni do `dispatch()` a přidej
ho do parametru. Ověř `curl`em:

```bash
curl -H 'Authorization: Bearer ...' \
     http://localhost/{ds-id}/api/v1/_ui/settings/navigation | jq
```

Mělo by vrátit pole se 3 sekcemi (Účetnictví, Sklady, Položky), kde
„Sklady" je zatím prázdná (warehouses/costCenters viewery ještě
neexistují — `collectItems` je přeskočí s warningem v logu) → sekce
„Sklady" tudíž ve výstupu **nebude** (pravidlo: sekce bez items se
vynechává). To je dočasný stav; po Kroku 4 se objeví.

### Krok 4: Sklady a Střediska — viewery, Documenty, formy

Vzor: `economy-cash-and-bank.md` task — Sklady a Střediska jsou jednodušší
verze (bez `currency`, `is_default`, `bank_*`).

**4a. Doplnit `docStates` do tabulek**

V `modules/economy/codebooks/tables/economy_codebooks_warehouses.jsonc`
a `economy_codebooks_cost_centers.jsonc` přidej do kořene:

```jsonc
"docStates": {
    "stateColumn": "docState",
    "mainColumn": "docStateMain",
    "cfgItem": "core.system.docStatesArchive"
},
```

A do `columns` doplň systémové sloupce (vzor: `economy_codebooks_cash_desks.jsonc`):

```jsonc
{
    "id": "docState",
    "name": "Doc state",
    "type": "tinyint",
    "nullable": false,
    "default": 10,
    "system": true
},
{
    "id": "docStateMain",
    "name": "Doc state main",
    "type": "tinyint",
    "nullable": false,
    "default": 1,
    "system": true
}
```

A do `indexes` přidej:

```jsonc
{
    "id": "idx_doc_state",
    "type": "index",
    "columns": [
        {"column": "docStateMain", "order": "ASC"},
        {"column": "sort_order", "order": "ASC"}
    ]
}
```

**4b. Document třídy**

`modules/economy/codebooks/src/WarehouseDocument.php`:

```php
<?php
declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class WarehouseDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $r = new ValidationResult();
        if (empty($data['code'])) {
            $r->addError('code', 'Kód je povinný', 'required');
        }
        if (empty($data['name'])) {
            $r->addError('name', 'Název je povinný', 'required');
        }
        if (!empty($data['valid_from']) && !empty($data['valid_to'])
            && (string) $data['valid_from'] > (string) $data['valid_to']
        ) {
            $r->addError('valid_to', 'Platnost do nesmí být dříve než platnost od.', 'invalid_range');
        }
        return $r;
    }

    public function beforeSave(array &$data): void
    {
        foreach (['code', 'name'] as $col) {
            if (isset($data[$col])) {
                $data[$col] = trim((string) $data[$col]);
            }
        }
    }
}
```

`modules/economy/codebooks/src/CostCenterDocument.php` — analogicky,
identický kód.

**4c. Viewery**

`modules/economy/codebooks/src/WarehousesViewer.php` — vzor `CashDesksViewer`,
ale jednodušší (bez currency, is_default, bank fields):

- `selectRows`: SELECT `id`, `code`, `name`, `valid_from`, `valid_to`,
  `sort_order`, `docState`, `docStateMain`
- Search columns: `['code', 'name']`
- ORDER BY: `docStateMain ASC, sort_order ASC, name ASC, id ASC`
- `renderRow`: `t1` = name, `i1` = code, `t2` = [validity range pokud je,
  state badge pokud != 10]
- `renderDetail`: jeden tab „Přehled" s properties content (Identifikace:
  code, name; Platnost: valid_from, valid_to; Pořadí: sort_order)

`modules/economy/codebooks/src/CostCentersViewer.php` — analogicky.

**Pozn.:** doc state mapping:

```php
private const STATE_SPAN_CLASS = [
    'concept'   => 'warning',
    'confirmed' => 'primary',
    'done'      => 'success',
    'edit'      => 'warning',
    'archive'   => 'muted',
    'trash'     => 'muted',
    'cancelled' => 'danger',
];
```

stejné jako `CashDesksViewer`.

**4d. Deklarativní formy**

`modules/economy/codebooks/forms/economy_codebooks_warehouses.jsonc`:

```jsonc
{
    "title": "Sklad",
    "titleNew": "Nový sklad",
    "fullSize": false,
    "tabs": [
        {
            "id": "basic",
            "label": "Sklad",
            "elements": [
                {"type": "input", "column": "code", "cols": 1, "required": true},
                {"type": "input", "column": "name", "cols": 3, "required": true},

                {"type": "separator", "label": "Nastavení"},
                {"type": "input", "column": "sort_order", "cols": 1},
                {"type": "input", "column": "valid_from", "cols": 1},
                {"type": "input", "column": "valid_to", "cols": 1}
            ]
        }
    ]
}
```

`modules/economy/codebooks/forms/economy_codebooks_cost_centers.jsonc` —
analogicky („Středisko" / „Nové středisko").

**4e. Registrace v `module.jsonc`**

Do `modules/economy/codebooks/module.jsonc` přidej:

`viewers[]`:
```jsonc
{
    "id": "economy.codebooks.warehouses",
    "name": "Warehouses",
    "name:cs": "Sklady",
    "name:en": "Warehouses",
    "icon": "warehouse",
    "table": "economy_codebooks_warehouses",
    "class": "Shipard\\Module\\Economy\\Codebooks\\WarehousesViewer"
},
{
    "id": "economy.codebooks.costCenters",
    "name": "Cost centers",
    "name:cs": "Střediska",
    "name:en": "Cost centers",
    "icon": "folder",
    "table": "economy_codebooks_cost_centers",
    "class": "Shipard\\Module\\Economy\\Codebooks\\CostCentersViewer"
}
```

`forms[]`:
```jsonc
{ "table": "economy_codebooks_warehouses",   "id": "economy.codebooks.warehouses" },
{ "table": "economy_codebooks_cost_centers", "id": "economy.codebooks.cost_centers" }
```

`documentClasses[]`:
```jsonc
{ "table": "economy_codebooks_warehouses",   "class": "Shipard\\Module\\Economy\\Codebooks\\WarehouseDocument" },
{ "table": "economy_codebooks_cost_centers", "class": "Shipard\\Module\\Economy\\Codebooks\\CostCenterDocument" }
```

**4f. Testy**

`tests/Unit/Module/Economy/Codebooks/WarehouseDocumentTest.php` — vzor
`CashDeskDocumentTest`:

- Chybějící `code`/`name` → required error
- `valid_from > valid_to` → invalid_range error na `valid_to`
- Validní data → no errors
- `beforeSave` trimuje `code`, `name`

`tests/Unit/Module/Economy/Codebooks/CostCenterDocumentTest.php` — analogicky.

**4g. Composer + ds-upgrade**

```bash
composer dump-autoload
bin/shpd-ds ds-upgrade  # vytvoří chybějící sloupce docState/docStateMain a index idx_doc_state
vendor/bin/phpunit tests/Unit/Module/Economy/Codebooks
```

### Krok 5: `NavigationController` — skrývání settings items

V `src/Api/Controller/NavigationController.php`:

1. Před `buildTree()` (nebo na začátku `navigation()`) sesbírej množinu
   skrytých identifikátorů:
   ```php
   $hiddenViewers = [];
   $hiddenTables  = [];
   foreach ($resolvedModules as $module) {
       foreach ($module->settingsItems as $item) {
           if ($item['viewer'] !== null) {
               $hiddenViewers[$item['viewer']] = true;
           }
           if ($item['table'] !== null) {
               $hiddenTables[$item['table']] = true;
           }
       }
   }
   ```
2. Předej tyto množiny do `buildTree()` → `buildTableItems()`
3. V `buildTableItems()`:
   - U vieweru: `if (isset($hiddenViewers[$viewer['id']])) continue;`
     (přeskoč přidání do `$viewerByTable`)
   - U tabulky: `if (isset($hiddenTables[$tableName])) continue;` před
     hlavním foreach
4. Pokud po skrytí je modul prázdný (žádné items), modul se vynechá
   ze stromu (current logika to už dělá — kontroluje `$children === []`)
5. Pokud je celá grupa prázdná, nezobrazuje se (current logika to dělá)

**Důležité:** logika musí být robustní vůči situaci, kdy `settingsItem`
odkazuje na neexistující viewer/tabulku — v tom případě se nic neskryje
(žádný no-op error). Reálně by to ale `ModuleLoader` měl zachytit dřív;
toto je defenzivní.

Ověř ručně přes UI:

- Hlavní sidebar: Pokladny, Bankovní spojení, Fiskální období, Registrace
  DPH, Sklady, Střediska, Druhy položek, Měrné jednotky **mizí**
- Sidebar Nastavení: ty samé položky se objevují v odpovídajících sekcích
- Skupina „Ekonomika" v hlavním sidebaru zůstává (kvůli `economy.items`
  — katalog položek), ale obsahuje **jen** položky, které nejsou v
  Nastavení

### Krok 6: Frontend — `navigation.svelte.js` mode

V `frontend/src/stores/navigation.svelte.js`:

```js
// Navigation store — manages navigation mode and the active item per mode.
// Modes: 'app' | 'settings'.
// Each mode remembers its own activeItem so switching app→settings→app
// returns the user to where they were.

let mode = $state('app');
let appActiveItem      = $state(null);
let settingsActiveItem = $state(null);

function navigate(item) {
  const normalized = {
    id: item.id,
    label: item.label,
    type: item.type,
    table: item.table,
    viewerId: item.viewerId,
    filter: item.filter ?? null,
  };
  if (mode === 'settings') {
    settingsActiveItem = normalized;
  } else {
    appActiveItem = normalized;
  }
}

function enterSettings() {
  mode = 'settings';
}

function exitSettings() {
  mode = 'app';
}

export const navigationStore = {
  get mode()        { return mode; },
  get activeItem()  { return mode === 'settings' ? settingsActiveItem : appActiveItem; },
  get activeId()    { const it = mode === 'settings' ? settingsActiveItem : appActiveItem; return it?.id ?? null; },
  navigate,
  enterSettings,
  exitSettings,
};
```

### Krok 7: Frontend — `Sidebar.svelte` mode-aware

V `frontend/src/components/layout/Sidebar.svelte`:

**7a. Reaktivní načítání podle módu**

Aktuální `onMount` načítá z `/_ui/navigation`. Změň na `$effect`, který
sleduje `navigationStore.mode`:

```js
import { navigationStore } from '../../stores/navigation.svelte.js';

let navTree = $state([]);
let loading = $state(true);
let error   = $state(null);

$effect(() => {
  const url = navigationStore.mode === 'settings'
    ? '/_ui/settings/navigation'
    : '/_ui/navigation';

  loading = true;
  error   = null;
  navTree = [];

  (async () => {
    try {
      const response = await get(url);
      if (response === null) { error = 'Nepřihlášen'; return; }
      if (!response.success) { error = response.error?.message ?? 'Nepodařilo se načíst navigaci'; return; }
      navTree = response.data;
      expanded = new Set(navTree.map(g => g.id));
    } catch {
      error = 'Nepodařilo se načíst navigaci';
    } finally {
      loading = false;
    }
  })();
});
```

(`onMount` nahraď tímto `$effect` — efekt se sám spustí při prvním
mountu i při změně `mode`.)

**Důležité:** `$effect` nesmí synchronně číst `$state` proměnné, které
nemají být sledovány jako závislosti — fetch funkce přijímá explicitně
URL z `navigationStore.mode`. Viz `docs/frontend.md` § 9 — Konvence Svelte.

**7b. Tlačítko „← Zpět do aplikace" v hlavičce**

V `<div class="shpd-sidebar__header">` (po sekci s logem a toggle
button) přidej podmíněně:

```svelte
{#if navigationStore.mode === 'settings' && expanded_sidebar}
  <button class="shpd-sidebar__back" onclick={() => navigationStore.exitSettings()}>
    <Icon icon={iconChevronLeft} size="sm" />
    <span>Zpět do aplikace</span>
  </button>
{/if}
```

Pozn.: hlavička sidebaru aktuálně obsahuje logo + toggle. Tlačítko
„Zpět do aplikace" je samostatný element pod hlavičkou (nový blok
`<div class="shpd-sidebar__back-bar">`), ne uvnitř existujícího
`__header`. Důvod: chceme, aby logo a toggle zůstávaly konzistentní
napříč módy, a jen pod nimi se objevoval / mizel back button.

Reálná struktura:

```svelte
<nav class="shpd-sidebar" ...>
  <div class="shpd-sidebar__header">
    {#if expanded_sidebar}
      <span class="shpd-sidebar__logo">Shipard</span>
    {/if}
    <button class="shpd-sidebar__toggle" ...>...</button>
  </div>

  {#if navigationStore.mode === 'settings' && expanded_sidebar}
    <div class="shpd-sidebar__back-bar">
      <button class="shpd-sidebar__back-button" onclick={() => navigationStore.exitSettings()}>
        <Icon icon={iconChevronLeft} size="sm" />
        <span>Zpět do aplikace</span>
      </button>
    </div>
  {/if}

  <div class="shpd-sidebar__nav">
    ...
  </div>

  <div class="shpd-sidebar__footer" ...>...</div>
</nav>
```

CSS pro back-bar — vzhled jako menu item, ale s borderem dole:

```css
.shpd-sidebar__back-bar {
  padding: var(--shpd-space-sm);
  border-bottom: 1px solid var(--shpd-color-bg-sidebar-border);
}
.shpd-sidebar__back-button {
  display: flex;
  align-items: center;
  gap: var(--shpd-space-sm);
  width: 100%;
  padding: var(--shpd-space-xs) var(--shpd-space-sm);
  background: transparent;
  border: none;
  border-radius: var(--shpd-radius-sm);
  color: var(--shpd-color-text-sidebar);
  font-size: var(--shpd-font-size-sm);
  cursor: pointer;
  text-align: left;
  transition: background-color 0.15s;
}
.shpd-sidebar__back-button:hover {
  background-color: var(--shpd-color-bg-sidebar-hover);
}
```

**7c. Položka „Nastavení aplikace" v dropdownu patky**

V dropdown menu (po `Nastavení účtu`, před divider + Vzhled), v režimu
**app**:

```svelte
{#if navigationStore.mode === 'app'}
  <button class="shpd-sidebar__user-menu-item" onclick={handleAppSettings} role="menuitem">
    <Icon icon={iconAppSettings} size="sm" />
    <span>Nastavení aplikace</span>
  </button>
{/if}
```

(V režimu `settings` se položka nezobrazuje — uživatel už tam je.)

```js
function handleAppSettings() {
  closeUserMenu();
  navigationStore.enterSettings();
}
```

Zde **chceme** zavřít menu před změnou módu — viz pasáž v
`docs/frontend.md` § 9 *„Past: zavírání menu z handleru položky uvnitř
menu"*. Ale `enterSettings()` mění reaktivní stav, který zase přepne
sidebar na nový obsah — sidebar se zase neunmountuje, jen se přerenderuje.
Takže detached-element problém nehrozí (sidebar zůstává namountovaný,
mění se jen obsah `__nav` divu). Přesto nech `closeUserMenu()` první
a `enterSettings()` až za ním, aby render flush proběhl správně.

Pokud by to v testu způsobilo flicker / zavření menu po jen půli akce,
fallback je:

```js
function handleAppSettings() {
  navigationStore.enterSettings();
  // menu se přirozeně zavře v $effect na změnu módu (níže)
}

// Při změně módu vždy zavři user menu
$effect(() => {
  void navigationStore.mode;
  closeUserMenu();
});
```

**7d. Ikony**

V `frontend/src/icons.js` přidej:

1. Import:
   ```js
   faGears,
   faCalculator,
   ```
   (pokud `faGears` neexistuje ve verzi FA, použij alternativně `faSliders`
   nebo nech `faGear` — který už je importovaný — pro „Nastavení aplikace"
   stejně jako pro „Nastavení účtu". V tom případě v `Sidebar.svelte`
   použij `iconSettings`.)

2. Export — pro ikonu „Nastavení aplikace" preferovaně samostatnou:
   ```js
   export const iconAppSettings = faGears; // ozubená kola — odlišuje od iconSettings (jedno kolo) pro Nastavení účtu
   export const iconCalculator  = faCalculator;
   ```
   Pokud `faGears` nedostupná, použij `iconSettings` i pro „Nastavení
   aplikace" — vizuální duplicita je akceptovatelná, popis říká kontext.

3. `iconMap`:
   ```js
   'calculator': iconCalculator,
   'app-settings': iconAppSettings,  // pro server-driven případy
   ```

V `Sidebar.svelte` import:
```js
import { iconAppSettings } from '../../icons.js';
```

**7e. Build**

```bash
cd frontend && npm run build 2>&1
```

Musí projít bez warning/error. Otestuj v dev režimu (`npm run dev`):

- Klik v dropdownu na „Nastavení aplikace" → sidebar se přepne, objeví
  se sekce Účetnictví / Sklady / Položky
- Klik na „← Zpět do aplikace" → vrátí se původní sidebar i obsah hlavní
  oblasti (poslední položka před přepnutím)
- Přepínání app ↔ settings ↔ app si pamatuje aktivní položku v každém módu
- Sbalování sidebaru (collapse button) funguje v obou módech

### Krok 8: Documentation

**`docs/frontend.md`:**

Rozšiř sekci **§ 4 Aplikační shell** o krátkou pasáž o módech:

```markdown
### Mode systém — App vs. Settings

Aplikace má dva navigační módy: `'app'` (běžná práce) a `'settings'`
(Nastavení aplikace). Mode drží `navigation.svelte.js` ve `$state`.

- **Vstup do Nastavení**: dropdown v patce sidebaru → položka „Nastavení
  aplikace" → `navigationStore.enterSettings()`
- **Výstup**: tlačítko „← Zpět do aplikace" v hlavičce sidebaru pod
  logem → `navigationStore.exitSettings()`
- **Stav per mode**: každý mode si pamatuje vlastní `activeItem`. Přepnutí
  app→settings→app vrátí uživatele na poslední položku v app módu

Sidebar reaguje na `navigationStore.mode` přes `$effect`:
- `'app'` → načítá z `GET /_ui/navigation`
- `'settings'` → načítá z `GET /_ui/settings/navigation`

V režimu `'settings'` jsou v hlavičce sidebaru navíc tlačítko „Zpět do
aplikace" (pod logem) a v dropdownu patky se skrývá položka „Nastavení
aplikace".

Žádné URL routing — mode se nepamatuje napříč reloady (po F5 se vrátí
do `'app'` módu). Persistence módu je out of scope této fáze.
```

V tabulce konvencí (sekce **§ 9 Konvence — API komunikace**) zmiň
existenci `/_ui/settings/navigation` jako paralelu k `/_ui/navigation`.

V sekci **§ 8 UI API endpointy** přidej:

```markdown
| `GET /_ui/settings/navigation` | Navigační strom režimu Nastavení (sekce + položky podle `settingsItems[]` napříč moduly) |
```

**`CLAUDE.md`:**

Do sekce „### Frontend — ikony" (nebo nová sekce „### Frontend — navigace")
přidej:

```markdown
### Frontend — Settings mód

- Aplikace má dva navigační módy: `app` (běžná práce) a `settings` (Nastavení)
- Mode drží `navigation.svelte.js`, oba módy mají vlastní `activeItem`
- Sidebar mode-aware načítá `/_ui/navigation` (app) nebo `/_ui/settings/navigation` (settings)
- Číselníky určené do Nastavení mají `settingsItems[]` v `module.jsonc`,
  sekce v `modules/install/base/config/settingsSections.jsonc`
- Položky uvedené v `settingsItems[]` se automaticky skrývají z hlavního
  navigačního stromu
```

**`modules/install/base/`** — pokud tu není README.md, není potřeba ho
zakládat. `module.jsonc` se vysvětluje sám.

## Hotovo když

- [ ] `bin/shpd-ds ds-upgrade` projde a doplní chybějící sloupce
  `docState`/`docStateMain` u Skladů a Středisek
- [ ] `vendor/bin/phpunit` všechny testy zelené
- [ ] `cd frontend && npm run build` projde bez warningů
- [ ] V hlavním sidebaru (mode `app`) **nejsou** položky: Pokladny,
  Bankovní spojení, Fiskální období, Registrace DPH, Sklady, Střediska,
  Druhy položek, Měrné jednotky
- [ ] V hlavním sidebaru zůstává: Osoby, Položky (katalog), Pošta a
  ostatní moduly mimo Nastavení
- [ ] V dropdownu v patce sidebaru (v módu `app`) je položka „Nastavení
  aplikace" s ikonou
- [ ] Klik na „Nastavení aplikace" → sidebar přepne, zobrazí se sekce
  Účetnictví / Sklady / Položky s odpovídajícími položkami
- [ ] V hlavičce sidebaru pod logem je v módu `settings` tlačítko
  „← Zpět do aplikace"
- [ ] Klik na „Zpět do aplikace" → návrat do `app` módu, sidebar i
  ContentArea ukazuje stav před přepnutím
- [ ] Přepnutí app→settings→app si pamatuje activeItem v obou módech
  nezávisle
- [ ] V Nastavení lze otevřít všechny číselníky a editovat záznamy
  (modal formy fungují pro Sklady, Střediska, Druhy položek, Měrné
  jednotky stejně jako pro ostatní)
- [ ] Sklady a Střediska mají docStates lifecycle (Koncept→V pořádku
  →V archívu→Smazáno) a tab bar (Aktivní/Archív/Vše/Koš) ve vieweru
- [ ] `curl /_ui/settings/navigation` vrátí strom 3 sekcí s odpovídajícími
  položkami; sekce bez items se vynechá
- [ ] `curl /_ui/navigation` neobsahuje skryté viewery
- [ ] Sbalování sidebaru funguje v obou módech (collapse + hover expand)
- [ ] `docs/frontend.md` a `CLAUDE.md` aktualizované

## Rozhodnutí k designu (potvrzená)

✓ **Žádné URL routing** — drží se konvence dokumentovaná v
`docs/frontend.md` § 1; mode v `$state`, persistence napříč reloady
out of scope

✓ **Vstup z patky, výstup z hlavičky** — vstup do Nastavení dropdownem
v patce (nepatří mezi pracovní položky), výstup tlačítkem v hlavičce
(jasné, vždy viditelné)

✓ **Mode-aware sidebar místo dvou komponent** — jeden `Sidebar.svelte`
s `$effect` na `mode` je jednodušší než duplikovat komponentu;
struktura stromu je pro oba módy stejná (sekce/grupy + items)

✓ **Vizuální odlišení režimu — jen podle URL a obsahu** — žádná jiná
barva pozadí ani hlavička. Tlačítko „Zpět do aplikace" + obsah menu
stačí

✓ **Per-mode activeItem** — uživatel po vrácení vidí, co měl otevřené;
důležité hlavně pro Nastavení (zalistuje pár číselníků a vrátí se k
práci, nesmí ztratit kontext)

✓ **Definice menu Nastavení per modul přes `settingsItems[]`** v
`module.jsonc` — drží se filozofie projektu (server-driven UI, per-module
definice). Globální sekce v `install.base/config/settingsSections.jsonc`

✓ **`install.base` jako vlastník `settingsSections`** — sekce jsou
globální (sdíleí všemi DS), `install.base` je instalační meta-modul,
přes který jdou všechny DS

✓ **Skrývání položek z hlavního menu — implicitní pravidlo** — co je
v `settingsItems[]`, mizí z hlavní navigace. Žádný extra flag
`hideFromMainNav`

✓ **Sklady a Střediska — vlastní viewery** — konzistence s ostatními
číselníky v `economy.codebooks` (CashDesks, BankAccounts, FiscalYears,
VatRegistrations); ne `TableBrowser` ad hoc

✓ **`economy.items` (katalog) zůstává v hlavním menu** — to je hlavní
pracovní prostor, ne číselník; jen `economy.items.kinds` (Druhy
položek) jde do Nastavení

✓ **`core.units` (Měrné jednotky) jde do Nastavení** — uživatel je sice
nastavuje občas (zboží), ale primárně je to číselník, ne pracovní
agenda

✓ **Sklady/Střediska mají docStates** — analogicky s CashDesks; lifecycle
Koncept→V pořádku→V archívu→Smazáno dává smysl (sklad může být zrušený,
ale historické doklady na něj odkazují)

✓ **Sekce bez items se vynechává** — neukazujeme prázdné nadpisy
(uživatelská hygiena)

✓ **Empty state pro celé Nastavení** (žádný modul nemá `settingsItems`) —
prázdné pole, klient zobrazí „Vyberte položku v menu" jako jinde

## Konvence a upozornění

- **Jazyk**: UI texty čeština, kód a komentáře angličtina
- **Vícejazyčnost**: každé `name` v `settingsSections.jsonc` má `:cs` a `:en`
- **Svelte 5 runes**: `$state`, `$derived`, `$effect`. Žádné `onMount` pro
  reaktivní načítání podle stavu — to je práce pro `$effect`
- **`$effect` a fetch**: nečíst `$state` z těla efektu jako závislost,
  pokud má smysl trackovat — fetch funkci předej hodnotu explicitně
  (viz `docs/frontend.md` § 9 — Konvence Svelte)
- **PHP 8.5+ strict_types**, readonly properties kde možné
- **Composer autoload**: po vytvoření nových src souborů
  `composer dump-autoload`
- **Před patch_file**: vždy nejdřív `read_file` pro celý soubor, hledej
  přesnou whitespace shodu (viz `CLAUDE.md` poznámky)
- **Vícenásobné non-overlapping edity** v jednom `patch_file` jsou
  spolehlivé; overlapping edity ne
- **Verifikace**: `cd frontend && npm run build 2>&1` po každé změně
  v `frontend/`; `vendor/bin/phpunit 2>&1` po PHP změnách
- **Po každém kroku** ověř ručně přes UI, ať se chyby nehromadí

## Doporučené pořadí implementace

Krok 1 (data — sekce + settingsItems v module.jsonc) → Krok 2
(`ModuleDefinition` + test) → ds-upgrade ověření že parser funguje →
Krok 3 (`SettingsController` + endpoint) → curl ověření → Krok 4
(Sklady/Střediska — viewery, Documenty, formy, registrace) → ds-upgrade
+ phpunit + curl ověření → Krok 5 (`NavigationController` skrývání) →
ověření přes curl (skryté items v hlavní navigaci, viditelné v
settings navigaci) → Krok 6 (`navigation.svelte.js` mode) → Krok 7
(`Sidebar.svelte` mode-aware + ikony) → frontend build + manuální
test celého toku → Krok 8 (docs).
