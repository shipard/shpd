# Shipard — Frontend Architecture

## 1. Přehled

Shipard frontend je single-page aplikace (SPA) postavená na **Svelte 5**, která komunikuje s existujícím REST API. Aplikace se chová jako desktopová — bez klasické URL navigace, s interním stavem řídícím co je vidět.

### Principy

- **Server-driven UI** — server definuje strukturu (sloupce tabulek, pole formulářů, navigaci), klient renderuje podle schémat
- **Definovat, ne programovat** — nový modul = nová definice na serveru, zero práce na klientovi
- **Konzistentní UX** — uživatel se naučí prohlížeč tabulek a formuláře → umí celou aplikaci
- **Minimální klientská logika** — silná JS knihovna se základními komponentami, inteligentní renderery
- **Žádné URL routing** — stav navigace interně (sidebar, taby, dialogy), ne přes URL

### Technologický stack

| Komponenta | Technologie | Důvod |
|------------|------------|-------|
| UI framework | Svelte 5 | Kompiluje do čistého JS (žádný runtime), reaktivita bez boilerplate, blízké vanilla HTML/JS |
| Build tool | Vite | Nativní podpora Svelte, rychlý HMR pro vývoj |
| CSS | Vlastní (bez frameworku) | Plná kontrola, žádný overhead z nepoužívaných stylů |
| Ikony | Font Awesome (SVG/JS) | Tree-shakeable, jen použité ikony v bundle, inline SVG |
| HTTP klient | Fetch API | Nativní v moderních prohlížečích, nepotřebujeme knihovnu |
| State management | Svelte stores (runes) | Vestavěný v Svelte 5, nepotřebujeme externí knihovnu |

### Cílové prohlížeče

Chrome, Firefox, Edge — poslední 2 roky. Žádná podpora IE nebo starších verzí.

### Jazyk

- **UI texty:** čeština (výchozí jazyk aplikace), s podporou vícejazyčnosti přes existující i18n systém
- **Kód a komentáře:** angličtina

---

## 2. Adresářová struktura

```
frontend/
├── package.json
├── vite.config.js
├── svelte.config.js
├── index.html
├── src/
│   ├── main.js                         # Bootstrap — mount Svelte app
│   ├── App.svelte                      # Root — přepíná login/app shell
│   ├── api/
│   │   ├── config.js                   # Detekce DS ID z URL, API_BASE_URL
│   │   ├── client.js                   # HTTP klient (fetch wrapper s auth, refresh, retry)
│   │   └── auth.js                     # Login, refresh, logout (raw fetch)
│   ├── icons.js                        # Centrální registr ikon (importy, mapování, resolveIcon)
│   ├── stores/
│   │   ├── auth.svelte.js              # Auth stav (token, user, isAuthenticated)
│   │   └── navigation.svelte.js        # Aktivní položka navigace
│   ├── components/
│   │   ├── ui/                         # Základní UI prvky
│   │   │   ├── Button.svelte
│   │   │   ├── Input.svelte
│   │   │   ├── NumberInput.svelte
│   │   │   ├── TextArea.svelte
│   │   │   ├── Select.svelte
│   │   │   ├── Checkbox.svelte
│   │   │   ├── DateInput.svelte
│   │   │   ├── Icon.svelte             # Univerzální ikona (inline SVG z FA definition)
│   │   │   └── Modal.svelte
│   │   ├── layout/
│   │   │   ├── AppShell.svelte         # Hlavní layout (sidebar + content)
│   │   │   ├── Sidebar.svelte          # Navigace, logo, user info — kolapsibilní s hover rozbalením
│   │   │   └── ContentArea.svelte      # Hlavní oblast — renderuje aktivní položku
│   │   ├── auth/
│   │   │   └── LoginScreen.svelte      # Přihlašovací obrazovka
│   │   ├── browser/
│   │   │   └── TableBrowser.svelte     # Generický prohlížeč tabulek
│   │   ├── viewer/
│   │   │   ├── Viewer.svelte           # Viewer shell (tab bar, search, infinite scroll, detail)
│   │   │   ├── ViewerRow.svelte        # Jeden řádek seznamu (t1/t2/t3, stateStyle)
│   │   │   ├── ViewerDetail.svelte     # Detail panel s taby (properties, table, html)
│   │   │   └── ViewerToolbar.svelte    # Toolbar akcí (Přidat, Otevřít, …)
│   │   └── form/
│   │       ├── FormField.svelte        # Dynamický field renderer (typ → komponenta)
│   │       ├── FormRenderer.svelte     # Generický formulář z metadat
│   │       └── FormDialog.svelte       # Modal wrapper pro FormRenderer
│   └── styles/
│       ├── variables.css               # CSS custom properties (barvy, spacing, typography)
│       ├── reset.css                   # CSS reset
│       └── base.css                    # Základní typografie a layout
```

