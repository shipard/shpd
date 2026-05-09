# Dev dashboard — vytvoření DS přes UI

## Status / Cíl

Přidat do dev dashboardu stránku `/_dev/ds-create/` s formulářem pro
vytvoření nového datového zdroje. Operace běhne na serveru jako sekvence
subprocesů (`ds-create` → `ds-upgrade` → `user-create` [→ `seed-*`]),
výstup se streamuje naživo do prohlížeče. Tester po vyplnění formuláře
vidí, co se děje, a po dokončení dostane odkaz do nového DS.

## Návaznost

- Vychází z [`dev-dashboard-mvp.md`](dev-dashboard-mvp.md) a
  [`dev-dashboard-log-viewer.md`](dev-dashboard-log-viewer.md) — fáze 1 a 2,
  obě hotové.
- Funguje **jen v `mode: development`** (gate v `public/index.php` z fáze 1).
- Subprocess pattern stejný jako v [`ds-upgrade-all.md`](ds-upgrade-all.md):
  shell-out přes `popen()`, žádný `symfony/process`. Rozdíl: zde čteme
  output po řádcích a streamujeme klientovi (nikoliv `passthru()` jako
  u `ds-upgrade-all`, který běží v CLI kontextu).
- Existující CLI příkazy se nemodifikují — orchestrátor je volá
  beze změn.

## Komponenty

```
src/Api/Response.php                                    ← rozšířit o stream()
src/Api/Controller/DevDashboardController.php           ← rozšířit
tests/Unit/Api/Controller/DevDashboardControllerTest.php ← rozšířit
```

## Architektura

### Flow

```
User → form na /_dev/ds-create/
     ↓ POST /_dev/api/ds-create  {name, login, password, seed}
     ↓
DevDashboardController::dsCreate()
   ├─ validate input → 400 + Response::error pokud rozbité
   └─ Response::stream(producer)
         ↓ streamuje text do response body
         ├─ [STEP] Creating data source...
         ├─ stdout/stderr ze `shpd-server ds-create`
         ├─ [STEP] Running ds-upgrade...
         ├─ stdout/stderr ze `shpd-ds ds-upgrade`
         ├─ [STEP] Creating admin user...
         ├─ stdout/stderr ze `shpd-ds user-create` (s redakcí --password)
         ├─ (volitelně) [STEP] Seeding...
         └─ [DONE] {"id":"...","url":"/{id}/app/"} | [ERROR] message
```

JS v prohlížeči čte `response.body.getReader()`, parsuje řádky, podle
prefixu (`[STEP]` / `[DONE]` / `[ERROR]` / běžný output) renderuje do UI.

### Subprocess izolace

Stejný princip jako `ds-upgrade-all`: každý krok je samostatný subprocess,
chyba v jednom kroku ukončí pipeline (žádný auto-rollback — částečně
vytvořený DS zůstane na disku, tester ho případně smaže ručně).

## Co je potřeba udělat

### 1. Rozšíření `src/Api/Response.php` o `stream()` factory

V `Response.php` z fáze 1 jsou už `html()` a `redirect()`. Přidat třetí:

```php
/** @var (callable():void)|null */
private $streamProducer = null;

public static function stream(
    callable $producer,
    int $status = 200,
    string $contentType = 'text/plain; charset=utf-8',
): self {
    $resp = new self($status, '');
    $resp->bodyType = 'stream';
    $resp->headers['Content-Type'] = $contentType;
    $resp->headers['X-Accel-Buffering'] = 'no';   // nginx — proxy buffering off
    $resp->headers['Cache-Control'] = 'no-cache';
    $resp->streamProducer = $producer;
    return $resp;
}
```

Update `send()` — case pro stream **před** existující JSON cestou:

```php
if ($this->bodyType === 'stream') {
    // Disable PHP output buffering tak, aby flush() šel rovnou ven
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    @ob_implicit_flush(true);

    if ($this->streamProducer !== null) {
        ($this->streamProducer)();
    }
    return;
}
```

`X-Accel-Buffering: no` říká nginx aby tuhle response neufruncoval do
bufferu — chová se to spolehlivě napříč FastCGI buffering nastavením.

