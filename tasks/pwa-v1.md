# PWA V1 — instalovatelná aplikace (issue #52)

**Stav:** hotovo — implementováno 2026-08-29; ruční ověření instalace na alfě (Chrome/Android, iOS Safari) po deployi

> PRD pro jednu Claude Code session. Řeší první krok issue #52 —
> instalaci DS aplikace do telefonu/desktopu. Focení dokladů a push
> notifikace jsou mimo scope (samostatné issues/debaty). Hosting PWA
> odloženo (D5).

## Kontext

Aplikace (Svelte 5 SPA v `public/app/`, servírovaná nginx) zatím není
instalovatelná jako PWA. Od ~2023 stačí pro instalační prompt
v Chrome/Edge **jen web app manifest** (+ HTTPS) — service worker už
není podmínkou; Safari umí „Přidat na plochu" vždy, Firefox 143+ na
Windows. V1 je tedy manifest-only: žádný service worker, žádná offline
cache, žádné starosti s invalidací po deployi.

Jméno a ikona aplikace jsou per-DS (settings `app.name`/`app.shortName`,
branding slot `icon`) — manifest proto generuje PHP, ne statický soubor.

## Rozhodnutí (odsouhlaseno v chatu)

| # | Rozhodnutí |
|---|---|
| D1 | **Bez service workeru.** Manifest-only; offline režim V1 neřeší. SW přijde až s push notifikacemi (vlastní issue). |
| D2 | **Manifest generuje PHP per-DS** — veřejný endpoint `GET /_app/manifest` (vzor `/_app/info`: bez auth, nic citlivého). `name`/`short_name` z `app.name`/`app.shortName` (fallback jako `AppController::info()`), `start_url`/`scope`/`id` dle režimu (prod `/app/`, dev `/{ds-id}/app/`). |
| D3 | **Relativní link v `index.html`:** `<link rel="manifest" href="../api/v1/_app/manifest">` — resolvuje se proti `/app/` (prod) i `/{ds-id}/app/` (dev) na správnou API cestu. **Žádná změna nginx** (upřesnění původního D3 — nginx location není potřeba). |
| D4 | **Ikony: statická defaultní sada**, per-DS branding ikony jako follow-up. PNG 192/512 + maskable varianty v `frontend/public/icons/` (Vite je kopíruje do `public/app/icons/`). Manifest odkazuje absolutními cestami odvozenými z režimu. `apple-touch-icon` (180×180) relativním linkem v `index.html`. |
| D5 | **Hosting PWA odloženo.** PWA je vázaná na origin; DS appky na jiných doménách do hosting PWA zabalit nejde. Zůstává otevřený bod v issue #52. |
| D6 | `display: "standalone"`, `theme_color`/`background_color` staticky ze Shipard palety (hodnoty z `--shpd-color-primary` / `--shpd-color-bg` ve `variables.css` — opsat literály, ne runtime derivace). Žádné vlastní instalační tlačítko (Chrome nabízí sám, iOS `beforeinstallprompt` nepodporuje). |

## Cíl

1. `GET /api/v1/_app/manifest` — veřejný, vrací validní web app manifest
   (`application/manifest+json`) s per-DS jménem a správným
   `start_url`/`scope`/`id` pro produkci i dev mód.
2. Defaultní sada ikon v buildu (`192`, `512`, obě i v maskable
   variantě, `apple-touch-icon.png` 180×180).
3. `frontend/index.html` — link na manifest + `apple-touch-icon` +
   `<meta name="theme-color">`.
