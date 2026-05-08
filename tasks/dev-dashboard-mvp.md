# Dev dashboard — MVP seznam datových zdrojů

## Status / Cíl

V development módu zpřístupnit jednoduchou webovou stránku, která vypíše
všechny datové zdroje s aktivními odkazy do aplikace. Eliminuje ruční
hledání DS ID a skládání URL při testování.

Záměrně MVP — jen seznam DS s "Open" tlačítkem, refresh a auto-refresh.
Log viewer a vytváření DS přes UI jsou na pozdější fáze.

## Návaznost

- Funguje **jen v `mode: development`** (z `/etc/shipard/server.json`).
  V produkci je `/_dev/...` neexistující route → 404.
- Hookuje se v `public/index.php` **před** `DataSourceResolver`,
  protože dashboard nepatří k žádnému konkrétnímu DS.
- Existující dev URL pattern: `http://{ip}/{ds-id}/app/` (viz
  `docs/nginx/development.conf`). Dashboard žije na `http://{ip}/_dev/`,
  klikací odkazy směřují na `/{ds-id}/app/`.

## Architektura

### Routing flow v `public/index.php`

Po načtení `ServerConfig` (sekce 2 v aktuálním `index.php`), před
`DataSourceResolver` (sekce 3), přidat:

```php
// ── 2.5. Dev dashboard ───────────────────────────────────────────────────
if ($serverConfig->getMode() === 'development') {
    $path = $request->getPath();
    if ($path === '/' || $path === '/_dev' || str_starts_with($path, '/_dev/')) {
        $response = (new \Shipard\Api\Controller\DevDashboardController())
            ->dispatch($request);
        $corsMiddleware->applyTo($response)->send();
        exit;
    }
}
```

Když mode není `development`, větev se přeskočí a `/_dev/...` propadne
na resolver, který hodí `UnknownDataSourceException` → 404. Stejně tak
i `/` v produkci — chování beze změny.

### Komponenty

```
src/Api/Controller/DevDashboardController.php   ← nový
src/Api/Response.php                             ← rozšířit o html() a redirect()
public/index.php                                 ← hook před resolverem
tests/Unit/Api/Controller/DevDashboardControllerTest.php   ← nový
```

## Co je potřeba udělat

### 1. Rozšíření `src/Api/Response.php`

Aktuální `send()` hardcoduje `Content-Type: application/json`. Přidat
podporu pro HTML a redirect bez rozbití existujícího chování.

#### Nové factory metody

```php
public static function html(string $body, int $status = 200): self
{
    $resp = new self($status, $body);
    $resp->bodyType = 'html';
    return $resp;
}

public static function redirect(string $location, int $status = 302): self
{
    $resp = new self($status, '');
    $resp->bodyType = 'redirect';
    $resp->headers['Location'] = $location;
    return $resp;
}
```

#### Drobná úprava `send()`

Přidat private property `$bodyType = 'json'` (default zachová stávající
chování). V `send()` rozhodnout podle `bodyType`:

```php
public function send(): void
{
    foreach ($this->headers as $name => $value) {
        header("{$name}: {$value}");
    }
    http_response_code($this->status);

    if ($this->status === 204 || $this->bodyType === 'redirect') {
        return;
    }

    if ($this->bodyType === 'html') {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->payload;
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
```

Existující JSON volání zůstávají beze změny — `bodyType` má default `'json'`.

### 2. Nový soubor: `src/Api/Controller/DevDashboardController.php`

```php
<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;

final class DevDashboardController
{
    public function __construct(
        private readonly string $dataSourcesDir = '/opt/shipard/data-sources',
    ) {}

    public function dispatch(Request $request): Response
    {
        $path = $request->getPath();

        if ($path === '/') {
            return Response::redirect('/_dev/');
        }

        if ($path === '/_dev' || $path === '/_dev/') {
            return $this->page();
        }

        if ($path === '/_dev/api/data-sources' && $request->getMethod() === 'GET') {
            return $this->listDataSources();
        }

        return Response::error('NOT_FOUND', 'Not found', 404);
    }

    private function listDataSources(): Response
    {
        $items = [];
        $dirs = glob($this->dataSourcesDir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $dsDir) {
            $configFile = $dsDir . '/config/main.json';
            if (!is_file($configFile)) {
                continue;
            }

            $content = @file_get_contents($configFile);
            if ($content === false) {
                continue;
            }
            $config = json_decode($content, true);
            if (!is_array($config) || !isset($config['id'], $config['name'])) {
                continue;
            }

            $items[] = [
                'id'            => $config['id'],
                'name'          => $config['name'],
                'created'       => $config['created'] ?? null,
                'database_name' => $config['database_name'] ?? null,
            ];
        }

        usort($items, fn($a, $b) => strcmp(
            mb_strtolower($a['name']),
            mb_strtolower($b['name']),
        ));

        return Response::success($items);
    }

    private function page(): Response
    {
        $hostname = htmlspecialchars(gethostname() ?: 'unknown', ENT_QUOTES, 'UTF-8');
        $html = $this->renderHtml($hostname);
        return Response::html($html);
    }

    private function renderHtml(string $hostname): string
    {
        // HTML+CSS+JS jako jediný řetězec — viz sekce 3 níže.
        // Implementuj jako PHP heredoc se substitucí jediné proměnné $hostname.
        return <<<HTML
        ...kompletní HTML dle specifikace v sekci 3...
        HTML;
    }
}
```

