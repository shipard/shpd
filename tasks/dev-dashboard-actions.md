# Dev dashboard — server akce a per-DS upgrade

## Status / Cíl

Fáze 4 dev dashboardu. Doplnit do UI tři akce, které dnes vyžadují
otevřít terminál a pamatovat si ID:

- **Per-DS upgrade** — `[Upgrade]` tlačítko v každém řádku DS, spustí
  `shpd-ds ds-upgrade` v adresáři daného DS
- **Upgrade All** — server-level akce v hlavičce, spustí
  `shpd-server ds-upgrade-all`
- **Doctor** — server-level akce v hlavičce, spustí `shpd-server doctor`

Všechny tři používají **stejný streaming pattern jako `/_dev/ds-create/`**
z fáze 3. Velká část infrastruktury je hotová (`Response::stream()`,
`runCommand()` helper, line-prefix protokol).

## Návaznost

- Vychází z [`dev-dashboard-create-ds.md`](dev-dashboard-create-ds.md)
  — streaming infrastruktura, `Response::stream()`, line-prefix protokol
- [`ds-upgrade-all.md`](ds-upgrade-all.md) — CLI příkaz, jen ho voláme
- [`server-setup-permissions.md`](server-setup-permissions.md) — CLI
  doctor command, jen ho voláme
- Žádné nové CLI příkazy — všechno už existuje, jen ho zpřístupňujeme z UI

## Komponenty

```
src/Api/Controller/DevDashboardController.php           ← rozšířit
tests/Unit/Api/Controller/DevDashboardControllerTest.php ← rozšířit
```

## Architektura

### Tři akční stránky se stejnou strukturou

```
/_dev/doctor/                  → GET HTML, POST /_dev/api/doctor
/_dev/upgrade-all/             → GET HTML, POST /_dev/api/upgrade-all
/_dev/ds-upgrade/?ds=<id>      → GET HTML, POST /_dev/api/ds-upgrade?ds=<id>
```

Všechny tři jsou téměř identické (banner, header, popis, Run tlačítko,
output panel, Run again + Back to Dashboard). Implementujeme přes
**shared rendering helper** `renderActionPage($config)` v controlleru.

### Layout akční stránky

```
┌──────────────────────────────────────────────────────────────┐
│ ⚠️  DEVELOPMENT MODE                                          │
├──────────────────────────────────────────────────────────────┤
│  Server Doctor      kuba       [← Dashboard]                  │
├──────────────────────────────────────────────────────────────┤
│                                                                │
│   Runs `shpd-server doctor` and shows the report.             │
│                                                                │
│   [ Run Doctor ]                                              │
│                                                                │
│   ─── Output ────────────────────────────────                 │
│   (dark terminal-style panel, streamovaný)                    │
│                                                                │
│   ✓ Done.  [ Run again ]  [ Back to Dashboard ]               │
└──────────────────────────────────────────────────────────────┘
```

## Co je potřeba udělat

### 1. Shared `renderActionPage()` helper

Přidat do `DevDashboardController`. Generuje HTML pro action stránky.

```php
/**
 * @param array{
 *   title: string,
 *   description: string,
 *   endpoint: string,
 *   runButtonText: string,
 *   queryParams?: string,
 * } $config
 */
private function renderActionPage(array $config): Response
{
    $hostname = htmlspecialchars(gethostname() ?: 'unknown', ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($config['title'], ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars($config['description'], ENT_QUOTES, 'UTF-8');
    $endpoint = htmlspecialchars($config['endpoint'], ENT_QUOTES, 'UTF-8');
    $runText = htmlspecialchars($config['runButtonText'], ENT_QUOTES, 'UTF-8');
    $queryParams = $config['queryParams'] ?? '';

    $html = <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    ...kompletní HTML šablona...
    </html>
    HTML;

    return Response::html($html);
}
```

Šablona obsahuje:

- Banner DEVELOPMENT MODE (stejný jako ostatní stránky)
- Header s `$title`, hostnamem a `[← Dashboard]` linkem
- Popis (description) jako `<p>` blok
- Run tlačítko s textem `$runText`
- Output panel (`<pre>`, dark style, monospace) — viditelný až po
  prvním Run, předtím `display: none`
- Post-done sekce s `[Run again]` a `[Back to Dashboard]` tlačítky —
  viditelná až po `[DONE]` markeru
- Error sekce viditelná po `[ERROR]` markeru

JS používá stejný streaming pattern jako `/_dev/ds-create/`:

