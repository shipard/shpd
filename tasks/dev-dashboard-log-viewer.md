# Dev dashboard — log viewer

**Stav:** hotovo

## Status / Cíl

Doplnění dev dashboardu o log viewer na URL `/_dev/logs/`. Pohodlné
prohlížení posledních záznamů z `/opt/shipard/log/shipard.log` s
vizualizací (color-coded úrovně, expandovatelný detail, exception trace,
chained `previous`), filtrováním (level, DS, fulltext) a auto-refresh
chováním podobným `tail -f`.

## Návaznost

- Vychází z [`dev-dashboard-mvp.md`](dev-dashboard-mvp.md) — fáze 1, hotová.
- Funguje **jen v `mode: development`** — gate je už v `public/index.php`
  z fáze 1, jen rozšíříme routing v `DevDashboardController::dispatch()`.
- Formát logu definovaný v [`docs/logging.md`](../docs/logging.md):
  jeden JSON record per řádek, pole `ts`, `level`, `ds`, `request`,
  `msg`, `exception`, `ctx`. Cesta z `ServerConfig::getLogFile()`,
  default `/opt/shipard/log/shipard.log`.

## Komponenty

```
src/Core/Logging/LogTail.php                            ← nový
src/Api/Controller/DevDashboardController.php           ← rozšířit
public/index.php                                        ← drobná úprava (předat log path)
tests/Unit/Core/Logging/LogTailTest.php                 ← nový
tests/Unit/Api/Controller/DevDashboardControllerTest.php ← rozšířit
```

## Co je potřeba udělat

### 1. Nový soubor: `src/Core/Logging/LogTail.php`

Helper pro chunked-backwards čtení posledních N řádků ze souboru.
Žádný shell-out, čistě PHP, testovatelné.

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Logging;

/**
 * Reads the last N lines of a file by reading chunks from the end.
 * Memory-bounded: never loads more than ~chunkSize × (limit/avg-line-length) bytes.
 */
final class LogTail
{
    public function __construct(
        private readonly string $path,
        private readonly int $chunkSize = 8192,
    ) {}

    /**
     * @return list<string> Last $limit non-empty lines (in original order).
     *                      Returns [] when file does not exist or is empty.
     */
    public function readLast(int $limit): array
    {
        if ($limit <= 0) return [];
        if (!is_file($this->path) || !is_readable($this->path)) return [];

        $size = filesize($this->path);
        if ($size === false || $size === 0) return [];

        $fp = fopen($this->path, 'rb');
        if ($fp === false) return [];

        $buffer = '';
        $pos = $size;
        $foundNewlines = 0;

        try {
            while ($pos > 0) {
                $readSize = (int) min($this->chunkSize, $pos);
                $pos -= $readSize;
                if (fseek($fp, $pos) !== 0) break;
                $chunk = fread($fp, $readSize);
                if ($chunk === false) break;
                $buffer = $chunk . $buffer;
                $foundNewlines = substr_count($buffer, "\n");

                // Have enough complete lines? +1 because the first line in
                // buffer may be partial until we reach BOF.
                if ($foundNewlines >= $limit + 1) break;
            }
        } finally {
            fclose($fp);
        }

        $allLines = explode("\n", $buffer);
        // Strip empty/whitespace-only entries (trailing newline, partial first line)
        $allLines = array_values(array_filter(
            $allLines,
            static fn(string $l): bool => trim($l) !== '',
        ));

        // If we hit BOF without finding enough newlines, the very first entry
        // is a complete line. Otherwise the first entry might be partial — but
        // since we read $limit + 1 newlines, slicing the last $limit drops it.
        return array_slice($allLines, -$limit);
    }
}
```

**Edge cases pokryté konstrukcí:**
- Neexistující/nečitelný soubor → `[]`
- Prázdný soubor → `[]`
- Soubor končící bez `\n` → poslední řádek se vrátí
- `\r\n` line endings — funguje, jen `\r` zůstane v řetězci (logger píše
  `\n`, takže není reálné)
- Řádky delší než `chunkSize` — postupně se přečtou, žádná oříznutí

### 2. Rozšíření `src/Api/Controller/DevDashboardController.php`

#### Rozšířit konstruktor o cestu k log souboru

```php
public function __construct(
    private readonly string $dataSourcesDir = '/opt/shipard/data-sources',
    private readonly ?string $logFilePath = null,
) {}
```

`?string` aby existující testy z fáze 1 nebyly rozbité — když se
nepředá, log endpointy vrátí 503 (viz níže).

#### Rozšíření `dispatch()`

Po existujících case přidat:

```php
if ($path === '/_dev/logs' || $path === '/_dev/logs/') {
    return $this->logsPage();
}

