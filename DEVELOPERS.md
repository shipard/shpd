# Průvodce vývojáře — Shipard

Vítej v projektu Shipard! Tenhle dokument tě provede od nuly k funkčnímu vývojovému prostředí.

---

## Požadavky

- **Ubuntu LTS** — 22.04 nebo 24.04
- **git** (obvykle předinstalovaný — pokud není, `sudo apt install git`)
- **root přístup** přes `sudo` pro one-time instalaci

---

## 1. Stažení repozitáře

```bash
git clone git@github.com:shipard/shpd.git ~/sw/shpd
cd ~/sw/shpd
```

---

## 2. Instalace systémových balíčků a setup

```bash
sudo bash scripts/install-packages.sh --mode=development
```

Skript je idempotentní a zařídí:

- Instalaci PHP 8.5, MariaDB, nginx, composer a rozšíření
- Vytvoření `/opt/shipard/` (datový root) a `/etc/shipard/` (config root)
  s ownership vlastněným tvým uživatelem (detekce přes `$SUDO_USER`)
- Konfiguraci samostatného **PHP-FPM poolu `shipard`** běžícího pod tvým
  uživatelem (žádný group hack se `www-data`)
- Symlink `/opt/shipard/shpd` → tento clone (kvůli nginx root path)
- Aktivaci nginx site `shipard.conf` (existující `development.conf` se
  uloží jako `.disabled-TIMESTAMP`)

Permission kontrakt je popsán v
[`docs/operations/permissions.md`](docs/operations/permissions.md).

---

## 3. Instalace PHP závislostí

```bash
composer install
```

---

## 4. Inicializace server configu

```bash
sudo shpd-server server-init --mode=development
```

Vytvoří `/etc/shipard/server.json` s admin DB credentials. Soubor má
ownership `root:<tvůj-user>` a mode `0640` — root ho edituje, ty čteš
přes group membership.

---

## 5. Ověření

```bash
shpd-server doctor
```

Vypíše report: mode, shipard-user, PHP-FPM pool user, kontrolu cest,
DB connection per DS. Exit 0 = vše OK.

Pokud něco nesouhlasí (typicky po migraci ze starého layoutu):

```bash
sudo shpd-server fix-permissions --dry-run    # preview
sudo shpd-server fix-permissions              # apply
```

---

## 6. Po `git pull`

Po každém stažení nové verze:

```bash
bash scripts/dev-update.sh
```

Skript vždy spustí `composer install`, `npm install` (ve `frontend/`)
a `npm run build`. Všechny kroky jsou idempotentní — pokud se nic
nezměnilo, projdou během pár sekund.

Pokud se měnily definice tabulek nebo cfgItems modulů, je potřeba
zaktualizovat i datové zdroje:

```bash
shpd-server ds-upgrade-all
```

### Automatizace přes git hooks (volitelné)

```bash
git config core.hooksPath .githooks
```

Stačí spustit jednou v repu.

---

## 7. CLI utility

Přehled v [`docs/cli.md`](docs/cli.md). Po základním setupu nepotřebuješ
`sudo` pro běžné shipard operace.

### `shpd-server` — správa serveru

| Příkaz | Popis |
|--------|-------|
| `shpd-server version` | Verze aplikace |
| `shpd-server help` | Nápověda |
| `shpd-server ds-create --name <název>` | Vytvoří nový datový zdroj |
| `shpd-server ds-upgrade-all` | Spustí `ds-upgrade` na všech DS |
| `shpd-server doctor` | Health check kontraktu a DB konektivity |
| `sudo shpd-server fix-permissions` | Opraví ownership/mode dle kontraktu |
| `sudo shpd-server server-init` | Inicializace server configu |
| `shpd-server next-table-id` | Vrátí další volné tableId |

### `shpd-ds` — správa datového zdroje

Spouštěj z adresáře datového zdroje (musí obsahovat `config/main.json`).

| Příkaz | Popis |
|--------|-------|
| `shpd-ds version` | Verze aplikace |
| `shpd-ds help` | Nápověda |
| `shpd-ds ds-upgrade` | Synchronizuje DB schéma podle modulů |
| `shpd-ds ds-secrets-health` | Kontrola encryption key pro `encrypted_text` |
| `shpd-ds ds-secrets-rotate` | Rotace encryption key |

---

## 8. Migrace ze staršího layoutu

Pokud máš dev box s ručně postaveným starým layoutem (typicky
`sebik:www-data` group hack), proveď:

```bash
sudo bash scripts/install-packages.sh --mode=development
sudo shpd-server fix-permissions
shpd-server doctor    # ověř že je vše zelené
```

Detaily v [`docs/operations/permissions.md`](docs/operations/permissions.md).

---

## 9. Kam dál

- **Architektura projektu:** [`docs/architecture.md`](docs/architecture.md)
- **Modulový systém:** [`docs/modules.md`](docs/modules.md)
- **Definice databázových tabulek:** [`docs/table-definitions.md`](docs/table-definitions.md)
- **Permission kontrakt:** [`docs/operations/permissions.md`](docs/operations/permissions.md)
- **CLI reference:** [`docs/cli.md`](docs/cli.md)
- **Přehled dokumentace:** [`docs/README.md`](docs/README.md)
- **Hlavní README:** [`README.md`](README.md)
