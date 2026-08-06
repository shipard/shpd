# Hosting — Task 4: AI gateway (Fáze 4)

**Stav:** hotovo (2026-08-06; commity `hosting: ai tokens + usage tables…`,
`hosting: AI gateway passthrough…`, `hosting: ai step in provisioning…` —
odchylky a zbývající ověření viz poznámky na konci)

> PRD pro jednu Claude Code session. Design: `docs/hosting.md`,
> rozhodnutí **D5, D6**, kontrakt §5.5. Fáze 4 z §8: nový DS má
> funkční AI od založení; hosting vidí spotřebu per DS. Vlastní klíč
> zůstává rovnocennou cestou (D6) — gateway je jen jiná data
> v `core_ai_backends`, na straně DS se nemění žádný kód.

## Kontext

Oba konzumenti (`AnthropicLlmClient` — chat/dashboard, streaming;
`ai_analyzer` — Python SDK, non-streaming) volají výhradně
`POST {base_url}/v1/messages` a autentizují se hlavičkou **`x-api-key`**.
Gateway = reverse proxy na hosting DS: ověří gateway token
(`x-api-key`), nahradí ho skutečným klíčem organizace, passthrough na
`api.anthropic.com` (včetně SSE), z odpovědi vytěží `usage` → metering.
Vědomý limit v1 (v designu): streamované spojení drží PHP-FPM worker
— pro desítky klientů OK, případné vydělení do daemonu nemění rozhraní.

**Vědomé volby (potvrzeno v chatu):**

1. **Klíč organizace v `secrets/`** hosting DS
   (`secrets/ai-gw-anthropic.key`, 0600), ne v DB ani settings —
   stejné zacházení jako privátní klíč OP. CLI `hosting-ai-gw-init`.
2. **Gateway tokeny**: prefix `shpd_gw_`, přichází v `x-api-key`
   (žádná změna klientů), validace prefix+hash (vzor `shpd_hk_`).
3. **Token se mintuje při vzniku požadavku na DS** a plaintext se
   drží šifrovaně na řádku tokenu (vzor mail_token brokera) — queue
   payload ho servíruje opakovaně (retry-stabilní), hash slouží
   runtime validaci.
4. **Metering tee**: chunky se přeposílají klientovi a zároveň se
   z nich extrahuje `usage` (SSE: `message_start` input +
   `message_delta` output; non-SSE: JSON body). Chybové odpovědi
   Anthropicu se přeposílají 1:1 a logují s `http_status` bez usage.
5. **`ai-analyzer-set-key` dostane `--base-url`** — agent i ruční
   backfill zapisují gateway backend stejným commandem (default
   backend, provider anthropic).

## Cíl

1. Tabulky `hosting_core_ai_tokens` + `hosting_core_ai_usage`
   (+ viewery), CLI `hosting-ai-gw-init` a `hosting-ai-token`.
2. Endpoint `POST /_hosting/ai-gw/v1/messages` — auth, passthrough
   (stream i non-stream), metering.
3. Krok v provisioningu: `ai` sekce v queue payloadu + zápis backend
   řádku na novém DS (`ai-analyzer-set-key --base-url`).
4. E2E: chat i analýza pošty na novém DS jedou přes gateway; spotřeba
   viditelná na hostingu.

## Před implementací přečti

- `docs/hosting.md` §5.5, §7; `docs/ai.md` (backendy, set-key)
- `src/Core/Ai/AnthropicLlmClient.php` — celý: přesný tvar požadavku
  (headers `x-api-key`, `anthropic-version`, `content-type`), SSE
  eventy (`message_start`/`message_delta`/`error`), curl streaming
  přes `CURLOPT_WRITEFUNCTION`
- `src/Api/Controller/ChatController.php` — SSE odpověď (hlavičky
  ř. ~299, `writeSse`/flush ř. ~484, vypnutí bufferingu) — zrcadli
  pro streamovaný passthrough
