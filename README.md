# Shipard

Modulární multi-tenant SaaS účetní systém. PHP/MariaDB backend s CLI utilitami — zatím bez frontendu.

Navržen pro správu více nezávislých datových zdrojů (firem, organizací) na jednom serveru s plnou izolací dat.

---

## Pro vývojáře

Jak rozjet vývojové prostředí na Ubuntu LTS za pár minut: [DEVELOPERS.md](DEVELOPERS.md)

## Dokumentace

Technické specifikace, architektura a formáty konfigurace: [docs/](docs/)

## Technologie

- **PHP 8.5** — strict types, PSR-4
- **MariaDB** — utf8mb4, bez foreign keys (referenční integrita na aplikační úrovni)
- **Symfony Console** — CLI příkazy
- **Dibi** — databázová vrstva (mysqli driver)
- **nginx** — webový server (pro budoucí API a frontend)
- **libsodium AES-256-GCM** — per-DS šifrování citlivých dat (`encrypted_text` sloupce); viz [docs/operations/secrets.md](docs/operations/secrets.md)
