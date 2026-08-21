# Reporty Fáze 1 — jádro domény + hlavní kniha + REST

**Stav:** hotovo

> PRD pro jednu Claude Code session. Design: `docs/reports.md` (celý, zejména
> §2–§6, §7.2), rozhodnutí D1–D16 (GitHub issue #42 + komentář). Nová doména —
> žádné prerekvizity mimo hotové účetnictví (deník `economy_accounting_journal`
> existuje a plní se).

## Kontext

Zakládáme doménu `report` (datové výstupy). Jádro fáze 1: engine produkuje
**výhradně** strukturovaný `ReportResult` (JSON) — „jeden výpočet,
N prezentací" (D4). První builder je **hlavní kniha** (nejjednodušší
z trojice kniha/výsledovka/rozvaha, čistá agregace deníku bez dopočtů).
Prezentace v této fázi = REST endpoint, aby byl výsledek ověřitelný
end-to-end bez UI. Viewer (Fáze 3) a MCP (Fáze 4) stavějí na stejném
`ReportResult` beze změn jádra.

**Print (tisk dokladů) s tímto nemá nic společného** — žádná sdílená
hierarchie tříd (D1).

## Cíl

1. Core infrastruktura `src/Core/Reports/` — `ReportResult` + hodnotové
   objekty, `ReportBuilder` interface, registry deklarací, validace
   parametrů, překlad období.
2. Nový klíč `reports` v `module.jsonc` + loader (deklarace = JSONC, D16).
3. Deklarace a builder **hlavní knihy** v `modules/economy/accounting`.
4. REST: `GET /_reports` (katalog) a `GET /_reports/{reportId}` (spuštění).
5. Testy.

## Návaznost

- **Odemyká:** Fázi 2 (výsledovka + rozvaha — jen další deklarace
  a buildery), Fázi 3 (Svelte `ReportViewer` + navigace + URL deep-link),
  Fázi 4 (MCP `report_list`/`report_run` + kontrolní diff pro M3).
- Deep-link (D10): frontend dnes **nemá URL routing** — řeší Fáze 3,
  jádro na tom nesmí záviset (parametry jsou obyčejný array).
- Materializace: mimo rozsah (D12) — jádro nesmí nic cachovat.

## Před implementací přečti

- `docs/reports.md` — celý (autoritativní design, D1–D16)
- Deník: `docs/accounting.md` §6 +
  `modules/economy/accounting/tables/economy_accounting_journal.jsonc`
  (sloupce `account`, `account_number`, `is_error`, `money_dr`, `money_cr`,
  `fiscal_year`, `fiscal_month`; index `(fiscal_year, fiscal_month)`)
- Účtový rozvrh: `modules/economy/accounting/tables/economy_accounting_accounts.jsonc`
  (číslo, název, `AccountsLookup`)
- Fiskální období: `economy_codebooks_fiscal_years` / `_fiscal_months`
  (tabulky modulu economy.codebooks)
- `src/Core/Module/ModuleDefinition.php` — vzor přidání klíče (viz
  `journalEventHandlers` ř. 173+, `lookups`, `alertChecks`)
- Loader vzory: `src/Api/LookupLoader.php`, `src/Api/AlertCheckLoader.php`,
  `src/Api/ViewerLoader.php` (JSONC + i18n + resolvnuté moduly)
- REST vzor: `modules/economy/accounting/src/AccountingController.php`
  + registrace route v `src/Api/Router.php` (najdi, jak jsou zapojené
  existující controllery)
- JSONC: komentáře se stripují před parsováním; po změně cfg souborů
  `ds-upgrade` (rebuild kompilované konfigurace)

## Scope

**Uvnitř:** `src/Core/Reports/*`; klíč `reports` v ModuleDefinition +
loader; `modules/economy/accounting/config/reports.jsonc` +
`src/Reports/GeneralLedgerBuilder.php`; `ReportsController` + routes; testy.

**Mimo:** výsledovka, rozvaha (Fáze 2 — ale `SubtotalAggregator` piš
obecně, použijí ho); Svelte UI, navigace, URL (Fáze 3); MCP, diff (Fáze 4);
tisk; materializace; období přes více fiskálních roků; střediska.

## Co implementovat

### A. `src/Core/Reports/` — hodnotové objekty

Vše `final`, `declare(strict_types=1)`, readonly kde to jde.

- **`ReportResult`** — `reportId`, `params` (array), `generatedAt`
  (DateTimeImmutable), `dataSource` (string), `status` (enum
  `ReportStatus: Ok | Warnings | Errors` — odvozený z messages, nezadává
  se ručně), `messages` (ReportMessage[]), `columns` (ReportColumn[]),
  `rows` (ReportRow[]). Metoda `toArray(): array` — přesný tvar dle
  `docs/reports.md` §3.1 (status lowercase string).
