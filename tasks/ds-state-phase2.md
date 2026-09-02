# Stavy zdroje dat — fáze 2: read-only vynucení, stavová obrazovka, alert D8

**Stav:** hotovo

## Kontext / Cíl

Fáze 2 stavového modelu DS dle issue **shipard/shpd#56**; navazuje na
hotovou fázi 1 (`tasks/ds-state-phase1.md` — `state.json`, 503 pro
suspended/maintenance/pending_deletion, cron gating, CLI). Cíl fáze:

- stav `read_only` je vynucený na HTTP routách (mutace → 403
  `DS_READ_ONLY`), v MCP (jen read-tier nástroje) a v chatu (vypnutý, D5);
- browser na zavřeném DS dostane stavovou obrazovku, na read-only DS
  banner;
- „zapomenutý" maintenance hlásí warning (D8).

Relevantní rozhodnutí z #56: **D4** (`read_only` nepřijímá poštu — 503,
router frontuje), **D5** (chat vypnutý, MCP read-tier), **D7** (reporty
v read-only fungují; klasifikace per route, ne per HTTP metoda — SSE chat
je GET a spouští LLM, login je POST a fungovat musí), **D8** (maintenance
déle než N dní → warning).

## Rozhodnutí z průzkumu kódu (R1–R6)

- **R1 — centrální `ReadOnlyPolicy`, ne flag na `Route`.** `Router` je
  ručně psaný if-chain s desítkami konstrukcí `Route`; flag by znamenal
  dotknout se každé. Politika klíčovaná `(controller, action)` v jedné
  třídě kopíruje existující vzory (exempt if-chain v `AuthMiddleware`,
  match v `dispatch()`), testuje se jako tabulka a — hlavně — **neznámá
  kombinace = DENY (fail-closed)**: nová routa přidaná bez rozmyslu je
  v read-only zavřená, dokud ji někdo vědomě nepovolí.
- **R2 — vynucení v `index.php` za auth middlewarem** (krok 6.5, před rate
  limitem). Anonymní klient dostane 401 dřív než informaci o stavu DS;
  endpointy exempt z auth (mail, analyzer, hosting) dostanou verdikt
  politiky před svou controller-level autentizací.
- **R3 — tři verdikty:** `ALLOW`; `DENY_403` (`DS_READ_ONLY`) pro
  uživatelské mutace; `DENY_503` (`DS_UNAVAILABLE` + `Retry-After`, vzor
  fáze 1) pro strojové ingest endpointy (`/_mail/incoming` dle D4,
  callbacky AI analyzeru) — dočasná sémantika, volající frontují/retryují
  a nezahazují práci.
- **R4 — stavová obrazovka je v SPA, ne PHP HTML.** SPA se servíruje
  staticky nginxem ze sdíleného `public/app/` (viz `docs/nginx/app.conf`)
  — `index.php` pro `/app/` vůbec neběží. SPA se tedy načte vždy; boot
  volá veřejný `GET /_app/info` (`main.js` → `appInfo` store), a 503
  `DS_UNAVAILABLE` z něj řídí full-screen stavovou obrazovku. Odchylka od
  původní formulace v #56 („statická stránka z index.php") — jednodušší
  a bez zásahu do nginx.
- **R5 — stav DS se vystavuje v `GET /_app/info`** (nové pole `dsState`),
  ne v `/_meta/*` (to je meta tabulek). `/_app/info` je veřejný boot
  endpoint — hodnota je vždy jen `active` nebo `read_only` (blokované
  stavy skončí 503 už v resolveru), nic citlivého neprozrazuje.
- **R6 — D8 jako sdílený scanner + dvě ústí.** Alert checky (`alerts-run`)
  běží *uvnitř* DS a v maintenance jsou cronem vypnuté — D8 musí být
  server-level. Sdílená třída projde `data-sources/*/config/state.json`
  a vrátí DS v maintenance déle než N dní (default **7**, konstanta);
  konzumují ji (a) `doctor` (řádek ⚠ per DS) a (b) nový server-level job
  v `daily` slotu (warning do `ErrorLogger`). Heartbeat pole
  `skippedDataSources` z fáze 1 zůstává jako rychlý indikátor pro doctor.

## Upřesnění z průzkumu kódu před implementací (2026-09-02)

