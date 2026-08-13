# Hosting 07b — Alerts viewer pro adminy + dashboard module guards

**Stav:** hotovo

Implementováno 2026-08-13 (body 1–6, PHPUnit + check:i18n + build zelené,
ds-upgrade na gn5c i 4l3j proveden). Zbývá ruční proklik v prohlížeči
(hosting DS admin/ne-admin, běžný DS regrese).

**Návaznost:** hosting-07 (portál v shellu, D4 explicitní `adminOnly` na
viewerech). Opravuje dva nálezy z ověření hosting-07 + jeden latentní bug
nezávislý na portálu (dashboard padá na DS bez `core.mail`).

## Cíl

1. Viewer Upozornění (`core.alerts.alerts`) skrýt z navigace ne-adminům
   **globálně** — alerty ne-admin vidí a obsluhuje přes dashboard feed.
2. Dashboard nesmí padat na DS, kde neběží modul některého feed zdroje
   (dnes: hosting DS bez `core.mail` → Dibi exception → „Načtení
   dashboardu selhalo" pro všechny uživatele včetně admina).
3. Dashboard nekreslí ovládací prvky funkcí, které na DS neexistují nebo
   uživateli nepatří (tlačítko Nahrát, drag&drop upload, ChatLauncher);
   navigace neemituje Chat leaf na DS bez `core.chat`.

## Schválená rozhodnutí (2026-08-13)

| # | Rozhodnutí |
|---|---|
| D7 | Viewer `core.alerts.alerts` dostane `"adminOnly": true` na deklaraci v module.jsonc (explicitní vrstva z hosting-07 D4). Tabulka `core_alerts_alerts` **zůstává bez** `adminOnly` — dashboardové alert karty nesou akce `open_viewer`/`open_form` na alerts viewer/form a ne-adminovi musí dál fungovat. Vědomá sémantika: viewer-level `adminOnly` = úklid navigace, ne serverová bariéra (data ne-admin beztak čte přes feed); zdokumentovat. |
| D8 | Feed zdroje degradují podle přítomnosti modulu: zdroj se vůbec nezaregistruje, když jeho tabulky na DS nejsou. K tomu per-source izolace — výjimka jednoho zdroje se zaloguje a feed pokračuje ostatními zdroji (error-tolerance, vzor accounting). |
| D9 | Dashboard response ponese `capabilities: {mailUpload, chat}` odvozené serverem; frontend podle nich skryje Nahrát + drag&drop (mailUpload) a ChatLauncher (chat). `chat` capability zohledňuje i pravidlo D5 z hosting-07 (ne-admin + aktivní hosting → false), aby launcher neobcházel skrytý nav leaf. |
| D10 | Hardcoded root leaf Chat v navigaci se emituje jen při aktivním `core.chat` (přítomnost `core_chat_conversations` v `$tables`) — dnes ho má i admin na hosting DS jako mrtvou položku padající na chybějící tabulce. Kombinuje se s D5 (ne-admin + hosting → skrýt i při aktivním chatu). |

## Scope

**Patří sem:** alerts module.jsonc, DashboardController + dispatch
(`$tables`, `$auth`), NavigationController (Chat leaf gating D10),
frontend Dashboard.svelte (capabilities), docs, testy.

**Nepatří sem:** změny feed karet/alert checků, chování chatu samotného,
`/_chat/*` endpointy (po skrytí vstupních bodů se na DS bez chatu nikdo
legitimně nedostane; hloubková degradace chat API = mimo scope), RBAC.

## Změny po souborech

### 1. `modules/core/alerts/module.jsonc`

Na deklaraci vieweru `core.alerts.alerts` přidat `"adminOnly": true`.
Nic jiného (tabulka beze změny, D7).

### 2. `public/index.php` — `dispatchDashboard()`

Rozšířit signaturu o `array $tables` a `AuthContext $auth` a předat
z dispatch místa (ř. ~283) do `$ctrl->dashboard(...)` i `$ctrl->summary(...)`.

### 3. `src/Api/Controller/DashboardController.php`

a) **Podmíněná registrace zdrojů (D8).** `collectCards()` dostane
`$tables`; zdroje se registrují podle přítomnosti klíčové tabulky:
   - `MailSuggestionsSource`, `MailDigestSource` — jen když
     `isset($tables['core_mail_incoming_messages'])`,
   - `AlertsSource` — jen když `isset($tables['core_alerts_alerts'])`.
   Mapování tabulka→zdroj drží DashboardController (zdroje jsou napevno
   registrované — dashboard.md D10; žádné nové rozhraní na FeedSource).

b) **Per-source izolace (D8).** Smyčka přes zdroje v `collectCards()`
obalí `$src->collectCards($ctx)` do try-catch: `\Throwable` →
`ErrorLogger::logException($e, "Dashboard feed source failed", ['source' => $class])`
a pokračuje dalším zdrojem. Dashboard se nevrátí 500, dokud funguje
aspoň jeho obálka.

