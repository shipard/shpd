# Task: Unifikované logování

**Stav:** hotovo

## Kontext

Při debugování poslední chyby v dokladech (po Fázi 6 doklady MVP) jsme do
projektu přidali `src/Core/Logging/ErrorLogger.php` jako rychlou MVP
implementaci centrálního logování — protože `index.php` `\Throwable`
catch handler dosud nikam nelogoval. Současný stav:

- `ErrorLogger` má `logException`, `warn`, `info`, `error` metody
- Píše multi-line text do `var/log/error.log` v projektu (codebase) +
  fallback do PHP `error_log()`
- `index.php` ho volá v hlavním catch handler

Tato implementace funguje pro dev debug, ale má několik problémů:

- **Cesta v codebase** (`var/log/`) je nesprávná — produkční kód má být
  read-only, runtime artefakty patří mimo
- **Multi-line text** se špatně parsuje, neumožňuje strukturovanou
  analýzu (jq, grafana loki, fluentbit)
- **Žádná úroveň** (DEBUG/INFO/WARN/ERROR) — vše se loguje na stejnou
  úroveň, není jak vypnout INFO v produkci
- **Žádný `ds_id` v záznamu** — multi-tenant nasazení nelze odlišit
- **Žádný request kontext** mimo logException — `warn/info/error`
  nezachytí `GET /api/v1/...`
- **Existující `error_log()` volání** v `AnalysisController` a
  `SettingsController` neběží přes `ErrorLogger`, čili část výstupu
  je v PHP error logu, část v aplikačním logu

Tato fáze to dotáhne na produkční kvalitu.

Před implementací **přečti**:

- `src/Core/Logging/ErrorLogger.php` — současný stav (~150 řádků)
- `public/index.php` — bootstrap a catch handler
- `src/Core/Config/ServerConfig.php` — `/etc/shipard/server.json` schema
- `src/Api/DataSourceResolver.php` — kde se `ds_id` rozpozná
  (viz `$resolved->config->getId()`)

Existující `error_log()` volání k migraci:

- `src/Api/Controller/AnalysisController.php:425, 1153`
- `src/Api/Controller/SettingsController.php:94, 107, 136, 144`

## Cíl

Po dokončení této fáze platí:

- `ErrorLogger` má **úrovně** `debug < info < warn < error` a konfigurovatelný
  threshold v `server.json` (`logLevel: "debug" | "info" | "warn" | "error"`)
- Záznamy jsou **single-line JSON** s pevně definovaným schématem
  (viz "Formát záznamu" níže)
- Každý záznam obsahuje **`ds`** field s ID datasource (nebo `null` pokud
  ještě nerozpoznán)
- Každý záznam obsahuje **`request`** field (`"GET /api/v1/..."`) nebo
  `null` pro CLI/non-HTTP kontext
- Default cesta logu **`/opt/shipard/log/shipard.log`**, konfigurovatelná
  přes `server.json` jako `logFile`
- `var/log/` v repu je smazaný (`.gitkeep`, `.gitignore`)
- Existující `error_log()` volání v `AnalysisController` a
  `SettingsController` jsou **migrované** na `ErrorLogger::warn` se
  strukturovaným kontextem
- `docs/logging.md` popisuje deploy, oprávnění, logrotate, jak číst
- PHPUnit testy pokrývají threshold filtraci, JSON format, fallback,
  exception entries

## Návaznost

- Závisí na: docs MVP (`docs-invoices.md` — hotovo), kvůli kontextu
  `ErrorLogger`
- Otevírá: nic přímo. Připravený podklad pro budoucí rozšíření
  (např. log shipping do Loki/Elasticsearch, audit log, atd.)

## Scope

### V rozsahu

- Refactor `ErrorLogger` — úrovně, JSON formát, `setDsId`,
  `setRequestContext`, `setLogLevel`, default cesta
- Rozšíření `ServerConfig` o `logFile` a `logLevel` (volitelné, s
  defaulty)
- Bootstrap úprava v `public/index.php` — volání `ErrorLogger::setLogPath`,
  `setLogLevel`, `setRequestContext` na začátku, `setDsId` po `resolve()`
- Migrace 6 existujících `error_log()` volání na `ErrorLogger::warn`
  se strukturovaným kontextem
- Cleanup `var/log/` (smazat `.gitkeep`, `.gitignore`, samotný adresář
  pokud zůstal)
