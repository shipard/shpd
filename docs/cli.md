# CLI nástroje — kompletní reference

Shipard má dvě hlavní CLI utility a dva podpůrné shell skripty. Dohromady
pokrývají instalaci serveru, vytváření a správu datových zdrojů (DS),
údržbu (secrets, mail-router, AI analyzer) a vývojový workflow.

| Nástroj | Účel | Spouštět z |
|---------|------|-----------|
| `shpd-server` | Správa serveru a DS na úrovni serveru (vytváření DS, hromadné upgrade, mapování domén) | libovolný CWD |
| `shpd-ds` | Správa konkrétního datového zdroje (schéma, uživatelé, secrets, mail, AI, seedy) | adresář DS (musí obsahovat `config/main.json`) |
| `scripts/dev-update.sh` | Po `git pull` — sync composer/npm/build | repo root (skript si dohledá sám) |
| `scripts/install-packages.sh` | Jednorázová instalace systémových balíčků (PHP, MariaDB, nginx, …) | repo root, jako root |

`scripts/install-packages.sh` vytvoří symlinky `/usr/bin/shpd-server`
a `/usr/bin/shpd-ds`, takže oba nástroje jsou poté volatelné odkudkoliv.
V repu lze nástroje spustit i přímo přes `php bin/shpd-server` /
`php bin/shpd-ds` — stejné chování, žádný setup nepotřeba.

---

## `shpd-server` — kompletní reference

Server-level příkazy. Nepředpokládají žádný konkrétní DS jako CWD; pracují
nad `/etc/shipard/server.json` a `/opt/shipard/data-sources/`.

### `version`

```bash
shpd-server version
```

Vypíše verzi nástroje včetně git hashe HEAD (např. `Shipard 0.1.1 (abc1234)`;
bez dostupného gitu jen `Shipard 0.1.1`). Žádné options, žádné side-effecty.
Zdroj verze: `Shipard\Core\Version`.

### `help`

```bash
shpd-server help
```

Vypíše seznam příkazů a jejich popis. Default command — `shpd-server` bez
argumentů spustí `help`.

### `server-init`

```bash
sudo shpd-server server-init                              # development, user z $SUDO_USER
sudo shpd-server server-init --mode=production            # default user 'shipard'
sudo shpd-server server-init --mode=development --user=alice
```

Inicializuje server: vytvoří `/etc/shipard/server.json` (ownership `root:<user>`,
mode 0640), vygeneruje admin DB credentials (uživatel pro `CREATE DATABASE` /
`CREATE USER`). Spouští se **jednou** při instalaci serveru, typicky po
`scripts/install-packages.sh`.

| Opce | Význam |
|------|--------|
| `--mode <development\|production>` | Operační režim. Zapíše se do `server.json` jako `mode`. Default: `development`. |
| `--user <name>` | Shipard user (vlastní `/opt/shipard/`, běží jako PHP-FPM pool). Default: `$SUDO_USER` v development, `shipard` v production. |

### `doctor`

```bash
shpd-server doctor
```

Read-only health check. Vypíše:

- **Mode** z `/etc/shipard/server.json`
- **Shipard user** (detekce z owner `/opt/shipard/`)
- **PHP-FPM pool user** (z `/etc/php/*/fpm/pool.d/shipard.conf`)
- **Filesystem checks** — per-cesta kontrola existence + type + owner + group + mode
  dle [permission kontraktu](operations/permissions.md)
- **System config includes** — warn-only: živý nginx site a FPM pool
  includují verzované `shipard-common.conf` / `shipard-fpm-common.conf`
  (viz [production.md §6](operations/production.md)); nikdy nemění exit code
- **Data source DB connections** — pokus o `SELECT 1` na každý DS

Žádné side-effecty. Exit code `0` (vše OK) nebo `1` (alespoň jeden issue).
Bez sudo (jen čte).

### `fix-permissions`

```bash
sudo shpd-server fix-permissions --dry-run        # preview, lze i bez sudo
sudo shpd-server fix-permissions                  # interaktivní confirm
sudo shpd-server fix-permissions --force          # bez confirm
```

