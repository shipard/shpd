# UI shells — Fáze 5: Sekční AI kontexty

**Stav:** hotovo
**Issue:** [#45](https://github.com/shipard/shpd/issues/45) (zastřešující)
**Design doc:** `docs/ui-shells.md` §10
**Návaznost:** Fáze 3 (`FeedCollector`, `navSection` na kartách),
Fáze 1 (`activeSection`). Nezávislé na Fázi 4 (funguje v obou shellech).
Server + frontend, jeden repozitář.

## Cíl

Chat asistent s vědomím „oddělení": konverzace scopnutá na sekci dostane
sekční prompt, uživatel při vstupu vidí karty sekce jako UI (ne AI) a model
si feed umí vytáhnout čtecím nástrojem. Přínos hned v sidebar/classic
shellech; wild shell (Fáze 6) z toho udělá první záložku sekce.

## Uzavřená rozhodnutí (z návrhové diskuse)

- **D1 — konfigurace: cfgItem `core.chat.sectionContexts`**
  (`modules/core/chat/module.jsonc`), klíč = id sekce, hodnota
  `{prompt}` lokalizovaný (vzor `alertChecks`/ConfigLocalizer). Ne
  v `navSections` — prompt je serverová věc, do `/_ui/navigation` neteče.
- **D2 — scope na konverzaci:** nullable sloupec `section`
  v `core_chat_conversations`; volí se při založení, nemění se.
  Scoped = základní system prompt + sekční blok.
- **D3 — feed do AI pullem:** čtecí nástroj `feed_cards`
  (volitelný param `section`) nad `FeedCollectorem`. Žádný push feedu
  do promptu (staleness, tokeny). Read-only invariant platí automaticky.
- **D4 — feed do UI jako UI:** prázdná scoped konverzace zobrazí karty
  sekce (`GET /_ui/dashboard?section=<id>`) nad inputem — skutečné
  dashboard karty s funkčními akcemi, žádná AI generace.
- **D5 — vstup jen implicitně přes ChatPanel** (potvrzeno): otevření
  panelu při `activeSection = X` → nová konverzace scoped na X,
  odstranitelný chip u inputu; po první zprávě scope zafixovaný.
  `_top`/`null` = bez scope. Plná sekce Chat scope jen **zobrazuje**
  (seznam + hlavička vlákna), nenabízí výběr.
- **D6 — nástroje se per sekce nefiltrují** (fokus dělá prompt,
  bezpečnost read-only tier).
- **D7 — žádná AI sumarizace sekce ve v1** (badge zůstávají
  deterministické).
- Prompty píše Claude (schváleno), revize = revize tohoto PRD, viz §Prompty.

## Před implementací přečti

- `docs/ui-shells.md` §10; `docs/chat.md` celý (smyčka, system prompt §5,
  panel §7); `docs/ai.md`; `docs/mcp-server.md` — registrace nástrojů
- `src/Api/Controller/ChatController.php` — `systemPrompt()` (ř. ~626),
  create/list/detail konverzací, `runAgenticLoop` (registry, `isReadOnly`)
- `modules/base/persons/src/Mcp/PersonsSearchTool.php` +
  `src/Api/Mcp/McpTool.php` — vzor nástroje
- `src/Core/Feed/FeedCollector.php`, `src/Api/Controller/DashboardController.php`
- `modules/core/chat/module.jsonc` (cfgItem `core.chat.settings`, ř. ~47),
  `modules/core/chat/tables/core_chat_conversations.jsonc`
- `frontend/src/components/chat/` (ChatPanel, ChatView, ChatInput,
  chat store), `frontend/src/components/dashboard/` — komponenta karty
  (reuse pro D4)
- `docs/table-definitions.md` — přidání sloupce + ds-upgrade

## Rozhodnutí v tomto PRD

- **R1 — validace `section` při založení konverzace:** hodnota musí být
  id existující sekce z `global.navSections` (ne `_top`, ne libovolný
  string) → jinak `INVALID_VALUE`. Zápis přes `TableGateway::saveDocument`
  jako dosud.
- **R2 — sekční blok promptu:** připojí se za základ (před datum):
  `"Kontext: uživatel konverzuje v oddělení «{label}». {prompt}"` —
  `label` z navSections (jazyk DS), `prompt` z cfgItem. Sekce bez
  záznamu v cfgItem → jen věta s labelem (degradace bez konfigurace).
- **R3 — `FeedCardsTool` v `src/Api/Mcp/`** (core nástroj — feed je
  core, spans moduly). `name: feed_cards`, `isReadOnly: true`, params:
  `{section?: string}`; běh: `FeedCollector::collect` (plný kontext jako
  dashboard) → filtr `navSection === section` (bez paramu bez filtru) →
  `stripInternalFields` → pole `{kind, title, subtitle, navSection,
  timestamp}`. Registrace dle mechaniky v `mcp-server.md` (stejně jako
  persons_search — implementátor převezme vzor).
- **R4 — `?section=` na `GET /_ui/dashboard`:** po collect filtr karet
  dle `navSection`; `readySummary` se při filtru **nepočítá** (je
  celofeedový) — odpověď jen karty. Nevalidní hodnota → prázdný seznam
  (ne chyba).
- **R5 — chip a fixace scope:** nová konverzace v panelu dostává
  `pendingSection` (klientský stav) z `navigationStore.activeSection`
  v okamžiku otevření; chip s labelem sekce + ✕ (odstraní scope) vedle
  inputu. `section` se odešle až s **prvním** POST konverzace (dnes se
  konverzace zakládá při odeslání launcherem — ověřit tok a založit se
  `section`). Po založení chip bez ✕ (statický indikátor). Vlákna
  v seznamu: malý štítek sekce.
- **R6 — karty sekce v prázdné scoped konverzaci:** fetch při otevření
  (jen `pendingSection`/`section` ≠ null a 0 zpráv), reuse dashboard
  karty komponenty; po odeslání první zprávy se blok skryje (nahoru ho
  vrátí jen nová prázdná scoped konverzace). Chyba fetche → blok se
  tiše vynechá.
- **R7 — `toolLabels.js`:** `feed_cards` → „Upozornění a návrhy" /
  "Alerts and suggestions".
- **R8 — provozní kroky po merge** (pro Davida, ne kód): rebuild
  kompilované cfg + `ds-upgrade` (nový cfgItem, nový sloupec — schema
  sync), restart PHP dle běžného postupu.

## Prompty (obsah cfgItem, k revizi v rámci PRD)

Krátké, fokus ne pravidla — obecné chování drží základní prompt.

**purchase (cs):** „Jsi asistent oddělení Nákup. Soustřeď se na přijaté
faktury, dodavatele, objednávky a závazky. Při dotazech na doklady začínej
u přijatých faktur a souvisejících dodavatelů; částky a splatnosti ověřuj
nástroji, neodhaduj."

**purchase (en):** "You are the Purchasing department assistant. Focus on
received invoices, suppliers, orders and payables. For document questions,
start from received invoices and related suppliers; verify amounts and due
dates with tools, never guess."

**sales (cs):** „Jsi asistent oddělení Prodej. Soustřeď se na vydané
faktury, odběratele a pohledávky. Při dotazech na doklady začínej
u vydaných faktur a souvisejících odběratelů; částky, splatnosti a úhrady
ověřuj nástroji, neodhaduj."

**sales (en):** "You are the Sales department assistant. Focus on issued
invoices, customers and receivables. For document questions, start from
issued invoices and related customers; verify amounts, due dates and
payments with tools, never guess."

**accounting (cs):** „Jsi asistent Účtárny. Soustřeď se na účetní doklady,
účetní deník, banku a saldokonto. Dbej na přesnost čísel a účtů — vždy je
ověřuj nástroji a odkazuj na konkrétní doklady, ze kterých vycházíš.
U nesrovnalostí (nezaúčtované doklady, nespárované platby) nabídni, kde
hledat příčinu."

**accounting (en):** "You are the Accounting department assistant. Focus on
accounting documents, the journal, bank and open items. Be precise with
numbers and accounts — always verify them with tools and reference the
specific documents you rely on. For discrepancies (unposted documents,
unmatched payments), suggest where to look for the cause."

`system` a `basic` bez záznamu (R2 degradace stačí; `basic` zaniká).

## Scope — po souborech

### Server

**`modules/core/chat/module.jsonc`** — cfgItem `core.chat.sectionContexts`
(prompty výše, cs/en dle lokalizačního vzoru).

**`modules/core/chat/tables/core_chat_conversations.jsonc`** — sloupec
`section` (string 32, nullable, bez indexu); regenerovat `.md` dle
postupu v `table-definitions.md`.

**`src/Api/Controller/ChatController.php`**
- POST create: přijmout + validovat `section` (R1), uložit.
- GET list/detail: `section` v odpovědi.
- `systemPrompt()` → `systemPrompt(?string $section)` (R2; cfgItem +
  navSections lookup).

**`src/Api/Mcp/FeedCardsTool.php`** — R3 + registrace.

**`src/Api/Controller/DashboardController.php`** — R4.

### Frontend

**`frontend/src/api/chat.js`** — `section` v create + typy odpovědí.

**chat store** — `pendingSection`, propsání `section` konverzací.

**`frontend/src/components/chat/ChatPanel.svelte`** — default scope
z `activeSection` při otevření (R5).

**`frontend/src/components/chat/ChatInput.svelte`** (příp. ChatThread) —
chip (R5).

**`frontend/src/components/chat/ChatThread.svelte`** — blok karet sekce
(R6), štítek scope v hlavičce vlákna.

**`frontend/src/components/chat/ConversationList.svelte`** — štítek sekce.

**`frontend/src/utils/toolLabels.js`** — R7.

**i18n** — chip, štítky, nadpis bloku karet („Aktuálně v oddělení").

### Dokumentace

- `docs/chat.md`: §5 sekční blok, §1/§6 `section`, nový § karty sekce
  + `feed_cards`.
- `docs/dashboard.md`: `?section=` param.
- `docs/mcp-server.md`: `feed_cards` v přehledu nástrojů.
- `docs/ui-shells.md`: §10 → realizováno.

### Mimo scope

- Výběr scope v plné sekci Chat (D5), filtrování nástrojů per sekce (D6),
  AI sumarizace sekce (D7), help provider palety (samostatná drobná
  úprava mimo #45 — nástroje už existují), wild shell.

## Testy

- **PHPUnit** (filtr `"ChatController|FeedCardsTool|Dashboard"`):
  create s validní/nevalidní/chybějící sekcí; systemPrompt se sekcí
  (s promptem / bez záznamu v cfgItem / bez sekce beze změny);
  `FeedCardsTool` — filtr, bez filtru, strip polí, `isReadOnly`;
  dashboard `?section=` filtr + vynechané summary; nevalidní sekce →
  prázdno.
- **Frontend:** build + check:i18n; smoke:
  1. v sekci Účtárna otevřu ChatPanel → chip „Účtárna", karty sekce
     nad inputem (existují-li), akce karty funguje;
  2. ✕ na chipu → scope pryč, karty zmizí; znovuotevření v sekci →
     chip zpět;
  3. odeslání první zprávy → konverzace má `section`, chip statický,
     karty skryté; model na dotaz „co tu na mě čeká" volá `feed_cards`
     (čip nástroje s popiskem) a odpovídá nad kartami sekce;
  4. scoped konverzace odpovídá v duchu sekčního promptu (ověřit
     otázkou na doménu);
  5. `_top`/dashboard → panel bez chipu, chování jako dosud;
  6. seznam konverzací: štítky sekcí; „Otevřít v Chatu" přenese vlákno
     vč. scope; plná sekce Chat scope nenabízí, jen zobrazuje;
  7. classic shell: totéž chování (activeSection z horního menu);
  8. konzole bez warningů.

## Strategie commitů

1. `feat(server): section contexts cfgItem, conversation section (#45)`
2. `feat(server): feed_cards tool, dashboard section filter (#45)`
3. `feat(frontend): chat section scope — chip, section cards (#45)`
4. `docs: chat, dashboard, mcp-server, ui-shells — section AI contexts (#45)`

Commity průběžně; push dělá David.

## Hotovo když

- [x] cfgItem s prompty (po Davidově revizi textů), sloupec `section`
      (ds-upgrade sync = provozní krok R8, na dev DS zatím neproběhl)
- [x] scoped konverzace: validace, sekční blok promptu, `feed_cards`
      registrovaný a čtecí
- [x] panel: chip dle `activeSection`, karty sekce v prázdné konverzaci,
      fixace po první zprávě
- [x] PHPUnit filtr zelený, build + check:i18n čisté
- [ ] smoke 1–8 prošel (vč. classic shellu)
- [x] dokumentace (4 soubory) aktualizovaná
- [ ] komentář v issue #45: Fáze 5 hotová (odkaz na commity)

## Odchylky implementace (2026-08-26)

- **Vstupní bod panelu**: `chatPanelStore.open()` měl jediného volajícího
  (dashboardový `ChatLauncher`, kde `activeSection` je vždy null) — scénář
  „otevřu panel v sekci" neměl jak nastat. Po dohodě přidáno **tlačítko
  chatu v chrome** obou shellů (hlavička Sidebaru + sbalený pás, classic
  `TopMenuBar`), gate = Chat leaf v nav tree. Smoke 1 se provádí přes něj.
  Scope zachytává `chatStore.newConversation(section)` — tedy i „+"
  v hlavičce panelu.
- **Akce karet v chatu**: obsluhují se jen navigační druhy (`open_viewer`,
  `open_panel`, `open_form`, `open_detail`), ostatní se z karty odfiltrují —
  pokrývá 100 % dnešních sekčních karet (alerty nesou jen
  `open_form`/`open_viewer`); těžké dashboard flow (apply/undo…) se
  neextrahovaly.
- R1 zmiňuje `TableGateway::saveDocument` — create ve skutečnosti píše raw
  `insertRow` (beze změny mechaniky, jen doplněn sloupec).
- `toolLabels.js` žije v `frontend/src/components/chat/`, ne v `utils/`.
