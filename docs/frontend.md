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
│   ├── stores/
│   │   ├── auth.svelte.js              # Auth stav (token, user, isAuthenticated)
│   │   └── navigation.svelte.js        # Taby (otevřené, aktivní, open/close/activate)
│   ├── components/
│   │   ├── ui/                         # Základní UI prvky
│   │   │   ├── Button.svelte
│   │   │   ├── Input.svelte
│   │   │   ├── NumberInput.svelte
│   │   │   ├── TextArea.svelte
│   │   │   ├── Select.svelte
│   │   │   ├── Checkbox.svelte
│   │   │   ├── DateInput.svelte
│   │   │   └── Modal.svelte
│   │   ├── layout/
│   │   │   ├── AppShell.svelte         # Hlavní layout (sidebar + tabs + content)
│   │   │   ├── Header.svelte           # (nepoužívá se — logo a user info přesunuty do Sidebar)
│   │   │   ├── Sidebar.svelte          # Navigace, logo, user info — kolapsibilní s hover rozbalením
│   │   │   ├── TabBar.svelte           # Lišta otevřených tabů
│   │   │   └── ContentArea.svelte      # Hlavní oblast — renderuje aktivní tab
│   │   ├── auth/
│   │   │   └── LoginScreen.svelte      # Přihlašovací obrazovka
│   │   ├── browser/
│   │   │   └── TableBrowser.svelte     # Generický prohlížeč tabulek
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
│ Shipard  │  TabBar (otevřené taby)                  │
│ [◀]      ├──────────────────────────────────────────┤
├──────────┤                                          │
│ Sidebar  │  ContentArea                             │
│ (server) │  ┌─ TableBrowser ─────────────────────┐ │
│          │  │  [Toolbar: Nový záznam]             │ │
│ ─ Systém │  │  ┌─ Tabulka s daty ──────────────┐ │ │
│   Users  │  │  │  (sloupce z metadat, řazení)   │ │ │
│   Sett.  │  │  └────────────────────────────────┘ │ │
│ ─ Základ │  │  [Stránkování]                      │ │
│   Osoby  │  └─────────────────────────────────────┘ │
│   ...    │                                          │
├──────────┤                                          │
│ J. Novák │                                          │
│ Odhlásit │                                          │
└──────────┴──────────────────────────────────────────┘
```

### Sidebar — struktura

Sidebar je flex column se třemi sekcemi:

- **Header** (fixní) — logo "Shipard" + tlačítko pro sbalení/rozbalení
- **Nav** (scrollovatelný, `flex: 1`) — navigační strom ze serveru
- **Footer** (fixní) — jméno uživatele + tlačítko Odhlásit

### Sidebar — kolapsibilní

Sidebar je kolapsibilní na úzký proužek (48px, CSS proměnná `--shpd-sidebar-width-collapsed`). Ve sbaleném stavu:

- Navigační strom a logo jsou skryté
- Ve footeru se zobrazí kruhový avatar s iniciálou uživatele
- Při hoveru myší se sidebar rozbalí jako overlay (`position: absolute`, `z-index: 100`) na plnou šířku, aniž by posouval hlavní obsah
- Po odjetí myší se sidebar zase sbalí

Stav řídí Svelte runes: `collapsed` (toggle tlačítkem), `hovered` (mouseenter/mouseleave), `expanded_sidebar = !collapsed || hovered`.

### Sidebar — dynamická navigace ze serveru

Sidebar načítá navigační strom z `GET /_ui/navigation`. Server generuje strom automaticky z aktivních modulů a jejich tabulek:

```json
{
    "success": true,
    "data": [
        {
            "id": "core",
            "label": "Systém",
            "children": [
                {"id": "core_system_users", "label": "Uživatelé", "type": "table", "table": "core_system_users"},
                {"id": "core_system_settings", "label": "Nastavení", "type": "table", "table": "core_system_settings"}
            ]
        },
        {
            "id": "base",
            "label": "Základní",
            "children": [
                {"id": "base_persons_persons", "label": "Osoby", "type": "table", "table": "base_persons_persons"}
            ]
        }
    ]
}
```

Interní tabulky (sessions, api_keys, rate_limits) se v navigaci nezobrazují.

### Tabový model

Klik v sidebar otevře nový tab (nebo aktivuje existující). `navigation.svelte.js` spravuje pole tabů a aktivní tab. `TabBar` zobrazuje lištu s taby — klik přepne, × zavře.

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
- **Filtry z navigace** — tab může mít `filter` objekt (budoucí: filtrované pohledy)
- **Tlačítko "Nový záznam"** — otevře FormDialog pro vytvoření
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
- Auto-managed pole (id, created, modified) se nezobrazují
- password_hash se nezobrazuje v editaci

### FormDialog

Modal wrapper — otevírá se z TableBrowser (tlačítko / dvojklik). Po uložení se prohlížeč automaticky refreshuje.

---

## 7. UI API endpointy

### Implementované

| Endpoint | Popis |
|----------|-------|
| `GET /_ui/navigation` | Navigační strom ze serveru (moduly → skupiny → tabulky) |

### Budoucí

| Endpoint | Popis |
|----------|-------|
| `GET /_ui/browser/{table}` | UI-specifická metadata prohlížeče (viditelné sloupce, výchozí řazení, akce) |
| `GET /_ui/form/{table}` | Rozšířená definice formuláře (custom layout, widgety, závislosti mezi poli) |

Zatím prohlížeč i formuláře fungují čistě z `_meta/tables/{table}` — UI endpointy přidají vrstvu přizpůsobení.

---

## 8. Konvence

### Svelte

- Svelte 5 syntax (runes: `$state`, `$derived`, `$effect`)
- Jedna komponenta na soubor
- Props přes `$props()`
- Události přes callback props, ne custom events

### CSS

- CSS custom properties pro theming (`--shpd-color-primary`, `--shpd-space-md`)
- BEM-like naming: `.shpd-button`, `.shpd-button--primary`, `.shpd-button__icon`
- Scoped styles v Svelte komponentách

### API komunikace

- Vždy přes `api/client.js` (nikdy přímý fetch, kromě auth.js)
- `api/config.js` řeší DS ID prefix automaticky
- Automatický 401 → refresh → retry
- Chyby přes return value, ne exceptions

### Pojmenování

- Soubory komponent: PascalCase (`LoginScreen.svelte`)
- Soubory utilit/stores: camelCase (`auth.svelte.js`)
- CSS třídy: `shpd-{component}` prefix

---

## 9. Budoucí rozšíření

- **Filtrování v prohlížeči** — toolbar s filtry podle typu sloupce
- **Hledání** — fulltext přes toolbar
- **Mazání záznamů** — tlačítko v řádku nebo hromadně
- **Inline editace** — editace přímo v tabulce
- **Drag & drop** — řazení řádků
- **Enum hodnoty** — Select s options z konfigurační položky (cfgItem)
- **Navigace s filtry** — položky sidebar s předdefinovaným filtrem (Faktury vydané = doc_type:INV)
- **Výběr sloupců** — uživatel si vybere které sloupce vidí
- **Export** — CSV/Excel export z prohlížeče
- **Oprávnění** — skrývání položek navigace podle uživatelských práv
