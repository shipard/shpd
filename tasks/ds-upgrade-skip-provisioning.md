# `ds-upgrade` — vypnutelný provisioning přes `config/main.json`

**Stav:** hotovo

## Status / Cíl

`shpd-ds ds-upgrade` po synchronizaci schématu automaticky generuje
referenční data (units, item kinds, fiskální roky, VAT období, číselné
řady, mail router, AI analyzer). To je ideální pro nově začínající firmu,
ale **komplikace při importu z jiného systému** — provisioner vytvoří
defaulty, které pak kolidují / duplikují s importovanými daty (typicky
číselné řady, druhy položek, fiskální období).

Cíl: volitelný boolean `skipProvisioning` v `config/main.json`. Když je
`true`, `ds-upgrade` provede synchronizaci schématu, ale **přeskočí celý
provisioning**. Po dokončení importu se flag vrátí na `false` a další
`ds-upgrade` doplní jen to, co import nepřinesl (provisionery jsou
idempotentní).

## Návaznost

- Skládá se s `ds-reset` (`tasks/ds-reset.md`, hotovo): `ds-reset` interně
  volá `ds-upgrade`, takže flag se propíše **sám od sebe** — žádná úprava
  `ds-reset` není potřeba.
- Mění se jen `DsUpgradeCommand` (gate kolem provisioningu) a
  `DataSourceConfig` (nový getter). Provisionery samotné se nemění.
- Vzor volitelného pole v `config/main.json`: `getDefaultLanguage()` /
  `getDefaultCurrency()` v `DataSourceConfig` (`?? default`).

## Mechanismus

Workflow pro opakovaný test importu:

```
1. config/main.json:  "skipProvisioning": true
2. shpd-ds ds-reset -y   → drop dat + rekreace schématu, BEZ provisioningu
3. … import ze starého Shipardu …
4. config/main.json:  "skipProvisioning": false
5. shpd-ds ds-upgrade    → doplní jen mezery, které import nenaplnil
```

Pro opakované testy zůstane flag `true` po celou dobu testování; na
`false` se přepne, až je import hotový „naostro".

## Co je potřeba udělat

### 1. `DataSourceConfig` — getter `shouldSkipProvisioning()`

Soubor `src/Core/Config/DataSourceConfig.php`. Přidat (vzor stávajících
volitelných getterů):

```php
/**
 * When true, `shpd-ds ds-upgrade` syncs the schema but SKIPS auto-provisioning
 * of reference data (units, item kinds, fiscal years, VAT periods, number series,
 * mail router, AI analyzer). Intended for data migration / import from another
 * system, where that reference data is supplied by the import itself.
 * Optional; defaults to false when missing from main.json.
 */
public function shouldSkipProvisioning(): bool
{
    return $this->data['skipProvisioning'] ?? false;
}
```

`skipProvisioning` **není** v poli `$required` — je volitelné.

### 2. `DsUpgradeCommand` — gate kolem provisioningu

Soubor `src/Command/DataSource/DsUpgradeCommand.php`, v `execute()`.

Aktuálně je sekce:

```php
        // Backfill doc_state_changed_at for rows that pre-date the column.
        // …
        if ($this->isModuleActive($resolvedModules, 'docs.core')) {
            $dsConnection->executeSQL('UPDATE docs_core_heads SET doc_state_changed_at = NOW() WHERE doc_state_changed_at IS NULL');
        }

        $this->provisionUnits($resolvedModules, $dsConnection, $output);
        $this->provisionItemKinds($resolvedModules, $dsConnection, $output);
        $this->provisionFiscalYears($resolvedModules, $dsDir, $dsConnection, $output);
        $this->provisionVatPeriods($resolvedModules, $dsConnection, $output);
        $this->provisionDocCoreNumberSeries($resolvedModules, $dsDir, $dsConnection, $output);
        $this->provisionMailRouter($dsConfig, $dsConnection, $output);
        $this->provisionAiAnalyzer($dsConfig, $dsConnection, $output);

        $secretsWarnings = DsSecretCipher::healthCheck($dsConfig);
```

