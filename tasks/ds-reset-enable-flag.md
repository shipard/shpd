# ds-reset — povolení na produkci přes `enableReset` flag

## Kontext

Alpha provoz (`ns-alpha`) běží se `server.json` `mode=production`, ale na testovacích
zdrojích dat je potřeba opakovaně spouštět `shpd-ds ds-reset` (importní testy, plánovaný
re-import po `docStateMain` fixu). `DsResetCommand` má ale tvrdý produkční guard
(krok 2 v `execute()`): `mode === 'production'` → refuse. Guard je principiálně správný,
chybí mu jen per-DS výjimka pro vědomě označené testovací zdroje.

**Zamítnutá alternativa `--force`:** je globální (resetne kterýkoli DS na produkci),
rozhoduje se v okamžiku spuštění místo předem, a pěstuje svalovou paměť „vždy připiš
`--force`" — degraduje strukturální pojistku na zpomalovač. Viz Rozhodnutí D1.

## Návaznost

- Vzor: `DataSourceConfig::shouldSkipProvisioning()` — volitelný boolean flag
  z `config/main.json`, default `false` (`skipProvisioning`).
- Po implementaci umožní na `ns-alpha` provést `ds-reset` + kompletní re-import
  (mj. pro `tasks/doc-states-main-persistence.md`).
- Dokumentace: `docs/cli.md` (§ `ds-reset`, § `skipProvisioning` jako vzor umístění),
  `docs/architecture.md` (řádek `DataSourceConfig` v tabulce), `docs/operations/production.md`.

## Před implementací přečti

- `src/Command/DataSource/DsResetCommand.php` — `getServerMode()`, `execute()` kroky 1–3
- `src/Core/Config/DataSourceConfig.php` — `shouldSkipProvisioning()` (vzor accessoru)
- `src/Command/Server/DoctorCommand.php` — `checkDataSourceConnections()` (iteruje DS
  a staví `DataSourceConfig`; sem patří warning), `execute()` (odkud vzít mode)
- `tests/Unit/Command/DataSource/DsResetCommandTest.php` — helper `createCommandTester(...)`
  a existující produkční refuse test (ř. ~170)

## Scope

**V rozsahu:** volitelný flag `"enableReset": true` v `config/main.json` datového zdroje,
který na produkčním serveru povolí `ds-reset` pro tento konkrétní DS; hlasitý warning při
uplatnění; warning v `doctor`; testy; dokumentace.

**Mimo rozsah:**

- Žádný `--force` ani jiný CLI bypass (D1).
- Změny chování v development módu (flag se tam nečte, chování beze změny).
- Konfirmační prompt a `--yes` — beze změny (flag obchází jen mode guard, D2).
- Jiné destruktivní příkazy — produkční guard má dnes jen `ds-reset`; nic dalšího
  se nezavádí.

## Co implementovat

1. **`DataSourceConfig::allowsReset(): bool`** — podle vzoru `shouldSkipProvisioning()`:

   ```php
   /**
    * When true, `shpd-ds ds-reset` is allowed even on a production-mode server.
    * Marks a disposable testing/alpha data source. The production guard in
    * DsResetCommand refuses without this flag. Never set it on a data source
    * holding real data; `shpd-server doctor` warns about it on production.
    * Optional; defaults to false when missing from main.json.
    */
   public function allowsReset(): bool
   {
       return $this->data['enableReset'] ?? false;
   }
   ```

2. **`DsResetCommand::execute()`** — konstrukci `$dsConfig` (dnes krok 3) přesunout
   **před** produkční guard (krok 1 už garantuje existenci `config/main.json`) a guard
   rozšířit:

   ```php
   // 2. Production safety net — refuse hard unless the DS explicitly opts in.
   $isProduction = $this->getServerMode() === 'production';
   if ($isProduction && !$dsConfig->allowsReset()) {
       $output->writeln('<error>Refusing to reset a data source in production mode.</error>');
       $output->writeln('ds-reset is a destructive development/testing tool.');
       $output->writeln('For a disposable testing data source, set "enableReset": true in config/main.json.');
       return Command::FAILURE;
   }
   if ($isProduction) {
       $output->writeln('<comment>enableReset is set in config/main.json — resetting a PRODUCTION data source.</comment>');
   }
   ```

   Pozor na pořadí: `DataSourceConnection` a resolver modulů (zbytek dnešního kroku 3)
   zůstávají až za guardem — kvůli flagu se nesmí navazovat DB spojení před refuse.

