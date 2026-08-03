# Task: MCP server — skeleton + persons_search (Fáze 1)

**Stav:** hotovo

## Kontext

Začínáme integraci AI do Shipardu. Cílový stav (z designové diskuze): uživatel
diskutuje nad svými daty, ptá se na odbornou problematiku a „agenti" dělají
rutinní práci (zpracování analyzované došlé pošty, upozornění). Architektura
stojí na jednom sdíleném základu — **MCP serveru**, který vystavuje *nástroje*
(operace nad Shipardem) jako funkce volatelné jazykovým modelem. Vnitřní chat,
externí klienti (Claude Desktop/Code) i agenti na pozadí jsou pak jen jeho
konzumenti. LLM (cloud i lokální) je vyměnitelný díl; nástroje napíšeme jednou.

Tato fáze staví **kostru MCP serveru** a jeden reálný čtecí nástroj
`persons_search`, aby šla celá smyčka ověřit end-to-end (jako u attachments se
dotahuje vertikální řez, ne holá infrastruktura).

**Uzavřená rozhodnutí, na kterých fáze stojí:**

- MCP server je **nativně v PHP**, jako součást stávající API aplikace
  (in-process, žádný druhý běhový proces, žádný HTTP skok). Protokol je malý;
  doménová logika už existuje a jen ji LLM-přívětivě obalíme.
- Transport: **Streamable HTTP** (single endpoint `POST /api/v1/_mcp`), ne stdio.
- Autentizace: stávající **Bearer tokeny** (`shpd_ak_` API klíč i `shpd_st_`
  session). MCP OAuth flow pro cizí klienty je **odloženo** na pozdější fázi.
- Katalog nástrojů se řadí podle **rizika**: čtení (bez brzdy) → koncepty
  (zápis do `docState` konceptu, lidská revize) → akce (potvrzení/zatím ne).
  Fáze 1 je čistě **čtecí**.
- **Orchestrátor** vnitřního chatu (smyčka LLM ↔ nástroje, streamování do UI)
  je samostatné rozhodnutí na později a tato fáze ho neřeší — MCP server jen
  vystavuje nástroje.

**Pozn. k MCP SDK / verzi protokolu:** ekosystém se rychle mění a verzi
protokolu i tvar `capabilities`/structured output ověříme proti aktuální spec
revizi při výběru knihovny. Tato fáze je psaná **verzně robustně** —
`protocolVersion` se **negociuje echem** klientovy hodnoty (viz `initialize`
níže), takže neukotvujeme konkrétní revizi.

## Návaznost

- Staví nad existující API vrstvou: `Router`, `AuthMiddleware`, `dispatch()`
  v `public/index.php`, `Request`/`Response`, `DataSourceConnection`.
- Navazuje na API-key infrastrukturu (`api-key-cli.md`, `ApiKeyService`,
  `core_system_api_keys`).
- Tool `persons_search` znovupoužívá dotazovací vzor z `PersonsLookup`.
- Budoucí fáze: zbývající čtecí nástroje (`persons_get`, `documents_search`,
  `mail_list_pending`), pak draft nástroj `mail_draft_document` (zápisový tier).

## Před implementací přečti

- **`public/index.php`** — bootstrap a `dispatch()`. Vzor, jak se dispatcher
  větví na `dispatchXxx()` helpery a jak `dispatchExchange`/`dispatchPersonsRegistry`
  instancují **modulové třídy přímo** (sem patří i registrace MCP nástrojů ve
  fázi 1). Sleduj, co vše je v `dispatch()` k dispozici (`$auth`, `$tables`,
  `$db`, `$configRuntime`).
- **`src/Api/Router.php`** — exact-match bloky `/_*` (vzor `/_mail/import`,
  `/_alerts`). Sem přidat `/_mcp`. Generický CRUD je až na konci, takže `/_mcp`
  blok musí být **před** ním.
- **`src/Api/Middleware/AuthMiddleware.php`** — `handle()` vrací
  `AuthContext::anonymous()` když chybí token (NEodmítá!). Vynucení přihlášení
  je tedy na controlleru. `shpd_ak_` → api_key, `shpd_st_` → session, oba dají
  `AuthContext` s `userId`.
- **`src/Api/AuthContext.php`** — `isAuthenticated`, `userId`, `tokenType`.
- **`src/Api/Request.php`** / **`src/Api/Response.php`** — `Request::getBody()`
  vrací už dekódovaný JSON (`?array`). **`Response::raw($data, $status)`** posílá
  payload přímo přes `json_encode` **bez** `{success,data}` obálky — to je to,
  co potřebujeme pro JSON-RPC envelope (NE `Response::success`, ta by obalila).