- `src/Api/HostingApiKeyAuthenticator.php` — zvaž rozšíření/obdobu
  pro `x-api-key` zdroj (jiná hlavička, jiný prefix)
- `src/Command/DataSource/AiAnalyzerSetKeyCommand.php` — celý (upsert
  default backendu, šifrování api_key)
- `modules/core/ai/tables/core_ai_backends.jsonc` — sloupce
  (`base_url`, `provider`)
- `src/Core/Server/HostingSyncRunner.php` — vzor kroku f.
  (gating přes `isModuleActiveForDs`, retry logika)
- `src/Api/Controller/HostingServerController.php` — queue payload,
  confirm handler
- `src/Command/DataSource/HostingOidcInitCommand.php` — vzor práce
  se `secrets/` souborem
- `ai_analyzer/providers/anthropic_provider.py` (repo `ai_analyzer`,
  jen ke čtení) — ověření, že SDK nepotřebuje nic navíc

## Změny po souborech

### Schéma (hosting DS)

**`hosting_core_ai_tokens.jsonc`** (nová, `adminOnly`, docStates
archive): `data_source` (int FK → hosting_core_data_sources),
`token_prefix` (varchar 12), `token_hash` (varchar 64, sensitive),
`token_encrypted` (`encrypted_text`, nullable, sensitive — plaintext
pro queue payload, vzor mail_token), `active` (bool, default true),
`note` (varchar 200, nullable), `last_used` (datetime, nullable —
update max. 1× za minutu, ne každý request), `created`. Unique
`token_prefix`. Viewer + form (vydání tokenu přes CLI, form jen
metadata/deaktivace).

**`hosting_core_ai_usage.jsonc`** (nová, `adminOnly`, bez docStates —
append-only log): `data_source` (FK), `model` (varchar 60),
`input_tokens` (int), `output_tokens` (int), `cache_creation_tokens`
(int, default 0), `cache_read_tokens` (int, default 0), `http_status`
(int), `stream` (bool), `duration_ms` (int, nullable), `created`.
Index `(data_source, created)`. Viewer (grid layout, footer sumy —
vzor JournalViewer) do `other.hosting`.

`tableId` 2× přes `next-table-id`; rebuild cfg + `ds-upgrade` gn5c.

### CLI (hosting DS)

**`HostingAiGwInitCommand`** (`hosting-ai-gw-init`): `--set-key`
(čte klíč z promptu/STDIN, ne z argv — nesmí do shell history) →
`secrets/ai-gw-anthropic.key` 0600; `--status` ukáže jen
existenci/mtime. Vzor `hosting-oidc-init`.

**`HostingAiTokenCommand`** (`hosting-ai-token`): `--ds <ndx>
--generate` → `shpd_gw_` + 43 random, uloží prefix+hash+encrypted,
vytiskne jednou; `--revoke <ndx tokenu>` → `active = false`. Pro
ruční backfill existujících DS.

### `src/Api/Controller/HostingAiGatewayController.php` (nový)

`POST /_hosting/ai-gw/v1/messages` — exempt (auth vlastní), gating:
chybí tabulka nebo org klíč soubor → 404. Kroky:

1. **Auth**: `x-api-key` header, prefix `shpd_gw_` → lookup
   prefix+`hash_equals`, `active`, DS `lifecycle = active`. Selhání →
   401 v Anthropic error formátu
   (`{"type":"error","error":{"type":"authentication_error",…}}`) —
   klienti mu rozumí. Úspěch → `last_used` (throttled).
2. **Request**: body limit 32 MB; forward headers pouze
   `content-type`, `anthropic-version`, `anthropic-beta`
   (z požadavku klienta); `x-api-key` = org klíč ze `secrets/`.
   Nikdy neforwardovat `authorization`/cookies. Jiná cesta pod
   `/_hosting/ai-gw/` → 404.
