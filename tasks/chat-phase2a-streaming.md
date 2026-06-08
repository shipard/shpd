# Task: Chat — streamovaný chat bez nástrojů (Fáze 2a)

## Kontext

První „živá" fáze chatu: uživatel napíše zprávu, model odpoví a tokeny se
**streamují** do UI. Žádné nástroje — model jen mluví. Cílem je ověřit
streamování end-to-end na minimu, než na něj 2b posadí tool-use smyčku.

Z designu uzavřeno:

- Orchestrátor v PHP, in-process, jeden tah = jeden SSE request (varianta A).
- **D-A:** LLM klient v1 = **Anthropic-only za tenkým rozhraním `LlmClient`**;
  ostatní provideři později.
- **D-B:** minimální streamovaná cesta. **Příjemné:** `Response::stream(callable
  $producer, status, contentType)` už existuje (Fáze 1) — `send()` pro
  `bodyType='stream'` vyprázdní buffery, zapne `ob_implicit_flush(true)` a zavolá
  producenta; hlavičky `X-Accel-Buffering: no` + `Cache-Control: no-cache` jsou
  nastavené. SSE infra tedy **znovupoužíváme**, nestavíme.

**Net-new v této fázi:** (1) `LlmClient` rozhraní + `AnthropicLlmClient`
(streamovaný Messages API s tool-use vynecháme do 2b) — první PHP-side LLM klient
vůbec (analyzér je Python); (2) endpoint `sendMessage` se smyčkou nad
`Response::stream`.

## Návaznost

- Staví na Fázi 1: `ChatController` (db + nullable `ConfigRuntime`), tabulky
  `core_chat_conversations`/`core_chat_messages`, `Response::stream`.
- Backend čte z `core/ai` (`core_ai_backends` + `AIBackendDocument::decryptApiKey`).
- **2b** přidá na `sendMessage` tool-use: definice nástrojů z `McpToolRegistry`
  (jen read-tier), události `tool-call`, strop iterací. Tady ne.

## Před implementací přečti

- **`src/Api/Controller/ChatController.php`** — vzor metod (db helpery
  `fetchAll`/`fetchRow`/`insertRow`/`updateWhere`, `requireUser`,
  `loadOwnedConversation`, `mainState`, `formatMessage`). Sem přibyde
  `sendMessage`.
- **`src/Api/Response.php`** — `Response::stream(...)` (použít) + tvar `send()`
  pro `bodyType='stream'`.
- **`modules/core/ai/src/AIBackendDocument.php`** — `decryptApiKey($row)` +
  `setSecretCipher()`; pole `provider`/`model`/`base_url`/`api_key`/`max_tokens`/
  `temperature`/`is_default`/`is_active`.
- **`src/Api/Controller/AnalysisController.php`** — jak se staví `DsSecretCipher`
  (`forConfig`) a jak se načte backend; replikovat (NE volat claim cestu).
- **`modules/core/chat/module.jsonc`** — sem přidat chat config (systémový prompt)
  jako cfgItem; **`public/index.php` `dispatchChat`** — sem injektovat
  `AnthropicLlmClient`.
- **Aktuální Anthropic Messages API docs** (streaming) — ověřit `anthropic-version`
  hlavičku a přesné SSE eventy (`message_start`/`content_block_delta`
  `text_delta`/`message_delta` usage/`message_stop`); moje znalost je k 01/2026.

## Scope

**V rozsahu:** `LlmClient` rozhraní + `AnthropicLlmClient` (streamovaný, bez
tool-use); `ChatController::sendMessage` + routa `POST /_chat/conversations/{id}/
messages` přes `Response::stream`; perzistence zprávy uživatele i asistenta;
agregace tokenů/nákladů na konverzaci; systémový prompt z chat configu; testy s
fake klientem.

**Mimo rozsah:**

- Nástroje / tool-use smyčka / `tool-call` události / strop iterací — **2b**.
- Multi-provider klient (jen Anthropic v1).
- Výpočet `cost` nad rámec uložení tokenů (cena za 1k → později; v1 `cost`
  best-effort nebo 0).
