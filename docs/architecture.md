# Shipard — Architektura

## 1. Přehled vrstev

```
┌─────────────────────────────────────────────────┐
│  CLI vrstva (bin/shpd-server, bin/shpd-ds)      │
│  src/Command/                                   │
├─────────────────────────────────────────────────┤
│  Modulový systém          │  Konfigurace        │
│  src/Core/Module/         │  src/Core/Config/   │
├───────────────────────────┤                     │
│  Databázová vrstva        │  I18n               │
│  src/Core/Database/       │  src/Core/I18n/     │
├─────────────────────────────────────────────────┤
│  Utility                                        │
│  src/Core/Utils/                                │
└─────────────────────────────────────────────────┘
```

Závislosti tečou shora dolů. Nižší vrstvy nikdy nezávisí na vyšších.

---

## 2. Utility (`src/Core/Utils/`)

Bezstavové pomocné třídy bez závislostí na zbytku systému.

| Třída | Účel |
|-------|------|
| `JsoncParser` | Parsování JSONC souborů (strip komentářů, trailing čárek) → `json_decode`. Statické metody `parse()` a `parseFile()`. |
| `IdGenerator` | Generování ID zdrojů dat (`xxxx-xxxx-xxxx-xxxx`, a-z0-9). Kryptograficky bezpečný (`random_int`). |

---

## 3. I18n (`src/Core/I18n/`)

Vícejazyčnost — rozbalení polí se suffixem `:lang`.

| Třída | Účel |
|-------|------|
| `LocalizedFieldResolver` | Resolví jedno vícejazyčné pole. Fallback: `field:XX` → `field:en` → `field` (holé). |
| `ConfigLocalizer` | Rekurzivně projde celou datovou strukturu a aplikuje `LocalizedFieldResolver` na všechna vícejazyčná pole. Odstraní `:lang` suffixy z výstupu. |

**Tok dat:**
```
JSONC soubor s "name", "name:cs", "name:en"
  → ConfigLocalizer.localize(data, "cs")
  → výstup obsahuje jen "name" s českou hodnotou
```

---

## 4. Konfigurace (`src/Core/Config/`)

| Třída | Účel |
|-------|------|
| `ServerConfig` | Načtení a validace `/etc/shipard/server.json` (DB credentials, mód nasazení). |
| `DataSourceConfig` | Načtení a validace `config/main.json` zdroje dat (ID, DB credentials, moduly, defaultLanguage). |
| `ConfigCompiler` | Kompilace konfiguračních souborů ze všech aktivních modulů. Generuje `compiled.{lang}.json` pro každý jazyk. Používá `ConfigLocalizer`. |
| `ConfigRuntime` | Runtime přístup ke zkompilované konfiguraci. Metoda `cfgItem(id)` vrací konfigurační položku. |

**Tok dat při kompilaci:**
```
module.jsonc → config[].file → JSONC soubory
  → JsoncParser → ConfigLocalizer (per jazyk)
  → compiled.cs.json, compiled.en.json
```

**Tok dat za běhu:**
```
compiled.cs.json → ConfigRuntime::load() → cfgItem('economy.docs.vatRates')
```

---

## 5. Modulový systém (`src/Core/Module/`)

| Třída | Účel |
|-------|------|
| `ModuleDefinition` | Datová třída pro `module.jsonc` — id, name, dependencies, tables, extensions, config. Factory `fromArray()`. |
| `ModuleLoader` | Načítání modulů ze souborového systému. `loadModule(path)` pro jeden modul, `loadAllModules(basePath)` pro všechny. Prochází `modules/{skupina}/{modul}/`. |
| `ModuleResolver` | Řešení závislostí. Vstup: všechny moduly + aktivované ID. Výstup: topologicky seřazené moduly. Detekuje cykly (DFS), řeší tranzitivní závislosti. |

**Tok dat:**
```
modules/{skupina}/{modul}/module.jsonc
  → ModuleLoader.loadAllModules()
  → ModuleResolver.resolve(definitions, activeIds)
  → seřazené pole ModuleDefinition[]
```

---

## 6. Databázová vrstva (`src/Core/Database/`)

### Definice schématu

| Třída | Účel |
|-------|------|
| `ColumnDefinition` | Datová třída pro sloupec — id, type, length, precision, scale, nullable, default, cfgItem, group atd. Validace podle typu. |
| `IndexDefinition` | Datová třída pro index — id, type (index/unique/fulltext), columns s order (ASC/DESC). |
| `TableDefinition` | Datová třída pro tabulku — tableId, name, columnGroups, columns, indexes. Factory `fromArray()`. Validace PK. |
| `ExtensionDefinition` | Datová třída pro extension — table (cílová), columns, columnGroups, indexes. Factory `fromArray()`. |
| `TableMerger` | Sloučení `TableDefinition` + `ExtensionDefinition`. Přidá sloupce, indexy, columnGroups. Detekuje kolize. |