### Build a nasazení

```bash
cd frontend
npm install
npm run build     # → výstup do ../public/app/
```

Nginx konfigurace: viz `docs/nginx/app.conf`.

### Dev mód — DS ID v URL

V dev módu (IP adresa) se aplikace otevírá na `http://{ip}/{ds-id}/app/`. Frontend automaticky detekuje DS ID z URL a přidává ho jako prefix ke všem API voláním (`/{ds-id}/api/v1/...`). Logika je v `api/config.js`.

V produkčním módu (subdoména) se DS ID nepoužívá — API je na `/api/v1/...`.

---

## 3. Přihlášení a autentizace

### API endpointy

```
POST /api/v1/_auth/login      # login + password → session token
POST /api/v1/_auth/refresh    # starý token → nový token
DELETE /api/v1/_auth/logout   # invalidace tokenu
```

Token má prefix `shpd_st_`, expirace 24h, uložen v `core_system_sessions`.

### Flow

1. `App.svelte` kontroluje auth store → není přihlášen → `LoginScreen`
2. Uživatel zadá login + heslo → `POST /_auth/login`
3. Úspěch → token + user do auth store + `localStorage`
4. App přepne na `AppShell`

### Bezpečnost

- Token v `localStorage` (přijatelné pro session token s expirací)
- Automatický refresh při 401 s retry původního requestu
- Logout vyčistí `localStorage`
- HTTPS v produkci

---

## 4. Aplikační shell

Layout je bez horní lišty — logo a uživatelské info jsou integrovány v sidebaru:

```
┌──────────┬──────────────────────────────────────────┐
│ Shipard  │                                          │
│ [◀]      │  ContentArea                             │
├──────────┤                                          │
│ Sidebar  │  ┌─ Viewer ───────────────────────────┐ │
│ (server) │  │  [Přidat]  [Otevřít]               │ │
│          │  │  [Aktivní][Archív][Vše][Koš]        │ │
│ ─ Systém │  │  [🔍 Hledat...]                    │ │
│   Users  │  │  ┌─ Seznam ──────┐ ┌─ Detail ────┐ │ │
│   Sett.  │  │  │  Řádek 1      │ │  Tab 1 Tab2 │ │ │
│ ─ Základ │  │  │  Řádek 2      │ │  ...obsah...│ │ │
│   Osoby  │  │  │  ...          │ │             │ │ │
│   ...    │  │  └───────────────┘ └─────────────┘ │ │
├──────────┤  └───────────────────────────────────────┘ │
│ J. Novák │                                          │
│ Odhlásit │                                          │
└──────────┴──────────────────────────────────────────┘
```

### Sidebar — struktura

Sidebar je flex column se třemi sekcemi:

- **Header** (fixní) — logo „Shipard" + tlačítko pro sbalení/rozbalení
- **Nav** (scrollovatelný, `flex: 1`) — navigační strom ze serveru
- **Footer** (fixní) — uživatelský panel s avatarem a jménem; klik otevře
  dropdown s položkami **Nastavení účtu** a **Odhlásit**

### Sidebar — kolapsibilní

Sidebar je kolapsibilní na úzký proužek (48px). Ve sbaleném stavu:

- Navigační strom a logo jsou skryté
- V patce zůstává jen kruhový avatar uzivatele; klik otevře dropdown menu
  jako overlay vpravo od sidebaru