4. Aplikace na alfě (subdoména, HTTPS) nabídne instalaci v Chrome
   (ikona v omniboxu / „Přidat na plochu" na Androidu) a jde přidat
   na plochu v iOS Safari se správným jménem a ikonou.

## Návaznost

- **Odemyká:** push notifikace (SW si přinese ta issue), focení dokladů
  z nainstalované aplikace, per-DS instalační ikony z branding slotu.
- **Nezávislé na:** #34 (Gotenberg), #40 (datasety).

## Před implementací přečti

- `src/Api/Controller/AppController.php` — `info()` (fallback name/
  shortName — stejnou logiku použije manifest; NEduplikovat, vytáhnout
  do privátního helperu), `brandingGet()` (vzor Response s vlastním
  Content-Type)
- `src/Api/Response.php` — `raw()` / custom `Content-Type` mechanika
  (ř. ~28 a ~60)
- `src/Api/Router.php` ř. ~115 (`/_app/info`) — sem přibude
  `/_app/manifest`, stejný tvar
- `src/Api/Middleware/AuthMiddleware.php` — `isExempt()`: přidat
  `(app, manifest)` vedle existujících výjimek; komentář proč (veřejný
  manifest, prohlížeč ho fetchuje bez tokenu)
- `frontend/src/api/config.js` — jak se detekuje DS ID z URL (stejnou
  informaci potřebuje backend z `REQUEST_URI` — zjisti, jak Router/
  index.php parsuje DS prefix, a použij existující mechanismus, ne nový
  regex)
- `frontend/index.html` — hlavička s bootstrap scripty (linky přijdou
  za ně); POZOR na `public/app/index.html` — je to build artefakt,
  edituje se jen `frontend/index.html`
- `frontend/vite.config.js` — `publicDir` (default `frontend/public/`,
  zatím neexistuje) → kopíruje se do `outDir`; ověř, že `emptyOutDir`
  ikony při buildu nepřepíše špatně
- `docs/nginx/app.conf` — jen pro pochopení URL vzorů (nic se nemění)

## Scope

### 1. Ikony — `frontend/public/icons/`

Nový adresář. Žádný rastrový Shipard logo asset neexistuje
(BrandingHeader je textový) → **vygenerovat programově** jednoduchou
defaultní ikonu: písmeno „S" (bílé, bezpatkové, bold) na pozadí Shipard
primary barvy, zaoblené rohy u ne-maskable variant. Generátor jako
jednorázový skript (PHP GD nebo node canvas — co je po ruce), výstupy
commitnout, skript zahodit (negeneruje se při buildu):

- `icon-192.png`, `icon-512.png` — `purpose: any`
- `icon-maskable-192.png`, `icon-maskable-512.png` — motiv zmenšený do
  safe zone (80 % plochy), pozadí do krajů
- `apple-touch-icon.png` — 180×180, bez průhlednosti

Soubory jsou záměrně statické — výměna za „skutečné" logo = přepsání
PNG, žádná změna kódu.

### 2. Backend — `AppController::manifest()` + Router + AuthMiddleware

- Router: `GET /_app/manifest` → `Route('app', 'manifest')`; jiné metody
  405 (vzor `/_app/info`).
- AuthMiddleware `isExempt()`: `+ (app, manifest)`.
- `AppController::manifest()`:
  - name/shortName: sdílený helper s `info()` (`app.name` →
    `config->getName()`; shortName fallback na name).
  - Prefix režimu: dev = DS ID v cestě, prod = bez. Odvodit ze stejného
    místa, kde ho řeší routing (ne vlastní parsování). `base` =
    `/{ds-id}` nebo ``.
  - Tělo:
    ```json
    {
      "name": "<name>",
      "short_name": "<shortName>",
      "id": "<base>/app/",
      "start_url": "<base>/app/",
      "scope": "<base>/app/",
      "display": "standalone",
      "lang": "cs",
      "theme_color": "<primary>",
      "background_color": "<bg>",
      "icons": [
        {"src": "<base>/app/icons/icon-192.png", "sizes": "192x192", "type": "image/png"},
        {"src": "<base>/app/icons/icon-512.png", "sizes": "512x512", "type": "image/png"},
        {"src": "<base>/app/icons/icon-maskable-192.png", "sizes": "192x192", "type": "image/png", "purpose": "maskable"},
        {"src": "<base>/app/icons/icon-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable"}
      ]
    }
    ```
  - Ikony absolutními cestami záměrně — relativní by se resolvovaly
    proti URL manifestu (`/api/v1/…`), ne proti scope. Komentář do kódu.
  - Content-Type `application/manifest+json`, `Cache-Control:
    public, max-age=3600` (jméno se mění zřídka; prohlížeč manifest
    stejně re-fetchuje při návštěvách).
  - Docblock třídy: doplnit endpoint do přehledu.

### 3. Frontend — `frontend/index.html`

Do `<head>` (za bootstrap scripty):

```html
<link rel="manifest" href="../api/v1/_app/manifest" />
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png" />
<meta name="theme-color" content="<primary>" />
```

Komentář k relativním href: dokument žije vždy přesně na `/app/` resp.
`/{ds-id}/app/` (SPA bez URL routingu), relativní cesty proto míří
správně v obou režimech; kdyby někdy přibylo URL routing s hlubšími
cestami, je potřeba `<base>` nebo absolutizace — poznamenat.

`<meta name="theme-color">` je statická (Shipard primary) — dynamické
přebarvování podle DS theme je mimo scope, nekolidovat s theme
bootstrapem.

### 4. Dokumentace

- `docs/frontend.md` — nová sekce **PWA** (krátká: manifest-only
  rozhodnutí D1, endpoint, relativní linky, kde žijí ikony, co je
  follow-up: per-DS ikony, push+SW, hosting).
- `docs/rest-api.md` — `GET /_app/manifest` do přehledu veřejných
  endpointů (vedle `/_app/info`, se stejným varováním „nic citlivého").
- `docs/frontend.md` tabulka v sekci 8 (UI API endpointy): přidat řádek.

## Testy

PHPUnit (vzor testů AppControlleru, úzký `--filter`, `timeout_sec: 120`):

- manifest je dostupný bez tokenu (exempt),
- `name`/`short_name` respektují `app.name`/`app.shortName` vč. fallbacků,
- `start_url`/`scope`/`id`/ikony nesou DS prefix v dev režimu a jsou
  bez něj v prod režimu,
- jiná metoda než GET → 405,
- Content-Type `application/manifest+json`.

Frontend testy nepřibývají (statické linky). Ruční ověření na alfě
(po deployi, mimo tuto session): Chrome DevTools → Application →
Manifest bez chyb; instalace na Android + iOS.

## Commit strategie

1. `feat(pwa): default app icons` — `frontend/public/icons/*`
2. `feat(pwa): manifest endpoint` — Router, AuthMiddleware,
   AppController + testy
3. `feat(pwa): manifest + touch icon links in index.html` — index.html
   + rebuild frontendu (`public/app/`)
4. `docs(pwa): frontend.md + rest-api.md`

## Hotovo když

- [x] `frontend/public/icons/` obsahuje 5 PNG (192, 512, 2× maskable,
      apple-touch) a po `npm run build` jsou v `public/app/icons/`
- [x] `GET /api/v1/_app/manifest` vrací validní manifest bez tokenu,
      s per-DS jménem a správným prefixem v obou režimech
- [x] `frontend/index.html` má manifest link, apple-touch-icon
      a theme-color; `public/app/index.html` rebuildnutý
- [x] PHPUnit testy manifestu zelené (úzký filter)
- [x] `docs/frontend.md` a `docs/rest-api.md` aktualizované
- [x] Žádná změna nginx konfigurace