Sjednotí ownership a modes dle [permission kontraktu](operations/permissions.md).
Volá `chown`/`chgrp`/`chmod`. Žádné mazání ani vytváření — cesty které
chybí jsou označené jako `fixable: false` a fix-permissions se jich nedotkne.

| Opce | Význam |
|------|--------|
| `--dry-run` | Vypíše plánované změny, nic neaplikuje. Lze spustit i bez sudo. |
| `--force` | Přeskočí interaktivní `[y/N]` konfirmaci |

Typické použití po migraci ze staršího layoutu (`sebik:www-data` group hack):

```bash
sudo bash scripts/install-packages.sh --mode=development
sudo shpd-server fix-permissions
shpd-server doctor    # ověř že vše zelené
```

### `ds-create --name <název> [--module <id>]`

```bash
sudo shpd-server ds-create --name "Moje firma s.r.o."
sudo shpd-server ds-create --name "Moje firma s.r.o." --module=install.base
```

Vytvoří nový datový zdroj:

- vygeneruje unikátní ID (`xxxx-xxxx-xxxx-xxxx`)
- vytvoří `/opt/shipard/data-sources/<id>/` (config, att, cache)
- vytvoří MariaDB databázi a runtime DB uživatele
- vygeneruje `secrets/secrets.key` pro per-DS šifrování `encrypted_text` sloupců
- zapíše `config/main.json` (práva 0600) se zvoleným install modulem

Po vytvoření je třeba spustit `shpd-ds ds-upgrade` z adresáře nového DS,
aby se založilo schéma a načetla výchozí konfigurace modulů.

| Opce | Význam |
|------|--------|
| `--name <název>` | **povinné** — lidsky čitelný název DS |
| `--module <id>` | volitelné (default: `install.base`) — install modul k aktivaci. Musí odpovídat adresáři `modules/install/<suffix>/`, jehož `module.jsonc` má id `install.<suffix>`. Seznam dostupných modulů: `ls modules/install/`. |

Vytvořený `config/main.json` bude obsahovat `"modules": ["<id>"]`. Install
modul je top-level bundle, který deklaruje své závislosti (`core.system`,
`core.attachments`, …), které `ds-upgrade` tranzitivně rozresolvuje.

### `ds-upgrade-all`

```bash
sudo shpd-server ds-upgrade-all
sudo shpd-server ds-upgrade-all --ds=abcd-efgh-ijkl-mnop
sudo shpd-server ds-upgrade-all --dry-run
sudo shpd-server ds-upgrade-all --stop-on-error
```

Pustí `shpd-ds ds-upgrade` na **všech** DS v `/opt/shipard/data-sources/`.
Každý DS běží v subprocesu, takže havárie jednoho neovlivní ostatní.
Závěrem vypíše souhrn `Summary: X upgraded, Y failed`. Návratový kód `0`
jen když projde 100 % DS.

| Opce | Význam |
|------|--------|
| `--ds=<id>` | Pustit jen na DS s daným ID (jinak SUCCESS + zpráva, pokud ID neexistuje) |
| `--stop-on-error` | Po první chybě zastavit (default: pokračovat dalšími DS) |
| `--dry-run` | Jen vypsat seznam DS, které by se upgradovaly |

