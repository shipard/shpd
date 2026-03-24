# Shipard — Frontend Architecture

## 1. Přehled

Shipard frontend je single-page aplikace (SPA) postavená na **Svelte 5**, která komunikuje s existujícím REST API. Aplikace se chová jako desktopová — bez klasické URL navigace, s interním stavem řídícím co je vidět.

### Principy

- **Server-driven UI** — server definuje strukturu (sloupce tabulek, pole formulářů), klient renderuje podle schémat
- **Definovat, ne programovat** — nový modul = nová definice na serveru, zero práce na klientovi
- **Konzistentní UX** — uživatel se naučí prohlížeč tabulek a formuláře → umí celou aplikaci
- **Minimální klientská logika** — silná JS knihovna s ~20 základními komponentami, inteligentní renderery
- **Žádné URL routing** — stav navigace interně (sidebar, taby, dialogy), ne přes URL

### Technologický stack

| Komponenta | Technologie | Důvod |
|------------|------------|-------|
| UI framework | Svelte 5 | Kompiluje do čistého JS (žádný runtime), reaktivita bez boilerplate, blízké vanilla HTML/JS |
| Build tool | Vite | Nativní podpora Svelte, rychlý HMR pro vývoj |
| CSS | Vlastní (bez frameworku) | Plná kontrola, žádný overhead z nepoužívaných stylů, ~20 komponent zvládneme sami |
| HTTP klient | Fetch API | Nativní v moderních prohlížečích, nepotřebujeme knihovnu |
| State management | Svelte stores (runes) | Vestavěný v Svelte 5, nepotřebujeme externí knihovnu |

### Cílové prohlížeče

Chrome, Firefox, Edge — poslední 2 roky. Žádná podpora IE nebo starších verzí.

---

## 2. Adresářová struktura

```
frontend/                           # Root frontendového projektu
├── package.json
├── vite.config.js
├── svelte.config.js
├── index.html                      # Entry point (Vite)
├── src/
│   ├── main.js                     # Bootstrap — mount Svelte app
│   ├── App.svelte                  # Root komponenta — routing login/app
│   ├── api/
│   │   ├── client.js               # HTTP klient (fetch wrapper s auth)
│   │   └── auth.js                 # Login, refresh, logout
│   ├── stores/
│   │   ├── auth.svelte.js          # Auth stav (token, user, isAuthenticated)
│   │   ├── navigation.svelte.js    # Navigační stav (aktivní panel, otevřené taby)
│   │   └── notifications.svelte.js # Toast notifikace
│   ├── components/
│   │   ├── ui/                     # Základní UI prvky (~20 komponent)
│   │   │   ├── Button.svelte
│   │   │   ├── Input.svelte
│   │   │   ├── Select.svelte
│   │   │   ├── Checkbox.svelte
│   │   │   ├── DatePicker.svelte
│   │   │   ├── NumberInput.svelte
│   │   │   ├── TextArea.svelte
│   │   │   ├── Modal.svelte
│   │   │   ├── Toast.svelte
│   │   │   ├── Spinner.svelte
│   │   │   ├── Badge.svelte
│   │   │   ├── Toggle.svelte
│   │   │   ├── Dropdown.svelte
│   │   │   ├── Tabs.svelte
│   │   │   ├── Pagination.svelte
│   │   │   ├── Table.svelte
│   │   │   ├── Icon.svelte
│   │   │   ├── FileUpload.svelte
│   │   │   └── index.js            # Re-export všech komponent
│   │   ├── layout/                 # Aplikační shell
│   │   │   ├── AppShell.svelte     # Hlavní layout (sidebar + obsah)
│   │   │   ├── Sidebar.svelte      # Levý sidebar s navigací
│   │   │   ├── Header.svelte       # Horní lišta (user menu, notifikace)
│   │   │   └── ContentArea.svelte  # Oblast pro zobrazení obsahu
│   │   ├── auth/                   # Přihlašovací obrazovka
│   │   │   └── LoginScreen.svelte
│   │   ├── browser/                # Prohlížeč tabulek (generický)
│   │   │   ├── TableBrowser.svelte # Hlavní prohlížeč — renderuje tabulku podle schématu
│   │   │   ├── BrowserToolbar.svelte
│   │   │   ├── BrowserFilters.svelte
│   │   │   └── BrowserPagination.svelte
│   │   └── form/                   # Editační formuláře (generické)
│   │   │   ├── FormRenderer.svelte # Renderuje formulář podle schématu
│   │   │   ├── FormField.svelte    # Dynamický field renderer
│   │   │   └── FormDialog.svelte   # Wrapper: modal + form renderer
│   └── styles/
│       ├── variables.css           # CSS custom properties (barvy, spacing, typography)
│       ├── reset.css               # CSS reset
│       ├── base.css                # Základní typografie a layout
│       └── components.css          # Styly pro UI komponenty
```

