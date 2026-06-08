# Task: Chat — perzistenční skelet (Fáze 1)

## Kontext

První fáze vnitřního chatu (orchestrátoru). Z designové diskuze je uzavřeno:

- Orchestrátor běží **v PHP, in-process**, jeden tah = jeden streamovaný (SSE)
  request (varianta A). To je ale **Fáze 2** — tady ještě žádný LLM.
- Konverzace se **perzistují** (D1) v nových tabulkách per uživatel + DS.
- Tvar tabulek je uzavřený (D4): `core_chat_conversations` +
  `core_chat_messages`, `content` jako pole bloků ve formátu Anthropic
  (`text`/`tool_use`/`tool_result`), `role` (`user`/`assistant`) + `kind`
  (display hint).

Tato fáze postaví **jen perzistenci a CRUD konverzací** — modul `core/chat`, obě
tabulky, controller pro správu konverzací. LLM smyčka, nástroje a streamování
přijdou ve Fázi 2. Prerekvizita `core/ai` (sdílené backendy) je hotová.

## Návaznost

- Staví na `core/ai` (backend FK konverzace míří na `core_ai_backends`).
- Fáze 2 přidá na **stejný** `ChatController` endpoint
  `POST /_chat/conversations/{id}/messages` (SSE smyčka) a zápis zpráv; proto už
  teď dedikovaný controller, ne generický CRUD (viz Rozhodnutí #1).

## Před implementací přečti

- **`src/Api/Controller/AttachmentController.php`** (nebo `AnalysisController`) —
  vzor dedikovaného controlleru: signatura metod `(AuthContext $auth, Request
  $request, ...)`, čtení body, vynucení auth, mapování na `Response`.
- **`src/Api/Router.php`** — bloky `/_*` (vzor `/_attachments/*`), kam přidat
  `/_chat/conversations…`; **public/index.php** `dispatch()` + `dispatchXxx`
  helper.
- **`modules/core/units/module.jsonc`** + **`modules/core/mail/module.jsonc`** —
  vzor `module.jsonc` (id, dependencies, tables, viewers, settingsItems).
- **`modules/core/ai/tables/core_ai_backends.jsonc`** — cíl `backend` FK.
- **Tabulka systémových uživatelů** (`core_system_users` — ověřit jméno) — cíl
  `user`/`created_by` FK; mrkni, jak na ni FK-uje např. `core_system_api_keys`.
- **`modules/docs/core/config/docStates.jsonc`** — `docState` (10 Koncept … 90
  Smazáno) pro soft-delete konverzace.
- **`next-table-id --range`** (CLI) — alokace tableId rozsahu pro `core/chat`.
- Libovolná stávající tabulková `.jsonc` ve `core/mail` jako vzor struktury
  (sloupce, indexy, `tableId`, `docState`/`docStateMain`, `created`/`modified`).

## Scope

**V rozsahu:** modul `core/chat`; tabulky `core_chat_conversations` a
`core_chat_messages`; `ChatController` s CRUD konverzací pod `/_chat/conversations`;
routa + dispatch + registrace v `module.jsonc`; viewer konverzací; testy.

**Mimo rozsah:**

- LLM / SSE smyčka / volání nástrojů / streamování — **Fáze 2**.
- Zápis zpráv přes API — zprávy vznikají ve smyčce (Fáze 2), **ne** přes CRUD.
  Žádný veřejný zápisový endpoint na `core_chat_messages` (integrita bloků).
- Frontend (Svelte chat UI).
- Logika nákladů/limitů — jen sloupce pro telemetrii, žádné vyhodnocování.
- Auto-odvození `title` z první zprávy — až Fáze 2 (teď `title` volitelný/null).

## Co implementovat

### 1. Modul `core/chat`

`modules/core/chat/module.jsonc`:

```jsonc
{
  "id": "core.chat",
  "name:cs": "Chat", "name:en": "Chat",
  "description": "In-app AI assistant: persisted conversations and messages",
  "dependencies": ["core.system", "core.ai"],
  "tables": ["core_chat_conversations", "core_chat_messages"],
  "viewers": [ { "id": "core.chat.conversations", "table": "core_chat_conversations", "class": "Shipard\\Module\\Core\\Chat\\ConversationsViewer" } ]
}
```

TableId rozsah přidělit přes `next-table-id --range` (vlastní rozsah pro
`core/chat`).

### 2. Tabulka `core_chat_conversations`

`modules/core/chat/tables/core_chat_conversations.jsonc`. Sloupce:

- `user` (FK → systémoví uživatelé) — vlastník.
- `title` (string, nullable) — z první zprávy (plní Fáze 2).
- `backend` (FK → `core_ai_backends`, nullable) — zvolený LLM backend.
- `model_snapshot` (string, nullable) — model v době konverzace (audit).
- `tokens_input`, `tokens_output` (int, default 0) — agregát.
- `cost` (decimal, default 0) — agregát nákladů.
- `created`, `created_by`, `modified` + `docState`/`docStateMain` (soft-delete /
  archiv).

Indexy: `idx_user`, `idx_doc_state`.

### 3. Tabulka `core_chat_messages`

`modules/core/chat/tables/core_chat_messages.jsonc`. Sloupce:

- `conversation` (FK → `core_chat_conversations`).
- `seq` (int) — pořadí v konverzaci.
- `role` (enum/small int: `user` / `assistant`).
- `kind` (enum/small int: `user_text` / `tool_results` / `assistant`) — display
  hint, ať UI odliší skutečnou zprávu uživatele od syntetické zprávy s
  `tool_result` bloky.
- `content` (longtext) — **JSON pole bloků** ve formátu Anthropic
  (`text`/`tool_use`/`tool_result`). NE čistý text.
- `tokens_input`, `tokens_output`, `cost` (per asistentský tah), `model_name`.
- `created`, `created_by`.

Indexy: `idx_conversation`, `idx_conversation_seq` (`conversation`,`seq`),
`idx_user` (pokud denormalizujeme vlastníka pro scoping — jinak přes JOIN).

### 4. `ChatController` + routy `/_chat/conversations`

`src/Api/Controller/ChatController.php`. Vše **vyžaduje auth** a je
**user-scoped** (uživatel vidí jen vlastní konverzace — filtr `user =
$auth->userId`):

- `GET /_chat/conversations` — seznam konverzací uživatele (bez smazaných,
  `docState != 90`), řazeno `modified DESC`. Stránkování (limit/offset).
- `POST /_chat/conversations` — založí prázdnou konverzaci; volitelně `title`,
  `backend`. Vrátí `id`.
- `GET /_chat/conversations/{id}` — detail konverzace + její zprávy
  (`ORDER BY seq ASC`). Ověřit vlastnictví (cizí → 404).
- `PATCH /_chat/conversations/{id}` — přejmenování (`title`).
- `DELETE /_chat/conversations/{id}` — soft-delete (`docState=90`), ne fyzické
  smazání.

Žádný endpoint na zápis/úpravu `core_chat_messages` (viz Scope).

Routa: blok `/_chat/conversations` (+ `/{id}`) v `Router`, `controller='chat'`;
v `dispatch()` přidat `dispatchChat(...)` (mirror `dispatchAttachment`),
předat `$auth`, `$request`, `$db`.

### 5. Viewer

`ConversationsViewer` (`modules/core/chat/src/`) — read-only přehled konverzací
pro admin/nastavení (mirror jiného jednoduchého vieweru). Stačí základ.

### 6. Testy

- `POST` založí konverzaci (vlastník = přihlášený uživatel); `GET` seznam vrací
  jen vlastní a ne smazané; `GET {id}` cizí konverzace → 404; `PATCH` přejmenuje;
  `DELETE` nastaví `docState=90` a konverzace zmizí ze seznamu.
- Neauthnutý request → 401.
- (Zprávy: jen ověřit schéma tabulky `migrate`/`ds-upgrade`; vznik zpráv testuje
  Fáze 2.)

## Hotovo když

1. Modul `core/chat` existuje, `ds-upgrade` založí `core_chat_conversations` a
   `core_chat_messages`; `ds-create` nového DS je založí rovnou.
2. `ChatController` umí CRUD konverzací pod `/_chat/conversations`, vše authnuté a
   user-scoped; smazání je soft (`docState=90`).
3. Na `core_chat_messages` není žádný veřejný zápisový endpoint.
4. Schéma zpráv odpovídá D4 (`content` = JSON bloky, `role`+`kind`, telemetrie).
5. Testy procházejí.

## Doporučené pořadí implementace

1. Modul + obě tabulky → `ds-upgrade` na dev DS, ověřit schéma.
2. `ChatController` + routy + dispatch + user-scoping.
3. Viewer + testy.

## Rozhodnutí k designu (potvrzená)

1. ✓ **Dedikovaný `ChatController` pod `/_chat/conversations`**, ne generický
   CRUD — Fáze 2 sem přidá SSE `…/{id}/messages`; chat endpointy drží pohromadě.
2. ✓ **Zprávy nejsou přístupné přes CRUD** — vznikají jen ve smyčce (Fáze 2),
   integrita bloků.
3. ✓ **Konverzace user-scoped**, soft-delete přes `docState=90`.
4. ✓ **`content` jako JSON bloky + `kind` hint** (z D4) — věrné formátu Anthropic
   kvůli kontextu tool_use/tool_result.
5. ✓ **Závislosti `core.system` + `core.ai`.**

## Otevřené body (k ověření, neblokující)

- **tableId rozsah** — přidělit přes `next-table-id --range`.
- **Přesné jméno tabulky uživatelů** (`core_system_users`?) pro FK `user`/`created_by`.
- **Stránkování zpráv** v `GET {id}` — pro v1 vrátit všechny (řazené `seq`);
  pokud konverzace narostou, doplnit limit později.
- **Typ `role`/`kind`** (enum vs small int + konstanty) — sjednotit se stávající
  konvencí stavových/enum sloupců v projektu.