### Správa schématu

| Třída | Účel |
|-------|------|
| `SchemaComparator` | Porovná `TableDefinition` s existujícím stavem DB. Vrací seznam operací (create_table, add_column, modify_column, create_index). Rozpozná bezpečné změny. |
| `SchemaValidator` | Validace definic před upgrade — duplicitní tableId, chybějící PK, kolize sloupců/indexů. Vrací chyby (fatální) a varování. |
| `SqlGenerator` | Generování SQL z definic — CREATE TABLE, ALTER TABLE ADD/MODIFY, CREATE INDEX. Ošetřuje specifika typů (enumString → CHAR + ASCII, numeric → NUMERIC(p,s), boolean → TINYINT(1)). |
| `DatabaseManager` | Správa DB na úrovni serveru — vytváření/mazání databází a uživatelů. Používá admin credentials ze ServerConfig. |
| `DataSourceConnection` | Připojení k DB konkrétního zdroje dat přes Dibi. Používá credentials z DataSourceConfig. |

**Tok dat při ds-upgrade:**
```
TableDefinition + ExtensionDefinition
  → TableMerger.merge()
  → SchemaValidator.validate()
  → SchemaComparator.compare(definition, existingDB)
  → SqlGenerator.generateXxx()
  → Dibi execute
```

---

## 7. CLI příkazy (`src/Command/`)

### Server (`src/Command/Server/`)

| Příkaz | Třída | Popis |
|--------|-------|-------|
| `shpd-server version` | `VersionCommand` | Zobrazí verzi |
| `shpd-server help` | `HelpCommand` | Nápověda |
| `shpd-server ds-create` | `DsCreateCommand` | Vytvoří nový zdroj dat (adresář, DB, uživatel, config) |
| `shpd-server server-init` | `ServerInitCommand` | Inicializace serveru |
| `shpd-server next-table-id` | `NextTableIdCommand` | Projde moduly, vypíše další volné tableId |

### Data Source (`src/Command/DataSource/`)

| Příkaz | Třída | Popis |
|--------|-------|-------|
| `shpd-ds version` | `VersionCommand` | Zobrazí verzi |
| `shpd-ds help` | `HelpCommand` | Nápověda |
| `shpd-ds ds-upgrade` | `DsUpgradeCommand` | Kompilace konfigurace + kontrola/aktualizace DB schématu |

**DsUpgradeCommand** je nejkomplexnější příkaz — orchestruje celý flow:
1. `DataSourceConfig` → načte main.json
2. `ModuleLoader` → načte všechny moduly
3. `ModuleResolver` → vyřeší závislosti, topologické řazení
4. `TableDefinition` + `TableMerger` → sestaví definice tabulek s extensions
5. `ConfigCompiler` → zkompiluje konfigurace per jazyk
6. `SchemaValidator` → validace
7. `SchemaComparator` + `SqlGenerator` → DB změny

---

## 8. Mapa závislostí mezi třídami

```
JsoncParser ←── ModuleLoader
             ←── ConfigCompiler
             ←── TableDefinition (načítání z JSONC)

LocalizedFieldResolver ←── ConfigLocalizer ←── ConfigCompiler

ModuleDefinition ←── ModuleLoader ←── ModuleResolver

ColumnDefinition ─┐
IndexDefinition  ─┼── TableDefinition ←── TableMerger
                  │                    ←── SchemaComparator
ExtensionDefinition ──── TableMerger

SchemaComparator ─┐
SqlGenerator     ─┼── DsUpgradeCommand
SchemaValidator  ─┤
ConfigCompiler   ─┤
ModuleResolver   ─┤
ModuleLoader     ─┘

ServerConfig ←── DsCreateCommand, ServerInitCommand
DataSourceConfig ←── DsUpgradeCommand
DatabaseManager ←── DsCreateCommand
DataSourceConnection ←── DsUpgradeCommand
```

---

## 9. Konvence v kódu

### Datové třídy (Definition)
- Readonly properties nebo gettery
- Factory metoda `fromArray(array $data): self` pro vytvoření z parsovaného JSONC/JSON
- Validace v konstruktoru nebo factory metodě
- Žádná business logika

### Příkazy (Command)
- Dědí ze Symfony Console `Command`
- Testovatelné přes subclassing (přetížení závislostí pro mockování)
- Formátovaný výstup přes Symfony Console output

### Testování
- Unit testy v `tests/Unit/`, zrcadlí `src/`
- DB-dependent testy: mockování přes reflexi nebo subclassing
- Fixture data: inline v testech nebo v `tests/fixtures/`
