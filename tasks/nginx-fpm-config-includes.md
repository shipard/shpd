# Systémové nginx/FPM parametry jako verzované include soubory

**Stav:** hotovo

## Kontext

Import příloh ze starého Shipardu padá na `413 Request Entity Too Large` od
nginx. Diagnóza (ověřeno):

1. **nginx** — žádná šablona v `docs/nginx/` nenastavuje `client_max_body_size`
   → platí default **1 MB**. To je příčina 413.
2. **PHP-FPM** — pool `shipard` z `install-packages.sh` nenastavuje
   `upload_max_filesize`/`post_max_size` → PHP defaulty **2M/8M** = další zeď
   hned za nginx.
3. Aplikační limit neexistuje (multipart přes `/_attachments/upload`,
   `AttachmentService` velikost nekontroluje).

`docs/attachments.md` §9 přitom správné hodnoty dokumentuje (128M/130M +
nginx 128M) — infrastruktura je jen neimplementuje.

**Proč include, ne úprava šablon:** site config je po instalaci ručně upravovaný
(server_name, certifikáty, redirecty) — git na něj po prvním nasazení nedosáhne.
Systémové parametry proto přesouváme do **verzovaných souborů v repu, které se
do živých configů includují**. Změna systémového parametru pak = `git pull` +
`reload` služby, žádné ruční mergování. (Pattern převzatý ze starého Shipardu.)

## Návaznost

