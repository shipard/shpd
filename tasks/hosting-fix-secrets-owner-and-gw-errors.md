# Hosting — fix: vlastník secrets souborů z CLI + tiché selhání AI gateway

**Stav:** hotovo

> Mini-task, jedna Claude Code session. Dva nálezy z adopce hostingu
> na alfě (2026-08-10): AI gateway po `hosting-ai-gw-init` spuštěném
> pod rootem tiše nefungovala — klíč organizace byl `root:root 600`,
> PHP-FPM běží jako `shipard`, čtení selhalo **bez jediného záznamu
> v logu, bez chybové odpovědi v chatu a bez usage řádku**.
> Diagnostika zabrala násobně víc času než oprava (`chown`).

## Kontext

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
