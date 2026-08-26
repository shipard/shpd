# UI shells — Fáze 4: shell `classic` + volba shellu

**Status:** připraveno k implementaci
**Issue:** [#45](https://github.com/shipard/shpd/issues/45) (zastřešující)
**Design doc:** `docs/ui-shells.md` §11, §12
**Návaznost:** Fáze 1 (primitivy, `activeSection`), Fáze 2 (paleta —
trigger v top baru, `groupLabel` v tooltipech), Fáze 3 (badge — render
v horním menu). Server + frontend, jeden repozitář.

## Cíl

První alternativní shell: **classic** (horní menu agend + levý pás ikon
s flyouty, styl starého Shipardu) a **volba shellu** per DS/user po vzoru
vzhledu (theme Fáze 4).

## Uzavřená rozhodnutí (z návrhové diskuse)

- **D1 — klik na sekci aktivuje první leaf sekce** (potvrzeno = chování
  starého Shipardu). Budoucí `defaultItem` v `navSections.jsonc` možný,
  ve v1 ne.
- **D2 — `_top` = „domeček":** první položka horního menu, jen ikona bez
  textu; jeho leafy v levém pásu. Badge `_top` z Fáze 3 na domečku,
  badge sekcí na položkách menu.
- **D3 — volba shellu po vzoru theme:** `app.shell` (scope ds, přes
  Uložit) + `account.shell` (scope user, `{follow:true}` |
  `{follow:false, shell, params}`, okamžité uložení mimo Uložit).
  DS default v `/_app/info`. Efektivní = `follow ? (DS default ?? 'sidebar')
  : override`; neznámé jméno → fallback `sidebar`. `params` v1 prázdné.
- **D4 — přepnutí soft swapem:** výměna komponenty shellu v AppShellu,
  žádný reload; `navigationStore` přežije (uživatel zůstane, kde byl).
  Reload jen jako únikový východ při zádrhelu.
- **D5 — mobil (<768 px) volbu ignoruje:** pod breakpointem vždy dnešní
  mobilní chrome. Classic je desktop-only do fáze „MobileShell".
- **D6 — módy settings/account vždy v sidebar-style chrome** (řeší se na
  úrovni resolveru, viz R2) — classic ovlivňuje jen app mód.
- **D7 — screen surface v classicu na desktopu nemá region** (obsah si
  kreslí vlastní toolbary; beze změny).
- **D8 — levý pás = uzly úrovně 2 aktivní sekce** (dle screenshotu
  starého Shipardu): leaf naviguje, skupina otevírá flyout s úrovní 3
  (vč. oddělovačů podskupin). Nový primitiv `NavFlyoutStrip`
  (`NavIconStrip` z Fáze 1 zůstává beze změny pro collapsed sidebar).
- **D9 — flyout klikem** (ne hoverem), zavření výběrem/Esc/klikem mimo,
  aktivní leaf zvýrazněný. Interakce dle vzoru `ui/Popover` + pasti
  `frontend.md` §9.
- Overflow „malé ikony dole" ze starého Shipardu ve v1 neřešíme — pás
  scrolluje; poznámka do design docu.

## Před implementací přečti

- `docs/ui-shells.md` §4, §11, §12 + screenshot chování v issue diskusi
- `docs/app-settings.md` — field typy `theme`/`language`, `/_app/info`,
  user-scope okamžité ukládání vs. DS-scope Uložit
- `modules/core/system/module.jsonc` — definice `app.theme` (ř. ~87)
  a `account.theme` (ř. ~117) — vzor pro shell pole
- `src/Api/Controller/SettingsController.php` — validace typu `theme`
  (ř. ~233), vzor pro typ `shell`
- `src/Api/Controller/AppController.php` — skladba `/_app/info` (ř. ~82)
- `frontend/src/stores/theme.svelte.js` — follow/override/dsDefault vzor
  (bez anti-flash částí — shell tokeny nemá)
- `frontend/src/components/layout/AppShell.svelte` — celý (dělí se)
- `frontend/src/components/settings/ThemeField.svelte`,
  `DsThemeField.svelte`, `LanguageField.svelte` — vzory settings widgetů
- `frontend/src/components/ui/Popover.svelte` + `docs/frontend.md` §9
- `frontend/src/components/chrome/*` — primitivy Fáze 1

## Rozhodnutí v tomto PRD

- **R1 — server drží allowlist shellů:** `SettingsController`, konstanta
  `SHELLS = ['sidebar', 'classic']` ve validaci typu `shell` (nový shell
  = přidat jméno; chrání DS config před překlepy). Klientský fallback
  na `sidebar` dle D3 zůstává druhou pojistkou.
- **R2 — resolver v AppShellu:**
  `ClassicShell` jen když `!isMobile && mode === 'app' && effective === 'classic'`;
  jinak `SidebarShell`. Tím jsou D5 i D6 vyřešené jedním výrazem —
  `SidebarShell` v settings/account módu renderuje settings strom
  + ModeBackBar jako dnes.
- **R3 — struktura souborů:** `frontend/src/components/shells/` —
  `SidebarShell.svelte` (extrakce layout části AppShellu 1:1, obě větve
  mobil/desktop), `ClassicShell.svelte`, `classic/TopMenuBar.svelte`
  (shell-privátní kompozice primitiv); `chrome/NavFlyoutStrip.svelte`
  (sdílený primitiv). Registry mapa `shells/index.js`
  (`{sidebar, classic}`).
- **R4 — shell store bez anti-flash mechaniky:** `stores/shell.svelte.js`
  po vzoru theme (`follow`, `override`, `dsDefault` z appInfo,
  `effective`), localStorage `shpd_shell` (`'follow'` | jméno)
  a `shpd_ds_shell` (cache DS defaultu) jen jako boot cache — bez
  tokenů/CSS. Špatná cache = jeden swap po načtení appInfo, přijatelné.
  Čistá funkce `resolveShell(follow, override, dsDefault, known)` v
  `utils/shell.js` (unit-testovatelná; `known` = klíče registry).
- **R5 — ThemePanel zůstává v AppShellu;** prop `collapsed` se zobecní
  na `leftOffset` (px): `SidebarShell` ho binduje dle collapsed stavu,
  `ClassicShell` dává konstantu šířky pásu. (Původní důvod umístění —
  transform draweru — platí dál, drawer je teď uvnitř SidebarShellu.)
- **R6 — TopMenuBar:** zleva `BrandingHeader`, domeček (`_top`), sekce
  z `global.navSections` (pořadí konfigurace), vpravo trigger palety
  a `UserMenu` (compact). Aktivní sekce = `navigationStore.activeSection`
  (domeček aktivní při `null` + app módu). Badge z `sectionBadgesStore`
  (shell čte store přímo — shelly nejsou hloupé primitivy).
- **R7 — klik na sekci (D1):** naviguje na první leaf sekce
  (depth-first přes `flattenLeaves` podstromu); prázdná sekce se v menu
  vůbec nezobrazí (konzistentní se sidebar chováním, který prázdné
  skupiny taky nerenderuje — ověřit a případně sjednotit).
- **R8 — NavFlyoutStrip:** props `nodes` (úroveň 2 aktivní sekce),
  `activeId`, `onNavigate`. Leaf = ikona + popisek, klik naviguje;
  skupina = totéž + klik otevírá `Popover` flyout s úrovní 3
  (podskupiny uvnitř skupiny oddělovačem, jako screenshot). Skupina
  aktivní, když aktivní leaf leží v ní. Vždy max jeden otevřený flyout.
- **R9 — settings widgety:** `ShellField.svelte` (account scope —
  segmenty „Podle aplikace / Sidebar / Klasické", okamžité uložení,
  vzor ThemeField/LanguageField) a `DsShellField.svelte` (ds scope,
  přes Uložit, vzor DsThemeField). Umístění na stejné stránky jako
  theme pole (`module.jsonc` core/system). Přepnutí přes store = soft
  swap ihned (D4).

## Scope — po souborech

### Server

**`modules/core/system/module.jsonc`**
- `appSettings` stránka s `app.theme`: přidat pole `app.shell`
  (type `shell`); account stránka s `account.theme`: přidat
  `account.shell` (type `shell`). Popisky cs/en dle vzoru theme polí.

**`src/Api/Controller/SettingsController.php`**
- Nová větev `$type === 'shell'` (za `theme`): user scope
  `{follow:true}` → uložit tak; jinak `{follow:false, shell, params}` —
  `shell` ∈ `SHELLS` (R1), `params` objekt (v1 propustit prázdný/objekt);
  DS scope follow zahazuje (vzor theme). Nevalidní → `INVALID_VALUE`.

**`src/Api/Controller/AppController.php`**
- `/_app/info`: `'shell' => is_array($values['app.shell']) ? … : null`
  (jen `{shell, params}` bez follow — vzor theme na ř. ~82).

### Frontend — nové

**`frontend/src/utils/shell.js`** — `resolveShell()` dle R4.

**`frontend/src/stores/shell.svelte.js`** — R4 (follow/override/dsDefault/
`effective`, `setFollow()`, `setOverride(name)`, `setDsDefault(v)`
volaný z appInfo flow — vzor `themeStore.setDsDefault`).

**`frontend/src/components/shells/index.js`** — registry mapa.

**`frontend/src/components/shells/SidebarShell.svelte`**
- Extrakce dnešní layout části AppShellu **1:1**: mobilní větev
  (MobileTopBar + overlay + drawer + main) i desktopová (Sidebar +
  main), `handleNavigate` (zavírání draweru), CSS `shpd-shell__*`
  bloků, které se stěhují. Bindable `themePanelLeftOffset` (R5).

**`frontend/src/components/shells/ClassicShell.svelte`**
- Grid: `TopMenuBar` nahoře, `NavFlyoutStrip` vlevo (nodes = úroveň 2
  `activeSection`, resp. `_top` leafy při domečku), `ContentArea`.
  Desktop-only (resolver R2 zaručuje). `themePanelLeftOffset`
  konstanta.

**`frontend/src/components/shells/classic/TopMenuBar.svelte`** — R6, R7.

**`frontend/src/components/chrome/NavFlyoutStrip.svelte`** — R8.

**`frontend/src/components/settings/ShellField.svelte`,
`DsShellField.svelte`** — R9.

### Frontend — změny

**`frontend/src/components/layout/AppShell.svelte`**
- Zůstávají globální starosti: paleta + zkratka, ChatPanel, ThemePanel
  (s `leftOffset` dle R5), polling badge, `openThemePanel` passthrough.
- Resolver R2: `{#if useClassic}<ClassicShell …>{:else}<SidebarShell …>{/if}`
  (příp. `<svelte:component>` nad registry — dle čitelnosti).

**`frontend/src/components/layout/Sidebar.svelte`** — beze změny chování;
jen pokud extrakce vyžádá kosmetiku propů, minimálně.

**appInfo flow** (`stores/appInfo.svelte.js` / místo, kde se volá
`themeStore.setDsDefault`) — doplnit `shellStore.setDsDefault(info.shell)`.

**`frontend/src/components/settings/SettingsPage.svelte`** (příp. field
dispatch) — registrace typu `shell` → ShellField/DsShellField dle scope
(vzor typu `theme`).

**i18n** — klíče: názvy shellů, „Podle aplikace", popisky polí, aria
domečku a flyoutů. `check:i18n` čisté.

### Dokumentace

- `docs/app-settings.md`: field typ `shell`, pole `app.shell` /
  `account.shell`, `/_app/info` rozšíření.
- `docs/frontend.md`: sekce Shelly (resolver, registry, SidebarShell /
  ClassicShell, NavFlyoutStrip), aktualizace zmínek o AppShellu.
- `docs/ui-shells.md`: §11 → realizováno; §12 řádek classic — opravit
  popis pásu na úroveň 2 + flyouty (dle screenshotu), poznámka
  o overflow ikonách („starý Shipard měl, v1 neřeší, pás scrolluje");
  §4 checklist — classic odškrtnout.

### Mimo scope

- `params` shellů (hustota…), wild shell, MobileShell, overflow ikony,
  `defaultItem` sekcí, surface region na desktopu.

## Testy

- **PHPUnit** (filtr `"SettingsController|AppController"`):
  typ `shell` — follow (user), override validní/nevalidní jméno
  (allowlist), DS scope zahazuje follow, `params` passthrough;
  `/_app/info` obsahuje `shell` (a `null` bez konfigurace).
- **Frontend unit:** `utils/shell.test.mjs` — follow s/bez DS defaultu,
  override, neznámé jméno → sidebar, garbage vstupy.
- **Build + i18n** čisté.
- **Manuální smoke (dev):**
  1. default (bez konfigurace): sidebar shell, vše jako dřív;
  2. Nastavení účtu → shell „Klasické": okamžitý soft swap, aktivní
     položka zůstala; zpět „Podle aplikace" → sidebar;
  3. DS default `classic` (Nastavení aplikace, Uložit): follow uživatel
     po reloadu v classicu; override uživatel nedotčen;
  4. classic: horní menu — domeček + sekce, aktivní zvýraznění, klik
     na sekci → první leaf; prázdné sekce skryté;
  5. pás: leaf naviguje; skupina otevře flyout (úroveň 3, oddělovače),
     výběr naviguje a zavře, Esc/klik mimo zavře, jediný otevřený;
     aktivní stavy (leaf, skupina s aktivním leafem, flyout položka);
  6. badge: warning/danger na sekci menu, `_top` badge na domečku;
  7. paleta: trigger v top baru, Ctrl/Cmd+K funguje, navigace z palety
     přepne sekci správně (activeSection derivace);
  8. vstup do Nastavení/Účtu z classicu → sidebar-style chrome
     s ModeBackBar, návrat zpět do classicu na původní položku;
  9. zúžení okna pod 768 px v classicu → mobilní chrome (drawer),
     rozšíření → zpět classic;
  10. ThemePanel z classicu otevře se správným odsazením;
  11. neznámé jméno shellu v localStorage/configu → sidebar fallback,
      konzole bez chyb;
  12. konzole bez warningů.

## Strategie commitů

1. `feat(server): shell settings type, app.shell/account.shell, app info (#45)`
2. `feat(frontend): shell store, resolver, extract SidebarShell (#45)`
3. `feat(frontend): classic shell — top menu, flyout strip (#45)`
4. `feat(frontend): shell choice fields in settings (#45)`
5. `docs: app-settings, frontend, ui-shells — shells (#45)`

Commity průběžně; push dělá David.

## Hotovo když

- [ ] typ `shell` validovaný (allowlist), pole v module.jsonc,
      `/_app/info` nese DS default
- [ ] resolver dle R2 (mobil + módy vždy sidebar-style), soft swap bez
      ztráty aktivní položky
- [ ] SidebarShell = extrakce 1:1 (UI beze změny v defaultu)
- [ ] classic: menu + domeček + pás s flyouty dle D1/D2/D8/D9,
      badge vč. `_top`
- [ ] volba v Nastavení účtu (okamžitě) i aplikace (Uložit)
- [ ] PHPUnit filtr zelený, frontend testy (vč. `shell.test.mjs`),
      build + check:i18n čisté
- [ ] smoke 1–12 prošel
- [ ] dokumentace (3 soubory) aktualizovaná
- [ ] komentář v issue #45: Fáze 4 hotová (odkaz na commity)