- `tasks/server-upgrade-command.md` — **implementováno**; tento task rozšiřuje
  `UpgradeCommand::computePlan()` o reload kroky (smyčka „změna systémového
  confu = obyčejný upgrade" se tím uzavře).
- `docs/attachments.md` §9 — zdroj doporučených hodnot; sekce se aktualizuje.
- Odložený follow-up: `--retry-attachments` pro `mail`/`bank-statements`
  v `old_shipard` (viz Otevřené body).

## Před implementací přečti

- `docs/nginx/production.conf`, `development.conf`, `app.conf` — všechny tři
  šablony (app.conf obsahuje oba server bloky jako referenční dokumentaci)
- `scripts/install-packages.sh` — §7 FPM pool heredoc (ř. ~159–187),
  §8 nginx site (ř. ~189+, `TEMPLATE="$PROJECT_DIR/docs/nginx/${MODE}.conf"`)
- `src/Command/Server/DoctorCommand.php` — struktura checků, `detectShipardUser`,
  práce s mode
- `src/Command/Server/UpgradeCommand.php` — `computePlan()`, provedení kroků,
  sudo prefix logika, dry-run výpis
- `docs/operations/production.md` §6 (nginx a TLS), §11 (po git pull)

## Scope

**V rozsahu:** verzované include soubory (nginx common + tls, FPM common),
úprava tří šablon a instalačního skriptu, doctor checky (warn-only), rozšíření
`upgrade` o reload kroky, dokumentace, ruční kroky pro `ns-alpha`.

**Mimo rozsah:**

- Aplikační limit velikosti souboru v `AttachmentService` (čistá JSON chyba
  místo nginx HTML) — případný budoucí task.
- `--retry-attachments` v old_shipard — odloženo (řeší se resetem DS
  a čistým re-importem).
- Ladění hodnot TLS policy — obsah `shipard-tls.conf` je převzatý 1:1
  z odsouhlaseného snippetu; změny jsou triviální právě díky include patternu.
- `memory_limit` — multipart upload streamuje do tmp souborů, paměti se netýká.

## Co implementovat

1. **`docs/nginx/shipard-common.conf`** — systémové parametry pro každý server
   blok:

   ```nginx
   # Shipard system parameters — included from the site config.
   # Changes take effect with: git pull && nginx -t && systemctl reload nginx
   client_max_body_size 128M;
   ```

2. **`docs/nginx/shipard-tls.conf`** — TLS policy (jen pro HTTPS bloky):

   ```nginx
   # Shipard TLS policy — include ONLY in HTTPS (listen 443 ssl) server blocks.
   ssl_session_timeout 60m;
   ssl_session_cache shared:SSL:50m;

   ssl_protocols TLSv1.3;
   ssl_ecdh_curve X25519:prime256v1:secp384r1;
   ssl_prefer_server_ciphers off;

   add_header Strict-Transport-Security "max-age=63072000; includeSubdomains; preload" always;
   ```

3. **Šablony** — do server bloků doplnit include (absolutní cesta, stejná
   konvence jako `root /opt/shipard/shpd/public;`):
   - `production.conf`: oba includy.
   - `development.conf`: jen `shipard-common.conf` (HTTP blok — **žádné TLS**,
     HSTS přes plain HTTP je chyba).
   - `app.conf`: production blok oba, development blok jen common.

   ```nginx
   include /opt/shipard/shpd/docs/nginx/shipard-common.conf;
   include /opt/shipard/shpd/docs/nginx/shipard-tls.conf;   # jen 443 ssl bloky
   ```

4. **`docs/php/shipard-fpm-common.conf`** (nový adresář `docs/php/`):

   ```ini
   ; Shipard FPM pool parameters — included from pool.d/shipard.conf.
   ; No [pool] header here — the file is parsed in the including pool's context.
   ; Changes take effect with: git pull && systemctl reload php<ver>-fpm
   php_admin_value[upload_max_filesize] = 128M
   php_admin_value[post_max_size] = 130M
   ```

5. **`install-packages.sh`** — do pool heredocu doplnit (s `$PROJECT_DIR`,
   ne hardcoded cestou):

   ```
   include=$PROJECT_DIR/docs/php/shipard-fpm-common.conf
   ```

   **Verifikační krok implementace:** `php-fpm8.5 -t` s vygenerovaným poolem —
   ověřit, že FPM include v pool kontextu funguje (soubor bez `[pool]` hlavičky
   se parsuje v kontextu includujícího poolu). Pokud by nefungoval, stop —
   nevymýšlet workaround, vrátit se k diskuzi.

6. **`DoctorCommand`** — nové warn-only checky (jen production mode / když
   soubory existují):
   - `/etc/nginx/sites-enabled/shipard.conf` obsahuje include
     `shipard-common.conf`; jinak warn s hintem.
   - Pool soubor (glob `/etc/php/*/fpm/pool.d/shipard.conf`) obsahuje include
     `shipard-fpm-common.conf`; jinak warn.
   - Includované soubory v repu existují (sanity).
   Exit code se kvůli těmto checkům nemění.

7. **`UpgradeCommand`** — rozšíření `computePlan()` + provedení:
   - změna pod `docs/nginx/` (∨ `--full`) → krok
     `nginx -t && systemctl reload nginx`;
   - změna pod `docs/php/` (∨ `--full`) → krok `systemctl reload php<ver>-fpm`
     (verze z běžícího PHP: `PHP_MAJOR_VERSION.PHP_MINOR_VERSION`);
   - kroky zařadit **za** `ds-upgrade-all`, **před** doctor;
   - jen jako root; jako `shipard` místo toho vypsat upozornění s příkazy;
   - selhání `nginx -t` → FAILURE (kód nasazen, config rozbitý — jasná hláška);
   - dry-run plán tyto kroky zobrazuje vč. důvodu.

8. **Testy:**
   - `computePlan()`: `docs/nginx/shipard-common.conf` → nginx reload ano,
     fpm ne; `docs/php/…` → naopak; `--full` → oba; nesouvisející soubor → nic.
   - Non-root → reload kroky se neprovádí, výstup obsahuje upozornění.
   - Doctor checky: chybějící include řádek → warn ve výstupu, exit code 0
     (dle testovacího vzoru DoctorCommand, pokud existuje; jinak aspoň
     computePlan/format testy).

9. **Dokumentace:**
   - `production.md` §6 — přepsat: co je **ruční** (server_name, certy,
     HTTP→HTTPS redirect) vs. co je **verzované přes include** (common + tls);
     instrukce doplnit include řádky do site configu; poznámka že TLS include
     patří jen do 443 bloku.
   - `production.md` §11 / sekce upgrade — zmínka, že změny systémových confů
     upgrade sám reloadne (jako root).
   - `docs/attachments.md` §9 — místo „přidej si do configu" odkázat na
     `shipard-common.conf` / `shipard-fpm-common.conf` jako zdroj pravdy.
   - `docs/cli.md` — u `upgrade` doplnit reload kroky do popisu.

## Hotovo když

- Čistá instalace přes `install-packages.sh`: site config i pool obsahují
  include řádky, `nginx -t` i `php-fpm -t` procházejí, upload 100MB souboru
  přes `/_attachments/upload` projde (413 ani PHP limit nespadne).
- Změna hodnoty v `shipard-common.conf` + `nginx reload` se projeví bez zásahu
  do site configu.
- `shpd-server upgrade` po commitu měnícím `docs/nginx/**` provede
  `nginx -t && systemctl reload nginx` (jako root) a dry-run to ukazuje v plánu;
  analogicky `docs/php/**` → FPM reload.
- `shpd-server doctor` warnuje při chybějícím include řádku v živém configu;
  exit code beze změny.
- Dev HTTP bloky TLS include **nemají**.
- Testy procházejí; dokumentace aktualizovaná.
- **Smoke test `ns-alpha`:** do živého `/etc/nginx/sites-available/shipard.conf`
  a pool souboru doplnit include řádky (po `git pull` s novými soubory),
  `nginx -t && systemctl reload nginx`, `systemctl reload php8.5-fpm`;
  ověřit doctor bez warnů; re-import pošty na resetnutém DS projde bez 413
  (soubor `Shipard_mzdy_2026_04-*.pdf`, 4.1 MB).

## Doporučené pořadí

1. Include soubory + šablony + install skript (**commit 1**,
   `feat: versioned nginx/fpm system config includes`).
2. Doctor checky (**commit 2**).
3. UpgradeCommand reload kroky + testy (**commit 3**).
4. Dokumentace (může být součástí commitu 1, nebo zvlášť dle rozsahu).
5. Smoke test na `ns-alpha` (viz Hotovo když).

## Rozhodnutí ✓

- **D1a:** Systémové nginx parametry ve verzovaných include souborech
  `docs/nginx/shipard-common.conf` (všechny bloky) a `shipard-tls.conf`
  (jen HTTPS bloky, obsah 1:1 z odsouhlaseného snippetu vč. HSTS `preload`
  a TLSv1.3-only — policy volby, měnitelné na jednom místě). Include
  absolutní cestou do repa. ✓
- **D1b:** FPM stejným patternem — `docs/php/shipard-fpm-common.conf`
  s `php_admin_value` limity (128M/130M dle attachments.md), `include=`
  v pool souboru; verifikace `php-fpm -t`. ✓
- **D2:** Doctor kontroluje strukturu (include řádky + existence souborů),
  ne hodnoty; warn-only. ✓
- **D-upgrade:** `computePlan()` detekuje změny `docs/nginx/**` / `docs/php/**`
  → reload kroky (root; jinak upozornění); za ds-upgrade-all, před doctor. ✓
- **D4:** Kosmetika chybové hlášky klienta (HTML → jednořádkové shrnutí) —
  přímá oprava v old_shipard, mimo tento task. ✓
- **D5:** production.md §6 přepsán na include pattern; attachments.md §9
  odkazuje na include soubory. ✓
- **D6:** Jeden task v nov_shipard; old_shipard strana bez tasku (D4 přímo). ✓

## Otevřené body

- `--retry-attachments` pro `mail`/`bank-statements` (dohojení příloh
  u already-imported záznamů; bezpečné díky server-side SHA-256 dedupu) —
  odloženo, aktuálně řešeno resetem DS; otevřít, pokud se částečné výpadky
  uploadů budou vracet.
- Aplikační max. velikost přílohy s čistou JSON chybou (místo nginx HTML) —
  zvážit až podle reálné potřeby.