### 2. Rozšíření `dispatch()` v `DevDashboardController`

Přidat dvě nové cesty:

```php
if ($path === '/_dev/ds-create' || $path === '/_dev/ds-create/') {
    return $this->dsCreatePage();
}

if ($path === '/_dev/api/ds-create' && $request->getMethod() === 'POST') {
    return $this->dsCreate($request);
}
```

### 3. Validace vstupu

Privátní metoda:

```php
/**
 * @return list<string>
 */
private function validateDsCreateInput(string $name, string $login, string $password): array
{
    $errors = [];

    if ($name === '') {
        $errors[] = 'Name is required.';
    } elseif (mb_strlen($name) > 200) {
        $errors[] = 'Name is too long (max 200 characters).';
    }

    if ($login === '') {
        $errors[] = 'Admin login is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) {
        $errors[] = 'Admin login may contain only letters, digits, and underscores.';
    } elseif (mb_strlen($login) > 64) {
        $errors[] = 'Admin login is too long (max 64 characters).';
    }

    if ($password === '') {
        $errors[] = 'Admin password is required.';
    } elseif (mb_strlen($password) < 4) {
        $errors[] = 'Admin password must be at least 4 characters.';
    }

    return $errors;
}
```

### 4. POST endpoint `dsCreate(Request $request): Response`

```php
private function dsCreate(Request $request): Response
{
    $body = $request->getBody() ?? [];

    $name     = is_string($body['name'] ?? null) ? trim($body['name']) : '';
    $login    = is_string($body['login'] ?? null) ? trim($body['login']) : '';
    $password = is_string($body['password'] ?? null) ? $body['password'] : '';
    $seed     = (bool) ($body['seed'] ?? false);

    $errors = $this->validateDsCreateInput($name, $login, $password);
    if ($errors) {
        return Response::error('VALIDATION', implode(' ', $errors), 400);
    }

    return Response::stream(function () use ($name, $login, $password, $seed) {
        $this->runDsCreatePipeline($name, $login, $password, $seed);
    });
}
```

### 5. Pipeline orchestrátor

