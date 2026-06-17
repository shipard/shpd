# Task: Sémantické sekce sidebaru (Nákup / Prodej / Účtárna)

## Status / Cíl

Dnes je navigační strom sidebaru generovaný **mechanicky podle prefixu
module ID** (`NavigationController::buildTree`): skupina = první segment
ID modulu (`docs.*` → „Doklady", `economy.*` → „Ekonomika", `core.*` →
„Systém"…), labely v konstantě `GROUP_LABELS`. Sekce sidebaru tak kopírují
technickou strukturu modulů, ne **význam** pro uživatele.

Cíl: odpojit sekce sidebaru od struktury modulů a postavit je na
**sémantických sekcích** definovaných v cfgItem (analogicky k existujícímu
`global.settingsSections`). Každý viewer / table item řekne, do které
sekce patří (`navSection`) a v jakém pořadí (`navOrder`). `NavigationController`
pak seskupuje podle `navSection`, ne podle prefixu ID.

Cílové uspořádání sidebaru:

```
Dashboard           ← root-level leaf (bez sekce)
Chat                ← root-level leaf
Došlá pošta         ← root-level leaf  (NOVĚ nahoře)
Úkoly               ← root-level leaf  (NOVĚ nahoře)

Základní
  Osoby
  Položky
Nákup
  Faktury přijaté
Prodej
  Faktury vydané
Účtárna
  Účetní deník
  Účtový rozvrh
  Bankovní transakce
  Bankovní výpisy
Systém
  Upozornění
```

Po dokončení platí:

- cfgItem `global.navSections` (`modules/install/base/config/navSections.jsonc`)
  definuje sekce sidebaru (`id`, `name`/`name:cs`/`name:en`, `icon`, `order`).
- Viewery (v `module.jsonc` `viewers[]`) a tabulky (`*.jsonc`) nesou nově
  `navSection` (id sekce z navSections) a volitelně `navOrder` (int).
- Sentinel `navSection: "_top"` = root-level leaf nad sekcemi (Došlá
  pošta, Úkoly). Dashboard a Chat zůstávají hardcoded root leaves jako
  dnes (nejsou to viewery).
- `NavigationController::buildTree` seskupuje podle `navSection`,
  řadí sekce dle `navSections.order`, položky dle `navOrder`.
- Co nemá `navSection` → fallback do sekce `system` (nic nezmizí, kdyby
  přibyl nový viewer bez konfigurace).
- `GROUP_LABELS` konstanta a seskupování dle prefixu ID jsou pryč.
- API tvar odpovědi (`id`/`label`/`children`/`type`/`icon`/`viewerId`/
  `table`) **beze změny** → `Sidebar.svelte` se nemění.
- Doklady (`docs.core.heads`) skryté z navigace (`hideFromNavigation`
  na vieweru — viz Krok 4; čtení hideFromNavigation z viewer deklarace
  je nové, dnes se čte jen z table `.jsonc`).
- Přesuny do Nastavení aplikace (settingsItems): Extrahované dokumenty
  → Ostatní/Pošta; Zprávy chatu → Ostatní/Chat; Období DPH → Účetnictví.

## Návaznost

- Závisí na: stávajícím settings-section mechanismu (`global.settingsSections`
  cfgItem, `settingsItems[]` na modulech, `SettingsController` navigace).
  Žádná schéma změna DB, žádná nová tabulka.
- Sousední vzory: `modules/install/base/config/settingsSections.jsonc`
  (přesná struktura cfgItem sekcí — `navSections` ho kopíruje),
  `docs/modules.md` sekce o `settingsItems`/`settingsSections`,
  `docs/frontend.md` sekce „Sidebar — dynamická navigace ze serveru".
- `NavigationController` cross-propagace skrytých viewerů/tabulek
  (viewer ↔ table) zůstává — důležité, protože Faktury přijaté/vydané
  i souhrnné Doklady **sdílejí tabulku `docs_core_heads`** (skrýt jen
  souhrnný viewer, ne tabulku).

## Před implementací přečti

- `src/Api/Controller/NavigationController.php` — **celý** (jádro změny:
  `navigation()`, `buildTree()`, `buildTableItems()`, `GROUP_LABELS`,
  `resolveGroupLabel()`, hidden-state cross-propagace, root-level
  unshift Dashboard/Chat, `isTableHiddenFromNavigation()`).
- `modules/install/base/config/settingsSections.jsonc` — vzor struktury
  cfgItem sekcí (id/name/name:cs/name:en/icon/order/subsections).
- `src/Core/Module/ModuleDefinition.php` — jak se parsují `viewers[]`
  a `settingsItems[]`; kam se uloží nová pole `navSection`/`navOrder`
  (ověřit, zda viewer pole projdou whitelistem nebo se ztratí).
- `src/Core/Module/ModuleLoader.php` + `ModuleResolver.php` — jak se
  čtou `module.jsonc` do `ModuleDefinition` (jestli `viewers` nese celé
  pole nebo jen vybrané klíče — kvůli `navSection`/`navOrder`).
- `src/Core/I18n/ConfigLocalizer.php` — lokalizace `name:cs`/`name:en`
  (navSections labely projdou stejně).
- `src/Core/Utils/JsoncParser.php` — jak se čtou cfgItem soubory
  (`global.*`); kde se registruje `global.navSections` (najít, jak je
  načítán `global.settingsSections`, a udělat totéž).
- `frontend/src/components/layout/Sidebar.svelte` — **jen pro ověření**,
  že `flattenLeaves` (root-level leaf = má `type`) a render skupin
  (má `children`) zůstanou funkční beze změny. NEMĚNIT.
- `docs/frontend.md` — sekce „Sidebar — struktura" + „dynamická
  navigace ze serveru" (aktualizovat).
- `docs/modules.md` — sekce settingsItems/settingsSections (přidat
  navSections + navSection).

## Scope

### V rozsahu

- **cfgItem `global.navSections`** — nový soubor
  `modules/install/base/config/navSections.jsonc` (sekce sidebaru).
  Registrace v cfgItem systému stejně jako `global.settingsSections`.
- **`navSection` + `navOrder` na viewerech** — doplnit do `viewers[]`
  v dotčených `module.jsonc`. Ověřit, že `ModuleDefinition`/`ModuleLoader`
  tato pole nese až do controlleru (jinak rozšířit parsování).
- **`navSection` + `navOrder` na tabulkách** — pro generické table
  items (např. Osoby je viewer, ale kdyby něco zůstalo jako table).
  V tomto zadání jsou všechny cílové položky viewery → primárně viewer
  cesta; table cesta jen pokud `buildTableItems` fallback table items
  reálně produkuje (ověřit).
- **`NavigationController` přepis `buildTree`** — seskupování podle
  `navSection` místo prefixu ID; sentinel `_top` → root-level leaf;
  fallback bez navSection → `system`; řazení sekcí dle navSections.order,
  položek dle navOrder; načtení navSections cfgItem + lokalizace labelů.
  Odstranit `GROUP_LABELS` + `resolveGroupLabel` (nahradit navSections
  lookupem).
- **`hideFromNavigation` na vieweru** — rozšířit `NavigationController`,
  ať čte `hideFromNavigation` i z viewer deklarace (dnes jen z table
  `.jsonc`). Skrýt `docs.core.heads`.
- **Přesuny do settings** (settingsItems):
  - `core_mail_extracted_documents` → `{ table, section: other,
    subsection: other.mail, order: 40 }` (`core/mail/module.jsonc`).
    POZN: order 40 je dnes obsazen `core.ai.backends` v jiném modulu
    (core/ai) — ověřit kolizi order v rámci subsekce; přečíslovat dle
    potřeby (order je jen v rámci subsekce, napříč moduly se slučuje).
  - `core_chat_messages` → `{ table, section: other, subsection:
    other.chat, order: 20 }` (`core/chat/module.jsonc`; conversations
    už tam jsou s order 10).
  - `economy_codebooks_vat_periods` → `{ viewer/table, section:
    accounting }` (`economy/codebooks/module.jsonc`). vat_periods nemá
    viewer → `{ table: economy_codebooks_vat_periods, section: accounting }`.
- **Dokumentace:** `docs/frontend.md` (sekce sidebaru — navSections,
  navSection, _top sentinel, fallback), `docs/modules.md` (navSections
  cfgItem + navSection pole), `CLAUDE.md` (zmínka o navSections jako
  zdroji sekcí sidebaru).
- **Testy:** `NavigationControllerTest` — sekce dle navSections, řazení,
  _top root leaves, fallback do system, skrytí docs.core.heads, přesunuté
  položky nejsou v nav.

### Mimo rozsah (budoucí)

- **Per-DS / per-user customizace pořadí sekcí** — sekce jsou zatím
  globální v cfgItem.
- **Drag&drop / UI editor navigace** — sekce se konfigurují v JSONC.
- **Ikony sekcí v sidebaru** — `navSections` nese `icon`, ale jestli ho
  Sidebar u group headerů zobrazuje, je věc frontendu; pokud dnes group
  header ikonu nemá, needitovat frontend (mimo rozsah).
- **Podskupiny v sémantických sekcích** (sub-group nesting) — cílové
  uspořádání je plché (sekce → leaves). `buildTree` dnes umí sub-group
  per modul; po přechodu na navSection se sub-groups neřeší (všechny
  cílové sekce jsou ploché). Ponechat kód schopný plochého výstupu.

## Datový tok

```
cfgItem global.navSections (modules/install/base/config/navSections.jsonc)
   sections[]: {id, name:cs/en, icon, order}
   → načte NavigationController (jako dnes settingsSections)

module.jsonc viewers[].navSection / navOrder
module.jsonc/tables *.jsonc navSection / navOrder
   → ModuleDefinition → NavigationController::buildTree

buildTree:
   pro každý viewer/table item:
     sec = item.navSection ?? 'system'      (fallback)
     pokud sec == '_top': přidej do topLeaves (root-level leaf)
     jinak: bucket[sec][] = item  (řazeno dle navOrder)
   sekce seřaď dle navSections.order
   výstup: [ ...root leaves (Dashboard, Chat, _top items), ...sekce ]

GET /_ui/navigation → stejný JSON tvar jako dnes (Sidebar beze změny)
```

Cílové `navSection` mapování:

| Viewer / table | navSection | navOrder |
|---|---|---|
| `base.persons` (Osoby) | `basic` | 10 |
| `economy.items` (Položky) | `basic` | 20 |
| `docs.invoicesIn.heads` (Faktury přijaté) | `purchase` | 10 |
| `docs.invoicesOut.heads` (Faktury vydané) | `sales` | 10 |
| `economy.accounting.journal` (Účetní deník) | `accounting` | 10 |
| `economy.accounting.accounts` (Účtový rozvrh) | `accounting` | 20 |
| `economy.bank.transactions` (Bank. transakce) | `accounting` | 30 |
| `economy.bank.statements` (Bank. výpisy) | `accounting` | 40 |
| `core.alerts.alerts` (Upozornění) | `system` | 10 |
| `tasks.core` (Úkoly) | `_top` | (pořadí 40 — viz níže) |
| `core.mail.incoming` (Došlá pošta) | `_top` | (pořadí 30) |
| `docs.core.heads` (Doklady) | — | `hideFromNavigation: true` |

Pořadí nahoře bez sekce: **Dashboard → Chat → Došlá pošta → Úkoly**.
Dashboard+Chat jsou hardcoded unshift (dnes). Došlá pošta + Úkoly jsou
`_top` viewery — musí se vložit **za** Chat a **před** sekce, ve správném
pořadí. Řešení: `_top` items seřaď dle `navOrder` (Došlá pošta 30, Úkoly
40) a vlož mezi hardcoded leaves a sekce. (Dashboard/Chat ponech jako
unshift na začátek; nebo je rovněž převeď na _top s navOrder 10/20 — viz
Rozhodnutí.)

## Implementace

### Krok 0 — Průzkum (před psaním kódu)

Ověřit dvě klíčové neznámé:

1. **Nese `ModuleDefinition` celé viewer pole, nebo jen vybrané klíče?**
   Pokud `ModuleLoader` při parsování `viewers[]` zachová jen
   `id`/`name`/`icon`/`table`/`class`, pak `navSection`/`navOrder`
   propadnou a je třeba rozšířit parsování (nebo číst raw `module.jsonc`
   v controlleru, jako se čte table meta). Zjistit a podle toho zvolit:
   - (a) `ModuleDefinition.viewers` nese celé asociativní pole → stačí
     číst `$viewer['navSection']` v controlleru.
   - (b) nese ořezané → rozšířit whitelist/parsování o `navSection`,
     `navOrder`, `hideFromNavigation`.

2. **Jak je registrován `global.settingsSections` cfgItem** a jak ho
   čte kód (SettingsController?) — `navSections` se zaregistruje +
   načte stejnou cestou. Najít čtení settingsSections, zrcadlit.

`grep -rn "settingsSections\|global\." src/ modules/install/base/config/`
+ přečíst `ModuleDefinition.php` a `ModuleLoader.php` kolem `viewers`.

### Krok 1 — cfgItem `global.navSections`

Nový soubor `modules/install/base/config/navSections.jsonc`:

```jsonc
{
    "sections": [
        {
            "id": "basic",
            "name": "Basic",
            "name:cs": "Základní",
            "name:en": "Basic",
            "icon": "folder",
            "order": 10
        },
        {
            "id": "purchase",
            "name": "Purchase",
            "name:cs": "Nákup",
            "name:en": "Purchase",
            "icon": "cart-down",
            "order": 20
        },
        {
            "id": "sales",
            "name": "Sales",
            "name:cs": "Prodej",
            "name:en": "Sales",
            "icon": "cart-up",
            "order": 30
        },
        {
            "id": "accounting",
            "name": "Accounting",
            "name:cs": "Účtárna",
            "name:en": "Accounting",
            "icon": "calculator",
            "order": 40
        },
        {
            "id": "system",
            "name": "System",
            "name:cs": "Systém",
            "name:en": "System",
            "icon": "settings",
            "order": 50
        }
    ]
}
```

Registrace v cfgItem systému (kde se registruje `global.settingsSections`).
POZN: ikony (`cart-down`, `cart-up`) ověřit v ikonové sadě frontendu;
když chybí, zvolit existující (fallback) nebo nechat bez ikony (group
header dnes ikonu nemusí zobrazovat — needitovat frontend). Ikony sekcí
jsou nice-to-have; primární je label + pořadí.

### Krok 2 — `navSection`/`navOrder` na viewery

Do `viewers[]` dotčených `module.jsonc` přidat `navSection` a `navOrder`.
Příklad (`docs/invoicesIn/module.jsonc`):

```jsonc
"viewers": [
    {
        "id": "docs.invoicesIn.heads",
        "name": "Received invoices",
        "name:cs": "Faktury přijaté",
        "name:en": "Received invoices",
        "icon": "invoice-in",
        "table": "docs_core_heads",
        "class": "...",
        "navSection": "purchase",
        "navOrder": 10
    }
]
```

Dotčené moduly + hodnoty viz tabulka „navSection mapování" výše:

- `base/persons` → `base.persons`: `navSection: basic, navOrder: 10`
- `economy/items` → `economy.items`: `navSection: basic, navOrder: 20`
  (POZOR: `economy.items.kinds` je už v settingsItems → needitovat,
  zůstává skrytý)
- `docs/invoicesIn` → `docs.invoicesIn.heads`: `purchase, 10`
- `docs/invoicesOut` → `docs.invoicesOut.heads`: `sales, 10`
- `economy/accounting` → `economy.accounting.journal`: `accounting, 10`;
  `economy.accounting.accounts`: `accounting, 20`
- `economy/bank` → `economy.bank.transactions`: `accounting, 30`;
  `economy.bank.statements`: `accounting, 40`
- `core/alerts` → `core.alerts.alerts`: `system, 10`
- `core/mail` → `core.mail.incoming`: `navSection: "_top", navOrder: 30`
- `tasks/core` → `tasks.core`: `navSection: "_top", navOrder: 40`

POZN diakritika: edity `module.jsonc` obsahují `name:cs` s diakritikou
→ `patch_file` nespolehlivý. Přidávané klíče (`navSection`/`navOrder`)
jsou ASCII, ale vkládají se do bloku s diakritikou. Bezpečně: Python
heredoc workaround (vkládat za `"class": "..."` řádek konkrétního
vieweru) nebo `write_file` celého `module.jsonc`. Po každé editaci
`grep -n "navSection" <file>` ověřit.

### Krok 3 — `NavigationController::buildTree` přepis

Nahradit prefix-grouping za navSection-grouping.

**3a.** Načíst navSections cfgItem (cesta jako settingsSections; přes
`JsoncParser` + `ConfigLocalizer::localize`). Připravit lookup
`id → {label, order}` + seznam seřazený dle `order`.

**3b.** Sebrat **všechny** navigační itemy (viewery + fallback table
items) napříč moduly do plochého seznamu — dnešní `buildTableItems`
produkuje itemy per modul; potřebujeme je s jejich `navSection`/`navOrder`.
Rozšířit item builder, ať ke každému itemu připojí `navSection`
(z viewer deklarace / table meta, fallback `system`) a `navOrder`
(fallback velké číslo, ať nezařazené padají na konec sekce).

**3c.** Roztřídit:

```php
$topLeaves = [];      // navSection === '_top'
$buckets   = [];      // sec => items[]
foreach ($allItems as $item) {
    $sec = $item['navSection'] ?? 'system';
    if ($sec === '_top') { $topLeaves[] = $item; continue; }
    if (!isset($sectionLookup[$sec])) { $sec = 'system'; } // neznámá sekce → fallback
    $buckets[$sec][] = $item;
}
// seřaď topLeaves dle navOrder
// seřaď každý bucket dle navOrder
```

**3d.** Sestavit strom v pořadí navSections.order; vynechat prázdné
sekce:

```php
$tree = [];
foreach ($sectionsSorted as $sec) {
    if (empty($buckets[$sec['id']])) continue;
    $tree[] = [
        'id'       => $sec['id'],
        'label'    => $sec['label'],   // lokalizováno
        'children' => $buckets[$sec['id']],  // bez navSection/navOrder klíčů ve výstupu
    ];
}
```

POZN: z výstupních itemů odstranit interní klíče `navSection`/`navOrder`
(nepatří do API; Sidebar je nečte, ale držet payload čistý).

**3e.** Root-level leaves. Dnes `navigation()` dělá `array_unshift`
Dashboard a Chat (v tomto pořadí: Chat unshift, pak Dashboard unshift →
výsledek Dashboard, Chat, …). Nově vložit `_top` leaves **za** Chat
a **před** sekce:

```php
// $tree = sekce (z buildTree)
// $topLeaves = _top viewery seřazené dle navOrder (Došlá pošta 30, Úkoly 40)
$groups = array_merge($topLeaves, $tree);
array_unshift($groups, $chatLeaf);       // Chat
array_unshift($groups, $dashboardLeaf);  // Dashboard
// výsledek: Dashboard, Chat, Došlá pošta, Úkoly, <sekce...>
```

(Alternativa dle Rozhodnutí: Dashboard/Chat převést také na `_top`
s navOrder 10/20 a vše řešit jednou cestou. Pokud zvoleno, Dashboard/Chat
nejsou viewery → musely by se injektovat jako pseudo-_top itemy. Pro
menší změnu doporučeno ponechat Dashboard/Chat hardcoded unshift a jen
přidat $topLeaves mezi ně a sekce — viz Rozhodnutí.)

**3f.** Odstranit `GROUP_LABELS` konstantu a `resolveGroupLabel()`
(nahrazeno navSections lookupem). `localizeModuleName()` — pokud už
nikde nevolané (sub-group per modul label), odstranit; jinak ponechat.

### Krok 4 — `hideFromNavigation` z viewer deklarace + skrýt Doklady

**4a.** Rozšířit hidden-collection v `navigation()`: kromě
`isTableHiddenFromNavigation` (table `.jsonc`) číst i `hideFromNavigation`
z viewer deklarace:

```php
foreach ($resolvedModules as $module) {
    foreach ($module->viewers as $viewer) {
        if (!empty($viewer['hideFromNavigation'])) {
            $hiddenViewers[$viewer['id']] = true;
        }
    }
}
```

(Umístit před cross-propagaci, ať se skrytí přenese na sdílenou tabulku
správně — pozor: `docs.core.heads` sdílí `docs_core_heads` s fakturami.
Cross-propagace dnes skrytý viewer → skryje tabulku. To by skrylo i
faktury! **Ověřit a obejít:** skrytí `docs.core.heads` NESMÍ skrýt
`docs_core_heads`, protože tu zobrazují invoicesIn/Out viewery. Dnešní
cross-propagace `hiddenViewers → hiddenTables` je nebezpečná pro sdílené
tabulky. Řešení: cross-propagovat viewer→table jen když je tabulka
„vlastněná" jen tím viewerem, NEBO vynechat docs_core_heads z table
cross-propagace. Bezpečně: propagovat table→viewer (skrytá tabulka
skryje viewery) ale NE viewer→table pro sdílené tabulky. Při implementaci
ověřit, že po skrytí docs.core.heads zůstanou Faktury přijaté/vydané
viditelné.)

**4b.** `docs/core/module.jsonc` — `docs.core.heads` viewer přidat
`"hideFromNavigation": true`. (numberSeries už je v settingsItems.)

### Krok 5 — Přesuny do settingsItems

**5a.** `core/mail/module.jsonc` — do `settingsItems` přidat:

```jsonc
{ "table": "core_mail_extracted_documents", "section": "other", "subsection": "other.mail", "order": 40 }
```

POZN: order napříč moduly ve stejné subsekci se slučuje a řadí; dnes
`core.ai.backends` má order 40 (jiný modul). Zvolit volný order
(např. 35 nebo 45), ať nekoliduje. Ověřit finální pořadí v Nastavení.

**5b.** `core/chat/module.jsonc` — do `settingsItems` přidat:

```jsonc
{ "table": "core_chat_messages", "section": "other", "subsection": "other.chat", "order": 20 }
```

**5c.** `economy/codebooks/module.jsonc` — do `settingsItems` přidat:

```jsonc
{ "table": "economy_codebooks_vat_periods", "section": "accounting" }
```

POZN: ověřit, že `economy_codebooks_vat_periods` se dnes v nav reálně
zobrazuje jako table item (nemá viewer ani hideFromNavigation) — po
přidání settingsItem ho hidden-collection krok (1) v controlleru skryje
z nav a settings ho zobrazí v Účetnictví.

### Krok 6 — Dokumentace

- `docs/frontend.md` — sekce „Sidebar — dynamická navigace ze serveru":
  popsat navSections cfgItem jako zdroj sekcí, `navSection`/`navOrder`
  na vieverech, sentinel `_top`, fallback `system`. Zrušit zmínku o
  seskupování dle prefixu ID.
- `docs/modules.md` — sekce settingsItems/settingsSections: přidat
  `global.navSections` (struktura jako settingsSections) + `navSection`/
  `navOrder` pole na vieweru/tabulce. Zmínit `hideFromNavigation` i na
  vieweru.
- `CLAUDE.md` — krátká zmínka: sekce sidebaru pochází z
  `global.navSections`, ne z prefixu modulu.

---

## Akceptační kritéria (Hotovo když)

- [ ] `vendor/bin/phpunit --filter NavigationControllerTest` zelené
      (+ nové testy: sekce dle navSections, řazení, _top leaves,
      fallback system, skrytí docs.core.heads se zachováním faktur,
      přesunuté položky nejsou v nav)
- [ ] `php -l` čisté na dotčených PHP souborech
- [ ] `cd frontend && timeout 90 npm run build` — bez chyb (frontend se
      needitoval, jen ověření že API tvar sedí)
- [ ] `GET /_ui/navigation` vrací uspořádání: Dashboard → Chat → Došlá
      pošta → Úkoly → Základní (Osoby, Položky) → Nákup (Faktury přijaté)
      → Prodej (Faktury vydané) → Účtárna (Účetní deník, Účtový rozvrh,
      Bankovní transakce, Bankovní výpisy) → Systém (Upozornění)
- [ ] Doklady (`docs.core.heads`) nejsou v navigaci; Faktury přijaté
      i vydané **zůstávají** viditelné (sdílená tabulka nepoškozena)
- [ ] Extrahované dokumenty jsou v Nastavení → Ostatní → Pošta;
      Zprávy chatu v Nastavení → Ostatní → Chat; Období DPH v Nastavení
      → Účetnictví; žádná z nich není v hlavní navigaci
- [ ] Nezařazený viewer (bez navSection) by spadl do Systém (otestovat
      uměle / unit test)
- [ ] Sidebar.svelte se nezměnil; sbalený stav (flattenLeaves) funguje
      (root-level leaves i sekce)
- [ ] `docs/frontend.md`, `docs/modules.md`, `CLAUDE.md` aktualizované
- [ ] `tasks/README.md` — task přesunout z Aktivní do hotových
      (navazující session)

---

## Rozhodnutí k designu (potvrzená s Annou)

- ✓ **Sekce sidebaru z cfgItem `global.navSections`**, ne z prefixu
  module ID. Analogie k `global.settingsSections`.
- ✓ **`navSection` + `navOrder` na vieweru/tabulce** určují zařazení
  a pořadí. Odpojeno od struktury modulů.
- ✓ **Cílové sekce:** Základní → Nákup → Prodej → Účtárna → Systém
  (v tomto pořadí). Nahoře bez sekce: Dashboard → Chat → Došlá pošta
  → Úkoly.
- ✓ **Účtárna obsahuje** Účetní deník, Účtový rozvrh, Bankovní
  transakce, Bankovní výpisy (banka oba viewery zvlášť, ne podskupina).
- ✓ **Základní obsahuje** Osoby + Položky (Druhy položek už jsou
  v Nastavení, Osoby zůstávají zde dle původního „Základní/Systém").
- ✓ **Upozornění** → sekce Systém (bez podsekce).
- ✓ **Fallback bez navSection → Systém** (bezpečné, nic nezmizí).
- ✓ **Skrýt Doklady** (`docs.core.heads`) přes `hideFromNavigation` na
  vieweru — bez dopadu na faktury sdílející tabulku.
- ✓ **Přesun do Nastavení:** Extrahované dokumenty
  (`core_mail_extracted_documents`) → Ostatní/Pošta; Zprávy chatu
  (`core_chat_messages`) → Ostatní/Chat; Období DPH
  (`economy_codebooks_vat_periods`) → Účetnictví.
- ✓ **Dashboard/Chat zůstávají hardcoded root leaves**, `_top` viewery
  (Došlá pošta, Úkoly) se vloží za ně a před sekce. (Pokud při
  implementaci vyjde čistší převést i Dashboard/Chat na _top, je to
  v pořádku — výsledné pořadí musí sedět.)
- ✓ **Frontend se needituje** — API tvar zůstává; Sidebar.svelte už
  rozlišuje root-leaf (`type`) vs skupinu (`children`).

## Doporučené pořadí

1. Krok 0 (průzkum: nese ModuleDefinition viewer navSection? jak je
   registrován settingsSections cfgItem?) — určí rozsah Kroku 2/3.
2. Krok 1 (navSections.jsonc + registrace) — ověřit, že se cfgItem
   načte (dočasný dump v controlleru / test).
3. Krok 3 (NavigationController buildTree přepis) + Krok 2 (navSection
   na viewery) iterativně — po každém přidání viewer navSection ověřit
   `GET /_ui/navigation` (curl) že položka skočila do správné sekce.
4. Krok 4 (hideFromNavigation viewer + skrýt Doklady) — **pečlivě**
   ověřit, že faktury zůstaly (sdílená tabulka). Curl + test.
5. Krok 5 (přesuny do settings) — ověřit Nastavení i mizení z nav.
6. Krok 6 (dokumentace).

Commity granulárně po krocích, konvence `feat(navigation): ...` /
`feat(settings): ...` / `refactor(navigation): ...` s `Co-Authored-By:
Claude` footerem. Build verifikace
`php -l <file> && vendor/bin/phpunit --filter NavigationControllerTest
&& cd frontend && timeout 90 npm run build 2>&1 | tail -4`. Push dělá Anna.

## Konvence a upozornění

- **PHP 8.3 strict_types.** `NavigationController` je čistá logika bez
  DB schématu — žádný `ds-upgrade` (ale ověřit, zda se `module.jsonc` /
  cfgItem cachují; pokud ano, rekompilace/cache clear po změně configu).
- **Server-driven UI** — sekce, labely, pořadí v JSONC, ne v Svelte.
- **Sdílená tabulka `docs_core_heads`** — nejcitlivější místo. Skrytí
  `docs.core.heads` vieweru nesmí cross-propagací skrýt tabulku a tím
  faktury. Otestovat explicitně.
- **`module.jsonc` diakritika** — `patch_file` nespolehlivý s háčky/
  čárkami; Python heredoc workaround (Unicode escape + `count()` assert)
  nebo `write_file` celého souboru. `grep -n "navSection"` po každé
  editaci.
- **cfgItem registrace** — najít přesně, jak je `global.settingsSections`
  zaregistrován a načítán, a `global.navSections` udělat identicky
  (ať nezůstane mrtvý soubor).
- **i18n** — navSections labely `name:cs`/`name:en` přes ConfigLocalizer
  (žádné frontend `t()` klíče netřeba — server posílá hotový label).
- **Backwards-compat** — `NavigationController` mění interní logiku, ne
  API tvar. Žádný jiný konzument navigace než Sidebar.svelte (ověřit
  grep `_ui/navigation` napříč frontendem).
- **Pre-existing test noise:** `Opis\JsonSchema\Validator not found`
  v Exchange/Mail testech je baseline, nesouvisí.

## Otevřené otázky k ověření při implementaci

- **ModuleDefinition nese navSection na vieweru?** (Krok 0.1) — pokud
  ne, rozšířit parsování `viewers[]` o `navSection`/`navOrder`/
  `hideFromNavigation`, nebo číst raw `module.jsonc` v controlleru.
- **Registrace `global.navSections`** (Krok 0.2) — zrcadlit
  settingsSections; ověřit cache/rekompilaci configu.
- **Cross-propagace skrytí u sdílené tabulky `docs_core_heads`**
  (Krok 4a) — potvrdit bezpečné skrytí jen souhrnného vieweru.
- **Ikony sekcí** (`cart-down`/`cart-up`) — existují v sadě? Jinak
  fallback / bez ikony; needitovat frontend kvůli ikonám.
- **Order kolize v subsekci other.mail** (Krok 5a) — extracted_documents
  vs backends order; zvolit volné číslo, ověřit výsledné pořadí.
- **economy.items.kinds** — potvrzeno už v settingsItems (section items),
  needitovat; jen `economy.items` (Položky) dostane navSection basic.