- Frontend (Svelte).
- Pokročilé cancel/disconnect chování (základní error event ano).

## Co implementovat

### 1. `LlmClient` rozhraní + `AnthropicLlmClient`

`src/Core/Ai/LlmClient.php` (a `AnthropicLlmClient.php`), případně
`modules/core/ai/src/`. Rozhraní záměrně minimální:

```php
interface LlmClient
{
    /**
     * Streamuje odpověď modelu. Pro každý textový delta zavolá $onTextDelta.
     * @param array<int,array{role:string,content:mixed}> $messages
     */
    public function streamChat(
        LlmChatParams $params,        // model, apiKey, baseUrl, system, messages, maxTokens, temperature
        callable $onTextDelta,        // fn(string $text): void
    ): LlmChatResult;                 // { text, inputTokens, outputTokens, stopReason, model }
}
```

`AnthropicLlmClient::streamChat`:
- `POST {baseUrl}/v1/messages` (default `https://api.anthropic.com`), hlavičky
  `x-api-key`, `anthropic-version`, `content-type: application/json`.
- Tělo `{model, max_tokens, system, messages, temperature, stream: true}`.
- Stream čti inkrementálně (curl `CURLOPT_WRITEFUNCTION`, nebo `fopen`+`fgets`);
  parsuj SSE: na `content_block_delta` s `delta.type=text_delta` zavolej
  `$onTextDelta($delta.text)`; z `message_delta`/`message_start` posbírej
  `usage` (input/output tokens) a `stop_reason`.
- Vrať `LlmChatResult` s plným textem + usage.
- v1 podporuje jen `provider='anthropic'`; jiný → výjimka `LlmUnsupportedProvider`
  (controller ji namapuje na SSE `error`).

### 2. `ChatController::sendMessage` + routa

Routa: `POST /_chat/conversations/{id}/messages` → `Route('chat','sendMessage',
null, $id)` (přidat blok do `Router` k ostatním `/_chat/conversations/{id}`
metodám). Controller dostane `?LlmClient` konstruktorem (nullable; `dispatchChat`
injektuje `new AnthropicLlmClient()`).

`sendMessage(AuthContext $auth, int $id, Request $request): Response`:

1. Auth + `loadOwnedConversation` (cizí/smazaná → 404 jako JSON, **před** streamem).
2. Pokud `$this->llm === null` → 503 JSON „chat LLM not configured".
3. Z body vezmi text uživatele; prázdný → 422.
4. **Persistuj zprávu uživatele hned** (před voláním LLM): `role='user'`,
   `kind='user_text'`, `seq = nextSeq($id)`, `content = [{type:'text', text}]`
   (JSON). Tím přežije i selhání modelu.
5. Načti backend: `conversation.backend` pokud je, jinak default aktivní
   (`is_default=1 AND is_active=1`) z `core_ai_backends`. Dešifruj klíč:
   `DsSecretCipher::forConfig(...)` → `AIBackendDocument::setSecretCipher` →
   `decryptApiKey($row)` (replikovat z `AnalysisController`).
6. Posklad `messages` z historie konverzace (`role` + dekódované `content` bloky,
   `ORDER BY seq`). Systémový prompt z chat configu (bod 4 níže).
7. Vrať `Response::stream(...)` s `Content-Type: text/event-stream`; producent:
   - akumuluj text; pro každý delta z `streamChat` emituj SSE
     `event: text-delta\ndata: {"text":"…"}`.
   - po dokončení: **persistuj zprávu asistenta** (`role='assistant'`,
     `kind='assistant'`, `content=[{type:'text', text}]`, `tokens_input/output`,
     `model_name`), aktualizuj agregáty konverzace (`tokens_*`, `cost`,
     `model_snapshot`, `modified`), emituj `event: message-complete\ndata:
     {"message_id":…, "usage":{…}}`.
   - při výjimce kdekoli: emituj `event: error\ndata: {"code":…,"message":…}` a
     ulož, co je (zpráva uživatele už uložená z kroku 4).

### 3. SSE kontrakt (2a)

