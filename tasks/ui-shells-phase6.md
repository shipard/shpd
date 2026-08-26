# UI shells — Fáze 6: shell `wild`

**Stav:** hotovo

Implementováno 2026-08-26 (4 commity dle strategie); zbývá ruční smoke
1–11 v prohlížeči a komentář v issue #45 po push. Odchylky od PRD:
`resolveLanding` v `utils/wildLanding.js` (testováno); ChatThread dostal
prop `showScopeChip` (AI záložka chip skrývá — scope dává záložka);
label shellu ve volbě = „Kompaktní / Compact" (potvrzeno). R3 efekt
reaguje jen na změnu `activeSection`/`activeId` (prev proměnné) — literal
podmínka „liší se od browsingSection" by prohlížení vracela zpět.
**Issue:** [#45](https://github.com/shipard/shpd/issues/45) (zastřešující)
**Design doc:** `docs/ui-shells.md` §12
**Návaznost:** skládá hotové kusy — Fáze 4 (resolver, registry, volba,
flyout vzor), Fáze 5 (scoped konverzace, `SectionCards`), Fáze 3 (badge
vč. `_top`), Fáze 1 (`activeSection`). Server změna minimální.

## Cíl

Třetí shell: **wild** — AI-first, maximálně kompaktní. Svislý rail sekcí
s badge vlevo, obsah se záložkami-ikonami nahoře, první záložka = AI
asistent sekce (scoped konverzace + karty oddělení). V1 = kompozice
existujících kusů; rozvoj iterativně dle chování (potvrzený přístup).

## Uzavřená rozhodnutí (z návrhové diskuse)

- **D1 — rail:** domeček (`_top`) nahoře + sekce z `navSections`, velké
  ikony bez textu + tooltip, badge z Fáze 3 (`_top` na domečku — první
  místo, kde se vykreslí). Dole trigger palety + `UserMenu` (compact);
  bez chrome tlačítka chatu (AI je v záložkách). Nahoře ikona aplikace
  (tooltip = název).
- **D2 — záložky = uzly úrovně 2 aktivní sekce jako ikony** s tooltipem;
  skupina = záložka s dropdownem úrovně 3 (interakce = flyout classicu,
  směr dolů). Nový primitiv `chrome/NavTabStrip.svelte` — **ne**
  „TabBar" (koliduje s existujícími záložkami dokladů
  `layout/TabBar.svelte`).
- **D3 — první záložka = AI asistent sekce; první vstup do sekce
  přistává na ní** (záměrný rozdíl proti classic D1 — AI-first).
  Domeček AI záložku **nemá** (bez scope, přistává na dashboardu).
- **D4 — AI záložka navazuje na poslední scoped konverzaci sekce**
  (nejnovější dle seznamu, `section` už API nese) + tlačítko „Nová
  konverzace"; bez existující = prázdný stav (SectionCards + input,
  Fáze 5). Komponenta `SectionAssistant` = kompozice
  ChatThread/ChatInput/SectionCards v plné ploše.
- **D5 — stav prohlížení je shell-lokální:** `browsingSection`
  + aktivní záložka; klik na rail nenaviguje (mění jen prohlíženou
  sekci), klik na leaf záložku naviguje (`activeSection` dojede
  derivací); externí navigace (paleta, deep link) srovná prohlížení
  dle `activeSection`. `navigationStore` beze změny.
- **D6 — zapojení:** `'wild'` do server allowlistu, registry,
  `ShellSegments` třetí volba; resolver Fáze 4 beze změny (mobil
  a settings/account módy → sidebar-style). ThemePanel `leftOffset`
  = šířka railu.
- **D7 — paměť „opravdového posledního stavu" per sekce** (potvrzeno):
  návrat do sekce obnoví záložku, na které uživatel skončil (vč. AI
  záložky, vč. aktivního leafu); AI záložka jen při **prvním** vstupu
  v session. Paměť přežije výlet do settings módu (viz R2), reload ne.