```php
protected function getShpdServerPath(): string
{
    return dirname(__DIR__, 3) . '/bin/shpd-server';
}

protected function getShpdDsPath(): string
{
    return dirname(__DIR__, 3) . '/bin/shpd-ds';
}

private function runDsCreatePipeline(
    string $name,
    string $login,
    string $password,
    bool $seed,
): void {
    $shpdServer = $this->getShpdServerPath();
    $shpdDs     = $this->getShpdDsPath();

    // ── 1. ds-create ────────────────────────────────────────────────
    $this->emitStep('Creating data source...');
    $cmd = sprintf(
        '%s ds-create --name=%s --no-ansi 2>&1',
        escapeshellarg($shpdServer),
        escapeshellarg($name),
    );
    [$exitCode, $output] = $this->streamCommand($cmd);

    if ($exitCode !== 0) {
        $this->emitError('ds-create failed (exit ' . $exitCode . ')');
        return;
    }

    // Parse DS ID z řádku "  Directory:     /opt/shipard/data-sources/<id>"
    if (!preg_match('~Directory:\s+(\S+)~', $output, $m)) {
        $this->emitError('Could not parse data source directory from output');
        return;
    }
    $dsDir = $m[1];
    $dsId  = basename($dsDir);

    // ── 2. ds-upgrade ───────────────────────────────────────────────
    $this->emitStep('Running ds-upgrade...');
    $cmd = sprintf(
        'cd %s && %s ds-upgrade --no-ansi 2>&1',
        escapeshellarg($dsDir),
        escapeshellarg($shpdDs),
    );
    [$exitCode] = $this->streamCommand($cmd);

    if ($exitCode !== 0) {
        $this->emitError(
            'ds-upgrade failed — DS ' . $dsId . ' was created but is not usable. '
            . 'Check the directory and run upgrade manually.'
        );
        return;
    }

    // ── 3. user-create ──────────────────────────────────────────────
    $this->emitStep('Creating admin user "' . $login . '"...');
    $cmd = sprintf(
        'cd %s && %s user-create --login=%s --password=%s --name=%s --no-ansi 2>&1',
        escapeshellarg($dsDir),
        escapeshellarg($shpdDs),
        escapeshellarg($login),
        escapeshellarg($password),
        escapeshellarg($login),  // full name = login (drobnost: lze přepsat později v UI)
    );
    [$exitCode] = $this->streamCommand($cmd, redactPassword: true);

    if ($exitCode !== 0) {
        $this->emitError(
            'user-create failed — DS ' . $dsId . ' was upgraded but has no admin user.'
        );
        return;
    }

    // ── 4. seed (optional) ──────────────────────────────────────────
    if ($seed) {
        $this->emitStep('Seeding test persons...');
        $cmd = sprintf(
            'cd %s && %s seed-persons --no-ansi 2>&1',
            escapeshellarg($dsDir),
            escapeshellarg($shpdDs),
        );
        [$exitCode] = $this->streamCommand($cmd);
        if ($exitCode !== 0) {
            $this->emitError('seed-persons failed (DS is otherwise usable)');
            return;
        }

        $this->emitStep('Seeding test mail...');
        $cmd = sprintf(
            'cd %s && %s seed-mail --no-ansi 2>&1',
            escapeshellarg($dsDir),
            escapeshellarg($shpdDs),
        );
        [$exitCode] = $this->streamCommand($cmd);
        if ($exitCode !== 0) {
            $this->emitError('seed-mail failed (DS is otherwise usable)');
            return;
        }
    }

    // ── Done ────────────────────────────────────────────────────────
    $this->emitDone($dsId);
}

/**
 * Spustí příkaz, naživo streamuje výstup klientovi a zachytí ho.
 *
 * @return array{0: int, 1: string} [exitCode, capturedOutput]
 */
protected function streamCommand(string $cmd, bool $redactPassword = false): array
{
    $proc = popen($cmd, 'r');
    if ($proc === false) {
        return [-1, ''];
    }

    $captured = '';
    while (($line = fgets($proc)) !== false) {
        $emitted = $redactPassword
            ? preg_replace('/--password=\S+/', '--password=***', $line)
            : $line;

        echo $emitted;
        flush();

        $captured .= $line;  // capture original (kvůli parsování ID atd.)
    }

    $status = pclose($proc);
    // pclose() vrací full status; exit code je horních 8 bitů
    $exitCode = ($status === -1) ? -1 : (($status >> 8) & 0xFF);

    return [$exitCode, $captured];
}

private function emitStep(string $msg): void
{
    echo "[STEP] " . $msg . "\n";
    flush();
}

private function emitError(string $msg): void
{
    echo "[ERROR] " . $msg . "\n";
    flush();
}

private function emitDone(string $dsId): void
{
    $payload = json_encode(
        ['id' => $dsId, 'url' => '/' . $dsId . '/app/'],
        JSON_UNESCAPED_SLASHES,
    );
    echo "[DONE] " . $payload . "\n";
    flush();
}
```

**Pozn. k `getShpdServerPath()` / `getShpdDsPath()` jako protected:**
testovatelnost přes subclassing — test je přepíše na fake skripty
(echo + exit 0).

### 6. HTML stránka `/_dev/ds-create/`

Single-file HTML+CSS+JS, vanilla, žádný build.

#### Layout

```
┌────────────────────────────────────────────────────────────────────┐
│ ⚠️  DEVELOPMENT MODE — do not deploy publicly                      │
├────────────────────────────────────────────────────────────────────┤
│  Create new Data Source     Server: kuba       [← Dashboard]       │
├────────────────────────────────────────────────────────────────────┤
│                                                                     │
│   Name *           [_______________________________]               │
│   Admin login *    [admin___________________________]              │
│   Admin password * [admin_______________] [show]                   │
│   ☐ Seed test data (persons, mail samples)                          │
│                                                                     │
│   [ Create Data Source ]                                            │
│                                                                     │
│   ─── Output ──────────────────────────────────                     │
│   ▶ Creating data source...                                         │
│     (streamovaný output, monospace, scroll)                         │
│   ✓ Done!  [ Open data source → ]   [ Create another ]              │
│                                                                     │
└────────────────────────────────────────────────────────────────────┘
```

