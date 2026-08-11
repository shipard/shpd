# Hosting — fix: vlastník secrets souborů z CLI + tiché selhání AI gateway

**Stav:** hotovo

> Mini-task, jedna Claude Code session. Dva nálezy z adopce hostingu
> na alfě (2026-08-10): AI gateway po `hosting-ai-gw-init` spuštěném
> pod rootem tiše nefungovala — klíč organizace byl `root:root 600`,
> PHP-FPM běží jako `shipard`, čtení selhalo **bez jediného záznamu
> v logu, bez chybové odpovědi v chatu a bez usage řádku**.
> Diagnostika zabrala násobně víc času než oprava (`chown`).

## Kontext

0. **Nález č. 3 (fáze 7 adopce):** `DomainAddCommand::saveDomainsFile`
   nekontroluje výsledek `file_put_contents`/`rename`. Agent
   `hosting-sync` běží z cronu jako `shipard`, `/etc/shipard` je
   `root:shipard 750` → zápis selže PHP warningem, command vytiskne
   „Added" a vrátí SUCCESS, confirm projde — a nový DS je nedostupný
   (UNKNOWN_HOST). Zároveň platí dluh z hosting-03: command ignoruje
   `domainsFile`/`dataSources` override ze server.json, který resolver
   respektuje.

1. CLI commandy zapisující do `secrets/` (`hosting-oidc-init`,
   `hosting-ai-gw-init`) vytvoří soubor pod uživatelem, který command
   spustil — typicky root. Runtime (PHP-FPM) běží jako vlastník DS
   adresáře. Gating gateway kontroluje `file_exists` (projde i pro
   nečitelný soubor), čtení pak selže.
2. Selhání čtení klíče v gateway se nepropíše nikam: žádný
   `ErrorLogger`, klientovi se nevrátí parsovatelná chyba (chat
   nezaloží ani assistant/error zprávu — ověř přesné chování a oprav
   i chat stranu, pokud polyká chybové odpovědi gateway).

## Cíl

1. Secrets soubory z CLI mají po zápisu správného vlastníka; nesoulad
   je hlasitý (warning při zápisu, nález v doctoru).
2. Nečitelný/nevalidní klíč organizace v gateway = zalogovaný error
   + chybová odpověď v Anthropic formátu; chat ji zobrazí uživateli.

## Před implementací přečti

- `src/Command/DataSource/HostingOidcInitCommand.php`,
  `HostingAiGwInitCommand.php` — zápis do secrets
- `src/Core/Security/DsSecretCipher.php` — konvence práce se secrets
- `src/Api/Controller/HostingAiGatewayController.php` — gating +
  čtení org klíče (místo tichého selhání)
- `src/Core/Ai/AnthropicLlmClient.php` + `src/Api/Controller/ChatController.php`
  — jak klient/chat zachází s chybovou odpovědí (ne-2xx, ne-SSE) —
  reprodukuj scénář „gateway vrátí 500/prázdno" a ověř, co uvidí
  uživatel; oprav, aby chyba byla viditelná (SSE error event / error
  zpráva v konverzaci)
- `src/Command/Server/DoctorCommand.php` (příp. kde doctor žije) —
  vzor checků

## Změny

### Vlastník secrets (sdílený helper)

`src/Core/Security/SecretsFileWriter.php` (nebo rozšíření stávající
třídy): zápis souboru do `secrets/` DS — 0600, a **vlastník = vlastník
DS root adresáře** (`fileowner(data-sources/<id>)`): běží-li proces
jako root → `chown` na něj; jinak při nesouladu vytiskni warning
s přesným `chown` příkazem. Oba init commandy přepnout na helper.

### Doctor check

Nový check per DS: každý soubor v `secrets/` musí mít vlastníka
shodného s DS root adresářem a mode ≤ 0600. Nález = warning s fix
příkazem.

### DomainAddCommand (nález č. 3)

- `saveDomainsFile`: kontrolovat návratové hodnoty `mkdir`,
  `file_put_contents` i `rename` — selhání = výjimka → Command::FAILURE
  s jasnou hláškou (cesta + uživatel + hint na práva). Agent pak
  korektně confirmne `failed` s touto zprávou.
- Respektovat `domainsFile` a `dataSources` override ze
  `ServerConfig` (stejně jako HTTP resolver) — konstanty jen jako
  default. Dluh evidovaný od hosting-03.
- Doctor check (server-level): soubor domén (efektivní cesta po
  override) musí být zapisovatelný uživatelem cron agenta — jinak
  warning s doporučením: pro agent-managed servery přesunout
  domains.json přes override do app-writable cesty; `/etc/shipard`
  zůstává root-managed.
- `docs/operations/hosting-adopt-existing.md` + `production.md`:
  poznámka o umístění domains.json na serverech s provisioning
  agentem.

### Gateway error handling

V `HostingAiGatewayController`: čtení org klíče do try/catch —
selhání → `ErrorLogger::error('hosting ai-gw: org klíč nelze číst', …)`
+ HTTP 500 s tělem
`{"type":"error","error":{"type":"api_error","message":"gateway key unavailable"}}`.
Gating ponechat na `file_exists` (404 = nezřízeno) — nečitelnost je
error, ne 404.