- **`modules/base/persons/src/PersonsLookup.php`** — hotový dotaz nad
  `base_persons_persons` (sloupce, `docState IN (10,40,80)`, LIKE přes
  `full_name`/`company_id`/`person_id`, `PersonType`). `persons_search` ho
  rozšíří o `tax_id`/`vat_id` (DIČ) a vrátí bohatší výstup.
- **`modules/base/persons/tables/base_persons_persons.jsonc`** — tableId 201,
  relevantní sloupce: `full_name`, `person_id` (kód), `person_type` (enum),
  `company_id` (IČO), `tax_id`, `vat_id` (DIČ), `birth_date`, `email`,
  `displayPattern: "{person_id} — {full_name}"`.
- **`src/Api/Controller/PersonsRegistryController.php`** — **POZOR na rozlišení**:
  tohle hledá v *externím* registru (ARES). `persons_search` hledá v *lokální*
  tabulce. Popis nástroje musí LLM jasně navést, kdy použít co.
- **`tasks/mail-phase4-import-endpoint.md`** — vzor formátu PRD i stylu endpoint
  tasku.

## Scope

**V rozsahu:**

- Routa `POST /api/v1/_mcp` + dispatcher wiring.
- JSON-RPC 2.0 vrstva nad jedním requestem: `initialize`,
  `notifications/initialized`, `tools/list`, `tools/call`.
- `McpController` + protokolové třídy (`src/Api/Mcp/`).
- Abstrakce nástroje: `McpTool` interface + `McpToolRegistry` +
  `McpInvocationContext` + jednotný kontrakt výstupní obálky.
- Jeden nástroj `persons_search` (modul `base.persons`).
- Vynucení autentizace (HTTP 401 bez tokenu).
- Testy.

**Mimo rozsah:**

- JSON-RPC batching (pole requestů) — fáze 1 zpracovává jeden request.
- SSE/streamování (GET na `/_mcp`) — server-initiated zprávy neřešíme.
- MCP `resources` a `prompts` — jen `tools`.
- MCP OAuth / discovery metadata pro cizí klienty.
- Module-driven registrace nástrojů přes `module.jsonc` (`mcpTools`) — viz
  Rozhodnutí #2; ve fázi 1 in-code registr.
- Jakýkoli zápisový/akční nástroj.

## Co implementovat

### 1. Routa `/_mcp`

V `Router::resolve`, mezi ostatní `/_*` bloky (před generický CRUD):

```php
if ($subpath === '/_mcp') {
	if ($method !== 'POST') {
		return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
	}
	return new Route('mcp', 'rpc');
}
```

(GET pro SSE je mimo rozsah — zatím jen POST.)

### 2. Dispatcher wiring

V `public/index.php` do `match ($route->controller)` přidat:

```php
'mcp' => dispatchMcp($request, $auth, $resolved->connection, $tables, $configRuntime),
```

a helper:

```php
function dispatchMcp(
	Request $request,
	AuthContext $auth,
	\Shipard\Core\Database\DataSourceConnection $db,
	array $tables,
	?\Shipard\Core\Config\ConfigRuntime $configRuntime,
): Response {
	// Fáze 1: in-code registr nástrojů (analogie wiring v dispatchExchange).
	$registry = new \Shipard\Api\Mcp\McpToolRegistry();
	$registry->register(new \Shipard\Module\Base\Persons\Mcp\PersonsSearchTool());

	$ctrl = new \Shipard\Api\Controller\McpController($registry);
	return $ctrl->rpc($request, $auth, $db, $tables, $configRuntime);
}
```

### 3. JSON-RPC 2.0 vrstva (`src/Api/Mcp/`)

Lehká vrstva — žádná knihovna. Tělo requestu bere z `Request::getBody()`
(už dekódované `?array`).

**Envelope:** request `{jsonrpc:"2.0", id, method, params?}`; response
`{jsonrpc:"2.0", id, result}` nebo `{jsonrpc:"2.0", id, error:{code,message,data?}}`.

**Chybové kódy:** `-32700` parse error, `-32600` invalid request,
`-32601` method not found, `-32602` invalid params, `-32603` internal error.

**Mapování na HTTP status (důležité — neplést):**

- Korektní JSON-RPC odpověď (i s `error`) → **HTTP 200**, tělo přes
  `Response::raw($envelope, 200)`.
- **Notifikace** (request bez `id`, např. `notifications/initialized`) → server
  NEvrací JSON-RPC odpověď → **HTTP 202**, prázdné tělo
  (`Response::raw(null, 202)` je ok).