- `docs/logging.md` — deploy guide, formát záznamu, příklady
- PHPUnit testy v `tests/unit/Core/Logging/ErrorLoggerTest.php`

### Mimo rozsah

- **Per-DS soubory** — jeden globální soubor s `ds` field je MVP-friendly;
  pokud někdo bude chtít per-DS soubory, řeší se logrotate/postprocessing
- **Log shipping** (Loki, Elasticsearch, syslog) — JSON formát to
  připravuje, ale samotná integrace je separátní úkol
- **Audit log** (kdo co změnil) — to je doménová záležitost, ne general
  logging
- **Frontend logging** — JS errors v prohlížeči, mimo scope tohoto
  taskunu

## Architektonická rozhodnutí

### Jeden globální soubor s `ds` field

`/opt/shipard/log/shipard.log` — jeden soubor pro celý server, `ds_id`
je polem v každém záznamu. Důvody:

- Globální chyby (před resolveDataSource — třeba rozbité `domains.json`)
  mají kam jít s `ds: null`
- Cross-DS analýza ("kolik 500 errors jsme měli celkem za den") je
  triviální (`jq 'select(.level=="error")' | wc -l`)
- Per-DS filtrace také snadno (`jq 'select(.ds=="abc")'`)
- Per-DS soubory by se daly vytvořit logrotate konfigurací nebo
  postprocessing skriptem, kdyby to bylo potřeba

### Default úroveň `debug`, threshold konfigurovatelný

V `server.json`:

```jsonc
{
    // ... existing fields ...
    "logFile": "/opt/shipard/log/shipard.log",  // optional, default same
    "logLevel": "debug"                          // optional, default "debug"
}
```

Default `debug` znamená: zapisovat všechno. Až se za pár týdnů ukáže,
co je v logu rozumné, nasadíme `info` jako produkční default.

`logLevel: "warn"` přeskočí všechny DEBUG a INFO záznamy — neuloží se,
nezavolá `error_log()`. Threshold je porovnání číselných hodnot
(`debug=0 < info=1 < warn=2 < error=3`).

### Format záznamu — single-line JSON

```json
{"ts":"2026-05-07T12:34:56+02:00","level":"error","ds":"abcd-efgh-ijkl-mnop","request":"GET /api/v1/_ui/form/docs_core_heads/meta/2","msg":"Database query failed","exception":{"class":"Dibi\\DriverException","message":"Unknown column 'docState' in 'WHERE'","at":"vendor/dibi/dibi/src/Dibi/Drivers/MySqliDriver.php:179","trace":["#0 ...","#1 ..."]},"ctx":{}}
```

Pole:

| Pole | Typ | Popis |
|---|---|---|
| `ts` | string | ISO 8601 s timezone, `date('c')` |
| `level` | string | `"debug" \| "info" \| "warn" \| "error"` |
| `ds` | ?string | DS ID nebo `null` (před resolvováním) |
| `request` | ?string | `"GET /path"` nebo `null` (CLI / non-HTTP) |
| `msg` | string | Krátká zpráva |
| `exception` | ?object | Jen pro `logException` (viz níže) |
| `ctx` | object | Volitelný strukturovaný kontext, vždy přítomný (i jako `{}`) |

`exception` objekt:

```json
{
    "class": "Fully\\Qualified\\ExceptionClassName",
    "message": "...",
    "at": "vendor/.../File.php:179",
    "trace": ["#0 ...", "#1 ...", "..."]
}
```

`trace` je array stringů (jeden per stack frame), oříznuté na **20
frames** (přiměřené pro debug, neexploduje JSON velikost). Chained
exceptions (`getPrevious()`) přidat jako další objekty pod
`exception.previous` array (max 5 hloubek, jak je to dnes).

Pro lidské čtení v dev shellu:

```bash
tail -f /opt/shipard/log/shipard.log | jq -c .
tail -f /opt/shipard/log/shipard.log | jq 'select(.level=="error")'
tail -f /opt/shipard/log/shipard.log | jq 'select(.ds=="abcd-efgh-ijkl-mnop")'
```

### `ds_id` lifecycle

`ErrorLogger` má statický `?string $dsId = null`. Volá se přes
`setDsId(?string)`:

- Před voláním `DataSourceResolver::resolve()` → `null`
- Po `resolve()` → `setDsId($resolved->config->getId())`
- Pokud resolve sám vyhodí výjimku (`UnknownDataSourceException`,
  `UnknownHostException`), `ds` zůstane `null`

V `index.php` přesný hook bod:

```php
$resolved = $resolver->resolve($request->getHost(), $request->getPath());

// Right after resolution — ds id is now known
ErrorLogger::setDsId($resolved->config->getId());
```

### Request context

`ErrorLogger::setRequestContext(?string)` — volá se na začátku
`index.php` po vytvoření `Request`:

```php
$request = Request::fromGlobals();
ErrorLogger::setRequestContext($request->getMethod() . ' ' . $request->getPath());
```

Pro CLI kontext (např. `bin/shpd-ds ds-upgrade`) by to byl `null`
nebo `"cli: ds-upgrade --ds=foo"` — to je věc samostatné integrace
do CLI příkazů, mimo scope tohoto úkolu.

### Migrace `error_log()` volání

Existujících 6 volání:

```php
// AnalysisController.php:425
error_log('AnalysisController::claim failed: ' . $e->getMessage());
// →
ErrorLogger::warn('AnalysisController::claim failed', [
    'error' => $e->getMessage(),
]);

// AnalysisController.php:1153
error_log('AnalysisController::updateExtractedStatus failed: ' . $e->getMessage());
// →
ErrorLogger::warn('AnalysisController::updateExtractedStatus failed', [
    'error' => $e->getMessage(),
]);

// SettingsController.php:94
error_log("SettingsController: viewer '{$viewerId}' not found in module '{$module->id}', skipping");
// →
ErrorLogger::warn("Viewer not found in module, skipping", [
    'viewer_id' => $viewerId,
    'module_id' => $module->id,
]);

// SettingsController.php:107
error_log("SettingsController: viewer '{$viewerId}' targets table '{$targetTable}' marked hideFromNavigation, skipping");
// →
ErrorLogger::warn("Viewer targets hidden table, skipping", [
    'viewer_id' => $viewerId,
    'table_name' => $targetTable,
    'module_id' => $module->id,
]);

// SettingsController.php:136
error_log("SettingsController: table '{$tableName}' not found in module '{$module->id}', skipping");
// →
ErrorLogger::warn("Table not found in module, skipping", [
    'table_name' => $tableName,
    'module_id' => $module->id,
]);

// SettingsController.php:144
error_log("SettingsController: table '{$tableName}' is marked hideFromNavigation, skipping");
// →
ErrorLogger::warn("Table is marked hideFromNavigation, skipping", [
    'table_name' => $tableName,
    'module_id' => $module->id,
]);
```

Pattern: **lidský `msg` + strukturovaný `ctx`**. Je-li `msg` typu
"Foo failed", `ctx.error` obsahuje detail. Žádný stringový concat
do `msg`, vše parametrizovaně.

`use Shipard\Core\Logging\ErrorLogger;` na začátku souboru.

## Implementace

### `ErrorLogger` refactor