3. **`DoctorCommand`** — v `checkDataSourceConnections()` (už staví `DataSourceConfig`
   per DS) přidat na produkci warning pro každý DS s flagem:

   ```
   [warn] <dsid>: enableReset is set — data source is resettable on a production server.
   ```

   Jen warning, **ne** failure (na alpha je flag záměrný; doctor nesmí kvůli němu
   vracet nenulový exit code). Metodě bude potřeba předat mode (dnes ho má `execute()`
   / `buildSpec()`); interní změna signatury je OK.

4. **Testy** — `DsResetCommandTest`:
   - Stávající test „production → refuse" beze změny (flag chybí), navíc assert na
     hint `enableReset` v chybovém výstupu.
   - Nový test: production + `enableReset=true` → příkaz projde guardem (dojde
     k drop/confirm fázi) a výstup obsahuje `PRODUCTION` warning.
   - Nový test: development + flag nepřítomen → beze změny chování (regrese).
   - Helper `createCommandTester(...)` rozšířit o možnost injektovat
     `DataSourceConfig` s flagem (stub/anonymní subclass, podle stávajícího stylu).
   - `allowsReset()` default/true pokrýt buď v existujícím `DataSourceConfig` testu,
     nebo nepřímo přes výše uvedené.

5. **Dokumentace:**
   - `docs/cli.md` § `ds-reset` — odstavec o produkčním chování + flagu (analogicky
     k popisu `skipProvisioning` na ř. ~243).
   - `docs/architecture.md` — do řádku `DataSourceConfig` doplnit `enableReset` mezi
     volitelná pole.
   - `docs/operations/production.md` — poznámka: testovací DS na produkci označit
     `"enableReset": true`; před ostrým provozem DS flag odstranit; `doctor` na něj
     upozorňuje.

## Hotovo když

- Na serveru s `mode=production`: `ds-reset` bez flagu → refuse s hintem na
  `enableReset`; s `"enableReset": true` v `config/main.json` → proběhne, výstup
  obsahuje PRODUCTION warning.
- Konfirmační prompt funguje na produkci stejně jako v developmentu (`--yes` ho
  obchází, flag ne).
- V development módu se chování nemění (flag netřeba).
- Před refusem se nenavazuje DB spojení.
- `shpd-server doctor` na produkci vypíše warning pro každý DS s flagem; exit code
  se kvůli němu nemění.
- Nové i existující testy procházejí.
- Dokumentace aktualizovaná (cli.md, architecture.md, production.md).

## Doporučené pořadí

1. `DataSourceConfig::allowsReset()` + test.
2. `DsResetCommand` — přesun konstrukce `$dsConfig` + nový guard; testy (červená → zelená).
3. `DoctorCommand` warning.
4. Dokumentace.
5. Smoke test na `ns-alpha`: refuse bez flagu → přidat flag do `config/main.json`
   testovacího DS → `ds-reset --dry-run` → `ds-reset`.

## Rozhodnutí ✓

- **D1:** Mechanismus je per-DS flag `"enableReset": true` v `config/main.json`;
  žádný `--force`. Jeden mechanismus. ✓
- **D2:** Flag obchází **jen** produkční mode guard. Konfirmační prompt (a `--yes`)
  i vše ostatní beze změny; development mode flag nepotřebuje. Při uplatnění na
  produkci hlasitý warning ve výstupu. ✓
- **D3:** Accessor `DataSourceConfig::allowsReset(): bool` podle vzoru
  `shouldSkipProvisioning()`; default `false`. Refuse hláška radí flag. ✓
- **D4:** `DoctorCommand` na produkci varuje u každého DS s `enableReset=true`
  (pojistka proti zapomenutému flagu); jen warning, ne failure. ✓
- **D5:** Testy v `DsResetCommandTest` (refuse / povolení / development regrese) +
  dokumentace cli.md, architecture.md, production.md. ✓
