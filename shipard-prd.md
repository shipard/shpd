# Shipard — Product Requirements Document

## 1. Přehled projektu

**Název produktu:** Shipard
**Typ:** Online SaaS účetní systém
**Počáteční verze:** 0.1.0
**Fáze:** Backend + CLI utility (bez frontendu)

Shipard je modulární účetní systém navržený pro provoz na vlastním serveru. Systém podporuje multi-tenancy — na jednom serveru může běžet libovolné množství nezávislých zdrojů dat, každý se svou vlastní databází a konfigurací.

---

## 2. Terminologie

| Pojem | Definice |
|-------|---------|
| **Databáze** | Fyzická databáze běžící na databázovém serveru (MariaDB/PostgreSQL) |
| **Zdroj dat (data source)** | Kompletní provozní jednotka pro jednu firmu — zahrnuje databázi, konfigurační soubory, www-root a vše potřebné pro chod systému |
| **Codebase** | Zdrojový kód aplikace umístěný v `/opt/shipard/shpd` |
| **Server** | Fyzický nebo virtuální stroj, na kterém běží instance Shipard |

---

## 3. Technologický stack

| Komponenta | Technologie |
|------------|------------|
| OS | Ubuntu LTS (Linux) |
| Jazyk | PHP 8.5 |
| Správa závislostí | Composer |
| Databázová vrstva | Dibi (https://github.com/dg/dibi) |
| Primární DB | MariaDB |
| Výhledová DB | PostgreSQL |
| Webový server | nginx + php-fpm |
| CLI framework | symfony/console |
| Testování | PHPUnit |

### Composer závislosti (počáteční)

- `dibi/dibi` — databázová abstrakce
- `symfony/console` — CLI framework (příkazy, argumenty, volby, nápověda, formátovaný výstup)
- `phpunit/phpunit` (dev) — testovací framework

---

## 4. Adresářová struktura na serveru

```
/etc/shipard/
└── server.json                    # Konfigurace serveru

/opt/shipard/
├── shpd/                          # Codebase (repozitář nebo symlink)
│   ├── composer.json
│   ├── composer.lock
│   ├── vendor/                    # Composer závislosti
│   ├── bin/
│   │   ├── shpd-server            # CLI utilita pro správu serveru
│   │   └── shpd-ds                # CLI utilita pro správu zdroje dat
│   ├── src/
│   │   ├── Core/
│   │   │   ├── Config/
│   │   │   │   ├── ServerConfig.php       # Načtení a validace server.json
│   │   │   │   └── DataSourceConfig.php   # Načtení a validace main.json zdroje dat
│   │   │   ├── Database/
│   │   │   │   └── DatabaseManager.php    # Správa DB připojení, vytváření DB a uživatelů
│   │   │   └── Utils/
│   │   │       └── IdGenerator.php        # Generování ID zdrojů dat
│   │   └── Command/
│   │       ├── Server/
│   │       │   ├── VersionCommand.php     # shpd-server version
│   │       │   ├── HelpCommand.php        # shpd-server help
│   │       │   └── DsCreateCommand.php    # shpd-server ds-create
│   │       └── DataSource/
│   │           ├── VersionCommand.php     # shpd-ds version
│   │           └── HelpCommand.php        # shpd-ds help
│   └── tests/
│       ├── Unit/
│       │   ├── Core/
│       │   │   ├── Config/
│       │   │   │   ├── ServerConfigTest.php
│       │   │   │   └── DataSourceConfigTest.php
│       │   │   ├── Database/
│       │   │   │   └── DatabaseManagerTest.php
│       │   │   └── Utils/
│       │   │       └── IdGeneratorTest.php
│       │   └── Command/
│       │       └── Server/
│       │           └── DsCreateCommandTest.php
│       └── bootstrap.php
│
└── data-sources/                  # Adresář se zdroji dat
    └── {data-source-id}/          # Jeden zdroj dat
        └── config/
            └── main.json          # Konfigurace zdroje dat
```

### Symbolické odkazy (symlinks)

V produkčním i vývojovém prostředí se vytvoří symlinky:

```
/usr/local/bin/shpd-server -> /opt/shipard/shpd/bin/shpd-server
/usr/local/bin/shpd-ds     -> /opt/shipard/shpd/bin/shpd-ds
```

---

## 5. Konfigurační soubory

### 5.1 Server — `/etc/shipard/server.json`

```json
{
    "version": "0.1.0",
    "mode": "production",
    "database": {
        "host": "127.0.0.1",
        "port": 3306,
        "adminUser": "shipard_admin",
        "adminPassword": "silne-heslo-zde"
    }
}
```

| Pole | Typ | Popis |
|------|-----|-------|
| `version` | string | Verze konfiguračního formátu |
| `mode` | string | Mód nasazení: `development` nebo `production` |
| `database.host` | string | IP adresa / hostname DB serveru |
| `database.port` | int | Port DB serveru |
| `database.adminUser` | string | Administrátorský uživatel DB s právy vytvářet databáze a uživatele |
| `database.adminPassword` | string | Heslo administrátorského uživatele |

### 5.2 Zdroj dat — `/opt/shipard/data-sources/{id}/config/main.json`

```json
{
    "id": "a3f2-b8c1-d4e7-f9a0",
    "name": "Naše firma s.r.o.",
    "database": {
        "name": "a3f2_b8c1_d4e7_f9a0",
        "user": "shpd_a3f2b8c1",
        "password": "nahodne-generovane-heslo"
    },
    "created": "2026-03-12T14:30:00+01:00"
}
```

| Pole | Typ | Popis |
|------|-----|-------|
| `id` | string | Unikátní ID zdroje dat ve formátu `xxxx-xxxx-xxxx-xxxx` (malá písmena a-z + čísla 0-9) |
| `name` | string | Název firmy / zdroje dat |
| `database.name` | string | Název databáze (ID s podtržítky místo pomlček) |
| `database.user` | string | Databázový uživatel s prefixem `shpd_` a zkráceným ID (max 32 znaků) |
| `database.password` | string | Náhodně vygenerované heslo |
| `created` | string | ISO 8601 časové razítko vytvoření |

---

## 6. CLI utility

### 6.1 `shpd-server` — Správa serveru

Spouštění: `shpd-server <příkaz> [volby]`

#### Příkazy

##### `shpd-server version`

Zobrazí verzi systému.

**Výstup:**
```
Shipard v0.1.0
```

##### `shpd-server help`

Zobrazí nápovědu se seznamem dostupných příkazů a jejich popisem.

**Výstup:**
```
Shipard Server Management v0.1.0

Available commands:
  version      Display the current version
  help         Display this help message
  ds-create    Create a new data source

Usage:
  shpd-server <command> [options]
```

##### `shpd-server ds-create --name <název>`

Vytvoří nový zdroj dat. Provede následující kroky:

1. **Validace** — ověří existenci a platnost `/etc/shipard/server.json`
2. **Generování ID** — vytvoří náhodné ID ve formátu `xxxx-xxxx-xxxx-xxxx` (malá písmena + čísly), ověří unikátnost v `/opt/shipard/data-sources/`
3. **Vytvoření adresářové struktury:**
   ```
   /opt/shipard/data-sources/{id}/
   └── config/
       └── main.json
   ```
4. **Vytvoření databáze** — název databáze = ID s podtržítky místo pomlček
5. **Vytvoření DB uživatele** — s prefixem `shpd_`, náhodným heslem a právy pouze na danou databázi
6. **Zápis konfigurace** — uloží `main.json` se všemi údaji
7. **Výstup** — zobrazí souhrn vytvořeného zdroje dat

**Parametry:**

| Parametr | Povinný | Popis |
|----------|---------|-------|
| `--name` | Ano | Název zdroje dat (název firmy) |

**Příklad použití:**
```bash
shpd-server ds-create --name "Naše firma s.r.o."
```

**Úspěšný výstup:**
```
Creating data source "Naše firma s.r.o."...
  ID:        a3f2-b8c1-d4e7-f9a0
  Database:  a3f2_b8c1_d4e7_f9a0
  DB User:   shpd_a3f2b8c1
  Directory: /opt/shipard/data-sources/a3f2-b8c1-d4e7-f9a0/

Data source created successfully.
```

**Chybové stavy:**

| Chyba | Zpráva |
|-------|--------|
| Chybí `--name` | `Error: The --name option is required.` |
| Prázdný `--name` | `Error: Data source name cannot be empty.` |
| Chybí `server.json` | `Error: Server configuration not found at /etc/shipard/server.json` |
| Neplatný `server.json` | `Error: Invalid server configuration: {detail}` |
| Nelze se připojit k DB | `Error: Cannot connect to database server: {detail}` |
| Nelze vytvořit adresář | `Error: Cannot create directory: {detail}` |

---

### 6.2 `shpd-ds` — Správa zdroje dat

Spouštění: `cd /opt/shipard/data-sources/{id} && shpd-ds <příkaz>`

**Detekce zdroje dat:** Utilita zjistí aktuální pracovní adresář a hledá v něm soubor `config/main.json`. Pokud soubor neexistuje, zobrazí chybu:

```
Error: Data source configuration not found.
Expected file: config/main.json in current directory.
Are you in a data source directory? (/opt/shipard/data-sources/{id}/)
```

#### Příkazy

##### `shpd-ds version`

Zobrazí verzi systému (stejnou jako `shpd-server version`).

##### `shpd-ds help`

Zobrazí nápovědu s dostupnými příkazy.

---

## 7. Generování ID zdroje dat

**Formát:** `xxxx-xxxx-xxxx-xxxx` kde `x` je malé písmeno (a-z) nebo číslice (0-9).

**Vlastnosti:**
- Celkem 16 znaků rozdělenných do 4 skupin po 4, oddělených pomlčkami
- Znaková sada: `a-z0-9` (36 znaků, celkem 36^16 ≈ 7.96 × 10^24 kombinací)
- Generováno kryptograficky bezpečným generátorem (`random_bytes` / `random_int`)
- Unikátnost ověřena kontrolou existujících adresářů v `/opt/shipard/data-sources/`

**Odvozené hodnoty:**
- Název databáze: pomlčky nahrazeny podtržítky → `a3f2_b8c1_d4e7_f9a0`
- DB uživatel: prefix `shpd_` + první 2 skupiny bez pomlček → `shpd_a3f2b8c1` (max 13 znaků, v limitu 32)

---

## 8. Databázové operace

### 8.1 Vytvoření databáze

Pro MariaDB:
```sql
CREATE DATABASE `a3f2_b8c1_d4e7_f9a0`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_czech_ci;
```

### 8.2 Vytvoření uživatele a přidělení práv

Pro MariaDB:
```sql
CREATE USER 'shpd_a3f2b8c1'@'localhost'
  IDENTIFIED BY 'nahodne-generovane-heslo';

GRANT ALL PRIVILEGES ON `a3f2_b8c1_d4e7_f9a0`.*
  TO 'shpd_a3f2b8c1'@'localhost';

FLUSH PRIVILEGES;
```

### 8.3 Budoucí podpora PostgreSQL

Dibi umožňuje přepnutí databázového driveru. SQL příkazy pro vytváření databází a uživatelů budou abstrahovány ve třídě `DatabaseManager`, která bude obsahovat implementace pro oba drivery. V první fázi bude implementován pouze MariaDB driver.

---

## 9. Bezpečnost

- **Hesla** se generují kryptograficky bezpečně (`random_bytes`), délka minimálně 32 znaků, alfanumerické + speciální znaky
- **server.json** má nastavena práva `0600` (čitelný pouze vlastníkem)
- **main.json** v každém zdroji dat má práva `0600`
- **DB uživatelé** mají přístup výhradně ke své databázi
- **Admin DB účet** v `server.json` se používá pouze pro vytváření/mazání databází a uživatelů, nikdy pro běžný provoz

---

## 10. Testovací strategie

### 10.1 Framework

PHPUnit 11.x (kompatibilní s PHP 8.5)

### 10.2 Typy testů

#### Unit testy

| Třída | Co se testuje |
|-------|--------------|
| `IdGenerator` | Formát ID, délka, znaková sada, unikátnost při opakovaném generování |
| `ServerConfig` | Načtení platného JSON, chybějící soubor, neplatný JSON, chybějící povinná pole, validace hodnot |
| `DataSourceConfig` | Načtení platného JSON, validace struktury, chybějící pole |
| `DatabaseManager` | Sestavení SQL dotazů pro vytvoření DB a uživatele, generování hesla, odvození názvu DB z ID |
| `DsCreateCommand` | Celý flow vytvoření zdroje dat (s mockovaným DB připojením) |

#### Integrační testy (budoucnost)

- Vytvoření zdroje dat s reálnou databází
- End-to-end test CLI příkazů

### 10.3 Struktura testů

```
tests/
├── Unit/
│   ├── Core/
│   │   ├── Config/
│   │   │   ├── ServerConfigTest.php
│   │   │   └── DataSourceConfigTest.php
│   │   ├── Database/
│   │   │   └── DatabaseManagerTest.php
│   │   └── Utils/
│   │       └── IdGeneratorTest.php
│   └── Command/
│       └── Server/
│           └── DsCreateCommandTest.php
└── bootstrap.php
```

### 10.4 Spouštění testů

```bash
cd /opt/shipard/shpd
vendor/bin/phpunit
```

---

## 11. Composer konfigurace

```json
{
    "name": "shipard/shipard",
    "description": "Shipard — Online SaaS Accounting System",
    "type": "project",
    "license": "proprietary",
    "require": {
        "php": ">=8.5",
        "dibi/dibi": "^5.0",
        "symfony/console": "^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Shipard\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Shipard\\Tests\\": "tests/"
        }
    },
    "bin": [
        "bin/shpd-server",
        "bin/shpd-ds"
    ]
}
```

---

## 12. Požadavky na implementaci pro Claude Code

### Fáze 1 — Kostra projektu

1. Vytvořit adresářovou strukturu projektu
2. Vytvořit `composer.json`
3. Implementovat `IdGenerator` s testy
4. Implementovat `ServerConfig` s testy
5. Implementovat `DataSourceConfig` s testy

### Fáze 2 — Databázová vrstva

6. Implementovat `DatabaseManager` s testy
7. Připojení přes Dibi
8. SQL pro vytvoření databáze a uživatele (MariaDB)

### Fáze 3 — CLI utility

9. Implementovat `bin/shpd-server` s příkazy `version`, `help`, `ds-create`
10. Implementovat `bin/shpd-ds` s příkazy `version`, `help`
11. Integrační test `ds-create`

### Fáze 4 — Nasazení

12. Vytvořit symlinky do `/usr/local/bin`
13. Nastavit oprávnění souborů
14. Dokumentace instalace

---

## 13. Otevřené otázky a budoucí rozšíření

- [ ] Frontend (odloženo)
- [ ] PostgreSQL driver v `DatabaseManager`
- [ ] Správa migrací databázového schématu
- [ ] Mazání zdroje dat (`shpd-server ds-delete`)
- [ ] Výpis zdrojů dat (`shpd-server ds-list`)
- [ ] Záloha a obnova zdroje dat
- [ ] Webové API
- [ ] Autentizace a autorizace uživatelů
- [ ] Logování a monitoring