```php
<?php

declare(strict_types=1);

namespace Shipard\Core\Logging;

/**
 * Centralized application logger.
 *
 * Writes single-line JSON entries to /opt/shipard/log/shipard.log (default)
 * or wherever ServerConfig::getLogFile() points. Falls back to PHP error_log()
 * when the file isn't writable.
 *
 * Lifecycle:
 *   - Bootstrap (index.php) calls setLogPath, setLogLevel, setRequestContext.
 *   - After DataSourceResolver::resolve(), bootstrap calls setDsId.
 *   - Application code uses static methods: debug/info/warn/error/logException.
 *
 * The logger is intentionally static — there's only one log destination per
 * request, threading isn't a concern (PHP-FPM is single-threaded per request),
 * and DI plumbing through 50 controllers for something this universal would
 * be overkill.
 */
final class ErrorLogger
{
    public const LEVEL_DEBUG = 0;
    public const LEVEL_INFO  = 1;
    public const LEVEL_WARN  = 2;
    public const LEVEL_ERROR = 3;

    private const LEVEL_NAMES = [
        self::LEVEL_DEBUG => 'debug',
        self::LEVEL_INFO  => 'info',
        self::LEVEL_WARN  => 'warn',
        self::LEVEL_ERROR => 'error',
    ];

    private const LEVEL_VALUES = [
        'debug' => self::LEVEL_DEBUG,
        'info'  => self::LEVEL_INFO,
        'warn'  => self::LEVEL_WARN,
        'error' => self::LEVEL_ERROR,
    ];

    /** Default destination if nothing else is configured. */
    private const DEFAULT_LOG_PATH = '/opt/shipard/log/shipard.log';

    /** Threshold — entries with level < threshold are dropped. */
    private static int $threshold = self::LEVEL_DEBUG;

    private static ?string $logPath = null;
    private static ?string $dsId = null;
    private static ?string $requestContext = null;

    /** Maximum stack frames recorded in JSON exception entry. */
    private const TRACE_FRAME_LIMIT = 20;

    /** Maximum chain depth for getPrevious() exceptions. */
    private const PREVIOUS_DEPTH_LIMIT = 5;

    public static function setLogPath(?string $path): void
    {
        self::$logPath = $path;
    }

    public static function setLogLevel(string $level): void
    {
        $key = strtolower($level);
        self::$threshold = self::LEVEL_VALUES[$key] ?? self::LEVEL_DEBUG;
    }

    public static function setDsId(?string $dsId): void
    {
        self::$dsId = $dsId;
    }

    public static function setRequestContext(?string $context): void
    {
        self::$requestContext = $context;
    }

    public static function debug(string $msg, array $ctx = []): void
    {
        self::emit(self::LEVEL_DEBUG, $msg, $ctx);
    }

    public static function info(string $msg, array $ctx = []): void
    {
        self::emit(self::LEVEL_INFO, $msg, $ctx);
    }

    public static function warn(string $msg, array $ctx = []): void
    {
        self::emit(self::LEVEL_WARN, $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::emit(self::LEVEL_ERROR, $msg, $ctx);
    }

    public static function logException(\Throwable $e, string $msg = ''): void
    {
        if (self::LEVEL_ERROR < self::$threshold) {
            return;
        }

        $primary = $msg !== '' ? $msg : (get_class($e) . ': ' . $e->getMessage());
        $entry = self::baseEntry(self::LEVEL_ERROR, $primary, []);
        $entry['exception'] = self::formatException($e);

        self::write($entry);
    }

    // ── Internal ────────────────────────────────────────────────────────────

    private static function emit(int $level, string $msg, array $ctx): void
    {
        if ($level < self::$threshold) {
            return;
        }
        self::write(self::baseEntry($level, $msg, $ctx));
    }

    /** @return array<string, mixed> */
    private static function baseEntry(int $level, string $msg, array $ctx): array
    {
        return [
            'ts'      => date('c'),
            'level'   => self::LEVEL_NAMES[$level] ?? 'info',
            'ds'      => self::$dsId,
            'request' => self::$requestContext,
            'msg'     => $msg,
            'ctx'     => (object) $ctx,  // ensures empty {} not [] in JSON
        ];
    }

    /** @return array<string, mixed> */
    private static function formatException(\Throwable $e, int $depth = 0): array
    {
        $traceLines = preg_split('/\r?\n/', $e->getTraceAsString()) ?: [];
        $traceLines = array_slice($traceLines, 0, self::TRACE_FRAME_LIMIT);

        $entry = [
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'at'      => self::shortenPath($e->getFile()) . ':' . $e->getLine(),
            'trace'   => $traceLines,
        ];

        $previous = $e->getPrevious();
        if ($previous !== null && $depth < self::PREVIOUS_DEPTH_LIMIT) {
            $entry['previous'] = self::formatException($previous, $depth + 1);
        }

        return $entry;
    }

    /**
     * Compact full filesystem paths to relative-from-project-root for shorter
     * log lines. /home/sebik/sw/shpd/src/Foo.php → src/Foo.php
     */
    private static function shortenPath(string $path): string
    {
        $projectRoot = dirname(__DIR__, 3);  // src/Core/Logging → project root
        if (str_starts_with($path, $projectRoot . '/')) {
            return substr($path, strlen($projectRoot) + 1);
        }
        return $path;
    }

    /** @param array<string, mixed> $entry */
    private static function write(array $entry): void
    {
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // json_encode failed (e.g. invalid UTF-8 in ctx); fall through
            // to a minimal entry so we at least see something
            $json = json_encode([
                'ts'    => $entry['ts'] ?? date('c'),
                'level' => $entry['level'] ?? 'error',
                'msg'   => '[ErrorLogger: json_encode failed]',
                'ctx'   => (object) [],
            ]);
        }
        $line = $json . "\n";

        $path = self::$logPath ?? self::DEFAULT_LOG_PATH;
        $dir = dirname($path);

        // Best-effort directory creation; fall through to error_log on failure
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $written = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            // Fallback — surface to PHP error_log so the message still lands
            // somewhere (PHP-FPM log, syslog, whatever the host configured)
            error_log('ErrorLogger fallback (cannot write to ' . $path . '): ' . trim($line));
            return;
        }

        // Always also surface to error_log() — production setups that pipe
        // PHP-FPM logs to a central system see the same JSON entry there too.
        // Trimmed because error_log() adds its own framing.
        error_log(rtrim($line, "\n"));
    }

    // ── Test helpers ────────────────────────────────────────────────────────

    /**
     * Reset internal state. Used by tests — never call from production code.
     */
    public static function resetForTesting(): void
    {
        self::$threshold = self::LEVEL_DEBUG;
        self::$logPath = null;
        self::$dsId = null;
        self::$requestContext = null;
    }
}
```