if ($path === '/_dev/api/logs' && $request->getMethod() === 'GET') {
    return $this->getLogs($request);
}
```

#### Nová metoda `logsPage(): Response`

Stejný pattern jako `page()` — vrátí `Response::html(...)` s inline
HTML+CSS+JS. Detaily v sekci 3.

#### Nová metoda `getLogs(Request $request): Response`

```php
private function getLogs(Request $request): Response
{
    if ($this->logFilePath === null) {
        return Response::error(
            'LOG_NOT_CONFIGURED',
            'Log file path not configured',
            503,
        );
    }

    $limit = (int) ($request->getQueryParams()['limit'] ?? 200);
    $limit = max(1, min(2000, $limit));

    $logFile = $this->logFilePath;

    if (!is_file($logFile)) {
        return Response::success([
            'entries'   => [],
            'logFile'   => $logFile,
            'available' => false,
            'limit'     => $limit,
        ]);
    }

    $tail = new \Shipard\Core\Logging\LogTail($logFile);
    $rawLines = $tail->readLast($limit);

    $entries = [];
    foreach ($rawLines as $line) {
        $parsed = json_decode($line, true);
        // Validujeme jen minimální kontrakt — `ts` a `level`. Cokoli jiného
        // může chybět (starší záznamy, partial migrace).
        if (is_array($parsed) && isset($parsed['ts'], $parsed['level'])) {
            $entries[] = $parsed;
        }
        // Nevalidní řádky tiše přeskočíme (typicky partial first line z chunked read,
        // pokud limit přesně odpovídá počtu řádků v souboru).
    }

    return Response::success([
        'entries'   => $entries,
        'logFile'   => $logFile,
        'available' => true,
        'limit'     => $limit,
    ]);
}
```

### 3. HTML stránka `/_dev/logs/`

Single-file HTML+CSS+JS jako `page()`, vanilla JS, bez build stepu.
Cílová délka cca 350–450 řádků v heredoc (logika je bohatší než MVP
dashboard).

#### Layout

```
┌────────────────────────────────────────────────────────────────────┐
│ ⚠️  DEVELOPMENT MODE — do not deploy publicly                      │
├────────────────────────────────────────────────────────────────────┤
│  Shipard Logs            Server: kuba       [← Dashboard]          │
├────────────────────────────────────────────────────────────────────┤
│  Levels: [DEBUG] [INFO] [WARN✓] [ERROR✓]   DS: [▼ All ▾]           │
│  Search: [_______________________________]                         │
│  [Refresh]  ⏸ Pause   • Auto-refresh in 5s                         │
├────────────────────────────────────────────────────────────────────┤
│  12:34:56  ERROR  4l3j…  GET /api/v1/persons   Failed to save…     │
│  12:34:55  WARN   4l3j…  POST /api/v1/...      Viewer not found    │
│  12:34:54  INFO   bb1c…  GET /api/v1/...       User logged in      │
│  ...                                                                │
│                                                                     │
│              [ Load older entries (limit: 400) ]                    │
└────────────────────────────────────────────────────────────────────┘
```

#### URL parameter pro per-DS pre-filter

Stránka při načtení čte `URLSearchParams.get('ds')`. Pokud je
nastaveno, předvyplní DS dropdown. To je vstupní bod pro odkaz
"Logs" z hlavního dashboardu.

#### Stylistické konvence

- Stejné fonty/colors jako MVP dashboard (`system-ui`, banner orange,
  header dark)
- **Level badges:**
  - `debug` — light gray bg (`#e5e7eb`), dark gray text
  - `info` — light blue bg (`#dbeafe`), blue text (`#1e40af`)
  - `warn` — amber bg (`#fef3c7`), dark amber text (`#92400e`)
  - `error` — red bg (`#fee2e2`), dark red text (`#991b1b`)
- Level a DS chip ve výpisu jsou klikatelné (cursor: pointer, hover
  underline) — klik aplikuje filter
- Time, DS ID, request path, exception location v `font-family: monospace`,
  `font-size: 0.85em`
- Expanded detail: světle šedé pozadí (`#f9fafb`), levý border 3px barvy
  level badge, padding, monospace pro JSON / trace
- Pause tlačítko v pause stavu má červený puntík; v běžícím stavu zelený

#### JS chování — filtry (klient-side)

State na page-level:

```js
const state = {
    limit: 200,
    levels: { debug: false, info: false, warn: true, error: true },
    ds: '',          // '' = all, '__null__' = entries with ds: null, jinak DS ID
    search: '',
    paused: false,
    expanded: new Set(),  // indices of expanded entries
    countdown: 5,
    entries: [],     // raw from API
};
```

