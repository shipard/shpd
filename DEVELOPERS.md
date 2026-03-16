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

## 5. CLI utility

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

## 6. Kam dál

- **Architektura projektu:** [`docs/architecture.md`](docs/architecture.md)
- **Modulový systém:** [`docs/modules.md`](docs/modules.md)
- **Definice databázových tabulek:** [`docs/table-definitions.md`](docs/table-definitions.md)
- **Přehled dokumentace:** [`docs/README.md`](docs/README.md)
- **Hlavní README:** [`README.md`](README.md)