### `ServerConfig` rozšíření

`/etc/shipard/server.json` přidá dvě volitelná pole:

```jsonc
{
    // ... existing required ...
    "logFile": "/opt/shipard/log/shipard.log",
    "logLevel": "debug"
}
```

Úprava `ServerConfig`:

```php
public function getLogFile(): string
{
    return $this->data['logFile'] ?? '/opt/shipard/log/shipard.log';
}

public function getLogLevel(): string
{
    return $this->data['logLevel'] ?? 'debug';
}
```

`load()` validation NErozšiřuje `$required` array — nová pole jsou
volitelná. Existující instalace bez nich fungují s defaulty.

### `index.php` bootstrap úpravy

```php
$request = Request::fromGlobals();

// Set request context immediately so even early errors carry it
ErrorLogger::setRequestContext($request->getMethod() . ' ' . $request->getPath());

$corsMiddleware = new CorsMiddleware();
// ... CORS preflight ...

$serverConfig = null;

try {
    $serverConfig = new ServerConfig();
    $serverConfig->load();

    // Configure logger from server config — must happen as early as possible
    ErrorLogger::setLogPath($serverConfig->getLogFile());
    ErrorLogger::setLogLevel($serverConfig->getLogLevel());

    $resolver = new DataSourceResolver($serverConfig->getDomainsFile());
    $resolved = $resolver->resolve($request->getHost(), $request->getPath());

    // DS is now known — propagate to logger
    ErrorLogger::setDsId($resolved->config->getId());

    // ... rest of bootstrap ...
}
```

Přesně po `$resolver->resolve()` — pokud `resolve()` selže s
`UnknownDataSourceException`, `setDsId` se nezavolá a `ds` zůstane `null`
(což je správné chování — neznáme DS).

### Cleanup `var/log/`

```bash
git rm -rf var/log/
# nebo manuálně:
rm -rf var/log/
```

`var/log/.gitignore` a `var/log/.gitkeep` zmizí. Pokud `var/` zůstává
prázdné, smazat i ho.

## `docs/logging.md`

Nový soubor:

```markdown
# Logging

Centralizované logování aplikace. Vše prochází přes
`Shipard\Core\Logging\ErrorLogger` — žádné přímé `error_log()` v aplikačním
kódu (s výjimkou fallback v `ErrorLogger` samotném).

## Cesta logu

Default: `/opt/shipard/log/shipard.log`

Konfigurovatelné v `/etc/shipard/server.json`:

\```jsonc
{
    "logFile": "/opt/shipard/log/shipard.log",
    "logLevel": "debug"
}
\```

`logLevel` může být `"debug"`, `"info"`, `"warn"`, `"error"`. Záznamy
s nižší úrovní se zahodí (neuloží do souboru, nezavolá `error_log`).

## Formát záznamu

Single-line JSON, jeden záznam per řádek:

\```json
{"ts":"2026-05-07T12:34:56+02:00","level":"error","ds":"...","request":"GET /...","msg":"...","exception":{...},"ctx":{...}}
\```

Pole:

- `ts` — ISO 8601 s timezone
- `level` — `debug` / `info` / `warn` / `error`
- `ds` — ID datasource nebo `null` (chyby před rozpoznáním DS)
- `request` — `"METHOD /path"` nebo `null` (CLI / non-HTTP)
- `msg` — krátká lidská zpráva
- `exception` — jen u `logException`, viz níže
- `ctx` — strukturovaný kontext (vždy objekt, i prázdný)

`exception` objekt:

\```json
{
    "class": "Dibi\\DriverException",
    "message": "Unknown column 'docState' in 'WHERE'",
    "at": "vendor/dibi/.../MySqliDriver.php:179",
    "trace": ["#0 ...", "#1 ...", "..."],
    "previous": { ... rekurzivně ... }
}
\```

`trace` je oříznutý na 20 frames. `previous` rekurzivně pro chained
exceptions, max 5 úrovní hloubky.

## Použití v kódu

\```php
use Shipard\Core\Logging\ErrorLogger;

ErrorLogger::debug("Something happened", ["request_id" => $id]);
ErrorLogger::info("User logged in", ["user_id" => $userId]);
ErrorLogger::warn("Viewer not found, skipping", ["viewer_id" => $vid]);
ErrorLogger::error("Save failed", ["table" => $t, "error" => $errMsg]);
ErrorLogger::logException($e);
ErrorLogger::logException($e, "Document save failed for doc {$docId}");
\```

Pravidla:

- **`msg` = lidská zpráva**, nikdy nezahrnuj parametry stringovým concatenací
- **`ctx` = strukturovaná data**, dostane do JSON `ctx` field
- **`logException`** zachytí celou výjimku včetně stack trace; `msg`
  parametr je volitelný kontext

## Čtení logu

Pro lidské sledování v dev / produkci:

\```bash
# Live tail s formátováním
tail -f /opt/shipard/log/shipard.log | jq -c .

# Jen errors
tail -f /opt/shipard/log/shipard.log | jq 'select(.level == "error")'

# Jeden konkrétní DS
tail -f /opt/shipard/log/shipard.log | jq 'select(.ds == "abcd-efgh-ijkl-mnop")'

# Kolik chyb za den
grep '"level":"error"' /opt/shipard/log/shipard.log | wc -l

# Top 10 chybových hlášek za týden
grep '"level":"error"' /opt/shipard/log/shipard.log \
    | jq -r '.msg' \
    | sort | uniq -c | sort -rn | head
\```

## Deploy

Při instalaci serveru:

\```bash
sudo mkdir -p /opt/shipard/log
sudo chown www-data:www-data /opt/shipard/log
sudo chmod 0775 /opt/shipard/log
\```

(Uživatel/skupina podle toho, pod čím běží PHP-FPM. Na Debianu
typicky `www-data:www-data`, na Alpine `nginx:nginx`.)

### Logrotate

Doporučená konfigurace `/etc/logrotate.d/shipard`:

\```
/opt/shipard/log/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 0664 www-data www-data
    sharedscripts
}
\```

30 dnů historie, gzip kompresí starších log souborů. Žádný `postrotate`
hook potřeba — `ErrorLogger` otevírá soubor při každém zápisu (append +
LOCK_EX), takže rotace bez signálu funguje.

### Systemd-tmpfiles (alternativa pro logrotate)

Pokud máš systemd-spravovaný host:

\```
# /etc/tmpfiles.d/shipard.conf
d /opt/shipard/log 0775 www-data www-data 30d
\```

## Vztah k PHP error_log

`ErrorLogger` paralelně volá PHP `error_log()` — každý zápis projde i
do tradičního PHP error logu (PHP-FPM, syslog, nebo wherever `php.ini`
ukáže). To je záměr:

- Pokud by se `/opt/shipard/log/shipard.log` rozbil (oprávnění,
  filesystem full), PHP error log je fallback
- Produkční pipelines (fluentbit, journald) často konzumují PHP-FPM log
  centrálně — takže získají stejné záznamy
```

## PHPUnit testy

