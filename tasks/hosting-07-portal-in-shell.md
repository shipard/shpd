# Hosting 07 — Portál v app shellu (sjednocení UI)

**Stav:** hotovo

Implementováno 2026-08-13 (body 1–13, PHPUnit + check:i18n zelené,
ds-upgrade na dev hosting DS proveden). Zbývá ruční proklik v prohlížeči
(admin i ne-admin na hosting DS, běžný DS regrese, OIDC průlet, mobilní
drawer).

**Návaznost:** hosting.md Fáze 0–5 (hotové), zejména task 0b (PortalScreen)
a D9/D10. Tento task reviduje D10 — viz Rozhodnutí níže.

## Cíl

Zrušit oddělený celoobrazovkový portál pro ne-adminy. Všichni přihlášení
uživatelé dostanou standardní app shell (sidebar + obsah); přehled „Moje
zdroje dat" se stane běžnou položkou navigace (panel), takže ho vidí
i admin. Ne-admin dostane serverem ořezanou navigaci — navigace zrcadlí
bariéry, které na serveru už existují (TableAccessGuard, D9). Běžný
uživatel tím zadarmo získá Nastavení účtu (změna hesla, avatar, vzhled,
jazyk) a do budoucna dashboard pro hosting alerty.

## Schválená rozhodnutí (2026-08-13)

| # | Rozhodnutí |
|---|---|
| D1 | Sjednocení na AppShell pro všechny přihlášené (varianta B). Celoobrazovkový `PortalScreen` a větev `hasPortal && !isAdmin` v `App.svelte` zanikají. |
| D2 | Portálový přehled ukazuje **jen DS uživatele** z `hosting_core_ds_users` (stávající sémantika `/_hosting/portal/my-datasources`) — i pro admina. Evidenci všech DS má admin ve stávajících viewerech. Serverová strana portal endpointů se nemění. |
| D3 | Pořadí root-level leaves na hosting DS: **1. Moje zdroje dat, 2. Dashboard**, pak Chat/_top/sekce. Dashboard zůstává i ne-adminům — budoucí hosting alerty se zobrazí „zadarmo" přes existující feed. |
| D4 | Gating navigace = **model odvozeného `adminOnly`**: položka, jejíž tabulka je `adminOnly` nebo má prefix `core_system_`, se ne-adminovi nevrací (zdroj pravdy = `TableDefinition`, tj. totéž co vynucuje `TableAccessGuard`). K tomu explicitní `adminOnly: true` na deklaraci vieweru/panelu v `module.jsonc` (vzor settings pages). Žádný `portalVisible` whitelist. |
| D5 | Root leaf **Chat se ne-adminovi na DS s aktivním `hosting.core` nevrací** (mrtvá položka na dedikovaném hosting DS). Vědomý důsledek: na smíšeném DS (D11) přijde o chat i běžný zaměstnanec provozovatele — akceptováno, dedikovaný hosting DS je doporučený stav. |
| D6 | Landing po přihlášení = **první root-level leaf** vráceného stromu navigace (obecné pravidlo, žádná hosting podmínka). Na hosting DS vyjde portál, jinde Dashboard. |

Revize D10 (hosting.md): věta „ne-admin po přihlášení vidí pouze portál"
se mění na „ne-admin vidí app shell s navigací ořezanou na to, co mu
server dovolí". Bariéry byly vždy na serveru — princip se nemění, UI se
srovnává se serverem. Bonus: na smíšeném DS ne-admin nově vidí svoji
legitimní agendu (dnes viděl jen portál).

## Scope

**Patří sem:** NavigationController (auth-aware filtrace, panely
v navigaci, navOrder pro Dashboard/Chat), dispatch `/_ui/navigation`,
panel `hostingPortal` v `hosting/core/module.jsonc`, frontend refaktor
(App.svelte, PortalScreen→PortalContent, ContentArea, Sidebar user menu,
navigationStore), úklid `hasPortal`, i18n, dokumentace, testy.

**Nepatří sem:** změny `/_hosting/portal/*` endpointů, změny portálových
dat/karet, jemnější RBAC, specifické hosting alerty (budoucí task),
jakékoli chování OIDC OP / OpAuth flow (beze změny).

## Změny po souborech

### Server

#### 1. `src/Api/Controller/NavigationController.php`

Signatura `navigation()` se rozšíří o `?AuthContext $auth = null`
a `array $tables = []` (mapa `TableDefinition`, jako u dispatchSettings).