- Chybějící/neplatný Bearer token → **HTTP 401** (transport-level, řeší se
  *před* JSON-RPC — viz bod 8).
- Tělo není platný JSON → `-32700` (HTTP 200).

Doporučené třídy: `JsonRpcRequest` (parse + validace tvaru), `JsonRpcError`
(konstanty kódů + factory), případně `JsonRpcResponder` (sestavení envelope).
Drž je malé a bez závislosti na doméně.

### 4. `McpController` (`src/Api/Controller/McpController.php`)

Konstruktor bere `McpToolRegistry`. Metoda:

```php
public function rpc(
	Request $request,
	AuthContext $auth,
	DataSourceConnection $db,
	array $tables,
	?ConfigRuntime $configRuntime,
): Response
```

Flow:

1. **Auth gate** (bod 8): `if (!$auth->isAuthenticated)` → `Response::error(
   'UNAUTHORIZED', 'Authentication required', 401)`.
2. Parse JSON-RPC z `$request->getBody()`. Není-li platné → `-32700`/`-32600`.
3. Dispatch dle `method`:
   - `initialize` → výsledek handshake (bod 4a).
   - `notifications/initialized` (notifikace, bez `id`) → HTTP 202, prázdno.
   - `tools/list` → `{tools: [...]}` z registru (bod 4b).
   - `tools/call` → spustí nástroj (bod 4c).
   - jinak → `-32601` method not found.

#### 4a. `initialize`

Params od klienta: `protocolVersion`, `capabilities`, `clientInfo`. Odpověď:

```json
{
  "protocolVersion": "<echo klientovy hodnoty>",
  "capabilities": { "tools": {} },
  "serverInfo": { "name": "shipard", "version": "<z ServerConfig/verze appky>" }
}
```

`protocolVersion` **echo** klientovy požadované hodnoty (negociace bez ukotvení
revize). Když klient verzi nepošle, dosadit naši nominální (konstanta, snadno
změnitelná). `capabilities.tools` prázdný objekt = „umíme nástroje".

#### 4b. `tools/list`

```json
{ "tools": [ { "name": "...", "description": "...", "inputSchema": { ... } } ] }
```

Pole sestav z `McpToolRegistry`: pro každý nástroj `name()`, `description()`,
`inputSchema()`. Bez kurzoru (fáze 1 vrací vše). `outputSchema` per-tool je
volitelný a verzně závislý — ve fázi 1 vynech (data nesou `structuredContent`,
viz 4c), případně doplň po ověření spec revize.

#### 4c. `tools/call`

Params `{name: string, arguments: object}`.

- Neznámý `name` → JSON-RPC **`-32602`** (protokolová chyba, nástroj neexistuje).
- Chybějící/špatné `arguments` proti `inputSchema` → `-32602` invalid params.
  (Fáze 1: stačí lehká manuální validace povinných polí v nástroji; plnou
  JSON-Schema validaci neřešíme.)
- Nástroj se zavolá s `McpInvocationContext` (nese `$auth`, `$db`, `$tables`,
  `$configRuntime`). Vrátí **doménovou obálku** (bod 6). Controller ji zabalí
  do MCP wire formátu (bod 7).
- **Chyba při vykonání** nástroje (např. DB error) ≠ protokolová chyba: vrať
  `result` s `isError: true` a textovým `content` popisujícím problém, ať na to
  LLM umí zareagovat. JSON-RPC `error` rezervuj pro protokol.

### 5. Abstrakce nástroje (`src/Api/Mcp/`)

```php
interface McpTool
{
	public function name(): string;        // 'persons_search' — [a-z0-9_], bez tečky
	public function description(): string; // text pro LLM (= prompt, viz bod 6)
	public function inputSchema(): array;  // JSON Schema (type:object)

	/** @return array doménová obálka (bod 6) */
	public function call(array $arguments, McpInvocationContext $ctx): array;
}
```

`McpToolRegistry`: `register(McpTool)`, `all(): McpTool[]`, `get(string $name): ?McpTool`.

`McpInvocationContext` (readonly): `AuthContext $auth`, `DataSourceConnection $db`,
`array $tables`, `?ConfigRuntime $config`.

**Jmenná konvence:** `modul_operace` s podtržítkem (`persons_search`) — tečku
nepoužívat, někteří provideři ji ve jméně nástroje nepovolují. (Přesný povolený
vzor potvrdíme při výběru SDK.)

### 6. `PersonsSearchTool` (`modules/base/persons/src/Mcp/PersonsSearchTool.php`)

