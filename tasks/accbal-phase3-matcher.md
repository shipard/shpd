# Saldokonto — Fáze 3: matcher (párování úhrad)

**Stav:** hotovo

## Kontext

Saldokonto (`economy.accbal`) páruje úhrady proti předpisům (pohledávky,
závazky, …) **čistě nad účetním deníkem**. Fáze 0–2b jsou hotové a nasazené:
deník nese platební symboly + splatnost, běží událost `journalWritten`
a `LedgerGenerator`, který z deníku idempotentně derivuje saldo pohyby
(`economy_accbal_ledger`, tableId 418). Tabulka `economy_accbal_allocations`
(419) je založená a **prázdná** — plní ji teprve matcher.

Tato fáze přidává **matcher**: přesune nespárované bankovní úhrady z clearingu
na 311/321 a naváže je na otevřené předpisy. Autoritativní design je
`docs/accbal.md §5` (rozhodnutí #13–#17 v §10). PRD ho **nenahrazuje** —
implementuj podle §5, tady je scope, pořadí a akceptace.

## Návaznost

- **Staví na** Fáze 0–2b (committed). Nemění deník, `LedgerGenerator` ani seed
  saldokont — jen je využívá.
- **Autoritativní design:** `docs/accbal.md §5` (+ §10 rozhodnutí #13–#17).
- **Pouze `nov_shipard`.** Z `old_shipard` se neportuje nic (starý
  `AccBalanceCreator` měl párování zašité do journal řádku — přesně to, co
  rušíme). Parsery/exchange mají symboly přejmenované z Fáze 0.
- **Měkká závislost — partner resolution při ingestaci** (dohledání `partner`
  u bankovní transakce z protiúčtu) **zatím není**. Auto-matcher zpracuje jen
  transakce s vyplněným `partner`. Na importovaných datech ze starého Shipardu
  partnera máme → tuto fázi lze odladit; resolver je samostatný follow-up
  (`docs/accbal.md §11`).

## Před implementací přečti

- `docs/accbal.md` **§5 celé** + §10 rozhodnutí #13–#17 — návrh matcheru,
  kontrakt přes `operation`, routing, algoritmus, vrstvy rozpárování.
- `modules/economy/bank/src/BankTransactionAccountingEngine.php` —
  `accountTransaction()`, `resolveCounterpartyAccount()` (`operation → cat →
  maska → účet`), `operationOf()`, emise `journalWritten` po commitu. **Engine
  se v této fázi NEMĚNÍ.**
- `modules/economy/bank/src/BankController.php` metoda `reaccount()` — vzor
  tenké vrstvy nad enginem (vyžaduje stav 40, volá `accountTransaction`).
- `modules/economy/accbal/src/LedgerGenerator.php` — stabilní klíč
  `(source_kind, source_id, balance, bal_side, account_number)`, co dělá
  re-derivace při změně účtu (DELETE clearing pohybu + INSERT 311 pohybu
  s novým `id`; cascade úklid allocations v `upsert()`).
- `modules/economy/accbal/tables/economy_accbal_ledger.jsonc` (418) +
  `economy_accbal_allocations.jsonc` (419) — sloupce, na které matcher píše.
- `modules/economy/accbal/config/balancesDefault.cz.jsonc` — kódy skupin
  (`receivables` / `payables` / `unmatched_payments`) a že 311 DAL / 321 MD
  jsou už klasifikované jako Úhrada.
- `modules/economy/bank/config/txOperations.jsonc` +
  `modules/economy/accounting/config/accountingRules.cz.jsonc` — kam přidat
  matched operace a řádky předpisu.
- `src/Core/Config/ConfigRuntime.php` — cfgItem se čte z **kompilovaného**
  `config/configuration/compiled.{lang}.json`; změny v `.jsonc` se musí
  promítnout rebuildem (viz Doporučené pořadí, krok 0).
- `src/Command/DataSource/BankImportStatementCommand.php` — vzor `DataSource`
  CLI příkazu vč. nadrátování runtime (config, db connection,
  `JournalEventHandlerLoader` — **bez něj se po reaccountu nespustí
  re-derivace ledgeru!**).

## Scope

**V rozsahu:**
- matched operace + řádky předpisu (config) pro clearing → 311/321
- jádro matcheru: routing + FIFO/VS alokace + kontrakt přes `operation` +
  `accountTransaction`
- zápis `allocations` (auto, `created_by=0`) vč. proporčních domácích částek
  a haléřového dorovnání
- přegenerace bucketu, úplné rozpárování (`unmatch`)
- CLI `AccbalMatchCommand` s `--dry-run`
- testy algoritmu + round-trip reaccount→re-derivace→alokace

**Mimo rozsah (pozdější):**
- UI párování + bucket pohled „kdo kolik dluží" (controller akce + Svelte)
- auto-trigger po ingestaci / cronu
- partner resolution při ingestaci
- zálohy (314/324), zápočty, kurzové rozdíly, multi-cíl (jedna platba na
  fakturu + odpočet zálohy), explicitní entita případu, otevírací doklady

## Co implementovat

### 1. Config — matched operace a routing na 311/321

`txOperations.jsonc`: přidat `payment.in.matched` (direction 1,
`cat: bank.matched.in`, name:cs „Příjem (spárováno)") a `payment.out.matched`
(direction 2, `cat: bank.matched.out`, name:cs „Výdaj (spárováno)").

`accountingRules.cz.jsonc`: do `categories` přidat `bank.matched.in` /
`bank.matched.out`; do `accounts` přidat `{"cat": "bank.matched.in",
"accountMask": "311"}` a `{"cat": "bank.matched.out", "accountMask": "321"}`.
(Maska 311/321 je shodná s `receivables`/`payables`, takže matched úhrada
dosedne na týž účet jako předpis faktury — stejný bucket.)

### 2. Jádro matcheru — `modules/economy/accbal/src/BalanceMatcher.php`

Konstruktor v duchu enginu: `\Dibi\Connection $db`, `?ConfigRuntime $config`,
`JournalEventDispatcher $journalEvents`, `?DataSourceConfig $dsConfig`. Engine
si instancuje stejně jako `BankController::reaccount`
(`new BankTransactionAccountingEngine($db, $config, $journalEvents)`).

Veřejné metody (názvy orientační):
- `matchTransaction(int $txId, bool $dryRun = false): MatchResult`
- `matchAll(array $filters = [], bool $dryRun = false): MatchSummary` — iteruje
  kandidáty `date_transaction ASC, id ASC`
- `rematchBucket(int $partner, int $balance, string $currency): MatchSummary`
- `unmatch(int $txId): void`

**Kandidát** = úhradový pohyb (`bal_side=1`) ve skupině `unmatched_payments`
(clearing). Tím se automaticky vyloučí poplatky/úroky (jdou na 568/662/562, ne
na clearing) i už spárované platby (nejsou na clearingu). Z kandidáta plyne
`bank_transaction`, `partner`, `currency`, `amount`/`amount_hc`,
`payment_reference`.

**`matchTransaction` (clearing → 311/321):**
1. Cíl dle směru transakce: příjem → `receivables`, výdaj → `payables`
   (id skupin podle `code`). Opačné páry MVP neřeší → skip.
2. `partner` povinný; chybí → skip (zůstává na clearingu).
3. Načti **otevřené předpisy** partnera v cíli a měně: `ledger` `bal_side=0`,
   `balance = cíl`, `partner`, `currency`, reziduum
   `= amount − COALESCE(Σ allocations.amount WHERE request_entry = ledger.id, 0)
   > 0`. **Napříč fiskálními roky** (bucket nezahrnuje `fiscal_year` — viz
   Rozhodnutí). Řazení FIFO: `due_date ASC` (NULL na konec), tie `ledger.id ASC`.
4. **VS signál:** když `payment_reference` platby jednoznačně sedí na *právě
   jeden* otevřený předpis → ten první do výše rezidua, zbytek pokračuje FIFO.
   Shoda na 0 nebo ≥2 předpisy → ignoruj VS, čisté FIFO.
5. **Konzervativní brána:** `payment.amount ≤ Σ rezidua` (+ haléř) a ≥1 otevřený
   předpis. Nesplněno → skip (zůstává na clearingu; tím i přeplatky).
6. Sestav plán (částka na předpis); domácí částka **proporčně z platby**
   (`alloc.amount × payment.amount_hc / payment.amount`); haléřové dorovnání
   na poslední alokaci tak, aby `Σ alloc.amount == payment.amount` i
   `Σ alloc.amount_hc == payment.amount_hc`.
7. `dryRun` → vrať plán, **nic neměň**.
8. Jinak: nastav `tx.operation = payment.in.matched` (resp. `out`), doplň
   `tx.partner` pokud chybí; zavolej `engine->accountTransaction(txId)` →
   reaccount + synchronní `journalWritten` → `LedgerGenerator` re-derivuje.
9. Najdi nový úhradový pohyb `(source_kind=bankTransaction, source_id=txId,
   balance=cíl, bal_side=1)`. **Chybí** (re-derivace polkla chybu) → log + skip
   alokace (311 úhrada zůstane nealokovaná = signál, idempotentně dohnatelné).
10. INSERT `allocations` (`created_by=0`) dle plánu.

Ledger částky jsou už normalizované (post `modify_sign`, `amounts_sign=1`
kladné) → matcher pracuje s kladnými magnitudami, `bal_side` rozlišuje
předpis/úhradu; znaménky se nezabývá.

**`rematchBucket`:** smaž `created_by=0` allocations bucketu → znovu `matchAll`
pro `(partner, balance, currency)`. Ruční (`created_by=1`) **nech být** a počítej
je do spotřebovaného rezidua. Platby zůstávají na 311 (routing se nehýbe);
neúplné pokrytí → nealokované reziduum na 311 (best-effort, žádná brána).

**`unmatch`:** nastav `tx.operation` zpět na `payment.in/out` → reaccount.
Re-derivace smaže 311 pohyb a cascade **všechny** jeho allocations (auto
i ruční) — vědomá destruktivní akce.

### 3. CLI — `src/Command/DataSource/AccbalMatchCommand.php`

Name `accbal-match`. Runtime nadrátuj dle `BankImportStatementCommand`
(config + `DataSourceConnection` + `JournalEventHandlerLoader`, jinak se
re-derivace nespustí). Volby:
`--all`, `--partner=`, `--fiscal-year=`, `--rematch-partner=`, `--unmatch=`,
`--dry-run`. Výstup: čitelný report (partner, platba, kandidáti, plán; souhrn
matched / skipped + důvod / Σ částka). Default chování bez `--all`/filtru =
nic neudělá (vyžádej filtr nebo `--all`).

### 4. Testy

- **Algoritmus (čisté unit, bez DB):** FIFO řazení; VS jednoznačná shoda →
  přednost; VS shoda na ≥2 → FIFO; konzervativní brána skipne přeplatek;
  haléřové dorovnání (`Σ == payment` v obou měnách); ruční allocations snižují
  reziduum a matcher na ně nesáhne; „600 na 3×250" dá 250/250/100.
- **Round-trip (vzor accbal/bank testů):** accounted bankovní příjem na
  clearingu + otevřený 311 předpis → `matchTransaction` → clearing pohyb zmizí,
  vznikne 311 úhrada + allocation; `unmatch` → vrátí na clearing, allocation
  pryč. Idempotence: druhý `matchAll` nic nezmění.

## Hotovo když

- Matched operace + řádky předpisu jsou v configu a po rebuildu viditelné přes
  `cfgItem` (matched reaccount vyrobí 311/321 řádek + partner).
- `accbal-match --dry-run` na importovaných datech vypíše smysluplné plány bez
  jakékoli změny.
- `accbal-match --all` spáruje konzervativně pokrytelné platby: clearing pohyby
  zmizí, vzniknou 311/321 úhrady s `allocations` (`created_by=0`); nepokrytelné
  platby zůstanou na clearingu.
- Skupina „Nespárované platby" po běhu obsahuje právě to, co matcher
  konzervativně nespáruje (čistý signál).
- `--rematch-partner` přepočítá auto allocations a zachová ruční;
  `--unmatch` vrátí platbu na clearing vč. cascade allocations.
- Běh je idempotentní (opakované spuštění bez efektu) a `Σ allocations`
  každé spárované platby sedí na její částku v obou měnách.
- Testy z bodu 4 procházejí; `SchemaDriftTest` zelený (žádná změna schématu se
  ale nečeká — jen config).

## Doporučené pořadí

0. **Config + rebuild.** Přidej matched operace a řádky předpisu (bod 1),
   přegeneruj `compiled.{lang}.json` (`ds-upgrade` / rebuild configu) a ověř,
   že `cfgItem('economy.bank.txOperations')['payment.in.matched']` resolvuje.
   Bez tohoto kroku reaccount na matched operaci spadne na `account_not_found`.
1. **Jádro matcheru** (bod 2) — nejdřív čistá funkce „plán" (testovatelná bez
   DB), pak routing + bránu, pak exekuci (reaccount + zápis allocations).
2. **CLI** (bod 3) s `--dry-run` jako první použitelná cesta.
3. **Testy** (bod 4).
4. Ruční ověření na importovaném DS: `--dry-run`, pak `--all`, kontrola
   clearingu a bucketů.

## Rozhodnutí ✓

1. ✓ **Spárovanost = hodnota `operation`** (D1). Matcher nastaví matched operaci
   a zavolá stávající `accountTransaction`; clearing → 311/321 je výstup řetězce
   `operation → cat → maska`. Nula změn v enginu, accbal seedu i deníku;
   reverzibilní/idempotentní přes re-derivaci.
2. ✓ **Konzervativní routing** (D2). Směr určuje cíl (příjem → 311, výdaj →
   321), partner povinný, jen stejná měna. Platba opouští clearing jen když je
   celá alokovatelná; jinak zůstává (signál) — tím i přeplatky zůstávají na
   clearingu.
3. ✓ **Matcher = samostatný explicitně volaný průchod**, NE handler
   `journalWritten` (D3). Rozpojuje smyčku reaccount → událost → matcher. Běh
   monotónní (jen přidává páry). Nejdřív CLI dávka s `--dry-run`.
4. ✓ **FIFO dle splatnosti, VS jako signál** (D4). VS přebije FIFO jen při
   jednoznačné shodě na jeden předpis. Alokace v měně dokladu, domácí proporčně
   z platby, haléřové dorovnání na poslední alokaci. `specific_symbol` v MVP
   není auto-signál.
5. ✓ **Dvě vrstvy rozpárování** (D5). Auto allocations jednorázové, ruční
   posvátné. Přegenerace bucketu = smaž auto + spusť znovu (platby zůstanou na
   311). Úplné rozpárování = `operation` zpět + reaccount (cascade smaže
   i ruční).
6. ✓ **Bucket nezahrnuje `fiscal_year`** — matcher páruje napříč roky
   (importovaná data nemají otevírací doklady, platba 2026 musí dosednout na
   otevřený předpis 2025). `fiscal_year` na pohybu je jen pro reporting/filtry.
7. ✓ **Pouze `nov_shipard`**, žádný koordinovaný PRD ve starém Shipardu.

## Otevřené body

- **Interakce s otevíracími doklady období (§7).** Až přijdou otevírací/závěrkové
  doklady, znovu posoudit, zda má párování zůstat napříč roky, nebo se omezit na
  rok (předchozí rok re-materializovaný jako otevírací předpis). Do té doby
  napříč roky (Rozhodnutí #6).
- **Ruční párovací UI** (mimo scope) — guard „ruční allocation ≤ min(reziduum
  předpisu, zbytek platby)", výběr předpisů, záměrný přeplatek / kříž měn.
- **Výkon dávky** — per-platba zpracování je nezávislé; pro desetitisíce plateb
  případně progress/chunking, ne nutné pro MVP.
- **Partner resolution při ingestaci** — předpoklad auto-matcheru; samostatný
  follow-up.