Použití typicky po `git pull`, pokud se měnily definice tabulek nebo
cfgItems modulů — viz [Workflow scénáře](#workflow-scénáře).

**Verbosity propagace:** `-v` se předává do vnitřního volání
`shpd-ds ds-upgrade` na každém DS:

```bash
sudo shpd-server ds-upgrade-all -v
```

### `upgrade`

```bash
sudo shpd-server upgrade --dry-run       # náhled: příchozí commity + plán kroků
sudo shpd-server upgrade                 # reálný běh
sudo shpd-server upgrade --full          # vynutí composer + frontend build
sudo shpd-server upgrade --skip-ds-upgrade
```

Orchestruje nasazení nové verze: `git pull --ff-only` → `composer install
--no-dev --optimize-autoloader` (jen při změně `composer.json`/`composer.lock`)
→ frontend build (jen při změně pod `frontend/`) → `ds-upgrade-all` →
reload služeb (jen při změně verzovaných systémových confů: `docs/nginx/**`
→ `nginx -t && systemctl reload nginx`, `docs/php/**` → `systemctl reload
php<ver>-fpm`; jen pod rootem, jinak vypíše ruční příkazy) → `doctor`.
Každý krok běží jako subproces — příkaz aktualizuje kód, ze kterého sám běží,
takže orchestrátor od pullu dál nenačítá žádné nové třídy; `ds-upgrade-all`
i `doctor` už běží z nové verze.

Pre-flight: vyžaduje čistý worktree a branch (ne detached HEAD); po fetchi
vypíše příchozí commity. Bez příchozích commitů (a bez `--full`) skončí
`Already up to date.` Selhání kroku běh zastaví (žádný automatický rollback);
selhání doctoru vrátí FAILURE, ale kód zůstává nasazený. Selhání reload
kroku (typicky `nginx -t` nad rozbitým site configem) taky — kód je
nasazený, rozbitý je jen config služby; příkaz vypíše ruční reload příkazy.
Summary: `Upgraded <old> → <new> (<N> commits)`.

Uživatelé: pod rootem běží kroky přes `sudo -u shipard -H`, doctor přímo.
Přímo pod shipard userem běží kroky bez sudo a doctor se přeskočí
(`sudo shpd-server doctor` ručně). Jiný uživatel na produkci → abort.

| Opce | Význam |
|------|--------|
| `--dry-run` | Fetch + výpis příchozích commitů a plánu kroků, nic nemění |
| `--full` | Vynutí composer i frontend krok bez ohledu na změněné soubory |
| `--skip-ds-upgrade` | Přeskočí krok `ds-upgrade-all` |

**Verbosity propagace:** `-v` se předává do vnitřního `ds-upgrade-all`.

### `next-table-id`

```bash
shpd-server next-table-id
```

Vrátí další volné `tableId` napříč všemi moduly (čte `*.jsonc` definice
v `modules/*/*/tables/`). Pomocný příkaz při tvorbě nové tabulky —
hodnotu se vepíše do `tableId` v JSONC definici.

### `domain-add`, `domain-list`, `domain-remove`

```bash
sudo shpd-server domain-add --host firma1.shipard.cz --ds abcd-efgh-ijkl-mnop
shpd-server domain-list
sudo shpd-server domain-remove --host firma1.shipard.cz
```

Mapování hostname → DS ID. Načítá se při HTTP requestu pro výběr DS.

| Příkaz | Opce |
|--------|------|
| `domain-add` | `--host <hostname>` (povinné), `--ds <ds-id>` (povinné) |
| `domain-list` | bez opcí |
| `domain-remove` | `--host <hostname>` (povinné) |

---

## `shpd-ds` — kompletní reference

> **Pozor:** `shpd-ds` se musí spouštět z **adresáře datového zdroje**, který
> obsahuje `config/main.json`. Většina příkazů jinak skončí chybou.
> Typicky `cd /opt/shipard/data-sources/<id>` a poté `sudo shpd-ds <command>`.

### Základ

#### `version`

```bash
shpd-ds version
```

Verze nástroje.

#### `help`

```bash
shpd-ds help
```

Vypíše seznam příkazů.

#### `ds-upgrade`

```bash
sudo shpd-ds ds-upgrade
```

Synchronizuje DS s aktuálním stavem modulů. Šest kroků (všechny idempotentní —
opakované spuštění bez efektu, pokud se nic nezměnilo):

1. **Modules resolve** — spočítá závislosti modulů, ověří absenci cyklů
2. **Table definitions + extensions** — sloučí tabulky modulu s extension sloupci
3. **Config compile** — vygeneruje `compiled.{cs,en}.json` z JSONC zdrojů
4. **Schema sync** — `CREATE TABLE` / `ADD COLUMN` / bezpečný `MODIFY`
   (nikdy nesmaže — viz [docs/table-definitions.md](table-definitions.md))
5. **Provisioning** — výchozí číselníky a referenční data
6. **Secrets health** — varuje, pokud `secrets/secrets.key` chybí nebo má špatná práva

Spustit po každém `git pull`, pokud se měnily definice tabulek nebo
cfgItems modulů.

**Verbosity:** výchozí výstup obsahuje jen akce a varování (`[CREATE]`,
`[ALTER]`, `[INFO]`, `[WARN]`, `[ERROR]`). Pro kompletní výpis včetně
průběhu kompilace konfigurace, kontroly schématu po tabulkách a
provisioner detailů použij `-v`:

```bash
shpd-ds ds-upgrade -v
```

**Vypnutí provisioningu (`skipProvisioning`):** volitelný boolean v
`config/main.json`. Když je `true`, `ds-upgrade` synchronizuje schéma, ale
přeskočí generování referenčních dat (units, druhy položek, fiskální roky,
VAT období, číselné řady, mail router, AI analyzer). Určeno pro import dat
z jiného systému, kde tyto údaje dodává sám import. Po dokončení importu
nastav `skipProvisioning` zpět na `false` a spusť `ds-upgrade` znovu —
provisionery jsou idempotentní, doplní jen chybějící data. Při zapnutém
flagu `ds-upgrade` při každém běhu hlásí `[SKIP] Provisioning disabled via
config`.

#### `ds-reset`

```bash
cd /opt/shipard/data-sources/<id>
sudo shpd-ds ds-reset --dry-run     # co by se smazalo
sudo shpd-ds ds-reset               # interaktivní potvrzení [y/N]
sudo shpd-ds ds-reset -y            # bez potvrzení (skriptování)
sudo shpd-ds ds-reset --keep=core_mail_mailboxes -y
```

Uvede DS do čistého stavu pro **opakovaný test kompletního importu**.
Smaže všechny „datové" tabulky (číselníky, osoby, položky, doklady, došlá
pošta, …) a delegací na `ds-upgrade` znovu vytvoří schéma i referenční data.

