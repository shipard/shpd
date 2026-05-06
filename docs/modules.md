# Shipard — Modulový systém

## 1. Přehled

Shipard je rozdělen do modulů. Modul je logická jednotka, která zapouzdřuje databázové tabulky, konfigurační soubory a business logiku pro určitou oblast systému (evidence osob, fakturace, smluvní agenda atd.).

Moduly jsou organizovány do **skupin** (groups) pro přehlednost. Skupina je čistě organizační složka bez vlastních metadat.

Na jednom serveru běží stovky modulů. Každý zdroj dat (data source) má v konfiguraci seznam aktivovaných modulů. Existují speciální instalační moduly (např. `install.base`), které nemají vlastní tabulky, ale přes závislosti aktivují sadu potřebných modulů.

---

## 2. Formát konfiguračních souborů — JSONC

Všechny ručně psané konfigurační a definiční soubory v Shipard používají formát **JSONC** (JSON with Comments).

### Co JSONC přidává oproti JSON

- Jednořádkové komentáře: `// komentář`
- Víceřádkové komentáře: `/* komentář */`
- Trailing čárky: `{"a": 1, "b": 2,}` (čárka za posledním prvkem)

### Přípony souborů

| Typ souboru | Přípona | Formát | Příklad |
|-------------|---------|--------|---------|
| Ručně psané definiční soubory | `.jsonc` | JSONC | `module.jsonc`, `core_system_users.jsonc` |
| Generované / kompilované soubory | `.json` | Čistý JSON | `compiled.cs.json`, `compiled.en.json` |

### PHP implementace

PHP nemá nativní JSONC parser. Shipard implementuje vlastní utilitu `JsoncParser`, která:

1. Odstraní jednořádkové komentáře (`// ...`)
2. Odstraní víceřádkové komentáře (`/* ... */`)
3. Odstraní trailing čárky
4. Předá výsledek standardnímu `json_decode()`

Komentáře uvnitř řetězců (v uvozovkách) se zachovávají beze změny.

### Příklad JSONC souboru

```jsonc
{
    // Identifikace modulu
    "id": "economy.docs",
    "name": "Documents",
    "name:cs": "Doklady",

    /*
     * Závislosti na dalších modulech.
     * Musí být aktivovány před tímto modulem.
     */
    "dependencies": [
        "core.system",
        "base.persons",
    ],

    "tables": [
        "economy_docs_heads",
        "economy_docs_rows",  // trailing čárka je povolena
    ]
}
```

---

## 3. Vícejazyčnost (i18n)

Shipard podporuje vícejazyčnost přímo v definičních souborech. Překlady se uvádějí pomocí suffixu `:jazyk` u příslušných polí.

### Jazykové kódy

Dvoupísmenné kódy dle ISO 639-1: `cs`, `en`, `de`, `sk`, `pl` atd.

### Podporované jazyky (počáteční)

- `cs` — čeština
- `en` — angličtina

Další jazyky lze přidat bez změny formátu.

### Formát

Vícejazyčné pole se uvádí ve třech variantách:

```jsonc
{
    // Holé pole — povinné, slouží jako fallback / vývojový popisek
    "name": "Column Name",

    // Jazykové varianty — volitelné
    "name:cs": "Název sloupce",
    "name:en": "Column Name"
}
```

### Pravidla

1. **Holé pole (bez suffixu) je vždy povinné** — slouží jako záchytná síť, když překlad chybí
2. Jazykové varianty jsou volitelné — systém funguje i bez nich
3. Vícejazyčnost se vztahuje na **metadata i obsahová data** — názvy modulů, sloupců, popisky, ale i názvy sazeb DPH, typů dokladů atd.

### Fallback logika

Při vyhodnocování vícejazyčného pole pro jazyk `XX`:

1. Hledej `pole:XX` (požadovaný jazyk, např. `name:cs`)
2. Pokud neexistuje → použij `pole:en` (angličtina jako univerzální fallback)
3. Pokud ani to neexistuje → použij `pole` (holé pole bez suffixu)

### Která pole jsou vícejazyčná

Vícejazyčná jsou všechna pole, která se zobrazují uživateli v UI:

| Kontext | Vícejazyčná pole |
|---------|-----------------|
| `module.jsonc` | `name`, `description` |
| Definice tabulky | `name` (název tabulky pro UI) |
| Definice sloupce | `name` (název sloupce pro formuláře a seznamy) |
| Konfigurační soubory | Jakékoliv pole s lidsky čitelným textem (např. `name` u sazeb DPH) |

Technická pole (`id`, `type`, `length`, `dependencies` atd.) nejsou vícejazyčná.

### Příklady

**module.jsonc:**
```jsonc
{
    "id": "economy.docs",
    "name": "Documents",
    "name:cs": "Doklady",
    "name:en": "Documents",
    "description": "Invoices, orders and other documents",
    "description:cs": "Faktury, objednávky a další účetní doklady",
    "description:en": "Invoices, orders and other accounting documents"
}
```

**Konfigurační soubor (vatRates.jsonc):**
```jsonc
{
    "rates": [
        {
            "id": "standard",
            "rate": 21,
            "name": "Standard rate",
            "name:cs": "Základní sazba",
            "name:en": "Standard rate"
        },
        {
            "id": "reduced1",
            "rate": 12,
            "name": "Reduced rate",
            "name:cs": "Snížená sazba",
            "name:en": "Reduced rate"
        }
    ]
}
```

### Kompilace — jazykové varianty

Kompilátor konfigurace generuje **jeden soubor na jazyk**:

```
config/configuration/compiled.cs.json    — česká varianta
config/configuration/compiled.en.json    — anglická varianta
```

Při kompilaci se pro každý jazyk:

1. Pro každé vícejazyčné pole aplikuje fallback logika
2. Výsledné `name` obsahuje vždy jeden řetězec (pro daný jazyk)
3. Jazykové suffixy (`name:cs`, `name:en`) se z kompilovaného souboru odstraní

**Zdrojový soubor (vatRates.jsonc):**
```jsonc
{
    "rates": [
        {"id": "standard", "rate": 21, "name": "Standard rate", "name:cs": "Základní sazba", "name:en": "Standard rate"}
    ]
}
```

**Kompilovaný (compiled.cs.json):**
```json
{
    "rates": [
        {"id": "standard", "rate": 21, "name": "Základní sazba"}
    ]
}
```

**Kompilovaný (compiled.en.json):**
```json
{
    "rates": [
        {"id": "standard", "rate": 21, "name": "Standard rate"}
    ]
}
```

### Runtime

Při startu aplikace se načte kompilovaný soubor pro jazyk přihlášeného uživatele:

```php
$lang = $user->getLanguage(); // "cs"
$config = ConfigRuntime::load($dataSourcePath, $lang);
$vatRates = $config->cfgItem('economy.docs.vatRates');
// $vatRates['rates'][0]['name'] === "Základní sazba"
```

### Výchozí jazyk

V `config/main.json` zdroje dat se nastavuje výchozí jazyk volitelným polem `defaultLanguage` (ISO 639-1, lowercase):

```json
{
    "id": "a3f2-b8c1-d4e7-f9a0",
    "name": "Naše firma s.r.o.",
    "defaultLanguage": "cs",
    "modules": ["install.base"]
}
```

Výchozí jazyk se použije jako fallback, pokud informace o jazyce uživatele chybí (chybějící hlavička `Accept-Language`, API volání bez jazykového kontextu atd.).

Backend čte volbu z `Accept-Language`. Pokud hlavička chybí, použije se `DataSourceConfig::getDefaultLanguage()` — vrací hodnotu z `main.json`, fallback `'en'` když pole chybí. Frontend odesílá `Accept-Language: {language.current}` z `language` storu (viz `docs/frontend.md` sekce *Internacionalizace*).

---

## 4. Identifikace modulu

### ID modulu

Formát: `{skupina}.{modul}` — tečková notace.

Příklady:
- `base.persons` — evidence dodavatelů/odběratelů
- `economy.docs` — doklady (faktury, objednávky)
- `economy.contracts` — smlouvy
- `install.base` — instalační profil se základními moduly
- `core.system` — systémové tabulky (uživatelé, sessions, nastavení)

Pravidla:
- Skupina i modul: malá písmena, bez diakritiky, `[a-z][a-z0-9]*`
- Vždy právě dvě úrovně (skupina.modul)
- ID modulu je globálně unikátní

### Adresářová struktura

ID modulu přímo odpovídá cestě v souborovém systému:

```
/opt/shipard/shpd/modules/
├── core/
│   └── system/
│       ├── module.jsonc
│       ├── tables/
│       │   ├── core_system_users.jsonc
│       │   ├── core_system_sessions.jsonc
│       │   └── core_system_settings.jsonc
│       └── config/
│           └── systemSettings.jsonc
├── base/
│   └── persons/
│       ├── module.jsonc
│       ├── tables/
│       │   └── base_persons_contacts.jsonc
│       ├── config/
│       │   └── virtualGroups.jsonc
│       └── extensions/
│           └── ext-some-table.jsonc
├── economy/
│   ├── docs/
│   │   ├── module.jsonc
│   │   ├── tables/
│   │   │   ├── economy_docs_heads.jsonc
│   │   │   └── economy_docs_rows.jsonc
│   │   └── config/
│   │       └── vatRates.jsonc
│   └── contracts/
│       ├── module.jsonc
│       └── tables/
│           └── economy_contracts_main.jsonc
└── install/
    ├── base/
    │   └── module.jsonc
    └── full/
        └── module.jsonc
```

---

## 5. Definiční soubor modulu — `module.jsonc`

### Struktura

```jsonc
{
    // Identifikace
    "id": "economy.docs",
    "name": "Documents",
    "name:cs": "Doklady",
    "name:en": "Documents",
    "description": "Invoices, orders and other documents",
    "description:cs": "Faktury, objednávky a další účetní doklady",
    "description:en": "Invoices, orders and other accounting documents",

    // Závislosti
    "dependencies": [
        "core.system",
        "base.persons"
    ],

    // Tabulky vlastněné tímto modulem
    "tables": [
        "economy_docs_heads",
        "economy_docs_rows"
    ],

    // Rozšíření tabulek jiných modulů
    "extensions": [
        {
            "table": "base_persons_contacts",
            "file": "extensions/ext-base-persons-contacts.jsonc"
        }
    ],

    // Konfigurační položky
    "config": [
        {
            "id": "economy.docs.vatRates",
            "file": "config/vatRates.jsonc"
        },
        {
            "id": "economy.docs.docTypes",
            "file": "config/docTypes.jsonc"
        }
    ]
}
```

### Pole

| Pole | Typ | Povinné | Vícejazyčné | Popis |
|------|-----|---------|-------------|-------|
| `id` | string | Ano | Ne | ID modulu v tečkové notaci |
| `name` | string | Ano | Ano | Lidsky čitelný název modulu |
| `description` | string | Ne | Ano | Popis modulu |
| `dependencies` | string[] | Ne | Ne | Seznam ID modulů, na kterých tento modul závisí |
| `tables` | string[] | Ne | Ne | Seznam ID tabulek (odpovídá názvům souborů v `tables/`) |
| `extensions` | object[] | Ne | Ne | Rozšíření tabulek jiných modulů |
| `extensions[].table` | string | Ano | Ne | ID cílové tabulky |
| `extensions[].file` | string | Ano | Ne | Relativní cesta k JSONC souboru s definicí rozšíření |
| `config` | object[] | Ne | Ne | Konfigurační položky modulu |
| `config[].id` | string | Ano | Ne | Globální identifikátor konfigurace |
| `config[].file` | string | Ano | Ne | Relativní cesta ke konfiguračnímu JSONC souboru |

### Instalační modul — příklad

```jsonc
{
    "id": "install.base",
    "name": "Base installation",
    "name:cs": "Základní instalace",
    "name:en": "Base installation",
    "description": "Base set of modules for system operation",
    "description:cs": "Základní sada modulů pro provoz systému",
    "description:en": "Base set of modules for system operation",
    "dependencies": [
        "core.system",
        "base.persons"
    ]
}
```

Instalační modul nemá vlastní tabulky ani konfigurace — slouží výhradně jako metabalík, který přes závislosti zajistí aktivaci potřebných modulů.

---

## 6. Závislosti

### Pravidla

- Závislosti jsou jednoduché — bez verzování (celý systém je jedno monorepo s jednou verzí)
- Modul deklaruje závislosti v poli `dependencies` v `module.jsonc`
- Závislosti jsou tranzitivní: pokud A závisí na B a B závisí na C, pak aktivace A aktivuje i B i C
- **Cirkulární závislosti jsou chyba** — systém je musí detekovat a odmítnout (ignorovat modul s cyklickou závislostí)

