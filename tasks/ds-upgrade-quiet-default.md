# `ds-upgrade` — tichý default, akce jen s `-v`

**Stav:** hotovo

## Status / Cíl

`shpd-ds ds-upgrade` je příliš ukecaný — i no-op upgrade vypisuje
desítky `[OK]` řádků za jednotlivé tabulky a hlavičky všech provisionerů.
Cíl: default mód vypisuje **jen akce a varování** (CREATE, ALTER, WARN,
ERROR), kompletní výstup se zapne přes standardní Symfony Console
`-v` flag.

## Návaznost

- Vychází z [`ds-upgrade-all.md`](ds-upgrade-all.md) — ten je v repu
  hotový. Tento task ho rozšiřuje o **propagaci verbosity** do
  vnitřního subprocesu.
- `DsUpgradeCommand` v `src/Command/DataSource/DsUpgradeCommand.php`
  zůstává v podstatě stejný — měníme jen volání `writeln()` o třetí
  parametr `OutputInterface::VERBOSITY_VERBOSE`.

## Mechanismus

Symfony Console má vestavěnou podporu — žádný custom flag, žádná úprava
`configure()`. Stačí třetí parametr `writeln()`:

```php
$output->writeln('Resolving modules...', OutputInterface::VERBOSITY_VERBOSE);
```

Default verbosity = `OUTPUT_NORMAL`. Při `-v` automaticky teče i
`VERBOSITY_VERBOSE`. `-vv` = `VERY_VERBOSE`, `-vvv` = `DEBUG` (ty
nepotřebujeme).

## Co je potřeba udělat

### 1. Reklasifikace výstupů v `DsUpgradeCommand`

Pro každé `writeln()` rozhodnout: **vždy** vs. **jen verbose**.

#### Vždy zobrazit (akce a varování)

- `[CREATE] <table>` (schema sync)
- `[ALTER] <table> — added/modified column/index`
- `[INFO] Created secrets/secrets.key — no data migration needed` (3 řádky)
- `[INFO] Adding encrypted_text column 'X.Y'.` (+ 2 navazující řádky)
- Schema validation warnings (`<comment>` z `SchemaValidator`)
- Všechny `<error>` řádky
- `[CREATE] user '_mail_router'` / `_ai_analyzer` (jen když se vytvořil)
- `[CREATE] mailbox 'default'` / `backend 'default'` / `profile 'czech_invoices'`
- `<comment>[SKIP] mailbox 'default' — <reason></comment>` (skutečně neobvyklé)
- `<comment>API key not set — run 'bin/shpd-ds ai-analyzer-set-key'</comment>` (action item)
- `<comment>[SKIP] config not compiled yet</comment>` (signál chyby pořadí kroků)
- Závěrečné `Upgrade complete. X created, Y altered, Z unchanged.`
- Závěrečné secrets `<comment>  [WARN] ...</comment>`
- Provisioner stats **když `created > 0`** (viz bod 2)

#### Jen s `-v` (verbose)

- Banner `<info>Shipard Data Source Upgrade v0.1.0</info>`
- `Data source: <name> (<id>)`
- `Resolving modules...`
- `  Active modules: N (M direct + K dependencies)`
- `  Module order: ...`
- `Compiling configuration...`
- `  Config items: N` / `Languages: ...` / `Written to: ...`
- `Checking database...`
- `[OK]     <table>` per-table řádky (typicky 50+)
- Header `Provisioning <module>...` všech provisionerů
- `[OK]` provisioner řádky kde `created == 0`
- `[OK] user '_mail_router' (id=N)` když už existoval
- `[OK] mailbox 'default'` / `backend` / `profile` když už existoval
- `<comment>[SKIP] core.units module not active</comment>` (informační)
- Prázdné oddělovací řádky před/za hlavičkami sekcí

### 2. Helper pro provisioner výstupy

Aktuálně mají všechny provisionery podobný řádek:

```php
$output->writeln(sprintf(
    '  [OK]    units — created: %d, existing: %d',
    $units['created'],
    $units['existing'],
));
```

Chování chceme:
- `created > 0` → `[CREATE]`, vždy zobrazit
- `created == 0` → `[OK]`, jen verbose

Doplnit privátní helper:

```php
/**
 * @param array{created: int, existing: int} $stats
 */
private function logProvisioningResult(
    OutputInterface $output,
    string $label,
    array $stats,
): void {
    if ($stats['created'] > 0) {
        $output->writeln(sprintf(
            '  [CREATE] %s — created: %d, existing: %d',
            $label,
            $stats['created'],
            $stats['existing'],
        ));
    } else {
        $output->writeln(sprintf(
            '  [OK]     %s — created: 0, existing: %d',
            $label,
            $stats['existing'],
        ), OutputInterface::VERBOSITY_VERBOSE);
    }
}
```

A použít ho ve všech 5 metodách: `provisionUnits`, `provisionItemKinds`,
`provisionFiscalYears`, `provisionVatPeriods`, `provisionDocCoreNumberSeries`.