**Důvod přepínače `dataSourcesDir` v konstruktoru:** testovatelnost přes
DI (test používá temp dir).

**`gethostname()`** je PHP built-in, čte interně z `/etc/hostname`.
Žádná závislost na konkrétní cestě, žádný cache problém.

### 3. HTML stránka — detailní specifikace

Single-file HTML+CSS+JS, vanilla (žádné externí závislosti, žádný
build step). Cílová délka: 150–200 řádků v heredoc.

#### Layout

```
┌─────────────────────────────────────────────┐
│ ⚠️  DEVELOPMENT MODE — do not deploy        │  ← oranžový banner
├─────────────────────────────────────────────┤
│  Shipard Dev Dashboard       Server: kuba   │  ← tmavý header
├─────────────────────────────────────────────┤
│                                             │
│  [Refresh]  Auto-refresh in 60s             │
│                                             │
│  ┌───────────┬──────────┬─────────┬───────┐ │
│  │ ID        │ Name     │ Created │ DB    │ │  ← tabulka
│  ├───────────┼──────────┼─────────┼───────┤ │
│  │ aaaa-...📋│ Demo Co  │ 2024-…  │ xxx   │ [Open]
│  │ bbbb-...📋│ Test     │ 2024-…  │ yyy   │ [Open]
│  └───────────┴──────────┴─────────┴───────┘ │
└─────────────────────────────────────────────┘
```

#### Stylistické konvence

- `font-family: system-ui, -apple-system, sans-serif` — standard fonts
- ID a database_name v `font-family: monospace`
- Banner: `background: #d97706; color: white; padding: 8px;`
- Header: `background: #1f2937; color: white; padding: 16px 24px;`
- Tabulka: alternating row colors, hover effect
- Tlačítka standardní HTML, bez framework CSS
- `max-width: 1200px` pro `<main>`, vycentrovaná
- Čistě světlý vzhled (žádné dark mode přepínání)

#### JS chování

- `loadData()` — `fetch('/_dev/api/data-sources')`, render řádků v tabulce
- Render přes `document.createElement` + `textContent` (XSS-safe), **ne** přes
  template literal s `innerHTML`
- Click-to-copy ID: `📋` ikonka volá `navigator.clipboard.writeText(id)`,
  na úspěch dočasně zobrazí `✓` (1 s)
- "Open" tlačítko je `<a href="/{id}/app/" target="_blank">` se stylem
  jako tlačítko (žádná onclick navigace, ať funguje middle-click i
  Ctrl+click)
- Refresh tlačítko volá `loadData()` okamžitě a resetuje countdown
- Countdown: `setInterval` po 1 s, dekrement čísla, na 0 pustí
  `loadData()` a vrátí na 60
- Při `loadData()` failu: zobrazit `<tr><td colspan="5">Failed to load
  data sources</td></tr>` + console.error
- Když je seznam prázdný: `<tr><td colspan="5">No data sources found.
  Run <code>sudo shpd-server ds-create --name &lt;n&gt;</code></td></tr>`
- Při startu page: zavolat `loadData()` jednou hned

#### PHP heredoc — escapování `${}` v JS

V heredoc se `${...}` interpoluje PHP. JS template literals tedy buď
neużívat (preferováno — používáme `createElement` + `textContent`), nebo
escape `\${...}`. Doporučuju: žádné JS template literals, vše přes DOM
API.

`$hostname` se v heredoc interpoluje normálně — je to jediná
substituce na page-level, navíc už je escapovaná `htmlspecialchars`.

### 4. Hook v `public/index.php`

Vložit blok ze sekce *Architektura → Routing flow* mezi sekce 2
("Server config") a 3 ("Resolve data source") existujícího `index.php`.
Použít explicitní komentář `// ── 2.5. Dev dashboard ──` ve stylu
ostatních sekcí.