```js
async function runAction() {
    const button = document.getElementById('run-button');
    const output = document.getElementById('output');
    const doneSection = document.getElementById('done-section');
    const errorSection = document.getElementById('error-section');

    button.disabled = true;
    button.textContent = 'Running...';
    output.style.display = 'block';
    output.textContent = '';
    doneSection.style.display = 'none';
    errorSection.style.display = 'none';

    try {
        const url = '__ENDPOINT__' + '__QUERY_PARAMS__';
        const response = await fetch(url, { method: 'POST' });

        if (!response.ok) {
            const err = await response.json().catch(() => ({}));
            showError(err?.error?.message || 'Request failed (' + response.status + ')');
            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let sawDone = false;
        let sawError = false;

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });

            let nl;
            while ((nl = buffer.indexOf('\n')) >= 0) {
                const line = buffer.slice(0, nl);
                buffer = buffer.slice(nl + 1);
                if (line.startsWith('[DONE]')) sawDone = true;
                if (line.startsWith('[ERROR]')) sawError = true;
                appendLine(line);
            }
        }
        if (buffer) appendLine(buffer);

        if (sawError) {
            errorSection.style.display = 'block';
        } else if (sawDone) {
            doneSection.style.display = 'block';
        } else {
            // Neither [DONE] nor [ERROR] — assume success (e.g., doctor
            // outputs structured report bez explicit DONE markeru)
            doneSection.style.display = 'block';
        }
    } finally {
        button.disabled = false;
        button.textContent = '__RUN_BUTTON_TEXT__';
    }
}

function appendLine(line) {
    const output = document.getElementById('output');
    const div = document.createElement('div');
    if (line.startsWith('[STEP] ')) {
        div.className = 'line-step';
        div.textContent = line;
    } else if (line.startsWith('[ERROR] ')) {
        div.className = 'line-error';
        div.textContent = line;
    } else if (line.startsWith('[DONE] ')) {
        div.className = 'line-done';
        div.textContent = line;
    } else {
        div.className = 'line-plain';
        div.textContent = line;
    }
    output.appendChild(div);
    output.scrollTop = output.scrollHeight;
}

// "Run again" → reset stavu
document.getElementById('run-again').addEventListener('click', () => {
    document.getElementById('output').textContent = '';
    document.getElementById('done-section').style.display = 'none';
    document.getElementById('error-section').style.display = 'none';
    runAction();
});

// "Back to Dashboard" → /_dev/?refresh=1 (signál pro instant refresh)
document.getElementById('run-button').addEventListener('click', runAction);
```

V HTML `Back to Dashboard` link: `<a href="/_dev/?refresh=1">`.

### 2. Tři nové GET routes — action pages

V `dispatch()`:

```php
if ($path === '/_dev/doctor' || $path === '/_dev/doctor/') {
    return $this->doctorPage();
}

if ($path === '/_dev/upgrade-all' || $path === '/_dev/upgrade-all/') {
    return $this->upgradeAllPage();
}

if ($path === '/_dev/ds-upgrade' || $path === '/_dev/ds-upgrade/') {
    return $this->dsUpgradePage($request);
}
```

Implementace přes `renderActionPage()`:

```php
private function doctorPage(): Response
{
    return $this->renderActionPage([
        'title'         => 'Server Doctor',
        'description'   => 'Runs `shpd-server doctor` to check server configuration, '
                         . 'filesystem permissions, FPM socket, nginx routing, and DB '
                         . 'connections. Read-only — no changes are made.',
        'endpoint'      => '/_dev/api/doctor',
        'runButtonText' => 'Run Doctor',
    ]);
}

private function upgradeAllPage(): Response
{
    return $this->renderActionPage([
        'title'         => 'Upgrade All Data Sources',
        'description'   => 'Runs `shpd-server ds-upgrade-all` to upgrade schema and '
                         . 'configuration on every data source. Idempotent — DS that '
                         . 'are already up to date pass through without changes.',
        'endpoint'      => '/_dev/api/upgrade-all',
        'runButtonText' => 'Run Upgrade All',
    ]);
}

private function dsUpgradePage(Request $request): Response
{
    $dsId = $request->getQueryParams()['ds'] ?? '';

    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)+$/', $dsId)) {
        return Response::error('INVALID_DS_ID', 'Invalid or missing ?ds=<id> parameter', 400);
    }

    $dsDir = $this->dataSourcesDir . '/' . $dsId;
    if (!is_file($dsDir . '/config/main.json')) {
        return Response::error('DS_NOT_FOUND', 'Data source not found: ' . $dsId, 404);
    }

    return $this->renderActionPage([
        'title'         => 'Upgrade Data Source',
        'description'   => 'Runs `shpd-ds ds-upgrade` in `' . $dsDir . '`. '
                         . 'Idempotent — no-op if already up to date.',
        'endpoint'      => '/_dev/api/ds-upgrade',
        'runButtonText' => 'Run Upgrade',
        'queryParams'   => '?ds=' . urlencode($dsId),
    ]);
}
```

