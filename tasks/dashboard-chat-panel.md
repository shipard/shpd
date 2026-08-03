# Dashboard — plovoucí chat launcher + boční AI chat panel

**Stav:** hotovo

## Status

Implementováno (2026-07-17). Navrženo a schváleno Annou (2026-07-17).
Zbývá ruční ověření v prohlížeči (akceptace 1–9; build ✓).

## Cíl

Uživatel může použít AI asistenta přímo z dashboardu, bez proklikávání do
sekce Chat. Dole v dashboardu plave **textový input (launcher)** — uživatel
do něj rovnou napíše dotaz, po odeslání **zprava vyjede boční panel**
s běžícím chatem (streaming odpovědi, tool čipy). Dashboard zůstává vidět
a klikatelný — panel je non-modální overlay.

```
┌───────────┬────────────────────────────────┬─────────────────────┐
│  Sidebar  │  Dashboard                     │  AI asistent    ⧉ + ×│
│           │  🤖 Dnešní shrnutí             │ ─────────────────── │
│           │  🟢 Přijatá faktura — ČEZ …    │  Kolik faktur čeká? │
│           │  🟡 Přijatá faktura — …        │                     │
│           │  ✓ Aktivní úkoly (5)           │  Ve frontě jsou 4…  │
│           │                                │                     │
│           │  ┌──────────────────────────┐  │ ─────────────────── │
│           │  │ Zeptejte se asistenta… ➤ │  │  [napište zprávu…]  │
│           │  └──────────────────────────┘  │                     │
└───────────┴────────────────────────────────┴─────────────────────┘
```

Konverzace se ukládají **stávající backend perzistencí** chatu (lazy
založení při prvním `send()`) — historie je automaticky dostupná v sekci
Chat, žádný nový endpoint, žádná změna backendu.

## Schválená rozhodnutí

1. **Panel žije v AppShellu** (vzor ThemePanel), ne v Dashboardu — přežije
   navigaci na viewer/formulář (chat může doporučit „otevři X", uživatel
   klikne a konverzace zůstává otevřená). Launcher je jen na dashboardu.
2. **Geometrie panelu**: overlay zprava, `width: min(480px, 90vw)`,
   **bez backdropu, non-modální**. Z-index **80** — pod mobilním drawerem
   (90/100), pod ThemePanelem (200) a pod Modalem/FormDialogem (1000):
   form modaly otevřené z karet i z chatu se otevírají NAD panelem.
3. **Launcher = přímý textový input** (žádná ikona, na kterou se kliká
   předem), sticky dole **na středu** dashboardu, `width: min(560px, 100%)`.
   Kolize s toastem („Vytvořen koncept…", fixed dole na střed) se řeší
   větším bottom offsetem toastu — vyskakuje nad launcherem.
4. **Vstup výhradně přes napsání zprávy**; každé odeslání z launcheru
   = **nová konverzace**. Návrat ke starším vláknům žije v sekci Chat.
5. **Mobil**: panel jako fullscreen overlay pod top barem (ne navigace do
   sekce Chat — nečekaný přesun by byl nepříjemný). Drawer (z-index 100)
   zůstává funkční nad panelem.
6. **Sdílený `chatStore` singleton** — panel používá tentýž store jako
   sekce Chat. Vědomý trade-off: odeslání z launcheru přepne `activeId`,
   takže sekce Chat po návratu ukazuje dashboardové vlákno („tentýž chat,
   jiné okno"). Perzistence, streaming, error handling — vše reuse.
7. **Žádný localStorage** — backend perzistence existuje a je user-scoped.

## Před implementací přečti

- `docs/chat.md` §7 (frontend chatu) — store, ChatThread, SSE konzument
- `docs/dashboard.md` §8 (frontend komponenty dashboardu)
- `frontend/src/stores/chat.svelte.js` — celý (singleton, lazy create,
  `newConversation()`, `send()`, `finalizeTurn()`)
- `frontend/src/components/chat/ChatThread.svelte` — samonosné vlákno
  (zprávy + streaming + composer), čte jen ze store
- `frontend/src/components/layout/AppShell.svelte` +
  `ThemePanel.svelte` — vzor AppShell-level fixed panelu
- `frontend/src/stores/navigation.svelte.js` — `navigate()`
- `frontend/src/stores/layout.svelte.js` — `isMobile`

## Scope

Čistě frontend. Žádná změna backendu, API kontraktu ani DB. Nové soubory:
1 mini store + 2 komponenty; drobné úpravy AppShell, Dashboard, i18n, docs.

## Datový tok

```
ChatLauncher (Dashboard)                      ChatPanel (AppShell)
  submit(text)                                  {#if chatPanelStore.isOpen}
    ├─ chatStore.newConversation()              hlavička (titulek, ⧉, +, ×)
    ├─ chatPanelStore.open()          ────►     <ChatThread />  ◄── chatStore
    └─ chatStore.send(text)                       (zprávy, streaming, composer)
         └─ lazy create + SSE (beze změny)
```

„Otevřít v Chatu" (⧉): `navigationStore.navigate({id:'chat', label:'Chat',
type:'chat', table:null, viewerId:null})` + `chatPanelStore.close()` —
`activeId` ve store zůstává, ChatView otevře totéž vlákno. (Nav položka
Chatu je hardcoded v `NavigationController.php` ~ř. 198 se stejným tvarem —
id `chat` zajistí zvýraznění v sidebaru.)

## Implementační kroky

### 1. Store `frontend/src/stores/chatPanel.svelte.js`

Minimální UI store podle vzoru layoutStore:

```js
// Stav bočního AI chat panelu (mountovaný v AppShellu, otevíraný
// z dashboardového ChatLauncheru). Drží jen otevřenost — obsah
// (konverzace) žije v chatStore.
let isOpen = $state(false);