- **Mimo v1 (vědomě, dle „pomalu budovat"):** varianty dashboardu dle
  objemu dokladů (serverové, mimo #45), živé UI prvky v chatu (vlastní
  trať), AI záložka domečku, AI shrnutí sekce, mobilní podoba wild.

## Před implementací přečti

- `docs/ui-shells.md` §12; `tasks/ui-shells-phase4.md` (R2 resolver, R8
  flyout — vzor dropdownu) a `tasks/ui-shells-phase5.md` (scope, karty)
- `frontend/src/components/shells/ClassicShell.svelte` +
  `classic/TopMenuBar.svelte` — vzor kompozice shellu
- `frontend/src/components/chrome/NavFlyoutStrip.svelte` — vzor
  skupinového dropdownu a aktivních stavů
- `frontend/src/components/chat/` — ChatPanel (lifecycle store),
  ChatThread, ChatInput, SectionCards; `stores/chat.svelte.js`
  (`newConversation(section)`, seznam se `section`)
- `frontend/src/stores/navigation.svelte.js` (`activeSection`),
  `stores/shell.svelte.js`, `components/settings/ShellSegments.svelte`
- `src/Api/Controller/SettingsController.php` — konstanta `SHELLS`

## Rozhodnutí v tomto PRD

- **R1 — struktura:** `shells/WildShell.svelte` (grid: rail | tabbar
  + obsah), `shells/wild/SectionRail.svelte` (shell-privátní),
  `chrome/NavTabStrip.svelte` (sdílený primitiv — čisté nav uzly);
  AI záložku renderuje WildShell **vedle** NavTabStrip (kompozice
  v jednom vizuálním pruhu), primitiv o AI nic neví.
- **R2 — shell-lokální stav v module-level store**
  `stores/wildShell.svelte.js`: `browsingSection`, `lastTabBySection`
  (Map `sectionId → {tab: 'ai' | leafId}`). Store, ne komponentní stav —
  přechod do settings módu WildShell odmountuje (resolver) a paměť by
  se ztratila; module-level přežije, reload ji smaže (D7).
- **R3 — synchronizace s externí navigací:** `$effect` ve WildShellu —
  změní-li se `navigationStore.activeSection` a neodpovídá
  `browsingSection`, nastav `browsingSection = activeSection`
  a `lastTabBySection[sekce] = {tab: activeItem.id}` (uživatel přišel
  paletou/deep linkem na konkrétní leaf — AI záložka se nevnucuje).
- **R4 — SectionAssistant a chatStore singleton:** používá tentýž
  `chatStore` jako panel a sekce Chat (žádný druhý store). Vstup na AI
  záložku: najdi nejnovější konverzaci se `section === browsingSection`
  → aktivuj; jinak `newConversation(section)` (prázdný stav s kartami).
  Souběh s otevřeným ChatPanelem zobrazuje totéž vlákno — přijatelné
  v1 (stejná sémantika jako dnešní „Otevřít v Chatu"), zapsat do
  `chat.md`.
- **R5 — NavTabStrip:** props `nodes`, `activeId`, `onNavigate`;
  leaf = ikonová záložka, skupina = záložka s `Popover` dropdownem
  (úroveň 3, oddělovače podskupin, aktivní stavy — chování 1:1
  s NavFlyoutStrip, jen orientace). Max jeden otevřený dropdown.
- **R6 — přistání a přepínání (D3 + D7):** klik na sekci v railu →
  `lastTabBySection` má záznam? obnov (leaf → `navigate`, 'ai' → AI
  záložka) : AI záložka. Klik na domeček → poslední `_top` leaf ze
  záznamu, jinak dashboard leaf. Každá změna záložky zapisuje do
  `lastTabBySection`.
- **R7 — server:** jen `SHELLS = ['sidebar', 'classic', 'wild']`
  + rozšíření existujícího testu. Žádná další serverová změna.
- **R8 — vzhled:** rail šířka dle collapsed sidebaru (konzistence
  tokenů), tabbar výška kompaktní; tokeny z design-system, žádné nové
  barvy. Ikona AI záložky: použít existující chat ikonu (nezavádět
  „sparkle" — konzistence s chrome tlačítkem chatu ostatních shellů).

## Scope — po souborech

### Server

**`src/Api/Controller/SettingsController.php`** — R7.
**`tests/Unit/Api/Controller/SettingsControllerTest.php`** — `wild`
validní, neznámé jméno dál nevalidní.

### Frontend — nové

**`frontend/src/stores/wildShell.svelte.js`** — R2.
**`frontend/src/components/shells/WildShell.svelte`** — R1, R3, R6;
`themePanelLeftOffset` konstanta šířky railu.
**`frontend/src/components/shells/wild/SectionRail.svelte`** — D1
(ikona aplikace, domeček + sekce + badge ze `sectionBadgesStore`,
dole paleta + UserMenu compact).
**`frontend/src/components/chrome/NavTabStrip.svelte`** — R5.
**`frontend/src/components/chat/SectionAssistant.svelte`** — D4, R4
(hlavička: název sekce + „Nová konverzace" + „Otevřít v Chatu").

### Frontend — změny

**`frontend/src/components/shells/index.js`** — registrace `wild`.
**`frontend/src/components/settings/ShellSegments.svelte`** — třetí
volba (cs „Divoké"? — finální label navrhne implementace, cs/en
v i18n; pracovně „Kompaktní / Compact" je popisnější než „wild").
**i18n** — název shellu, AI záložka, „Nová konverzace" (existuje-li
klíč, reuse), tooltipy railu.

### Dokumentace

- `docs/frontend.md`: wild shell (rail, tab strip, section assistant,
  wildShell store), NavTabStrip do přehledu primitiv.
- `docs/ui-shells.md`: §12 wild → realizováno + poznámka „v1 = kompozice,
  rozvoj iterativně"; §4 checklist odškrtnout.
- `docs/chat.md`: SectionAssistant + poznámka o sdíleném singletonu (R4).
- `docs/app-settings.md`: allowlist `wild`.

### Mimo scope

Viz „Mimo v1" v rozhodnutích. Navíc: overflow railu (scroll stačí),
konfigurovatelné pořadí záložek, params shellu.

## Testy

- **PHPUnit** (filtr `"SettingsController"`): allowlist s `wild`.
- **Frontend:** build + check:i18n; unit jen pokud R6 logika vyjde jako
  čistá funkce (`resolveLanding(lastTab, section)` — doporučeno vytáhnout
  do `utils/` a otestovat: první vstup → 'ai', záznam leaf → leaf,
  záznam 'ai' → 'ai', domeček bez záznamu → dashboard).
- **Manuální smoke (dev, uživatel se shellem wild):**
  1. přepnutí na wild v Nastavení účtu → soft swap, aktivní položka
     zůstala (a její sekce je prohlížená, záložka = aktivní leaf);
  2. rail: ikony + tooltips, badge na sekcích i domečku (`_top`),
     aktivní zvýraznění prohlížené sekce;
  3. první vstup do Účtárny → AI záložka: karty sekce + input; dotaz
     → scoped konverzace (chip mechanika Fáze 5 tu není — scope dává
     záložka), `feed_cards` funguje;
  4. návrat do Účtárny po práci jinde → poslední stav (leaf i AI dle
     D7); reload → paměť čistá, první vstup zas AI;
  5. záložky: leaf naviguje, skupina (reporty) dropdown s úrovní 3,
     aktivní stavy, jeden otevřený;
  6. „Nová konverzace" v AI záložce, „Otevřít v Chatu" přenese vlákno;
     souběh s ChatPanelem = totéž vlákno (zdokumentované chování);
  7. paleta z railu i Ctrl/Cmd+K; navigace paletou do jiné sekce →
     rail i záložky se srovnají, AI se nevnucuje (R3);
  8. domeček: `_top` leafy, přistání na dashboardu, bez AI záložky;
  9. settings mód → sidebar-style, návrat → wild s pamětí záložek (R2);
  10. mobil → dnešní mobilní chrome; classic/sidebar uživatelé nedotčeni;
  11. konzole bez warningů.

## Strategie commitů

1. `feat(server): allow wild shell (#45)`
2. `feat(frontend): wild shell — section rail, nav tab strip (#45)`
3. `feat(frontend): section assistant tab (#45)`
4. `docs: frontend, ui-shells, chat, app-settings — wild shell (#45)`

Commity průběžně; push dělá David.

## Hotovo když

- [ ] `wild` v allowlistu, registry a volbě (segmenty)
- [ ] rail + záložky dle D1/D2 (badge vč. domečku, dropdown skupin)
- [ ] AI záložka: přistání dle D3/D7 (paměť posledního stavu, reload
      čistí), navázání na poslední scoped konverzaci (D4)
- [ ] externí navigace srovnává stav (R3), settings roundtrip drží
      paměť (R2)
- [ ] PHPUnit + frontend testy zelené, build + check:i18n čisté
- [ ] smoke 1–11 prošel
- [ ] dokumentace (4 soubory) aktualizovaná
- [ ] komentář v issue #45: Fáze 6 hotová (odkaz na commity) + zvážit
      uzavření issue (všech 6 fází hotových; průběžný bod sloučení
      `basic` → `_top` vede samostatně)
