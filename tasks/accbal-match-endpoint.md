# API endpoint POST /_accbal/match — vzdálené spuštění matcheru

## Kontext

Po importu ze starého Shipardu je dnes nutné ručně na cílovém serveru spustit
`shpd-ds accbal-match --all`. Import (old_shipard, `all` pipeline) potřebuje
totéž vyvolat „na dálku" přes API jako závěrečný krok — viz
`old_shipard:modules/imports/newShipard/tasks/15-accbal-all-integration.md`
(konzument tohoto endpointu).

Matcher i jeho dávkové API existují (`BalanceMatcher::matchAll(array $filters,
bool $dryRun): MatchSummary`, Saldokonto Fáze 3); CLI obal je
`AccbalMatchCommand`. Chybí jen HTTP obal.

**Časování (ověřeno provozně):** `accbal-match --all` nad migrovanými daty běží
nízké desítky sekund → synchronní endpoint se zvednutými timeouty stačí
s velkou rezervou; asynchronní job by byl overkill (viz Otevřené body).

## Návaznost

- **Prerekvizita/souběh:** `tasks/nginx-fpm-config-includes.md` — zavádí
  `docs/nginx/shipard-common.conf`; tento task do něj přidává
  `fastcgi_read_timeout 600s;`. Pokud include task ještě není zmergovaný,
  implementovat po něm.
- Konzument: old_shipard task 15 (fáze `Match` v `all`, klientský timeout 600 s).
- `docs/accbal.md` — doplní se sekce o API.

## Před implementací přečti

- `src/Command/DataSource/AccbalMatchCommand.php` — celý (wiring vzor:
  ConfigRuntime + dibi + **JournalEventHandlerLoader** + BalanceMatcher;
  validace `--all` XOR filtry; sémantika dry-run)
- `modules/economy/accbal/src/MatchSummary.php` + `MatchResult.php` — tvar
  agregátu (allocated / planned / routedUnallocated / skipped[reason] /
  matchedAmount / candidates())
- `src/Api/Router.php` — `resolveAlertsRoute()` / `resolveBankExchangeRoute()`
  jako vzor route resolveru
- `public/index.php` — dispatch route → controller (vzor `AlertsController`,
  ř. ~415; jaké závislosti má API kontext k dispozici — db, configRuntime, …)
- `src/Api/JournalEventHandlerLoader.php` — signatura `load(...)`
- Existující testy controllerů (vzor: testy `AttachmentController` /
  `AlertsController`, pokud jsou) — použít stejný pattern

## Scope

**V rozsahu:** `POST /api/v1/_accbal/match` (scope all / filtry, dryRun),
agregátní response, timeout opatření (set_time_limit + nginx), testy, docs.

**Mimo rozsah:**

- Destruktivní operace matcheru přes API (`unmatch`, `rematch-partner`) —
  zůstávají jen v CLI.
- Asynchronní zpracování / job fronta — při desítkách sekund zbytečné.
- UI nad matchingem (bucket view — samostatná saldokonto roadmapa).

## Co implementovat

1. **Router** — `resolveAccbalRoute()` podle vzoru alerts:
   - `POST /_accbal/match` → `new Route('accbal', 'match')`;
   - jiná metoda → 405; jiný subpath pod `/_accbal` → 404.

2. **`src/Api/Controller/AccbalController.php`** — akce `match`:
   - Body (JSON, všechna pole volitelná):
     `{"scope": "all", "partner": <int>, "fiscalYear": <int>, "dryRun": <bool>}`.
   - **Validace zrcadlí CLI:** vyžaduj `scope: "all"` NEBO aspoň jeden filtr
     (`partner`/`fiscalYear`); ani jedno → 400 `VALIDATION` s hláškou. `scope`
     jiný než `"all"` → 400.
   - Wiring dle `AccbalMatchCommand::execute()`: dibi + config z API kontextu,
     `JournalEventHandlerLoader::load(...)` (bez něj se po reaccountu nespustí
     re-derivace ledgeru — stejná past jako v CLI), `new BalanceMatcher(...)`.
   - `set_time_limit(0);` na začátku akce (běh v desítkách sekund nesmí
     zabít `max_execution_time`).
   - `$summary = $matcher->matchAll($filters, $dryRun);`
   - **Response — jen agregát, žádné `results[]`** (můžou být tisíce řádků):

     ```json
     {
       "success": true,
       "data": {
         "dryRun": false,
         "candidates": 1234,
         "allocated": 1100,
         "planned": 0,
         "routedUnallocated": 90,
         "skipped": {"no_open_items": 30, "…": 14},
         "matchedAmount": 1234567.89
       }
     }
     ```

     (v dry-run je `allocated=0` a plán je v `planned` — přesně jak plní
     `MatchSummary::add()`.)