### Řešení závislostí — algoritmus

1. Načíst seznam aktivovaných modulů z konfigurace zdroje dat
2. Pro každý aktivovaný modul rekurzivně načíst závislosti
3. Detekovat cykly (DFS s detekcí back-edge)
4. Pokud je nalezen cyklus — zalogovat chybu, přeskočit problematický modul
5. Topologicky seřadit moduly (závislosti před závislými)
6. Výsledné pořadí se použije pro:
   - Vytváření tabulek (nejdříve tabulky modulů bez závislostí)
   - Aplikaci extensions (až po vytvoření cílových tabulek)
   - Kompilaci konfigurace

---

## 7. Tabulky

### Pojmenování tabulek

Formát: `{skupina}_{modul}_{tabulka}`

Příklady:
- `core_system_users`
- `core_system_sessions`
- `base_persons_contacts`
- `economy_docs_heads`
- `economy_docs_rows`

Pravidla:
- Malá písmena, podtržítka jako oddělovač
- Prefix vždy odpovídá modulu, který tabulku vlastní
- Název tabulky je globálně unikátní v rámci databáze

### Definiční soubor tabulky

Umístění: `modules/{skupina}/{modul}/tables/{id_tabulky}.jsonc`

Podrobný formát definice tabulky bude specifikován v samostatném dokumentu `docs/table-definitions.md`.

### Extensions — rozšíření tabulek

Extension je JSONC soubor, který přidává sloupce, indexy nebo cizí klíče do tabulky jiného modulu.

Umístění: `modules/{skupina}/{modul}/extensions/ext-{cilova-tabulka}.jsonc`

Extension může přidat:
- Nové sloupce
- Nové indexy
- Nové cizí klíče

Extension nemůže:
- Odebírat existující sloupce, indexy nebo klíče
- Měnit definici existujících sloupců (to může jen vlastník tabulky)

**Pořadí aplikace:**
1. Nejprve se sestaví základní definice všech tabulek z jejich vlastnících modulů
2. Poté se aplikují extensions v pořadí daném topologickým seřazením modulů (podle závislostí)
3. Modul s extension musí mít v `dependencies` uveden modul vlastnící cílovou tabulku

---

## 8. Konfigurace modulů

### Definiční soubory

Každý modul může definovat libovolný počet konfiguračních položek. Konfigurační soubor je JSONC s libovolnou datovou strukturou — sazby DPH, typy dokladů, definice virtuálních skupin atd. Obsahová pole mohou být vícejazyčná.

### ID konfigurace

ID konfigurace je globální identifikátor ve formátu tečkové notace. Nemusí odpovídat ID modulu — existují i globální/nesystémové konfigurace.

Příklady:
- `economy.docs.vatRates` — sazby DPH (patří modulu `economy.docs`)
- `economy.docs.docTypes` — typy dokladů
- `base.persons.virtualGroups` — virtuální skupiny osob
- `global.currencies` — příklad globální konfigurace

### Kompilace konfigurace

Všechny konfigurační soubory ze všech aktivních modulů se „zkompilují" — jeden soubor na jazyk:

**Umístění:**
```
/opt/shipard/data-sources/{ds-id}/config/configuration/compiled.cs.json
/opt/shipard/data-sources/{ds-id}/config/configuration/compiled.en.json
```

**Struktura kompilovaného souboru (compiled.cs.json):**

```json
{
    "_meta": {
        "compiled": "2026-03-14T10:30:00+01:00",
        "version": "0.1.0",
        "language": "cs",
        "modules": ["core.system", "base.persons", "economy.docs"]
    },
    "items": {
        "economy.docs.vatRates": {
            "rates": [
                {"id": "standard", "rate": 21, "name": "Základní sazba"},
                {"id": "reduced1", "rate": 12, "name": "Snížená sazba"}
            ]
        },
        "base.persons.virtualGroups": {
            "groups": [
                {"id": "suppliers", "name": "Dodavatelé"},
                {"id": "customers", "name": "Odběratelé"}
            ]
        }
    }
}
```

V kompilovaných souborech jsou vícejazyčné suffixy odstraněny — pole `name` vždy obsahuje hodnotu pro daný jazyk.

**Přístup v aplikaci:**

```php
$lang = $user->getLanguage(); // "cs"
$config = ConfigRuntime::load($dataSourcePath, $lang);
$vatRates = $config->cfgItem('economy.docs.vatRates');
// $vatRates['rates'][0]['name'] === "Základní sazba"
```