### Build a nasazení

Vite buildne frontend do statických souborů. Ty se servírují přes nginx:

```
public/
├── index.php                       # API entry point (existující)
└── app/                            # Frontend build output
    ├── index.html
    ├── assets/
    │   ├── app-[hash].js
    │   └── app-[hash].css
```

Kompletní nginx konfigurace je v `docs/nginx/app.conf`. Klíčové body:

```nginx
# Redirect root to the app
location = / {
    return 301 /app/;
}

# Frontend SPA — static files, SPA fallback
location /app/ {
    alias /opt/shipard/shpd/public/app/;
    try_files $uri $uri/ /app/index.html;
}

location = /app {
    return 301 /app/;
}

# API → PHP
location /api/ {
    try_files $uri /index.php$is_args$args;
}
```

### Dev mód a DS ID

V dev módu nginx jsou API cesty ve tvaru `/{ds-id}/api/v1/...` (PHP resolver čte DS ID z URL prefixu). Frontend SPA používá relativní cesty `/api/v1/...`.

**Možnosti:**

1. **`npm run dev` (Vite dev server)** — proxy v `vite.config.js` přeposílá `/api/*` na `localhost:80`. PHP dostane request s cestou `/api/v1/...` bez DS ID prefixu → je potřeba dev-mode resolver, který DS ID čte z jiného zdroje (config, env, hlavička).

2. **Přístup přes `/{ds-id}/app/`** — nginx obsluhuje SPA i pod DS-ID prefixem (viz `docs/nginx/app.conf`). Frontend pak musí DS ID prefix přidat do API volání (zatím neimplementováno).

3. **Produkční mód lokálně** — přidat DS ID do `domains.json` namapovaný na `localhost` nebo `127.0.0.1` a přistupovat přes doménové jméno.

---

## 3. Přihlášení a autentizace

### Existující API endpointy

API už podporuje kompletní autentizaci:

```
POST /api/v1/_auth/login      # login + password → token
POST /api/v1/_auth/refresh    # starý token → nový token
DELETE /api/v1/_auth/logout   # invalidace tokenu
```

Token má prefix `shpd_st_`, expirace je uložena v `core_system_sessions`.

### Flow přihlášení

```
1. Uživatel otevře /app → App.svelte
2. App kontroluje auth store → není přihlášen
3. Zobrazí LoginScreen
4. Uživatel zadá login + heslo
5. POST /api/v1/_auth/login
6. Úspěch → uloží token + user do auth store (+ localStorage pro persistence)
7. App přepne na AppShell
```

### Auth store (`stores/auth.svelte.js`)

```javascript
// Stav
let token = $state(localStorage.getItem('shpd_token'));
let user = $state(JSON.parse(localStorage.getItem('shpd_user') || 'null'));
let isAuthenticated = $derived(token !== null);

// Akce
function login(loginResponse) {
    token = loginResponse.data.token;
    user = loginResponse.data.user;
    localStorage.setItem('shpd_token', token);
    localStorage.setItem('shpd_user', JSON.stringify(user));
}

function logout() {
    // Volá API, pak vyčistí stav
    token = null;
    user = null;
    localStorage.removeItem('shpd_token');
    localStorage.removeItem('shpd_user');
}
```

### HTTP klient (`api/client.js`)

Wrapper kolem fetch, který automaticky:
- Přidává `Authorization: Bearer {token}` ke každému requestu
- Přidává `Content-Type: application/json`
- Parsuje JSON odpovědi
- Při `401` zavolá refresh; pokud refresh selže → logout
- Při síťové chybě zobrazí notifikaci

```javascript
async function apiRequest(method, path, body = null) {
    const headers = {
        'Content-Type': 'application/json',
        'Accept-Language': 'cs',
    };

    if (authStore.token) {
        headers['Authorization'] = `Bearer ${authStore.token}`;
    }

    const response = await fetch(`/api/v1${path}`, {
        method,
        headers,
        body: body ? JSON.stringify(body) : null,
    });

    if (response.status === 401 && authStore.token) {
        // Pokus o refresh
        const refreshed = await tryRefresh();
        if (refreshed) {
            return apiRequest(method, path, body); // Retry
        }
        authStore.logout();
        return null;
    }

    return response.json();
}
```

### LoginScreen — design

