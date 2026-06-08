# Task: Chat — tool-use smyčka (Fáze 2b)

## Kontext

Na ověřený streamovaný chat (2a) sadíme **tool-use smyčku** — chat poprvé sáhne
na data přes čtecí MCP nástroje. Model může uprostřed odpovědi zavolat nástroj,
my ho spustíme in-process, výsledek vrátíme a model pokračuje, dokud nedá finální
odpověď.

Z designu uzavřeno:

- **D-C = A:** na `McpTool` přibyde `isReadOnly(): bool`; smyčka v1 nabízí modelu
  **jen čtecí nástroje** (`persons_search`, `persons_get`, `documents_search`,
  `mail_list_pending`). `mail_draft_document` (zápis) vrací `false` a do chatu
  v1 nejde — zápisové nástroje s potvrzováním jsou vlastní pozdější fáze.
- **Strop iterací = 8** (ochrana před zacyklením a útěkem nákladů).
- SSE: přibyde událost `tool-call` (`{name, arguments}`); výsledek nástroje se
  samostatně neposílá — navazující text modelu doteče jako `text-delta`.
- Tool wiring je **in-process** přes `McpToolRegistry` (ne přes `/_mcp` HTTP).

## Návaznost

- Staví na 2a (`ChatController::sendMessage` → `runStream`, `LlmChatParams`/
  `LlmChatResult`, `AnthropicLlmClient`, SSE helper `sse()`).
- Staví na MCP katalogu (Fáze 1–3 MCP serveru): `McpTool`, `McpToolRegistry`,
  `McpInvocationContext`, pět nástrojů.
- Registr nástrojů dnes staví `dispatchMcp` — vytáhneme do sdílené factory, ať
  ho `dispatchChat` neduplikuje.

## Před implementací přečti

- **`src/Api/Controller/ChatController.php`** — `sendMessage()` + `runStream()` se
  rozšíří na smyčku; helpery `sse()`, `insertMessage()` (umí bloky + tokeny),
  `nextSeq()`, `bumpConversation()`, `buildAnthropicMessages()` jsou hotové a
  použijí se.
- **`src/Core/Ai/AnthropicLlmClient.php`** — `dispatchEvent()`/`feedSse()` se
  rozšíří o `tool_use` bloky; `streamChat()` přidá `tools` do těla requestu.
- **`src/Core/Ai/LlmChatParams.php` / `LlmChatResult.php`** — přibyde `tools`
  (params) a `toolUses` + `contentBlocks` (result).
- **`src/Api/Mcp/McpTool.php`** — přibyde `isReadOnly()`; **všech pět nástrojů**
  ho doplní.
- **`src/Api/Mcp/McpInvocationContext.php`** — staví se v `ChatController` pro
  vykonání nástroje (`auth`, `db`, `tables`, `config`).
- **`public/index.php` `dispatchMcp` + `dispatchChat`** — registrace nástrojů →
  sdílená factory; `dispatchChat` musí dostat `$tables` (pro kontext).
- **`src/Api/Controller/McpController.php`** — mapování doménové obálky →
  `content`/`structuredContent`; tool_result do modelu použije stejný tvar
  (viz bod 5).
- **Aktuální Anthropic docs (tool use + streaming)** — `content_block_start`
  typu `tool_use`, `input_json_delta` (`partial_json`), `tool_result` blok;
  ověřit proti docs (znalost k 01/2026).

## Scope

**V rozsahu:** `isReadOnly()` na `McpTool` (+ 5 implementací); rozšíření klienta o
tool-use (request `tools` + parsování `tool_use` ze streamu); rozšíření
`LlmChatParams`/`LlmChatResult`; agentní smyčka v `ChatController` (iterace,
perzistence tahů, `tool-call` SSE, strop); sdílená factory registru; testy.

**Mimo rozsah:**

- Zápisové/akční nástroje v chatu (`mail_draft_document` a spol.) + potvrzovací
  UI — **pozdější fáze**.
- Multi-provider tool-use (jen Anthropic).
- Frontend.
- Výpočet `cost` (jen tokeny, jako v 2a).

## Co implementovat

### 1. `McpTool::isReadOnly()`

Přidat do rozhraní `public function isReadOnly(): bool;`. Implementace:
`persons_search`/`persons_get`/`documents_search`/`mail_list_pending` → `true`;
`mail_draft_document` → `false`.

### 2. Sdílená factory registru