- Při hoveru myší se sidebar rozbalí jako overlay (`position: absolute`, `z-index: 100`) na plnou šířku, aniž by posouval hlavní obsah
- Po odjetí myší se sidebar zase sbalí

Stav řídí Svelte runes: `collapsed` (toggle tlačítkem), `hovered` (mouseenter/mouseleave).

### Sidebar — dynamická navigace ze serveru

Sidebar načítá navigační strom z `GET /_ui/navigation`. Server generuje strom automaticky z aktivních modulů:

```json
{
    "success": true,
    "data": [
        {
            "id": "core",
            "label": "Systém",
            "children": [
                {"id": "core_system_users", "label": "Uživatelé", "type": "table", "table": "core_system_users"}
            ]
        },
        {
            "id": "base",
            "label": "Základní",
            "children": [
                {"id": "viewer:base.persons", "label": "Osoby", "type": "viewer", "viewerId": "base.persons", "icon": "user"}
            ]
        }
    ]
}
```

Klik v sidebaru přímo nahradí obsah hlavní oblasti. `navigation.svelte.js` spravuje jedinou aktivní položku (`activeItem`). `ContentArea` renderuje obsah podle typu (`table` → `TableBrowser`, `viewer` → `Viewer`).

---

## 5. Prohlížeč tabulek (TableBrowser)

Generická komponenta — dostane název tabulky a vykreslí prohlížeč s daty z API.

### Flow

1. Fetch metadata: `GET /_meta/tables/{table}` → sloupce, typy, groups
2. Fetch data: `GET /{table}?limit=20&offset=0` → záznamy
3. Vykreslí tabulku podle metadat

### Funkce

- **Dynamické sloupce** — z metadat, filtrované (bez id, password_hash, json)
- **Formátování podle typu** — varchar→text, int→číslo vpravo, boolean→Ano/Ne, date→dd.mm.yyyy, datetime→dd.mm.yyyy hh:mm, numeric→desetinná místa
- **Řazení** — klik na hlavičku, toggle asc/desc, API sort parametr
- **Stránkování** — offset-based, 20/50/100 na stránku, předchozí/další
- **Tlačítko „Nový záznam"** — otevře FormDialog pro vytvoření
- **Dvojklik na řádek** — otevře FormDialog pro editaci

---

## 6. Editační formuláře

Generický renderer — `FormRenderer` dostane tabulku a volitelně ID záznamu, stáhne metadata a vykreslí formulář.

### Flow

1. Fetch metadata: `GET /_meta/tables/{table}` → sloupce, typy, groups, nullable
2. Pokud editace: fetch záznamu `GET /{table}/{id}`
3. Vykreslí formulář — `FormField` mapuje typ sloupce na UI komponentu
4. Uložení: `POST /{table}` (nový) nebo `PUT /{table}/{id}` (editace)
5. Validační chyby ze serveru se mapují na pole formuláře

### Mapování typ → komponenta (FormField)

| Typ sloupce | Komponenta |
|-------------|-----------|
| varchar | Input (text) |
| text, longtext | TextArea |
| int, smallint, bigint | NumberInput (step=1) |
| numeric | NumberInput (step z scale) |
| boolean | Checkbox |
| date | DateInput |
| datetime | Input (datetime-local) |
| enumInt, enumString | Select (budoucí: options z konfigurace) |

### Layout

- Pole seskupené podle `columnGroups` z metadat
- Dvousloupcový grid (responzivní → 1 sloupec na úzkých obrazovkách)
- Auto-managed pole (id, created, modified) a systémové pole (`system: true`) se nezobrazují
- password_hash se nezobrazuje v editaci

### FormDialog

Modal wrapper — otevírá se z TableBrowser (tlačítko / dvojklik). Po uložení se prohlížeč automaticky refreshuje.

---

## 7. Viewer systém

Viewer je specializovaný prohlížeč pro složitější tabulky — na rozdíl od generického `TableBrowser` (který funguje čistě z metadat) viewer implementuje vlastní renderování řádků, filtrování a detail panel. Každý viewer je PHP třída dědící `TableViewer`.

### Architektura

