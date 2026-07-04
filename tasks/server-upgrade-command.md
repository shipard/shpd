# shpd-server upgrade — orchestrace nasazení nové verze + centrální Version

## Kontext

Nasazení nové verze na produkci dnes znamená ručně provést kroky z
`docs/operations/production.md` §11: `git pull`, `composer install --no-dev
--optimize-autoloader`, frontend build (`npm ci && npm run build`),
`shpd-server ds-upgrade-all`, `shpd-server doctor` — všechno pod správným
uživatelem (`sudo -u shipard`) a ve správném pořadí. Nový příkaz
`shpd-server upgrade` to zorchestruje jedním spuštěním.

Součástí je centralizace verze aplikace: řetězec `0.1.0` je dnes hardcoded
na 9 místech, z toho 8 znamená „verze aplikace" a 1 (`ConfigCompiler::VERSION`)
je **verze formátu kompilovaného configu** — ta se centralizovat nesmí.

**Klíčový technický problém — self-update:** příkaz aktualizuje kód, ze
kterého sám běží. PHP načítá třídy líně; kdyby si orchestrátor po `git pull`
doloadoval třídu z nové verze do staré paměti, vznikne nekonzistentní mix.
Proto: tenký orchestrátor podle vzoru `DsUpgradeAllCommand` — každý krok je
subproces (`passthru`), a od kroku `git pull` dál orchestrátor nesmí lazy-loadovat
žádné další třídy (vše potřebné mít načtené/vyřešené před pullem).

## Návaznost

- `docs/operations/production.md` §11 „Po `git pull`" — zdroj kroků; sekce se
  přepíše tak, aby vedla přes nový příkaz.
- Vzor orchestrace: `src/Command/Server/DsUpgradeAllCommand.php` (passthru
  subprocesy, verbosity propagace, summary).
- Vzor testování: `tests/Unit/Command/DataSource/DsResetCommandTest.php`
  (override protected metod).
- `tasks/ds-reset-enable-flag.md` — nesouvisí přímo, ale oba tasky sahají na
  Server příkazy; při implementaci pozor na konflikty v `HelpCommand`.

## Před implementací přečti

- `src/Command/Server/DsUpgradeAllCommand.php` — celý (vzor: subprocesy,
  `getShpdDsPath()`, verbosity, summary, exit code)
- `bin/shpd-server`, `bin/shpd-ds` — registrace příkazů, `Application(...)`
- `src/Command/Server/VersionCommand.php`, `src/Command/DataSource/VersionCommand.php`
- `src/Command/Server/HelpCommand.php`, `src/Command/DataSource/HelpCommand.php`
- `src/Command/DataSource/DsUpgradeCommand.php` — verbose banner (ř. ~76)
- `src/Api/Controller/McpController.php` — `SERVER_VERSION` (ř. ~28, ~83)
- `src/Core/Config/ConfigCompiler.php` — `VERSION` (ř. ~14, ~51) — **NEcentralizovat**
- `src/Command/Server/DoctorCommand.php` — `detectShipardUser()`, co vyžaduje root
- `docs/operations/production.md` §11; `docs/cli.md` (struktura, kam přidat sekce)

## Scope

**V rozsahu:**

- Část 0: `src/Core/Version.php` (konstanta + git hash za běhu) a přechod
  8 aplikačních míst na ni.
- Část 1: `src/Command/Server/UpgradeCommand.php` (`shpd-server upgrade`)
  s detekcí změn, dry-run, správou uživatelů a doctor závěrem.
- Testy obou částí, dokumentace (production.md §11, cli.md, HelpCommand).

**Mimo rozsah:**

- `ConfigCompiler::VERSION` — zůstává nezávislá verze formátu kompilátu
  (jen doplnit vysvětlující komentář).
- Maintenance mode (503 stránka během upgradu) — budoucí téma pro ostrý provoz.
- FPM/opcache reload — default `validate_timestamps=1`, reload není potřeba;
  jen poznámka do production.md.
- Automatický rollback při selhání (D5 — žádný `git reset`).
- Vystavení verze do API/frontendu (mimo MCP serverInfo) — případný budoucí task.

## Co implementovat

### Část 0 — centrální Version

1. **`src/Core/Version.php`**:

   ```php
   final class Version
   {
       public const VERSION = '0.1.1';

       /** "0.1.1 (abc1234)"; bez dostupného gitu jen "0.1.1". */
       public static function full(): string;

       /** Short hash HEAD přes `git -C <root> rev-parse --short HEAD`;
        *  null když git binárka nebo .git chybí. Statická cache
        *  (vč. negativního výsledku), stderr zahodit. Repo root =
        *  dirname(__DIR__, 2). Výstup validovat (/^[0-9a-f]{7,40}$/). */
       public static function gitHash(): ?string;
   }
   ```