Vytáhnout registraci z `dispatchMcp` do factory (např.
`McpToolRegistry::createDefault(DataSourceConnection $db, ?ConfigRuntime $config,
?ExtractedDocumentApplier $draftApplier): McpToolRegistry`) — vrací registr se
všemi pěti nástroji (draft jen když applier není null). `dispatchMcp` ji volá
beze změny chování; `dispatchChat` ji volá taky a pro smyčku filtruje
`isReadOnly()`.

### 3. `LlmChatParams` + `LlmChatResult`

- `LlmChatParams`: přidat `?array $tools = null` — pole Anthropic tool defs
  `{name, description, input_schema}`.
- `LlmChatResult`: přidat `array $toolUses` (`[{id, name, input(array)}]`) a
  `array $contentBlocks` (úplné bloky asistentova tahu v pořadí: `text` +
  `tool_use` — pro věrnou perzistenci a feed zpět modelu). `text` zůstává
  (konkatenovaný text pro `text-delta`/zobrazení).

### 4. `AnthropicLlmClient` — tool-use

- `streamChat()`: když `params->tools !== null`, přidat do těla `'tools' =>
  $params->tools` (případně `tool_choice` auto). Akumulátor rozšířit o pole
  bloků a rozpracované tool_use dle indexu.
- `dispatchEvent()`:
  - `content_block_start` s `content_block.type='tool_use'` → založ blok na jeho
    `index` (`id`, `name`, prázdný `partial_json`).
  - `content_block_delta` s `delta.type='input_json_delta'` → přilep
    `delta.partial_json` k bloku na `index`.
  - `content_block_start` typu `text` / `content_block_delta` `text_delta` →
    jako dosud (akumulace textu + `$onTextDelta`), ale **zaznamenat i do pořadí
    bloků**.
  - `content_block_stop` → finalizuj blok na `index` (u tool_use `json_decode`
    nasbíraného `partial_json` → `input`).
- Výstup: `LlmChatResult` s `contentBlocks` (text + tool_use v pořadí), `toolUses`
  (jen tool_use), `text`, usage, `stopReason`.

### 5. Agentní smyčka v `ChatController`

`runStream()` přejmenovat/rozšířit na smyčku (`runAgenticLoop`). Vstup: backend
params + registr nástrojů filtrovaný na `isReadOnly()` (tool defs do
`params->tools`) + `McpInvocationContext`.

```
seq pokračuje přes nextSeq(); messages = buildAnthropicMessages(conv)
for iteration in 1..8:
    result = llm->streamChat(params s aktuálními messages + tools, onTextDelta → sse 'text-delta')
    if result->toolUses prázdné:                         # finální odpověď
        insertMessage(assistant, kind='assistant', content=result->contentBlocks, tokeny)
        bumpConversation(cumulativní usage); sse 'message-complete'; return
    # model chce nástroje:
    insertMessage(assistant, kind='assistant', content=result->contentBlocks, tokeny)
    messages[] = {role:'assistant', content: result->contentBlocks}
    toolResultBlocks = []
    for tu in result->toolUses:
        sse 'tool-call' {name: tu.name, arguments: tu.input}
        tool = registry->get(tu.name)
        if tool == null || !tool->isReadOnly():           # ochrana: model si nesmí vynutit cizí/zápisový nástroj
            toolResultBlocks[] = {type:'tool_result', tool_use_id: tu.id, is_error:true, content:"Neznámý nebo nepovolený nástroj."}
            continue
        try:
            envelope = tool->call(tu.input, ctx)
            toolResultBlocks[] = {type:'tool_result', tool_use_id: tu.id, content: <obálka jako text/JSON, viz níže>}
        catch:
            toolResultBlocks[] = {type:'tool_result', tool_use_id: tu.id, is_error:true, content:"Nástroj selhal: …"}
    insertMessage(user, kind='tool_results', content=toolResultBlocks)
    messages[] = {role:'user', content: toolResultBlocks}
# strop:
insertMessage(assistant, kind='assistant', content=[{type:text, text:"Dosáhl jsem limitu kroků; zkus dotaz upřesnit."}])
sse 'message-complete' (s poznámkou o limitu)
```

- **`tool_result.content`**: vrátit modelu data z doménové obálky. Použij stejný
  tvar jako `McpController` (text summary + případně structured) — nejjednodušeji
  `json_encode(envelope)` jako text bloku, ať má model `items`/`refs`. Pokud je
  levné, vytáhni mapování obálky z `McpController` do sdíleného helperu a použij
  i tady.
- **Tokeny**: každý tah ukládá své `tokens_input/output` na svém řádku;
  `bumpConversation` agreguj **kumulativně** přes iterace; `message-complete`
  usage = součet.