```
Viewer.svelte          (frontend — tab bar, search, infinite scroll, detail panel)
  ↕ REST API
ViewerController       (PHP — meta, rows, detail)
  ↕
TableViewer (abstract) (PHP — bázová třída se všemi helpers)
  ↕
PersonsViewer          (PHP — konkrétní viewer pro base.persons)
```

### API endpointy vieweru

| Endpoint | Popis |
|----------|-------|
| `GET /_ui/viewer/{id}/meta` | Metadata: name, table, filters, toolbar, viewGroups |
| `GET /_ui/viewer/{id}/rows` | Záznamy (stránkované, fulltext, viewGroup filter) |
| `GET /_ui/viewer/{id}/detail/{recordId}` | Detail vybraného záznamu (tabs) |

Parametry pro `rows`:
- `page=0` — číslo stránky (0-based), server vrátí pageSize+1 pro detekci `hasMore`
- `search=text` — fulltext hledání
- `filter[viewGroup]=active` — filtr skupiny stavů (active / archive / trash; bez = vše)

### Tab bar (doc state taby)

Pokud viewer vrací neprázdné `viewGroups` v meta odpovědi, `Viewer.svelte` zobrazí tab bar:

| Tab | Filtr | Popis |
|-----|-------|-------|
| **Aktivní** | `filter[viewGroup]=active` | Koncepty, V opravě, V pořádku |
| **Archív** | `filter[viewGroup]=archive` | Archivované záznamy |
| **Koš** | `filter[viewGroup]=trash` | Smazané záznamy |
| **Vše** | bez filtru | Všechny záznamy |

Přepnutí tabu resetuje stránku a výběr záznamu. Výchozí tab: Aktivní.

### Formát řádku (`renderRow()`)

```json
{
    "id": 42,
    "stateStyle": "done",
    "t1": "Název záznamu",
    "i1": "#kód",
    "t2": [{"text": "IČO: 12345"}, {"text": "V pořádku", "class": "success"}],
    "t3": "email@example.com"
}
```

Pole `t1`, `i1`, `t2`, `i2`, `t3` přijímají string, objekt `{text, class?}` nebo pole objektů. `stateStyle` se mapuje na CSS třídu `docState_{stateStyle}` na řádku. Dostupné span třídy: `amount`, `muted`, `bold`, `primary`, `success`, `warning`, `danger`.

### Formát detail panelu (`renderDetail()`)

Vrací taby s obsahem jednoho ze tří typů:

```json
{"tabs": [
    {"id": "overview", "label": "Přehled", "content": {
        "type": "properties",
        "groups": [{"title": "Identifikace", "items": [{"label": "IČO", "value": "12345"}]}]
    }},
    {"id": "contacts", "label": "Kontakty", "content": {
        "type": "table",
        "columns": [{"id": "name", "label": "Název"}, {"id": "email", "label": "E-mail"}],
        "rows": [{"name": "Jan Novák", "email": "jan@example.com"}]
    }}
]}
```

Typy obsahu: `properties` (label/value grid), `table` (tabulka), `html` (surové HTML).

### Registrace vieweru

V `module.jsonc`:

```jsonc
"viewers": [
    {
        "id": "base.persons",
        "name:cs": "Osoby",
        "icon": "user",
        "table": "base_persons_persons",
        "class": "Shipard\\Module\\Base\\Persons\\PersonsViewer"
    }
]
```

PHP třída vieweru žije v `modules/{skupina}/{modul}/src/` a dědí `TableViewer`. Pro podporu stavů dokumentů nastaví `$docStatesCfgItem`:

```php
class PersonsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    public function selectRows(?string $search, array $filters, int $pageNumber): array { /* ... */ }
    public function renderRow(array $rowData): array { /* ... */ }
    public function renderDetail(int $recordId): array { /* ... */ }
}
```

Viz také `docs/doc-states.md` — sekce Viewer systém.

### Existující viewery

| Viewer ID | Modul | Třída | Zvláštnosti |
|---|---|---|---|
| `base.persons` | `base.persons` | `PersonsViewer` | Archivační docStates, fulltext search přes full_name/company_id/email/person_id |
| `core.mail.incoming` | `core.mail` | `IncomingMessagesViewer` | Vlastní docStates (`core.mail.docStatesIncoming`), JOIN na schránku, relativní formátování received_at, 4 detail taby (Obsah / Přílohy / Analýzy / Originál) |