2. **Přechod konzumentů** (8 míst):
   - `bin/shpd-server`, `bin/shpd-ds` — `new Application(..., Version::VERSION)`.
   - `Server/VersionCommand`, `DataSource/VersionCommand` — výpis
     `'Shipard ' . Version::full()`.
   - `Server/HelpCommand`, `DataSource/HelpCommand` — hlavička s `Version::VERSION`.
   - `DsUpgradeCommand` verbose banner — `Version::VERSION`.
   - `McpController` — `private const string SERVER_VERSION = Version::VERSION;`
     (konstantní výraz; zbytek beze změny).

3. **`ConfigCompiler::VERSION`** — jen komentář: verze formátu kompilovaného
   configu, nezávislá na verzi aplikace; bumpovat při změně struktury kompilátu.

4. **Test** `tests/Unit/Core/VersionTest.php`: `full()` začíná `Version::VERSION`;
   `gitHash()` vrací null nebo hex string; `full()` bez hashe neobsahuje závorky.

### Část 1 — UpgradeCommand

5. **`src/Command/Server/UpgradeCommand.php`** — name `upgrade`, options:
   `--dry-run`, `--full` (vynutí composer + frontend), `--skip-ds-upgrade`.
   Registrace v `bin/shpd-server`, řádek do `Server/HelpCommand`.

6. **Repo root a prostředí:** root = `dirname(__DIR__, 3)` (vzor
   `getShpdDsPath()`); guard `is_dir($root . '/.git')`. Cestu ke composeru
   vyřešit **před pullem** (`command -v composer`).

7. **Uživatelé (D2):**
   - `posix_geteuid() === 0` → kroky git/composer/npm/ds-upgrade-all obalit
     `sudo -u <shipardUser> -H`; doctor běží přímo (root). `shipardUser`:
     mode `production` → `shipard`, jinak vlastník `/opt/shipard` (logika
     jako `DoctorCommand::detectShipardUser` — extrahovat/duplikovat dle
     vkusu implementace).
   - Běh přímo pod `shipardUser` → kroky bez sudo; doctor **přeskočit**
     s upozorněním `run 'sudo shpd-server doctor' to verify` (doctor bez
     roota nemá plnou vypovídací hodnotu).
   - Produkce + jiný uživatel → abort (soubory by změnily vlastníka).

8. **Git pre-flight (D3):** vše jako subprocesy v repo rootu:
   - `git status --porcelain` neprázdný → abort + výpis prvních řádků.
   - `git rev-parse --abbrev-ref HEAD` → branch; `HEAD` (detached) → abort.
   - `git rev-parse --short HEAD` → `oldHash`.
   - `git fetch` → `git rev-list --count HEAD..origin/<branch>` +
     `git log --oneline` (limit ~20) — výpis příchozích commitů.
   - 0 příchozích commitů a ne `--full` → `Already up to date.` a SUCCESS
     (nic dalšího se nespouští).

9. **Plán kroků (D4):** `git diff --name-only HEAD origin/<branch>` (před
   pullem, použije ho i dry-run):
   - composer krok ⇔ změna `composer.json`/`composer.lock` ∨ `--full`;
   - frontend krok ⇔ změna pod `frontend/` ∨ `--full`;
   - `ds-upgrade-all` vždy (mimo `--skip-ds-upgrade`).
   Logiku extrahovat do čisté metody (např.
   `computePlan(array $changedFiles, bool $full): array`) kvůli testům.

10. **`--dry-run` (D7):** po fetchi vypíše příchozí commity + plán kroků
    (`[run]`/`[skip]` s důvodem) a skončí beze změn.

11. **Provedení (D5):** sekvenčně, stop na první chybě, každý krok s hlavičkou
    (vzor `DsUpgradeAllCommand`) a propagací verbosity:
    1. `git pull --ff-only`
    2. composer (podmíněně): `composer install --no-dev --optimize-autoloader`
    3. frontend (podmíněně): `cd frontend && npm ci && npm run build`
    4. `<root>/bin/shpd-server ds-upgrade-all` — **nový proces = nový kód**
    5. doctor (jen root): `<root>/bin/shpd-server doctor`
    Selhání kroku → abort, FAILURE, hláška se jménem kroku + odkaz na ruční
    postup (production.md §11). Selhání doctoru → FAILURE s hláškou
    „code deployed, but doctor reported issues" (upgrade se nevrací, D6).
    Od kroku 1 dál žádné lazy-loadované třídy v orchestrátoru.

12. **Summary:** `Upgraded <oldHash> → <newHash> (<N> commits)` — `newHash`
    z gitu subprocesem (konstanta `Version::VERSION` v paměti orchestrátoru
    je po pullu stará, D11), seznam provedených/přeskočených kroků.

13. **Testy** `tests/Unit/Command/Server/UpgradeCommandTest.php` — override
    protected metod (git/step runner) podle vzoru `DsResetCommandTest`:
    - `computePlan()`: composer.lock → composer ano/frontend ne; `frontend/x`
      → naopak; nic → jen ds-upgrade-all; `--full` → vše.
    - dirty worktree → abort, nic se nespustí.
    - 0 příchozích commitů → early SUCCESS.
    - dry-run → žádný mutující krok.
    - selhání kroku uprostřed → FAILURE, další kroky se nespustí.
    - sudo prefix: euid 0 → `sudo -u shipard -H`, jinak bez.