Render přepočítá filtered entries z `state.entries` při každé změně
state. Čtení: vždy fetch celého `state.limit`, JS aplikuje filtry.

Filter logika:

```js
function applyFilters(entries) {
    const q = state.search.trim().toLowerCase();
    return entries.filter(e => {
        // Level
        if (!state.levels[e.level]) return false;
        // DS
        if (state.ds === '__null__' && e.ds !== null) return false;
        if (state.ds && state.ds !== '__null__' && e.ds !== state.ds) return false;
        // Search
        if (q) {
            const haystack = (
                (e.msg || '') + ' ' +
                (e.exception?.message || '')
            ).toLowerCase();
            if (!haystack.includes(q)) return false;
        }
        return true;
    });
}
```

#### JS chování — expand

Klik na řádek (mimo klikatelné chipy) → toggle v `state.expanded`,
re-render řádku.

Při expand některé entry → automaticky `state.paused = true`,
update Pause tlačítka. Při sbalení posledního expanded → `state.paused
= false`. Tj. uživatel nikdy neztratí expanded view pod auto-refreshem.

Render expanded sekce:

1. **Header**: "Details" + close button (×)
2. **Plná zpráva** (`msg`) — pokud je delší než compact zobrazení
3. **Request**: `e.request || '(none)'`
4. **DS**: `e.ds || '(none)'`
5. **Context** (`e.ctx`): `<pre>JSON.stringify(e.ctx, null, 2)</pre>`,
   pokud `Object.keys(e.ctx).length > 0`
6. **Exception** (pokud je `e.exception`): renderovat přes funkci
   `renderException(exc, depth = 0)` (rekurze pro `previous`)

Funkce `renderException(exc, depth)`:

```
[Caused by:] (jen když depth > 0)

<exc.class>: <exc.message>
at <exc.at>

[ Show 20 frames ▾ ]   ← collapsed default
   #0 ...
   #1 ...
   ...

[recursivně pro exc.previous]
```

Trace renderovat jako `<pre>` s každým frame na vlastním řádku, monospace.
Collapsed default — toggle button "Show N frames ▾" / "Hide trace ▴".
Pokud `exc.trace` je prázdný/missing, button se nezobrazí.

`exc.previous` rekurzivně, max 5 úrovní (limit už je v loggeru). Vizuálně
indented (left-margin) nebo s "Caused by:" prefixem.

#### JS chování — auto-refresh

```js
function tick() {
    if (state.paused) return;
    state.countdown--;
    updateCountdownUI();
    if (state.countdown <= 0) {
        loadEntries();
        state.countdown = 5;
    }
}
setInterval(tick, 1000);
```

`loadEntries()` fetch `/`+`_dev/api/logs?limit=`+`state.limit`, parse
response, replace `state.entries`, re-render. Pokud expand byl aktivní
na entry, kterou už nelze najít v novém setu (uplavala dolů), po
refresh se rozbalí stejný index — ale typicky uživatel je s
auto-pause v safe stavu.

Pause tlačítko jen toggle `state.paused`. Když user klikne na Refresh,
loadEntries() zavolá ihned a resetne countdown.

#### JS chování — Load older

Tlačítko text:
- Když `state.limit < 2000`: `Load older (currently 200)`, klik → `state.limit *= 2`,
  `loadEntries()`. Cap na 2000.
- Když `state.limit === 2000`: text `Showing maximum 2000 entries — use grep for older`.
  Tlačítko disabled.

#### JS chování — klikatelné chipy

```js
function onLevelChipClick(level) { /* toggle state.levels[level] */ }
function onDsChipClick(ds) { state.ds = ds || '__null__'; /* update dropdown */ }
```

#### Empty / chybové stavy

