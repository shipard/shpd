# Task: Chat — Svelte UI (Fáze 3)

## Kontext

Backend chatu je hotový (perzistence + streamovaná tool-use smyčka). Tato fáze
přidává **uživatelské rozhraní** — vlastní pohled „Chat" ve frontendu, který
konzumuje SSE endpoint a dává uživateli konečně to, co celá integrace slibovala:
chat nad svými daty.

Z designu uzavřeno:

- **D-1 = A:** vlastní pohled „Chat" (postranní seznam konverzací + aktivní
  vlákno), zapojený do `AppShell`/`ContentArea` jako další navigační položka. Ne
  globální vysouvací panel (ten je pozdější vize „asistent všude").
- **D-2 = B:** text asistenta se renderuje jako **minimální bezpečná podmnožina
  markdownu** (odstavce, tučné, kurzíva, inline kód, kódový blok, odrážkové a
  číslované seznamy) do Svelte elementů — **bez nové závislosti a bez `innerHTML`
  z výstupu modelu** (žádná XSS plocha).
- **D-3:** událost `tool-call` se zobrazí jako kompaktní „čip" s lidským
  popiskem („🔍 Hledám osoby…"), ne syrový JSON.
- **SSE v prohlížeči:** `EventSource` neumí POST ani hlavičky → konzumace přes
  `fetch()` + `response.body.getReader()` a ruční parsování SSE rámců.

## Návaznost

- Konzumuje backend: CRUD `/_chat/conversations` (Fáze 1) + streamovaný
  `POST /_chat/conversations/{id}/messages` (Fáze 2a/2b).
- Frontend konvence: Svelte 5 runes, `api/client.js` (bearer token z
  `localStorage 'shpd_token'`), `t()`/`tn()` z `../i18n`, CSS proměnné
  `--shpd-color-*`, navigace přes `navigationStore` + `Sidebar` + `ContentArea`.

## Před implementací přečti

- **`frontend/src/api/client.js`** — bufferovaný fetch wrapper + token (`shpd_token`)
  + 401-refresh; CRUD chatu půjde přes `get/post/patch/del`. Streamovaný endpoint
  ale potřebuje vlastní cestu (viz bod 2).
- **`frontend/src/components/layout/AppShell.svelte` + `Sidebar.svelte` +
  `ContentArea.svelte` + `stores/navigation.svelte.js`** — jak se registruje
  navigační položka a jak `ContentArea` přepíná pohled dle `activeItem`. Sem
  zapojit „Chat".
- **`frontend/src/api/attachments.js`** — vzor api klienta, který volá `fetch`
  napřímo s bearer hlavičkou (pro nestandardní případy mimo `client.js`).
- **`frontend/src/stores/auth.svelte.js`** — token pro streamovaný fetch.
- **Jedna komponenta z `components/ui/` a `components/viewer/`** — vzor Svelte 5
  komponenty (`$props`, `$state`, `$effect`), stylu (scoped `<style>` + CSS
  proměnné) a i18n (`t('...')`). Znovupoužij existující primitivy (tlačítko,
  potvrzovací dialog) místo nových.
- **`frontend/src/styles/variables.css`** — paleta `--shpd-color-*` (accent, bg,
  bg-sidebar, border, primary, danger, focus-ring…).
- **`frontend/src/icons.js`** — registrace fontawesome ikon (pro ikonu chatu +
  čipy nástrojů).

## Scope

**V rozsahu:** navigační položka + pohled „Chat"; seznam konverzací (založit/
přejmenovat/smazat/přepnout); vlákno se streamovanou odpovědí, čipy nástrojů a
markdown-subset renderem; `api/chat.js` (CRUD + streamovaný `sendMessageStream`);
chat store (runes); i18n klíče; unit testy SSE parseru + markdown renderu.

**Mimo rozsah:**

- Potvrzování zápisových nástrojů — backend je chatu nenabízí (jen čtecí tier).
- Výběr LLM backendu/modelu v UI — v1 výchozí backend.
- Plný markdown / knihovna / raw HTML.
- Globální vysouvací asistent (D-1 B).
- Vyhledávání v konverzacích / pokročilé stránkování.

## Co implementovat

### 1. `api/chat.js` — CRUD

Přes `client.js` (`get/post/patch/del`):

```js
listConversations()                         // GET /_chat/conversations
createConversation(title = null)            // POST /_chat/conversations
getConversation(id)                         // GET /_chat/conversations/{id}  → {conversation, messages}
renameConversation(id, title)               // PATCH
deleteConversation(id)                      // DELETE
```

### 2. `api/chat.js` — streamovaný `sendMessageStream`

`EventSource` nelze (POST + bearer). Vlastní cesta:

```js
export async function sendMessageStream(conversationId, text, handlers) {
  // handlers: { onTextDelta(text), onToolCall({name, arguments}), onComplete(payload), onError(err) }
  const token = localStorage.getItem('shpd_token');
  const res = await fetch(`${API_BASE_URL}/_chat/conversations/${conversationId}/messages`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'Accept-Language': language.current },
    body: JSON.stringify({ text }),
  });
  if (!res.ok || !res.body) { handlers.onError(...); return; }
  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buf = '';
  // čti chunky → buf; rozděl na rámce přes "\n\n"; každý rámec má `event:` a `data:` řádky;
  // parsuj data JSON; dispatch dle event: text-delta | tool-call | message-complete | error.
  // ošetři neúplný rámec přes hranici chunku (drž v buf).
}
```

Parser SSE rámců vyčlenit do **čisté funkce** (`parseSseFrames(buffer) → {frames, rest}`)
kvůli testovatelnosti (bod 7). 401 uprostřed → `onError` se srozumitelným
„relace vypršela" (plný refresh-retry uprostřed streamu je mimo rozsah).

### 3. Chat store (`stores/chat.svelte.js`, runes)

Sdílený stav mezi seznamem a vláknem: `conversations[]`, `activeId`,
`messages[]` (aktivní konverzace), `streaming` (bool), `streamingText`
(akumulovaný text běžící odpovědi), `streamingTools[]` (čipy běžícího tahu).
Akce: `loadConversations`, `openConversation(id)`, `newConversation`,
`send(text)` (optimisticky přidá uživatelovu zprávu, zavolá `sendMessageStream`,
průběžně plní `streamingText`/`streamingTools`, na `onComplete` finalizuje), 
`rename`, `remove`.

### 4. Komponenty (`components/chat/`)

- **`ChatView.svelte`** — layout pohledu: vlevo `ConversationList`, vpravo
  `ChatThread` (na mobilu přepínání seznam ↔ vlákno).
- **`ConversationList.svelte`** — seznam (řazeno dle `modified`), tlačítko „Nová
  konverzace", přejmenování (inline / dialog), smazání (potvrzení přes existující
  `ui/` primitiv).
