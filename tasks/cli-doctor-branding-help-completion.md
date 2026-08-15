# CLI údržba: branding v PermissionSpec, mkdir módy, sync nápovědy, bash completion

**Stav:** hotovo — nasazeno a ověřeno na alfě 15. 8. 2026: `fix-permissions`
opravil `branding` na `t55w-…` (shipard:shipard 0750), `doctor` čistý,
upload obrázku v Nastavení aplikace prošel, `shpd-ds help` obsahuje
všechny registrované příkazy (ověřeno proti `list --raw`), completion
skripty nainstalované v `/etc/bash_completion.d/`

**Cíl:** `shpd-server doctor` a `fix-permissions` pokrývají adresář
`branding/` v datových zdrojích; `ds-create`/`ds-upgrade` nastavují
správné módy už při vytvoření adresářů (bez spoléhání na následný
`fix-permissions`); nápovědy `shpd-ds` a `shpd-server` obsahují všechny
registrované příkazy a drift hlídá test; obě CLI utility mají
bash/zsh completion instalovanou serverovým upgradem.

**Návaznost / kontext:**

- Reálný incident (alfa, DS `t55w-4ijc-6fuo-9lz3`): `branding/` je
  `root:root 0755`, upload obrázků v Nastavení aplikace selhává.
  `att` a `cache` vedle něj mají správně `shipard:shipard 0750` —
  všechny tři vytvořil `ds-create` běžící pod rootem ve stejný okamžik,
  následný `fix-permissions` srovnal jen to, co je v
  `PermissionSpec::getDataSourceEntries()`. `branding` ve specu chybí,
  proto ho `doctor` ani `fix-permissions` nevidí.
- `HelpCommand` obou binárek je ručně psaný seznam a rozjel se
  s registrací v `bin/shpd-ds` / `bin/shpd-server`:
  - `shpd-ds`: registrováno 40, v nápovědě 27; chybí `user-set-admin`,
    `auth-emergency-login`, `api-key-create`, `api-key-list`,
    `api-key-revoke`, `mail-outbox-run`, `mail-outbox-retry`,
    `mail-send-test`, `registry-extract-texts`, `alerts-run`,
    `alerts-prune`, `bank-import-statement`, `accbal-match`.
  - `shpd-server`: chybí `cron`, `cron-install`, `hosting-sync`.
- Symfony Console 7 (composer: `^7.0`) má vestavěné
  `DumpCompletionCommand` (`completion bash|zsh|fish`) a skrytý
  `_complete` — completion tedy nevyžaduje vlastní kód, jen instalaci
  vygenerovaného skriptu do `/etc/bash_completion.d/` a binárku
  v `PATH`.

**Rozhodnutí (potvrzeno):**

- D1: `branding` do `PermissionSpec::getDataSourceEntries()` —
  `dir`, owner/group `user`, mode `0750`, `optional: true`,
  `recurse: true` (po vzoru `att`).
- D2: `cache/oidc` se nepřidává — pokrývá ho rekurze na `cache`.
- D3: `ds-create` i `ds-upgrade` nastavují módy explicitně při
  vytvoření (chmod po mkdir; mkdir mód podléhá umask), cílově 0750
  dle specu.
- Nápověda: ruční kategorizovaný seznam zůstává (čitelnější než
  generovaný `list`), doplní se chybějící příkazy a přibude test
  hlídající drift.
- Completion: instalace v `shpd-server upgrade` (a `server-init`),
  idempotentně; jen bash skript do `/etc/bash_completion.d/`
  (zsh/fish si uživatel vygeneruje sám přes `completion zsh`).

## Scope

### 1. PermissionSpec + módy při vytvoření

- `src/Core/Server/PermissionSpec.php`
  - `getDataSourceEntries()`: přidat záznam
    `['path' => $dsDir . '/branding', 'type' => 'dir', 'owner' => 'user',
    'group' => 'user', 'mode' => 0750, 'optional' => true,
    'recurse' => true]` (zařadit vedle `att`).
- `src/Command/Server/DsCreateCommand.php`
  - Po blocích `@mkdir(...)` doplnit explicitní `@chmod($dir, 0750)`
    pro `att`, `branding`, `cache`, `cache/thumbnails`, `cache/oidc`
    (mkdir 0755 + umask nezaručuje výsledek; spec říká 0750).
  - `config` dir: `mkdir($configDir, 0755, true)` → chmod 0750
    po vytvoření (spec: 0750). Root DS adresáře taktéž.
  - Pokud `ds-create` běží pod rootem (hosting provisioning),
    po vytvoření adresářů `chown` na shipard uživatele — použít
    `PermissionSpec::resolveOwner()`/detekci jako `FixPermissionsCommand`,
    nebo minimálně zdokumentovaný `chown` bloku ds adresáře.
    (Tohle je jádro incidentu — adresáře nesmí zůstat root:root.)
- `src/Command/DataSource/DsUpgradeCommand.php`
  - Smyčka `['att', 'branding', 'cache/thumbnails', 'cache/oidc']`:
    po `@mkdir($dirPath, 0755, true)` doplnit `@chmod($dirPath, 0750)`
    (vč. mezilehlého `cache`). `ds-upgrade` běží pod shipard userem,
    chown tu neřešíme.