3. **Passthrough**: curl na `https://api.anthropic.com/v1/messages`,
   `CURLOPT_WRITEFUNCTION` → echo chunk + flush (SSE hlavičky
   a buffering dle ChatControlleru, HTTP status + `content-type`
   Anthropicu propagovat 1:1 — pozor, status je znám až z prvních
   hlaviček odpovědi: použij `CURLOPT_HEADERFUNCTION`). Timeout
   celkový 600 s, connect 10 s, `set_time_limit(0)`.
4. **Metering tee** (`src/Core/Hosting/GwUsageExtractor.php`, čistá
   třída): chunky se paralelně krmí extraktoru — SSE:
   `message_start` (model, `usage.input_tokens`,
   `cache_creation_input_tokens`, `cache_read_input_tokens`) +
   `message_delta` (`usage.output_tokens`); non-SSE: buffer celého
   JSON body (limit 10 MB pro parse). Po dokončení INSERT do
   `hosting_core_ai_usage` (i pro chybové odpovědi — usage nuly,
   `http_status`). Selhání meteringu nesmí shodit odpověď klientovi
   (log warning).

### Provisioning

**Queue payload** (`HostingServerController::queue`): sekce `ai`
jen když existuje org klíč soubor a DS požadavek má aktivní AI moduly
smysl řešit (gating dle install modulu není třeba — rozhodne agent):
`{base_url: "{portál base URL}/api/v1/_hosting/ai-gw", api_key:
"<plaintext z token_encrypted>"}`. Token pro DS se mintuje
v `beforeSave` požadavku (vzor `oidc_client_secret`): řádek
v `hosting_core_ai_tokens` (encrypted plaintext + hash). Base URL:
odvození z issuer settingu (D12 — stejný host, nahradit
`/_hosting/oidc` za `/_hosting/ai-gw`; ulož jako helper, ne duplikát
stringu).

**Agent** (`HostingSyncRunner`) — krok g. (za mail-router-setup):
payload má `ai` sekci **a** DS má aktivní `core.ai`
(`isModuleActiveForDs`) → `cd {dsDir} && shpd-ds ai-analyzer-set-key
--backend default --api-key {ai.api_key} --base-url {ai.base_url}`.
Idempotentní (set-key je upsert). Jinak skip. Pozn.: api_key
v argv subprocessu je lokální root kontext (žádný shell — argv pole),
konzistentní s tím, jak set-key funguje dnes; do logu agenta klíč
nepsat (maskovat).

**Confirm**: beze změny (token už hosting má). Po confirmu `ok`
možno `token_encrypted` ponechat (broker vzor jako mail_token —
konzistence, možnost re-provisioningu).

### `AiAnalyzerSetKeyCommand`

Option `--base-url` (nullable → sloupec `base_url`; prázdný string =
NULL = přímé Anthropic). Bez option beze změny.

### Dokumentace

`docs/hosting.md` §5.5 skutečný stav + status; `docs/ai.md` — sekce
„AI přes hosting gateway" (backend řádek, vlastní klíč jako
alternativa — D6); `docs/cli.md` (2 nové commandy, `--base-url`);
operations runbook: zřízení gateway (init → tokeny → backfill DS).

## Testy

- `GwUsageExtractorTest`: SSE fixture (message_start s cache poli +
  message_delta) → správná čísla; non-SSE JSON; error response →
  nuly + status; oříznutý stream → co je k dispozici.
- `HostingAiGatewayControllerTest` (HTTP seam mock): auth matice
  (chybějící/špatný/revokovaný token, neaktivní DS → 401 Anthropic
  formát); forward headers allowlist (authorization se nepropustí);
  org klíč chybí → 404; usage INSERT vč. chybové odpovědi; metering
  exception → odpověď OK + warning.
- Queue payload: `ai` sekce jen s org klíčem; beforeSave mintuje
  token (hash i encrypted); base_url helper.
- Runner krok g.: gating (payload bez `ai`, DS bez core.ai), maskování
  klíče v logu.