Které tabulky reset přežijí, se řeší **deklarativně** polem `keepOnReset`
v `module.jsonc` každého modulu (viz [docs/modules.md](modules.md)). Ve
výchozím stavu zůstávají:

- `core.system` — uživatelé, relace, nastavení, API klíče (vč. importního),
  rate limity (login funguje i po resetu),
- `core.mail` — `core_mail_ai_backends` kvůli zašifrovanému AI klíči
  (`ai-analyzer-set-key`).

Vše ostatní se dropuje, **včetně osiřelých tabulek** po odebraných modulech
(`dropSet` = existující tabulky − `keepSet`). Pokud se dropuje
`core_attachments_files`, vyčistí se i obsah `att/` a `cache/thumbnails/`
(prázdné adresáře `ds-upgrade` následně znovu zajistí).

**Produkční pojistka (`enableReset`):** v `production` módu příkaz tvrdě
odmítne (`FAILURE`) bez jakéhokoliv dropu i DB spojení — je to záměrně
destruktivní vývojový/testovací nástroj. Výjimku má jen zdroj dat vědomě
označený jako testovací: volitelný boolean `"enableReset": true` v jeho
`config/main.json` guard pro tento konkrétní DS obchází. Při uplatnění příkaz
vypíše hlasitý warning (`resetting a PRODUCTION data source`); konfirmační
dotaz zůstává (obchází ho jen `--yes`, ne flag). Flag nikdy nenastavuj na DS
s ostrými daty — `shpd-server doctor` na něj na produkci upozorňuje. Žádný
`--force` neexistuje záměrně. V development módu se flag nečte.

| Opce | Význam |
|------|--------|
| `--keep <tabulka>` | ad-hoc další tabulka k zachování (opakovatelné); aditivní k deklarativní `keepOnReset` |
| `--dry-run` | vypsat `[keep]`/`[drop]` a počty, nic neměnit |
| `--yes`, `-y` | přeskočit potvrzovací dotaz |

### Users

#### `user-create`

