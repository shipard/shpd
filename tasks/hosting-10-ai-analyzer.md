# hosting-10 — Automatické napojení nových DS na AI analyzer

**Stav:** částečně — část A (nov_shipard: tabulka + analyzer_token + hosting-analyzer-key + lookup endpoint + --json + krok h + confirm, commity 1–3) hotová 2026-08-22; zbývá část B (ai_analyzer: sources-sync + systemd) a E2E na alfě (D7 backfill).

> PRD schváleno (D1–D8 potvrzeno v chatu, včetně D6a).
> Návaznost: `docs/hosting.md` (D3 pull agent, D4 mail-router vzor), task
> `hosting-04-mail-router.md` (přímý vzor — tento task je jeho vědomá kopie
> pro druhého konzumenta), ai_analyzer `tasks/phase2.md` (sources.d design,
> který s automatizací od začátku počítal).

## Kontext

Dnes: hosting-sync automaticky napojí nový DS na mail-router (krok f,
`mail_token`, lookup endpoint, `lookup-sync` na router stroji). Krok g
(`ai-analyzer-set-key`) konfiguruje **AI gateway backend na straně DS** —
s registrací DS jako zdroje pro analyzer daemon nemá nic společného.
`/etc/shipard-ai-analyzer/sources.d/` se plní výhradně ručně: operátor
spustí `shpd-ds ai-analyzer-setup`, token vloží do JSON souboru.

Cíl: nový DS založený z portálu začne být analyzován bez ručního zásahu.
Kompletní řetěz: agent mintuje token (krok h) → confirm (`analyzer_token`)
→ hosting broker (encrypted) → lookup endpoint → `sources-sync` na
analyzer stroji → spravovaný soubor `sources.d/hosting.json` → systemd
path unit restartuje daemon.

## Schválená rozhodnutí

| # | Rozhodnutí |
|---|---|
| D1 | Nová tabulka `hosting_core_ai_analyzers` (analog `hosting_core_mail_routers`, bez `domains`), klíče `shpd_hk_` přes sdílený `HostingApiKeyAuthenticator`, CLI `hosting-analyzer-key`. Analyzer obsluhuje všechny DS, bez FK vazby. |
| D2 | Sloupec `analyzer_token` (encrypted_text, sensitive) na `hosting_core_data_sources`, přesný vzor `mail_token`: šifruje `HostingDataSourceDocument::beforeSave`, sensitive ve formech, dešifrovaný odchází jen lookup endpointem. |
| D3 | Krok h agenta: `ai-analyzer-setup --json` (option se přidává, zrcadlo `mail-router-setup --json`), gate = aktivní `core.mail` **i** `core.ai`, retry po pádu s `--force`, token v confirm body jako `analyzer_token`. |
| D4 | `GET /_hosting/ai-analyzer/lookup` — body = přesně obsah jednoho sources.d souboru: JSON pole `{id, base_url, api_token}` řazené dle `id`, ETag/304. Bez `timeout_seconds` (default 60 v loaderu). |
| D5 | ai_analyzer: `sources-sync` oneshot CLI + systemd timer (2 min), kopie vzoru `lookup_sync.py` z mail_routeru (ETag cache, validace před zápisem, atomický `os.replace`, mode 0600, síťová chyba → stale + exit 0). |
| D6a | Reload daemonu: systemd path unit (`PathChanged=` na `sources.d/`) + oneshot restart service. Žádná změna kódu daemonu — restart je bezpečný (graceful drain, claims v SQLite, lease expiry requeuene). Dynamický reload (D6b) odloženo. |
| D7 | Backfill existujících DS ruční: `ai-analyzer-setup --force --json` → admin form → počkat na sync → smazat ruční soubor. Okno 401 mezi rotací a syncem je vědomé (alerter throttluje, pull model poštu neztrácí). |
| D8 | Commity: 3× nov_shipard + 1× ai_analyzer (viz níže). |

---

## Část A — nov_shipard

### A1. Tabulka `hosting_core_ai_analyzers`

