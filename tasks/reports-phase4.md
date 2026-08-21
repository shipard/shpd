# Reporty Fáze 4 — MCP tooly + kontrolní diff + report-run CLI

**Stav:** hotovo

> Implementováno 2026-08-21. Testy (ReportDiffTest, ReportMcpToolsTest,
> ReportCliTest) + CLI smoke na dev DS zelené (self-diff exit 0,
> pozměněná kopie exit 1 s tabulkou rozdílů). Z checklistu „Hotovo když"
> zbývá ruční smoke AI asistenta v chatu a založení navazujícího tasku
> v `old_shipard` (exportér dle kontraktu `docs/reports.md` §7.4).
> Odchylka od zadání: MCP tooly se registrují podmíněně přítomností
> `economy_accounting_journal` (vzor Spisovny).
>
> PRD pro jednu Claude Code session. Design: `docs/reports.md` (D4, D11,
> D14, D15). Staví na Fázích 1–3. Uzavírá plán domény z issue #42.

## Kontext

Poslední dvě prezentace nad `ReportResult` (D4): **MCP** (AI asistent —
D11: dva generické tooly, počet neroste s počtem reportů) a **kontrolní
diff** (D14 — validace importu ze starého Shipardu, M3).

K diffu: starý Shipard strukturovaná data reportů negeneruje a jeho
reportovací engine oživovat nebudeme. Kontrola „stejný report za stejné
období sedí" se redukuje na **shodu agregovaného deníku per účet
a období** — detail řádky hlavní knihy. Stará strana = malý SQL exportér
v migračním pipeline (**samostatný task v `old_shipard`**, mimo tento
PRD), který vyprodukuje JSON kompatibilní s diffem (viz §Diff, tvar
vstupu). Tento PRD dodává novou stranu: diff engine + CLI, a k tomu
`report-run` CLI, aby šel `ReportResult` získat skriptovatelně bez HTTP
autentizace.

## Cíl

1. MCP tooly `report_list` + `report_run` (D11) + registrace.
2. `ReportDiff` (porovnání dvou `ReportResult`) + CLI `report-diff`.
3. CLI `report-run` (JSON na stdout — vstupní materiál pro diff a skripty).
4. Testy.

## Návaznost