- **`ReportMessage`** — `severity` (enum Error|Warning|Info), `code`
  (string, strojový, např. `journal.accountNotFound`), `text` (string,
  lokalizovaný), `rowRef` (?string).
- **`ReportColumn`** — `id`, `type` (v1 jen `money`), `label`.
- **`ReportRow`** — `kind` (enum Detail|Subtotal|Total|Computed), `level`
  (int), `account` (?string — číslo účtu), `label`,
  `values` (array<string, array{md: float, d: float, balance: float}>
  klíčované id sloupce). Pozn.: `Computed` v této fázi nikdo neemituje
  (rozvaha, Fáze 2) — enum hodnotu ale zaveď hned.

### B. `src/Core/Reports/` — engine

- **`ReportDefinition`** — z JSONC deklarace: `id`, `name` (i18n),
  `builderClass`, `periodGranularities` (podmnožina
  `month|quarter|halfYear|year`), `params` (schéma ne-periodových
  parametrů: `[{id, type: enum|bool, options?, default}]`).
- **`ReportRegistry`** — mapa id → ReportDefinition, plněná loaderem (D).
- **`FiscalRange`** — `fiscalYear` + `monthFrom` + `monthTo` (fiskální
  měsíce). **Jediný tvar období, který engine zná** (D8). V1: interval
  vždy uvnitř jednoho fiskálního roku.
- **`ReportParamValidator`** — validuje request params proti definici:
  období (existence fiskálního roku/měsíců v DB, from ≤ to, granularita
  povolená deklarací), ostatní parametry dle schématu (neznámý parametr =
  chyba, chybějící = default). Vrací normalizované params + `FiscalRange`.
  Chyba → `InvalidArgumentException` s lidskou zprávou (controller mapuje
  na 400).
- **`ReportRequest`** — obálka pro builder: `FiscalRange`, normalizované
  params, `DataSourceConnection`, `ConfigRuntime`, jazyk.
- **`ReportBuilder`** (interface) —
  `build(ReportRequest $req): ReportResult`.
- **`ReportRunner`** — `run(string $reportId, array $rawParams): ReportResult`:
  registry → validator → builder → doplň metadata (generatedAt,
  dataSource, status z messages). Jediný vstupní bod pro REST i budoucí
  MCP/diff — controllery nikdy nevolají builder přímo.
- **`SubtotalAggregator`** — obecný prefix-based rollup: z detail řádků
  (klíč = číslo účtu) vyrobí subtotal řádky pro zadané délky prefixů
  (v1: 3 = syntetika, 2 = skupina, 1 = třída) + total. Sčítá `md`/`d`
  per sloupec, `balance` per sloupec = md − d (znaménko dle stran, ne
  prezentace — D6). Piš tak, aby ho Fáze 2 použila beze změn.

### C. `ModuleDefinition` — klíč `reports`

Mirror `lookups`/`alertChecks`: `"reports": [{"file": "config/reports.jsonc"}]`.
Validace tvaru ve `fromArray` (file povinný). Jeden soubor může deklarovat
víc reportů (pole).

### D. `ReportDefinitionLoader` (`src/Api/` dle vzoru LookupLoader)

Projde resolvnuté moduly, načte JSONC soubory z klíče `reports`
(strip komentářů!), aplikuje i18n (`name:cs` vzor jako jinde), naplní
`ReportRegistry`. Duplicitní id reportu = tvrdá chyba při načtení
(failing loudly).

### E. Deklarace hlavní knihy —
`modules/economy/accounting/config/reports.jsonc`

```jsonc
[
    {
        "id": "economy.accounting.generalLedger",
        "name": "General ledger",
        "name:cs": "Hlavní kniha",
        "builder": "Shipard\\Module\\Economy\\Accounting\\Reports\\GeneralLedgerBuilder",
        "periodGranularities": ["month", "quarter", "halfYear", "year"],
        "params": [
            {"id": "detail", "type": "enum", "options": ["analytic", "synthetic"], "default": "analytic"}
        ]
    }
]
```

+ registrace v `modules/economy/accounting/module.jsonc` klíčem `reports`.

### F. `GeneralLedgerBuilder`
(`modules/economy/accounting/src/Reports/GeneralLedgerBuilder.php`)

Sloupce: `opening` (Počáteční stav), `turnover` (Obraty za období),
`closing` (Konečný zůstatek) — všechny `money` buňky `{md, d, balance}`.

Výpočet (jeden fiskální rok Y, interval F–T):

1. `opening`: agregace deníku `fiscal_year = Y AND fiscal_month < F`
   GROUP BY `account_number` — otevírací doklady (701) jsou v deníku
   jako každé jiné účtování (D3), nic se nedopočítává.