### 2. Sync nápovědy

- `src/Command/DataSource/HelpCommand.php` — doplnit chybějících 13
  příkazů do stávajících kategorií (popisy převzít ze `setDescription()`
  příkazů):
  - Users: `user-set-admin`, `auth-emergency-login`
  - nová kategorie API keys: `api-key-create`, `api-key-list`,
    `api-key-revoke`
  - Mail: `mail-outbox-run`, `mail-outbox-retry`, `mail-send-test`
  - nová kategorie Alerts: `alerts-run`, `alerts-prune`
  - nová kategorie Economy: `bank-import-statement`, `accbal-match`
  - nová kategorie Registry (Spisovna): `registry-extract-texts`
- `src/Command/Server/HelpCommand.php` — doplnit `cron`,
  `cron-install`, `hosting-sync` (kategorie Cron / Hosting).
- Test proti driftu (`tests/...` dle konvence testů v repu):
  - Registrace příkazů je dnes jen v `bin/*` — pro testovatelnost
    extrahovat seznam příkazů do factory
    (`Shipard\Cli\DsApplicationFactory::create(): Application`,
    obdobně pro server), `bin/shpd-ds` a `bin/shpd-server` ji volají.
  - Test: pro každou binárku vytvořit Application přes factory,
    vytáhnout jména viditelných příkazů (bez `_complete`, `completion`,
    `list`, `help`, `version`) a assertnout, že každé jméno je
    obsaženo ve výstupu HelpCommandu. Chybějící příkaz = červený test
    s jasnou hláškou.

### 3. Bash completion

- `src/Command/Server/UpgradeCommand.php` (a `ServerInitCommand`):
  krok „Install shell completion" —
  - zjistit cestu binárek (očekávaný symlink v PATH; pokud
    `shpd-ds`/`shpd-server` v PATH nejsou, krok přeskočit s WARN),
  - `<binárka> completion bash > /etc/bash_completion.d/<name>`
    (zapisovat atomicky, jen pokud se obsah liší — idempotence),
  - vyžaduje root; pokud upgrade neběží pod rootem, přeskočit s WARN.
- Ověřit, že `_complete` funguje mimo DS adresář (bin skript má
  try/catch na ServerConfig; `requireDataSource` je jen v `help`).
- `docs/` — krátká zmínka v serverové dokumentaci: jak completion
  funguje, ruční instalace pro zsh/fish.

## Testy

- PHPUnit: nový drift test nápovědy (obě binárky); úprava/rozšíření
  stávajících testů `HealthChecker`/`FixPermissions`, pokud existují —
  scénář „branding root:root 0755 → doctor hlásí, fix-permissions
  opraví".
- Ruční ověření na alfě (po nasazení, mutace jednotlivě po schválení):
  1. `shpd-server doctor` → hlásí `branding` na `t55w-…` (owner root,
     mode 0755),
  2. `sudo shpd-server fix-permissions` → opraví,
  3. `doctor` čistý, upload obrázku v Nastavení aplikace projde,
  4. `shpd-ds help` obsahuje všech 40 příkazů,
  5. tab-completion napovídá po instalaci completion skriptu.

## Commit strategie

1. `PermissionSpec: add branding dir to data source contract`
2. `ds-create/ds-upgrade: explicit modes (and ownership) at creation time`
3. `cli: sync help with registered commands + drift test (app factories)`
4. `server upgrade: install bash completion scripts`

## Hotovo když

- [x] `branding` je v `PermissionSpec` (0750, optional, recurse);
      `doctor` mismatch hlásí a `fix-permissions` opravuje
- [x] `ds-create`/`ds-upgrade` vytvářejí `att/branding/cache/*`
      s módem 0750 nezávisle na umask; `ds-create` pod rootem
      nezanechává root:root adresáře
- [x] Nápověda `shpd-ds` i `shpd-server` obsahuje všechny registrované
      příkazy; drift test zelený a při vynechání příkazu padá
- [x] `shpd-server upgrade` idempotentně instaluje bash completion;
      tab napovídá příkazy obou binárek
- [x] Dokumentace completion v `docs/` (`cli.md` § completion-install,
      `operations/production.md` §8 a §11)

## Poznámky k implementaci (2026-08-15)

- Completion instalaci dělá nový příkaz **`shpd-server
  completion-install`** (vzor `cron-install`) — `upgrade` ho volá jako
  subproces (respektuje pravidlo „po pullu žádné lazy-loadované třídy"),
  `server-init` best-effort přímo přes `CompletionInstaller`. Drobná
  odchylka od zadání („krok v UpgradeCommand"), stejný efekt.
- Detekce shipard uživatele sjednocena do statické
  `PermissionSpec::detectShipardUser()` (dřív 4 kopie: Doctor,
  FixPermissions, Upgrade, CronInstall); používá ji i chown v ds-create.
- `requireDataSource` v DS HelpCommandu už nevolá `exit()`, vrací
  FAILURE (kvůli drift testu).
- Ruční ověření na alfě dle sekce Testy proběhlo 15. 8. 2026 — vše
  prošlo (doctor/fix-permissions na `t55w-…`, upload brandingu,
  `shpd-ds help` kompletní, completion skripty na místě).