- `AiAnalyzerSetKeyCommand --base-url` upsert.
- PHPUnit `--filter 'HostingAiGateway|GwUsage|AiAnalyzerSetKey'`.

## E2E na dev (součást tasku)

1. gn5c: `hosting-ai-gw-init --set-key` (testovací klíč),
   `hosting-ai-token --ds <vlm9> --generate` (backfill vzor) →
   `ai-analyzer-set-key --base-url` na vlm9.
2. Chat na vlm9 přes gateway (streaming end-to-end), analýza pošty
   (`ai_analyzer` non-streaming) — obojí funkční, řádky
   v `hosting_core_ai_usage` sedí s usage z odpovědí.
3. Nový DS z portálu (install s core.ai) → backend řádek zapsán
   agentem, chat funguje bez ručního kroku.
4. Vlastní klíč (D6): na testovacím DS přepnout backend na přímý
   Anthropic (bez base_url) → funguje beze změny kódu.
5. Revokace tokenu → 401 v klientovi (rozumná chybová hláška chatu).

## Commit strategie

1. `hosting: ai tokens + usage tables, hosting-ai-gw-init, hosting-ai-token (D5)`
2. `hosting: AI gateway passthrough with usage metering (D5)`
3. `hosting: ai step in provisioning + ai-analyzer-set-key --base-url (D5, D6)`

## Hotovo když

- [x] Chat (SSE) i mail analýza (non-stream) na DS s gateway backendem
      fungují beze změny kódu na straně DS; vlastní klíč dál funguje
      (D6)
- [x] Nový DS z portálu má AI od založení (krok g. agenta)
- [x] Každý průchod gatewayí = řádek v `hosting_core_ai_usage`
      (vč. chybových, s http_status); footer sumy ve vieweru
- [x] Org klíč jen v `secrets/` (0600), gateway tokeny na hostingu
      prefix+hash (+ šifrovaný plaintext pro provisioning), 401
      v Anthropic error formátu
- [x] Forward headers allowlist — `authorization` a cookies se nikdy
      nepropustí; jiné cesty pod ai-gw → 404
- [x] Selhání meteringu neshodí odpověď; výpadek gateway nezasahuje
      DS s vlastním klíčem
- [x] Testy zelené, dokumentace + runbook aktualizované

## Poznámky k implementaci (odchylky od zadání)

- **Mint tokenu není v `beforeSave` požadavku, ale lazy v `buildQueueItem`**
  (odsouhlaseno v chatu): token žije v jiné tabulce a beforeSave nezná id
  nového řádku; lazy mint je retry-stabilní (existující token se dešifruje
  z `token_encrypted`) a pokrývá i požadavky vzniklé před zavedením
  gateway / org klíče. Selhání `ai` sekce provisioning neblokuje — DS
  vznikne bez AI, backfill přes `hosting-ai-token`.
- Formát tokenu centralizuje `AiGwToken` (`src/Core/Hosting/`); base URL
  gateway odvozuje `HostingUrls::aiGwBaseUrl()` z issueru (D12).
- Navíc proti zadání: vlastní rate-limit bucket `ai_gw` (300/min per
  token) — bez něj by všichni klienti jedné egress IP sdíleli anon
  60/min; runbook `docs/operations/ai-gateway.md`.
- **E2E na dev (2026-08-06, gn5c/vlm9):** non-stream i SSE passthrough
  přes curl, `AnthropicLlmClient::streamChat` (cesta chatu) i Python
  `anthropic` SDK (cesta analyzeru) end-to-end přes gateway; usage řádky
  sedí 1:1 s odpověďmi; revokace → 401 `authentication_error`
  (klient hlášku správně propaguje); D6 ověřeno na btpg (base_url NULL).
  **Zbývá na reálném serveru:** plný průchod agenta (nový DS z portálu
  s krokem g.) — dev stroj nemá `hosting` sekci v server.json; krok g.
  i queue `ai` sekce jsou pokryté unit testy (vč. maskování klíče).