Jednoduchá přihlašovací obrazovka na středu stránky:
- Logo Shipard
- Pole: Login, Heslo
- Tlačítko "Přihlásit se"
- Chybová hláška při neúspěchu
- Loading stav při komunikaci se serverem
- Bez registrace (uživatele zakládá admin)

### Bezpečnost

- Token se ukládá do `localStorage` (přijatelné pro session token s expirací)
- Automatický refresh před expirací
- Logout vyčistí `localStorage`
- Všechna API komunikace přes HTTPS (v produkci)

---

## 4. Aplikační shell

Po přihlášení se zobrazí hlavní layout aplikace.

### Struktura

```
┌─────────────────────────────────────────────────────┐
│  Header (logo, název DS, user menu)                 │
├──────────┬──────────────────────────────────────────┤
│          │                                          │
│ Sidebar  │  Content Area                            │
│          │                                          │
│ ─ Osoby  │  ┌─ Tab: Faktury vydané ──────────────┐ │
│ ─ Doklady│  │                                     │ │
│   ─ FV   │  │  [Toolbar: filtr, hledání, nový]    │ │
│   ─ FP   │  │  ┌───────────────────────────────┐  │ │
│ ─ ...    │  │  │  Tabulka s daty                │  │ │
│          │  │  │                                 │  │ │
│          │  │  │                                 │  │ │
│          │  │  └───────────────────────────────┘  │ │
│          │  │  [Stránkování]                      │ │
│          │  └─────────────────────────────────────┘ │
│          │                                          │
├──────────┴──────────────────────────────────────────┤
│  Status bar (volitelně)                             │
└─────────────────────────────────────────────────────┘
```

### Sidebar

Sidebar je strom navigace generovaný z modulového systému. Server poskytne strukturu přes nový API endpoint:

```
GET /api/v1/_meta/navigation
```

```json
{
    "success": true,
    "data": [
        {
            "id": "core",
            "label": "Systém",
            "icon": "settings",
            "children": [
                {"id": "core_system_users", "label": "Uživatelé", "type": "table"}
            ]
        },
        {
            "id": "economy",
            "label": "Ekonomika",
            "icon": "file-text",
            "children": [
                {
                    "id": "economy_docs",
                    "label": "Doklady",
                    "children": [
                        {"id": "economy_docs_heads:INV", "label": "Faktury vydané", "type": "table", "filter": {"doc_type": "eq:INV"}},
                        {"id": "economy_docs_heads:REC", "label": "Faktury přijaté", "type": "table", "filter": {"doc_type": "eq:REC"}}
                    ]
                }
            ]
        }
    ]
}
```

Klik na položku v sidebar otevře prohlížeč tabulky v Content Area (nebo přepne na existující tab).

### Navigační model

Aplikace používá **tabový model** — klik v sidebar otevře nový tab (nebo aktivuje existující). Uživatel může mít otevřeno více prohlížečů současně a přepínat mezi nimi.

Navigační stav (`stores/navigation.svelte.js`):

```javascript
let tabs = $state([]);        // Otevřené taby [{id, label, type, table, filter}]
let activeTabId = $state(null); // ID aktivního tabu
```

---

## 5. Prohlížeč tabulek (TableBrowser)

Generická komponenta, která dostane název tabulky a vykreslí prohlížeč.

### Flow

```
1. Uživatel klikne v sidebar na "Faktury vydané"
2. Otevře se nový tab s TableBrowser(table="economy_docs_heads", filter={doc_type: "eq:INV"})
3. TableBrowser:
   a) GET /api/v1/_meta/tables/economy_docs_heads → metadata (sloupce, typy)
   b) GET /api/v1/economy_docs_heads?filter[doc_type]=eq:INV&limit=20 → data
   c) Vykreslí tabulku podle metadat
```

### Funkce prohlížeče

- **Zobrazení dat** — tabulka s dynamickými sloupci podle metadat
- **Řazení** — klik na hlavičku sloupce
- **Stránkování** — offset-based (20/50/100 na stránku)
- **Filtrování** — toolbar s filtry podle typu sloupce
- **Akce nad řádkem** — dvojklik otevře editační formulář (budoucnost)

### Renderování sloupců podle typu

| Typ sloupce | Zobrazení |
|-------------|-----------|
| `varchar`, `text` | Text |
| `int`, `smallint`, `bigint` | Číslo (zarovnání vpravo) |
| `numeric` | Formátované číslo s desetinami |
| `boolean` | Ikona (check/cross) |
| `date` | Formátované datum (dd.mm.yyyy) |
| `datetime` | Datum + čas |
| `enumInt`, `enumString` | Popisek z konfigurace (budoucnost) |

---

## 6. Editační formuláře (budoucnost)