### 3. Tři nové POST routes — action endpoints

V `dispatch()`:

```php
if ($path === '/_dev/api/doctor' && $request->getMethod() === 'POST') {
    return $this->runDoctor();
}

if ($path === '/_dev/api/upgrade-all' && $request->getMethod() === 'POST') {
    return $this->runUpgradeAll();
}

if ($path === '/_dev/api/ds-upgrade' && $request->getMethod() === 'POST') {
    return $this->runDsUpgrade($request);
}
```

Implementace:

```php
private function runDoctor(): Response
{
    return Response::stream(function () {
        $shpdServer = $this->getShpdServerPath();
        $cmd = sprintf('%s doctor --no-ansi 2>&1', escapeshellarg($shpdServer));
        [$exitCode] = $this->streamCommand($cmd);

        // Doctor sám vypíše vlastní souhrn ("All checks passed" / "Issues found");
        // line-prefix protokol jen značí state pro JS klient.
        if ($exitCode === 0) {
            $this->emitDone('Doctor completed without issues');
        } else {
            $this->emitError('Doctor reported issues (exit ' . $exitCode . ')');
        }
    });
}

private function runUpgradeAll(): Response
{
    return Response::stream(function () {
        $shpdServer = $this->getShpdServerPath();

        $this->emitStep('Upgrading all data sources...');
        $cmd = sprintf('%s ds-upgrade-all --no-ansi 2>&1', escapeshellarg($shpdServer));
        [$exitCode] = $this->streamCommand($cmd);

        if ($exitCode === 0) {
            $this->emitDone('All data sources upgraded successfully');
        } else {
            $this->emitError('Upgrade-all reported failures (exit ' . $exitCode . ')');
        }
    });
}

private function runDsUpgrade(Request $request): Response
{
    $dsId = $request->getQueryParams()['ds'] ?? '';

    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)+$/', $dsId)) {
        return Response::error('INVALID_DS_ID', 'Invalid or missing ?ds=<id> parameter', 400);
    }

    $dsDir = $this->dataSourcesDir . '/' . $dsId;
    if (!is_file($dsDir . '/config/main.json')) {
        return Response::error('DS_NOT_FOUND', 'Data source not found: ' . $dsId, 404);
    }

    return Response::stream(function () use ($dsId, $dsDir) {
        $shpdDs = $this->getShpdDsPath();

        $this->emitStep('Upgrading data source ' . $dsId . '...');
        $cmd = sprintf(
            'cd %s && %s ds-upgrade --no-ansi 2>&1',
            escapeshellarg($dsDir),
            escapeshellarg($shpdDs),
        );
        [$exitCode] = $this->streamCommand($cmd);

        if ($exitCode === 0) {
            $this->emitDone('Data source ' . $dsId . ' upgraded successfully');
        } else {
            $this->emitError('Upgrade failed for ' . $dsId . ' (exit ' . $exitCode . ')');
        }
    });
}
```

**Pozn.** Existující `emitStep()`, `emitError()`, `streamCommand()`,
`getShpdServerPath()`, `getShpdDsPath()` zůstávají z fáze 3 beze
změny. `emitDone()` z fáze 3 očekává `$dsId` argument — pro nové
akce, kde `[DONE]` nese jen textovou zprávu, doplnit overload nebo
přidat novou metodu:

```php
private function emitDoneMessage(string $message): void
{
    echo "[DONE] " . json_encode(['message' => $message], JSON_UNESCAPED_SLASHES) . "\n";
    flush();
}
```

(Aby se nezbouralo chování `runDsCreatePipeline()`, který volá
`emitDone($dsId)` a payload má `{id, url}`. Pro action pages stačí
`{message}`.)

### 4. Update hlavního dashboardu — header

V `page()` HTML v hlavičce nahradit existující:

```html
<div>
    Server: <strong>{$hostname}</strong>
    &nbsp;&nbsp;
    <a href="/_dev/logs/">View Logs →</a>
    &nbsp;&nbsp;
    <a href="/_dev/ds-create/">+ New Data Source</a>
</div>
```

za kompaktnější:

```html
<div class="header-actions">
    Server: <strong>{$hostname}</strong>
    <span class="separator">|</span>
    <a href="/_dev/ds-create/">+ New DS</a>
    <a href="/_dev/upgrade-all/">Upgrade All</a>
    <a href="/_dev/logs/">Logs</a>
    <a href="/_dev/doctor/">Doctor</a>
</div>
```

V CSS:

```css
.header-actions a {
    color: #93c5fd;
    margin-left: 12px;
    text-decoration: none;
}
.header-actions a:hover { text-decoration: underline; }
.header-actions .separator { color: #6b7280; margin-left: 12px; }
```

Pořadí akcí podle frekvence použití: Create > Upgrade All > Logs > Doctor.

### 5. Update hlavního dashboardu — per-DS row

V `renderRow()` (nebo ekvivalentní místo, kde se renderuje DS řádek)
přidat třetí tlačítko `Upgrade` vedle existujících `Open` a `Logs`:

```js
// V JS funkci, která vytváří per-DS akční buttony
const openLink = document.createElement('a');
openLink.href = '/' + ds.id + '/app/';
openLink.target = '_blank';
openLink.appendChild(makeButton('Open', 'primary'));

const logsLink = document.createElement('a');
logsLink.href = '/_dev/logs/?ds=' + encodeURIComponent(ds.id);
logsLink.target = '_blank';
logsLink.appendChild(makeButton('Logs', 'secondary'));

const upgradeLink = document.createElement('a');
upgradeLink.href = '/_dev/ds-upgrade/?ds=' + encodeURIComponent(ds.id);
// target="_self" — action pages jsou same-tab; Back to Dashboard
// se signálem ?refresh=1 vrátí na dashboard se freshly fetched daty
upgradeLink.appendChild(makeButton('Upgrade', 'secondary'));
```

Tři tlačítka inline. Hustota je hraniční, ale unesené.

**Helper `makeButton(text, variant)`** (pokud ještě neexistuje):

```js
function makeButton(text, variant) {
    const btn = document.createElement('button');
    btn.textContent = text;
    btn.className = 'btn btn-' + variant;
    return btn;
}
```

V CSS:

```css
.btn-primary { background: #2563eb; color: white; }
.btn-secondary { background: #6b7280; color: white; }
.btn { padding: 4px 10px; border: none; border-radius: 4px; cursor: pointer; }
.btn + .btn, a + a { margin-left: 4px; }
```

### 6. Auto-refresh dashboardu po akci — `?refresh=1` signal

V dashboard `page()` JS, po načtení:

```js
// Pokud URL obsahuje ?refresh=1, vyčistit ho a vynutit fresh fetch
if (new URLSearchParams(location.search).has('refresh')) {
    history.replaceState({}, '', '/_dev/');
    // loadDataSources() se stejně volá v initu — nemusíme nic dalšího
}
```

`history.replaceState` smaže parametr z URL bez page reloadu. Dashboard
už dělá fetch při mount, takže refresh "for free". Hlavní účel
parametru je sémantický signál a budoucnost-proof (pokud bychom někdy
přidali např. cache nebo "show this was just refreshed" indicator).

### 7. Drobná úprava existující ds-create stránky

`Back to Dashboard` link na `/_dev/ds-create/` po úspěchu by měl
taky používat `?refresh=1`, aby existing dashboard hned ukázal nový
DS místo čekání na 60s auto-refresh:

```html
<a href="/_dev/?refresh=1">Back to Dashboard</a>
```

### 8. Testy

Rozšířit `tests/Unit/Api/Controller/DevDashboardControllerTest.php`.

#### Action page tests

- **`testDoctorPageReturnsHtml`** — `GET /_dev/doctor/` → 200,
  body obsahuje "Server Doctor", "Run Doctor", hostname
- **`testUpgradeAllPageReturnsHtml`** — `GET /_dev/upgrade-all/` → 200,
  body obsahuje "Upgrade All Data Sources"
- **`testDsUpgradePageWithValidId`** — vytvoř fake DS, `GET /_dev/ds-upgrade/?ds=<id>`
  → 200, body obsahuje DS id a "Run Upgrade"
- **`testDsUpgradePageWithInvalidIdReturns400`** — `GET /_dev/ds-upgrade/?ds=bad!id`
  → 400, error `INVALID_DS_ID`
