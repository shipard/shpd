# Tabulka: Stavy kontrol upozornění (core_alerts_check_states)

Interní stavová tabulka subsystému Alerts. Jeden řádek per zaregistrovaný
check (= `alertChecks[].id` z `module.jsonc` některého modulu).

Tabulku **spravuje výhradně `AlertReconciler`** — uživatel ji nemá co
prohlížet samostatně, proto má `hideFromNavigation: true`. Veřejné view dat
z této tabulky poskytuje endpoint `GET /_alerts/registry`, který je joinuje
s `AlertCheckDefinition` z registru a vrací jednu konsolidovanou strukturu.

## Lifecycle řádku

Řádek vzniká **lazily** — při prvním běhu daného `check_id` reconciler
INSERTne nový řádek; další běhy už jen UPDATE.

Pokud se z modulu odebere `alertChecks[].id`, řádek tu zůstane viset (sirotek).
Tomu vadí jen kosmeticky — `getDueCheckIds()` ho neuvidí (chybí v registru),
takže se nikdy nespustí. `alerts-prune` ho v MVP nemaže; je to operations TODO.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `check_id` | varchar(200), NOT NULL, UNIQUE | Stejný formát jako v `core_alerts_alerts.check_id`. |
| `enabled` | boolean, default true | Manuální vypnutí konkrétního checku **bez** zásahu do `module.jsonc`. Doporučená cesta pro produkční flapping — admin si může check dočasně utišit. Reconciler `enabled=false` přeskakuje úplně (žádný run, žádný resolve existujících alertů). |

### Plánování (schedule)

| Sloupec | Typ | Popis |
|---|---|---|
| `next_run_at` | datetime | Kdy se má check příště spustit (NOW + intervalSeconds, vždy po dokončení běhu — i u chyby). NULL = "spustit při nejbližší příležitosti" (čerstvý řádek po prvním boot). |

### Poslední běh (lastRun)

| Sloupec | Typ | Popis |
|---|---|---|
| `last_run_at` | datetime | Konec posledního běhu. |
| `last_run_status` | enumString → `core.alerts.checkRunStatuses` | `ok` / `found` / `error`. |
| `last_run_duration_ms` | int | Trvání v ms. |
| `last_run_findings` | int | Počet vrácených `AlertFinding[]` (nehledě na to, jestli byly new / updated / resolved). |
| `last_run_error` | text | Když `status=error`: zpráva výjimky. Stack trace patří do ErrorLogger, ne sem. |

### Zámek (lock)

| Sloupec | Typ | Popis |
|---|---|---|
| `is_running` | boolean, default false | Zámek proti paralelnímu spuštění téhož checku. |
| `running_since` | datetime | Pomáhá detekovat "stale lock" — pokud `is_running=true` ale `running_since < NOW - 5min`, reconciler považuje zámek za odumřelý, override + warn do logu, vlastní běh pokračuje. |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_check_id` | unique | `check_id` | Invariant "max 1 řádek per check". |
| `idx_next_run` | index | `enabled`, `next_run_at` | `getDueCheckIds()` lookup: `WHERE enabled = TRUE AND (next_run_at IS NULL OR next_run_at <= NOW) ORDER BY next_run_at ASC`. |