export const chatPanelStore = {
  get isOpen() { return isOpen; },
  open()  { isOpen = true; },
  close() { isOpen = false; },
};
```

### 2. `frontend/src/components/chat/ChatPanel.svelte`

Obálka: hlavička + `<ChatThread />`. Žádný ConversationList — historie je
v sekci Chat.

- **Hlavička**: titulek = title aktivní konverzace
  (`chatStore.conversations.find(c => c.id === chatStore.activeId)?.title`,
  fallback `t('chat.panel.title')` — list se naplní, protože `send()` po
  lazy create volá `loadConversations()`). Tlačítka (Button ghost/sm):
  - **Otevřít v Chatu** (⧉) — navigace viz Datový tok, pak `close()`;
  - **Nová konverzace** (+) — `chatStore.newConversation()` (vlákno se
    vyprázdní, další zpráva z composeru založí nové);
  - **×** — `chatPanelStore.close()`. Konverzace zůstává uložená.
- **Tělo**: `<ChatThread />` v flex kontejneru (`flex: 1; min-height: 0` —
  ChatThread má `height: 100%`).
- **Geometrie (scoped CSS)**:

```css
.shpd-chat-panel {
  position: fixed;
  top: 0; right: 0; bottom: 0;
  width: min(480px, 90vw);
  z-index: 80; /* pod drawerem 90/100, ThemePanelem 200, Modalem 1000 */
  display: flex; flex-direction: column;
  background: var(--shpd-color-bg);
  border-left: 1px solid var(--shpd-color-border);
  box-shadow: var(--shpd-shadow-lg, -4px 0 16px rgba(0,0,0,0.15));
}
/* Mobil: fullscreen pod top barem (drawer zůstává nad panelem). */
.shpd-chat-panel--mobile {
  top: var(--shpd-header-height);
  left: 0; width: auto;
  border-left: none;
}
```

  Třídu `--mobile` řídí `layoutStore.isMobile` (stejně jako ChatView).
  Volitelně slide-in animace (`transform` + `transition` ~0.22s, vzor
  drawer v AppShellu).
- **Zavírání v1 jen přes ×** — žádný Esc listener (kolidoval by s Esc ve
  FormDialogu/Modalu otevřeném nad panelem; řešitelné později).

### 3. `frontend/src/components/dashboard/ChatLauncher.svelte`

Plovoucí jednořádkový input se send tlačítkem (iconChat), vzhled
„command bar" (pill, stín).

- **Chování**: Enter / klik na tlačítko →
  `chatStore.newConversation(); chatPanelStore.open(); chatStore.send(text);`
  (send bez await — panel se otevře okamžitě, optimistická zpráva +
  streaming dotečou přes store). Input se vyprázdní.
- **Viditelnost**: renderuje se jen když `!chatPanelStore.isOpen`
  (composer je v panelu, dva inputy by matoucí).
- **Geometrie**: sticky uvnitř scroll kontejneru `.shpd-content`:

```css
.shpd-chat-launcher {
  position: sticky;
  bottom: var(--shpd-space-lg);
  margin-top: auto;          /* krátký obsah → launcher u spodní hrany */
  align-self: center;
  width: min(560px, 100%);
  z-index: 10;               /* nad kartami feedu */
  box-shadow: var(--shpd-shadow-lg, 0 4px 16px rgba(0,0,0,0.2));
}
```

  K tomu v `Dashboard.svelte` doplnit `.shpd-dashboard { min-height: 100%;
  box-sizing: border-box; }` — kombinace `min-height` + `margin-top: auto`
  drží launcher u spodní hrany viewportu i při krátkém obsahu; při dlouhém
  obsahu ho `position: sticky; bottom` nechá „plavat" nad kartami během
  scrollu. Ověřit v prohlížeči (sticky v flex kontejneru s gap).

### 4. `AppShell.svelte` — mount panelu

Vedle `<ThemePanel …/>` (mimo mobilní/desktop větve — geometrii si panel
řeší sám přes `layoutStore.isMobile`):

```svelte
{#if chatPanelStore.isOpen}
  <ChatPanel />
{/if}
```

### 5. `Dashboard.svelte` — launcher + toast offset

- `<ChatLauncher />` jako poslední child `.shpd-dashboard` (za tasks
  widgetem).
- Toast: `bottom: calc(var(--shpd-space-lg) + 72px)` — vyskakuje nad
  launcherem (toast je lokální v Dashboard.svelte, jiné obrazovky
  nedotčené; 72px ≈ výška launcheru + mezera, doladit v prohlížeči).

### 6. i18n (`cs.js` + `en.js`)

| Klíč | cs |
|---|---|
| `dashboard.chatLauncher.placeholder` | `Zeptejte se AI asistenta…` |
| `dashboard.chatLauncher.send` | `Odeslat` |
| `chat.panel.title` | `AI asistent` |
| `chat.panel.openFull` | `Otevřít v Chatu` |
| `chat.panel.new` | `Nová konverzace` |
| `chat.panel.close` | `Zavřít` |

### 7. Dokumentace

- `docs/chat.md` §7 — odstavec: dashboardový launcher + AppShell panel,
  sdílený store, odkaz na `docs/dashboard.md`.
- `docs/dashboard.md` §8 — `ChatLauncher.svelte` do stromu komponent +
  zmínka o `ChatPanel` (AppShell) a toast offsetu.

## Akceptace

1. Dashboard zobrazuje dole na středu plovoucí input; při dlouhém feedu
   zůstává při scrollu u spodní hrany, při krátkém obsahu sedí dole.
2. Napsání dotazu + Enter → panel vyjede zprava, uživatelova zpráva je
   vidět, odpověď streamuje (text delty, tool čipy); launcher je schovaný.
3. Konverzace se objeví v seznamu v sekci Chat; „Otevřít v Chatu" (⧉)
   naviguje do sekce Chat se stejným otevřeným vláknem a zavře panel;
   položka Chat v sidebaru je zvýrazněná.
4. „Nová konverzace" (+) vyprázdní vlákno; další zpráva z composeru založí
   novou konverzaci (v seznamu přibude nový řádek).
5. Zavření panelu (×) konverzaci nezahazuje (je v historii); launcher se
   znovu objeví.
6. Panel přežije navigaci na viewer i otevření formuláře; FormDialog se
   otevírá NAD panelem a jeho Esc/× zavírá formulář, ne panel.
7. Apply toast („Vytvořen koncept…") vyskakuje nad launcherem, nepřekrývají
   se; Otevřít/Vrátit fungují.
8. Mobil (< 768px): panel je fullscreen pod top barem; hamburger otevře
   drawer NAD panelem; zavření panelu vrátí dashboard s launcherem.
9. Sekce Chat funguje beze změny (list/thread, mobilní přepínání).
10. `cd frontend && timeout 90 npm run build` bez chyb.

## Rozhodnutí k designu (zamítnuté alternativy)

- **localStorage perzistence** — zamítnuto; backend perzistence existuje,
  je user-scoped a sdílená se sekcí Chat.
- **Dashboard-scoped panel** — zamítnuto; při navigaci by panel zmizel
  uprostřed konverzace.
- **Squeeze layoutu** (panel odsouvá obsah) — zamítnuto pro v1; overlay je
  levnější a nepřeskládává feed.
- **Mobil: navigace do sekce Chat místo panelu** — zamítnuto; nečekaný
  přesun pryč z dashboardu.
- **Esc zavírání panelu** — odloženo; kolize s Esc modálů nad panelem.
- **Vstup přes ikonu/bublinu** — zamítnuto; launcher je rovnou input.