`tests/unit/Core/Logging/ErrorLoggerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Logging;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;

class ErrorLoggerTest extends TestCase
{
    private string $tempLog;

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        $this->tempLog = sys_get_temp_dir() . '/shipard-test-log-' . uniqid() . '.log';
        ErrorLogger::setLogPath($this->tempLog);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempLog)) {
            unlink($this->tempLog);
        }
        ErrorLogger::resetForTesting();
    }

    public function testInfoEntryIsValidJson(): void
    {
        ErrorLogger::info("Hello", ["key" => "value"]);
        $entry = $this->readFirstEntry();

        self::assertSame("info", $entry['level']);
        self::assertSame("Hello", $entry['msg']);
        self::assertSame(["key" => "value"], (array) $entry['ctx']);
        self::assertNull($entry['ds']);
        self::assertNull($entry['request']);
        self::assertArrayHasKey('ts', $entry);
    }

    public function testThresholdFiltersBelowLevel(): void
    {
        ErrorLogger::setLogLevel('warn');
        ErrorLogger::debug("dropped");
        ErrorLogger::info("dropped");
        ErrorLogger::warn("kept");
        ErrorLogger::error("kept");

        $entries = $this->readAllEntries();
        self::assertCount(2, $entries);
        self::assertSame('warn', $entries[0]['level']);
        self::assertSame('error', $entries[1]['level']);
    }

    public function testDsIdAndRequestContextArePropagated(): void
    {
        ErrorLogger::setDsId('test-ds');
        ErrorLogger::setRequestContext('GET /test');
        ErrorLogger::warn("ctx test");

        $entry = $this->readFirstEntry();
        self::assertSame('test-ds', $entry['ds']);
        self::assertSame('GET /test', $entry['request']);
    }

    public function testLogExceptionRecordsClassMessageTrace(): void
    {
        $exception = new \RuntimeException("boom");
        ErrorLogger::logException($exception);

        $entry = $this->readFirstEntry();
        self::assertSame('error', $entry['level']);
        self::assertArrayHasKey('exception', $entry);
        self::assertSame('RuntimeException', $entry['exception']['class']);
        self::assertSame('boom', $entry['exception']['message']);
        self::assertNotEmpty($entry['exception']['trace']);
    }

    public function testLogExceptionWithExplicitMessage(): void
    {
        $exception = new \RuntimeException("inner detail");
        ErrorLogger::logException($exception, "Operation X failed");

        $entry = $this->readFirstEntry();
        self::assertSame('Operation X failed', $entry['msg']);
        self::assertSame('inner detail', $entry['exception']['message']);
    }

    public function testChainedExceptionsRecordedAsPrevious(): void
    {
        $inner = new \LogicException("root cause");
        $outer = new \RuntimeException("wrapper", 0, $inner);

        ErrorLogger::logException($outer);
        $entry = $this->readFirstEntry();

        self::assertSame('wrapper', $entry['exception']['message']);
        self::assertArrayHasKey('previous', $entry['exception']);
        self::assertSame('root cause', $entry['exception']['previous']['message']);
    }

    public function testFallbackToErrorLogWhenFileNotWritable(): void
    {
        ErrorLogger::setLogPath('/proc/cannot-write-here.log');
        // Should not throw — falls back to error_log()
        ErrorLogger::warn("survives unwritable path");

        // No exception thrown is the assertion here
        self::assertTrue(true);
    }

    /** @return array<string, mixed> */
    private function readFirstEntry(): array
    {
        $entries = $this->readAllEntries();
        self::assertNotEmpty($entries, "log file is empty");
        return $entries[0];
    }

    /** @return list<array<string, mixed>> */
    private function readAllEntries(): array
    {
        if (!file_exists($this->tempLog)) {
            return [];
        }
        $lines = file($this->tempLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_map(
            fn(string $line): array => json_decode($line, true) ?: [],
            $lines,
        );
    }
}
```

## Hotovo když

- [ ] `ErrorLogger` má 4 úrovně (debug/info/warn/error) a threshold přes
      `setLogLevel`
- [ ] Zápis je single-line JSON validní podle schématu výše
- [ ] `setDsId` a `setRequestContext` propagují do `ds` a `request` polí
- [ ] `logException` má volitelný `$msg` parametr; když chybí, použije
      `class: message` jako default
- [ ] `previous` chained exceptions jsou v JSON jako rekurzivní objekt
      (max 5 úrovní)
- [ ] `trace` je oříznutý na 20 frames
- [ ] `ServerConfig::getLogFile()` a `getLogLevel()` existují a jsou
      volitelné (default `/opt/shipard/log/shipard.log` resp. `"debug"`)