Generický renderer, který dostane definici formuláře ze serveru a vykreslí ho.

### Koncept

Server vrátí JSON popis formuláře:

```json
{
    "table": "core_system_users",
    "layout": [
        {
            "group": "credentials",
            "label": "Přihlašovací údaje",
            "fields": [
                {"column": "login", "type": "varchar", "length": 100, "required": true},
                {"column": "password", "type": "password", "required": true}
            ]
        },
        {
            "group": "personal",
            "label": "Osobní údaje",
            "fields": [
                {"column": "full_name", "type": "varchar", "length": 200, "required": true},
                {"column": "email", "type": "varchar", "length": 200}
            ]
        }
    ]
}
```

Klient to vykreslí jako formulář se skupinami polí, validací, tlačítky Uložit/Zrušit.

---

## 7. UI API — server-side endpointy (nové)

Pro podporu frontend aplikace je potřeba rozšířit API o tyto endpointy:

### 7.1 Navigace

```
GET /api/v1/_ui/navigation
```

Vrátí stromovou strukturu sidebar navigace (generovanou z modulů a oprávnění uživatele).

### 7.2 Definice prohlížeče tabulky

```
GET /api/v1/_ui/browser/{table}
```

Vrátí kompletní definici prohlížeče — viditelné sloupce, výchozí řazení, dostupné filtry, akce. Nadstavba nad `_meta/tables/{table}` — přidává UI-specifické informace.

### 7.3 Definice formuláře

```
GET /api/v1/_ui/form/{table}
GET /api/v1/_ui/form/{table}/{id}   # s předvyplněnými daty
```

Vrátí layout formuláře a (volitelně) data záznamu.

Tyto endpointy budou implementovány postupně, podle potřeby.

---

## 8. Fáze implementace

### Fáze 1: Přihlášení a shell

1. **Inicializace Svelte projektu** — `frontend/` adresář, Vite, Svelte 5
2. **CSS základ** — variables, reset, base typography
3. **API klient** — fetch wrapper s auth
4. **Auth store** — token management, localStorage persistence
5. **LoginScreen** — přihlašovací obrazovka
6. **AppShell** — layout (sidebar + content area + header)
7. **Nginx konfigurace** — servírování frontend buildu
8. **Build pipeline** — `npm run build` → `public/app/`

### Fáze 2: Prohlížeč tabulek

9. **Navigační store** — taby, aktivní tab
10. **Sidebar** — statická navigace (hardcoded pro začátek)
11. **TableBrowser** — generický prohlížeč tabulek
12. **Základní UI komponenty** — Table, Button, Spinner, Pagination
13. **Napojení na _meta API** — dynamické sloupce

### Fáze 3: UI API a rozšířená navigace

14. **Server: `_ui/navigation` endpoint** — generování navigace z modulů
15. **Server: `_ui/browser/{table}` endpoint** — UI-specifická metadata
16. **Sidebar dynamický** — generovaný ze serveru
17. **Filtrování a řazení** v prohlížeči

### Fáze 4: Editační formuláře

18. **Server: `_ui/form/{table}` endpoint** — definice formuláře
19. **FormRenderer** — generický renderer
20. **FormField** — dynamický field renderer (podle typu)
21. **FormDialog** — modální okno s formulářem
22. **Validace** — client-side (z metadat) + server-side
23. **Rozšířené komponenty** — DatePicker, Select, Dropdown
24. **Inline editace** — editace přímo v tabulce
25. **Drag & drop** — řazení řádků

---

## 9. Konvence pro Claude Code

### Svelte komponenty

- Svelte 5 syntax (runes: `$state`, `$derived`, `$effect`)
- Jedna komponenta na soubor
- Props přes `let { prop1, prop2 } = $props()`
- Události přes callback props, ne custom events
- Žádné TypeScript v první fázi (plain JS)

### CSS

- CSS custom properties pro theming (`--color-primary`, `--spacing-md`)
- BEM-like naming: `.shpd-button`, `.shpd-button--primary`, `.shpd-button__icon`
- Scoped styles v Svelte komponentách kde to dává smysl
- Globální proměnné v `variables.css`

### API komunikace

- Vždy přes `api/client.js` (nikdy přímý fetch)
- Všechny requesty s `Authorization` hlavičkou
- Automatický 401 → refresh → retry
- Chyby propagovat přes return value, ne exceptions

### Pojmenování

- Soubory komponent: PascalCase (`LoginScreen.svelte`)
- Soubory utilit/stores: camelCase (`auth.svelte.js`)
- CSS třídy: `shpd-{component}` prefix
- API funkce: `getUsers()`, `createInvoice()`, `loginUser()`
