# Stavy zdroje dat — fáze 1: `state.json` + HTTP enforcement + cron gating + CLI

**Stav:** hotovo

## Kontext / Cíl

Fáze 1 stavového modelu DS dle issue **shipard/shpd#56** (rozhodnutí D1–D10
v textu issue). Cíl fáze: zdroj dat lze zavřít (maintenance / suspended /
pending_deletion) tak, že HTTP vrací 503, cron joby se nepouštějí a stav se
ovládá CLI příkazem. Read-only vynucení na úrovni rout, stavová HTML stránka
a hosting orchestrace jsou fáze 2 a 3.

Shrnutí relevantních rozhodnutí:

- **D1** — dvě osy: lifecycle `active` / `read_only` / `suspended` /
  `pending_deletion` + maintenance overlay (`reason`, `since`). Aktivní
  maintenance má přednost — DS se navenek chová jako `suspended`.
- **D2** — zdroj pravdy `config/state.json`; chybějící soubor = `active`;
  čte se před připojením k DB; cron čte jen tento soubor.
- **D3** — `suspended` / maintenance / `pending_deletion` → **503 +
  `Retry-After`**; nikdy 404 (mail-router by zprávy zahodil, 503 frontuje).
- **D9** — CLI `ds-state` žije v `shpd-ds`.

## Před implementací přečti

- issue #56 — kompletní rozhodnutí a fázování
- `src/Api/DataSourceResolver.php` — resolve vytváří `DataSourceConfig`
  **i** `DataSourceConnection`; kontrola stavu patří mezi ně
- `public/index.php` — pipeline kroky 3–10, catch bloky
  (`UnknownHostException` → vzor pro novou výjimku), krok 2.5 dev dashboard
  (stavem se neřídí)
- `src/Command/Server/CronCommand.php` — `SLOT_JOBS`, smyčka přes DS adresáře
- `src/Core/Server/DomainsFile.php` — vzor atomického zápisu (tmp + rename)
  a „hlasitého" selhání zápisu
- `src/Command/DataSource/DsSettingCommand.php` — vzor jednoduchého
  `shpd-ds` příkazu (cwd = adresář DS)
- `docs/cli.md` §cron — sloty a chování dispatcheru

## Krok 1 — `DataSourceState` (čtení + zápis `config/state.json`)

Nová třída `src/Core/Config/DataSourceState.php`:

- Formát v1 (viz issue #56): `version`, `state`, volitelně `maintenance
  {reason, since}`, `deleteAfter`, `changedBy`, `changed`.
- `DataSourceState::load(string $dataSourceDir): self` —
  - soubor neexistuje → `active` bez overlay (zpětná kompatibilita, žádný
    zásah do existujících DS);
  - **nečitelný soubor / nevalidní JSON / neznámý `state` / neznámá
    `version` → fail-closed**: chová se jako `suspended` + `ErrorLogger`
    error. Poškozený stavový soubor nesmí tiše otevřít DS, který měl být
    zavřený.
- `reason` enum: `import` | `restore` | `migration` | `manual`.
- Odvozené gettery: `getState()`, `isMaintenanceActive()`,
  `getEffectiveState()` (maintenance → `suspended`, jinak lifecycle),
  `blocksHttp()` (efektivní stav ∈ {suspended, pending_deletion}),
  `getMaintenanceReason()`, `getMaintenanceSince()`, `getDeleteAfter()`.
- `save(string $dataSourceDir): void` — atomicky tmp + rename do
  `config/state.json` (vzor `DomainsFile`), selhání zápisu = výjimka
  (hlasitě, ne tichý pád). Nastavuje `changed` (UTC ISO 8601) a `changedBy`
  (parametr, fáze 1 vždy `cli`).
- Mutační factory/settery pro CLI: `withState()`, `withMaintenance(reason)`,
  `withoutMaintenance()`.

Unit testy (`tests/Unit/Core/Config/DataSourceStateTest.php`): chybějící
soubor → active; validní soubor všechny stavy; maintenance overlay →
efektivní suspended; nevalidní JSON → fail-closed suspended; neznámý stav →
fail-closed; round-trip save/load; atomicita (tmp soubor po save neexistuje).

**Commit 1:** `DataSourceState: čtení a zápis config/state.json (fail-closed)`

## Krok 2 — HTTP enforcement v resolveru

- Nová výjimka `src/Api/Exception/DataSourceUnavailableException.php` —
  nese `dsId`, efektivní stav a `maintenanceReason` (pro fázi 2 / stavovou
  stránku; teď jen do logu a error details).
- `DataSourceResolver`: v `resolveProductionMode` i `resolveDevMode` po
  zjištění `dsId` a před `createConnection()` načíst `DataSourceState`;
  `blocksHttp()` → throw `DataSourceUnavailableException`. **Před** vytvořením
  connection — v maintenance může být DB rozbitá nebo neexistovat.
- `public/index.php`: nový catch blok (před `UnknownHostException`):

  ```php
  } catch (DataSourceUnavailableException $e) {
      Response::error('DS_UNAVAILABLE', 'Data source is temporarily unavailable', 503)
          ->withHeader('Retry-After', '300')
  ```

  + CORS applyTo, jako ostatní catch bloky. Do error details efektivní stav
  (`suspended` / `pending_deletion`) — klient (frontend, mail-router
  diagnostika) pozná proč. Maintenance reason do details ne (interní info),
  jen do logu (info level, ne error — zavřený DS není chyba).
- `read_only` ve fázi 1 HTTP **nevynucuje** (chová se jako `active`) —
  klasifikace rout je fáze 2. Stav ale lze nastavit a cron ho respektuje
  (krok 3).
- `/_mail/incoming` jde stejnou pipeline → 503 dostává automaticky,
  mail-router frontuje (D3/D4). Žádný speciální kód v `MailController`.

Integration test (`tests/Integration/`): DS se `state.json` suspended →
libovolný request 503 + `Retry-After`; maintenance overlay nad `active` →
503; `read_only` → request projde; bez `state.json` → projde.

**Commit 2:** `HTTP: 503 DS_UNAVAILABLE pro suspended/maintenance/pending_deletion`

## Krok 3 — cron gating

`src/Command/Server/CronCommand.php`:

- `SLOT_JOBS` rozšířit o deklaraci stavů, ve kterých job běží — struktura
  `příkaz => allowed states` (výhledově se přesune do module.jsonc spolu
  s registrem jobů, teď zůstává v konstantě):

  | Job | active | read_only |
  |---|---|---|
  | `mail-outbox-run` | ✓ | ✗ |
  | `mail-analysis-reap` | ✓ | ✗ |
  | `mail-preprocess --sweep` | ✓ | ✗ |
  | `alerts-run` | ✓ | ✗ |
  | `mail-idempotency-prune` | ✓ | ✓ |
  | `alerts-prune` | ✓ | ✓ |

  V `suspended` / maintenance / `pending_deletion` neběží nic.
- Ve smyčce přes DS adresáře načíst `DataSourceState::load()` (čte **jen**
  `state.json`, `main.json` se nedotýká) a filtrovat joby podle efektivního
  stavu. Přeskočený DS: tichý skip (žádný log per běh — minute slot by
  spamoval), počet přeskočených DS do heartbeat JSON (nové pole
  `skippedDataSources`) — čte ho `doctor`, alert check na „zapomenutý"
  maintenance (D8) přijde ve fázi 2.
- Server-level joby (`SERVER_SLOT_JOBS`) se stavem DS neřídí — běží vždy.

Test: rozšířit existující testy `CronCommand` (jsou-li) / nový unit test
s temp adresářovou strukturou — suspended DS joby nedostane, read_only
dostane jen prune joby.

**Commit 3:** `cron: gating jobů podle stavu DS (state.json)`

## Krok 4 — CLI `shpd-ds ds-state`

`src/Command/DataSource/DsStateCommand.php`, registrace vedle ostatních
DS příkazů (`DsApplicationFactory`), cwd = adresář DS (vzor
`DsSettingCommand`):

```bash
shpd-ds ds-state                                  # show: stav + overlay + deleteAfter, lidsky čitelné
shpd-ds ds-state set active|read_only|suspended|pending_deletion
shpd-ds ds-state maintenance --on --reason=import|restore|migration|manual
shpd-ds ds-state maintenance --off
```

- `set read_only` vypíše upozornění, že HTTP vynucení přijde až ve fázi 2
  (cron už ho respektuje).
- `set pending_deletion` vyžaduje `--delete-after=<ISO datum>` (mazací job
  je fáze 3, ale hodnota se ukládá už teď) + interaktivní potvrzení
  (vzor `dataset-seed` reset confirm); `--yes` pro skripty.
- `maintenance --on` bez `--reason` → `manual`.
- Zápis přes `DataSourceState::save()` (`changedBy: cli`), po zápisu vypíše
  výsledný efektivní stav.
- Exit kódy: SUCCESS; FAILURE při nevalidním stavu/reasonu nebo selhání
  zápisu.

**Commit 4:** `CLI: shpd-ds ds-state (show/set/maintenance)`

## Krok 5 — dokumentace

- Nový `docs/ds-state.md` — stavový model (dvě osy, tabulka efektivního
  chování per subsystém), formát `state.json` v1, fail-closed sémantika,
  odkaz na issue #56 a fázování; řádek do tabulky v `docs/README.md`.
- `docs/cli.md` — sekce `ds-state` + poznámka u `cron --slot` o gatingu
  a novém heartbeat poli.
- `docs/architecture.md` — do pipeline requestu vložit krok kontroly stavu
  (mezi resolve DS a TableLoader) + `DataSourceUnavailableException`
  do přehledu.
- `docs/migration-guide.md` — poznámka: `state.json` cestuje v tarballu;
  před dumpem na zdrojovém serveru přepnout DS do maintenance
  (`ds-state maintenance --on --reason=migration`), na cílovém po sanity
  checku `--off`.

**Commit 5:** `docs: stavy zdroje dat (ds-state.md, cli, architecture, migration-guide)`

## Ověření (2. 9. 2026)

Implementováno v 5 commitech dle kroků výše. Ručně ověřeno na dev DS
(dočasný `state.json`, po testu smazán): `_meta` i `POST /_mail/incoming`
vrací 503 + `Retry-After: 300`, po `maintenance --off` DS resolvuje
normálně. Odchylky od zadání:

- HTTP scénáře jsou unit testy v `DataSourceResolverTest` (existující
  `TestableDataSourceResolver` bez DB), ne integrační suite.
- `SLOT_JOBS` zůstává seznam; povolené stavy nese samostatná konstanta
  `JOB_ALLOWED_STATES` (job bez záznamu = jen `active`). Heartbeat nese
  navíc `corruptedStateFiles`.
- Alfa: `ds-upgrade` není potřeba (žádná změna schématu), stačí deploy
  kódu. Nikde zatím žádný `state.json` neexistuje = všechny DS `active`.

## Mimo rozsah fáze 1 (vědomě)

- Read-only vynucení na routách (allowlist flag na `Route`, 403
  `DS_READ_ONLY`), MCP read-tier omezení, vypnutí chatu — fáze 2.
- Stavová HTML stránka pro browser (fáze 1 vrací JSON 503 i pro `/_app`)
  — fáze 2.
- Stav v `/_meta` + frontend banner — fáze 2.
- Alert check „DS v maintenance déle než N dní" (D8) — fáze 2 (heartbeat
  pole z kroku 3 už data připravuje).
- Hosting: desired state, rozšíření `lifecycle`, `hosting-sync` aplikace,
  rekonciliace, mazací job + záloha (D10) — fáze 3.
- Integrace do `ds-create` / import tooling (založení migrovaného DS rovnou
  v maintenance `reason=import`) — přijde s import nástroji; do té doby
  ručně `ds-state maintenance --on --reason=import` hned po `ds-create`.
- Trial + zpětný kanál DS → hosting — vědomě odloženo (issue #56).

## Hotovo když

- [x] `DataSourceState` čte a zapisuje `state.json` v1; chybějící soubor =
      active, poškozený = fail-closed suspended + error log; unit testy zelené
- [x] Request na DS v suspended / maintenance / pending_deletion vrací 503
      `DS_UNAVAILABLE` + `Retry-After`, bez pokusu o DB připojení;
      `read_only` a chybějící `state.json` procházejí beze změny
- [x] `/_mail/incoming` na zavřeném DS vrací 503 (ověřit ručně nebo
      integračním testem — mail-router zprávu frontuje, nezahodí)
- [x] Cron přeskakuje joby dle tabulky stavů; heartbeat nese
      `skippedDataSources`; existující chování pro DS bez `state.json`
      beze změny
- [x] `shpd-ds ds-state` show/set/maintenance funguje včetně validací
      a potvrzení u `pending_deletion`
- [x] Dokumentace z kroku 5 aktualizovaná
- [x] PHPUnit (narrow `--filter`, `timeout_sec: 120`) zelené; commit per
      logický krok (5 commitů dle výše)