#### Styl

- Stejný banner + header pattern jako MVP a logs page
- Form: max-width 500px, vlevo zarovnaný, labely nad inputy
- Inputs: standardní HTML, padding 8px, border 1px solid #d1d5db,
  focus border modrý
- "show" tlačítko vedle password — toggle `<input type>` mezi
  `password` ↔ `text`
- Submit tlačítko: primární modré, full width, disabled stav během
  běhu (text "Creating..." s spinning indikátorem)
- Output panel: `<pre>`, monospace, `font-size: 0.85em`,
  `background: #1f2937`, `color: #f3f4f6` (dark terminal look),
  `max-height: 60vh`, auto-scroll při novém řádku
- `[STEP]` řádky: highlight modře (`color: #93c5fd`)
- `[ERROR]` řádky: highlight červeně (`color: #fca5a5`)
- Běžný output: bílý, mírně tlumený
- Po `[DONE]` zobrazit success banner zeleně + dvě tlačítka
- Po `[ERROR]` zobrazit error banner + tlačítko "Try again"

#### Form chování

- Defaulty: `login = "admin"`, `password = "admin"`, `seed = false`
- Inline validace (klient-side) duplikuje server-side regexy:
  - Login `/^[a-zA-Z0-9_]+$/`, nonempty, max 64
  - Password nonempty, min 4
  - Name nonempty, max 200
- Při submitu: disable všech inputs + tlačítka, vyčistit output panel,
  fetch POST se streaming readerem
- Pokud server vrátí 400 (validation chyba), zobrazit chybu nad formem
  a re-enable inputs

#### Streaming reader (JS)

```js
async function submitForm() {
    const body = JSON.stringify({ name, login, password, seed });
    const response = await fetch('/_dev/api/ds-create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body,
    });

    if (!response.ok) {
        // Pre-stream chyba (400 validation)
        const err = await response.json().catch(() => ({}));
        showFormError(err?.error?.message || 'Validation failed');
        return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });

        let nl;
        while ((nl = buffer.indexOf('\n')) >= 0) {
            const line = buffer.slice(0, nl);
            buffer = buffer.slice(nl + 1);
            handleLine(line);
        }
    }
    if (buffer) handleLine(buffer);  // poslední neukončený řádek
}

function handleLine(line) {
    if (line.startsWith('[STEP] ')) {
        appendOutput(line, 'step');
    } else if (line.startsWith('[ERROR] ')) {
        appendOutput(line, 'error');
        showErrorBanner(line.slice(8));
    } else if (line.startsWith('[DONE] ')) {
        const data = JSON.parse(line.slice(7));
        showDoneBanner(data);
    } else {
        appendOutput(line, 'plain');
    }
}
```

#### URL parameter pre-fill (volitelné, drobné UX)

`/_dev/ds-create/?name=Demo` předvyplní pole Name. Z dashboardu zatím
voláme bez parametru.

### 7. "+ New Data Source" tlačítko v MVP dashboardu

V `page()` (HTML pro `/_dev/`), v hlavičce přidat link vedle
existujícího "View Logs":

```html
<header>
    <h1>Shipard Dev Dashboard</h1>
    <div>
        Server: <strong>{$hostname}</strong>
        &nbsp;&nbsp;
        <a href="/_dev/logs/" style="color: #93c5fd;">View Logs →</a>
        &nbsp;&nbsp;
        <a href="/_dev/ds-create/" style="color: #93c5fd;">+ New Data Source</a>
    </div>
</header>
```

### 8. Testy

#### Rozšíření `tests/Unit/Api/Controller/DevDashboardControllerTest.php`

Validační testy (jednoduché, bez subprocess):

- **`testDsCreatePageReturnsHtml`** — `GET /_dev/ds-create/` → 200,
  body obsahuje "Create new Data Source"
- **`testDsCreatePageWithoutTrailingSlash`** — `GET /_dev/ds-create` → 200
- **`testDsCreateValidationEmptyName`** — POST s `{name: ''}` →
  status 400, error obsahuje "Name is required"
- **`testDsCreateValidationInvalidLogin`** — POST s `{login: 'bad-login!'}`
  → 400, error o povolených znacích