```bash
sudo shpd-ds user-create --login=admin --password=Tajne123 --name="Admin" --email=admin@example.com
```

Vytvoří nového uživatele v DS.

| Opce | Význam |
|------|--------|
| `--login <login>` | **povinné** — login |
| `--password <heslo>` | **povinné** — heslo |
| `--name <jméno>` | **povinné** — celé jméno |
| `--email <email>` | volitelné — e-mailová adresa |

### API keys

Generické příkazy pro správu API klíčů libovolného uživatele. Pro
role-specifické bootstrap subsystémů (mail-router, AI analyzer) viz
příslušné sekce níže — ty jsou samostatné a tyto generické příkazy
je nenahrazují.

Klíč žije v `core_system_api_keys`: ukládá se jen SHA-256 hash + 12-znakový
`key_prefix` pro lookup. Plaintext token (`shpd_ak_` + 32 hex chars) se
zobrazí pouze jednou, ihned po vytvoření. Revoke nemaže row — jen nastaví
`is_active = 0`, kvůli auditu.

#### `api-key-create`

```bash
shpd-ds api-key-create --user=alice --name=import-from-old-shipard
shpd-ds api-key-create --user=alice --name=ci-bot --ip=10.0.0.5 --ip=10.0.0.6
shpd-ds api-key-create --user=5 --name=temp --expires=+30d
shpd-ds api-key-create --user=alice@example.com --name=k --expires="2026-12-31 23:59:59"
```

Vygeneruje nový API klíč pro existujícího uživatele. Plaintext token vypíše
ve výstupu — zachyť ho ihned, podruhé už k němu nemáš přístup.

| Opce | Význam |
|------|--------|
| `--user <login\|email\|id>` | **povinné** — uživatel. Ambiguous match (login a email se prolnuly) → chyba, použij `--user=<id>`. |
| `--name <text>` | **povinné** — lidsky čitelný popisek (max 100 znaků). Není unique. |
| `--ip <addr>` | volitelné, lze opakovat nebo dát comma-separated (`--ip=1.2.3.4,5.6.7.8`). Bez opce = bez IP restrikce. |
| `--expires <date>` | volitelné — datum/čas expirace. Akceptuje `YYYY-MM-DD`, `YYYY-MM-DD HH:MM:SS`, relative `+30d` / `+1y`, nebo cokoli, co umí PHP `DateTimeImmutable`. Bez opce = bez expirace. |

#### `api-key-list`

```bash
shpd-ds api-key-list                          # všichni useři, jen aktivní
shpd-ds api-key-list --user=alice             # filter per uživatel
shpd-ds api-key-list --include-inactive       # včetně revokovaných
```

Tabulkový výpis API klíčů. Plaintext se nikdy nezobrazí — jen prefix.
Allowed IPs nejsou v listu (sloupec by byl moc široký).

| Opce | Význam |
|------|--------|
| `--user <login\|email\|id>` | Filtrovat jen klíče tohoto uživatele. |
| `--include-inactive` | Zobrazit i revokované klíče (jinak filtr `is_active = 1`). |

#### `api-key-revoke`

```bash
shpd-ds api-key-revoke --id=42                # interaktivní potvrzení
shpd-ds api-key-revoke --id=42 --yes          # bez potvrzení
shpd-ds api-key-revoke --prefix=aabbccdd1122  # identifikace přes prefix
```

Deaktivuje klíč (`is_active = 0`). Idempotentní — opakované volání na už
revokovaný klíč vrátí success bez změny. Identifikace přes `--id`
(preferované) nebo `--prefix` (přesných 12 znaků); přesně jedno z těch dvou.

| Opce | Význam |
|------|--------|
| `--id <N>` | Numerické ID klíče (`core_system_api_keys.id`). |
| `--prefix <12chars>` | Prefix. Pokud sdílí prefix víc klíčů (vzácné), command vyzve k `--id`. |
| `--yes`, `-y` | Skip interaktivního potvrzení. |

### Secrets

#### `ds-secrets-health`

```bash
shpd-ds ds-secrets-health
```