Upravit tak, aby **backfill běžel vždy** (zůstává nad podmínkou — není to
generování referenčních dat, ale schema backfill; idempotentní, s importem
nekoliduje) a **7 provisionerů bylo gateováno**. `secrets healthCheck`
zůstává také vždy (jen warnings):

```php
        // Backfill doc_state_changed_at — vždy (schema backfill, ne provisioning).
        if ($this->isModuleActive($resolvedModules, 'docs.core')) {
            $dsConnection->executeSQL('UPDATE docs_core_heads SET doc_state_changed_at = NOW() WHERE doc_state_changed_at IS NULL');
        }

        if ($dsConfig->shouldSkipProvisioning()) {
            $output->writeln('');
            $output->writeln("<comment>[SKIP] Provisioning disabled via config (skipProvisioning=true).</comment>");
            $output->writeln("<comment>       No reference data (units, item kinds, fiscal years, VAT periods,</comment>");
            $output->writeln("<comment>       number series, mail router, AI analyzer) was generated.</comment>");
            $output->writeln("<comment>       Set skipProvisioning=false in config/main.json and re-run</comment>");
            $output->writeln("<comment>       ds-upgrade once the import is complete.</comment>");
        } else {
            $this->provisionUnits($resolvedModules, $dsConnection, $output);
            $this->provisionItemKinds($resolvedModules, $dsConnection, $output);
            $this->provisionFiscalYears($resolvedModules, $dsDir, $dsConnection, $output);
            $this->provisionVatPeriods($resolvedModules, $dsConnection, $output);
            $this->provisionDocCoreNumberSeries($resolvedModules, $dsDir, $dsConnection, $output);
            $this->provisionMailRouter($dsConfig, $dsConnection, $output);
            $this->provisionAiAnalyzer($dsConfig, $dsConnection, $output);
        }

        $secretsWarnings = DsSecretCipher::healthCheck($dsConfig);
```

**Důležité:** `[SKIP]` hláška je na **normální** verbositě (žádný třetí
parametr `VERBOSITY_VERBOSE`) — musí být vidět při každém běhu, aby bylo
zřejmé, že je DS v „import módu". To je pojistka proti zapomenutému flagu.

Synchronizace schématu (kroky CREATE/ALTER výše) se **nemění** — běží vždy.

### 3. Testy

#### 3a. `tests/Unit/Core/Config/DataSourceConfigTest.php` — doplnit

Podle stávajícího vzoru (fixture `main.json` s povinnými poli zapsaný do
temp dir). Tři cases:
- chybějící `skipProvisioning` → `shouldSkipProvisioning() === false` (default)
- `"skipProvisioning": true` → `true`
- `"skipProvisioning": false` → `false`

#### 3b. `tests/Unit/Command/DataSource/DsUpgradeCommandTest.php` — doplnit

V existujícím `setUp()` přidat k mocku `dsConfig` stub (aby stávající
testy zůstaly v provisioning větvi):

```php
$this->dsConfig->method('shouldSkipProvisioning')->willReturn(false);
```

Nový test case **`testUpgradeSkipsProvisioningWhenConfigured`**:
- Postavit vlastní mock `dsConfig` (jako v `testUpgradeValidationErrorAborts`)
  se všemi gettery + `shouldSkipProvisioning()` → `true`.
- `getTableColumns`/`getTableIndexes` → `[]`, `executeSQL` no-op.
- Spustit, ověřit:
  - `Command::SUCCESS`,
  - výstup obsahuje `Provisioning disabled via config`,
  - výstup obsahuje finální řádek `Upgrade complete.` (schema sync proběhl).

Druhý case **`testUpgradeRunsProvisioningByDefault`** (nebo rozšířit
existující): při `shouldSkipProvisioning() === false` výstup
**neobsahuje** `Provisioning disabled via config`.

