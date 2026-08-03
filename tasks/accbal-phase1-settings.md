# accbal Fáze 1 — nastavení saldokont

**Stav:** hotovo

> PRD pro jednu Claude Code session. Design: `docs/accbal.md` (§3.1, §3.2, §9
> Fáze 1). Prerekvizita Fáze 0 (`tasks/accbal-phase0-payment-identity.md`) je
> hotová — deník nese symboly + splatnost, bankovní transakce mají
> `payment_reference`.

## Kontext

Saldokonto (`docs/accbal.md`) potřebuje konfiguraci: seznam **skupin saldokont**
(Pohledávky, Závazky, zálohy, …) a ke každé skupině **seznam účtů** s nastavením
strany (MD/DAL), filtru částky a role (předpis/úhrada) + případného obrácení
znaménka (dobropisy). To je čistě CRUD nad dvěma settings tabulkami — žádná
saldo logika (ta přijde ve Fázi 2/3). Vzor 1:1 jako staré Saldo2
(`old_shipard` `e10doc/accBal`: `balances`, `balancesAccounts`), modernizované
do konvencí nového Shipardu.

Tahle fáze **negeneruje** žádné saldo pohyby — jen zakládá nový modul a jeho
nastavení + seed.

## Cíl

1. Nový modul `economy.accbal` se dvěma tabulkami: `economy_accbal_balances`
   (416), `economy_accbal_balance_accounts` (417).