Pro mail router a AI analyzer je struktura jiná (per-entity řádky, ne
agregát) — tam jen ručně přidat `OutputInterface::VERBOSITY_VERBOSE`
do `[OK]` větví, `[CREATE]` větve nechat vždy zobrazené.

### 3. Skrytí prázdných sekcí v default módu

Pokud má sekce header `Provisioning X...` a všechna její podčára jsou
verbose-only, header by se taky neměl zobrazit. Řešení: header taky pod
`VERBOSITY_VERBOSE`. Když dojde k akci uvnitř sekce, ta se zobrazí
samostatně — header přitom chybět může (uživatel vidí, že se něco
vytvořilo, kontextová hlavička není podstatná).

Konkrétně: všechny `Provisioning <X>...` řádky a předcházející prázdný
oddělovací řádek označit jako `VERBOSITY_VERBOSE`.

### 4. Propagace verbosity v `DsUpgradeAllCommand`

V `runDsUpgrade()` zjistit aktuální verbosity a předat do subprocesu:

```php
protected function runDsUpgrade(string $dsDir, OutputInterface $output): array
{
    $verbosityFlag = match (true) {
        $output->isDebug() => ' -vvv',
        $output->isVeryVerbose() => ' -vv',
        $output->isVerbose() => ' -v',
        default => '',
    };

    $cmd = sprintf(
        'cd %s && %s ds-upgrade%s',
        escapeshellarg($dsDir),
        escapeshellarg($this->getShpdDsPath()),
        $verbosityFlag,
    );

    $exitCode = 0;
    passthru($cmd, $exitCode);

    return ['success' => $exitCode === 0, 'exitCode' => $exitCode];
}
```

V testovacím `TestableDsUpgradeAllCommand` přidat capture pro tento
parametr — `protected function runDsUpgrade()` může uložit do
`$this->callLog` strukturu `['id' => ..., 'verbosity' => ...]` místo
holého ID.

### 5. Update testů `DsUpgradeAllCommandTest`

Přidat dva test cases:

- **Default verbosity → bez flagu v subprocess** — pustit bez
  `--verbosity`, ověřit že captured commands neobsahují ` -v`
- **`-v` se propaguje** — pustit s `'verbosity' => OutputInterface::VERBOSITY_VERBOSE`
  (přes `CommandTester::execute([], ['verbosity' => ...])`), ověřit že
  captured commands obsahují ` -v`

Existující test cases neměnit — `callLog` může zůstat polem ID, jen
rozšířit datovou strukturu pro tyto dva nové.

### 6. Update `docs/cli.md`

V sekci `### ds-upgrade` (pod `shpd-ds`) přidat odstavec:

```markdown
**Verbosity:** výchozí výstup obsahuje jen akce a varování (`[CREATE]`,
`[ALTER]`, `[INFO]`, `[WARN]`, `[ERROR]`). Pro kompletní výpis včetně
průběhu kompilace konfigurace, kontroly schématu po tabulkách a
provisioner detailů použij `-v`:

\`\`\`bash
shpd-ds ds-upgrade -v
\`\`\`
```

V sekci `### ds-upgrade-all` (pod `shpd-server`) přidat odstavec:

```markdown
**Verbosity propagace:** `-v` se předává do vnitřního volání
`shpd-ds ds-upgrade` na každém DS:

\`\`\`bash
sudo shpd-server ds-upgrade-all -v
\`\`\`
```

## Co netřeba

- Žádný `--quiet` flag (default už je tichý)
- Neměnit format markerů (`[CREATE]`, `[ALTER]`, atd.)
- Neměnit barvení (`<info>`, `<comment>`, `<error>`)
- Neřešit `-vv` a `-vvv` jinak než propagací — žádný náš výstup je
  nepoužívá, Symfony si je interně bere pro debug

## Konvence k dodržení

- `OutputInterface::VERBOSITY_VERBOSE` jako třetí parametr `writeln()`,
  ne testovat `$output->isVerbose()` ručně (až na propagaci v
  `DsUpgradeAllCommand`, kde potřebujeme rozlišit `-v`/`-vv`/`-vvv`)
- Helper `logProvisioningResult()` jako private, ne protected (není
  potřeba pro testy, jednotlivé provisionery jsou samostatné metody)

## Hotovo když

- `php bin/shpd-ds ds-upgrade` na nezměněném DS vypíše **jen** finální
  řádek `Upgrade complete. 0 tables created, 0 tables altered, N tables unchanged.`
  (plus případně `[WARN]` od secrets)
- `php bin/shpd-ds ds-upgrade -v` vypíše vše jako dosud (kompletní
  výstup)
- `php bin/shpd-ds ds-upgrade` po skutečné změně schématu vypíše jen
  `[CREATE]`/`[ALTER]` řádky a souhrn
- `php bin/shpd-server ds-upgrade-all` na čisté instalaci je téměř
  prázdný (jen oddělovače mezi DS a souhrn)
- `php bin/shpd-server ds-upgrade-all -v` vypisuje plný výstup pro
  každý DS
- `vendor/bin/phpunit` projde, včetně 2 nových test cases pro
  verbosity propagaci
- `docs/cli.md` má v obou relevantních sekcích zmínku o verbosity
