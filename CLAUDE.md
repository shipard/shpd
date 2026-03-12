# Shipard — CLAUDE.md

## Projekt

Modulární multi-tenant SaaS účetní systém. Backend + CLI utility, bez frontendu.

- **Namespace:** `Shipard\` → `src/`, testy `Shipard\Tests\` → `tests/`
- **PHP 8.5+**, strict_types povinně, PSR-4 autoloading
- **Závislosti:** `dibi/dibi` (DB vrstva), `symfony/console` (CLI), `phpunit/phpunit` (dev)

## Klíčové konvence

### Konfigurace na serveru
- Server config: `/etc/shipard/server.json` (práva 0600)
- Data sources: `/opt/shipard/data-sources/{id}/config/main.json` (práva 0600)

### ID zdroje dat
- Formát: `xxxx-xxxx-xxxx-xxxx` (a-z0-9, 4 skupiny po 4)
- DB name: pomlčky → podtržítka (`abcd_efgh_ijkl_mnop`)
- DB user: `shpd_` + první 2 skupiny bez pomlček (`shpd_abcdefgh`)
- Unikátnost: kontrola existence adresáře v data-sources dir

### Databáze
- MariaDB přes Dibi (`driver: mysqli`)
- `CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci`
- Admin účet (ze server.json) slouží jen pro CREATE DATABASE / CREATE USER
- Každý data source má vlastního DB uživatele s právy jen na svou databázi

### CLI příkazy
- `shpd-server`: správa serveru (spustit odkudkoli)
- `shpd-ds`: správa data source (spouštět z adresáře data source, vyžaduje `config/main.json` v CWD)

## Příkazy pro vývoj

```bash
composer install
vendor/bin/phpunit          # všechny testy musí projít
php bin/shpd-server version # → Shipard v0.1.0
php bin/shpd-server help
php bin/shpd-ds version     # vyžaduje CWD s config/main.json
```

## Testování

- Testy v `tests/Unit/`, zrcadlí strukturu `src/`
- `DatabaseManager` testovat přes reflexi (bez reálného DB připojení)
- Subclassing příkazů pro testování (viz `TestableDsCreateCommand` v `DsCreateCommandTest`, `TestableServerInitCommand` v `ServerInitCommandTest`)
- void metody v mocku: jen `->method('foo')` bez `willReturn`

## Otevřené úkoly (budoucí fáze)

- PostgreSQL driver v `DatabaseManager`
- `ds-delete`, `ds-list` příkazy
- Migrace DB schématu
- Webové API + frontend