a) **Auth-aware filtrace (D4).** Nová privátní metoda
`isItemForbidden(array $item, ?array $def, bool $isAdmin, array $tables): bool`
— pro ne-admina platí item za zakázaný, když:
   - viewer/table item cílí na tabulku s prefixem
     `TableAccessGuard::SYSTEM_TABLE_PREFIX`, NEBO
   - `$tables[<table>]->adminOnly === true`, NEBO
   - deklarace vieweru/panelu v module.jsonc nese `"adminOnly": true`.
   Aplikuje se v `collectItems()` (viewery i fallback tabulky),
   v `collectProviderItems()` (přes `item['table']`, pokud provider
   tabulku uvádí) a na panelové položky (bod b). Admin: žádná filtrace
   (chování beze změny). `$auth === null` (degradovaný kontext) se
   chová jako ne-admin — fail-closed.
   Pozn.: kontrola jde přes `$tables` (runtime definice), ne přes
   opakované parsování jsonc — stejný zdroj pravdy jako
   `TableAccessGuard::guardTable()`.

b) **Panely v hlavní navigaci.** `collectItems()` nově projde
i `$module->panels`; panel, který deklaruje `navSection`, se emituje
jako item `{id: 'panel:'+panelId, label (lokalizovaný name:xx),
type: 'panel', panelId, icon?}` + interní `_section`/`_order`
z `navSection`/`navOrder`. Panely bez `navSection` se chovají jako
dnes (jen settingsItems) — plně zpětně kompatibilní. Tvar itemu
shodný se SettingsController (ř. ~636).

c) **Dashboard a Chat = řadované root leaves (D3/D6).** Zrušit
`array_unshift`; Dashboard a Chat se přidají do `$topLeaves` jako
syntetické itemy s `_order` **20** (Dashboard) a **25** (Chat) a řadí
se společně s `_top` položkami. Stávající `_top` viewery mají navOrder
30/35/40 → na běžném DS se výsledné pořadí **nemění**
(Dashboard, Chat, pošta, spisovna, úkoly, sekce).

d) **Chat gating (D5).** Syntetický Chat item se nepřidá, když
`!$isAdmin && isset($tables['hosting_core_data_sources'])`
(stejná detekce aktivního hostingu jako `AppController::hasPortal`).
Dashboard se přidává vždy (D3).

#### 2. `public/index.php` — `dispatchUi()`

Rozšířit signaturu o `AuthContext $auth` a `array $tables` a předat je
z dispatch místa (ř. ~281) do `$ctrl->navigation(...)`. Nic dalšího.

#### 3. `modules/hosting/core/module.jsonc`

Přidat sekci `panels`:

```jsonc
"panels": [
    {
        "id": "hostingPortal",
        "name": "My data sources",
        "name:cs": "Moje zdroje dat",
        "name:en": "My data sources",
        "icon": "database",
        "navSection": "_top",
        "navOrder": 10
    }
]
```

(Žádný `adminOnly` — portál vidí všichni, D1/D2.)

#### 4. `src/Api/Controller/AppController.php`

Odstranit pole `hasPortal` z `/_app/info` (po refaktoru bez konzumenta;
jediný uživatel byla větev v App.svelte). Pokud by se při implementaci
ukázal další konzument, pole ponechat a jen to poznamenat do commitu.

### Frontend

#### 5. `frontend/src/App.svelte`

Odstranit import `PortalScreen` a celou větev
`appInfoStore.hasPortal && !authStore.isAdmin` — každý přihlášený
dostane `<AppShell/>`. Větev `opAuth.txn` beze změny.

#### 6. `frontend/src/components/portal/PortalContent.svelte` (nový; `PortalScreen.svelte` se maže)

Obsahová část dnešního PortalScreen bez celoobrazovkového chrome:
- odpadá `portal__header` (branding, logout — obojí řeší shell),
  logika logout, importy `logout`/`authStore`/`brandingUrl`,
- zůstává: načtení `fetchMyDatasources`, stavy loading/error/empty,
  grid karet, freshness logika statistik (STATS_FRESH_MS, freshStats,
  statsTotal, statsTitle) — beze změny chování,
- layout jako obsahová stránka v `ContentArea` (max-width wrapper
  ponechat, top-level padding dle ostatních obsahových stránek),
- nadpis stránky = `t('portal.subtitle')` (dnešní podtitul; hlavní
  heading přebírá shell/sidebar).

#### 7. `frontend/src/components/layout/ContentArea.svelte`

Do mapy `panelComponents` přidat `hostingPortal: PortalContent`
(+ import). Dispatch `type === 'panel'` už existuje — žádná další změna.

#### 8. `frontend/src/components/layout/Sidebar.svelte`

- User menu: položku „Nastavení aplikace" renderovat jen pro
  `authStore.isAdmin` (server settings pages už chrání — jde o skrytí
  mrtvého odkazu, princip D9).
- Po loadu app navigace volat `navigationStore.ensureDefaultActiveItem(navTree)`
  (změněná signatura, bod 9).