### Chat strana

Dle zjištění z reprodukce: ne-2xx/ne-SSE odpověď backendu musí
skončit viditelnou chybou v chatu (a error stavem zprávy, pokud
existuje), ne tichým koncem bez assistant zprávy.

## Testy

- Regresní test k `HostingDataSourceDocument::beforeSave` (oprava pasti
  z fáze 7 adopce, už zapatchováno přímo): přechod existujícího řádku
  do `request` s prázdným `ds_id` → `prepareRequest` proběhne;
  adoptovaný řádek s vyplněným `ds_id` přepnutý do `request` →
  generování ds_id/secretu se NEspustí (jen případná doplnění prázdných
  URL dle stávající logiky prepareRequest).

- SecretsFileWriter: odvození vlastníka, warning větev (non-root);
  chown větev aspoň smoke pod možnostmi test prostředí.
- Doctor: nesoulad vlastníka → nález; soulad → čisto.
- Gateway: nečitelný klíč (fixture soubor 000/root nelze — simuluj
  seam čtení) → 500 v Anthropic formátu + error log; usage řádek se
  nezapisuje (nedošlo k upstreamu) — potvrď záměr v kódu komentářem.
- Chat: mock backendu vracející 500 Anthropic error → uživatel vidí
  chybu (assert na SSE výstup / uloženou zprávu).

## Commit strategie

1. `security: secrets file owner helper + doctor check`
2. `hosting: ai-gw loud failure on unreadable org key + chat error surfacing`

## Hotovo když

- [x] `hosting-ai-gw-init`/`hosting-oidc-init` pod rootem zanechají
      soubor čitelný runtime uživatelem; pod jiným uživatelem varují
- [x] `shpd-server doctor` odhalí špatného vlastníka v `secrets/`
- [x] Nečitelný org klíč: error v logu, 500 v Anthropic formátu,
      viditelná chyba v chatu — nikdy tichý konec
- [x] Testy zelené

## Poznámky k implementaci (2026-08-10)

- Skutečná příčina tichého 404: `root:root 0600` projde perms checkem
  v `AiGwKeyStore::read()` (mode JE 0600) a selhání `file_get_contents`
  se hodilo jako `AiGwKeyMissingException` → 404. Nově
  `AiGwKeyUnreadableException` (+ `OpKeyUnreadableException` symetricky)
  → error log + 500 v Anthropic formátu; Missing zůstává 404.
- Vlastníka v `secrets/` doctor hlídal už dřív (recurse scan
  `PermissionSpec`); doplněn jen `contentsMaxMode 0600` na soubory.
- `SecretsFileWriter` sjednotil i zápis `secrets.key`
  (`DsSecretCipher::generateKey` — ds-create pod rootem měl tutéž díru).
- Chat chybu nepolykal backend (SSE `error` event se posílal), ale
  frontend store: `finalizeTurn` → `openConversation` nulovala `error`
  hned po nastavení. Druhá díra: stream ukončený bez terminálního frame
  nevolal žádný callback (věčný spinner) — doplněn fallback
  `STREAM_ERROR`. Chybová zpráva je transientní (nepersistuje se do
  konverzace — error kind zprávy neexistuje, vědomě mimo rozsah).
- Ruční smoke chatu v prohlížeči proti rozbité gateway zbývá na alfě.

## Poznámky k implementaci — nálezy z reálného testování (2026-08-11)

- **Nález č. 3 (`DomainAddCommand`)**: zápis sjednocen do
  `Core/Server/DomainsFile` (load/save s kontrolou každého kroku,
  hláška s cestou + uživatelem + hintem na `domainsFile` override);
  stejné hardening dostal i `domain-remove` (identická tichá díra)
  a `domain-list` sdílí load. Overrides: nový
  `ServerConfig::getDataSourcesDir()` (klíč `dataSources`),
  `domain-*` commandy čtou efektivní cesty ze server.json
  a `public/index.php` předává resolveru nově i `dataSources`
  (dřív jen `domainsFile` — tvrzení „resolver respektuje" platilo
  jen napůl).
- Doctor: check `Hosting domains file` — jen pro servery se sekcí
  `hosting`; ověřuje zápis na **adresář** mapy (atomický tmp + rename
  zápis na souboru nestačí) výpočtem z owner/group/mode, warn-only.
- Docs: poznámka o umístění domains.json na agent-managed serverech
  ve `hosting-adopt-existing.md` (fáze 7) a `production.md` (§9).
- **Regresní testy k fixu `6d7ea84`** (beforeSave přechod do request):
  fix byl zapatchovaný bez testů a rozbil
  `testUpdateDoesNotGenerate` (fixture bez `ds_id` v originalData
  nově legitimně generuje) — test opraven (ds_id vyplněné) + dva nové
  testy: prázdné ds_id generuje, adoptovaný řádek s ds_id nedotčen.
