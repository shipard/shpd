# Stavy zdroje dat

Stavový model DS podle issue [shipard/shpd#56](https://github.com/shipard/shpd/issues/56)
(rozhodnutí D1–D10). Tento dokument popisuje **fázi 1**: stavový soubor,
HTTP vynucení, cron gating a CLI. Co je fáze 2 a 3, je v závěru.

## 1. Model — dvě osy

**Lifecycle stav** (`state`):

| Stav | Význam |
|------|--------|
| `active` | běžný provoz |
| `read_only` | klient „jen kouká" — čtení a reporty fungují, zápisy ne. **Fáze 1 HTTP nevynucuje** (chová se jako `active`), cron už ho respektuje |
| `suspended` | zavřeno (neplatící klient, ruční zásah) — HTTP 503, cron nic |
| `pending_deletion` | čeká na fyzické smazání po `deleteAfter` — HTTP 503, cron nic; mazací job je fáze 3 |

**Maintenance overlay** (`maintenance {reason, since}`) — dočasné zavření
nezávislé na lifecycle stavu: `reason` ∈ `import` | `restore` | `migration`
| `manual`. Aktivní maintenance **má přednost**: DS se navenek chová jako
`suspended`, ať je lifecycle stav jakýkoli. Po `--off` se vrátí přesně do
uloženého lifecycle stavu — DS v `read_only` se po přesunu na jiný server
vrátí do `read_only`, ne do `active` (D1).

**Efektivní stav** = `maintenance aktivní ? suspended : state`. Všechny
subsystémy rozhodují podle efektivního stavu.

## 2. Chování per subsystém (fáze 1)

| Efektivní stav | HTTP (vč. `/_mail/incoming`) | cron per-DS joby | cron server-level joby |
|---|---|---|---|
| `active` | běží | všechny | běží |
| `read_only` | běží beze změny (fáze 2: 403 `DS_READ_ONLY` na mutacích) | jen `mail-idempotency-prune`, `alerts-prune` | běží |
| `suspended` | **503** `DS_UNAVAILABLE` + `Retry-After: 300` | žádné | běží |
| `pending_deletion` | **503** `DS_UNAVAILABLE` + `Retry-After: 300` | žádné | běží |

Proč 503 a ne 404 (D3): mail-router a monitoring by 404 vyhodnotily jako
permanentní chybu a poštu zahodily; 503 znamená „zkus později" — router
zprávy frontuje a po otevření DS doručí. Zavřený DS **není chyba** —
odmítnutý request se loguje na úrovni `info`, ne `error`.

Registr cron jobů a jejich povolených stavů je
`CronCommand::JOB_ALLOWED_STATES` — job bez záznamu běží **jen** v
`active` (fail-closed). Přeskočený DS se neloguje per běh (minute slot by
spamoval); počet jde do heartbeat souboru slotu (`skippedDataSources`,
`corruptedStateFiles`), viz [cli.md](cli.md) → `cron --slot`.

## 3. Zdroj pravdy — `config/state.json`

Soubor v adresáři DS, **ne** DB (D2): musí fungovat, když DB neexistuje
nebo je uprostřed restore. Čte se v `DataSourceResolver` **před**
připojením k databázi a v `CronCommand` (jen tento soubor, `main.json`
se nedotýká).

Formát v1:

```json
{
  "version": 1,
  "state": "active",
  "maintenance": { "reason": "import", "since": "2026-09-01T10:00:00Z" },
  "deleteAfter": "2026-10-01T00:00:00Z",
  "changedBy": "cli",
  "changed": "2026-09-01T10:00:00Z"
}
```

`maintenance` a `deleteAfter` volitelné. `deleteAfter` má smysl jen u
`pending_deletion` — přechod do jiného stavu ho zahodí. Časy UTC ISO 8601.
`changedBy` je ve fázi 1 vždy `cli`; hosting-sync (fáze 3) zapíše vlastní
identifikátor.

### Sémantika čtení (`DataSourceState::load`)

| Situace | Výsledek |
|---|---|
| soubor neexistuje | `active` bez overlay — zpětná kompatibilita, existující DS nic nemění |
| validní v1 | přečtený stav |
| nečitelný soubor, nevalidní JSON, neznámá `version`, neznámý `state`, neznámý `reason` | **fail-closed**: efektivní `suspended` + `error` do logu |

Fail-closed je záměr: poškozený stavový soubor nesmí tiše otevřít DS,
který měl být zavřený. `ds-state` (show) poškozený soubor ohlásí a poradí
opravu (`set <state>` soubor přepíše).

### Zápis (`DataSourceState::save`)

Atomicky tmp + rename (vzor `DomainsFile`), každý krok kontrolovaný,
selhání = výjimka — soubor čte resolver při každém requestu a cron každou
minutu, roztržený zápis by DS fail-closed zavřel. Nastavuje `changed`
a `changedBy`.

## 4. Ovládání

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds ds-state                                        # show
shpd-ds ds-state set read_only
shpd-ds ds-state maintenance --on --reason=import
shpd-ds ds-state maintenance --off
shpd-ds ds-state set pending_deletion --delete-after=2026-10-01 [--yes]
```

Reference: [cli.md](cli.md) → `ds-state`. Na hostovaných DS drží desired
state hosting (D6) — lokální CLI je pro nehostované DS a nouzové zásahy;
orchestrace přes `hosting-sync` je fáze 3.

**Typické scénáře**

- **Import ze starého Shipardu:** hned po `ds-create` zapnout
  `maintenance --on --reason=import`; API, cron i pošta jsou po celou dobu
  importu mrtvé (503, router frontuje). Aktivace `--off` je explicitní
  krok na konci.
- **Přesun DS na jiný server:** na zdroji `maintenance --on
  --reason=migration` **před** dumpem; `state.json` cestuje v tarballu,
  DS je na cíli automaticky zavřený, dokud po sanity checku operátor
  neřekne `--off`. Viz [migration-guide.md](migration-guide.md).
- **Ukončení činnosti klienta „chce jen vidět účetnictví":** `set read_only`
  (plné vynucení fáze 2).

## 5. Třídy

| Třída | Účel |
|---|---|
| `Core\Config\DataSourceState` | readonly stav; `load()` / `save()`, gettery `getEffectiveState()`, `blocksHttp()`, mutátory `withState()` / `withMaintenance()` / `withoutMaintenance()` / `withDeleteAfter()` |
| `Api\Exception\DataSourceUnavailableException` | hází resolver pro zavřený DS; nese `dsId`, `effectiveState`, `maintenanceReason`; `index.php` → 503 |
| `Command\Server\CronCommand::JOB_ALLOWED_STATES` | registr job → povolené lifecycle stavy |
| `Command\DataSource\DsStateCommand` | `shpd-ds ds-state` |

## 6. Fázování

- **Fáze 1 (tento dokument):** `state.json`, 503 za resolverem, cron
  gating, CLI. Task `tasks/ds-state-phase1.md`.
- **Fáze 2:** per-route klasifikace (allowlist flag na `Route`) a 403
  `DS_READ_ONLY` na mutacích, MCP read-tier, vypnutí chatu, statická
  stavová HTML stránka pro browser (fáze 1 vrací JSON 503 i pro `/_app`),
  stav v `/_meta` + frontend banner, alert check „DS v maintenance déle
  než N dní" (D8, data z heartbeatu už jsou).
- **Fáze 3:** hosting — desired state v `hosting_core_data_sources`,
  aplikace přes `hosting-sync`, rekonciliace actual state zpět,
  `pending_deletion` mazací job se zálohou (D10).