14. **Dokumentace (D8):**
    - `production.md` §11 — vede přes `sudo shpd-server upgrade`
      (+ `--dry-run` příklad); ruční kroky ponechat jako „co příkaz dělá /
      fallback"; poznámka o opcache (default validace timestampů → reload
      FPM není potřeba).
    - `docs/cli.md` — sekce `upgrade` u `shpd-server`; zmínka o formátu
      výstupu `version`.

## Hotovo když

- `shpd-server version` vypíše `Shipard 0.1.1 (<hash>)`; bez `.git` jen
  `Shipard 0.1.1`. `shpd-ds version` totéž.
- Všech 8 aplikačních míst čte z `Version`; `ConfigCompiler::VERSION` beze
  změny hodnoty (jen komentář).
- MCP `serverInfo.version` hlásí `Version::VERSION`.
- `shpd-server upgrade --dry-run` na serveru s příchozími commity vypíše
  commity a plán, nic nezmění.
- Špinavý worktree → abort před jakoukoli změnou.
- Bez příchozích commitů (a bez `--full`) → `Already up to date.`, SUCCESS.
- Reálný upgrade: pull → (composer) → (frontend) → ds-upgrade-all → doctor,
  summary s rozsahem hashů; composer/frontend se přeskočí, když se jejich
  vstupy nezměnily; `--full` je vynutí; `--skip-ds-upgrade` funguje.
- Jako root jdou kroky přes `sudo -u shipard -H`; jako `shipard` přímo
  a doctor se přeskočí s upozorněním; jiný uživatel na produkci → abort.
- Selhání kroku → FAILURE + srozumitelná hláška; selhání doctoru → FAILURE
  s „code deployed" hláškou.
- Nové i existující testy procházejí; dokumentace aktualizovaná.

## Doporučené pořadí

1. Část 0: `Version` + test + přechod konzumentů + komentář v `ConfigCompiler`.
   **Commit 1** (`feat: central app version with git hash`).
2. `UpgradeCommand`: `computePlan()` + git pre-flight + dry-run; testy
   (červená → zelená).
3. Provedení kroků + user handling + summary; testy selhání.
4. Registrace, HelpCommand, dokumentace. **Commit 2**
   (`feat: shpd-server upgrade command`), docs případně zvlášť dle rozsahu.
5. Smoke test na `ns-alpha`: `--dry-run` → reálný běh na malé změně →
   ověřit summary a doctor.

## Rozhodnutí ✓

- **D1:** `shpd-server upgrade`, tenký orchestrátor, kroky jako passthru
  subprocesy (vzor `DsUpgradeAllCommand`); řeší self-update problém. ✓
- **D2:** root → kroky přes `sudo -u shipard -H`, doctor jako root; přímo
  `shipard` → kroky bez sudo, doctor skip s upozorněním; jiný uživatel na
  produkci → abort. ✓
- **D3:** fetch + výpis příchozího rozsahu; čistý worktree povinný (žádný
  autostash); `git pull --ff-only`; summary `old → new (N commitů)`. ✓
- **D4:** detekce změn z `git diff --name-only`: composer jen při změně
  composer.json/lock, frontend jen při změně `frontend/`, `ds-upgrade-all`
  vždy; `--full` a `--skip-ds-upgrade` overridy. ✓
- **D5:** sekvenčně, stop na první chybě, žádný automatický rollback; hláška
  s krokem + odkaz na ruční postup. ✓
- **D6:** doctor jako závěrečný krok; jeho selhání → FAILURE („code deployed,
  doctor reported issues"), upgrade se nevrací. ✓
- **D7:** `--dry-run` = fetch + příchozí commity + plán kroků, žádné změny. ✓
- **D8:** production.md §11 vede přes nový příkaz (ruční kroky jako fallback),
  cli.md, HelpCommand, registrace v bin. ✓
- **D9:** `src/Core/Version.php` — `const VERSION` jako jediný zdroj pravdy,
  git hash za běhu se statickou cache a tichým fallbackem; formát
  `0.1.1 (abc1234)`. ✓
- **D10:** 8 aplikačních míst přechází na `Version`; `ConfigCompiler::VERSION`
  zůstává nezávislý (verze formátu kompilátu). ✓
- **D11:** semver bumpuje David ručně při milnících; denní přesnost nese git
  hash; upgrade summary reportuje hashe, ne semver (konstanta je po pullu
  v paměti stará). ✓

## Otevřené body

- Maintenance mode (503) během upgradu — až pro ostrý provoz.
- Vystavení verze do API/frontendu (footer, status endpoint) — případný
  samostatný task; MCP serverInfo je pokryto tímto taskem.
- Kdyby se někdy na produkci vypnulo `opcache.validate_timestamps`, patří na
  konec upgradu reload FPM — dnes ne (poznámka bude v production.md).