- **Bezpečnostní invariant**: smyčka spouští **jen** nástroje, které jsou v
  registru a `isReadOnly()` — i kdyby model požádal o jiný název.

### 6. Wiring + kontext

- `dispatchChat`: předat `$tables` do `ChatController` (pro `McpInvocationContext`)
  a registr z factory; filtr read-only nech na controlleru.
- `ChatController` staví `McpInvocationContext($auth, $db, $tables, $config)` jednou
  a předává do každého `tool->call`.

### 7. Testy

- `isReadOnly()` na všech pěti nástrojích (čtyři true, draft false).
- **Klient**: fixture stream s `tool_use` blokem (`content_block_start` +
  `input_json_delta` + `stop`) → `LlmChatResult.toolUses` se správným
  `name`/`input` a `contentBlocks`; smíšený text+tool_use zachová pořadí.
- **Smyčka** (fake `LlmClient` s předskriptovanými tahy): tah s `tool_use` →
  spustí se čtecí nástroj, uloží se assistant (s tool_use bloky) + `tool_results`
  zpráva, `tool-call` event ve streamu, druhý tah → finální text +
  `message-complete`; vícenásobné nástroje v jednom tahu; nástroj hodí výjimku →
  `tool_result` `is_error` a smyčka pokračuje; model požádá o zápisový/neznámý
  nástroj → odmítnut přes `tool_result` `is_error`, nespustí se; strop 8 → graceful
  konec.
- Filtr: do `params->tools` jdou jen read-only nástroje (žádný `mail_draft_document`).

## Hotovo když

1. `McpTool` má `isReadOnly()`; chat nabízí modelu jen čtecí nástroje.
2. `AnthropicLlmClient` umí poslat `tools` a vyparsovat `tool_use` bloky ze
   streamu; `LlmChatResult` nese `toolUses` + `contentBlocks`.
3. `ChatController` běží agentní smyčku (≤ 8 iterací): tah s nástrojem → spuštění
   in-process → `tool_result` zpět modelu → pokračování → finální odpověď.
4. Asistentovy tahy (text + tool_use) i `tool_results` zprávy se perzistují jako
   bloky; SSE nese `text-delta` + `tool-call` + `message-complete`/`error`.
5. Smyčka spustí jen nástroje z registru s `isReadOnly()`; cizí/zápisový požadavek
   modelu je bezpečně odmítnut (`tool_result is_error`).
6. Registr nástrojů staví sdílená factory (`dispatchMcp` i `dispatchChat`).
7. Testy procházejí.

## Doporučené pořadí implementace

1. `isReadOnly()` na rozhraní + 5 nástrojů; sdílená factory registru (regrese
   `dispatchMcp` přes existující MCP testy).
2. `LlmChatParams.tools` + `LlmChatResult.toolUses/contentBlocks` + rozšíření
   `AnthropicLlmClient` (parsování tool_use) + testy klienta z fixture.
3. Smyčka v `ChatController` + `McpInvocationContext` wiring + `tool-call` SSE +
   testy se scriptovaným fake klientem.
4. Smoke proti reálnému Anthropic backendu na dev DS (dotaz, co si vynutí
   `persons_search`).

## Rozhodnutí k designu (potvrzená)

1. ✓ **D-C = A**: `isReadOnly()` na `McpTool`; chat v1 jen čtecí tier.
2. ✓ **Strop 8 iterací**; po vyčerpání graceful konec.
3. ✓ **`tool-call` SSE událost**; výsledek nástroje se samostatně nestreamuje
   (model pokračuje textem).
4. ✓ **Chyba nástroje → `tool_result is_error`**, model reaguje; ne tvrdý pád.
5. ✓ **Bezpečnostní invariant**: spouští se jen registrované read-only nástroje,
   i proti přání modelu.
6. ✓ **Sdílená factory registru** pro `dispatchMcp` i `dispatchChat`.
7. ✓ **In-process volání** (registr vedle), ne přes `/_mcp` HTTP.

## Otevřené body (k ověření, neblokující)

- **Anthropic tool-use streaming** — přesné tvary `content_block_start` tool_use,
  `input_json_delta`, `tool_result` ověřit proti aktuálním docs (znalost 01/2026).
- **`tool_result.content` tvar** — JSON obálky vs. summary+structured; potvrdit,
  co model nejlíp zpracuje; ideálně sdílet mapování s `McpController`.
- **Chování při stropu** — graceful zpráva vs. `error` event (zvoleno graceful).
- **`cost`** — stále jen tokeny; cena za 1k tokenů follow-up.