Nové viewery přidávají moduly přes `module.jsonc.viewers[]` — jakmile je viewer registrován, automaticky se objeví v navigaci (ikona z `iconMap`, fallback `iconTable`).

---

## 8. UI API endpointy

### Implementované

| Endpoint | Popis |
|----------|-------|
| `GET /_ui/navigation` | Navigační strom ze serveru (moduly → skupiny → tabulky/viewery) |
| `GET /_ui/viewer/{id}/meta` | Metadata vieweru (name, table, filters, toolbar, viewGroups) |
| `GET /_ui/viewer/{id}/rows` | Záznamy vieweru (page, search, filter) |
| `GET /_ui/viewer/{id}/detail/{recordId}` | Detail panel záznamu (tabs) |

### Budoucí

| Endpoint | Popis |
|----------|-------|
| `GET /_ui/browser/{table}` | UI-specifická metadata prohlížeče (viditelné sloupce, výchozí řazení, akce) |
| `GET /_ui/form/{table}` | Rozšířená definice formuláře (custom layout, widgety, závislosti mezi poli) |

Zatím prohlížeč i formuláře fungují čistě z `_meta/tables/{table}` — UI endpointy přidají vrstvu přizpůsobení.

---

## 9. Konvence

### Svelte

- Svelte 5 syntax (runes: `$state`, `$derived`, `$effect`)
- Jedna komponenta na soubor
- Props přes `$props()`
- Události přes callback props, ne custom events
- `$effect` nesmí synchronně číst `$state` proměnné, které nemají být sledovány jako závislosti — funkce pro fetch přijímají explicitní parametry

### CSS

- CSS custom properties pro theming (`--shpd-color-primary`, `--shpd-space-md`)
- BEM-like naming: `.shpd-button`, `.shpd-button--primary`, `.shpd-button__icon`
- Scoped styles v Svelte komponentách
- `:global()` pro třídy aplikované dynamicky (např. `docState_concept` na řádcích vieweru)

Detailní dokumentace barevného systému (paleta, doc-state konvence, badge varianty,
focus stavy) je v [`design-system.md`](design-system.md).

### API komunikace

- Vždy přes `api/client.js` (nikdy přímý fetch, kromě auth.js)
- `api/config.js` řeší DS ID prefix automaticky
- Automatický 401 → refresh → retry
- Chyby přes return value, ne exceptions
- **Envelope konvence:** všechny odpovědi mají tvar `{ success, data, meta? }` nebo `{ success, error }`. Data jsou vždy v `res.data`, nikdy přímo v `res`. Např. `res.data.formDefinition`, ne `res.formDefinition`.

### Pojmenování

- Soubory komponent: PascalCase (`LoginScreen.svelte`)
- Soubory utilit/stores: camelCase (`auth.svelte.js`)
- CSS třídy: `shpd-{component}` prefix

### Dropdown / popover komponenty

Dropdown menu (např. user menu v patce sidebaru) typicky implementují dva
kódové vzorce: **toggle při kliku na trigger** a **close-on-outside přes
document click listener registrovaný v `$effect`**. Tyhle dva vzorce spolu
mají jednu nepříjemnou interakci, na které se dá lehce spálit.

#### Past: zavírání menu z handleru položky uvnitř menu

Nechci zavírat menu pomocí `closeMenu()` v handleru položky, která spouští
asynchronní akci (logout, navigace, fetch). Špatně:

```js
function handleLogoutFromMenu() {
  closeUserMenu();        // ❌ synchronně nastaví userMenuOpen = false
  handleLogout();         // ...sem se může nedostat, viz níže
}
```

Sekvence událostí:

1. Click na položku v menu spustí handler.
2. `closeUserMenu()` synchronně nastaví `userMenuOpen = false` a Svelte
   reaktivně odmontuje `{#if userMenuOpen}` blok — položka (target eventu)
   se stává detached elementem.