```
event: text-delta
data: {"text":"částečný text"}

event: message-complete
data: {"message_id": 123, "usage": {"input_tokens": …, "output_tokens": …}, "model": "…"}

event: error
data: {"code":"LLM_ERROR","message":"…"}
```

(`tool-call` přibyde v 2b.) Helper na zápis eventu (`event:`/`data:`/prázdný
řádek + flush) v controlleru.

### 4. Systémový prompt (chat config)

Do `modules/core/chat/module.jsonc` přidat cfgItem `core.chat.settings`
(nebo podobně) se `systemPrompt` (český účetní asistent Shipardu) a rozumným
defaultem; controller ho načte přes `ConfigRuntime`. Zatím statický; per-DS
ladění později.

### 5. Testy

- **`AnthropicLlmClient`**: parsování SSE streamu (fixture s `text_delta` +
  `message_delta` usage) → správný text + tokeny; neznámý provider → výjimka.
  (Bez reálného síťového volání — mock transportu / fixture.)
- **`ChatController::sendMessage`** s **fake `LlmClient`** (emituje pár delt):
  uloží se zpráva uživatele i asistenta (správné `role`/`kind`/`content` bloky,
  `seq`), agregáty konverzace narostou; SSE výstup obsahuje `text-delta` +
  `message-complete`; výjimka klienta → `error` event a zpráva uživatele zůstane
  uložená; cizí konverzace → 404; bez `LlmClient` → 503; neauthnutý → 401.

## Hotovo když

1. Existuje `LlmClient` + `AnthropicLlmClient` (streamovaný, Anthropic-only).
2. `POST /_chat/conversations/{id}/messages` streamuje přes `Response::stream`
   SSE `text-delta` → `message-complete`; user-scoped, authnuté.
3. Zpráva uživatele se ukládá před voláním modelu; zpráva asistenta po dokončení;
   `content` jsou bloky, `role`/`kind` stringy; agregáty konverzace narostou.
4. Backend + klíč se berou z `core_ai_backends` (dešifrování přes `DsSecretCipher`).
5. Chyba modelu → SSE `error`, žádný ztracený stav.
6. Testy (klient + controller s fake LLM) procházejí.

## Doporučené pořadí implementace

1. `LlmClient` + `AnthropicLlmClient` + testy parsování (fixture, bez sítě).
2. `sendMessage` se streamem + perzistence + agregáty + routa + `dispatchChat`
   injekce.
3. Chat config (systémový prompt) + testy controlleru s fake klientem.
4. Ruční smoke proti reálnému Anthropic backendu na dev DS.

## Rozhodnutí k designu (potvrzená)

1. ✓ **Anthropic-only za rozhraním `LlmClient`** (D-A) — provider-agnostické
   dveře otevřené, stream formát se neabstrahuje předčasně.
2. ✓ **Reuse `Response::stream`** (D-B) — SSE mechanismus už existuje z Fáze 1.
3. ✓ **Bez nástrojů** — tool-use je 2b.
4. ✓ **Zpráva uživatele se persistuje PŘED voláním modelu** — odolnost vůči
   selhání.
5. ✓ **Backend = `conversation.backend`, jinak default aktivní** z `core_ai_backends`.
6. ✓ **Systémový prompt v chat configu** (`module.jsonc` cfgItem), ne v kódu.
7. ✓ **`LlmClient` injektovaný konstruktorem** (nullable) — testovatelnost přes
   fake.

## Otevřené body (k ověření, neblokující)

- **Anthropic streaming detaily** — `anthropic-version` hlavička + přesné názvy/
  tvary SSE eventů ověřit proti aktuálním docs (znalost k 01/2026).
- **Výpočet `cost`** — v1 ukládáme tokeny; cena za 1k tokenů per model je
  follow-up.
- **FPM/proxy timeouty** pro dlouhý stream — provozní nastavení (zdokumentovat,
  ne kód).
- **Disconnect klienta uprostřed streamu** — základní ošetření (detekce přerušení,
  uložení částečného stavu); plné cancel chování případně později.
