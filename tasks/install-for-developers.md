# Task: Dokumentace a instalační skript pro nové vývojáře

## Kontext

Chceme projekt Shipard zpřístupnit dalším vývojářům. Cíl: člověk se základními znalostmi Linuxu si za pár minut rozjede vývojové prostředí a může nám dávat zpětnou vazbu.

Všechny potřebné informace o projektu najdeš v `CLAUDE.md` v kořeni repozitáře.

## Co je potřeba udělat

### 1. Vytvořit složku `scripts/` a instalační skript `scripts/install-packages.sh`

Bash skript pro čisté Ubuntu LTS, který nainstaluje všechny potřebné systémové balíčky.

Požadavky na skript:
- Musí běžet jako root (nebo přes sudo) — na začátku zkontroluj a případně ukonči s chybou
- **Nejprve** nainstaluj prerekvizity pro PPA: `ca-certificates`, `apt-transport-https`, `software-properties-common`
- **Pak** přidej PPA pro PHP: `add-apt-repository --yes ppa:ondrej/php`
- **Pak** `apt update`
- Nainstaluj tyto balíčky:
  - `php8.5-cli`, `php8.5-fpm`
  - PHP rozšíření potřebná pro projekt — podívej se do `composer.json` na požadovaná rozšíření a podle toho urči, jaké `php8.5-*` balíčky jsou potřeba (minimálně: `php8.5-mysql`, `php8.5-xml`, `php8.5-mbstring`, `php8.5-curl`, `php8.5-zip`, `php8.5-intl`)
  - `mariadb-server`
  - `nginx`
  - `composer`
  - `git` (pro úplnost)
  - `unzip` (composer ho potřebuje)
- Na konci vypiš shrnutí, co bylo nainstalováno (verze PHP, MariaDB)
- Skript **neřeší** konfiguraci nginx ani MariaDB — jen instalaci balíčků
- Přidej komentáře v angličtině, aby byl skript srozumitelný

### 2. Vytvořit `DEVELOPERS.md` v kořeni repozitáře

Průvodce pro nového vývojáře, napsaný v češtině. Struktura:

1. **Požadavky** — Ubuntu LTS, git
2. **Instalace systémových balíčků** — odkaz na `scripts/install-packages.sh` s příkazem ke spuštění
3. **Stažení repozitáře**:
   ```
   git clone git@github.com:shipard/shpd.git
   cd shpd
   ```
4. **Instalace PHP závislostí**:
   ```
   composer install
   ```
5. **Základní ověření instalace** — jak ověřit, že vše funguje:
   ```
   php bin/shpd-server version
   php bin/shpd-server help
   ```
6. **Přehled CLI utilit** — stručný popis `shpd-server` a `shpd-ds` a jejich základních příkazů (vezmi z CLAUDE.md)
7. **Kam dál** — odkaz na `docs/architecture.md` a na hlavní `README.md`

Styl: přátelský, stručný, krok za krokem. Jako bys psal kolegovi, který se chce zapojit.

### 3. Aktualizovat `README.md` v kořeni repozitáře

Aktuální README.md má jen nadpis a je jinak prázdné. Doplň:

- **Úvodní odstavec** — popis projektu Shipard. Podívej se do `CLAUDE.md` na informace o projektu (online SaaS účetní systém, PHP, MariaDB...) a napiš stručný, výstižný popis. Zaměř se na to, co projekt dělá a proč existuje, spíš než na technologie. Piš česky.
- **Sekce "Pro vývojáře"** — odkaz na `DEVELOPERS.md` s jednou větou typu "Návod na rozjetí vývojového prostředí najdete v DEVELOPERS.md"
- **Sekce "Dokumentace"** — odkaz na složku `docs/`
- **Sekce "Technologie"** — krátký seznam (PHP 8.5, MariaDB, nginx, Symfony Console, Dibi...)

README má být stručné — je to rozcestník, ne román.

### 4. Vytvořit `docs/README.md`

Obsah dokumentace — rozcestník pro složku `docs/`. Tento soubor se zobrazí, když někdo na GitHubu otevře složku `docs/`.

Obsah:
- Nadpis "Dokumentace"
- Seznam odkazů na existující dokumenty ve složce `docs/` — podívej se, co tam je (minimálně `architecture.md`), a pro každý soubor přidej odkaz s krátkým popisem (jedna věta)
- Odkaz zpět na hlavní `README.md` a na `DEVELOPERS.md`

**Důležité:** Odkazy musí fungovat na GitHubu — tj. relativní cesty typu `[Architektura](architecture.md)`, ne absolutní URL.

## Obecné pokyny

- Všechny dokumenty piš **česky** (kromě komentářů v bash skriptu, ty anglicky)
- Drž se stručného, přehledného stylu
- Nepiš nic, co nevíš — informace čerpej z `CLAUDE.md` a ze struktury projektu
- U instalačního skriptu zkontroluj `composer.json`, abys věděl, jaká PHP rozšíření jsou skutečně potřeba
- Před psaním `docs/README.md` se podívej, jaké soubory ve složce `docs/` skutečně existují
