# Shipard

Modulární multi-tenant systém pro správu firemní agendy — účetnictví,
dokumenty a zpracování došlé pošty s AI analýzou. PHP/MariaDB backend,
REST API a Svelte frontend.

Navržen pro správu více nezávislých datových zdrojů (firem, organizací)
na jednom serveru s plnou izolací dat.

> **Stav:** alfa. Hlavní subsystémy běží, ale projekt se aktivně vyvíjí
> a leccos se ještě mění. Hledáme testery — jak se připojit a jak hlásit
> problémy je v [TESTERS.md](TESTERS.md) (uživatelé)
> a [DEVELOPERS.md](DEVELOPERS.md) (vývojáři).

---

## Chci to vyzkoušet

Nejsi vývojář a chceš si Shipard zkusit na vlastní agendě? Aplikaci
provozujeme, stačí prohlížeč — jak si říct o přístup, co si zkusit
a jak nahlásit chybu: [TESTERS.md](TESTERS.md)

## Pro vývojáře

Jak rozjet vývojové prostředí na Ubuntu LTS za pár minut: [DEVELOPERS.md](DEVELOPERS.md)

## Komunita

Rychlý dotaz, nejasnost, nebo si jen chceš popovídat o tom, kam Shipard
míří? Máme **[Discord](https://discord.gg/PWTt5EUFAV)** — je nás tam
zatím hrstka, ale odpovídáme rychle a žádná otázka není moc malá.
Přijď klidně jen nakouknout.

## Dokumentace

Jak se v Shipardu dělají věci (návody pro uživatele): [help/](help/README.md)

Technické specifikace, architektura a formáty konfigurace: [docs/](docs/)

Co aplikace umí, co částečně a co plánujeme: [docs/features.md](docs/features.md)

Kam projekt směřuje a v jakém pořadí: [docs/roadmap.md](docs/roadmap.md)

## Technologie

- **PHP 8.5** — strict types, PSR-4
- **MariaDB** — utf8mb4, bez foreign keys (referenční integrita na aplikační úrovni)
- **Svelte 5** — frontend (SPA)
- **Symfony Console** — CLI příkazy
- **Dibi** — databázová vrstva (mysqli driver)
- **nginx** — webový server (REST API + frontend)
- **Anthropic API + MCP** — AI subsystém (analýza došlé pošty, chat); zatím Anthropic, další poskytovatelé budou přibývat. MCP server běží nativně in-process.
- **libsodium AES-256-GCM** — per-DS šifrování citlivých dat (`encrypted_text` sloupce); viz [docs/operations/secrets.md](docs/operations/secrets.md)