c) **Capabilities (D9).** `dashboard()` (ne `summary()` — shrnutí
capabilities nepotřebuje) přidá do response:

```php
'capabilities' => [
    'mailUpload' => isset($tables['core_mail_incoming_messages']),
    'chat'       => isset($tables['core_chat_conversations'])
                    && ($auth->isAdmin || !isset($tables['hosting_core_data_sources'])),
],
```

Detekce hostingu = stejný idiom jako NavigationController (hosting-07).
`summary()` dostane `$tables` jen kvůli sdílenému `collectCards()`.

### 4. `src/Api/Controller/NavigationController.php`

Chat root leaf (D10): podmínku z hosting-07
(`!$isAdmin && hosting aktivní` → skip) rozšířit — leaf se emituje jen
když `isset($tables['core_chat_conversations'])` **a zároveň** projde
D5 pravidlo. Výsledné pravidlo: `chat aktivní && ($isAdmin || hosting
neaktivní)` — identické s capability `chat` v bodu 3c (záměrně; udržet
oba výrazy stejné, ideálně s odkazem v komentáři).

### 5. `frontend/src/components/dashboard/Dashboard.svelte`

Z `fetchDashboard()` výsledku převzít `capabilities` do stavu
(default při chybějícím poli: `{mailUpload: true, chat: true}` —
zpětná kompatibilita během deploye, server je starší než frontend jen
přechodně):
- `mailUpload === false` → nerenderovat tlačítko Nahrát,
  `MailUploadModal` ani drag&drop handlery/overlay (drag&drop otevírá
  tentýž modal — musí zmizet obojí),
- `chat === false` → nerenderovat `<ChatLauncher/>`.

### 6. Dokumentace

- `docs/modules.md` (sekce viewers/panels): doplnit sémantiku
  viewer-level `adminOnly` — skrývá položku z navigace ne-adminům,
  **není** serverová bariéra (tou je table-level `adminOnly` /
  `core_system_` prefix vynucované `TableAccessGuard`); typický případ
  = viewer dosažitelný ne-adminům jinou cestou (dashboard karta).
- `docs/dashboard.md`: degradace zdrojů dle modulů, per-source
  izolace, pole `capabilities` v response.
- `docs/hosting.md` stavový blok: řádek o 07b.

## Testy

PHPUnit (úzké `--filter`):

1. **DashboardController — degradace:** bez `core_mail_incoming_messages`
   v `$tables` se mail zdroje nezaregistrují a response je 200 s kartami
   zbylých zdrojů; bez `core_alerts_alerts` totéž pro AlertsSource.
2. **DashboardController — izolace:** zdroj vyhazující výjimku
   (test double) feed neshodí; karty ostatních zdrojů se vrátí.
3. **DashboardController — capabilities:** čtyři kombinace
   (mail ±, chat ±) + chat=false pro ne-admina s aktivním hostingem
   navzdory aktivnímu `core.chat`; admin s hostingem chat=true.
4. **NavigationController — Chat leaf (D10):** bez `core_chat_conversations`
   Chat chybí i adminovi; s chatem platí D5 matice z hosting-07
   (rozšířit stávající test 5).
5. **Navigace — alerts viewer (D7):** ne-admin nedostane
   `viewer:core.alerts.alerts` na žádném DS; admin ano.

Frontend: ruční ověření + `npm run check:i18n` (nové klíče se nečekají,
ale kontrola je levná).

## Strategie commitů

1. `dashboard: module-aware feed sources + per-source isolation + capabilities` (body 2, 3, testy 1–3)
2. `nav: chat leaf requires core.chat` (bod 4, test 4)
3. `alerts: admin-only viewer in navigation` (bod 1, test 5)
4. `frontend: dashboard capabilities (upload, chat launcher)` (bod 5)
5. `docs: adminOnly viewer semantics, dashboard degradation` (bod 6)

Změna module.jsonc (commit 3) je jen metadata vieweru — `ds-upgrade`
na dev DS spustit pro jistotu po nasazení (schéma se nemění).

## Hotovo když

- [ ] Na hosting DS (bez `core.mail`/`core.chat`) se dashboard načte
      bez chyby adminovi i ne-adminovi; feed obsahuje alert karty.
- [ ] Na hosting DS nikdo nevidí tlačítko Nahrát, drag&drop overlay
      ani ChatLauncher; admin nemá v sidebaru Chat leaf.
- [ ] Na běžném DS s `core.mail` + `core.chat` se dashboard, upload,
      drag&drop, ChatLauncher i Chat leaf chovají beze změny.
- [ ] Ne-admin nevidí Upozornění v navigaci na žádném DS; alert karty
      na dashboardu mu fungují včetně akcí (`open_viewer`/`open_form`).
- [ ] V `shipard.log` nepřibývají Dibi chyby z `/_ui/dashboard` na
      hosting DS.
- [ ] PHPUnit testy 1–5 zelené (úzký --filter), `check:i18n` prochází.
- [ ] Dokumentace (modules.md, dashboard.md, hosting.md) aktualizovaná.
