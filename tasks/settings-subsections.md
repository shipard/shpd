# Task: Dvouúrovňové sekce v Nastavení aplikace + přesun položek

**Stav:** hotovo

## Status / cíl

Rozšířit navigaci Nastavení aplikace (`settings` mód) o **dvě úrovně sekcí**
(sekce → podsekce → položka) a přesunout vybrané tabulky z hlavní navigace
do nové hlavní sekce **Ostatní**.

Dnes settings navigace umí jen jednu úroveň (sekce → položky). Frontend
(`Sidebar.svelte`) přitom **dvě úrovně už renderovat umí** — větev
`{#if child.children}` vykreslí podskupinu s vlastním rozbalovacím headerem.
Veškerá práce je proto na **backendu** (schéma + skládání stromu) a v
**datech** (`module.jsonc` napříč moduly). Frontend se nemění.

## Závislosti

- Žádné na jiných taskech. Staví na existujícím settings navigačním pipeline
  (`SettingsController`, `ModuleDefinition`, `settingsSections.jsonc`).

## Potvrzená designová rozhodnutí (Anna)

1. **Cílová struktura** — jedna hlavní sekce „Ostatní", v ní podsekce:
   - **Systém** → Přílohy
   - **Pošta** → Schránky, Analýzy zpráv, Idempotency klíče pro došlou
     poštu, AI backendy, AI profily, Rezervace analýz
   - **Svět** → Administrativní členění
   - **Osoby** → Kontakty, Bankovní účty, Adresy