Health check infrastruktury per-DS šifrování (`secrets/secrets.key`):
existence, práva 0600, velikost klíče, čitelnost. Žádný side-effect.
Detaily v [docs/operations/secrets.md](operations/secrets.md).

#### `ds-secrets-rotate`

```bash
sudo shpd-ds ds-secrets-rotate            # ostrá rotace
sudo shpd-ds ds-secrets-rotate --dry-run  # jen výpis, co by se přešifrovalo
```

**Před spuštěním vždy backupnout DS** (DB dump + adresář). Příkaz vygeneruje
nový `secrets.key`, projde všechny `encrypted_text` sloupce a každou hodnotu
přešifruje pomocí nového klíče. Starý klíč se po úspěšné rotaci přepíše.

| Opce | Význam |
|------|--------|
| `--dry-run` | Vypíše, co by se přešifrovalo, ale nic nezmění |

### Mail

#### `mail-router-bootstrap`

```bash
sudo shpd-ds mail-router-bootstrap
```

Idempotentně zajistí systémového uživatele `_mail_router` a výchozí
mailbox. Spouští se jednou před prvním nasazením mail integrace.

#### `mail-router-setup`

```bash
sudo shpd-ds mail-router-setup
sudo shpd-ds mail-router-setup --force
sudo shpd-ds mail-router-setup --ip 203.0.113.10
```

Vygeneruje (nebo rotuje) API klíč pro externí mail-router.

| Opce | Význam |
|------|--------|
| `--force` | Deaktivovat stávající aktivní klíč a vygenerovat nový |
| `--ip <addr>` | Omezit klíč na konkrétní zdrojovou IP |

#### `mail-idempotency-prune`

```bash
sudo shpd-ds mail-idempotency-prune
sudo shpd-ds mail-idempotency-prune --days 30
```

Odstraní idempotency klíče incoming mailů starší než TTL.

| Opce | Význam |
|------|--------|
| `--days <N>` | TTL v dnech (default: hodnota `IdempotencyStore::TTL_DAYS`) |

### AI Analyzer

#### `ai-analyzer-bootstrap`

```bash
sudo shpd-ds ai-analyzer-bootstrap
```

Idempotentně zajistí systémového uživatele `_ai_analyzer`, výchozí AI
backend a výchozí profil.

#### `ai-analyzer-setup`

```bash
sudo shpd-ds ai-analyzer-setup
sudo shpd-ds ai-analyzer-setup --force
sudo shpd-ds ai-analyzer-setup --ip 203.0.113.10
```

Vygeneruje (nebo rotuje) API klíč pro externí AI analyzer.

| Opce | Význam |
|------|--------|
| `--force` | Deaktivovat stávající aktivní klíč a vygenerovat nový |
| `--ip <addr>` | Omezit klíč na konkrétní zdrojovou IP |

#### `ai-analyzer-set-key`

```bash
sudo shpd-ds ai-analyzer-set-key --api-key=sk-xxx
sudo shpd-ds ai-analyzer-set-key --backend=openai-gpt4 --api-key=sk-xxx
```

Nastaví (nebo rotuje) API klíč na konkrétním AI backendu. Klíč se před
uložením zašifruje přes `DsSecretCipher`.

| Opce | Význam |
|------|--------|
| `--backend <kód>` | Backend code (default: `default`) |
| `--api-key <klíč>` | **povinné** — plaintext API klíč |

#### `ai-profile-reload`

```bash
sudo shpd-ds ai-profile-reload
sudo shpd-ds ai-profile-reload --profile=mail-classifier
sudo shpd-ds ai-profile-reload --dry-run
```

Načte AI profil z JSONC šablony do DB. Použít po změně profilu v repu.

| Opce | Význam |
|------|--------|
| `--profile <kód>` | Konkrétní profil (default: všechny) |
| `--template-path <cesta>` | Override cesty k JSONC šabloně |
| `--force` | Přepsat i když se hash neliší |
| `--dry-run` | Jen ukázat, co by se změnilo |

#### `mail-analysis-reap`

```bash
sudo shpd-ds mail-analysis-reap
```