- **`testDsUpgradePageWithoutIdReturns400`** — `GET /_dev/ds-upgrade/` (bez ?ds)
  → 400
- **`testDsUpgradePageWithUnknownIdReturns404`** — `GET /_dev/ds-upgrade/?ds=zzzz-aaaa-bbbb-cccc`
  → 404, error `DS_NOT_FOUND`

#### Action endpoint tests (subclassing s `TestableDevDashboardController`)

- **`testDoctorEndpointStreamsOutputAndReportsDone`** — mock `streamCommand` exit 0,
  output capture obsahuje `[DONE]` marker, 1 příkaz spuštěn obsahuje `doctor --no-ansi`
- **`testDoctorEndpointReportsErrorOnNonZeroExit`** — mock exit 1 → output obsahuje
  `[ERROR]` marker zmiňující exit code
- **`testUpgradeAllEndpointSpawnsCorrectCommand`** — mock exit 0, commandsRun[0]
  obsahuje `ds-upgrade-all --no-ansi`
- **`testDsUpgradeEndpointValidatesDsId`** — POST `/_dev/api/ds-upgrade?ds=bad!`
  → 400 (pre-stream validation, ne streamovaná chyba)
- **`testDsUpgradeEndpointSpawnsCorrectCommand`** — vytvoř fake DS,
  POST `/_dev/api/ds-upgrade?ds=<id>` → commandsRun[0] obsahuje
  `cd /tmp/.../id` a `ds-upgrade --no-ansi`
- **`testDsUpgradeEndpointRejectsUnknownDs`** — POST s neexistujícím DS → 404
- **`testActionEndpointsRequirePost`** — `GET /_dev/api/doctor`, `GET /_dev/api/upgrade-all`,
  `GET /_dev/api/ds-upgrade?ds=valid` → 404 (jen POST je akceptovaný)

## Co netřeba

- **Per-DS Delete** — destruktivní akce, samostatný task (vyžaduje
  nový CLI `shpd-server ds-delete`, confirmation modal s typed-name)
- **Cancel běžící akce** — `popen` neumí, hard kill přes proc_open by
  zanechal partial state; pro idempotentní akce není potřeba
- **Confirmation dialog** — pro idempotentní akce (upgrade/doctor)
  zbytečný overhead
- **--stop-on-error flag pro upgrade-all** — default je "continue",
  UI nevynáší další kontroly
- **Per-DS logs filtrace na action page** — pokud chce uživatel logy
  z konkrétního DS po upgradu, otevře `/_dev/logs/?ds=<id>` v jiné záložce

## Konvence k dodržení

- PHP `declare(strict_types=1)`, PSR-4
- `--no-ansi` u všech subprocess volání
- `2>&1` v shell command (merge stderr do stdout)
- `escapeshellarg()` pro DS id v cestě (defense in depth, i když je
  regex-validated)
- Vanilla JS, žádné innerHTML s user content
- Action page šablona je sdílená — duplicitu mezi 3 stránkami minimalizujeme

## Hotovo když

- `vendor/bin/phpunit` projde, včetně všech nových test cases
- V hlavičce dashboardu jsou 4 akce: `+ New DS`, `Upgrade All`, `Logs`, `Doctor`
- V každém řádku DS jsou 3 tlačítka: `Open`, `Logs`, `Upgrade`
- Kliknutí `Doctor` v hlavičce otevře `/_dev/doctor/`, kliknutí `Run Doctor`
  streamuje výstup `shpd-server doctor`, po dokončení se objeví `Run again`
  a `Back to Dashboard`
- Kliknutí `Upgrade All` otevře `/_dev/upgrade-all/`, `Run Upgrade All`
  streamuje výstup `shpd-server ds-upgrade-all`
- Kliknutí per-DS `Upgrade` otevře `/_dev/ds-upgrade/?ds=<id>`, stránka
  ukazuje konkrétní DS id, `Run Upgrade` streamuje výstup
  `shpd-ds ds-upgrade` z adresáře toho DS
- Po `Back to Dashboard` z action page → dashboard hned ukáže aktuální
  stav (URL `/_dev/?refresh=1` se automaticky vyčistí na `/_dev/`)
- Per-DS upgrade na neexistujícím DS (`?ds=xxxx-yyyy`) → 404 s
  message "Data source not found"
- Per-DS upgrade s rozbitým id v URL (`?ds=bad!id`) → 400 s message
  o INVALID_DS_ID
