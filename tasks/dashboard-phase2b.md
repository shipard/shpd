# Task: Dashboard — fáze 2b (generované AI shrnutí)

**Stav:** hotovo

## Status / Cíl fáze

Nahradit statický text v `AiSummaryCard` **generovaným shrnutím dne** —
první viditelný generativní AI prvek na home obrazovce. Feed (fáze 2a) už
zviditelňuje AI práci analyzeru; tato fáze přidá krátké přirozené shrnutí
nad feedem („Dnes máte 3 faktury připravené k zaúčtování a 1 alert po
splatnosti…").

Klíčové vlastnosti (rozhodnutí D11–D16): **neblokující** (feed se vykreslí
okamžitě, shrnutí dotéká přes SSE), **cache podle hashe feedu** (žádné LLM
volání, když se feed nezměnil), **degraduje** na statické county při chybě.

Návaznost na spec: [`docs/dashboard.md`](../docs/dashboard.md) §AI shrnutí
(dnes „plánováno"), designový základ [`docs/dashboard-feed.md`](../docs/dashboard-feed.md) §9.

## Návaznost

- `tasks/dashboard-phase2.md` — feed, `DashboardController` (card assembly,
  `MailSuggestionsSource`/`AlertsSource`), `AiSummaryCard.svelte`
  (dnes county, `aiText=null`), `GET /_ui/dashboard`. **Shrnutí reusuje tytéž
  karty jako vstup.**
- `tasks/chat-phase2a-streaming.md` — `LlmClient::streamChat`,
  `AnthropicLlmClient`, `LlmChatParams`/`LlmChatResult`, SSE streaming pattern
  v `ChatController` + FE konzument (`api/sse.js`). **Shrnutí reusuje tuto
  cestu** (bez tool-use).
- `tasks/mcp-server-*`, `mail-phase3a.md` — AI backend/profil konfigurace
  (`AIBackendLookup`), stejná, jakou používá chat i analyzer.
- `tasks/alerts-*`, `mail-phase*` — zdroje karet.

Tato fáze **nezavádí nový LLM stack** — reusuje `streamChat` (jedno streamované
volání, žádné tools) a existující backend konfiguraci. Nová je jen malá
cache tabulka, service pro sestavení promptu/hashe a jeden SSE endpoint.

## Před implementací přečti

- **`docs/dashboard.md`** — §AI shrnutí (nahrazujeme „plánováno"), API tvar,
  `AiSummaryCard`
- **`docs/dashboard-feed.md`** — §9 (směr: generované, líné+cache, neblokující)
- `docs/chat.md` — **SSE streaming** (formát událostí, jak `ChatController`
  emituje stream, FE konzument)
- `docs/ai.md` — LLM subsystém, backend/profil, `LlmClient` kontrakt
- `src/Api/Controller/ChatController.php` — **vzor**: jak se z backend konfigurace
  staví `LlmChatParams` a jak se streamuje SSE odpověď (reuse obojího)
- `modules/core/ai/src/AIBackendLookup.php` — načtení default backendu
  (provider, model, apiKey, baseUrl)
- `src/Core/Ai/LlmClient.php`, `AnthropicLlmClient.php`, `LlmChatParams.php`,
  `LlmChatResult.php` — cesta k LLM. **`temperature=null`** (Opus 4.7/4.8 jinak
  vrací HTTP 400), `tools=null` (bez tool-use), `maxTokens` malý (~300)
- `src/Api/Controller/DashboardController.php` — **cíl**: refaktor card assembly
  do `collectCards()`, přidat `summary()` endpoint
- `src/Api/Router.php` — registrace SSE routy (vzor: chat stream routa)
- `modules/core/ai/tables/*.jsonc` — konvence definice tabulky (vzor pro
  cache tabulku) + jak se tvoří přes `ds-upgrade`
- `frontend/src/components/dashboard/AiSummaryCard.svelte` — **cíl** rozšíření
- `frontend/src/api/sse.js`, `frontend/src/api/chat.js` — FE SSE konzument (reuse)
- `frontend/src/api/dashboard.js` — kam přidat stream wrapper

## Scope

### V rozsahu

**Backend**
- Malá cache tabulka `core_ai_dashboard_summary` (1 řádek per jazyk)
- `DashboardSummaryService` — sestaví digest z karet, spočítá hash, obslouží
  cache, při miss zavolá `streamChat`, uloží výsledek
- Refaktor `DashboardController`: card assembly do privátní `collectCards()`
  (sdílí `dashboard()` i `summary()`)
- Nový **SSE endpoint** `GET /_ui/dashboard/summary`
- Načtení backend konfigurace přes `AIBackendLookup` (reuse chat cesty)

**Frontend**
- `AiSummaryCard.svelte`: po načtení feedu otevře SSE stream, text dotéká do
  karty; cache hit = okamžitý text; prázdný feed / chyba = statický text (2a)
- `api/dashboard.js`: `streamDashboardSummary(handlers)` nad SSE konzumentem
- Refresh button znovu otevře stream (server rozhodne hit/miss)

**i18n**: pár klíčů (stav „generuji…", degradace); labely shrnutí generuje LLM

**Dokumentace**: `docs/dashboard.md` (§AI shrnutí → hotovo), `docs/ai.md`
(nový konzument LlmClient), `docs/architecture.md` (endpoint), `CLAUDE.md`.
**`docs/README.md` neupravovat** (David).

### Mimo rozsah

- **Per-user shrnutí** — feed není per-user (2a); shrnutí je per-DS + per-jazyk.
- **Chat / „proč je tohle navržené"** — deferováno (celý chat mimo).
- **Levnější dedikovaný model** pro shrnutí — D15: reuse backend model; override
  odložen.
- **Telemetrie/dashboard nákladů LLM** — usage se uloží (input/output tokens),
  ale reporting mimo.
- **Personalizace tónu/délky shrnutí** — pevný prompt.
- **Auto-refresh/polling** — jen mount + manuální refresh.

## Architektura

```
  GET /_ui/dashboard          GET /_ui/dashboard/summary  (SSE)
        │                              │
        ▼                              ▼
  DashboardController          DashboardController::summary()
  ::dashboard()                       │
        └──── collectCards() ◄─────────┤  (sdílené — stejné karty)
                                       ▼
                          DashboardSummaryService::stream()
                                       │
              buildDigest(cards, tasksCount, dnes, lang)
                                       │
                       hash = sha256(digest)   ── obsahuje datum (D12)
                                       │
                    ┌──────────────────┴──────────────────┐
              cache HIT (stejný hash)              cache MISS / prázdný feed
                    │                                     │
          emit text + done (žádné LLM)        prázdný feed → done(text=null)
                                              jinak: LlmClient::streamChat
                                                → SSE text delty
                                                → done, upsert cache
                                       │
                                       ▼
                             AiSummaryCard.svelte
                     (text dotéká / hit okamžitě / degradace)
```

## Cache & regenerace (D12)

- **Digest** = kanonická struktura z: county dle kind (urgent/review/ready) +
  počet úkolů + **top ~6 karet** (kind, stableKey, titulek, částka) +
  **dnešní datum (`Y-m-d`)** + jazyk.
- **Hash** = `sha256(json_encode(digest))`. Tentýž digest se použije i pro
  prompt (D13) — sestaví se jednou.
- **Datum v hashi** realizuje „regeneruj aspoň jednou denně" bez samostatného
  TTL časovače: přechod přes půlnoc → nový hash → regenerace; v rámci dne
  regenerace jen při změně feedu.
- **Úložiště**: `core_ai_dashboard_summary` `{ language (unikát), input_hash,
  text, input_tokens, output_tokens, generated_at }`. Upsert per jazyk.
- Servíruj z cache když `input_hash` sedí; jinak regeneruj a upsertni.

## Prompt (D13)

- **System**: stručný asistent shrnující domovský feed Shipardu pro dnešek;
  **2–4 věty**, jazyk dle `lang`, akčně (nejnaléhavější první); **nic
  nevymýšlet** mimo dodaná data; **próza, ne odrážky**.
- **User**: serializovaný digest (county + top karty s titulkem/kind/částkou +
  datum). **NE** plný `extracted_json`.
- `maxTokens ~300`, `temperature=null`, `tools=null`.
- **Soukromí**: digest obsahuje partnery/částky — stejná data, jaká analyzer
  LLM už posílá; žádná nová hranice (pozn. do `docs/ai.md`).

## API kontrakt

### `GET /_ui/dashboard/summary` (SSE)

**Auth**: Bearer (jako `/_ui/dashboard`).

**Content-Type**: `text/event-stream` (vzor: chat stream v `ChatController`).

**Události** (reuse formátu chatu, redukovaný):
- `text` — `{ "delta": "…" }` inkrementální text (jen při cache miss + LLM)
- `done` — `{ "text": "…"|null, "cached": true|false }` finální; `text=null`
  = prázdný feed / žádné shrnutí (FE ponechá statický)
- `error` — `{ "message": "…" }` LLM/transport chyba → FE degraduje

**Chování**:
- **prázdný feed** (urgent+review+ready == 0) → rovnou `done{ text:null }`,
  žádné LLM, žádný zápis do cache.
- **cache hit** → `done{ text, cached:true }`, žádné LLM.
- **cache miss** → stream `text` delt → `done{ text, cached:false }`, upsert cache.
- **chyba LLM** → `error` (bez zápisu do cache).

## Změny souborů — backend

### 1. `modules/core/ai/tables/core_ai_dashboard_summary.jsonc` — **nové**
Definice tabulky dle konvence sousedních `core_ai_*` tabulek. Sloupce:
`language` (string, unikátní index), `input_hash` (string), `text` (text),
`input_tokens`/`output_tokens` (int, null), `generated_at` (datetime).
**Vyžaduje `ds-upgrade`** (schéma) — první v pořadí implementace.

### 2. `src/Core/Dashboard/DashboardSummaryService.php` — **nové**
```php
final readonly class DashboardSummaryService
{
    public function __construct(
        private DataSourceConnection $db,
        private LlmClient $llm,
        private AIBackendLookup $backends,   // default backend cfg
    ) {}

    /**
     * @param list<array<string,mixed>> $cards  z DashboardController::collectCards()
     * @param callable(string):void $onDelta
     * @return array{text: ?string, cached: bool}
     */
    public function stream(array $cards, int $tasksCount, string $language, callable $onDelta): array
    {
        $digest = $this->buildDigest($cards, $tasksCount, $language); // vč. Y-m-d
        if ($this->isEmpty($digest)) {
            return ['text' => null, 'cached' => false];
        }
        $hash = hash('sha256', json_encode($digest, JSON_THROW_ON_ERROR));

        $cached = $this->readCache($language);
        if ($cached !== null && $cached['input_hash'] === $hash) {
            return ['text' => $cached['text'], 'cached' => true];
        }

        $backend = $this->backends->default();          // provider/model/key/baseUrl
        $params  = new LlmChatParams(
            provider: $backend->provider,
            model:    $backend->model,
            apiKey:   $backend->apiKey,
            baseUrl:  $backend->baseUrl,
            system:   $this->systemPrompt($language),
            messages: [['role' => 'user', 'content' => $this->userPrompt($digest)]],
            maxTokens: 300,
            temperature: null,
            tools: null,
        );

        $result = $this->llm->streamChat($params, $onDelta);
        $this->upsertCache($language, $hash, $result);   // text + usage
        return ['text' => $result->text, 'cached' => false];
    }

    private function buildDigest(array $cards, int $tasksCount, string $language): array { /* county dle kind + top ~6 + Y-m-d + lang */ }
    private function isEmpty(array $digest): bool { /* žádné actionable karty */ }
    private function systemPrompt(string $lang): string { /* §Prompt */ }
    private function userPrompt(array $digest): string { /* serializace digestu */ }
    private function readCache(string $lang): ?array { /* SELECT … LIMIT 1 */ }
    private function upsertCache(string $lang, string $hash, LlmChatResult $r): void { /* upsert */ }
}
```
- `AIBackendLookup->default()` — sleduj skutečné API lookupu (název metody/VO).
  Když backend nemá klíč (neaktivovaný), `stream()` vrátí `text=null` (degradace,
  žádná chyba).

### 3. `src/Api/Controller/DashboardController.php`
- **Refaktor**: card assembly z `dashboard()` do privátní `collectCards(FeedContext): array`
  (+ `tasksCount`). `dashboard()` i `summary()` ji volají.
- **`summary()`**: sestaví SSE odpověď (vzor `ChatController`): načte
  `collectCards()`, zavolá `DashboardSummaryService::stream()` s `$onDelta`
  emitujícím SSE `text` událost; na konci `done`; při výjimce `error`.
  Prázdný feed / cache hit → jen `done` (service vrátí bez volání `$onDelta`).

### 4. `src/Api/Router.php`
- Registrovat `GET /_ui/dashboard/summary` → `DashboardController::summary`
  (jako SSE, vzor chat stream routy).

## Změny souborů — frontend

### 5. `frontend/src/api/dashboard.js`
```js
// Nad existujícím SSE konzumentem (api/sse.js). Vrací handle s .close().
export function streamDashboardSummary({ onDelta, onDone, onError }) {
  return openSse('/_ui/dashboard/summary', {
    text:  (d) => onDelta(d.delta),
    done:  (d) => onDone(d.text, d.cached),
    error: (d) => onError(d.message),
  });
}
```
(Přesné API dle `api/sse.js`/`api/chat.js`.)

### 6. `frontend/src/components/dashboard/AiSummaryCard.svelte`
- Props z 2a: `summary` (counts). Přidat lokální stav `aiText`, `streaming`.
- `$effect`/`onMount` po prvním renderu: `streamDashboardSummary`:
  - `onDelta` → append do `aiText`, `streaming=true`
  - `onDone(text)` → pokud `text` neprázdný, zobraz ho; jinak ponech statický
    count text (2a). `streaming=false`
  - `onError` → ponech statický count text (tichá degradace), zaloguj
- Když `aiText` prázdný → render statického textu z countů (beze změny 2a).
- Subtilní „generuji…" indikátor během streamu (ne blokující spinner).
- Odregistrovat stream při unmountu / refreshi (uložit handle, `.close()`).
- Refresh dashboardu → znovu otevřít stream.

## Empty & failure

| Situace | Chování |
|---|---|
| Žádné actionable karty | `done{text:null}` → statický text „Vše hotovo…" (2a) |
| Backend bez API klíče | `text=null` → statický text (tichá degradace) |
| LLM chyba/timeout | `error` → statický text, log server-side |
| Cache hit | okamžitý text, žádné LLM |

## i18n klíče

```
dashboard.aiSummary.generating   — „Generuji shrnutí…" / „Generating summary…"
dashboard.aiSummary.failed       — (volitelně, tiché; jinak žádný klíč)
```
Samotné shrnutí generuje LLM v jazyce `lang` — do slovníků nepatří.

## Testy

### Backend — `tests/Unit`
- `DashboardSummaryServiceTest`:
  - `buildDigest`/hash stabilita: stejné vstupy → stejný hash; změněná karta →
    jiný hash; **jiné datum → jiný hash**.
  - cache hit: mock `LlmClient` — `streamChat` **není** zavolán, vrátí uložený text.
  - cache miss: `streamChat` zavolán, cache upsertnuta (text + usage).
  - prázdný feed → `text=null`, `streamChat` nevolán, žádný zápis.
  - backend bez klíče → `text=null`, žádná výjimka ven.
- `DashboardControllerTest`: `collectCards()` refaktor nemění tvar
  `/_ui/dashboard` (regresní); `summary()` emituje `done` na prázdném feedu.

### Backend — integrace (pokud harness)
- `summary` SSE: první volání stream + upsert; druhé (nezměněný feed) cache hit.

### Frontend — manuální smoke
1. Otevři dashboard → feed hned, shrnutí dotéká (vidíš „generuji…" pak text).
2. Reload bez změny feedu → shrnutí **okamžitě** (cache hit).
3. Aplikuj/zamítni kartu (feed se změní) → reload → shrnutí se přegeneruje.
4. Prázdný feed → statický „Vše hotovo".
5. Vypni/rozbij AI backend (odeber klíč) → karta ukáže statické county, feed OK.
6. Přepnutí cs/en → shrnutí ve zvoleném jazyce (nový hash per jazyk).

## Dokumentace

- **`docs/dashboard.md`** — §AI shrnutí: z „plánováno" na popis (SSE endpoint,
  cache dle hashe+datum, degradace, prompt vstupy, soukromí).
- **`docs/ai.md`** — nový konzument `LlmClient` (dashboard summary, bez tools);
  pozn. o soukromí digestu.
- **`docs/architecture.md`** — `GET /_ui/dashboard/summary` k endpointům.
- **`CLAUDE.md`** — krátká zmínka o AI shrnutí + cache.
- **`docs/README.md`** — **neupravovat** (David).

## Doporučené pořadí

1. **`ds-upgrade` prereq**: definuj cache tabulku (krok 1) → `ds-upgrade`,
   ověř vznik tabulky. (Schéma první — dle principu „config/schema před kódem".)
2. **`DashboardSummaryService`** + digest/hash/cache + unit testy (bez LLM;
   mock `LlmClient`). `vendor/bin/phpunit 2>&1`.
3. **`collectCards()` refaktor** + `summary()` SSE endpoint + routa.
   Ověř `curl -N` na SSE (cache miss vs hit).
4. **`AiSummaryCard` stream + `api/dashboard.js`** + i18n. `npm run build 2>&1`,
   `npm run check:i18n`.
5. **Manuální smoke** (6 scénářů) — vč. degradace při odebraném klíči.
6. **Dokumentace**.

## Konvence

- API/SSE na drátě camelCase; SSE formát reuse chatu.
- PHP 8.5 `strict_types`, `readonly`, `final`; service bezstavová.
- **`temperature=null`, `tools=null`, `maxTokens` malý** (viz LLM path).
- Cache nikdy neblokuje feed; všechny chyby → tichá degradace + log.
- Před `patch_file` na Svelte přečíst celý soubor; větší přepis `write_file`.
- Build/test verifikace po každém logickém kroku.

## Rozhodnutí ✓

- ✓ **D11** — samostatný **SSE endpoint** `/_ui/dashboard/summary`, neblokuje
  feed; cache hit vrací text bez streamu.
- ✓ **D12** — cache klíč = **hash(digest + jazyk)**; regenerace při změně feedu.
  **Realizace „aspoň denně": datum (`Y-m-d`) je součástí digestu/hashe** — bez
  samostatného TTL časovače.
- ✓ **D13** — vstupy: county dle kind + úkoly + top ~6 karet + datum; NE plný
  `extracted_json`.
- ✓ **D14** — prázdný feed → žádné LLM, statický text.
- ✓ **D15** — reuse default AI backendu (`AIBackendLookup`); model jeho default;
  levnější override odložen.
- ✓ **D16** — chyba/timeout → tichá degradace na statické county, feed neblokuje.
- ✓ Shrnutí je **per-DS + per-jazyk** (feed není per-user).
- ✓ Backend bez klíče = degradace (`text=null`), ne chyba.
- ✓ Usage (input/output tokens) se ukládá do cache řádku (telemetrie), reporting mimo.

## Otevřené body

- **`AIBackendLookup` API** — přesný název metody pro default backend + tvar VO
  (provider/model/apiKey/baseUrl). Ověřit při implementaci.
- **SSE reuse** — jestli `ChatController` streaming jde čistě extrahovat do
  sdíleného helperu, nebo se pattern zopakuje. Preferuj extrakci, když levná.
- **Umístění cache tabulky** — `modules/core/ai/tables/` vs jinam; dle konvence
  core AI tabulek.
- **Model vhodný na časté regenerace** — pokud default backend jede Opus,
  zvážit levnější model override (odloženo, D15).

## Hotovo když

- [ ] `vendor/bin/phpunit 2>&1` prochází (nový `DashboardSummaryServiceTest`)
- [ ] `cd frontend && npm run build 2>&1` bez chyb
- [ ] `npm run check:i18n` parita
- [ ] `ds-upgrade` vytvoří `core_ai_dashboard_summary`
- [ ] `GET /_ui/dashboard/summary` (SSE): miss streamuje + upsertne, hit vrátí
  okamžitě, prázdný feed `done{text:null}`
- [ ] Dashboard: feed hned, shrnutí dotéká; reload bez změny = okamžitě (hit)
- [ ] Změna feedu (apply/reject) → přegenerování při dalším reloadu
- [ ] Prázdný feed → statický text
- [ ] Odebraný AI klíč / LLM chyba → statické county, feed OK (tichá degradace)
- [ ] cs/en — shrnutí ve správném jazyce
- [ ] `docs/dashboard.md` §AI shrnutí hotové; `ai.md`, `architecture.md`,
  `CLAUDE.md` aktualizované

## Commit strategie

1. `feat(dashboard): AI summary cache table and service`
   — `core_ai_dashboard_summary.jsonc`, `DashboardSummaryService` (digest/hash/
   cache/prompt), unit testy s mock LlmClient.
2. `feat(dashboard): SSE summary endpoint`
   — `collectCards()` refaktor, `DashboardController::summary`, routa.
3. `feat(dashboard): stream AI summary into card`
   — `AiSummaryCard` stream, `api/dashboard.js`, i18n, degradace.
4. `docs(dashboard): document AI summary (phase 2b)`
   — `dashboard.md`, `ai.md`, `architecture.md`, `CLAUDE.md`.