V tabulce/listu:
- `available: false` → "Log file does not exist yet at `<path>`. See
  [docs/logging.md](https://github.com/shipard/shpd/blob/main/docs/logging.md)
  for setup."
- `entries.length === 0` v API ale `available: true` → "No log entries yet."
- Po filtraci 0 záznamů → "No entries match current filters."
- Fetch error → "Failed to load logs. Check console."

#### Heredoc & escapování

Stejně jako v MVP — render přes `document.createElement` + `textContent`,
žádné JS template literals s `innerHTML`. Hostname interpolovaný PHP
heredocem jako u MVP.

JSON content (`ctx`, exception fields) je z trusted source (vlastní log),
ale stejně raději přes `textContent` na `<pre>` elementech.

### 4. Drobné úpravy v MVP dashboardu (`page()` v stejném controlleru)

#### Header link "View Logs"

Vedle hostname:

```html
<header>
    <h1>Shipard Dev Dashboard</h1>
    <div>
        Server: <strong>{$hostname}</strong>
        &nbsp;&nbsp;
        <a href="/_dev/logs/" style="color: #93c5fd;">View Logs →</a>
    </div>
</header>
```

#### "Logs" tlačítko per DS row

Vedle existujícího "Open" tlačítka:

```html
<a href="/{ds.id}/app/" target="_blank"><button>Open</button></a>
<a href="/_dev/logs/?ds={ds.id}" target="_blank"><button>Logs</button></a>
```

V JS `renderRow()` přidat druhý link element, stejné stylování. "Logs"
tlačítko může být drobně méně vizuální (sekundární styl, např.
`background: #6b7280` místo primární modré) — Open je hlavní akce.

### 5. Hook v `public/index.php`

Změnit existující řádek:

```php
$response = (new \Shipard\Api\Controller\DevDashboardController())
    ->dispatch($request);
```

na:

```php
$response = (new \Shipard\Api\Controller\DevDashboardController(
    '/opt/shipard/data-sources',
    $serverConfig->getLogFile(),
))->dispatch($request);
```

### 6. Testy

#### `tests/Unit/Core/Logging/LogTailTest.php`

`setUp()` vytvoří temp soubor přes `tempnam(sys_get_temp_dir(), 'shpd-tail-')`.
`tearDown()` smaže.

Helper `writeLines(array $lines, bool $trailingNewline = true)`:
zapíše řádky implodované přes `\n`, volitelně s trailing newline.

Test cases:

- **`testNonExistentFileReturnsEmpty`** — path neexistujícího souboru → `[]`
- **`testEmptyFileReturnsEmpty`** — vytvoř prázdný file → `[]`
- **`testZeroLimitReturnsEmpty`** — `readLast(0)` → `[]`
- **`testReturnsAllWhenLimitExceedsLineCount`** — 3 řádky, `readLast(10)` → 3 řádky
- **`testReturnsLastN`** — 10 řádků "line-1" .. "line-10", `readLast(3)` →
  `['line-8', 'line-9', 'line-10']`
- **`testFileWithoutTrailingNewline`** — 3 řádky, žádný `\n` na konci, `readLast(3)`
  → vrátí všechny tři řádky
- **`testLinesLongerThanChunk`** — `chunkSize=64`, 5 řádků s délkou ~200 znaků
  → `readLast(3)` vrátí poslední 3 (algoritmus přečte víc chunků)
- **`testManyLinesWithSmallChunk`** — `chunkSize=128`, 100 řádků, `readLast(20)`
  → posledních 20
- **`testEmptyLinesAreFiltered`** — řádky `["a", "", "b", "  ", "c"]` → `["a", "b", "c"]`
  (prázdné a whitespace-only filtered)

#### `tests/Unit/Api/Controller/DevDashboardControllerTest.php`

Přidat k existujícím test cases:

- **`testLogsPageReturnsHtml`**: `GET /_dev/logs/` → 200, body obsahuje
  "Shipard Logs" a hostname
- **`testLogsPageWithoutTrailingSlash`**: `GET /_dev/logs` → 200
- **`testApiLogsWithoutLogPathReturns503`**: konstruktor bez `logFilePath`,
  `GET /_dev/api/logs` → status 503, error code `LOG_NOT_CONFIGURED`
- **`testApiLogsReturnsParsedEntries`**: vytvoř temp log soubor s 3
  validními JSON řádky → response.data.entries má 3 položky
- **`testApiLogsSkipsInvalidJsonLines`**: log soubor s mixem validního
  a rozbitého JSONu → vrátí jen validní
- **`testApiLogsSkipsLinesMissingRequiredFields`**: validní JSON ale
  bez `ts` nebo bez `level` → přeskoceny
- **`testApiLogsRespectsLimit`**: 50 řádků, `?limit=10` → 10 entries
- **`testApiLogsCapsLimitAt2000`**: `?limit=99999` → response.data.limit = 2000
- **`testApiLogsClampsLimitAtMin`**: `?limit=0` → 1
- **`testApiLogsHandlesMissingLogFile`**: konstruktor s cestou neexistujícího
  souboru → success, available: false, entries: []

Helper pro logy: vytvoří temp soubor a vrátí cestu, registruje pro cleanup.

## UX detaily

### Auto-pause logika

```js
function setExpanded(idx, expand) {
    if (expand) state.expanded.add(idx);
    else state.expanded.delete(idx);

    state.paused = state.expanded.size > 0;
    updatePauseButton();
}
```

Když uživatel ručně klikne Pause tlačítko:

```js
function togglePause() {
    if (state.expanded.size > 0 && state.paused) {
        // Has expanded items but wants to unpause — sbalit vše
        state.expanded.clear();
        renderEntries();
    }
    state.paused = !state.paused;
    updatePauseButton();
}
```

Tj. ruční Resume sbalí všechny rozbalené (jinak by je auto-refresh
přepsal). Drobnost ale výrazně redukuje confusion.

### Časový formát

```js
function formatTimeCompact(iso) {
    // "2026-05-07T12:34:56+02:00" → "12:34:56"
    const m = String(iso).match(/T(\d{2}:\d{2}:\d{2})/);
    return m ? m[1] : String(iso);
}

function formatTimeFull(iso) {
    // "2026-05-07T12:34:56+02:00" → "2026-05-07 12:34:56 +02:00"
    return String(iso).replace('T', ' ');
}
```

V compact view: `12:34:56`. V expanded view: full timestamp.

### DS chip zobrazení

Compact: prvních 4 znaky DS ID + `…`. Hover tooltip s plným ID.
Klik → filter na ten DS.
Pro `e.ds === null`: `(none)` chip, klik filtruje na `__null__`.

## Bezpečnost

- Log file path z `ServerConfig::getLogFile()` — controller nemá pravomoc
  číst libovolný soubor
- Limit cap 2000 — chrání paměť (i v dev)
- `LogTail` čte jen z konce, paměť je `O(chunkSize × limit/avgLineLength)`,
  typicky pár MB max
- Žádné secrets v logu (ErrorLogger neenkóduje sensitive data — viz
  `docs/logging.md`); ale v kontextu / exception trace by se teoreticky
  mohly objevit. Stejné pravidlo jako u zbytku dashboardu: dev mode je
  trusted, banner upozorní

## Co netřeba

- Server-side filtering — vše klient-side
- Cursor-based pagination — refetch s vyšším limitem stačí
- WebSocket / SSE pro live tail — polling 5 s vyhovuje
- Per-DS log files — `docs/logging.md` to zmiňuje jako future work,
  není v tomto scope
- Export do souboru / download — uživatel má přímý přístup k log souboru
- Stop-words / regex search — substring stačí
- Časový rozsah filter (from/to) — default tail strategie pokrývá use case

## Konvence k dodržení

- PHP `declare(strict_types=1)`, PSR-4
- HTML lang="en", anglické UI texty
- Vanilla JS, render přes `document.createElement` + `textContent`
- `gethostname()` pro server name (sdíleno s MVP page)
- Test pattern: konstruktor s injectable cestami, ne mockování
- `LogTail::chunkSize` jako konstruktor argument (default 8192) kvůli
  testovatelnosti edge cases s malými chunky

## Hotovo když

- `vendor/bin/phpunit` projde, včetně 9 nových `LogTailTest` a 10
  rozšiřujících `DevDashboardControllerTest` cases
- `curl http://{ip}/_dev/api/logs?limit=5` vrátí JSON s posledními 5
  entries (nebo `available: false` pokud log neexistuje)
- `curl http://{ip}/_dev/api/logs?limit=99999` vrátí response s
  `limit: 2000` (cap funguje)
- V prohlížeči `http://{ip}/_dev/logs/`:
  - se renderuje stránka s default filtrem WARN+ERROR
  - klik na level chip ve filteru přepne (nutno zapnout DEBUG/INFO pro
    plný přehled)
  - klik na DS chip v řádku zfiltruje na ten DS
  - klik na řádek expanduje detail, auto-refresh se pauzne (puntík
    červený), nový řádek nepřepisuje rozbalené
  - sbalení posledního detailu vrátí auto-refresh (puntík zelený)
  - "Load older" zdvojnásobí limit, max 2000, pak disabled
  - search box filtruje fulltextem na `msg` i `exception.message`
  - exception trace je collapsed default, expand zobrazí všechny frames
  - `previous` exceptions jsou vnořeně rekurzivně
- Z hlavního `/_dev/` dashboardu:
  - V hlavičce vedle hostnamu je odkaz "View Logs →"
  - U každého DS řádku je tlačítko "Logs" vedle "Open", které otevře
    `/_dev/logs/?ds=<id>` v novém tabu s předvyplněným DS filtrem
- `docs/cli.md` ani `docs/logging.md` nepotřebují update — log viewer
  je samodokumentační, formát logu je už popsaný v `docs/logging.md`