### 5. Testy: `tests/Unit/Api/Controller/DevDashboardControllerTest.php`

Vzor: subclassing přes konstruktor (DS dir je injectable).

`setUp()` vytvoří temp dir přes `sys_get_temp_dir() . '/shpd-dev-test-' . uniqid()`.
`tearDown()` rekurzivně smaže.

Helper `createDs(string $id, string $name, ?string $created = null)`:
vytvoří `$tmpDir/$id/config/main.json` s validním JSON.

Použij `Shipard\Api\Request::fromArray(...)` pro vytvoření request objektů.

Test cases:

- **`testRootPathRedirects`**: `dispatch(GET /)` → status 302, header
  `Location: /_dev/`
- **`testDashboardPageReturnsHtml`**: `dispatch(GET /_dev/)` → status 200,
  body obsahuje `DEVELOPMENT MODE`, `Shipard Dev Dashboard`, hostname
- **`testDashboardPageWithoutTrailingSlash`**: `dispatch(GET /_dev)` → status
  200 (stejné jako `/_dev/`)
- **`testListDataSourcesReturnsSorted`**: vytvoř 3 DS s názvy "Charlie",
  "Alpha", "Bravo" → JSON response má items v pořadí Alpha, Bravo,
  Charlie
- **`testListDataSourcesSkipsDirsWithoutMainJson`**: vytvoř 1 valid DS +
  prázdný adresář `lost+found` → response má jen 1 item
- **`testListDataSourcesSkipsCorruptJson`**: vytvoř DS s rozbitým JSON
  v main.json → skip, response má 0 items, žádná exception
- **`testListDataSourcesEmpty`**: prázdný dataSourcesDir → status 200,
  data: []
- **`testUnknownPathReturns404`**: `dispatch(GET /_dev/random)` → status
  404
- **`testApiDataSourcesPostReturns404`**: `dispatch(POST /_dev/api/data-sources)`
  → 404 (jen GET)

## Bezpečnost

- **Mode check je jediný gate** — jakmile je `mode: development`,
  dashboard je veřejný. Filozofie: dev server musí být na trusted síti.
- **Nikdy nezobrazovat:** `database_password`, cestu k `secrets/secrets.key`,
  decryptnuté hodnoty z `encrypted_text` sloupců.
- **Banner nahoře** ("DEVELOPMENT MODE — do not deploy publicly") je
  vizuální ochrana, aby si nikdo nespletl prostředí.
- Žádný IP whitelist — komplikuje legitimní remote dev workflow.

## Co netřeba

- Žádný build step (vanilla HTML+CSS+JS)
- Žádné externí knihovny (žádný React, Tailwind, Bootstrap)
- Žádná autentizace (mode check stačí)
- Log viewer — fáze 2
- Vytvoření DS přes UI — fáze 3
- Per-DS quick actions (delete, upgrade) — fáze 4
- Žádná i18n — UI jen anglicky (dev nástroj)

## Konvence k dodržení

- PHP `declare(strict_types=1)`, PSR-4
- HTML lang="en", anglické UI texty
- Vanilla JS (`document.createElement`, `textContent`), žádné template
  literals s `innerHTML`
- `gethostname()`, ne `file_get_contents('/etc/hostname')`
- Existující JSON Response volání **nesmí změnit chování** — default
  `bodyType = 'json'`
- Test pattern: konstruktor s injectable `dataSourcesDir`, ne mockování

## Hotovo když

- `vendor/bin/phpunit` projde, včetně 9 nových test cases
- V dev módu `curl http://{ip}/` vrátí 302 s Location `/_dev/`
- V dev módu `curl http://{ip}/_dev/` vrátí HTML obsahující název hostu
  ze `gethostname()`
- V dev módu `curl http://{ip}/_dev/api/data-sources` vrátí JSON se
  seznamem DS (seřazeno podle názvu)
- V produkčním módu `curl https://customer.example.com/_dev/` vrátí 404
  (`UNKNOWN_DATASOURCE` nebo `UNKNOWN_HOST`)
- V dev módu otevření `http://{ip}/` v prohlížeči zobrazí dashboard,
  click na "Open" otevře `http://{ip}/{ds-id}/app/` v novém tabu
- Auto-refresh viditelně odpočítává a po 60 s znova načte data
- Click-to-copy zkopíruje ID do clipboardu a krátce zobrazí potvrzení
- `docs/cli.md` ani jiná dokumentace nepotřebuje update — dashboard
  je samodokumentační a `DEVELOPERS.md` na něj odkáže v navazujícím
  drobném tasku
