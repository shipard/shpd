# Task: AI analyzer provisioning bezpodmínečně v `ds-upgrade` + profily přežívají `ds-reset`

**Stav:** hotovo

**Schválená rozhodnutí:** D1–D5 (viz níže)

## Cíl

Po `ds-reset` na DS se `skipProvisioning=true` dnes zmizí AI profil
(`core_mail_ai_profiles` není v žádném `keepOnReset`) a `ds-upgrade` ho
neobnoví (`provisionAiAnalyzer` je uvnitř `skipProvisioning` gatu).
Analyzer pak padá na `NO_PROFILE` a je nutné ručně pouštět
`ai-analyzer-bootstrap`.

Cílový stav: **ruční akce je pouze jednorázové nastavení klíčů při prvním
zřízení DS** (`ai-analyzer-set-key`, `ai-analyzer-setup`). Reset ani
upgrade už žádnou ruční AI akci nevyžadují:

- `ds-reset` zachová profily (vč. admin úprav) stejně, jako už dnes
  zachovává backendy s klíčem, uživatele a API klíče,
- `ds-upgrade` zajistí uživatele/backend/profil + version sync
  bezpodmínečně, i pod `skipProvisioning` (čerstvý migrační DS).

## Návaznost

- `tasks/ai-profile-sync-in-ds-upgrade.md` — automatický version sync
  profilu (hotovo); tento task na něj navazuje, sync přechází ven z gatu
  spolu s celým `provisionAiAnalyzer`.
- Precedens: `ClearingInfrastructureProvisioner` běží bezpodmínečně
  (mimo `skipProvisioning`) — stejný argument: systémový kontrakt,
  ne migrovaná data. Ověřeno: migrace/exchange se `core_ai_backends`
  ani `core_mail_ai_profiles` nedotýká.
- `keepOnReset` mechanismus: `core.ai` chrání `core_ai_backends`
  (šifrovaný klíč), `core.system` chrání users/api_keys/sessions/…
  Profily zůstaly nechráněné — opomenutí z doby přesunu backendů
  z `core.mail` do `core.ai`.

## Schválená rozhodnutí

- **D1** — `provisionAiAnalyzer()` se volá bezpodmínečně, mimo
  `skipProvisioning` gate, hned za `provisionClearingInfrastructure()`.
  Provisioner je plně idempotentní (user podle `login`, backend podle
  `backend_id` + kontrola cizího defaultu, profil podle `profile_id`,
  sync version-guarded).
- **D2** — Doplní se module guard: `core.mail` + `core.ai` přes
  `isModuleActive()`, po vzoru clearing infrastruktury, s verbose
  `[SKIP]` hláškou. (Dnes guard chybí — uvnitř else větve to nevadilo,
  bezpodmínečné volání by na DS bez mail modulu spadlo na chybějící
  tabulky.)
- **D3** — Z `[SKIP] Provisioning disabled…` hlášky se odstraní
  „AI analyzer" z výčtu přeskočených položek. Mail router zůstává
  gatovaný (jeho `default` mailbox by kolidoval s mailboxy z migrace).
- **D4** — `ai-analyzer-bootstrap` zůstává jako samostatný příkaz;
  dokumentace doplní, že `ds-upgrade` ho nyní pokrývá automaticky.
- **D5** — `core_mail_ai_profiles` se přidá do `keepOnReset` v
  `modules/core/mail/module.jsonc`. Profil je konfigurace jako backend;
  přežijí tak i admin úpravy (`name`, `is_default`, `is_active`, vazba
  na backend — id backendu zůstává stabilní, tabulka je také kept).

## Scope

### 1. `modules/core/mail/module.jsonc`

Přidat blok (modul dnes žádný `keepOnReset` nemá):

```jsonc
// ds-reset chrání AI profily — je to konfigurace (vč. admin úprav
// name/is_default/is_active a lokálně laděného promptu), ne migrovaná
// data. Backendy chrání analogicky core.ai.
"keepOnReset": [
    "core_mail_ai_profiles"
],
```

Umístit logicky vedle `tables` bloku, konzistentně s `core.ai` a
`core.system`.

### 2. `src/Command/DataSource/DsUpgradeCommand.php`

a) Přesun volání ven z `else` větve `skipProvisioning` — nový blok hned
za `provisionClearingInfrastructure(...)`:

```php
// AI analyzer (user, backend, profil + version sync ze šablony) se
// zajišťuje BEZPODMÍNEČNĚ — i pod skipProvisioning. Není to migrovaná
// data, ale systémový kontrakt modulů core.mail/core.ai. Idempotentní;
// klíče (backend key, analyzer API key) přežívají ds-reset přes
// keepOnReset, takže po resetu není potřeba žádná ruční akce.
$this->provisionAiAnalyzer($resolvedModules, $dsConfig, $dsConnection, $output);
```

a odstranit původní volání z else větve.

