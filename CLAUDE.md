# Shipard — CLAUDE.md

## Projekt

Modulární multi-tenant SaaS účetní systém. Backend + CLI utility, bez frontendu.

- **Namespace:** `Shipard\` → `src/`, testy `Shipard\Tests\` → `tests/`
- **PHP 8.5+**, strict_types povinně, PSR-4 autoloading
- **Závislosti:** `dibi/dibi` (DB vrstva), `symfony/console` (CLI), `phpunit/phpunit` (dev)

## Dokumentace

Podrobné specifikace jsou v adresáři `docs/`. Přečti příslušný dokument PŘED implementací.

| Dokument | Obsah |
|----------|-------|
| `docs/architecture.md` | Mapa tříd, vrstvy, závislosti, tok dat — přečti pokud potřebuješ pochopit jak komponenty spolupracují |
| `docs/modules.md` | Modulový systém — struktura modulů, závislosti, JSONC formát, vícejazyčnost (i18n), kompilace konfigurace, CLI příkaz `ds-upgrade` |
| `docs/table-definitions.md` | Formát definice databázových tabulek — datové typy, sloupce, indexy, extensions, validace, bezpečné změny |

## Architektura — rychlý přehled

```
src/
├── Api/                        # REST API vrstva
│   ├── Controller/             # AuthController, CrudController, MetaController, OpenApiController
│   ├── Exception/              # UnknownHostException
│   ├── Middleware/             # AuthMiddleware, CorsMiddleware, RateLimitMiddleware
│   ├── OpenApi/                # SpecGenerator
│   ├── Validation/             # InputValidator
│   └── ...                     # Request, Response, Router, Route, AuthContext,
│                               # DataSourceResolver, ResolvedDataSource, TableLoader
├── Command/                    # CLI příkazy (Symfony Console)
│   ├── Server/                 # shpd-server: ds-create, domain-add/list/remove, ...
│   └── DataSource/             # shpd-ds: ds-upgrade
├── Core/
│   ├── Config/                 # ServerConfig, DataSourceConfig, ConfigCompiler, ConfigRuntime
│   ├── Database/               # TableDefinition, ColumnDefinition, IndexDefinition,
│   │                           # ExtensionDefinition, TableMerger, SchemaComparator,
│   │                           # SchemaValidator, SqlGenerator, DatabaseManager
│   ├── I18n/                   # LocalizedFieldResolver, ConfigLocalizer
│   ├── Module/                 # ModuleDefinition, ModuleLoader, ModuleResolver
│   └── Utils/                  # JsoncParser, IdGenerator
```

Závislosti tečou shora dolů: Api/Command → Core/Module/Config/Database → I18n/Utils.

## Klíčové konvence

### Konfigurace na serveru
- Server config: `/etc/shipard/server.json` (práva 0600)
- Data sources: `/opt/shipard/data-sources/{id}/config/main.json` (práva 0600)

### ID zdroje dat
- Formát: `xxxx-xxxx-xxxx-xxxx` (a-z0-9, 4 skupiny po 4)
- DB name: pomlčky → podtržítka (`abcd_efgh_ijkl_mnop`)
- DB user: `shpd_` + první 2 skupiny bez pomlček (`shpd_abcdefgh`)

### Databáze
- MariaDB přes Dibi (`driver: mysqli`)
- `CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci`
- Žádné FOREIGN KEY — referenční integrita na aplikační úrovni
- Admin účet jen pro CREATE DATABASE / CREATE USER, runtime přes DS uživatele

### Modulový systém
- Moduly v `modules/{skupina}/{modul}/`, ID v tečkové notaci (`economy.docs`)
- Definiční soubory: `.jsonc` (ručně psané), `.json` (generované)
- Vícejazyčnost: suffix `:lang` (`"name:cs": "Název"`), holé pole povinné jako fallback
- Fallback: požadovaný jazyk → `en` → holé pole
- Závislosti: jednoduché (bez verzí), tranzitivní, cyklické = chyba
- Extensions: jen přidávání sloupců/indexů do cizích tabulek

### Databázové tabulky
- Pojmenování: `{skupina}_{modul}_{tabulka}` (snake_case)
- Každá tabulka má `tableId` (unikátní SMALLINT, globálně)
- PK: vždy `id INT AUTO_INCREMENT`
- `enumInt` → SMALLINT, `enumString` → CHAR(len) CHARACTER SET ascii
- `numeric(precision, scale)` pro finance
- `ds-upgrade`: CREATE/ADD/bezpečný MODIFY, nikdy nesmaže

### REST API
- Entry point: `public/index.php` (front controller pro všechny DS)
- Subdoména → DS: mapování přes `/etc/shipard/domains.json`
- Architektura: `src/Api/` — Router, Request, Response, Controller/, Middleware/, Validation/
- Univerzální CRUD: jeden CrudController pro všechny tabulky
- Autentizace: API klíče (`shpd_ak_`) a session tokeny (`shpd_st_`) přes Bearer header
- Formát odpovědí: obálka `{success, data/error, meta}`
- Dokumentace API: `docs/rest-api.md`

### Kódové konvence
- Datové třídy (*Definition): readonly, factory `fromArray()`, validace v konstruktoru
- Příkazy: Symfony Console, testovatelné přes subclassing
- Žádná business logika v datových třídách

### CLI příkazy
- `shpd-server`: `version`, `help`, `ds-create --name`, `server-init`, `next-table-id`
- `shpd-server`: `domain-add --host --ds`, `domain-list`, `domain-remove --host`
- `shpd-ds` (z adresáře DS): `version`, `help`, `ds-upgrade`

## Příkazy pro vývoj

```bash
composer install
vendor/bin/phpunit              # všechny testy musí projít
php bin/shpd-server version     # → Shipard v0.1.0
php bin/shpd-server help
php bin/shpd-ds version         # vyžaduje CWD s config/main.json
```

## Testování

- Testy v `tests/Unit/`, zrcadlí strukturu `src/`
- DB testy: mockování přes reflexi nebo subclassing (viz `TestableDsCreateCommand`)
- void metody v mocku: jen `->method('foo')` bez `willReturn`
- JSONC parser: testovat komentáře v řetězcích, trailing čárky
- I18n: testovat fallback chain (cs → en → holé pole)

## Otevřené úkoly

- PostgreSQL driver v DatabaseManager/SqlGenerator
- `ds-delete`, `ds-list` příkazy
- Frontend (SPA)
- Detekce chybějících překladů (validační nástroj)
- Servisní chod pro výmaz nepotřebných tabulek/sloupců
