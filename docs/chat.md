# Shipard — Chat (vnitřní AI asistent)

Vestavěný asistent, se kterým uživatel diskutuje nad svými daty. Orchestrátor
běží **v PHP, in-process**; jeden tah konverzace = jeden streamovaný (SSE)
požadavek, uvnitř kterého běží smyčka LLM ↔ nástroje. Konzumuje čtecí MCP
nástroje. Přehled subsystému: [`ai.md`](ai.md).

- **Backend:** `src/Api/Controller/ChatController.php` + modul `core/chat`
- **LLM klient:** `src/Core/Ai/` (`LlmClient`, `AnthropicLlmClient`)
- **Frontend:** `frontend/src/components/chat/` + `frontend/src/api/chat.js`

---

## 1. Endpointy

| Metoda | Cesta | Účel |
|--------|-------|------|
| GET | `/_chat/conversations` | Seznam konverzací uživatele |
| POST | `/_chat/conversations` | Založí prázdnou konverzaci |
| GET | `/_chat/conversations/{id}` | Detail + zprávy (`seq` ASC) |
| PATCH | `/_chat/conversations/{id}` | Přejmenování (`title`) |
| DELETE | `/_chat/conversations/{id}` | Soft-delete (`docState=90`) |
| POST | `/_chat/conversations/{id}/messages` | **Streamovaný tah** (SSE) |

Vše vyžaduje auth a je **user-scoped** (cizí konverzace → 404). Zprávy nejsou
zapisovatelné přes CRUD — vznikají jen ve smyčce (integrita bloků).

---

## 2. Orchestrační smyčka (`runAgenticLoop`)

`POST …/messages` uloží zprávu uživatele a vrátí `Response::stream(...)`, uvnitř
kterého běží smyčka (≤ `MAX_TOOL_ITERATIONS` = 8):

1. **Zpráva uživatele se persistuje hned** (před voláním modelu) — odolnost vůči
   selhání.
2. Sestav `messages` z historie + nabídni modelu **jen čtecí nástroje**
   (`isReadOnly()`), zavolej `LlmClient::streamChat()`; textové delty průběžně
   posílej jako SSE `text-delta`.
3. Vrátí-li model `tool_use`:
   - persistuj asistentův tah (bloky `text` + `tool_use`),
   - pro každý nástroj: SSE `tool-call`, spusť **in-process**
     (`registry->get(name)->call(args, ctx)`), výsledek zabal do `tool_result`,
   - persistuj syntetickou zprávu `role=user`/`kind=tool_results`, vrať modelu,
     opakuj.
4. Bez `tool_use` → finální odpověď: persistuj, agreguj telemetrii, SSE
   `message-complete`.
5. Strop iterací → graceful konec se zprávou, ne chyba.

**Bezpečnostní invariant:** smyčka spustí jen nástroj, který je v registru a
`isReadOnly()` — i kdyby si model vyžádal jiný či zápisový (vrátí se
`tool_result` s `is_error`, nespustí se). Vykonávací chyba nástroje → rovněž
`tool_result is_error`, model reaguje; ne tvrdý pád.

---

## 3. SSE kontrakt

```
event: text-delta
data: {"text":"částečný text"}

event: tool-call
data: {"name":"persons_search","arguments":{...}}

event: message-complete
data: {"message_id":123,"usage":{"input_tokens":…,"output_tokens":…},"model":"…"}

event: error
data: {"code":"LLM_ERROR","message":"…"}
```

Výsledek nástroje se samostatně nestreamuje — navazující text modelu doteče jako
`text-delta`. `message-complete` může nést `note: "iteration_limit"` při dosažení
stropu.

---

## 4. LLM klient

`LlmClient` je rozhraní; `AnthropicLlmClient` jediná v1 implementace (Anthropic
Messages API, streamovaně). `streamChat(LlmChatParams, $onTextDelta)`:

- tělo `{model, max_tokens, system, messages, stream:true}` + `tools` (když jsou);
- parsuje provider SSE: `text_delta` → `$onTextDelta`; `tool_use` bloky
  (`content_block_start` + `input_json_delta`) → poskládá `input`;