3. Click event pokračuje bublat k document listeneru.
4. Listener testírá `menuRoot.contains(e.target)` — detached element není
   v DOMu, `contains()` vrátí **false**, listener spadne do větve
   „klik mimo menu“.
5. `$effect` cleanup během stejného microtasku odregistruje listener
   a může zasáhnout do běhu následující asynchronní akce.

Výsledek: menu zmizí, ale akce problému docálu z prvního kliku se ztratí.
Druhý klik už funguje, protože běží s novým `$effect` cyklem.

#### Řešení

Pokud akce stejně změní kontext tak, že menu přestane existovat (logout,
navigace pryč), `closeMenu()` se vůbec nevolá — celý sidebar / komponenta
zmizí sám:

```js
function handleLogoutFromMenu() {
  // Záměrně nezavíráme menu — sidebar zmizí sám,
  // jakmile authStore.clearAuth() přepne na LoginScreen.
  handleLogout();
}
```

Pokud akce kontext nezmění (běžný případ), zavírám menu **až po dokončení
akce**, ne předem:

```js
async function handleAction() {
  await doSomething();
  closeMenu();
}
```

Nebo zavři menu **a počkej jeden tick** než spustíš další akci, aby se
stihl render flush a click bubbling dokončily:

```js
function handleAction() {
  closeMenu();
  setTimeout(doSomething, 0);  // nebo queueMicrotask
}
```

#### Robustní logout / fetch v handleru

Asynchronní akce volané z handlerů, které změní auth state, by měly přežít
i pád API volání. Příklad: `logout` musí úspět z perspektivy uživatele i když
backend fetch selhal (token už neplatný, síť nedostupná atd.):

```js
async function handleLogout() {
  try {
    await logout();
  } catch (err) {
    console.warn('Logout API call failed (continuing):', err);
  }
  authStore.clearAuth();
  onLogout?.();
}
```

---

## 10. Ikony

Aplikace používá **Font Awesome** (SVG/JS varianta) pro ikony napříč celým UI.

### Balíčky

- `@fortawesome/fontawesome-svg-core` — základní knihovna
- `@fortawesome/free-solid-svg-icons` — sada solid ikon

### Architektura

- **`src/icons.js`** — centrální registr. Všechny ikony se importují a re-exportují z jednoho místa. Pojmenování podle *významu* (ne podle vzhledu): `iconAdd`, `iconEdit`, `iconUser`. Obsahuje `iconMap` pro překlad řetězců z API a funkci `resolveIcon(name, fallback)`.
- **`components/ui/Icon.svelte`** — univerzální komponenta. Přijímá FA icon definition a vykreslí inline SVG. Podporuje velikosti (`xs`/`sm`/`md`/`lg`/`xl`) a animaci `spin`.
- **`components/ui/Button.svelte`** — rozšířený o prop `icon`, `iconOnly` a variantu `ghost`.

### Ikony v navigaci (server-driven)

Navigační položky mohou mít volitelnou ikonu definovanou na serveru v `module.jsonc`. Frontend používá `resolveIcon(item.icon)` s fallbackem na `iconTable`.

### Přidání nové ikony

1. V `icons.js`: import z `@fortawesome/free-solid-svg-icons`, pojmenovaný export (`iconNěco`)
2. Pokud ji server posílá jako string: přidat záznam do `iconMap`
3. V komponentě: importovat z `icons.js` a předat do `<Icon>` nebo `<Button>`

---

## 11. Budoucí rozšíření

- **Filtrování v prohlížeči** — toolbar s filtry podle typu sloupce
- **Mazání záznamů** — tlačítko v řádku nebo hromadně
- **Inline editace** — editace přímo v tabulce
- **Enum hodnoty** — Select s options z konfigurační položky (cfgItem)
- **Navigace s filtry** — položky sidebar s předdefinovaným filtrem (Faktury vydané = doc_type:INV)
- **Výběr sloupců** — uživatel si vybere které sloupce vidí
- **Export** — CSV/Excel export z prohlížeče
- **Oprávnění** — skrývání položek navigace podle uživatelských práv
- **Editační formuláře pro doc states** — stavová tlačítka, zamčení readOnly formuláře, badge stavu v hlavičce (Fáze 4 stavů dokumentů)
