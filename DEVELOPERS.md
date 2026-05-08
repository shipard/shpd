# Průvodce vývojáře — Shipard

Vítej v projektu Shipard! Tenhle dokument tě provede od nuly k funkčnímu vývojovému prostředí.

---

## Požadavky

- **Ubuntu LTS** — 22.04 nebo 24.04
- **git** (obvykle předinstalovaný - pokud není, proveď `sudo apt install git`)

---

## 1. Stažení repozitáře

```bash
git clone git@github.com:shipard/shpd.git
cd shpd
```

---

## 2. Instalace systémových balíčků

Skript nainstaluje PHP 8.5, MariaDB, nginx, composer a potřebná rozšíření:

```bash
sudo bash scripts/install-packages.sh
```

---

## 3. Instalace PHP závislostí

```bash
composer install
```

---

## 4. Ověření instalace

```bash
shpd-server version
shpd-server help
```

Výstup první příkazu by měl být `Shipard <číslo-verze>`.

---

## 5. Po `git pull`

Po každém stažení nové verze je potřeba zaktualizovat závislosti a
frontend build:

```bash
bash scripts/dev-update.sh
```

Skript vždy spustí `composer install`, `npm install` (ve `frontend/`)
a `npm run build`. Všechny kroky jsou idempotentní — pokud se nic
nezměnilo, projdou během pár sekund.

Pokud se měnily definice tabulek nebo cfgItems modulů, je potřeba
zaktualizovat i datové zdroje:

```bash
sudo shpd-server ds-upgrade-all
```

### Automatizace přes git hooks (volitelné)

Aby se `dev-update.sh` pouštěl automaticky po `git pull`,
`git pull --rebase` a `git checkout <branch>`:

```bash
git config core.hooksPath .githooks
```

Stačí spustit jednou v repu. Hook skripty jsou součástí repozitáře.

---

## 6. CLI utility

Projekt obsahuje dvě CLI utility:

### `shpd-server` — správa serveru

Spouštěj odkudkoliv.

| Příkaz | Popis |
|--------|-------|
| `shpd-server version` | Verze aplikace |
| `shpd-server help` | Nápověda |
| `shpd-server ds-create --name <název>` | Vytvoří nový datový zdroj |
| `shpd-server server-init` | Inicializuje server (generuje server config) |
| `shpd-server next-table-id` | Vrátí další volné tableId |

### `shpd-ds` — správa datového zdroje

Spouštěj z adresáře datového zdroje (musí obsahovat `config/main.json`).

| Příkaz | Popis |
|--------|-------|
| `shpd-ds version` | Verze aplikace |
| `shpd-ds help` | Nápověda |
| `shpd-ds ds-upgrade` | Synchronizuje databázové schéma podle modulů |

---

## 7. Kam dál

- **Architektura projektu:** [`docs/architecture.md`](docs/architecture.md)
- **Modulový systém:** [`docs/modules.md`](docs/modules.md)
- **Definice databázových tabulek:** [`docs/table-definitions.md`](docs/table-definitions.md)
- **Přehled dokumentace:** [`docs/README.md`](docs/README.md)
- **Hlavní README:** [`README.md`](README.md)