- **`testDsCreateValidationShortPassword`** — POST s `{password: 'ab'}`
  → 400, error o min 4 znaky
- **`testDsCreateValidationMultipleErrors`** — POST s prázdným body →
  400, message obsahuje všechny tři chyby
- **`testDsCreateGetReturns404`** — `GET /_dev/api/ds-create` → 404 (jen POST)

Pipeline test přes subclassing (mock subprocesů):

- **`TestableDevDashboardController`** přepíše `streamCommand()`
  z polem mocked outputs:
  ```php
  class TestableDevDashboardController extends DevDashboardController {
      /** @var list<array{0: int, 1: string}> */
      public array $commandResults = [];
      public array $commandsRun = [];

      protected function streamCommand(string $cmd, bool $redactPassword = false): array {
          $this->commandsRun[] = $cmd;
          if (count($this->commandResults) === 0) {
              return [0, ''];
          }
          $result = array_shift($this->commandResults);
          // Stále emituje captured output přes echo, abychom mohli verifikovat
          echo $result[1];
          return $result;
      }
  }
  ```

- **`testDsCreatePipelineHappyPath`** — nakonfigurovat 3 commandResults
  (ds-create vrací output s "Directory: /tmp/test/abc123", ds-upgrade
  prázdný, user-create prázdný), zachytit stream output přes `ob_start()`
  → expect tři `[STEP]` markery, `[DONE]` s `id: "abc123"`,
  3 příkazy v `commandsRun`
- **`testDsCreatePipelineCreateFails`** — první commandResult vrací
  exitCode 1 → expect `[ERROR]`, žádný `[DONE]`, jen 1 příkaz spuštěn
- **`testDsCreatePipelineUpgradeFails`** — druhý commandResult exitCode 1
  → expect `[ERROR]` zmiňující `ds-upgrade failed`, 2 příkazy spuštěny
- **`testDsCreatePipelineParsesIdFromDirectoryLine`** — output obsahuje
  `  Directory:     /opt/shipard/data-sources/zzzz-aaaa-bbbb-cccc`,
  ověř že `[DONE]` má `id: "zzzz-aaaa-bbbb-cccc"`
- **`testDsCreatePipelineFailsOnMissingDirectoryLine`** — ds-create
  vrátí 0, ale output bez "Directory:" řádku → `[ERROR]` "Could not
  parse"
- **`testDsCreatePipelineWithSeed`** — `seed: true` → 5 příkazů spuštěno
  (create + upgrade + user + seed-persons + seed-mail), všechny success
- **`testDsCreatePasswordRedactedInOutput`** — třetí command (user-create)
  obsahuje `--password=secret123` v echo'd output → ve streamu nahrazeno
  `--password=***`. **Pozor:** redakce platí pro to, co `streamCommand()`
  echo'uje, ne pro to, co vrací jako captured. V mock implementaci
  testu echo zachytit přes `ob_start()`.

Pro stream capture v testech:

```php
ob_start();
$tester = new CommandTester($cmd);  // nebo přímý dispatch volání
$tester->execute(...);
$output = ob_get_clean();
```

Ale pozor: `Response::stream()` v skutečnosti volá producer během
`send()`. V testech nezavoláme `send()` přímo, místo toho zavoláme
producer přímo. Helper:

```php
private function streamToString(Response $response): string {
    $reflection = new \ReflectionProperty($response, 'streamProducer');
    $reflection->setAccessible(true);
    $producer = $reflection->getValue($response);

    ob_start();
    $producer();
    return ob_get_clean();
}
```

Reflexe protože `streamProducer` je private. Jediné místo kde
porušujeme princip "subclassing > reflexe" — testovat producer
extrakcí je únosné, alternativa by bylo dělat ho protected jen kvůli
testům.

## UX detaily

### Po `[DONE]`

Success banner zeleně s textem "Data source created successfully" +
dvě tlačítka:

- **Open data source →** (`<a href="{url}" target="_blank">`) — otevře
  nový DS v novém tabu
- **Create another** — reload stránky, vyčistí form

Form a output panel zůstanou viditelné pod bannerem (tester si může
prohlédnout, co se dělo).