b) Rozšíření signatury `provisionAiAnalyzer()` o
`array $resolvedModules` (první parametr, vzor
`provisionClearingInfrastructure`) + guard na začátek metody:

```php
if (!$this->isModuleActive($resolvedModules, 'core.mail')
    || !$this->isModuleActive($resolvedModules, 'core.ai')
) {
    $output->writeln(
        '  <comment>[SKIP] core.mail / core.ai module not active</comment>',
        OutputInterface::VERBOSITY_VERBOSE,
    );
    return;
}
```

Pozn.: `core.mail` deklaruje dependency na `core.ai`, takže druhá
podmínka je defenzivní — nechat obě (levné, explicitní).

c) Úprava `[SKIP] Provisioning disabled…` textu: z řádku
`number series, mail router, AI analyzer` odstranit `, AI analyzer`
(zkontrolovat zalomení výčtu, ať zůstává čitelný).

Zbytek `provisionAiAnalyzer` (logování CREATE/OK/SKIP, sync match,
try/catch na šablonu) beze změny — hotovo v předchozím tasku.

### 3. Dokumentace

- `docs/cli.md`:
  - `ds-upgrade`: AI analyzer provisioning (user, backend, profil,
    version sync) běží vždy, i pod `skipProvisioning`.
  - `ds-reset`: do popisu chráněných tabulek doplnit AI profily.
  - `ai-analyzer-bootstrap`: poznámka, že `ds-upgrade` pokrývá totéž
    automaticky; příkaz slouží pro ruční zásah mimo upgrade.
- `docs/ai.md` (příp. `modules/core/mail/docs/ai-prompts.md`): lifecycle
  odstavec — jediné ruční kroky jsou `ai-analyzer-set-key` a
  `ai-analyzer-setup` při prvním zřízení DS; klíče přežívají reset.

## Testy

### `tests/Unit/Command/DataSource/DsUpgradeCommandTest.php`

1. `skipProvisioning=true` → `provisionAiAnalyzer` proběhl (uživatel/
   backend/profil vytvořeny nebo OK), ostatní provisionery přeskočeny,
   `[SKIP]` hláška neobsahuje „AI analyzer".
2. `core.mail` modul neaktivní → AI provisioning se přeskočí bez SQL
   dotazů na mail/ai tabulky.

### `tests/Unit/Command/DataSource/DsResetCommandTest.php`

3. `core_mail_ai_profiles` je v keptList (ne v dropList), pokud je
   `core.mail` modul aktivní — vzor stávajících keepOnReset testů,
   pokud existují; jinak test na složení keepSet z resolvedModules.

### Ruční ověření (reálný migrační DS, `skipProvisioning=true`)

4. `ds-reset -y` → `ds-upgrade` proběhne v rámci resetu → profil
   existuje (přežil jako kept tabulka), backend + oba klíče přežily,
   analyzer analyzuje bez jakékoliv ruční akce.
5. Bump `prompt_version` v šabloně → `ds-upgrade` → `[UPDATE]` řádek,
   nová verze v DB (ověření, že sync běží i pod skipProvisioning).

## Commit strategie

1. `feat(ds-reset): keep AI profiles across reset via keepOnReset`
   — module.jsonc + DsReset test.
2. `feat(ds-upgrade): provision AI analyzer unconditionally, outside skipProvisioning gate`
   — DsUpgradeCommand + testy.
3. `docs(cli,ai): document unconditional AI provisioning and key lifecycle`

Po každém patchi: `php -l`, `git diff`, pak
`vendor/bin/phpunit --filter DsUpgradeCommandTest --testsuite Unit`
a `--filter DsResetCommandTest`.

Pozn.: `.jsonc` změna modulu nevyžaduje rebuild kompilované konfigurace
DS? Ověřit — pokud `keepOnReset` čte ModuleLoader přímo z `.jsonc`
(pravděpodobné, viz DsResetCommand → ModuleLoader::loadAllModules),
rebuild není potřeba. Pokud by šel přes kompilovaný config, doplnit
krok rebuildu do ověření.

## Hotovo když

- [ ] `core_mail_ai_profiles` v `keepOnReset` (`core.mail`), `ds-reset
      --dry-run` ji ukazuje v `[keep]` sekci.
- [ ] `ds-upgrade` na DS se `skipProvisioning=true` vytvoří/ověří
      uživatele, backend i profil a provede version sync; `[SKIP]`
      hláška už AI analyzer nezmiňuje.
- [ ] Na DS bez `core.mail` modulu `ds-upgrade` AI provisioning tiše
      přeskočí (verbose `[SKIP]`).
- [ ] Po `ds-reset` na migračním DS analyzer funguje bez jediné ruční
      CLI akce (klíče přežily, profil přežil, backend přežil).
- [ ] `ai-analyzer-bootstrap` beze změny chování.
- [ ] Dokumentace aktualizována (`docs/cli.md`, `docs/ai.md` /
      `ai-prompts.md`).
- [ ] Všechny dotčené unit testy zelené.