- [ ] `index.php` volá `setLogPath`, `setLogLevel`, `setRequestContext`,
      `setDsId` ve správném pořadí
- [ ] 6 existujících `error_log()` volání v `AnalysisController` a
      `SettingsController` jsou migrované na `ErrorLogger::warn` se
      strukturovaným kontextem
- [ ] `var/log/` smazaný z repa
- [ ] `docs/logging.md` napsaný s deploy guide, formátem, příklady
- [ ] PHPUnit testy v `tests/unit/Core/Logging/ErrorLoggerTest.php`
      pokrývají threshold, JSON shape, ds/request propagation,
      logException, chained exceptions, fallback
- [ ] Manuální test: zopakovat předchozí docs bug (zase tam vrátit
      `docState IN (...)` v `resolvePartnerBankOptions`), ověřit, že
      JSON entry v `/opt/shipard/log/shipard.log` má všechny očekávané
      pole vč. `ds`, `request`, `exception.trace`
- [ ] Smoke test pro CLI příkaz (např. `bin/shpd-ds list-projects`) —
      `ds` a `request` zůstávají `null`, ostatní pole fungují

## Konvence

- **PHP 8.3** strict_types
- **JSON encoding**: `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` —
  čitelnější logy, žádné `\u0027` a `\/`
- **Statická utility class** — `final class ErrorLogger`, žádná instance,
  state přes static fields. Per-request lifecycle (PHP-FPM nový proces
  per request), threading není problém.
- **Žádné dependencies** mimo PHP standard library — chceme, aby logger
  fungoval i když je všechno ostatní rozbité (např. ConfigRuntime
  selhal při loadu)

## Doporučené pořadí implementace

1. **`ErrorLogger` refactor** — hlavní třída, single-line JSON,
   úrovně, lifecycle setters
2. **PHPUnit testy** souběžně — píš testy zatímco refaktoruješ
3. **`ServerConfig` rozšíření** — `getLogFile()`, `getLogLevel()`,
   default fallback
4. **`index.php` bootstrap úpravy** — pořadí volání důležité
   (setRequestContext první, setDsId po resolve)
5. **Migrace 6 `error_log()` volání** — vždy s `use` statement nahoře
6. **Cleanup `var/log/`** — smaž celý adresář, žádný stopa po MVP
   implementaci
7. **`docs/logging.md`** — napiš na konec, když znáš finální API
8. **End-to-end smoke test**:
   - Vyvolej chybu (např. dočasně rozbij DocsHeadsForm SQL),
     ověř že `/opt/shipard/log/shipard.log` má JSON entry s plnou trace
   - Spusť CLI command, ověř že `ds`/`request` jsou `null` ale
     ostatní pole fungují
   - Nastav `logLevel: "warn"`, ověř že `info` a `debug` se zahodí
9. **Server `mkdir /opt/shipard/log`** — doplnit do existujícího
   instalačního skriptu / DEVELOPERS.md

## Otevřené body / Future work

- **CLI integrace** — `bin/shpd-ds` příkazy by měly volat
  `ErrorLogger::setRequestContext("cli: " . $commandName)` na začátku.
  Pokud existuje obecný `Symfony\Console` event listener, zaháknout
  tam. Není nutné v tomto tasku, ale uveď v dokumentaci jako TODO.
- **Per-DS soubory** — pokud se ukáže, že jeden globální soubor je
  pro multi-tenant nepraktický, řeší se buď logrotate splittingem
  podle `ds` field, nebo refaktorem `ErrorLogger` na per-DS path.
  Aktuálně mimo scope.
- **Log shipping** — JSON formát to připravuje. Když se v budoucnu
  zavede grafana loki / elasticsearch / fluentbit, integrace je
  promile páce: `tail -f` source, `parse json`, ship.
- **Strukturovaný `ctx` napříč voláními** — bylo by hezké mít
  globální `ErrorLogger::pushContext(['user_id' => $u])` který se
  přidá do každého následujícího záznamu (request-scoped). To je
  ale komplexnější fíčura, řeš v navazujícím úkolu pokud bude potřeba.
- **Zpětná kompatibilita pro existující dev `var/log/error.log`** —
  task to maže. Pokud má někdo zaháknutý log shipper na ten soubor,
  vznikne mu díra. Ale: nikdo ho mít zaháknutý nemá, je to z
  posledních pár dní. Smazání je čisté.
