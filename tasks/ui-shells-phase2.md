# UI shells — Fáze 2: Command palette

**Status:** připraveno k implementaci
**Issue:** [#45](https://github.com/shipard/shpd/issues/45) (zastřešující)
**Design doc:** `docs/ui-shells.md` §9
**Návaznost:** staví na Fázi 1 (`tasks/ui-shells-phase1.md`) — primitivy chrome,
`utils/navTree.js`. Čistě frontendová fáze, žádný PHP kód.

## Cíl

Spotlight/Cmd-K overlay pro rychlou navigaci: začnu psát, nabízí se cíle
(viewery, panely, reporty, stránky nastavení), Enter naviguje. Prázdný vstup
nabízí posledně navštívené. Shell-nezávislá — renderuje ji AppShell, shelly
dodávají jen trigger (kontrakt, `docs/ui-shells.md` §4).

## Uzavřená rozhodnutí (z návrhové diskuse)

- **D1 — rozsah v1: pouze navigační cíle** ze všech tří navigačních stromů.
  Žádná nápověda (nemá zatím frontend infrastrukturu; přijde s Fází 5 jako
  další provider), žádný fulltext záznamů, žádné akce `open_form` (v2).
- **D2 — zdroje: tři stromy, lazy.** První otevření palety stáhne
  `/_ui/navigation` + `/_ui/settings/navigation` + `/_ui/account/navigation`,
  cache po dobu session. Výběr cíle z jiného módu = přepnutí módu + navigace.
- **D3 — prázdný vstup: recents,** max 7, localStorage, zaznamenává se
  v `navigationStore.navigate()` (učí se i z běžné navigace sidebarem).
  **Jen app mód** — settings/account položky se do recents nepočítají.
- **D4 — matching s foldingem diakritiky** („uctarna" → „Účtárna"):
  NFD normalizace + strip, subsequence match, ranking
  prefix > začátek slova > subsequence, remíza → boost z recents.
- **D5 — trigger:** Ctrl/Cmd+K globálně + lupa v hlavičce sidebaru
  (collapsed: v pásu ikon), na mobilu položka v draweru.
- **D6 — interakce:** šipky/Enter/Esc, klik mimo zavírá, skupiny výsledků
  (Naposledy / Aplikace / Nastavení / Účet), max ~10 viditelných položek.
- **Architektura providerů:** zdroj = funkce `query → položky` s vlastní
  skupinou. V1 má providery „stromy" a „recents"; nápověda/záznamy se
  později přidají jako další provider, ne přepisem.

## Před implementací přečti

- `docs/ui-shells.md` §9 (koncept) a §4 (kontrakt — trigger)
- `frontend/src/utils/navTree.js` — `flattenLeaves` (rozšíří se)
- `frontend/src/stores/navigation.svelte.js` — `navigate()`, mode API,
  `setAppNavTree`
- `frontend/src/components/layout/AppShell.svelte` — existující `keydown`
  listener (drawer Esc) a místo pro overlay
- `frontend/src/components/layout/ThemePanel.svelte` — vzor overlay panelu
  (keydown lifecycle, Esc, klik mimo)
- `frontend/src/components/chrome/NavIconStrip.svelte`, `Sidebar.svelte` —
  místa pro trigger
- `frontend/src/stores/auth.svelte.js` (`authStore.user`),
  `frontend/src/api/client.js` — pro klíč recents a fetch vzor
- `docs/frontend.md` §9 — pasti (dropdown/popover, `$derived` vs. efekt)

## Rozhodnutí v tomto PRD

- **R1 — umístění:** overlay `frontend/src/components/chrome/CommandPalette.svelte`
  (je to chrome, byť shell-nezávislý), logika v
  `frontend/src/stores/palette.svelte.js`, čisté funkce v
  `frontend/src/utils/paletteMatch.js` a `frontend/src/utils/recents.js`.
- **R2 — vlastní overlay, ne `ui/Modal`:** paleta má specifický tvar
  (horní třetina obrazovky, bez headeru/footeru, input jako první prvek).
  Lifecycle vzory (Esc, klik mimo, focus) převzít z `ThemePanel.svelte`.
- **R3 — klíč recents:** `shpd_recents_<userId>` (`authStore.user.id`;
  přesný název pole ověřit v kódu). DS izolaci řeší doména (localStorage je
  per origin, každá DS má vlastní subdomain) — DS id do klíče nepatří.
  Hodnota: pole `{id, label, icon, type, ts}`, cap 7, nejnovější první,
  dedup podle `id`. Defenzivní `try/catch` na parse (vzor `auth.svelte.js`).
- **R4 — záznam recents:** na konci `navigationStore.navigate(item)`,
  pouze `mode === 'app'` a jen položky s `id` (ne ad-hoc
  `navigateToViewer`/`navigateToPanel` cíle — ty nejsou v nabídce palety,
  recents drží jen to, co paleta umí znovu otevřít).
- **R5 — identifikace položek napříč módy:** interní tvar položky palety
  `{key: "<mode>:<id>", mode, id, label, icon, type, groupLabel}`.
  `flattenLeaves` v `utils/navTree.js` dostane volitelný parametr pro
  sběr `groupLabel` (název sekce/skupiny pro sekundární řádek výsledku) —
  zpětně kompatibilně (NavIconStrip beze změny).
- **R6 — navigace na výběr:** cílový mód ≠ aktuální →
  `enterSettings()`/`enterAccount()`/`exitToApp()` a pak `navigate(item)`
  s originálním objektem leafu ze stromu (paleta si drží referenci).
  `pendingViewerNavigation` a spol. se nepoužívají — leaf existuje.
- **R7 — zkratka:** `Ctrl+K` / `Cmd+K` (`e.key === 'k' && (e.ctrlKey || e.metaKey)`),
  `preventDefault`, registruje `AppShell` ve stávajícím `onKeyDown`.
  Ignorovat, je-li otevřený FormDialog (kolize s editací — detekci
  převzít z existujícího stavu AppShell/ContentArea; pokud čistá detekce
  není k dispozici, v1 stačí: nereagovat, když je focus v `input`,
  `textarea` nebo `[contenteditable]` mimo paletu).
- **R8 — bez virtualizace:** stromy mají desítky položek, ne tisíce.
  Renderuje se max ~10 výsledků na skupinu, žádný windowing.

## Scope — po souborech

### Nové soubory

**`frontend/src/utils/paletteMatch.js`**
- `foldDiacritics(s)` — NFD + strip kombinujících znaků + lowercase.
- `matchItem(queryFolded, labelFolded)` → `null` | `{score, ranges}`:
  prefix (nejvyšší) > začátek slova > subsequence (nejnižší); `ranges`
  = indexy shod pro zvýraznění v UI (mapované na původní label).
- `rankResults(items, query, recentIds)` — skóre + recents boost při remíze,
  stabilní řazení, limit per skupina.

**`frontend/src/utils/recents.js`**
- `loadRecents(userId)`, `recordRecent(userId, entry)` (cap 7, dedup,
  nejnovější první), `clearRecents(userId)`. Bez Svelte — testovatelné
  přes `node --test` (localStorage mock).

**`frontend/src/stores/palette.svelte.js`**
- Stav: `open`, `query`, `activeIndex`, `sources` (cache tří stromů),
  `loading`, `error`.
- `openPalette()` (lazy fetch stromů při prvním otevření; app strom
  přednostně z `navigationStore.appNavTree`, zbylé dva přes
  `apiGet`), `closePalette()`, `setQuery()`, `moveActive(±1)`,
  `confirmActive()` (R6 + `recordRecent`), `results` (`$derived`
  ze `sources` + `query` + recents; prázdný query → skupina Naposledy).
- Selhání fetche stromu: skupina se vynechá, chyba se zobrazí jako řádek
  ve výsledcích (ne toast) — paleta zůstává použitelná pro ostatní zdroje.

**`frontend/src/components/chrome/CommandPalette.svelte`**
- Overlay: backdrop (klik zavírá), panel v horní třetině, input
  (autofocus), skupiny výsledků s nadpisy, zvýraznění shod (`ranges`),
  aktivní řádek (šipky + hover), ikony položek, sekundární řádek
  `groupLabel`, Enter = confirm, Esc = zavřít.
- Klávesy řeší lokální `keydown` na overlay (vzor ThemePanel); globální
  jen otevírací zkratka v AppShellu.
- A11y minimum: `role="dialog"`, `aria-label`, input `aria-activedescendant`.

### Změny

**`frontend/src/components/layout/AppShell.svelte`**
- Render `<CommandPalette />` (vedle ThemePanel/ChatPanel).
- `onKeyDown`: Ctrl/Cmd+K → `paletteStore.openPalette()` (R7).

**`frontend/src/stores/navigation.svelte.js`**
- `navigate()`: záznam recents dle R4 (`import { recordRecent }`).
- Žádná jiná změna.

**`frontend/src/utils/navTree.js`**
- `flattenLeaves` — volitelný sběr `groupLabel` (R5), zpětně kompatibilní;
  doplnit unit testy.

**`frontend/src/components/layout/Sidebar.svelte`**
- Lupa v hlavičce vedle `BrandingHeader` (rozbalený režim), v collapsed
  režimu ikona nad `NavIconStrip`; mobil (drawer) — tatáž hlavička, ověřit
  že klik zavře drawer (otevření palety → `closeDrawer()`).
- Tooltip/aria s údajem zkratky („Hledat · Ctrl+K" / „⌘K" dle platformy).

**i18n (`frontend/src/i18n/*.json`)**
- Nové klíče: placeholder inputu, nadpisy skupin (Naposledy / Aplikace /
  Nastavení / Účet), prázdný stav („Nic nenalezeno"), chybový řádek,
  tooltip triggeru. `npm run check:i18n` musí projít.

**`docs/frontend.md`**
- Nová podsekce (do §4 nebo za něj): command palette — trigger, zkratka,
  provider architektura, recents (localStorage, jen app mód), odkaz na
  `docs/ui-shells.md` §9.

**`docs/ui-shells.md`**
- §9 přepnout na „realizováno Fází 2" + zapsat provider kontrakt jako
  hotový koncept.

### Mimo scope v1

- Nápověda, fulltext záznamů, akce `open_form` (v2 — nové providery).
- Serverové vyhledávání čehokoli; synchronizace recents mezi zařízeními.
- Vlastní konfigurace zkratky.

## Testy

- **Unit (`node --test`):**
  - `paletteMatch.test.mjs` — folding („Účtárna"→„uctarna", „Číselníky"),
    prefix vs. word-start vs. subsequence skóre, ranges pro zvýraznění,
    recents boost při remíze, prázdný query;
  - `recents.test.mjs` — cap 7, dedup přesouvá nahoru, corrupted JSON
    nepadá, izolace per userId;
  - `navTree.test.mjs` — rozšíření o `groupLabel`.
- **Build + i18n:** `npm run build`, `npm run check:i18n` čisté.
- **Manuální smoke (dev):**
  1. Ctrl/Cmd+K otevře, autofocus, Esc zavře, klik na backdrop zavře;
  2. „uctarna" najde položky Účtárny (folding), zvýraznění shod;
  3. výběr app položky naviguje; výběr settings položky přepne mód
     a naviguje; návrat do app drží poslední položku;
  4. prázdný vstup: Naposledy dle skutečné historie navigace (i klikáním
     v sidebaru, jen app mód), max 7;
  5. šipky + Enter; hover nemění výběr pod rukama při psaní;
  6. trigger: lupa rozbalený/collapsed sidebar, drawer na mobilu
     (otevření zavře drawer), tooltip se zkratkou;
  7. zkratka nereaguje při otevřeném FormDialogu / focusu v inputu;
  8. konzole bez warningů.

## Strategie commitů

1. `feat(frontend): palette match + recents utils` (+ unit testy)
2. `feat(frontend): command palette store and overlay (#45)`
3. `feat(frontend): palette triggers in sidebar, recents in navigate (#45)`
4. `docs: frontend.md — command palette`

Commity průběžně; push dělá David.

## Hotovo když

- [ ] Ctrl/Cmd+K + lupa otevírají paletu ve všech režimech sidebaru
      i na mobilu
- [ ] fuzzy s foldingem diakritiky, zvýraznění shod, skupiny dle módů
- [ ] výběr naviguje vč. přepnutí módu; recents (7, jen app) fungují
      a plní se i běžnou navigací
- [ ] provider architektura zřejmá z kódu (přidání zdroje = nový provider)
- [ ] `npm test` zelené vč. nových testů, build + check:i18n čisté
- [ ] smoke 1–8 prošel
- [ ] `docs/frontend.md` + `docs/ui-shells.md` §9 aktualizované
- [ ] komentář v issue #45: Fáze 2 hotová (odkaz na commity)