2. `turnover`: totéž pro `fiscal_month BETWEEN F AND T`.
3. `closing`: opening + turnover (per strany; balance = md − d).
4. `detail = synthetic`: agregace na 3místný prefix místo plných čísel.
5. Názvy účtů z `economy_accounting_accounts` (join/lookup přes
   `AccountsLookup`, pokud API sedí); nenalezený název → label = číslo účtu.
6. Mezisoučty + total přes `SubtotalAggregator`.
7. Řádky `is_error = 1`: **nezahazovat** — samostatný detail řádek
   (label z chybové masky v `account_number`) + `ReportMessage` severity
   Error, code `journal.accountNotFound` (D15).
8. Řádky s nulovým opening i turnover neemituj (účet bez pohybu
   v roce do knihy nepatří).

Řazení: dle čísla účtu; subtotal řádek následuje za svou skupinou
(vzor screenshotů starého Shipardu — potvrzený layout).

### G. REST — `src/Api/Controller/ReportsController.php`

- `GET /_reports` → `{items: [{id, name, periodGranularities, params}]}` —
  katalog z registry (lokalizované názvy).
- `GET /_reports/{reportId}?fiscalYear=&monthFrom=&monthTo=&detail=…`
  → `ReportResult::toArray()` beze změn (D4 — API vrací výsledek tak,
  jak je).
- Chyby: neznámý report → 404; nevalidní parametry → 400 s textem
  z validatoru. **Výsledek se `status: errors` je HTTP 200** — chyba dat
  není chyba requestu; konzument čte `status` (D15).
- Registrace routes dle existujícího vzoru v Routeru; endpoint čte data →
  stejná auth/DS bariéra jako ostatní čtecí API.

## Testy

PHPUnit (`--filter 'ReportCoreTest|GeneralLedgerBuilderTest|ReportsApiTest'`,
`timeout_sec=120`):

1. **ReportCoreTest** — `ReportResult::toArray` tvar (vč. status odvození:
   bez zpráv → ok, warning → warnings, error → errors);
   `ReportParamValidator` (neznámý param, špatná granularita, monthFrom >
   monthTo, neexistující fiskální rok, defaulty); `SubtotalAggregator`
   (md/d/balance na syntetice, skupině, třídě, total; prázdný vstup).
2. **GeneralLedgerBuilderTest** — seedni deník (vzor existujících DB
   testů): otevírací řádek (month < F), pohyby v intervalu, pohyb po
   intervalu (nesmí vstoupit), řádek `is_error = 1`. Ověř: opening/
   turnover/closing per účet, closing = opening + turnover, synthetic
   režim, error řádek + message, total = suma tříd.
3. **ReportsApiTest** — katalog obsahuje `economy.accounting.generalLedger`;
   run vrací validní tvar; 400 na špatné parametry; 404 na neznámé id.

## Commit strategie

1. `core: reports — ReportResult, engine, deklarace (klíč reports v module.jsonc)`
   (A+B+C+D + ReportCoreTest)
2. `economy.accounting: report hlavní kniha (GeneralLedgerBuilder + deklarace)`
   (E+F + GeneralLedgerBuilderTest)
3. `api: GET /_reports — katalog a spuštění reportu` (G + ReportsApiTest)
4. `docs: reports.md — stav Fáze 1, upřesnění dle implementace`

## Hotovo když

- [x] `ds-upgrade` na dev DS projde (nový klíč module.jsonc, kompilace cfg)
- [x] `GET /_reports` vrací hlavní knihu s lokalizovaným názvem (cs)
- [x] `GET /_reports/economy.accounting.generalLedger?fiscalYear=…&monthFrom=…&monthTo=…`
      na dev DS vrací ReportResult; ručně ověřeno proti pár SQL agregacím
      (SUM money_dr/money_cr per účet) — sedí na halíř
- [x] closing = opening + turnover na každém řádku; total tříd = celkový total
- [x] doklad s `is_error = 1` v období → status errors + message, řádek
      ve výsledku
- [x] všechny tři test suity zelené (narrow --filter)
- [x] `docs/reports.md` aktualizován (Stav: Fáze 1 hotova; případné
      upřesnění tvaru ReportResult dle skutečnosti)

## Otevřené body (nerozhodují o Fázi 1)

- Umístění builderů dlouhodobě: v1 v `economy.accounting` (vlastní deník);
  případný samostatný modul `economy.reports` zvážíme, až přibudou reporty
  mimo účetnictví.
- Sloupce hlavní knihy „Měsíc / Rok" ze starého Shipardu (screenshot) vs.
  opening/turnover/closing — v1 volí opening/turnover/closing (úplnější);
  varianta „období vs. kumulativně od začátku roku" může být v Fázi 2
  parametr (`columns: period|ytd`), rozhodne se podle potřeby výsledovky.
- Drill-down odkaz na deník z řádku (accountId v ReportRow) — přidá Fáze 3
  podle potřeb vieweru.