- Verdiktová tabulka níže je orientační — **zdroj pravdy je `Router`**.
  Skutečné akce: `alerts` má jen `registry`/`runDue`/`runCheck` (snooze a
  dismiss jdou přes CRUD), `viewer` má `meta`/`rows`/`detail`, `lookup`
  `search`/`resolve`, `exchange` akce `validate|preview|apply` + prefixy
  `person:`/`item:`/`bank:` (politika matchuje suffix), `password` má navíc
  `invite`/`sessionDelete`/`sessionsRevokeOthers`, `analysis` navíc
  `reanalyze`/`applyMessage`/`unapplyMessage`/`rejectMessage`/`previewMessage`.
- `analysis.previewMessage` je GET bez zápisu → **ALLOW** (jako
  `exchange *:preview`). `setup` čtecí akce zůstávají fail-closed 403 —
  jejich read-only povaha nebyla ověřena.
- **Skrytí chatu (D5) dělá server**, ne frontend: capability `chat`
  v `DashboardController` a chat leaf v `NavigationController` (dnes povinně
  identická podmínka) dostanou `&& !readOnly`.
- **Integrační test přes `index.php` PHPUnitem nejde** (Integration suite
  volá controllery přímo) → unit tabulka politiky + curl smoke na dev DS.
- MCP: `McpToolRegistry::readOnlyView()` sdílí chat i `/_mcp`;
  `McpController` má navíc flag `readOnly`, aby `tools/call` na write nástroj
  vrátil explicitní JSON-RPC chybu.
- D8 daily job = nový příkaz `shpd-server ds-state-check` (factory +
  řádek v `HelpCommand`, hlídá `HelpDriftTest`); scanner používá
  `DataSourceState::isCorrupted()`.
- Frontend: 503 z `/_app/info` přijde přes `client.js` jako JSON
  `{error:{code:'DS_UNAVAILABLE', details:[{field:'_state', code}]}}` —
  store jen čte. Banner do `ContentArea` (sdílí všechny tři shelly); toast
  přes nový minimální `notice` store + i18n `error.DS_READ_ONLY`.

## Před implementací přečti

- issue #56 + `tasks/ds-state-phase1.md` (hotová fáze 1)
- `src/Api/Middleware/AuthMiddleware.php` — exempt vzor (controller/action)
- `public/index.php` — pipeline kroky 5–7 (route → auth → rate limit),
  `dispatch()` match — úplný seznam controllerů a akcí pro tabulku politiky
- `src/Api/ResolvedDataSource.php` + `src/Api/DataSourceResolver.php` —
  stav se ve fázi 1 načítá, ale nepředává dál
- `src/Api/Mcp/McpTool.php` (`isReadOnly()`), `src/Api/Controller/McpController.php`
  — chat tool-use loop už dnes filtruje na read-only nástroje, vzor
- `frontend/src/main.js`, `frontend/src/stores/appInfo.svelte.js`,
  `frontend/src/api/client.js` — boot, chování při chybách
- `src/Command/Server/DoctorCommand.php` — vzor checků a výstupu
- `docs/nginx/app.conf` — proč R4

## Krok 1 — stav do `ResolvedDataSource` + `/_app/info`

- `DataSourceResolver::assertDataSourceAvailable()` → přejmenovat tok:
  načtený `DataSourceState` se po kontrole `blocksHttp()` **předá do
  `ResolvedDataSource`** (nové readonly pole `state`), ať ho pipeline
  nečte z disku podruhé.
- `AppController::info()`: nové pole `dsState` (`active` | `read_only`)
  z `ResolvedDataSource` (protáhnout přes `dispatchApp`).

**Commit 1:** `resolver: DataSourceState v ResolvedDataSource + dsState v /_app/info`

## Krok 2 — `ReadOnlyPolicy` + vynucení

Nová třída `src/Api/ReadOnlyPolicy.php`: `verdict(Route $route): Verdict`
(enum `ALLOW` / `DENY_403` / `DENY_503`), tabulka `(controller, action)`,
**default `DENY_403`** pro neznámé kombinace. Verdikty:

| Controller | ALLOW | DENY_503 (stroje) | DENY_403 (zbytek = default) |
|---|---|---|---|
| `auth`, `password` | vše | — | — |
| `app` | `info`, `manifest`, `brandingGet`, `avatarGet` | — | uploady/delete |
| `meta`, `openapi`, `dsAbout` | vše | — | — |
| `ui`, `settings` | `navigation`, `accountNavigation`, `page` | — | `savePage` |
| `dashboard` | `index`, `sectionBadges` | — | `summary` (LLM, D5) |
| `crud` | `list`, `show`, `docStateOptions` | — | create/update/patch/delete |
| `viewer` | vše (meta/rows/detail) | — | — |
| `form` | `meta` | — | `save`, `recalculate` |
| `lookup` | vše | — | — |
| `attachment` | `download`, `thumbnail`, `list` | — | upload/patch/delete/restore |
| `reports` | vše (D7) | — | — |
| `alerts` | `registry` | — | `runDue`, `runCheck`, snooze/dismiss/unsnooze |
| `setup` | `checklist` | — | vše ostatní |
| `contentTags` | `overview`, `tagItems` | — | `materialize` |
| `exchange` | `*:validate`, `*:preview` (bez zápisu) | — | `*:apply` |
| `personsRegistry` | `search`, `fetchPerson` | — | `import` |
| `mail` | — | `receiveIncoming` (D4) | importMessage, uploadMessages, setSenderPassword |
| `analysis` | — | `queue`, `claim`, `payload`, `attachmentContent`, `result`, `failed` (analyzer) | reanalyze, apply/unapply/reject/previewMessage |
| `chat` | — | — | **vše** (D5) |
| `mcp` | `rpc` (filtr uvnitř, krok 3) | — | — |
| `senderRules`, `registry`, `bank`, `accounting`, `accbal` | — | — | vše |
| `hosting*` | vše (viz pozn.) | — | — |

Pozn. `hosting*`: endpointy řídí lifecycle *jiných* DS a mají vlastní
klíčovou auth; read-only hosting DS, který by zablokoval rekonciliaci
celé flotily, je horší selhání než teoretická mutace hosting dat —
proto ALLOW s komentářem v kódu.

`public/index.php` krok 6.5 (za auth, před rate limit): efektivní stav
`read_only` (z `ResolvedDataSource->state`) → verdikt; `DENY_403` →
`Response::error('DS_READ_ONLY', 'Data source is read-only', 403)`;
`DENY_503` → shodná odpověď jako fáze 1 (`DS_UNAVAILABLE`, `Retry-After:
300`). `active` politiku vůbec nevolá.

Testy: unit tabulka politiky (včetně fail-closed pro vymyšlenou routu);
integrační na read_only DS — `GET crud list` 200, `POST create` 403
`DS_READ_ONLY`, `/_mail/incoming` 503, `login` 200, `reports run` 200.

**Commit 2:** `read-only: ReadOnlyPolicy + vynucení v pipeline (403 DS_READ_ONLY)`

## Krok 3 — MCP read-tier

`dispatchMcp` dostane efektivní stav; v `read_only` `McpController`:

- `tools/list` vrací jen nástroje s `isReadOnly() === true`;
- `tools/call` na ne-read-only nástroj → JSON-RPC error (vzor chybových
  odpovědí v `McpController`), ne HTTP 403 — MCP klienti čtou JSON-RPC.

Použít existující filtr z chat tool-use loopu (sdílet, neduplikovat —
případně přesunout do `McpToolRegistry::readOnlyView()`).

Test: registry s read i write nástrojem — v read_only list neobsahuje
write nástroj a call na něj vrací JSON-RPC error.

**Commit 3:** `mcp: read-tier filtr nástrojů v read_only`

## Krok 4 — frontend: stavová obrazovka + banner

- `appInfo` store: `load()` rozliší 503 `DS_UNAVAILABLE` (z error details
  vezme efektivní stav) → nový store stav `unavailable`; `dsState:
  read_only` → flag `readOnly`.