(Pozn.: fixture modul `test.unit` neodpovídá žádnému provisionovanému
modulu, takže provisionery stejně early-returnují přes `isModuleActive`.
Rozlišovacím signálem testu je tedy přítomnost/nepřítomnost `[SKIP]`
hlášky — to je čistá a pozorovatelná aserce gate.)

### 4. Dokumentace

#### 4a. `docs/cli.md` — sekce `### ds-upgrade`

Přidat odstavec o `skipProvisioning`:

```markdown
**Vypnutí provisioningu (`skipProvisioning`):** volitelný boolean v
`config/main.json`. Když je `true`, `ds-upgrade` synchronizuje schéma, ale
přeskočí generování referenčních dat (units, druhy položek, fiskální roky,
VAT období, číselné řady, mail router, AI analyzer). Určeno pro import dat
z jiného systému, kde tyto údaje dodává sám import. Po dokončení importu
nastav `skipProvisioning` zpět na `false` a spusť `ds-upgrade` znovu —
provisionery jsou idempotentní, doplní jen chybějící data. Při zapnutém
flagu `ds-upgrade` při každém běhu hlásí `[SKIP] Provisioning disabled via
config`.
```

Rozšířit workflow scénář **„Opakovaný test importu"** (přidaný v
`ds-reset.md`) o flag:

```bash
cd /opt/shipard/data-sources/<id>
# 1. zapnout import mód
#    config/main.json:  "skipProvisioning": true
sudo shpd-ds ds-reset -y        # čistý stav, schéma bez provisioningu
# 2. … spustit import ze starého Shipardu …
# 3. vypnout import mód
#    config/main.json:  "skipProvisioning": false
sudo shpd-ds ds-upgrade         # doplní zbývající referenční data
```

#### 4b. `docs/architecture.md` — volitelná pole `config/main.json`

Tam, kde jsou popsaná volitelná pole `main.json` (vedle `defaultLanguage`
/ `defaultCurrency`), přidat řádek:

```markdown
- `skipProvisioning` (bool, default `false`) — dočasně vypne
  auto-provisioning v `ds-upgrade`; viz `docs/cli.md`.
```

Pokud takový seznam v `architecture.md` není, přidat krátkou zmínku ke
zmínce o `main.json`.

## Co netřeba

- Žádný CLI flag na `ds-upgrade` — config flag se skládá s `ds-reset`
  zdarma a odpovídá „stavovému" workflow importu.
- Žádná selektivní granularita (per-provisioner) — all-or-nothing.
- Neměnit provisionery ani `ds-reset`.
- Negateovat `docs_core_heads` backfill ani `secrets healthCheck` — běží
  vždy.

## Konvence k dodržení

- PHP `declare(strict_types=1)`, getter ve stylu stávajících
  (`?? default`).
- `[SKIP]` hláška `<comment>` na **normální** verbositě (vždy viditelná).
- Czech v UI/docs, English v kódu a identifikátorech.
- Test pattern: rozšířit stávající `DsUpgradeCommandTest` / `DataSourceConfigTest`,
  ne nový harness.

## Hotovo když

- `vendor/bin/phpunit` — vše prochází včetně nových testů.
- `DataSourceConfig::shouldSkipProvisioning()` vrací `false` pro DS bez
  pole a `true` pro `"skipProvisioning": true`.
- `php bin/shpd-ds ds-upgrade` v DS s `skipProvisioning: true` provede
  schema sync, vypíše `[SKIP] Provisioning disabled via config` a žádné
  referenční data nevytvoří.
- Tentýž DS po přepnutí na `false` + `ds-upgrade` doplní chybějící
  referenční data (provisionery proběhnou).
- `shpd-ds ds-reset -y` v DS s `skipProvisioning: true` automaticky
  přeskočí provisioning (flag se propsal přes interní `ds-upgrade`).
- `docs/cli.md` má odstavec o `skipProvisioning` a rozšířený import
  scénář; `docs/architecture.md` zmiňuje pole.