### Po `[ERROR]`

Error banner červeně s chybovou zprávou + tlačítko **Try again**
(reload stránky, vyčistí form). Output panel zůstane pro debugging.

### Disabled state během běhu

Submit tlačítko: text "Creating...", `disabled`, vedle něj malý
animovaný puntík (CSS `@keyframes`). Inputs taky `disabled`.

## Bezpečnost

- `mode: development` gate (z fáze 1) je jediný auth — stejný princip
  jako celý dashboard.
- **Password redakce** v streamu: `streamCommand(redactPassword: true)`
  nahradí `--password=...` na `--password=***`. Týká se jen toho, co
  jde do echo / streamu, ne capturedu (ten je internal pro parsování).
- Login regex `^[a-zA-Z0-9_]+$` chrání před shell injection v
  `escapeshellarg` — i kdyby `escapeshellarg` selhal (nepravděpodobné),
  povolené znaky jsou v shellu bezpečné.
- Name může obsahovat libovolné UTF-8 — `escapeshellarg` to bezpečně
  obalí, MySQL ho přebírá přes prepared statement v `ds-create`.
- Žádný rate limit — dev mode, předpokládáme trusted user.

## Co netřeba

- Auto-rollback při chybě (manuální cleanup)
- Pokročilá konfigurace modulů, jazyků, currency
- Editace existujícího DS, mazání DS přes UI
- Cancel běžícího vytváření
- Generování bezpečného hesla (default `admin/admin` v dev)
- WebSocket / SSE — chunked text response stačí
- Strukturovaná protokol JSON-per-line — line-prefix `[STEP]`/`[DONE]`
  je jednodušší a lidsky čitelný v `curl`
- Kontrola unikátnosti názvu DS (názvy se mohou opakovat — DS jsou
  identifikované ID, ne názvem)

## Konvence k dodržení

- PHP `declare(strict_types=1)`, PSR-4
- `--no-ansi` u všech subprocess volání (deterministický parsing)
- `2>&1` v shell command (merge stderr do stdout pro single stream)
- `escapeshellarg()` pro **všechny** user-supplied hodnoty
- Test pattern: subclassing přes `Testable*Controller` + protected
  `getShpdServerPath()` / `getShpdDsPath()` / `streamCommand()`
- Vanilla JS, render přes DOM API, žádné innerHTML s user content

## Hotovo když

- `vendor/bin/phpunit` projde, včetně všech rozšiřujících testů
- `curl -X POST http://{ip}/_dev/api/ds-create -H 'Content-Type: application/json' -d '{}'`
  vrátí 400 s validation errors
- `curl -X POST http://{ip}/_dev/api/ds-create -H 'Content-Type: application/json' -d '{"name":"Test","login":"admin","password":"admin","seed":false}'`
  streamuje výstup (`[STEP] Creating data source...`,
  output ze `ds-create`, ..., `[DONE] {"id":"...","url":"..."}`),
  konec souboru po dokončení
- V prohlížeči `/_dev/ds-create/`:
  - Form má defaulty `login=admin`, `password=admin`, seed off
  - "show" tlačítko toggluje viditelnost hesla
  - Klient-side validace zobrazí chyby pod poli
  - Při submitu se inputs disabled, output panel zobrazuje
    streaming text v reálném čase (řádky se objevují postupně, ne
    najednou na konci)
  - `[STEP]` řádky modré, `[ERROR]` červené, output bílý,
    auto-scroll funguje
  - Po `[DONE]` se zobrazí green success banner s tlačítky "Open"
    (otevře nový DS) a "Create another" (reload)
  - Po `[ERROR]` red error banner s tlačítkem "Try again"
- Z `/_dev/` dashboardu vede v hlavičce odkaz "+ New Data Source"
  na ds-create stránku
- Vytvořený DS je okamžitě viditelný v hlavním dashboardu (po refresh
  nebo auto-refresh)
- Heslo `--password=admin` se v output streamu objeví jako
  `--password=***` (pouze v případě, že by ho subprocess v output
  zmínil; standardně `user-create` ho neecho-uje, ale redakce je tam
  jako bezpečnostní pojistka)