Uvolní vypršené AI analysis claims (zaseknutí workeři) a re-queueuje
postižené zprávy. Bezpečné spouštět opakovaně (např. z cronu).

### Seed (testovací data)

> Seed příkazy jsou určené pro vývoj a demo. **Nepouštět na produkční DS.**

#### `seed-persons`

```bash
sudo shpd-ds seed-persons
sudo shpd-ds seed-persons --count=100 --with-contacts --with-bank-accounts
sudo shpd-ds seed-persons -c 20 --company-ratio=60
```

Vygeneruje fake osoby s prefixem `TEST-` v názvu — později snadno smazatelné
přes `seed-clear`.

| Opce | Význam |
|------|--------|
| `-c, --count <N>` | Počet osob (default: `50`) |
| `--with-contacts` | Také kontakty |
| `--with-bank-accounts` | Také bankovní účty |
| `--company-ratio <0-100>` | Procento firem (default: `40`) |

#### `seed-clear`

```bash
sudo shpd-ds seed-clear
```

Smaže všechny seedované osoby (`TEST-` prefix) včetně jejich kontaktů
a bankovních účtů.

#### `seed-mail`

```bash
sudo shpd-ds seed-mail
sudo shpd-ds seed-mail -c 60
sudo shpd-ds seed-mail --attachment-ratio=20
```

Vygeneruje fake mailboxy a incoming zprávy pro `core.mail` modul.

| Opce | Význam |
|------|--------|
| `-c, --count <N>` | Počet zpráv (default: `60`, doporučení 40–80) |
| `--attachment-ratio <0-100>` | Procento zpráv s ukázkovou PDF přílohou |

#### `seed-mail-clear`

```bash
sudo shpd-ds seed-mail-clear
```

Smaže všechna seedovaná mail data (`TEST-` mailboxy + `TEST-MSG-` zprávy).

---

## Pomocné skripty

### `scripts/dev-update.sh`

```bash
bash scripts/dev-update.sh
```

Po `git pull` synchronizuje vývojové prostředí — `composer install`,
`npm install`, `npm run build`. Všechny tři kroky jsou idempotentní;
pokud se nic nezměnilo, projdou během pár sekund. Skript lze spustit
z libovolného CWD (sám si dohledá repo root).

Pro automatizaci přes git hooks:

```bash
git config core.hooksPath .githooks
```

Po této jednorázové konfiguraci se `dev-update.sh` spustí automaticky
po `git pull`, `git pull --rebase` a `git checkout <branch>`.

### `scripts/install-packages.sh`

```bash
sudo bash scripts/install-packages.sh --mode=development
sudo bash scripts/install-packages.sh --mode=production
```

Jednorázová idempotentní instalace systémových balíčků (musí běžet jako root):

- PHP 8.5 (cli, fpm, mysql, xml, mbstring, curl, zip, intl)
- MariaDB, nginx
- composer, git, unzip

Dále zařídí:

- Detekci **shipard usera** (development → `$SUDO_USER`, production → systémový `shipard`)
- Vytvoření `/opt/shipard/` (data root) a `/etc/shipard/` (config root) s ownership
  dle [permission kontraktu](operations/permissions.md)
- Symlink `/opt/shipard/shpd` → tento repo clone (kvůli nginx root path)
- Konfiguraci samostatného **PHP-FPM poolu `shipard`** běžícího pod shipard userem
  (`/etc/php/8.5/fpm/pool.d/shipard.conf` + restart php-fpm)
- Aktivaci nginx site `shipard.conf` (existující `development.conf`/`production.conf`
  se uloží jako `.disabled-TIMESTAMP`)
- Symlinky `/usr/bin/shpd-server` a `/usr/bin/shpd-ds` — utility volatelné odkudkoliv

Po instalaci spusť `shpd-server doctor` pro ověření kontraktu.

| Opce | Význam |
|------|--------|
| `--mode <development\|production>` | Operační režim. Default: interaktivní volba. |

---

## Workflow scénáře

### 1. Nasazení nové verze

