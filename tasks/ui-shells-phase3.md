# UI shells — Fáze 3: Badge stavů sekcí

**Status:** připraveno k implementaci
**Issue:** [#45](https://github.com/shipard/shpd/issues/45) (zastřešující)
**Design doc:** `docs/ui-shells.md` §8
**Návaznost:** Fáze 1 (NavTree primitiv), nezávislé na Fázi 2. První fáze
se serverovou částí (PHP + frontend, jeden repozitář).

## Cíl

Signalizace „v této sekci na tebe něco čeká": serverová agregace dashboard
feedu per navigační sekce + pilot v současném sidebaru (tečka s počtem na
hlavičkách sekcí). Data jsou shell-nezávislá — classic/wild shelly je později
jen vykreslí jinde.

## Uzavřená rozhodnutí (z návrhové diskuse)

- **D1 — atribuce per karta:** nové volitelné pole `navSection` v kartovém
  kontraktu, plní zdroj (zdroj zná doménu; u alertů se liší per check).
  Karta bez pole se do badge **nepočítá** (explicitní opt-in, žádná
  heuristika přes `category` — ta řídí chips filtru, jiný účel).
- **D2 — jen `urgent` a `review`:** severity urgent → `danger`,
  review → `warning`; sekce = součet count + max severity. `ready`/`info`
  se nepočítají (trvale svítící badge není signál).
- **D3 — samostatný endpoint `GET /_ui/section-badges`** (jiná kadence než
  strom navigace). Odpověď: `{sections: {"<sectionId>": {count, severity}}}`,
  jen neprázdné sekce; `_top` je platný klíč.
- **D4 — extrakce `FeedCollector`:** sběr karet z `DashboardController` do
  služby `Core\Feed\FeedCollector`; dashboard i badges ji sdílejí
  („one calculation, N presentations"). Fáze 5 (výsek feedu per sekce) ji
  bude potřebovat také. Bez serverové cache ve v1; budoucí optimalizace
  = `countOnly` režim ve `FeedContext` (jen poznámka, neřešit).
- **D5 — klient:** store `sectionBadges.svelte.js`, polling à 60 s +
  refresh při focusu okna, spouští AppShell. Pilot: badge na hlavičkách
  root skupin-sekcí v rozbaleném NavTree (⇒ i v mobilním draweru).
  Collapsed režim v1 bez badge (vyřeší Fáze 4).
- **D6 — `_top` v sidebar pilotu nerenderovat** (položky jsou trvale
  viditelné, dashboard je landing page); data v odpovědi jsou, classic/wild
  je použijí na „domečku". Případný leaf-level badge pošty = budoucí
  zjemnění dle zkušenosti.

## Před implementací přečti

- `docs/ui-shells.md` §8, `docs/dashboard.md` (kartový kontrakt, §3
  architektura, `sortAndCap`)
- `src/Api/Controller/DashboardController.php` — celý (extrahuje se z něj)
- `src/Core/Feed/FeedSource.php`, `src/Core/Feed/FeedContext.php`
- `modules/core/alerts/src/Feed/AlertsSource.php` — `buildCard`,
  `buildGroupCard`, použití `$this->registry`
- `src/Core/Alerts/AlertCheckDefinition.php` + `AlertCheckRegistry`
- `src/Api/Router.php` — vzor `/_ui/*` rout
- `frontend/src/components/chrome/NavTree.svelte`, `Sidebar.svelte`,
  `AppShell.svelte` (start pollingu)
- `docs/design-system.md` — tokeny barev pro severity

## Rozhodnutí v tomto PRD

- **R1 — endpoint jako akce `DashboardController::sectionBadges()`**
  (route `Route('dashboard', 'sectionBadges')`). Po extrakci FeedCollectoru
  je DashboardController „prezentační vrstvy feedu" — badges je další
  prezentace. Žádný nový controller.
- **R2 — `AlertCheckDefinition` dostane volitelný `navSection`**
  (`?string`, default `null`) z `alertChecks` bloku `module.jsonc`;
  validace formátu `[a-z_][a-z0-9_]*` ve `fromArray` (povolit `_top`).
  `AlertsSource` doplní `navSection` do `buildCard` i `buildGroupCard`
  lookupem `registry?->get($checkId)?->navSection` (null-safe — bez
  registry bez pole, chování beze změny).
- **R3 — přiřazení sekcí existujícím zdrojům:**
  `MailSuggestionsSource`, `MailDigestSource` → `'_top'`;
  `ContentTagSuggestionsSource` → `'basic'` (pozn.: po plánovaném sloučení
  Základní → `_top` se změní na `'_top'` — připsat do checklistu té úpravy);
  `AlertsSource` → per check (R2). Existujícím `alertChecks` definicím
  v `module.jsonc` doplnit `navSection` dle domény modulu (implementátor
  najde: `grep -rn "alertChecks" modules/*/*/module.jsonc`); mailové checky
  → `_top`, účetní → `accounting`. Check bez jasné domény nechat bez pole.
- **R4 — agregace ve FeedCollectoru:** metoda
  `FeedCollector::sectionBadges(array $cards): array` — čistá funkce nad
  kartami (testovatelná bez DB): filtr kind ∈ {urgent, review}
  + `navSection` != null, součet, max severity (danger > warning).
  Controller akce = collect + sectionBadges + JSON.
- **R5 — NavTree je hloupý:** badge data dostane propem
  `sectionBadges` (mapa) ze Sidebaru (ten čte store); renderuje jen na
  root uzlech-sekcích s neprázdným záznamem, `_top` klíč ignoruje
  přirozeně (není uzlem stromu). Vzhled: tečka v barvě severity + počet
  (99+ cap), tokeny `--color-danger`/`--color-warning` dle
  design-system.md (přesné názvy ověřit), `aria-label` s počtem.
- **R6 — polling:** `setInterval` 60 s + listener `focus`; při
  `document.hidden` tick přeskočit. Start/stop řídí AppShell (`$effect`
  s cleanup). Chyba fetche = ponechat poslední známý stav, nelogovat
  do UI (tichá degradace, vzor AI shrnutí).

## Scope — po souborech

### Server — nové

**`src/Core/Feed/FeedCollector.php`**
- Přesun z `DashboardController`: registrace zdrojů (D10 napevno),
  `collect(FeedContext …): array` (dnešní `collectCards` bez
  readySummary — ten je dashboard-specifický a zůstává v controlleru),
  `sortAndCap()`, `countByKind()`, `stripInternalFields()` — vše co není
  prezentace. Signatury a chování 1:1 (čistý přesun), controller metody
  smazat a volat službu.
- Nová `sectionBadges(array $cards): array` dle R4.

### Server — změny

**`src/Api/Controller/DashboardController.php`**
- `dashboard()` a `summary()` přepnout na `FeedCollector`;
  `buildReadySummary`, `andMoreCard`, SSE zůstávají.
- Nová akce `sectionBadges()`: collect (plný `FeedContext` jako dashboard)
  → `FeedCollector::sectionBadges()` → `{sections: …}`.

**`src/Api/Router.php`**
- `GET /_ui/section-badges` → `Route('dashboard', 'sectionBadges')`
  (vzor sousedních `/_ui/*` rout, stejný auth režim jako `/_ui/dashboard`).

**`src/Core/Feed/FeedSource.php`**
- Docblock kartového kontraktu: doplnit `navSection?` (+ sémantika D1/D2).

**`src/Core/Alerts/AlertCheckDefinition.php`** — R2 (konstruktor,
`fromArray`, validace).

**`modules/core/mail/src/Feed/MailSuggestionsSource.php`,
`MailDigestSource.php`** — `'navSection' => '_top'` do karet.

**`modules/core/exchange/src/Dashboard/ContentTagSuggestionsSource.php`**
- `'navSection' => 'basic'` (+ komentář o budoucím sloučení do `_top`).

**`modules/core/alerts/src/Feed/AlertsSource.php`** — R2 lookup
v `buildCard` + `buildGroupCard`.

**`modules/*/*/module.jsonc` s `alertChecks`** — doplnit `navSection`
dle R3.

### Frontend — nové

**`frontend/src/stores/sectionBadges.svelte.js`**
- `$state badges` (mapa sekcí), `startPolling()`/`stopPolling()` (R6),
  `refresh()` (fetch `/_ui/section-badges` přes `apiGet`).

### Frontend — změny

**`frontend/src/components/layout/AppShell.svelte`**
- `$effect`: start polling při mountu, cleanup stop.

**`frontend/src/components/layout/Sidebar.svelte`**
- Předat `sectionBadges={sectionBadgesStore.badges}` do `NavTree`
  (jen app mód; settings/account strom badge nemá).

**`frontend/src/components/chrome/NavTree.svelte`**
- Prop `sectionBadges = {}`; render badge na root skupinách dle R5.
  CSS s komponentou (`shpd-navtree__badge`).

**i18n** — `aria-label` klíč (cs + en), `npm run check:i18n`.

### Dokumentace

- `docs/dashboard.md`: kartový kontrakt + `navSection`, architektura §3
  (FeedCollector), odkaz na badges.
- `docs/rest-api.md`: `GET /_ui/section-badges` (tvar odpovědi, auth).
- `docs/ui-shells.md` §8 → „realizováno Fází 3" + finální tvar.
- `docs/frontend.md`: podsekce badge v sidebaru (store, polling, pilot
  jen rozbalený strom).
- `docs/alerts.md`: `navSection` v definici checku.

### Mimo scope

- Serverová cache / `countOnly` režim (budoucí optimalizace).
- Badge v collapsed pásu ikon (Fáze 4), leaf-level badge pošty (dle
  zkušenosti), SSE push místo pollingu.
- Sloučení sekce `basic` do `_top` (samostatná průběžná úprava).

## Testy

- **PHPUnit** (narrow filtry, `timeout_sec: 120`):
  - `tests/Unit/Core/Feed/FeedCollectorTest.php` — `sectionBadges()`:
    počítá jen urgent/review, ignoruje karty bez `navSection`, max
    severity (danger > warning), součty per sekce, `_top` jako klíč,
    prázdný feed → prázdná mapa; přesunuté `sortAndCap`/`countByKind`
    testy (pokud dnes žijí v testu controlleru, přestěhovat);
  - `AlertCheckDefinitionTest` — `navSection` volitelný, validace
    formátu, chyba na nevalidní hodnotu;
  - test AlertsSource/controller upravit dle přesunů (existující testy
    musí zůstat zelené: filtr `"FeedCollector|AlertCheckDefinition|Dashboard|AlertsSource"`).
- **Frontend:** `npm run build` + `check:i18n`; store bez čisté logiky
  k unit testování (agregace je serverová) — pokrývá smoke.
- **Manuální smoke (dev):**
  1. DS s aktivním warning alertem s `navSection: accounting` →
     oranžová tečka s počtem u Účtárny v rozbaleném sidebaru;
  2. urgentní karta téže sekce → tečka červená (max severity), počet
     = součet;
  3. vyřešení alertu + další tick (či focus okna) → badge zmizí;
  4. mobilní drawer ukazuje badge; collapsed desktop nikde;
  5. settings/account mód bez badge; `_top` se nikde nerenderuje;
  6. `GET /_ui/section-badges` bez tokenu → 401; odpověď obsahuje jen
     neprázdné sekce;
  7. dashboard funguje beze změny (refaktor collectoru nic nerozbil),
     AI shrnutí (SSE) beze změny;
  8. konzole bez warningů; síť: polling à 60 s, pauza při skryté kartě.

## Strategie commitů

1. `refactor(server): extract FeedCollector from DashboardController`
2. `feat(server): navSection on feed cards and alert checks (#45)`
3. `feat(server): GET /_ui/section-badges (#45)`
4. `feat(frontend): section badges store + sidebar pilot (#45)`
5. `docs: dashboard, rest-api, alerts, frontend — section badges`

Commity průběžně; push dělá David.

## Hotovo když

- [ ] `FeedCollector` extrahovaný, dashboard + summary beze změny chování
- [ ] karty nesou `navSection` dle R3, alert checky konfigurovatelné
      z `module.jsonc`
- [ ] `GET /_ui/section-badges` vrací agregaci dle D2/D3
- [ ] pilot v sidebaru: badge na sekcích v rozbaleném stromu + draweru,
      polling 60 s + focus, tichá degradace
- [ ] PHPUnit filtr `"FeedCollector|AlertCheckDefinition|Dashboard|AlertsSource"`
      zelený; build + check:i18n čisté
- [ ] smoke 1–8 prošel
- [ ] dokumentace (5 souborů) aktualizovaná
- [ ] komentář v issue #45: Fáze 3 hotová (odkaz na commity)