`modules/hosting/core/tables/hosting_core_ai_analyzers.jsonc` —
**tableId 439** (438 zabírá `core_exchange_tag_rules`!). Sloupce dle
`hosting_core_mail_routers.jsonc` **bez `domains`**: `id`, `name`
(unq), `note`, `api_key_prefix`, `api_key_hash`, `last_seen`,
`created`, `modified`, `docState`, `docStateMain`; stejné docStates
(identity/status). K tomu `hosting_core_ai_analyzers.md` (vzor
`hosting_core_mail_routers.md`: účel, sloupce, vztah k
`hosting_core_data_sources` — lookup servíruje aktivní DS s vyplněným
`analyzer_token`, analyzer k DS není vázán řádkem).

### A2. Sloupec `analyzer_token`

`modules/hosting/core/tables/hosting_core_data_sources.jsonc`: nový
sloupec `analyzer_token`, definice 1:1 dle `mail_token` (typ,
sensitive flag). Doplnit do `.md` (řádek tabulky dle vzoru
`mail_token` na ř. 49).

`modules/hosting/core/src/HostingDataSourceDocument.php` (ř. ~198):
rozšířit seznam secret sloupců na
`['oidc_client_secret', 'mail_token', 'analyzer_token']` + upravit
docblock (ř. 18).

`modules/hosting/core/src/DataSourcesForm.php`: přidat
`analyzer_token` do formu i do `getEditableSensitiveColumns()`
(ř. ~50 a ~73) — ruční backfill přes admin form (D7).

### A3. CLI `hosting-analyzer-key`

`src/Command/DataSource/HostingAnalyzerKeyCommand.php` — kopie
`HostingRouterKeyCommand` s parametry: option `--analyzer` (row id),
`--generate`, `--revoke`; `TOKEN_PREFIX = 'shpd_hk_'` (stejné schéma).
Registrace commandu tam, kde je registrován `hosting-router-key`.

### A4. Lookup endpoint

`src/Api/Controller/HostingAiAnalyzerController.php` — vzor
`HostingMailController::lookup`:

- Guard na existenci `hosting_core_ai_analyzers` +
  `hosting_core_data_sources` (404 bez modulu).
- `HostingApiKeyAuthenticator('hosting_core_ai_analyzers',
  errorMessage: 'Analyzer key required', invalidMessage: 'Invalid
  analyzer key')` — jen jiná tabulka, žádná nová hash logika.
- `SELECT * FROM hosting_core_data_sources WHERE lifecycle = 'active'
  AND docState IN %in AND analyzer_token IS NOT NULL ORDER BY ds_id ASC`.
- Per řádek: decrypt `analyzer_token`; nedešifrovatelný → skip +
  `ErrorLogger::warn` (jeden vadný token nesmí shodit lookup).
- Položka: `{"id": ds_id, "base_url": url_app, "api_token": token}`.
  Žádné web_id aliasy (id musí být unikátní — loader analyzeru
  duplicity odmítá; ds_id vyhovuje `[a-z0-9_-]{1,64}` regexu
  `SourceConfig.id`).
- **Body = přesně formát sources.d souboru** (JSON pole, žádný
  success envelope), deterministické řazení → stabilní ETag
  (sha256 kanonizovaného obsahu), If-None-Match → 304
  (převzít `etagMatches` helper).

`src/Api/Router.php` (ř. ~243, vedle `/_hosting/mail/lookup`):
`/_hosting/ai-analyzer/lookup` → `Route('hostingAiAnalyzer', 'lookup')`
+ registrace controlleru dle vzoru `hostingMail`.

### A5. `ai-analyzer-setup --json`

`src/Command/DataSource/AiAnalyzerSetupCommand.php`: přidat option
`--json` — zrcadlo `MailRouterSetupCommand` (ř. 20, 39, 49–54):
stdout = jediný JSON objekt `{"api_key": ..., "user_id": N}`, lidské
hlášky v json módu na stderr. Bez `--json` beze změny chování.

### A6. Krok h agenta + confirm

`src/Core/Server/HostingSyncRunner.php`:

- Nová metoda `mintAnalyzerToken(string $dsDir, ?string &$analyzerToken): ?string`
  — kopie `mintMailToken` (ř. 366–400) s rozdíly: gate
  `isModuleActiveForDs($dsDir, 'core.mail') && isModuleActiveForDs($dsDir, 'core.ai')`
  (skip log: `ai-analyzer-setup skipped — core.mail or core.ai not
  active.`); argv `['ai-analyzer-setup', '--json']`, retry s
  `--force`; parsování `api_key` stejným způsobem jako krok f.
- Volání jako **krok h** za krokem g (`setupAiBackend`), aktualizovat
  docblock kroků (ř. ~23).
- Confirm body: `analyzer_token` vedle `mail_token` (ř. ~123–126,
  stejná podmínka — jen když token existuje).

`src/Api/Controller/HostingServerController.php` (confirm, ř. ~260):
vedle `mail_token` bloku identický blok pro `analyzer_token` —
šifrovat a ukládat NEPODMÍNĚNĚ i při re-confirmu (retry agenta token
rotuje, hosting musí držet poslední). Aktualizovat docblock (ř. 213–215).

### A7. Dokumentace

`docs/hosting.md`: krok h do výčtu kroků agenta (ř. ~91), sekce
lookup endpointů (ai-analyzer vedle mail), řádek do tabulky dopadů
(`ai_analyzer` | `sources-sync` plní `sources.d/hosting.json`),
backfill postup (D7). Tabulka `hosting_core_ai_analyzers` do přehledu
tabulek modulu.

---

## Část B — ai_analyzer

### B1. `sources-sync`

`ai_analyzer/sources_sync.py` — kopie `mail_router/lookup_sync.py`
s rozdíly:

- Validace payloadu: JSON parse → musí být **pole** → každou položku
  provalidovat existujícím Pydantic `SourceConfig` (přesně tentýž
  model jako loader — co projde syncem, projde i startem daemonu).
  Prázdné pole = validní (zapíše `[]` + warning).
- Cíl: `config.sources_sync.target_file` (default
  `<sources_dir>/hosting.json`), atomický zápis (tmp v témže
  adresáři + `os.replace`, mode 0600), ETag cache
  `<target_file>.etag`.
- Síťová/HTTP chyba → warning + exit 0 (stale sources dál fungují);
  nenulový exit jen na lokální I/O chybu. 304 → nezapisovat.
  Timeout 10 s.

### B2. Konfigurace + entry point

`ai_analyzer/config.py`: volitelná sekce

```yaml
sources_sync:
  url:      https://home.shpd.dev/api/v1/_hosting/ai-analyzer/lookup
  api_key:  shpd_hk_XXXX
  # target_file: /etc/shipard-ai-analyzer/sources.d/hosting.json  (default)
```

(chybí → `sources-sync` odmítne běžet s jasnou hláškou; daemon sekci
ignoruje). `ai_analyzer/cli.py`: `run_sources_sync` (vzor
`run_admin`) + console_script `shipard-ai-analyzer-sources-sync`
v `pyproject.toml`.

### B3. systemd — sync + reload (D6a)

`deploy/systemd/`:

- `shipard-ai-analyzer-sources-sync.service` (Type=oneshot, User=
  shipard-ai-analyzer) + `.timer` (OnBootSec=30s, OnUnitActiveSec=2min).
- `shipard-ai-analyzer-reload.path`: `PathChanged=/etc/shipard-ai-analyzer/sources.d`
  → `shipard-ai-analyzer-reload.service` (Type=oneshot,
  `ExecStart=systemctl restart shipard-ai-analyzer`, bez User= — unit
  vlastní root, žádné sudo v Pythonu). Restart je bezpečný: SIGTERM →
  stop event → pullery drain ve `finally`, claims v SQLite, in-flight
  requeuene lease expiry na shpd.

`install.sh`: instalace nových unit souborů. `deploy/config.example.yaml`
+ `deploy/README.md`: sekce sources_sync, popis spravovaného souboru
(`hosting.json` needitovat ručně — přepíše ho sync; ruční zdroje do
vlastních souborů; kolize `id` mezi ručním souborem a hosting.json
shodí start daemonu s pojmenováním obou souborů — to je záměr),
migrace/backfill postup (D7).