- **Prerekvizity:** Fáze 1–3.
- **Odemyká:** M3 validaci importu (po dodání exportéru v `old_shipard` —
  navazující task tam), AI asistenta nad reporty („porovnej výsledovku Q1
  a Q2…" — funguje hned, diff mezi obdobími nepotřebuje starou stranu).
- Mimo: materializace, tisk (#34), oficiální výkazy.

## Před implementací přečti

- `docs/reports.md` §7.3, §7.4 + D15 (sémantika `status: errors` — MCP
  tool ji deklaruje v popisu; diff označuje/odmítá)
- MCP vzor: `src/Api/Mcp/McpTool.php` (interface), `McpInvocationContext`
  (auth, db, tables, config — dost pro `ReportRunner`),
  `modules/base/persons/src/Mcp/PersonsSearchTool.php` (styl popisů:
  kdy použít / NEpoužít), `buildMcpRegistry` v `public/index.php`
  (in-code registrace — vzor pro oba tooly) a `dispatchReports` tamtéž
  (wiring `ReportRunner` — stejná konstrukce v toolech)
- CLI vzor: `src/Cli/DsApplicationFactory.php` (registrace commandů),
  `src/Command/DataSource/DsSettingCommand.php` (DS z cwd, výstup)
- `src/Core/Reports/ReportRunner.php`, `ReportResult::toArray()`

## Scope

**Uvnitř:** dva MCP tooly + registrace; `src/Core/Reports/ReportDiff.php`;
commandy `ReportRunCommand`, `ReportDiffCommand`; testy; docs.

**Mimo:** exportér staré strany (task v `old_shipard`, založíme po této
fázi); jakékoli změny builderů, REST, UI; chat prompt tuning.

## Co implementovat

### A. MCP — `report_list`
(`modules/economy/accounting/src/Mcp/ReportListTool.php`)

Pozn. k umístění: tooly jsou generické (jádro domény), ale MCP tooly
zatím žijí v modulech — drž konvenci, umísti k accounting reportům.

- `name: report_list`, `isReadOnly: true`, `inputSchema`: object bez
  povinných polí.
- `call`: z `ReportRegistry` (postav loaderem — v toolu lazily, ctx nese
  vše potřebné kromě resolveru; resolver si vytvoř jako `dispatchReports`)
  vrať `{summary, items: [{reportId, name, periodGranularities, params,
  fiscalYears}]}` — `fiscalYears` z `DbFiscalPeriodProvider` (jednou,
  ne per report), aby model uměl rovnou sestavit validní volání
  `report_run` bez hádání roků.
- `description`: k čemu reporty jsou (hlavní kniha / výsledovka /
  rozvaha nad účetním deníkem), že vrací katalog + dostupná období,
  a že pro data se volá `report_run`. NEpoužívat pro seznam dokladů
  či faktur (od toho documents_search).

### B. MCP — `report_run`
(`modules/economy/accounting/src/Mcp/ReportRunTool.php`)

- `inputSchema`: `reportId` (string, required), `fiscalYear` (integer,
  required), `monthFrom`/`monthTo` (integer 1–12, required), `detail`
  (`enum analytic|synthetic, default synthetic`) — **default pro MCP je
  synthetic** (menší výstup pro LLM; UI default zůstává analytic),
  popiš to ve schema description.
- `call`: `ReportRunner::run()` (wiring dle `dispatchReports`);
  `InvalidArgumentException` z validace nech propadnout (mapuje se
  na -32602, vzor ostatních toolů). Návrat: `{summary, report:
  ReportResult::toArray()}` — `summary` jedna věta: název, období,
  status + počet messages (např. „Rozvaha 2026/1–8, status: errors
  (2 messages)") — model status nepřehlédne ani bez čtení celého JSONu.
- `description` MUSÍ obsahovat sémantiku D15: výsledek se
  `status: "errors"` nejsou spolehlivá čísla — nahlásit uživateli,
  nepoužívat mlčky pro výpočty; `warnings` zmínit. Dále: doporuč
  `detail: synthetic` pro přehledy a `analytic` jen pro dohledávání
  konkrétního účtu (velikost výstupu).
- Registrace obou toolů v `buildMcpRegistry` (in-code, vzor persons).

### C. Diff — `src/Core/Reports/ReportDiff.php`

Čisté porovnání dvou dekódovaných `ReportResult` array (final třída,
bez DB — testovatelná unit testem):

- **Porovnávají se pouze `kind: detail` řádky** (klíč = `account`)
  a jako kontrolní součet řádky `kind: total` (klíč = label). Subtotaly
  a computed se ignorují — derivují z detailů; stará strana je mít
  nemusí. Diff tak nevyžaduje identické `reportId` ani stejnou sadu
  sloupců-metadat, jen **stejná id sloupců** u porovnávaných hodnot
  (průnik sloupců; sloupce jen na jedné straně → warning v souhrnu).
- Výstup: `{identical: bool, differences: [{account, column, field
  (md|d|balance), a, b, delta}], onlyInA: [...účty], onlyInB: [...],
  columnsOnlyInA/B: [...], statusA, statusB}`. Tolerance 0,005.
- **D15**: vstup se `status: errors` diff nezastaví, ale výsledek nese
  `statusA/statusB` a CLI je zřetelně tiskne; `--strict` (viz D) při
  errors končí chybou. (Rozhodnuto dříve: default označit, strict
  odmítne.)

### D. CLI

1. **`ReportRunCommand`** (`report-run`): argumenty `reportId`, options
   `--fiscal-year`, `--month-from`, `--month-to`, `--detail`
   (default **analytic** — plný detail je pro diff žádoucí), `--pretty`.
   DS z cwd (vzor DsSettingCommand), wiring `ReportRunner` jako
   `dispatchReports`. Výstup: čistý JSON na stdout (žádné dekorace —
   pipe-friendly); `status: errors` → poznámka na **stderr**, exit code
   zůstává 0 (výsledek je legitimní, D15).
2. **`ReportDiffCommand`** (`report-diff`): argumenty `fileA`, `fileB`
   (cesty k JSON; `-` = stdin pro jeden z nich), options `--strict`,
   `--json` (strojový výstup ReportDiff struktury místo lidského
   souhrnu). Lidský výstup: statusy stran, počty shod/rozdílů, tabulka
   rozdílů (účet, sloupec, pole, A, B, delta), onlyInA/B. **Exit code:
   0 shoda, 1 rozdíly, 2 chyba vstupu / strict violation.**
3. Registrace obou v `DsApplicationFactory`.

### E. Tvar vstupu pro starou stranu (kontrakt, jen dokumentace)

Do `docs/reports.md` §7.4 doplň: exportér staré strany produkuje minimální
`ReportResult`-kompatibilní JSON — `{reportId: "external.oldShipard.
generalLedger", params: {...volné...}, status: "ok", messages: [],
columns: [{id: "opening"...}, {id: "turnover"...}, {id: "closing"...}],
rows: [{kind: "detail", level: 4, account: "...", label: "...",
values: {...md/d/balance...}}]}` — žádné subtotaly, žádný total (diff
si vystačí; total volitelný). Přesné sloupce si určí task v `old_shipard`
podle toho, co stará DB umí — minimálně `closing` k datu.

## Testy

`--filter 'ReportDiffTest|ReportMcpToolsTest|ReportCliTest'`:

1. **ReportDiffTest** (unit, bez DB) — shoda; rozdíl v md/balance;
   účet jen v A / jen v B; sloupec jen v jedné straně; tolerance
   (0,004 = shoda, 0,006 = rozdíl); strany se status errors →
   statusA/B propagované; subtotaly/computed ignorovány (strana B bez
   nich == strana A s nimi při shodných detailech).
2. **ReportMcpToolsTest** (integrace, vzor builder testů nad dev DS) —
   `report_list`: 3 reporty + fiscalYears; `report_run`: validní tvar,
   summary obsahuje status; default detail synthetic; nevalidní
   parametry → InvalidArgumentException.
3. **ReportCliTest** (integrace) — `report-run` vypíše parsovatelný JSON
   (spusť command přes Symfony CommandTester, cwd/DS dle vzoru
   existujících command testů, pokud jsou; jinak otestuj command class
   přímo); `report-diff` na dvou temp souborech: shoda → exit 0,
   rozdíl → exit 1 + rozdíl v output, `--strict` s errors → exit 2.

## Commit strategie

1. `core: reports — ReportDiff (porovnání dvou ReportResult)` (C + unit test)
2. `cli: report-run a report-diff` (D + test)
3. `economy.accounting: MCP tooly report_list a report_run` (A+B + test)
4. `docs: reports.md — stav Fáze 4 + kontrakt exportu staré strany`

## Hotovo když

- [x] všechny tři test suity zelené; existující suity nedotčené
- [x] dev DS: `bin/shpd-ds report-run economy.accounting.generalLedger
      --fiscal-year 2026 --month-from 8 --month-to 8 > /tmp/a.json`
      funguje; `report-diff /tmp/a.json /tmp/a.json` → exit 0, „identical"
- [x] uměle pozměněná kopie (jiná částka) → exit 1, rozdíl vypsaný
      s účtem/sloupcem/delta
- [ ] v chatu (dev DS) AI asistent přes `report_list`/`report_run` odpoví
      na „jaká je rozvaha k srpnu?" — ruční smoke test
- [x] `docs/reports.md`: stav Fáze 4 + §7.4 kontrakt exportu
- [ ] po merge: založit navazující task v `old_shipard`
      (exportér agregovaného deníku dle kontraktu §7.4) — udělá David
      s Claudem v old_shipard kontextu, tento PRD jen připomíná

## Otevřené body (nerozhodují o Fázi 4)

- Limit velikosti výstupu `report_run` pro MCP (analytic ledger za rok
  na velkém DS = tisíce řádků) — zatím řešeno doporučením synthetic
  v popisu; tvrdý limit / stránkování až podle chování na alfě.
- Diff období proti období (ne A/B souborů, ale „srovnej 2026/7 vs
  2026/8") — jde složit z `report-run` × 2 + `report-diff`; vestavěná
  zkratka až bude potřeba.
- `report_list` vs. budoucí non-accounting reporty — tooly jsou generické,
  umístění v accounting modulu se případně přehodnotí s modulem
  `economy.reports` (otevřený bod Fáze 1).