- Nová komponenta `StatusScreen.svelte` — full-screen obrazovka místo
  aplikace (logo/název je nedostupný — DS config se nečte; generický
  Shipard vizuál), text per stav: suspended („Zdroj dat je pozastaven"),
  pending_deletion (totéž znění — detail klient nepotřebuje), maintenance
  se z 503 nerozlišuje (details nesou jen efektivní stav `suspended`) —
  jeden text s „zkuste to později". Tlačítko „Zkusit znovu" → reload.
- `App.svelte`: `unavailable` → `StatusScreen` místo login/aplikace.
- Read-only banner: trvalý pruh pod headerem („Zdroj dat je jen pro
  čtení") ve všech shellech; chat entry point skrytý (D5).
- `client.js`: 403 `DS_READ_ONLY` → jednotný toast („Zdroj dat je jen pro
  čtení, změny nelze ukládat") místo generické chyby.
- **Mimo rozsah kroku:** plošné skrývání editačních prvků (tlačítka Nový
  záznam, save ve formulářích, drag&drop příloh…) — server vynucuje,
  UI dolaďování průběžně (Anna); banner + toast dávají srozumitelnost.

Build na alfě při nasazení (bundle není v gitu), hard refresh.

**Commit 4:** `frontend: stavová obrazovka DS + read-only banner a toast`

## Krok 5 — D8: scanner + doctor + daily warning

- `src/Core/Server/DataSourceStateScanner.php`: projde
  `data-sources/*/config/state.json` (přes `DataSourceState::load`,
  fail-closed stavy hlásí taky), vrátí seznam `{dsId, efektivní stav,
  maintenance reason/since, stáří}`.
- `DoctorCommand`: nová sekce „Data source states" — počty per stav,
  ⚠ per DS v maintenance déle než 7 dní, ✗ per DS s fail-closed čtením.
- Nový server-level job v `daily` slotu (vzor existujících
  `SERVER_SLOT_JOBS`): tentýž scanner, maintenance > 7 dní → warning do
  `ErrorLogger` (jednou denně, ne spam).

Test: unit scanner nad temp strukturou (active bez souboru, maintenance
starý/nový, poškozený soubor).

**Commit 5:** `d8: scanner stavů DS — doctor sekce + daily warning`

## Krok 6 — dokumentace + úklid CLI

- `docs/ds-state.md`: sekce fáze 2 — verdiktová tabulka politiky (zdroj
  pravdy je kód, doc shrnuje princip + odchylky: mail 503, hosting ALLOW),
  chování SPA, D8.
- `docs/cli.md`: doctor sekce + daily job; **odstranit** poznámku
  u `ds-state set read_only` „HTTP vynucení přijde ve fázi 2" (CLI
  warning z fáze 1 smazat i v `DsStateCommand`).
- `docs/architecture.md`: krok 6.5 do pipeline.
- `docs/mcp-server.md`: read-tier chování v read_only.

**Commit 6:** `docs: read-only vynucení (ds-state, cli, architecture, mcp) + úklid CLI warningu`

## Stav implementace (2026-09-02)

Všech šest commitů hotových (`bf60cc8` … docs). Ověřeno: unit testy kroků
1–5, curl smoke na dev DS s `ds-state set read_only` (create 403, mail
503 + Retry-After, login ne-403, chat 403, chat leaf i capability skryté,
`/_app/info` dsState). **Zbývá ruční ověření v prohlížeči** (StatusScreen
na zavřeném DS, banner + toast na read-only DS) a nasazení na alfu
(`npm run build` na místě, hard refresh; `cron-install` netřeba — daily
job je v `SERVER_SLOT_JOBS`, ne v cron souboru).

## Mimo rozsah fáze 2 (vědomě)

- Hosting orchestrace (desired state, `hosting-sync`, rekonciliace,
  mazací job + záloha D10) — fáze 3.
- Plošné skrývání editačních UI prvků v read-only — průběžně (server
  vynucuje).
- Rozlišení maintenance reason ve stavové obrazovce (503 details nesou
  jen efektivní stav; per-reason texty případně ve fázi 3 s hostingem).
- Konfigurovatelnost N dní pro D8 (konstanta 7; do `server.json` až bude
  důvod).
- Trial + zpětný kanál DS → hosting — odloženo (issue #56).

## Hotovo když

- [x] Na read_only DS: čtení (list/show/viewer/reporty/přílohy/lookup)
      funguje, mutace vrací 403 `DS_READ_ONLY`, `/_mail/incoming`
      a analyzer callbacky 503, login/refresh/logout funguje, chat celý
      403, neznámá routa fail-closed 403
- [x] MCP v read_only: `tools/list` jen read nástroje, `tools/call` na
      write nástroj JSON-RPC error
- [x] `/_app/info` nese `dsState`; SPA na zavřeném DS ukazuje stavovou
      obrazovku, na read_only banner + toast při 403; chat skrytý
- [x] `doctor` má sekci stavů DS; daily job loguje warning pro maintenance
      > 7 dní; fail-closed soubory viditelné v doctoru
- [x] CLI warning z fáze 1 odstraněn; dokumentace z kroku 6 aktualizovaná
- [x] PHPUnit (narrow `--filter`, `timeout_sec: 120`) zelené; 6 commitů
      dle kroků
