# accbal — Clearing infrastruktura pro migrovaný DS

**Stav:** hotovo

> PRD pro jednu Claude Code session (**nov_shipard**). Zajistit, aby clearing
> účty (261200/261300) a saldo skupina `unmatched_payments` existovaly i na
> migrovaném DS, kde je provisioning vypnutý (`skipProvisioning`). Design:
> `docs/accbal.md` §4.5 + rozhodnutí #18.

## Kontext

Clearing účty `261200`/`261300` a saldo skupina `unmatched_payments` normálně
vznikají seedem v `ds-upgrade` (`AccountChartProvisioner`, `BalancesProvisioner`).
Na **migrovaném DS** je celý provisioning blok v `DsUpgradeCommand` vypnutý
(`shouldSkipProvisioning()` → `skipProvisioning=true`), protože osnova i saldo
nastavení se přebírají ze staré strany. Jenže ty dva nové konstrukty ve staré
straně **nemají protějšek**, takže po migraci chybí, a:

- `AccountMaskResolver::resolve('261200', …)` vrátí `null` → bankovní mikroengine
  spadne na `account_not_found` → `accounting_state = 2` u **každé** importované
  platby (hlučné, ale blokující).
- `BalanceMatcher` má kód skupiny natvrdo (`balanceId('unmatched_payments')`,
  řádky 244/309) → bez skupiny vrátí `null` → matcher najde **nula kandidátů**
  a tiše nic neudělá.

Řešení: clearing účty + skupina nejsou *migrovaná data*, ale **infrastruktura
modulů** `bank`/`accbal`. Zajistí je nový `ClearingInfrastructureProvisioner`
**bezpodmínečně** (i pod `skipProvisioning`) v `ds-upgrade`, idempotentně podle
`number` / `code`. Tím je infrastruktura zaručeně přítomna před jakýmkoli
importem (ds-upgrade vždy předchází `all`).

## Návaznost

- **Prerekvizita:** accbal Fáze 1–3 nasazené (tabulky `economy_accbal_balances`,
  `economy_accbal_balance_accounts`, matcher), bank modul nasazený (clearing
  šev, `accountingRules.cz.jsonc` mapuje `bank.unmatched.in/out` → 261200/261300).