- vrací `LlmChatResult` s `text`, `contentBlocks` (celé bloky tahu v pořadí —
  pro věrnou perzistenci a feed zpět), `toolUses`, usage, `stopReason`.

`temperature` se v1 vynechává (novější modely ho odmítají). Provider scope:
jen `anthropic`; rozhraní drží dveře pro další.

---

## 5. Backend, klíč a systémový prompt

- **Výběr backendu** (`resolveBackend`): `conversation.backend`, jinak default
  aktivní z `core_ai_backends`. Klíč dešifruje `DsSecretCipher` (stejně jako
  analýza pošty). Viz [`ai.md`](ai.md) §5.
- **Systémový prompt** (`systemPrompt`): základ z cfgItem `core.chat.settings`
  (`module.jsonc`), s built-in fallbackem. K němu se **při každém požadavku
  přilepí aktuální datum** + instrukce „neodhaduj podle tréninkových dat, ověř
  nástrojem; pokud nemáš nástroj, řekni to". Bez data model pokládá současný rok
  za budoucnost.

---

## 6. Datový model

Konverzace a zprávy — per uživatel + DS, soft-delete přes `docState`.

- [`core_chat_conversations`](../modules/core/chat/tables/core_chat_conversations.md)
  — vlastník, název, backend, agregát telemetrie.
- [`core_chat_messages`](../modules/core/chat/tables/core_chat_messages.md)
  — `content` jako **JSON pole bloků Anthropic** (`text`/`tool_use`/`tool_result`),
  `role` (`user`/`assistant`) + `kind` (`user_text`/`assistant`/`tool_results`).

`tool_result` bloky žijí ve zprávě s `role=user` (formát Anthropic) — proto
`kind`, aby UI odlišilo skutečnou zprávu uživatele od syntetické s výsledky
nástrojů.

---

## 7. Frontend

- **`api/chat.js`** — CRUD přes `client.js`; streamovaný tah ale **nelze přes
  `EventSource`** (neumí POST ani Bearer hlavičku), takže `sendMessageStream`
  jede `fetch()` + `response.body.getReader()` a ručně parsuje SSE rámce.
  Parser je čistá funkce `parseSseFrames` (testovatelná, řeší neúplné rámce
  přes hranici chunku).
- **Chat store** (runes) — sdílený stav seznam ↔ vlákno; optimistická zpráva
  uživatele, akumulace `text-delta`, čipy nástrojů.
- **Komponenty** (`components/chat/`) — `ChatView`/`ConversationList`/
  `ChatThread`/`ChatInput`/`MessageBubble`/`ToolCallChip`/`Markdown`. `tool-call`
  se zobrazuje jako čip s lidským popiskem (`toolLabels.js`).
- **`Markdown`** renderuje **bezpečnou podmnožinu** markdownu do Svelte elementů
  — **žádné `{@html}` ze syrového výstupu modelu** (text se escapuje, emitují se
  jen kontrolované tagy). Nula XSS plochy, nula nové závislosti.
- **Backend:** v1 výchozí (žádný výběr modelu v UI). **401 uprostřed streamu**
  (vypršení tokenu) → srozumitelná chyba; plný refresh-retry je odložený.

---

## 8. Bezpečnost a hranice v1

- Chat nabízí modelu **jen čtecí tier** + bezpečnostní invariant (§2).
- Zápisové nástroje (`mail_draft_document`) chat zatím **nenabízí** — přijdou
  jako vlastní fáze se zastavením a potvrzením u zápisu.
- Žádné `{@html}` z modelu (§7).

---

## 9. Související dokumenty

- [`ai.md`](ai.md) — přehled subsystému
- [`mcp-server.md`](mcp-server.md) — nástroje, které chat konzumuje
- [`frontend.md`](frontend.md) — frontend architektura (Svelte, API komunikace)
- [`operations/secrets.md`](operations/secrets.md) — šifrování klíče backendu