#### 9. `frontend/src/stores/navigation.svelte.js`

`ensureDefaultActiveItem(navTree = null)` (D6): pokud
`mode === 'app' && appActiveItem === null`, vybrat **první root-level
leaf** stromu (první uzel s `type`; skupiny bez `type` přeskočit) a
navigovat na něj přes existující mapování itemu (id/label/type/viewerId/
panelId/…). Fallback při prázdném/chybějícím stromu: dosavadní
`DASHBOARD_ITEM`.

#### 10. `frontend/src/stores/appInfo.svelte.js`

Odstranit `hasPortal` (načítání i getter) — bez konzumenta (bod 4/5).

#### 11. i18n (`frontend/src/i18n/cs.js`, `en.js`)

- Odstranit klíče použité jen zaniklým chrome PortalScreenu
  (`portal.heading`; `sidebar.logout` zůstává — používá ho user menu).
- Zbytek `portal.*` (subtitle, loading, error, retry, empty, enter,
  role.admin, stats.*) zůstává pro PortalContent.
- `npm run check:i18n` z `frontend/` musí projít.

### Dokumentace

#### 12. `docs/hosting.md`

- §6: přepsat popis přístupového modelu — jednotný shell, portál jako
  panel navigace, ne-admin = serverem ořezaná navigace (odvozené
  `adminOnly`), landing = první root leaf. Zaznamenat revizi D10
  a důsledek pro smíšený DS (chat, viditelná agenda).
- Stavový blok: přidat řádek o tomto tasku.

#### 13. `docs/modules.md` (sekce panels) — doplnit, že panel může přes
`navSection`/`navOrder` vstoupit do hlavní navigace a nést `adminOnly`.

## Testy

PHPUnit (spouštět úzce, např. `--filter NavigationController`):

1. **adminOnly filtrace:** ne-admin nedostane viewer nad tabulkou
   s `adminOnly: true` ani nad `core_system_*`; admin dostane vše.
2. **Fallback tabulky:** adminOnly tabulka bez vieweru se ne-adminovi
   neemituje.
3. **Panel v navigaci:** panel s `navSection: "_top"` se emituje jako
   `{type:'panel'}` root leaf s korektním pořadím; panel bez
   `navSection` se v hlavní navigaci neobjeví.
4. **Pořadí root leaves:** s aktivním hosting.core je pořadí
   portál(10) → Dashboard(20) → Chat(25) → _top(30+); bez hostingu
   Dashboard → Chat → _top (regrese pořadí).
5. **Chat gating (D5):** ne-admin + `hosting_core_data_sources`
   v `$tables` → Chat chybí; admin ho má; ne-admin bez hostingu ho má.
6. **Fail-closed:** `$auth === null` filtruje jako ne-admin.

Frontend: ruční ověření (viz Hotovo když) + `npm run check:i18n`.
Ověřit i mobilní drawer (portál jako aktivní položka, zavírání draweru
při navigaci — jde přes standardní handleItemClick, mělo by fungovat
beze změny).

## Strategie commitů

1. `nav: adminOnly-aware navigation + panels as nav items + ordered root leaves` (body 1, 2, testy 1–6)
2. `hosting: portal as navigation panel` (bod 3)
3. `frontend: unified shell — portal panel replaces PortalScreen` (body 5–11)
4. `app-info: drop hasPortal` (body 4, 10 — může být součástí commitu 3)
5. `docs: hosting portal-in-shell (D10 revision)` (body 12, 13)

Po commitu 2 nutný `ds-upgrade` na dev DS (změna module.jsonc).

## Hotovo když

- [ ] Admin na hosting DS vidí v sidebaru „Moje zdroje dat" jako první
      položku, Dashboard jako druhou; kliknutí zobrazí přehled DS
      (jen jeho DS z `hosting_core_ds_users`).
- [ ] Ne-admin na hosting DS dostane app shell: navigace obsahuje jen
      portál + Dashboard (žádný Chat, žádné hosting/system viewery);
      landing po přihlášení = portál.
- [ ] Ne-admin má v user menu Nastavení účtu (změna hesla funguje),
      jazyk a vzhled; „Nastavení aplikace" nevidí.
- [ ] Na běžném DS bez hostingu se navigace ani landing nemění
      (Dashboard první, Chat druhý, _top a sekce beze změny).
- [ ] OIDC průlet (op_auth) funguje pro admina i ne-admina beze změny.
- [ ] `PortalScreen.svelte` smazán, `hasPortal` odstraněn z API i storu.
- [ ] PHPUnit testy navigace zelené (úzký --filter), `check:i18n` prochází.
- [ ] `docs/hosting.md` a `docs/modules.md` aktualizované.