Soubor se načítá při startu aplikace jedním čtením z disku.

---

## 9. Aktivace modulů ve zdroji dat

### Konfigurace — `config/main.json`

Do konfigurace zdroje dat se přidá pole `modules` a `defaultLanguage`:

```json
{
    "id": "a3f2-b8c1-d4e7-f9a0",
    "name": "Naše firma s.r.o.",
    "database": {
        "name": "a3f2_b8c1_d4e7_f9a0",
        "user": "shpd_a3f2b8c1",
        "password": "nahodne-generovane-heslo"
    },
    "created": "2026-03-12T14:30:00+01:00",
    "defaultLanguage": "cs",
    "modules": [
        "install.base"
    ]
}
```

Pole `modules` obsahuje seznam přímo aktivovaných modulů. Tranzitivní závislosti se dořeší automaticky.

Pole `defaultLanguage` určuje jazyk, pokud informace o jazyce uživatele chybí (nepřihlášený uživatel, API volání bez jazykového kontextu).

### Deaktivace modulu

Při odebrání modulu z `modules`:
- Tabulky se **neodstraňují** automaticky
- Konfigurační položky modulu se odeberou z kompilovaných souborů při další kompilaci
- Časem bude existovat servisní chod pro výmaz nepotřebných tabulek

---

## 10. CLI příkaz `shpd-ds ds-upgrade`

Příkaz se spouští z adresáře zdroje dat:

```bash
cd /opt/shipard/data-sources/{ds-id}
shpd-ds ds-upgrade
```

### Kroky

1. **Načtení konfigurace** — přečíst `config/main.json`, načíst seznam aktivovaných modulů
2. **Řešení závislostí** — rekurzivně načíst závislosti, detekovat cykly, topologicky seřadit
3. **Načtení definic tabulek** — pro každý modul načíst definiční soubory tabulek (JSONC)
4. **Aplikace extensions** — v pořadí závislostí aplikovat extensions na základní definice
5. **Kompilace konfigurace** — pro každý podporovaný jazyk vygenerovat `compiled.{lang}.json`
6. **Kontrola databáze:**
   - Pro každou tabulku v definici:
     - Pokud tabulka neexistuje → `CREATE TABLE`
     - Pokud tabulka existuje → porovnat sloupce:
       - Chybějící sloupec → `ALTER TABLE ADD COLUMN`
       - Změněný typ (bezpečná změna — prodloužení stringu, rozšíření int) → `ALTER TABLE MODIFY COLUMN`
       - Přebytečný sloupec → **ignorovat** (nechat)
   - Zkontrolovat indexy:
     - Chybějící index → `CREATE INDEX`
7. **Výstup** — zobrazit souhrn provedených změn

### Příklad výstupu

```
Shipard Data Source Upgrade v0.1.0
Data source: Naše firma s.r.o. (a3f2-b8c1-d4e7-f9a0)

Resolving modules...
  Active modules: 5 (install.base + 4 dependencies)
  Module order: core.system, base.persons, economy.docs, economy.contracts, install.base

Compiling configuration...
  Config items: 8
  Languages: cs, en
  Written to: config/configuration/compiled.{cs,en}.json

Checking database...
  [CREATE] core_system_users
  [CREATE] core_system_sessions
  [CREATE] core_system_settings
  [OK]     base_persons_contacts
  [ALTER]  economy_docs_heads — added column: payment_method (varchar(50))
  [CREATE] economy_docs_rows

Upgrade complete. 3 tables created, 1 table altered, 1 table unchanged.
```

### Spouštění

- **Vývoj:** manuálně přes CLI
- **Produkce:** automaticky po deploy

---

## 11. Modul `core.system`

Základní modul, který je vždy aktivní (závislost `install.base` a všech dalších instalačních profilů).

### Tabulky

#### `core_system_users`

Uživatelé systému s přihlašovacími údaji.

| Sloupec | Účel |
|---------|------|
| `id` | Primární klíč |
| `login` | Přihlašovací jméno |
| `password_hash` | Hash hesla |
| `full_name` | Celé jméno uživatele |
| `email` | E-mail |
| `is_active` | Aktivní/neaktivní |
| `created` | Datum vytvoření |
| `modified` | Datum poslední změny |