Namespace `Shipard\Module\Base\Persons\Mcp\`.

**`name()`** → `persons_search`

**`description()`** (LLM-facing, přesný, s kdy použít/nepoužít):

> „Vyhledá osoby a firmy evidované v Shipardu podle jména, IČO, DIČ nebo kódu
> osoby. Použij, když uživatel odkazuje na konkrétní osobu nebo firmu a
> potřebuješ její záznam nebo `ref` pro další krok. Hledá v **lokální evidenci
> osob**, NE ve veřejných registrech (ARES) — pro lustraci nové firmy podle IČO
> ve veřejném rejstříku tento nástroj nepoužívej. Nepoužívej ani pro doklady či
> faktury."

**`inputSchema()`**:

```json
{
  "type": "object",
  "properties": {
    "query":  { "type": "string", "description": "Volný text: jméno, IČO, DIČ nebo kód osoby. Prázdné = první stránka." },
    "limit":  { "type": "integer", "minimum": 1, "maximum": 50, "default": 20 },
    "offset": { "type": "integer", "minimum": 0, "default": 0 }
  },
  "required": ["query"]
}
```

**`call()`**:

- Sestav SQL nad `base_persons_persons` ve stylu `PersonsLookup::search()`,
  ale rozšiř LIKE i o `tax_id` a `vat_id` (uživatel chce hledat dle DIČ) a do
  SELECTu přidej `tax_id`, `vat_id`, `email`. Zachovej `docState IN (10,40,80)`
  a `ORDER BY full_name ASC`.
- Stránkování: `LIMIT (limit+1) OFFSET offset`; pokud se vrátí `limit+1` řádků,
  ořízni na `limit` a nastav `has_more=true`.
- `limit` zařízni do 1..50 (default 20), `offset` >= 0.
- Vrať **doménovou obálku**:

```php
return [
  'summary' => $n === 0
    ? "Nenalezena žádná osoba pro \"{$q}\"."
    : "Nalezeno {$shown} osob pro \"{$q}\"" . ($hasMore ? " (zobrazeno prvních {$shown})." : "."),
  'items' => array_map(fn($r) => [
    'ref'         => ['type' => 'person', 'id' => (int) $r['id']],
    'full_name'   => (string) ($r['full_name'] ?? ''),
    'person_type' => $personTypeLabel,            // 'company' | 'person'
    'company_id'  => $r['company_id'] ?: null,     // IČO
    'vat_id'      => $r['vat_id'] ?: null,          // DIČ
    'email'       => $r['email'] ?: null,
  ], $rows),
  'pagination' => ['limit' => $limit, 'offset' => $offset, 'returned' => $shown, 'has_more' => $hasMore],
];
```

`person_type` mapuj přes `PersonType` enum na stabilní string (`company`/`person`),
ne na číslo — LLM-přívětivé. **Žádné syrové DB sloupce** nad rámec kurátorovaného
seznamu (princip „výstup tvaruješ pro LLM").

### 7. Mapování obálky → MCP wire formát (v `McpController`)

Controller (ne nástroj) vlastní wire formát. Z obálky sestav `tools/call` result:

```json
{
  "content": [ { "type": "text", "text": "<summary + kompaktní výpis items>" } ],
  "structuredContent": { "summary": ..., "items": [...], "pagination": {...} },
  "isError": false
}
```

- `content[].text` — **vždy** (univerzálně podporováno): `summary` plus stručný
  čitelný výpis položek (např. `"#123 Jan Novák — IČO 123…"` na řádku). Tohle je
  to, co LLM/uživatel uvidí i u klientů bez structured output.
- `structuredContent` — celá obálka pro klienty, co ji umí. Aditivní; pokud
  cílová spec revize `structuredContent` nezná, data stále cestují v textu.

### 8. Vynucení autentizace

`AuthMiddleware` vrací `anonymous()` bez tokenu — `McpController::rpc()` proto
**hned na začátku** vrátí HTTP 401, pokud `!$auth->isAuthenticated`. Fáze 1
přijímá oba typy tokenu (`api_key` i `session`).

**Scope:** čtecí nástroje jsou automaticky **DS-scoped** — DataSource je
resolvnutý už v `index.php` z hostu/cesty, dotaz běží v DB té DS. `userId` je
v kontextu k dispozici (audit, budoucí per-user omezení), ale tabulka osob není
user-scoped, takže fáze 1 dle uživatele nefiltruje. Jemnější autorizaci řešíme
až u zápisových nástrojů.

### 9. OpenAPI / testy

- **OpenAPI:** `/_mcp` je JSON-RPC, ne REST — do generované spec **nezahrnuj**.
- **Testy** (API úroveň, vzor stávajících controller testů):
  1. `initialize` → result má `serverInfo`, `capabilities.tools`, a
     `protocolVersion` = echo poslané hodnoty.
  2. `notifications/initialized` → HTTP 202, prázdné tělo.
  3. `tools/list` → obsahuje `persons_search` s `inputSchema`.
  4. `tools/call persons_search` se seedovanými osobami → obálka s `items`
     (správné `ref`, `company_id`/`vat_id`), `pagination.has_more` při překročení
     `limit`.
  5. `tools/call` neznámý nástroj → JSON-RPC `-32602`.
  6. `tools/call persons_search` bez `query` → `-32602`.
  7. Vykonávací chyba nástroje → `result.isError = true` (ne JSON-RPC error).
  8. Bez tokenu → HTTP 401.
  9. Tělo není JSON → `-32700`.

## Hotovo když

1. `POST /api/v1/_mcp` přijímá JSON-RPC 2.0 a vynucuje Bearer auth (401 bez).
2. `initialize` vrací handshake s negociovaným `protocolVersion`,
   `capabilities.tools` a `serverInfo`.
3. `notifications/initialized` → 202.
4. `tools/list` vrací `persons_search` s `description` a `inputSchema`.
5. `tools/call persons_search` hledá v `base_persons_persons` dle jména / IČO /
   DIČ / kódu, vrací MCP `content` (text) + `structuredContent` (obálka
   summary/items[ref]/pagination), DS-scoped.
6. Neznámý nástroj → `-32602`; vykonávací chyba → `result.isError`.
7. `McpTool`/`McpToolRegistry`/`McpInvocationContext` existují tak, že přidání
   dalšího nástroje = nová třída + jeden `register()`.
8. Testy procházejí (handshake, list, call happy/edge, auth, parse error).

## Doporučené pořadí implementace

1. Routa `/_mcp` + `dispatchMcp` skeleton + `McpController::rpc` vracející na
   `initialize` statický handshake → smoke přes curl s Bearer klíčem.
2. JSON-RPC parse/responder + `notifications/initialized` (202) + error kódy.
3. `McpTool`/`McpToolRegistry`/`McpInvocationContext` + prázdný `tools/list`.
4. `PersonsSearchTool` (query, stránkování, obálka) + registrace + `tools/call`.
5. Mapování obálky → `content`/`structuredContent`, `isError` cesta.
6. Testy + dotažení edge cases.

## Rozhodnutí k designu (potvrzená)

1. ✓ **MCP server nativně v PHP, in-process** ve stávající API appce. Sdílí
   auth, DS resolution, žádné druhé nasazení.
2. ✓ **Registr nástrojů ve fázi 1 in-code** (v `dispatchMcp`, analogie
   `dispatchExchange`). Module-driven registrace přes `module.jsonc`
   (`mcpTools: [{name, class}]` + `McpToolLoader`) je odložená na fázi, kdy
   nástroje pokryjí víc modulů — `McpTool` interface je navržený tak, aby ten
   přechod byl triviální (přesun místa registrace, ne změna nástrojů).
3. ✓ **Doménová obálka vlastněná nástrojem, MCP wire formát vlastněný
   controllerem.** Nástroj vrací `{summary, items[ref], pagination}`; controller
   mapuje na `content`+`structuredContent`. Jediné místo zná wire formát.
4. ✓ **`content` text vždy, `structuredContent` aditivně.** Robustní napříč
   spec revizemi.
5. ✓ **`persons_search` hledá lokálně**, výslovně odlišeno od `/persons/registry`
   (ARES) v popisu nástroje.
6. ✓ **Jméno nástroje s podtržítkem** (`persons_search`), tečka ne.
7. ✓ **`protocolVersion` negociován echem** — neukotvujeme spec revizi.
8. ✓ **Fáze 1 jen čtení, oba typy tokenu, DS-scoped, bez per-user filtru.**

## Otevřené body (k ověření, ne blokující)

- **Spec revize + `structuredContent`/`outputSchema`/`capabilities` tvar** —
  ověřit proti aktuální MCP spec při výběru SDK; návrh je psaný tak, aby drobné
  odchylky (zejm. `outputSchema`) šly doplnit bez přestavby.
- **Nominální `protocolVersion`** (fallback, když klient nepošle) — dosadit
  konstantu po ověření aktuální revize.
- **Verze v `serverInfo`** — vzít z existujícího zdroje verze appky
  (`ServerConfig`/`shpd-server version`).