- **Párová strana (old_shipard):** pre-flight guard v `AllRunner` + úprava
  `accbalSettings.json` (zúžení skupiny „Peníze na cestě") — viz
  `old_shipard:modules/imports/newShipard/tasks/13-clearing-infra-guard.md`.
  Tahle session se staré strany **netýká**.
- Po nasazení: na cílovém (migrovaném) DS pustit jednou `ds-upgrade`, ať se
  ensure poprvé promítne (idempotentní, re-run neškodí).

## Před implementací přečti

- `docs/accbal.md` §4.4 (clearing šev), §4.5 (tento návrh), rozhodnutí #18
- `modules/economy/accounting/src/AccountChartProvisioner.php` — **vzor** pro
  idempotentní seed účtu (skip podle `number` v jakémkoli stavu; insert s
  `is_system=1`, `docState=40`, `docStateMain=3`; `account_level`/`g1`/`g2`/`g3`
  z `AccountDocument::deriveStructure()`)
- `modules/economy/accbal/src/BalancesProvisioner.php` — **vzor** pro idempotentní
  seed skupiny (skip celé skupiny podle `code`; insert skupiny + řádků
  `_balance_accounts`)
- `modules/economy/accbal/config/balancesDefault.cz.jsonc` — skupina
  `unmatched_payments` (přesné hodnoty řádků, které ensure zrcadlí)
- `modules/economy/accounting/config/accountChartDefault.jsonc` — řádky
  `261200`/`261300` (name / short_name / account_kind)
- `src/Command/DataSource/DsUpgradeCommand.php` — `execute()`: blok za
  „Upgrade complete" + backfill `doc_state_changed_at`, hned **před**
  `if ($dsConfig->shouldSkipProvisioning())`; metody `provisionAccountChart` /
  `provisionAccbalBalances` / `logProvisioningResult` / `isModuleActive` jako vzor
- `tests/Unit/Module/Economy/Accounting/AccountChartProvisionerTest.php` —
  konvence unit testu provisioneru

## Scope

**Uvnitř:** nový `ClearingInfrastructureProvisioner` (nová strana); zapojení do
`DsUpgradeCommand` bezpodmínečně (mimo skip-gate); unit test na drift proti
seedům.

**Mimo:** jakákoli změna enginu, matcheru, accbal seedu, `accountingRules`;
změna staré strany; přesun definic ze seedů (možný pozdější follow-up — viz
Otevřené body).

## Co implementovat

### A. `ClearingInfrastructureProvisioner`

Soubor `modules/economy/accbal/src/ClearingInfrastructureProvisioner.php`,
namespace `Shipard\Module\Economy\Accbal`. Konstruktor `(DataSourceConnection $db)`
— **bez** seed file cesty: infra definice jsou inline konstanty (jsou to fakticky
enginový kontrakt — maska 261200/261300, kód `unmatched_payments`).

Inline konstanty (přesně dle seedů):

```
Účty (economy_accounting_accounts):
  261200  „Nespárované platby — příjmy"  short „Nespárované příjmy"  account_kind 0
  261300  „Nespárované platby — výdaje"  short „Nespárované výdaje"  account_kind 0

Skupina (economy_accbal_balances): code „unmatched_payments",
  name „Nespárované platby", short_name „Nespárované", sort_order 50
  řádky (economy_accbal_balance_accounts):
    261200  acc_side 1 (DAL)  amounts_sign 1  bal_side 1  modify_sign false  „Nespárovaný příjem (clearing)"
    261300  acc_side 0 (MD)   amounts_sign 1  bal_side 1  modify_sign false  „Nespárovaný výdaj (clearing)"
```

`provision()` → `array{accounts: {created:int, existing:int}, group: {created:int, existing:int}}`:

1. **Účty** — pro 261200 i 261300: zrcadlo `AccountChartProvisioner`. `SELECT id
   … WHERE number = %s` (jakýkoli stav) → existuje? skip (`existing++`); jinak
   `AccountDocument::deriveStructure($number)` + `insertRow` do
   `economy_accounting_accounts` s `is_system=1`, `docState=40`, `docStateMain=3`,
   `account_kind=0`.
2. **Skupina** — zrcadlo `BalancesProvisioner`. `SELECT id … WHERE code =
   'unmatched_payments'` → existuje? skip celé skupiny (`existing++`); jinak
   `insertRow` skupiny (`docState=40`, `docStateMain=3`) → zachyť `balanceId` →
   `insertRow` obou řádků do `economy_accbal_balance_accounts`
   (`balance=$balanceId`, `docState=40`, `docStateMain=3`, `sort_order` 10/20).

`AccountDocument` se importuje z `Shipard\Module\Economy\Accounting`
(accbal na accounting závisí — správný směr).

### B. Zapojení do `DsUpgradeCommand`

- `use Shipard\Module\Economy\Accbal\ClearingInfrastructureProvisioner;`
- V `execute()` **bezpodmínečně** (mimo `if/else` skip-gate), hned po backfillu
  `doc_state_changed_at` a **před** `if ($dsConfig->shouldSkipProvisioning())`:
  `$this->provisionClearingInfrastructure($resolvedModules, $dsConnection, $output);`
- Nová privátní metoda `provisionClearingInfrastructure(array $resolvedModules,
  DataSourceConnection $dsConnection, OutputInterface $output)`: guard
  `isModuleActive('economy.accbal')` **a** `isModuleActive('economy.accounting')`
  (jinak skip s comment logem); instancuj provisioner, zavolej `provision()`,
  zaloguj přes `logProvisioningResult` (dva řádky: „clearing accounts",
  „clearing balance group").

> Pozn.: na **normálním** DS (skip=false) ensure běží taky, ale je no-op — doběhne
> první, full provisionery (`provisionAccountChart` / `provisionAccbalBalances`
> v else větvi) pak ty dva účty/skupinu přeskočí (idempotence podle number/code,
> obsah identický). Tím se zacelí i `accountChart='none'` a případná `npo`
> varianta bez těch účtů.

### C. Unit test na drift

`tests/Unit/Module/Economy/Accbal/ClearingInfrastructureProvisionerTest.php`:

- Konstanty provisioneru se shodují se seedy (chrání proti rozejití):
  - 261200/261300 v `accountChartDefault.jsonc` (number / name / account_kind),
  - skupina `unmatched_payments` v `balancesDefault.cz.jsonc` (řádky:
    account_number / acc_side / amounts_sign / bal_side / modify_sign).
- Idempotence: druhý `provision()` na témž stavu → `created=0` (vzor
  `AccountChartProvisionerTest`).

## Hotovo když

- Na **migrovaném** DS (`skipProvisioning=true`) `ds-upgrade` vytvoří 261200,
  261300 (level-4 analytiky, docState 40) a skupinu `unmatched_payments` s oběma
  řádky — i když je provisioning jinak vypnutý.
- `AccountMaskResolver::resolve('261200'/'261300', …)` vrací účet (ne null);
  `BalanceMatcher::balanceId('unmatched_payments')` vrací id.
- Na **normálním** DS se chování nemění (ensure no-op, full provisionery dál
  vytvoří celou osnovu/skupiny; žádné duplicity, žádná `unq` kolize).
- `ds-upgrade` je idempotentní (opakovaný běh → created=0).
- Unit test na drift + idempotenci zelený; PHP lint OK.

## Doporučené pořadí

1. `ClearingInfrastructureProvisioner` (inline konstanty + provision, zrcadlo
   obou existujících provisionerů).
2. Zapojení do `DsUpgradeCommand` (bezpodmínečně, před skip-gate) + log.
3. Unit test (drift + idempotence).
4. Smoke: na testovacím migrovaném DS `ds-upgrade` → ověř účty + skupinu v DB
   a v UI (Nastavení → Saldokonta).

## Rozhodnutí ✓

1. **Infrastruktura, ne migrovaná data** — clearing účty + skupina se zajišťují
   na nové straně bezpodmínečně v `ds-upgrade`, ne injektáží ze staré (D2 ✓).
2. **Trigger = ds-upgrade-time, mimo skip-gate** — ne samostatný krok uvnitř
   `all`; ds-upgrade vždy předchází importu, takže „před doklady/transakcemi" je
   splněno konstrukcí (D3 ✓).
3. **Zdroj pravdy = inline konstanty provisioneru** (= enginový kontrakt), seedy
   nedotčené; drift hlídá test (#18). *(David ✓)*

## Otevřené body

- **Odstranění duplicity (follow-up)** — varianta „jediný zdroj = ensure,
  vyhodit 261200/261300 z `accountChartDefault.jsonc`/`npo` a skupinu z
  `balancesDefault.cz.jsonc`" je čistší proti duplicitě, ale je to větší zásah
  (3 soubory + změna chování normálního DS). Teď řešeno testem na drift; přesun
  zvážit později.
- **Integrační test** (volitelně) — `ds-upgrade` se `skipProvisioning=true` na
  reálném DS ověří přítomnost účtů/skupiny end-to-end (vzor
  `tests/Integration/Accbal/*`).