#### `core_system_sessions`

Aktivní uživatelské relace.

| Sloupec | Účel |
|---------|------|
| `id` | Primární klíč |
| `user_id` | FK na users |
| `token` | Session token |
| `ip_address` | IP adresa |
| `created` | Datum vytvoření |
| `expires` | Datum expirace |

#### `core_system_settings`

Systémová nastavení zdroje dat (key-value).

| Sloupec | Účel |
|---------|------|
| `id` | Primární klíč |
| `key` | Klíč nastavení |
| `value` | Hodnota (JSON) |
| `modified` | Datum poslední změny |

---

## 12. Implementační plán pro Claude Code

### Prerekvizita

Tento dokument předpokládá existující kostru projektu z PRD v0.1 (CLI utility, server config, data source config).

### Fáze 1 — JSONC parser a i18n resolver

**Soubory:**
- `src/Core/Utils/JsoncParser.php` — parsování JSONC (strip komentářů, trailing čárek)
- `src/Core/I18n/LocalizedFieldResolver.php` — fallback logika pro vícejazyčná pole
- `src/Core/I18n/ConfigLocalizer.php` — rekurzivní rozbalení vícejazyčných polí pro daný jazyk

**Testy:**
- JSONC: komentáře (jednořádkové, víceřádkové), trailing čárky, komentáře uvnitř řetězců
- I18n: fallback chain (cs → en → holé), chybějící překlady, vnořené struktury

### Fáze 2 — Loader modulů

**Soubory:**
- `src/Core/Module/ModuleDefinition.php` — datová třída pro `module.jsonc`
- `src/Core/Module/ModuleLoader.php` — načtení `module.jsonc` ze souborového systému
- `src/Core/Module/ModuleResolver.php` — řešení závislostí, detekce cyklů, topologické řazení

**Testy:**
- Načtení validního `module.jsonc`
- Chybějící povinná pole
- Řešení závislostí — lineární řetěz, strom, diamantový pattern
- Detekce cyklických závislostí
- Topologické řazení

### Fáze 3 — Definice tabulek

**Soubory:**
- `src/Core/Database/TableDefinition.php` — datová třída pro definici tabulky
- `src/Core/Database/ExtensionLoader.php` — načtení a aplikace extensions
- `src/Core/Database/TableMerger.php` — sloučení základní definice s extensions

**Testy:**
- Načtení definice tabulky z JSONC
- Aplikace extension (přidání sloupce, indexu)
- Sloučení v správném pořadí

### Fáze 4 — Kompilace konfigurace

**Soubory:**
- `src/Core/Config/ConfigCompiler.php` — kompilace konfiguračních souborů, generování jazykových variant
- `src/Core/Config/ConfigRuntime.php` — runtime přístup přes `cfgItem()` s podporou jazyků

**Testy:**
- Kompilace z více modulů, více jazyků
- Správný fallback pro chybějící překlady
- Přístup přes `cfgItem()`
- Chybějící klíč

### Fáze 5 — Database upgrade

**Soubory:**
- `src/Core/Database/SchemaComparator.php` — porovnání definice vs. skutečný stav DB
- `src/Core/Database/SchemaMigrator.php` — generování a provádění ALTER/CREATE příkazů

**Testy:**
- CREATE TABLE z definice
- Detekce chybějícího sloupce
- Bezpečný ALTER (prodloužení stringu, rozšíření int)
- Ignorování přebytečných sloupců

### Fáze 6 — CLI příkaz `ds-upgrade`

**Soubory:**
- `src/Command/DataSource/DsUpgradeCommand.php`

**Testy:**
- End-to-end test s mockovanou DB

### Fáze 7 — Základní moduly

- Vytvořit `modules/core/system/module.jsonc` a definiční soubory tabulek
- Vytvořit `modules/install/base/module.jsonc`

---

## 13. Otevřené otázky

- [ ] Formát definice tabulky (JSONC) — bude specifikován v `docs/table-definitions.md`
- [ ] Formát extension JSONC
- [ ] Servisní chod pro výmaz nepotřebných tabulek
- [ ] Migrace dat při změně struktury tabulek
- [ ] Správa oprávnění (který uživatel smí co v kterém modulu)
- [ ] Přidávání dalších jazyků za běhu
- [ ] Detekce chybějících překladů (validační nástroj)