2. **Položky jdou jako `type: "table"`** (generický TableBrowser). Zakládání
   plnohodnotných viewerů je mimo rozsah — řeší se zvlášť (viz „Mimo rozsah").
3. **Období DPH vynecháno** — `economy_codebooks_vat_periods` je subtabulka
   registrace DPH (`vat_registration` FK `nullable:false`, `hideFromNavigation`).
   Vytržení do samostatného top-level vieweru jde proti návrhu tabulky.
   Vyřeší se spolu se zakládáním viewerů. Sekce „Ekonomika" tím v tomto
   tasku **odpadá**.
4. **Schéma podsekcí — vnořené `subsections`** (rozhodnutí Claude, viz níže).

### Proč vnořené `subsections`, ne `parent` reference

- Mapuje 1:1 na výstupní JSON stromu — server dnes iteruje `sections` a plní
  `children`; vnořená data jen přidají druhou úroveň téhož skládání.
- `settingsItems[]` se rozšíří jen o volitelný `subsection` → **zpětně
  kompatibilní**: bez `subsection` položka padne přímo do sekce (stávající
  Účetnictví / Sklady / Položky se nemění).
- `parent` reference by vyžadovala dvoufázové skládání a rozbila by řízení
  pořadí přes `order`.
- Tradeoff: jedna podsekce nemůže patřit pod dvě sekce. Nepotřebujeme.

## Rozsah

### V rozsahu
- Schéma `settingsSections.jsonc` rozšířené o `subsections[]` uvnitř sekce.
- Nová hlavní sekce `other` se čtyřmi podsekcemi.
- `settingsItems[]` rozšířené o volitelný klíč `subsection`.
- Parser v `ModuleDefinition::fromArray()` čte `subsection`.
- `SettingsController::navigation()` + `collectItems()` skládají dvouúrovňový
  strom (sekce → podsekce → položky), prázdné podsekce/sekce se vynechávají.
- `settingsItems` přidané do čtyř modulů: core.attachments, core.mail,
  world.divisions, base.persons.

### Mimo rozsah
- Zakládání viewerů pro tabulky bez vieweru (Schránky, AI backendy, Kontakty,
  Adresy, …) — „staré prohlížeče" se předělají samostatným taskem.
- Období DPH / `economy_codebooks_vat_periods` viewer.
- Jakákoli změna `Sidebar.svelte` nebo frontend navigation store.
- Klávesová zkratka pro toggle sidebaru (deferred jinde).

## Kroky

### 1. Rozšířit schéma settingsSections o podsekce

Soubor: `modules/install/base/config/settingsSections.jsonc`

Přidat novou hlavní sekci `other` se čtyřmi podsekcemi. Stávající tři sekce
(accounting, warehouses, items) **ponechat beze změny**. `order` nové sekce
zvolen 100, aby „Ostatní" bylo až za stávajícími sekcemi.

```jsonc
{
    "id": "other",
    "name": "Other",
    "name:cs": "Ostatní",
    "name:en": "Other",
    "icon": "dots",
    "order": 100,
    "subsections": [
        {
            "id": "other.system",
            "name": "System",
            "name:cs": "Systém",
            "name:en": "System",
            "order": 10
        },
        {
            "id": "other.mail",
            "name": "Mail",
            "name:cs": "Pošta",
            "name:en": "Mail",
            "order": 20
        },
        {
            "id": "other.world",
            "name": "World",
            "name:cs": "Svět",
            "name:en": "World",
            "order": 30
        },
        {
            "id": "other.persons",
            "name": "Persons",
            "name:cs": "Osoby",
            "name:en": "Persons",
            "order": 40
        }
    ]
}
```

> Pozn. k ikoně `dots`: ověřit, že existuje v `frontend/src/icons.js`
> (`resolveIcon`). Pokud ne, použít jinou existující sémantickou ikonu nebo
> `icon` u sekce vynechat (frontend ikonu sekce nezobrazuje v group-headeru,
> takže je bezpečné ji vynechat). Podsekce ikonu nemají — v UI ji
> `shpd-sidebar__subgroup-header` nevykresluje.

### 2. Parser — číst `subsection` v ModuleDefinition

Soubor: `src/Core/Module/ModuleDefinition.php`

V bloku `settingsItems` (kolem ř. 44–58) přidat čtení `subsection`.

Najít:
```php
                $settingsItems[] = [
                    'viewer'  => $item['viewer'] ?? null,
                    'table'   => $item['table']  ?? null,
                    'section' => (string) $item['section'],
                    'order'   => isset($item['order']) ? (int) $item['order'] : null,
                ];
```

Nahradit:
```php
                $settingsItems[] = [
                    'viewer'     => $item['viewer'] ?? null,
                    'table'      => $item['table']  ?? null,
                    'section'    => (string) $item['section'],
                    'subsection' => isset($item['subsection']) ? (string) $item['subsection'] : null,
                    'order'      => isset($item['order']) ? (int) $item['order'] : null,
                ];
```

### 3. SettingsController — skládat dvouúrovňový strom

Soubor: `src/Api/Controller/SettingsController.php`

#### 3a. `collectItems()` — klíčovat položky podle sekce A podsekce

Dnes vrací `array<sectionId, navItem[]>`. Změnit na
`array<sectionId, array{__direct: navItem[], __sub: array<subsectionId, navItem[]>}>`
— tj. odlišit položky patřící přímo do sekce od položek v podsekcích.

Nejjednodušší zpětně kompatibilní přístup: ploché klíčování řetězcem.
Klíč položky = `section` pokud `subsection === null`, jinak
`section . '\u0000' . subsection` (NUL separátor — nemůže být v id).
Skládání stromu (3b) si pak klíče rozparsuje.

V místě, kde se dnes plní `$itemsBySection[$section][] = $navItem;`
(dvě místa — větev viewer i větev table), nahradit za pomocnou metodu:

```php
    private function sectionKey(string $section, ?string $subsection): string
    {
        return $subsection === null ? $section : $section . "\0" . $subsection;
    }
```

a volat `$itemsBySection[$this->sectionKey($section, $item['subsection'] ?? null)][] = $navItem;`
v obou větvích. `$section` se už čte z `$item['section']` na začátku smyčky —
přidat hned vedle `$subsection = $item['subsection'] ?? null;`.

Řazení podle `_order` na konci metody zůstává (klíče se jen rozšířily).

#### 3b. `navigation()` — vkládat podsekce

Dnes (kolem ř. 42–60) pro každou sekci vezme `$itemsBySection[$sectionId]`
a vloží jako `children`. Rozšířit o podsekce.

Najít blok skládání `$tree`:
```php
        $tree = [];
        foreach ($sections as $section) {
            $sectionId = $section['id'];
            if (empty($itemsBySection[$sectionId])) {
                continue;
            }

            $label = $section['name:' . $language]
                ?? $section['name:en']
                ?? $section['name']
                ?? $sectionId;

            $tree[] = [
                'id'       => $sectionId,
                'label'    => $label,
                'icon'     => $section['icon'] ?? null,
                'children' => $itemsBySection[$sectionId],
            ];
        }
```

Nahradit:
```php
        $tree = [];
        foreach ($sections as $section) {
            $sectionId = $section['id'];

            // Položky patřící přímo do sekce (bez subsection).
            $directItems = $itemsBySection[$sectionId] ?? [];

            // Podsekce — každá sbírá své položky z klíče "section\0subsection".
            $subChildren = [];
            if (!empty($section['subsections']) && is_array($section['subsections'])) {
                $subsections = $section['subsections'];
                usort($subsections, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
                foreach ($subsections as $sub) {
                    $subId   = $sub['id'];
                    $subKey  = $sectionId . "\0" . $subId;
                    $subItems = $itemsBySection[$subKey] ?? [];
                    if ($subItems === []) {
                        continue; // prázdnou podsekci nevykreslujeme
                    }
                    $subLabel = $sub['name:' . $language]
                        ?? $sub['name:en']
                        ?? $sub['name']
                        ?? $subId;
                    $subChildren[] = [
                        'id'       => $subId,
                        'label'    => $subLabel,
                        'children' => $subItems,
                    ];
                }
            }

            // Sekce bez přímých položek i bez naplněných podsekcí se vynechá.
            if ($directItems === [] && $subChildren === []) {
                continue;
            }

            $label = $section['name:' . $language]
                ?? $section['name:en']
                ?? $section['name']
                ?? $sectionId;

            // Pořadí children: nejdřív přímé položky, pak podsekce.
            // (Pro sekci "other" nejsou žádné přímé položky — jen podsekce.)
            $tree[] = [
                'id'       => $sectionId,
                'label'    => $label,
                'icon'     => $section['icon'] ?? null,
                'children' => array_merge($directItems, $subChildren),
            ];
        }
```

> Pozn. ke `$sectionsCfg` validaci na začátku metody: podmínka
> `empty($sectionsCfg['sections'])` zůstává. Nově ale `$itemsBySection`
> klíčuje i složenými klíči — žádná další úprava začátku metody netřeba.

### 4. Přidat settingsItems do modulů

U každého modulu přidat (nebo rozšířit) pole `settingsItems`. Tabulky jdou
jako `type:"table"` → klíč `"table"`, ne `"viewer"`. Všech 10 tabulek má
v JSONC `name:cs` přesně odpovídající cílovým labelům (ověřeno) a žádná nemá
`hideFromNavigation`.

#### 4a. core.attachments — `modules/core/attachments/module.jsonc`

Modul nemá `settingsItems`. Přidat (např. za blok `tables`):
```jsonc
    "settingsItems": [
        { "table": "core_attachments_files", "section": "other", "subsection": "other.system", "order": 10 }
    ],
```

#### 4b. core.mail — `modules/core/mail/module.jsonc`

Modul nemá `settingsItems`. Přidat (např. za blok `tables` / `keepOnReset`):
```jsonc
    "settingsItems": [
        { "table": "core_mail_mailboxes",           "section": "other", "subsection": "other.mail", "order": 10 },
        { "table": "core_mail_message_analyses",    "section": "other", "subsection": "other.mail", "order": 20 },
        { "table": "core_mail_incoming_idempotency","section": "other", "subsection": "other.mail", "order": 30 },
        { "table": "core_mail_ai_backends",         "section": "other", "subsection": "other.mail", "order": 40 },
        { "table": "core_mail_ai_profiles",         "section": "other", "subsection": "other.mail", "order": 50 },
        { "table": "core_mail_analysis_claims",     "section": "other", "subsection": "other.mail", "order": 60 }
    ],
```

> Pozor: `core_mail_incoming_messages` má viewer `core.mail.incoming`
> (Došlá pošta) a do settings **nepatří** — zůstává v hlavní navigaci.

#### 4c. world.divisions — `modules/world/divisions/module.jsonc`

Modul nemá `settingsItems`. Přidat:
```jsonc
    "settingsItems": [
        { "table": "world_divisions", "section": "other", "subsection": "other.world", "order": 10 }
    ],
```

> Důsledek: `world_divisions` zmizí z hlavní navigace (NavigationController
> skrývá vše, co je v jakémkoli `settingsItems`). Skupina „Svět" v hlavní
> navigaci tím může zůstat prázdná → automaticky se vynechá (`$children === []`).

#### 4d. base.persons — `modules/base/persons/module.jsonc`

Modul nemá `settingsItems`. Přidat:
```jsonc
    "settingsItems": [
        { "table": "base_persons_contacts",      "section": "other", "subsection": "other.persons", "order": 10 },
        { "table": "base_persons_bank_accounts", "section": "other", "subsection": "other.persons", "order": 20 },
        { "table": "base_persons_addresses",     "section": "other", "subsection": "other.persons", "order": 30 }
    ],
```

> Pozor: `base_persons_persons` má viewer `base.persons` (Osoby) a do settings
> **nepatří** — zůstává v hlavní navigaci.

## Akceptační kritéria

1. Po vstupu do Nastavení je v sidebaru nová sekce **Ostatní** (poslední,
   `order:100`).
2. Rozbalení „Ostatní" ukáže čtyři podsekce: Systém, Pošta, Svět, Osoby
   (v tomto pořadí), každá rozbalovací (chevron).
3. Položky v podsekcích odpovídají seznamu z rozhodnutí #1, ve správném
   pořadí, se správnými českými labely.
4. Klik na položku otevře generický TableBrowser dané tabulky.
5. Přesunuté tabulky (`world_divisions`, `core_attachments_files`,
   mail/persons tabulky) **zmizí z hlavní (app) navigace**.
6. Stávající sekce Účetnictví / Sklady / Položky zůstávají **beze změny**
   (zpětná kompatibilita položek bez `subsection`).
7. Prázdná podsekce ani prázdná sekce se nevykreslí.
8. `php -l` čistý na obou PHP souborech; `npm run build` projde.
9. Cílené testy projdou (viz níže). Pre-existing 37 failures
   (`Opis\JsonSchema\Validator not found` v Exchange/Mail) ignorovat.

## Verifikace

```bash
# PHP syntaxe
php -l src/Core/Module/ModuleDefinition.php
php -l src/Api/Controller/SettingsController.php

# JSONC validita (parse) — pokud existuje helper, jinak vizuální kontrola
# a běh aplikace.

# Cílené testy
vendor/bin/phpunit --filter 'Settings|Navigation|Module'

# Frontend build
cd frontend && npm run build 2>&1 | tail -10

# Ruční ověření přes API (settings strom):
#   GET /_ui/settings/navigation  → očekávej sekci "other" se 4 children,
#   z nichž každý má vlastní children (položky).
#   GET /_ui/navigation           → ověř, že world_divisions a přesunuté
#   tabulky už nejsou přítomné.
```

> Pozn.: pokud cílený PHPUnit filtr nepokrývá settings strom novými testy,
> zvážit přidání testu na dvouúrovňové skládání v
> `SettingsController` (sekce s `subsections` → 2 úrovně `children`;
> prázdná podsekce vynechána; položka bez `subsection` zůstane přímo
> v sekci). Není blokující pro tento task, ale doporučené.

## Doporučené pořadí commitů

1. `feat(settings): support two-level sections in app settings navigation`
   — kroky 1–3 (schéma + parser + controller). Mechanismus podsekcí
   samostatně, bez přesunu dat. Po tomto commitu se nic nezobrazí navíc
   (žádný modul zatím `subsection` nepoužívá), ale stávající chování
   zůstává beze změny.
2. `feat(settings): move attachments, mail, divisions and persons tables to "Other" section`
   — krok 4 (settingsItems napříč 4 moduly). Po tomto commitu se objeví
   sekce Ostatní a tabulky zmizí z hlavní navigace.

Co-Authored-By: Claude

## Mimo rozsah (pro pozdější tasky)

- Zakládání plnohodnotných viewerů pro přesunuté tabulky („staré prohlížeče").
- Období DPH (`economy_codebooks_vat_periods`) — viewer + řešení vztahu
  k registraci DPH.
- Defaultní expand stav podsekcí po vstupu do Nastavení (dnes se expandují
  jen root sekce přes `navTree.filter(g => g.children)` v `Sidebar.svelte`;
  podsekce se zobrazí sbalené — pokud by Anna chtěla rozbalené i druhou
  úroveň, je to malá úprava ve frontendu).
- `docs/frontend.md` — aktualizovat popis settings navigace o dvouúrovňové
  sekce (deferred doc task).