---

## Testy

**A (PHPUnit, `--filter 'HostingAiAnalyzer|HostingAnalyzerKey|AiAnalyzerSetup|HostingSync|HostingServer'`):**

- Lookup: auth matice (bez klíče 401, revokovaný 401, cizí prefix
  401); obsah jen active DS s tokenem; formát položek přesně
  `{id, base_url, api_token}`; deterministické řazení; ETag stabilní
  + 304 na If-None-Match; nedešifrovatelný token → skip, ostatní DS
  servírovány; DS bez modulu hosting → 404.
- `HostingAnalyzerKeyCommand`: generate/revoke, prefix+hash na řádku,
  token vytištěn jednou.
- `AiAnalyzerSetupCommand --json`: výstupní kontrakt (jediný JSON
  objekt na stdout, hlášky na stderr), bez `--json` beze změny.
- Runner krok h: gating (jen core.mail → skip; jen core.ai → skip;
  oba → běží); `--json` parsování; retry s `--force` (přes
  `runProcess` seam); token v confirm body.
- Confirm: `analyzer_token` uložen šifrovaně; bez tokenu sloupec
  nedotčen; re-confirm přepisuje.

**B (pytest, `tests/test_sources_sync.py`, bez sítě — mock HTTP):**

- 304 → nezapisuje; validní 200 (pole) → atomický zápis + ETag;
  nevalidní JSON / položka neprocházející `SourceConfig` → soubor
  nedotčen + warning; síťová chyba → stale + exit 0; prázdné pole →
  zapíše `[]` + warning; chybějící config sekce → jasná chyba.

## E2E na alfě (součást tasku, mutace po odsouhlasení)

1. Hosting: řádek analyzeru + `hosting-analyzer-key --generate`.
2. Analyzer stroj: config sekce + jeden běh
   `shipard-ai-analyzer-sources-sync` → `sources.d/hosting.json`
   existuje (zatím `[]` nebo backfillnuté DS); druhý běh → 304.
3. Backfill jednoho existujícího DS dle D7 → po syncu je v
   `hosting.json`, ruční soubor smazán, daemon se restartoval (path
   unit), `journalctl`: `sources_loaded` s novým počtem, puller běží.
4. Nový DS z portálu (install modul s core.mail + core.ai) → confirm
   s `analyzer_token` → do ~2 min v `hosting.json` → daemon
   restartován → zdroj pullován bez ručního zásahu.

## Commit strategie

nov_shipard:
1. `hosting: ai_analyzers table + hosting-analyzer-key + analyzer_token column (hosting-10 D1, D2)`
2. `hosting: /_hosting/ai-analyzer/lookup endpoint with ETag (hosting-10 D4)`
3. `hosting: ai-analyzer-setup --json + step h in hosting-sync + analyzer_token in confirm (hosting-10 D3)`

ai_analyzer:
4. `sources-sync: pull sources.d/hosting.json from hosting + systemd path-unit reload (hosting-10 D5, D6a)`

## Hotovo když

- [ ] Nový DS založený z portálu je analyzován bez ruční editace
      `sources.d/` (E2E krok 4)
- [ ] Lookup endpoint servíruje jen aktivní DS s tokenem, formát =
      přesně sources.d soubor, ETag/304 funguje
- [ ] `sources-sync` nikdy nepřepíše funkční soubor nevalidním
      obsahem; výpadek hostingu = stale sources, analýza běží dál
- [ ] Restart daemonu po změně `sources.d/` je automatický (path
      unit) a graceful (drain, žádná ztráta claims)
- [ ] `analyzer_token` na hostingu jen šifrovaně, ve formech
      sensitive, dešifrovaný odchází jen lookup endpointem přes https
- [ ] Ruční backfill (D7) zdokumentovaný a ověřený na alfě
- [ ] Testy obou repozitářů zelené, dokumentace aktualizovaná