- **`ChatThread.svelte`** — zprávy (`MessageBubble`), běžící streamovaná odpověď,
  `ChatInput` dole; auto-scroll na konec.
- **`MessageBubble.svelte`** — render dle `role`/`kind`: `user_text` (prostý
  text), `assistant` (přes `Markdown`), `tool_results` (z historie → kompaktní
  „použité nástroje", ne syrový JSON).
- **`ToolCallChip.svelte`** — `{name, arguments}` → lidský popisek (mapa jmen →
  `t()` klíče, např. `persons_search` → „Hledám osoby"); fallback na jméno.
- **`Markdown.svelte`** — minimální bezpečná podmnožina (odstavce, `**tučné**`,
  `*kurzíva*`, `` `kód` ``, ```` ``` blok ````, `-`/`1.` seznamy) renderovaná do
  Svelte elementů. **Žádné `{@html}` z výstupu modelu.**

### 5. Streamovací UX

Odeslání: optimisticky bublina uživatele → `streaming=true`, prázdná bublina
asistenta plněná z `text-delta`; `tool-call` vloží `ToolCallChip` do běžícího
tahu; `message-complete` finalizuje (ulož `message_id`, vypni streaming, případně
refetch konverzace pro konzistenci `seq`); `error` → chybový stav + možnost
zkusit znovu. Vstup zablokovat po dobu streamu.

### 6. Navigace + i18n + styl

- Položka „Chat" do `Sidebar`/`navigationStore` + případ v `ContentArea`
  renderující `ChatView` (dle existujícího vzoru ostatních pohledů). Ikona z
  `icons.js`.
- Všechny texty přes `t()`; přidat chat klíče do i18n zpráv (cs + en, ať
  `check:i18n` projde).
- Styl jen přes `--shpd-color-*`; respektovat light/dark a mobilní režim
  (`layoutStore`).

### 7. Testy (`frontend/tests/Unit`)

- **`parseSseFrames`** — rámce rozdělené přes hranici chunku; `event:`+`data:`
  páry; více rámců v jednom chunku; ignorování `event:` bez `data:`.
- **`Markdown` subset** — vstup → očekávaná struktura (tučné/kurzíva/kód/seznam/
  odstavce); ověřit, že se neprovádí raw HTML (`<script>` ve vstupu zůstane
  textem).
- Mapování `tool-call` jména → popisek (známé + fallback).

## Hotovo když

1. V navigaci je „Chat" pohled; otevře seznam konverzací + aktivní vlákno.
2. Lze založit/přejmenovat/smazat/přepnout konverzaci (přes CRUD endpointy).
3. Odeslání zprávy streamuje odpověď token po tokenu; `tool-call` se ukáže jako
   čip s lidským popiskem; `message-complete` finalizuje, `error` zobrazí chybu.
4. Text asistenta se renderuje jako bezpečná podmnožina markdownu, bez raw HTML
   z výstupu modelu.
5. Historie konverzace (po reloadu) zobrazí uživatelské i asistentovy zprávy a
   použité nástroje čitelně (ne syrový JSON).
6. Vše přes `t()`, styl přes `--shpd-color-*`, funguje na desktopu i mobilu.
7. Unit testy (`parseSseFrames`, `Markdown`, mapování čipů) procházejí; `check:i18n`
   je čistý.

## Doporučené pořadí implementace

1. `api/chat.js` — CRUD + `sendMessageStream` s vyčleněným `parseSseFrames` +
   jeho test (keystone, nejdřív ověřit parser).
2. Chat store + `ChatView`/`ConversationList`/`ChatThread`/`ChatInput` se základním
   (neformátovaným) renderem — funkční smyčka end-to-end proti backendu.
3. `Markdown` subset + `ToolCallChip` + jejich testy.
4. Navigace, i18n klíče, mobilní režim, light/dark doladění.

## Rozhodnutí k designu (potvrzená)

1. ✓ **D-1 = A** — vlastní pohled „Chat" v `AppShell`/`ContentArea`, ne globální
   panel.
2. ✓ **D-2 = B** — minimální bezpečná podmnožina markdownu, bez závislosti a bez
   `{@html}` z modelu.
3. ✓ **D-3** — `tool-call` jako čip s lidským popiskem.
4. ✓ **SSE přes `fetch` + `getReader()`**, ne `EventSource`; `parseSseFrames`
   jako čistá testovatelná funkce.
5. ✓ **Výchozí backend**, žádný výběr modelu v UI v1.
6. ✓ **Žádné potvrzování zápisů** — backend chatu zápisové nástroje nenabízí.
7. ✓ **Chat store (runes)** pro sdílený stav seznam ↔ vlákno.

## Otevřené body (k ověření, neblokující)

- **401 uprostřed streamu** (vypršení tokenu) — v1 srozumitelná chyba; plný
  refresh-retry uprostřed POST streamu později.
- **Přesná gramatika markdown podmnožiny** — sjednotit, které prvky v1 (návrh:
  odstavce/tučné/kurzíva/inline+blokový kód/seznamy).
- **Mobilní layout seznam ↔ vlákno** uvnitř pohledu (app drawer řeší `AppShell`;
  přepínání uvnitř `ChatView` je na nás).
- **Mapa jmen nástrojů → popisky** — udržovat při růstu katalogu (fallback na
  jméno zajistí, že nic „nespadne").
