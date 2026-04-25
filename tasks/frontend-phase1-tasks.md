# Fáze 1: Přihlášení a aplikační shell — Tasky pro Claude Code

Tasky jsou seřazeny v pořadí implementace. Každý task je samostatně spustitelný v Claude Code.

---

## Task 1: Inicializace Svelte 5 projektu

**Prompt pro Claude Code:**

```
Create a new Svelte 5 frontend project in the `frontend/` directory of the Shipard repository.

Requirements:
- Initialize with Vite as build tool
- Svelte 5 (latest stable) with runes mode
- No TypeScript — use plain JavaScript
- Configure Vite to build output to `../public/app/` (relative to frontend/)
- Set base path to `/app/` in Vite config
- Create minimal `index.html`, `src/main.js`, and `src/App.svelte` that renders "Shipard" text
- Add `.gitignore` for node_modules, dist, .svelte-kit
- The dev server should proxy `/api/*` requests to `http://localhost:80` (PHP backend)

File structure:
```
frontend/
├── package.json
├── vite.config.js
├── svelte.config.js
├── index.html
├── .gitignore
└── src/
    ├── main.js
    └── App.svelte
```

Do NOT use TypeScript. Do NOT add any CSS framework. Do NOT add any router library.
Verify the project builds successfully with `npm run build`.
```

---

## Task 2: CSS základ

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), create the base CSS system.

Create these files in `frontend/src/styles/`:

1. `reset.css` — Modern CSS reset (box-sizing, margin/padding reset, sensible defaults for images, forms)

2. `variables.css` — CSS custom properties for the design system:
   - Colors: --shpd-color-primary (#2563eb), --shpd-color-primary-hover (#1d4ed8), --shpd-color-danger (#dc2626), --shpd-color-success (#16a34a), --shpd-color-warning (#d97706), --shpd-color-bg (#ffffff), --shpd-color-bg-secondary (#f8fafc), --shpd-color-bg-sidebar (#1e293b), --shpd-color-text (#0f172a), --shpd-color-text-secondary (#64748b), --shpd-color-text-sidebar (#e2e8f0), --shpd-color-border (#e2e8f0), --shpd-color-border-focus (#2563eb)
   - Spacing: --shpd-space-xs (4px), --shpd-space-sm (8px), --shpd-space-md (16px), --shpd-space-lg (24px), --shpd-space-xl (32px)
   - Typography: --shpd-font-family (Inter, system-ui, sans-serif), --shpd-font-size-sm (0.875rem), --shpd-font-size-base (1rem), --shpd-font-size-lg (1.125rem), --shpd-font-size-xl (1.25rem)
   - Border radius: --shpd-radius-sm (4px), --shpd-radius-md (6px), --shpd-radius-lg (8px)
   - Shadows: --shpd-shadow-sm, --shpd-shadow-md, --shpd-shadow-lg
   - Layout: --shpd-sidebar-width (260px), --shpd-header-height (56px)

3. `base.css` — Base typography and layout styles:
   - Apply font family to body
   - Set default text color and background
   - Basic heading styles (h1-h4)
   - Link styles using primary color
   - Full height html/body for app layout

Import all CSS files in `main.js` in order: reset, variables, base.

All comments in code must be in English. CSS class names use `shpd-` prefix.
```

---

## Task 3: API klient

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), create the API client layer.

Read `docs/rest-api.md` and `docs/frontend.md` for context about the API structure.

Create `frontend/src/api/client.js`:
- Export functions: `get(path)`, `post(path, body)`, `put(path, body)`, `patch(path, body)`, `del(path)`
- All functions are async and return the parsed JSON response (the envelope: {success, data, meta?, error?})
- Automatically add `Authorization: Bearer {token}` header if token exists
- Automatically add `Content-Type: application/json` and `Accept-Language: cs` headers
- Base URL: relative `/api/v1` (same origin)
- On 401 response: attempt token refresh via POST /api/v1/_auth/refresh with current token. If refresh succeeds, update stored token and retry the original request (once). If refresh fails, clear auth state and return null.
- On network error: return `{success: false, error: {code: 'NETWORK_ERROR', message: 'Network error'}}`
- Token is read from and written to localStorage key `shpd_token`

Create `frontend/src/api/auth.js`:
- Export `login(loginName, password)` — POST /api/v1/_auth/login, returns API response
- Export `refresh()` — POST /api/v1/_auth/refresh with current token, returns API response
- Export `logout()` — DELETE /api/v1/_auth/logout with current token, returns API response
- These functions use the raw fetch (not the client.js wrapper) to avoid circular dependency with the 401 handling

All code in plain JavaScript (no TypeScript). All comments in English.
```

---

## Task 4: Auth store

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), create the auth store using Svelte 5 runes.

Create `frontend/src/stores/auth.svelte.js`:

This module manages authentication state for the entire application.

State (using $state runes):
- `token` — initialized from localStorage key `shpd_token` (string or null)
- `user` — initialized from localStorage key `shpd_user` (parsed JSON object or null), contains {id, login, full_name}

Derived:
- `isAuthenticated` — $derived, true when token is not null

Exported functions:
- `setAuth(loginResponse)` — receives the successful login API response (shape: {data: {token, user, expires_at}}), stores token and user in state and localStorage
- `clearAuth()` — clears token and user from state and localStorage
- `updateToken(newToken)` — updates just the token (used after refresh), updates state and localStorage
- `getToken()` — returns current token value
- `getUser()` — returns current user value

Export the store as a single object with all state and functions.

Use Svelte 5 runes syntax ($state, $derived). All comments in English.
```

---

## Task 5: LoginScreen

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), create the login screen component.

Read `docs/frontend.md` section 3 for the login flow.

Create `frontend/src/components/auth/LoginScreen.svelte`:

A centered login form with:
- Application name "Shipard" as heading
- Input field for "Přihlašovací jméno" (login name)
- Input field for "Heslo" (password, type=password)
- Button "Přihlásit se" (submit)
- Error message area (shown when login fails, displays the error message from API response)
- Loading state: button shows "Přihlašování..." and is disabled while request is in progress
- Form submits on Enter key
- Auto-focus on the login name field on mount

Behavior:
- On submit: call auth.js login() function
- On success: call authStore.setAuth() with the response, then call an `onSuccess` callback prop
- On failure: display error message from the API response
- Disable form during submission

Styling:
- Use CSS custom properties from variables.css (--shpd-color-*, --shpd-space-*, etc.)
- Center the form on screen (both horizontally and vertically)
- Form width: max 400px
- Clean, professional look — white card on light gray background
- Input styling: full width, proper padding, border, focus state with --shpd-color-border-focus
- Button: full width, primary color, hover state
- BEM-like CSS class names with `shpd-` prefix (e.g., .shpd-login, .shpd-login__input)
- Use <style> block within the Svelte component for scoped styles

Use Svelte 5 runes. All comments in English. UI text in Czech.
```

---

## Task 6: Aplikační shell (layout)

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), create the application shell layout components.

Read `docs/frontend.md` section 4 for the shell structure.

Create these components:

### 1. `frontend/src/components/layout/Header.svelte`
Top header bar (height: --shpd-header-height):
- Left: "Shipard" logo/text
- Right: user display (full_name from auth store) + "Odhlásit" button
- On logout click: call auth.js logout(), then authStore.clearAuth(), then call `onLogout` callback prop
- Dark or white background, clean horizontal bar

### 2. `frontend/src/components/layout/Sidebar.svelte`
Left sidebar (width: --shpd-sidebar-width):
- Dark background (--shpd-color-bg-sidebar)
- Light text (--shpd-color-text-sidebar)
- For now: hardcoded navigation items (we'll make it dynamic later):
  - "Systém" group with "Uživatelé" item
  - "Ekonomika" group with "Doklady" sub-group containing "Faktury vydané" and "Faktury přijaté"
- Each item is clickable, calls `onNavigate` callback prop with {id, label, type: "table", table: "table_name"}
- Active item is highlighted
- Collapsible groups (click group header to expand/collapse)

### 3. `frontend/src/components/layout/ContentArea.svelte`
Main content area:
- Takes up remaining space (right of sidebar, below header)
- For now: displays a welcome message "Vyberte položku v menu" when nothing is selected
- Has a slot or children prop for future content

### 4. `frontend/src/components/layout/AppShell.svelte`
Combines Header + Sidebar + ContentArea into the main app layout:
- Uses CSS Grid or Flexbox for the layout
- Header spans full width at top
- Sidebar on left, ContentArea fills remaining space
- Full viewport height (100vh)
- Accepts `onLogout` prop

### 5. Update `frontend/src/App.svelte`
Root component that switches between LoginScreen and AppShell:
- Import auth store
- If not authenticated: show LoginScreen
- If authenticated: show AppShell
- Handle login success (LoginScreen) → switch to AppShell
- Handle logout (AppShell) → switch to LoginScreen

All styling uses CSS custom properties from variables.css. BEM-like class names with `shpd-` prefix.
Use Svelte 5 runes. All comments in English. UI text in Czech.
```

---

## Task 7: Nginx konfigurace

**Prompt pro Claude Code:**

```
In the Shipard repository, create nginx configuration for serving the frontend alongside the existing API.

Create `docs/nginx/app.conf` (or update existing nginx docs) with the configuration needed to serve the frontend SPA.

The key additions to existing nginx config:

1. The frontend is built to `public/app/` directory
2. All requests to `/app` and `/app/*` should serve the frontend SPA
3. For SPA routing: any path under `/app/` that doesn't match a real file should fall back to `/app/index.html`
4. API requests (`/api/*`) continue to go to PHP as before
5. Root `/` should redirect to `/app`

Both development mode (IP-based) and production mode (subdomain-based) need this.

Create the nginx config snippet and add deployment notes to `docs/frontend.md` if needed.
Also update `frontend/vite.config.js` if the base path needs adjustment.
```

---

## Task 8: Build pipeline a .gitignore

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), finalize the build pipeline.

1. Verify `vite.config.js` builds to `../public/app/` with base `/app/`

2. Add npm scripts to `package.json`:
   - "dev": starts Vite dev server with API proxy to localhost
   - "build": production build to ../public/app/
   - "preview": preview production build

3. Update root `.gitignore` to include:
   - frontend/node_modules/
   - public/app/ (build output — generated, not committed)

4. Add `frontend/.gitignore` for:
   - node_modules/
   - dist/

5. Verify the full flow works:
   - `cd frontend && npm install && npm run build`
   - Check that files appear in `public/app/`
   - Check that `public/app/index.html` exists and references the correct asset paths

6. Add a `frontend/README.md` with brief setup instructions:
   - Prerequisites (Node.js 20+)
   - npm install
   - npm run dev (development)
   - npm run build (production build)
```

---

## Pořadí spouštění

Spouštěj tasky v pořadí 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8. Každý task závisí na předchozích.

Po dokončení všech tasků bys měl mít:
- Funkční přihlašovací obrazovku
- Aplikační shell s hardcoded sidebar
- Připravený základ pro Fázi 2 (prohlížeč tabulek)