3. **`public/index.php`** — dispatch case `'accbal'` → instanciace controlleru
   (závislosti dle vzoru alerts).

4. **nginx timeout** — do `docs/nginx/shipard-common.conf` přidat:

   ```nginx
   fastcgi_read_timeout 600s;
   ```

   (server kontext, platí pro všechny PHP location; bez toho by nginx dlouhý
   match utnul 504 po defaultních 60 s).

5. **Testy** — podle vzoru existujících controller testů:
   - bez `scope`/filtrů → 400; `scope: "nonsense"` → 400;
   - GET → 405; neznámý subpath → 404;
   - happy path s mock/stub matcherem (nebo integrační přes
     `SHIPARD_INTEGRATION_DS_PATH`, pokud pattern existuje) — response
     obsahuje agregátní klíče a neobsahuje `results`;
   - `dryRun: true` se propaguje do `matchAll`.

6. **Dokumentace** — `docs/accbal.md`: nová podsekce „API — dávkové párování"
   (request/response, sémantika dry-run, poznámka o timeoutu). Ověřit, jestli
   interní `_`-prefixované endpointy figurují v OpenAPI specu (`src/Api/OpenApi`)
   — pokud ano (vzor alerts), doplnit i tam; pokud ne, nedoplňovat.

## Hotovo když

- `POST /api/v1/_accbal/match` s API klíčem a `{"scope":"all"}` spustí párování
  a vrátí agregát; s `{"scope":"all","dryRun":true}` vrátí plán bez zápisů.
- Filtry `partner`/`fiscalYear` fungují samostatně (bez `scope`).
- Chybějící rozsah → 400 s jasnou hláškou; GET → 405.
- Response nikdy neobsahuje per-result řádky.
- Reálný běh v desítkách sekund projde bez 504 (nginx `fastcgi_read_timeout`
  z `shipard-common.conf`, ověřit na `ns-alpha` po reload).
- Testy procházejí; `docs/accbal.md` aktualizovaný.

## Doporučené pořadí

1. Router + controller + dispatch + testy (**commit 1**,
   `feat: accbal match API endpoint`).
2. `fastcgi_read_timeout` do `shipard-common.conf` (**commit 2** nebo součást
   commitu 1, podle stavu include tasku).
3. Docs.
4. Smoke na `ns-alpha`: curl s API klíčem, `dryRun: true` → plán; ostrý běh →
   porovnat agregát s výstupem `shpd-ds accbal-match --all`.

## Rozhodnutí ✓

- **D3:** Synchronní endpoint `POST /_accbal/match` (scope all / filtry,
  dryRun); wiring vč. `JournalEventHandlerLoader`; response jen agregát
  z `MatchSummary`; `set_time_limit(0)` + `fastcgi_read_timeout 600s`
  přes `shipard-common.conf`; auth standardně API klíčem. Ověřené časování
  (nízké desítky sekund) → 600 s má velkou rezervu. ✓
- Destruktivní cesty matcheru (`unmatch`/`rematch`) přes API se nevystavují. ✓

## Otevřené body

- Asynchronní job (enqueue + poll) — otevřít jen pokud by běh matcheru
  s rostoucími daty překročil jednotky minut.
- Automatický trigger matcheru po běžné ingestion transakcí (mimo import) —
  samostatná položka saldokonto roadmapy, tímto endpointem nedotčená.