2. CRUD: formuláře + viewery; detail skupiny ukazuje její účty (tabulka
   „Nastavení účtů" jako na referenčním screenshotu).
3. Idempotentní seed standardních skupin + účtů (vč. clearingu jako
   „Nespárované platby") přes provisioner drátovaný do `DsUpgradeCommand`.
4. Obě nastavení dostupná v Nastavení → sekce účetnictví.

## Návaznost

- **Prerekvizita:** Fáze 0 hotová.
- **Odemyká:** Fázi 2 (generátor pohybů čte tohle nastavení).
- Tabulky `economy_accbal_ledger` (418) a `economy_accbal_allocations` (419)
  jsou **mimo tento task** (Fáze 2).

## Před implementací přečti

- `docs/accbal.md` §3.1 (balances), §3.2 (balance_accounts), §9 Fáze 1
- Vzor modulu: `modules/economy/codebooks/module.jsonc` (struktura `tables`/
  `viewers`/`forms`/`settingsItems`), `modules/economy/accounting/module.jsonc`
- Vzor tabulky: `modules/economy/codebooks/tables/economy_codebooks_cash_desks.jsonc`
  (docStates `core.system.docStatesArchive`, columnGroups, system docState
  sloupce, indexy)
- Vzor formuláře: `modules/economy/codebooks/forms/economy_codebooks_cash_desks.jsonc`
- Vzor vieweru: `modules/economy/codebooks/src/CashDesksViewer.php`
  (`$docStatesCfgItem`, `selectRows` s `buildViewGroupFilter`/
  `buildSearchCondition`/`buildPaginationLimit`, `renderRow`, `renderDetail`/
  `buildOverviewContent`)
- Vzor renderDetail s vnořenou tabulkou: `modules/economy/accounting/src/JournalViewer.php`
  (jak se v detailu vykreslí související řádky)
- Vzor provisioneru: `modules/economy/accounting/src/AccountChartProvisioner.php`
  (idempotence dle přirozeného klíče, seed z jsonc, `docState 40`/`docStateMain 3`)
- Registrace provisioneru: `src/Command/DataSource/DsUpgradeCommand.php` —
  `use` (ř. 27 vzor), privátní `provisionAccountChart()` (ř. 432–472),
  volání v guard bloku `skipProvisioning` (ř. 266–273)
- `modules/core/system/config/docStatesArchive.jsonc` (stavy 10/40/70/80/90)
- Referenční screenshot starého saldo nastavení (skupiny + tabulka účtů
  „Nastavení účtů") — v zadání tohoto sezení
- Pro seed: starý `old_shipard` `modules/e10doc/accBal/tables/balances.json`,
  `balancesAccounts.json` a případný importní JSON nastavení (ověřit prefixy
  účtů proti nasazené osnově `modules/economy/accounting/config/accountChartDefault.jsonc`)

## Scope

**Uvnitř:** modul + 2 tabulky + 2 formuláře + 2 viewery + 3 cfgItem enumy +
seed config + provisioner + registrace v `DsUpgradeCommand` + settingsItems.

**Mimo:** ledger/allocations tabulky a generátor pohybů (Fáze 2); událost
`journalWritten`; matcher (Fáze 3); **import/export nastavení** (starý
`AccBalances{Import,Export}Wizard`) — samostatný follow-up, fresh DS pokrývá
provisioner; inline editace účtů přímo ve formuláři skupiny (childTables) —
viz Otevřené body.

## Co implementovat

### A. Modul `economy.accbal`

`modules/economy/accbal/module.jsonc` (vzor `economy.codebooks`):

```jsonc
{
    "id": "economy.accbal",
    "name": "Saldokonto", "name:cs": "Saldokonto", "name:en": "Open items",
    "dependencies": ["core.system", "economy.accounting", "economy.bank", "docs.core", "economy.codebooks"],
    "tables": ["economy_accbal_balances", "economy_accbal_balance_accounts"],
    "viewers": [
        {"id": "economy.accbal.balances", "table": "economy_accbal_balances",
         "class": "Shipard\\Module\\Economy\\Accbal\\BalancesViewer",
         "icon": "scale", "name:cs": "Saldokonta", "name:en": "Balances"},
        {"id": "economy.accbal.balanceAccounts", "table": "economy_accbal_balance_accounts",
         "class": "Shipard\\Module\\Economy\\Accbal\\BalanceAccountsViewer",
         "icon": "list", "name:cs": "Účty saldokont", "name:en": "Balance accounts"}
    ],
    "forms": [
        {"table": "economy_accbal_balances", "id": "economy.accbal.balances"},
        {"table": "economy_accbal_balance_accounts", "id": "economy.accbal.balanceAccounts"}
    ],
    "settingsItems": [
        {"viewer": "economy.accbal.balances", "section": "accounting"},
        {"viewer": "economy.accbal.balanceAccounts", "section": "accounting"}
    ],
    "config": [
        {"id": "economy.accbal.accSides", "file": "config/accSides.jsonc"},
        {"id": "economy.accbal.amountsSigns", "file": "config/amountsSigns.jsonc"},
        {"id": "economy.accbal.balSides", "file": "config/balSides.jsonc"}
    ]
}
```

cfgItem enumy (malé, i18n; vzor enumů s cfgItem):
- `accSides`: `{"0":{"name:cs":"MD"}, "1":{"name:cs":"DAL"}}`
- `amountsSigns`: `{"0":{"name:cs":"Všechny"},"1":{"name:cs":"Kladné"},"2":{"name:cs":"Záporné"}}`
- `balSides`: `{"0":{"name:cs":"Předpis"},"1":{"name:cs":"Úhrada"}}`

### B. Tabulka `economy_accbal_balances` (416)

docStates `core.system.docStatesArchive`, displayPattern `{name}`.

| sloupec | typ | pozn. |
|---|---|---|
| `id` | int PK ai | |
| `code` | varchar 25, not null | stabilní id pro seed/exchange |
| `name` | varchar 140, not null | |
| `short_name` | varchar 80, nullable | |
| `sort_order` | smallint, default 0 | |
| `valid_from` / `valid_to` | date, nullable | |
| `docState` / `docStateMain` | tinyint, system | |

Indexy: unique `code`; `(sort_order, name)`; `(docStateMain, sort_order)`.

### C. Tabulka `economy_accbal_balance_accounts` (417)

docStates `core.system.docStatesArchive`, displayPattern `{account_number}`.

| sloupec | typ | pozn. |
|---|---|---|
| `id` | int PK ai | |
| `balance` | int, FK economy_accbal_balances, not null | |
| `account_number` | varchar 12, not null | **prefix** účtu (`311` chytí `311100`) |
| `acc_side` | enumInt, cfgItem `economy.accbal.accSides` | 0 MD / 1 DAL |
| `amounts_sign` | enumInt, cfgItem `economy.accbal.amountsSigns` | 0 Všechny / 1 Kladné / 2 Záporné |
| `bal_side` | enumInt, cfgItem `economy.accbal.balSides` | 0 Předpis / 1 Úhrada |
| `modify_sign` | boolean, default 0 | obrátit znaménko (dobropisy) |
| `note` | varchar 80, nullable | |
| `sort_order` | smallint, default 0 | |
| `valid_from` / `valid_to` | date, nullable | |
| `docState` / `docStateMain` | tinyint, system | |

Indexy: `(balance, sort_order)`; `(account_number)`; `(docStateMain, sort_order)`.

### D. Formuláře

- `forms/economy_accbal_balances.jsonc`: code, name, short_name, sort_order,
  valid_from, valid_to.
- `forms/economy_accbal_balance_accounts.jsonc`: balance (picker/combo na
  balances), account_number, acc_side, amounts_sign, bal_side, modify_sign,
  note, sort_order, valid_from, valid_to.

Document class **netřeba** (žádná derivovaná pole; `required` ve formuláři
stačí). Přidat jen pokud chceš tvrdou validaci `account_number` (neprázdné,
číselný prefix).

### E. Viewery (`src/`, namespace `Shipard\Module\Economy\Accbal`)

- `BalancesViewer` (vzor `CashDesksViewer`): seznam skupin (název + code,
  badge stavu). **`renderDetail`**: properties (Identifikace) + vnořená
  **tabulka účtů** skupiny (`type: table`, vzor `JournalViewer`/frontend §7) —
  sloupce Účet / Strana / Částky / P-Ú / *−1 / Poznámka, dotaz
  `WHERE balance = id ORDER BY sort_order`. To je ekvivalent „Nastavení účtů"
  ze screenshotu.
- `BalanceAccountsViewer` (vzor `CashDesksViewer`): seznam řádků s vyhledáním
  per účet; sloupec se skupinou. Volitelně filtr `balance` (select skupin) —
  ať jde z detailu skupiny odkázat na filtrovaný seznam (`open_viewer` akce).

### F. Seed + provisioner

`modules/economy/accbal/config/balancesDefault.cz.jsonc` — pole skupin, každá:
`{code, name, short_name, sort_order, accounts: [{account_number, acc_side,
amounts_sign, bal_side, modify_sign, note}]}`.

Definované skupiny (přesné mapování účtů ověřit proti nasazené osnově):

- `receivables` „Pohledávky": `311 MD Kladné Předpis`, `311 DAL Kladné Úhrada`
- `payables` „Závazky": `321 DAL Kladné Předpis`, `321 MD Kladné Úhrada`,
  `311 MD Záporné Předpis *−1`, `311 DAL Záporné Úhrada *−1`, + ostatní
  závazkové (`325/331/336/341/342/345/379` — DAL Předpis / MD Úhrada)
- `advances_given` „Poskytnuté zálohy": `314`
- `advances_received` „Přijaté zálohy": `324`
- `unmatched_payments` „Nespárované platby" (clearing, varianta B —
  `docs/accbal.md` §4.4): `261200 DAL Kladné Úhrada`, `261300 MD Kladné Úhrada`
- `prepaid_expenses` „Náklady příštích období": `381`
- `loans_given` „Poskytnuté půjčky", `loans_received` „Přijaté půjčky",
  `credits` „Úvěry" — skupiny založit dle screenshotu; **účty doplnit z
  old_shipard seedu** (`balancesAccounts.json`) a ověřit prefixy v osnově
  (nevymýšlet — pokud prefix v osnově není, poznamenat do Otevřených bodů).

`src/BalancesProvisioner.php` (vzor `AccountChartProvisioner`): idempotentní —
existuje-li balance se stejným `code` (libovolný stav), **přeskočit celou
skupinu** (uživatel si ji mohl upravit); jinak INSERT balance (`docState 40`,
`docStateMain 3`) + INSERT jejích účtů. Vrací `{balances: {created, existing}}`.

Registrace v `src/Command/DataSource/DsUpgradeCommand.php`:
- `use Shipard\Module\Economy\Accbal\BalancesProvisioner;`
- privátní `provisionAccbalBalances($resolvedModules, $dsConnection, $output)`
  (vzor `provisionAccountChart`, ř. 432–472) — guard na resolved modul
  `economy.accbal`, seed file path, `new BalancesProvisioner(...)`,
  `provision()`, report stats
- volání **za** `provisionAccountChart` v guard bloku (ř. 268)

## Hotovo když

- `ds-upgrade` na čistém DS vytvoří obě tabulky a provisioner naseeduje skupiny
  vč. „Nespárované platby"; opětovný `ds-upgrade` hlásí `existing` (idempotence).
- V Nastavení → Účetnictví jsou „Saldokonta" i „Účty saldokont"; lze přidat/
  upravit/archivovat skupinu i účet.
- Detail skupiny „Závazky" ukazuje tabulku účtů vč. dobropisových řádků
  (311 záporné *−1) — vizuálně odpovídá referenčnímu screenshotu.
- Archivační stavy (10/40/70/80/90) fungují (taby active/archive/trash).
- Žádný saldo pohyb se negeneruje (Fáze 2).

## Doporučené pořadí

1. Modul + 3 cfgItem enumy + obě tabulky → `ds-upgrade` (tabulky vzniknou).
2. Formuláře + viewery → ruční CRUD smoke test.
3. `renderDetail` skupiny s tabulkou účtů.
4. Seed config + provisioner + registrace v `DsUpgradeCommand` →
   `ds-upgrade`, ověřit seed.
5. settingsItems → ověřit v Nastavení.

## Rozhodnutí ✓

1. Nový modul `economy.accbal`. *(David ✓)*
2. Clearing varianta B — skupina „Nespárované platby" (261200/261300). *(David ✓)*
3. Nastavení = 2 standalone CRUD tabulky (skupiny + účty), detail skupiny
   ukazuje její účty; faithful k starému Saldo2.
4. enumy přes cfgItem (konvence nového systému), ne inline `enumValues`.
5. Provisioner idempotentní dle `code` skupiny (nepřepisovat customizaci),
   drátovaný do `DsUpgradeCommand` za account chart.

## Otevřené body

- **Inline editace účtů ve formuláři skupiny** (childTables, jako heads→rows) —
  bohatší UX než dvě samostatné tabulky, ale větší frontend lift. Fáze 1 jede
  na standalone + detail; childTables je možný pozdější polish. *(rozhodnout,
  jestli chceš rovnou)*
- **Sekce nastavení** — Fáze 1 dává obě pod „accounting". Dedikovaná sekce
  „Saldokonto" (vlastní `navSections` položka, jako na screenshotu) je možná
  alternativa. *(rozhodnout)*
- **Účty skupin loans/credits/prepaid** — doplnit z old_shipard seedu; pokud
  prefix v nasazené osnově chybí, doplnit účet do osnovy je samostatná drobnost.
- **Import/export nastavení** — follow-up (starý `AccBalances{Import,Export}Wizard`);
  fresh DS pokrývá provisioner, mezi-DS přenos je nice-to-have.