```bash
sudo shpd-server upgrade --dry-run   # náhled
sudo shpd-server upgrade             # pull + composer + frontend + ds-upgrade-all + doctor
```

Ve vývoji alternativně:

```bash
bash scripts/dev-update.sh
sudo shpd-server ds-upgrade-all   # když se měnily moduly nebo definice tabulek
```

### 2. Vytvoření nového DS

```bash
sudo shpd-server ds-create --name "Moje firma s.r.o."
cd /opt/shipard/data-sources/<id>
sudo shpd-ds ds-upgrade
sudo shpd-ds user-create --login=admin --password=... --name="Admin"
```

### 3. Upgrade všech DS najednou

```bash
sudo shpd-server ds-upgrade-all
```

### 4. Upgrade jen jednoho konkrétního DS

```bash
sudo shpd-server ds-upgrade-all --ds=abcd-efgh-ijkl-mnop
```

### 5. Zapnutí mail integrace na DS

```bash
cd /opt/shipard/data-sources/<id>
sudo shpd-ds mail-router-bootstrap
sudo shpd-ds mail-router-setup           # vrátí API klíč pro externí router
```

### 6. Rotace per-DS šifrovacího klíče

```bash
# 1) vždycky napřed backup (viz docs/migration-guide.md)
cd /opt/shipard/data-sources/<id>
sudo shpd-ds ds-secrets-rotate --dry-run  # ověřit rozsah
sudo shpd-ds ds-secrets-rotate            # provést
sudo shpd-ds ds-secrets-health
```

### 7. Opakovaný test importu

```bash
cd /opt/shipard/data-sources/<id>
# 1. zapnout import mód
#    config/main.json:  "skipProvisioning": true
sudo shpd-ds ds-reset -y            # čistý stav, schéma bez provisioningu
# 2. … spustit import ze starého Shipardu …
# 3. vypnout import mód
#    config/main.json:  "skipProvisioning": false
sudo shpd-ds ds-upgrade             # doplní zbývající referenční data
```

`skipProvisioning` se propíše i přes interní `ds-upgrade` v `ds-reset`,
takže při zapnutém flagu reset rekreuje jen schéma a žádné referenční data
nevytvoří. Po celou dobu opakovaného testování zůstává flag `true`; na
`false` se přepne, až je import hotový „naostro".

---

## Konvence

### Exit codes

- `0` — úspěch
- `1` — chyba (chybějící povinný argument, neexistující DS, nevalidní stav, výjimka)

### Výstupní tagy (Symfony Console)

| Tag | Barva | Použití |
|-----|-------|---------|
| `<info>` | zelená | název příkazu, úspěšný krok |
| `<comment>` | žlutá | nadpisy, varování, sekce v helpu |
| `<error>` | červená | chyby (pozor: ne `<e>` — to není standardní tag) |

### Pojmenování příkazů

| Prefix | Doména |
|--------|--------|
| `ds-*` | datový zdroj jako celek (`ds-create`, `ds-upgrade`, `ds-upgrade-all`, `ds-secrets-*`) |
| `mail-*` | core.mail modul a router |
| `ai-*` | AI analyzer |
| `seed-*` | testovací data |
| `domain-*` | hostname → DS routing |
| `*-bootstrap` | jednorázová idempotentní inicializace (system uživatel, default mailbox, default profil) |
| `*-setup` | opakovatelná konfigurace (typicky generování/rotace API klíčů) |

### Idempotence

`ds-upgrade`, `*-bootstrap`, `*-setup` (bez `--force`), `seed-*-clear` musí
být idempotentní — opakované spuštění bez efektu, pokud se nic nezměnilo.
To je conscious design choice; umožňuje pouštět `ds-upgrade-all` po každém
deployi bez obav.

Výjimkou je `ds-reset` — ten je **záměrně destruktivní** (vždy dropuje
datové tabulky a rekreuje schéma, není idempotentní v běžném smyslu), ale
je bezpečný k opakování: opakovaný běh skončí znovu čistým stavem.

---

[← docs/README.md](README.md) · [Průvodce vývojáře](../DEVELOPERS.md)
