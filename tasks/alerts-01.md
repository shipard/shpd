# Task: Implementace modulu `core.alerts` — MVP

**Stav:** hotovo

Cílem je vytvořit nový subsystém Upozornění (Alerts), který bude uživateli oznamovat
problémy, které systém "ví", ale uživatel je nevidí (chybějící základní nastavení DS,
nespárované bankovní platby, IoT zařízení s vybitou baterií, doklady viset ve stavu
V opravě, atd.). Tento task pokrývá MVP — infrastrukturu + jeden konkrétní check.

---

## 1. Co číst před začátkem

Povinné:

- `docs/architecture.md` — vrstvy a tok dat
- `docs/modules.md` — JSONC, vícejazyčnost, `module.jsonc`, kompilace konfigurace
- `docs/table-definitions.md` — JSONC schéma tabulek
- `docs/document-system.md` — Document třídy, hooky, DocumentRegistry
- `docs/doc-states.md` — **POZOR**: pro tento modul `docStates` mechanismus
  **NEPOUŽÍVÁME** (viz sekce 3 níže), ale stojí za to znát konvence
- `docs/cli.md` — vzor pro CLI příkazy
- `docs/frontend.md` — viewer + form patterns
- `docs/design-system.md` — CSS proměnné, badge konvence

Inspirace v existujících modulech (čti, opisuj patterny):

- `modules/core/attachments/` — kompletní modul s tabulkou, kontrolerem, viewerem,
  CLI integrací — nejbližší analogie
- `modules/core/mail/` — `core_mail_analysis_claims` jako pattern pro
  state/lease tabulku, `MailRouterProvisioner` jako pattern pro lifecycle helper
- `modules/base/persons/` — `PersonsViewer`, `PersonsForm`, `PersonDocument`
  jako vzor pro viewer/form/document trio

---

## 2. Klíčová architektonická rozhodnutí (recap z diskuse)

Tyto věci jsou **uzavřené**, nediskutuj je, jen je implementuj:

1. **Check vs Alert (Finding) — oddělené pojmy.**
   - *Check* = definice kontroly (JSONC + PHP třída), statická.
   - *Alert / Finding* = konkrétní instance problému (řádek v DB), dynamická.
   - Jeden check produkuje 0..N alertů. Identita alertu uvnitř checku =
     `(check_id, finding_key)`.

2. **Stavy alertů — vlastní `alert_state` sloupec, NE `docStates` mechanismus.**
   Hodnoty: 10 Aktivní, 20 Odložené (snoozed), 70 Vyřešené (auto), 80 Zamítnuté.
   Žádný `docState`/`docStateMain`/cfgItem v `docStates` bloku.
   Tabulka nemá tab bar v prohlížeči — místo toho filter dropdown.

3. **`alertChecks` jsou inline v `module.jsonc`**, podobně jako `viewers`,
   `forms`, `documentClasses`. Žádné separátní JSONC soubory per check.

4. **Snooze nemá background job na flipnutí 20→10.**
   Reconciler při potkání existujícího alertu ve stavu 20 zkontroluje
   `snoozed_until` a případně přepne na 10. Viewer dotaz "Otevřené" filtruje:
   `alert_state = 10 OR (alert_state = 20 AND snoozed_until < NOW())`.

5. **Subject reference (`subject_table_id`, `subject_row_id`) jen pro proklik.**
   Lidský popis nálezu jde do `title`/`message`, žádný denormalizovaný
   `subject_label` sloupec.

6. **Chyba checku ≠ resolve alertů.** Když `check->run()` hodí výjimku:
   - Runner ji chytí.
   - V `core_alerts_check_states` zapíše `last_run_status = 'error'` +
     error message.
   - Existujících alertů toho checku **se nedotkne** (žádný auto-resolve).

7. **Lokalizace title/message generovaných za běhu:** check je generuje v jazyce
   DS — vezmi z `DataSourceConfig::getDefaultLanguage()`. Per-uživatelské
   varianty neřešíme.

8. **Cron** spouští `shpd-ds alerts-run` každých 5 minut (cron je out of scope
   tohoto tasku, ale v dokumentaci to zmiň). Jednotlivý check si nese
   vlastní `interval` (typicky 1h+), runner přeskakuje checky kde
   `next_run_at > NOW()`.

9. **Pevné snooze volby v UI:** 1h / 4h / 1d / 1w. Endpoint ale přijme libovolnou
   ISO 8601 duration.

---

## 3. Adresářová struktura

Modul `core.alerts`:

```
modules/core/alerts/
├── module.jsonc
├── README.md
├── tables/
│   ├── core_alerts_alerts.jsonc
│   ├── core_alerts_alerts.md
│   ├── core_alerts_check_states.jsonc
│   └── core_alerts_check_states.md
├── config/
│   ├── severities.jsonc            # cfgItem core.alerts.severities
│   ├── alertStates.jsonc           # cfgItem core.alerts.alertStates
│   ├── viewerDetailLabels.jsonc    # standard pattern
│   └── viewerDefaults.jsonc        # standard pattern (jako u jiných modulů)
├── forms/
│   └── core_alerts_alerts.jsonc    # read-only form definition
└── src/
    ├── AlertsViewer.php
    └── AlertDocument.php           # může být tenká, jen pro lifecycle
```

Core backend (mimo modul, sdílené):

```
src/Core/Alerts/
├── AlertCheck.php                  # abstract base class
├── AlertFinding.php                # readonly value object
├── AlertCheckDefinition.php        # parsed entry z module.jsonc
├── AlertCheckRegistry.php          # agregace alertChecks ze všech modulů
└── AlertReconciler.php             # reconciliation logic
```

Kontroler:

```
src/Api/Controllers/AlertsController.php
```

CLI:

```
src/Command/DataSource/AlertsRunCommand.php
src/Command/DataSource/AlertsPruneCommand.php
```

Konkrétní check (v modulu `base.persons`):

```
modules/base/persons/src/Checks/MissingOwnPersonCheck.php
```

Dokumentace:

```
docs/alerts.md
```

Aktualizovat `docs/README.md` (přidat odkaz na `alerts.md`).

---

## 4. Tabulky

### 4.1 `core_alerts_alerts`

`tableId` — vezmi další volné přes `shpd-server next-table-id` (mělo by být
v core rozsahu 1–9999).

```jsonc
{
    "tableId": <next-free>,
    "name": "Alerts",
    "name:cs": "Upozornění",
    "name:en": "Alerts",

    "displayPattern": "{title}",

    "columnGroups": [
        {
            "id": "identity",
            "name": "Identification",
            "name:cs": "Identifikace",
            "name:en": "Identification"
        },
        {
            "id": "content",
            "name": "Content",
            "name:cs": "Obsah",
            "name:en": "Content"
        },
        {
            "id": "subject",
            "name": "Subject",
            "name:cs": "Předmět",
            "name:en": "Subject"
        },
        {
            "id": "state",
            "name": "State",
            "name:cs": "Stav",
            "name:en": "State"
        },
        {
            "id": "timing",
            "name": "Timing",
            "name:cs": "Časové údaje",
            "name:en": "Timing"
        }
    ],

    "columns": [
        { "id": "id", "name": "ID", "type": "int", "autoIncrement": true, "primaryKey": true },

        // --- identity ---
        {
            "id": "check_id",
            "name": "Check ID",
            "name:cs": "ID kontroly",
            "name:en": "Check ID",
            "type": "varchar", "length": 200,
            "nullable": false,
            "group": "identity"
        },
        {
            "id": "finding_key",
            "name": "Finding key",
            "name:cs": "Klíč nálezu",
            "name:en": "Finding key",
            "type": "varchar", "length": 200,
            "nullable": false,
            "default": "",
            "group": "identity"
        },

        // --- content ---
        {
            "id": "title",
            "name": "Title",
            "name:cs": "Titulek",
            "name:en": "Title",
            "type": "varchar", "length": 250,
            "nullable": false,
            "group": "content"
        },
        {
            "id": "message",
            "name": "Message",
            "name:cs": "Zpráva",
            "name:en": "Message",
            "type": "text",
            "nullable": true,
            "group": "content"
        },
        {
            "id": "severity",
            "name": "Severity",
            "name:cs": "Závažnost",
            "name:en": "Severity",
            "type": "enumInt",
            "cfgItem": "core.alerts.severities",
            "default": 20,
            "group": "content"
        },
        {
            "id": "actions",
            "name": "Actions",
            "name:cs": "Akce",
            "name:en": "Actions",
            "type": "json",
            "nullable": true,
            "group": "content"
        },
        {
            "id": "context",
            "name": "Context",
            "name:cs": "Kontext",
            "name:en": "Context",
            "type": "json",
            "nullable": true,
            "group": "content"
        },

        // --- subject ---
        {
            "id": "subject_table_id",
            "name": "Subject table",
            "name:cs": "Tabulka předmětu",
            "name:en": "Subject table",
            "type": "smallint",
            "nullable": true,
            "group": "subject"
        },
        {
            "id": "subject_row_id",
            "name": "Subject row",
            "name:cs": "Záznam předmětu",
            "name:en": "Subject row",
            "type": "int",
            "nullable": true,
            "group": "subject"
        },

        // --- state ---
        {
            "id": "alert_state",
            "name": "State",
            "name:cs": "Stav",
            "name:en": "State",
            "type": "enumInt",
            "cfgItem": "core.alerts.alertStates",
            "default": 10,
            "group": "state"
        },
        {
            "id": "snoozed_until",
            "name": "Snoozed until",
            "name:cs": "Odloženo do",
            "name:en": "Snoozed until",
            "type": "datetime",
            "nullable": true,
            "group": "state"
        },
        {
            "id": "dismissed_at",
            "name": "Dismissed at",
            "name:cs": "Zamítnuto",
            "name:en": "Dismissed at",
            "type": "datetime",
            "nullable": true,
            "group": "state"
        },
        {
            "id": "resolved_at",
            "name": "Resolved at",
            "name:cs": "Vyřešeno",
            "name:en": "Resolved at",
            "type": "datetime",
            "nullable": true,
            "group": "state"
        },

        // --- timing ---
        {
            "id": "first_seen_at",
            "name": "First seen at",
            "name:cs": "Poprvé spatřeno",
            "name:en": "First seen at",
            "type": "datetime",
            "nullable": false,
            "group": "timing"
        },
        {
            "id": "last_seen_at",
            "name": "Last seen at",
            "name:cs": "Naposled spatřeno",
            "name:en": "Last seen at",
            "type": "datetime",
            "nullable": false,
            "group": "timing"
        },
        {
            "id": "seen_count",
            "name": "Seen count",
            "name:cs": "Počet pozorování",
            "name:en": "Seen count",
            "type": "int",
            "default": 1,
            "group": "timing"
        }
    ],

    "indexes": [
        {
            "id": "idx_check_finding_state",
            "type": "index",
            "columns": [
                {"column": "check_id"},
                {"column": "finding_key"},
                {"column": "alert_state"}
            ]
        },
        {
            "id": "idx_state_last_seen",
            "type": "index",
            "columns": [
                {"column": "alert_state"},
                {"column": "last_seen_at", "order": "DESC"}
            ]
        },
        {
            "id": "idx_subject",
            "type": "index",
            "columns": [
                {"column": "subject_table_id"},
                {"column": "subject_row_id"}
            ]
        }
    ]
}
```

**Markdown dokumentace** (`core_alerts_alerts.md`) — popis tabulky, typický
životní cyklus alertu, vysvětlení `finding_key` (proč není `varchar(36)` /
UUID — je to opaque key vyrobený checkem), poznámka o `actions` schématu (viz
sekce 10 task promptu — zkopíruj).

### 4.2 `core_alerts_check_states`

Jeden řádek per zaregistrovaný check. Vytváří se lazily — při prvním běhu
checku reconciler insertne, dál updatuje.

```jsonc
{
    "tableId": <next-free>,
    "name": "Alert check states",
    "name:cs": "Stavy kontrol upozornění",
    "name:en": "Alert check states",

    "displayPattern": "{check_id}",

    "columnGroups": [
        {"id": "identity", "name:cs": "Identifikace", "name:en": "Identification"},
        {"id": "schedule", "name:cs": "Plánování",   "name:en": "Schedule"},
        {"id": "lastRun",  "name:cs": "Poslední běh", "name:en": "Last run"},
        {"id": "lock",     "name:cs": "Zámek",       "name:en": "Lock"}
    ],

    "columns": [
        { "id": "id", "name": "ID", "type": "int", "autoIncrement": true, "primaryKey": true },

        // identity
        {
            "id": "check_id",
            "name": "Check ID",
            "name:cs": "ID kontroly",
            "type": "varchar", "length": 200,
            "nullable": false,
            "group": "identity"
        },
        {
            "id": "enabled",
            "name": "Enabled",
            "name:cs": "Povoleno",
            "type": "boolean",
            "default": true,
            "group": "identity"
        },

        // schedule
        {
            "id": "next_run_at",
            "name": "Next run at",
            "name:cs": "Další běh v",
            "type": "datetime",
            "nullable": true,
            "group": "schedule"
        },

        // lastRun
        {
            "id": "last_run_at",
            "name": "Last run at",
            "name:cs": "Poslední běh v",
            "type": "datetime",
            "nullable": true,
            "group": "lastRun"
        },
        {
            "id": "last_run_status",
            "name": "Last run status",
            "name:cs": "Status posledního běhu",
            "type": "enumString",
            "length": 20,
            "cfgItem": "core.alerts.checkRunStatuses",
            "nullable": true,
            "group": "lastRun"
        },
        {
            "id": "last_run_duration_ms",
            "name": "Last run duration (ms)",
            "name:cs": "Trvání posledního běhu (ms)",
            "type": "int",
            "nullable": true,
            "group": "lastRun"
        },
        {
            "id": "last_run_findings",
            "name": "Last run findings",
            "name:cs": "Počet nálezů posl. běhu",
            "type": "int",
            "nullable": true,
            "group": "lastRun"
        },
        {
            "id": "last_run_error",
            "name": "Last run error",
            "name:cs": "Chyba posledního běhu",
            "type": "text",
            "nullable": true,
            "group": "lastRun"
        },

        // lock
        {
            "id": "is_running",
            "name": "Is running",
            "name:cs": "Právě běží",
            "type": "boolean",
            "default": false,
            "group": "lock"
        },
        {
            "id": "running_since",
            "name": "Running since",
            "name:cs": "Běží od",
            "type": "datetime",
            "nullable": true,
            "group": "lock"
        }
    ],

    "indexes": [
        {
            "id": "unq_check_id",
            "type": "unique",
            "columns": [{"column": "check_id"}]
        },
        {
            "id": "idx_next_run",
            "type": "index",
            "columns": [
                {"column": "enabled"},
                {"column": "next_run_at"}
            ]
        }
    ]
}
```

Tabulku **schovat ze sidebaru** přes `"hideFromNavigation": true` — je čistě
interní, uživatel ji nemá co prohlížet samostatně.

---

## 5. cfgItems

### 5.1 `core.alerts.severities`

```jsonc
// modules/core/alerts/config/severities.jsonc
{
    "10": {
        "name": "Info",
        "name:cs": "Informace",
        "name:en": "Info",
        "style": "info"
    },
    "20": {
        "name": "Warning",
        "name:cs": "Upozornění",
        "name:en": "Warning",
        "style": "warning"
    },
    "30": {
        "name": "Error",
        "name:cs": "Chyba",
        "name:en": "Error",
        "style": "error"
    }
}
```

`style` se použije v UI pro CSS class `shpd-alert--severity-{style}` (a/nebo
v badgi). Barvy:

- `info` → modrá (návrh: existující `--shpd-color-info` nebo `--shpd-color-doc-state-confirmed`)
- `warning` → oranžová/žlutá (návrh: `--shpd-color-doc-state-edit`)
- `error` → červená (návrh: `--shpd-color-doc-state-cancelled` nebo `--shpd-color-error`)

Pokud žádná z navržených proměnných neexistuje, doplň do `design-system.md`
a definuj v base stylech tři nové custom properties. Implementer je v právu
zvolit.

### 5.2 `core.alerts.alertStates`

```jsonc
// modules/core/alerts/config/alertStates.jsonc
{
    "10": {
        "name": "Active",
        "name:cs": "Aktivní",
        "name:en": "Active",
        "style": "active",
        "isOpen": true
    },
    "20": {
        "name": "Snoozed",
        "name:cs": "Odložené",
        "name:en": "Snoozed",
        "style": "snoozed",
        "isOpen": true
    },
    "70": {
        "name": "Resolved",
        "name:cs": "Vyřešené",
        "name:en": "Resolved",
        "style": "resolved",
        "isOpen": false
    },
    "80": {
        "name": "Dismissed",
        "name:cs": "Zamítnuté",
        "name:en": "Dismissed",
        "style": "dismissed",
        "isOpen": false
    }
}
```

`isOpen` rozlišuje "ještě v hledáčku" (Aktivní + Odložené) vs. "uzavřené"
(Vyřešené + Zamítnuté) — slouží pro filtry ve vieweru.

### 5.3 `core.alerts.checkRunStatuses`

```jsonc
// modules/core/alerts/config/checkRunStatuses.jsonc
{
    "ok": {
        "name": "OK",
        "name:cs": "OK",
        "name:en": "OK"
    },
    "found": {
        "name": "Found problems",
        "name:cs": "Nalezeny problémy",
        "name:en": "Found problems"
    },
    "error": {
        "name": "Error",
        "name:cs": "Chyba",
        "name:en": "Error"
    }
}
```

---

## 6. Rozšíření `ModuleDefinition` o `alertChecks`

V `src/Core/Module/ModuleDefinition.php` přidat pole `alertChecks` (pole
objektů). Schéma každého záznamu:

```jsonc
{
    "id": "base.persons.missing_own_person",      // string, povinné, globálně unikátní
    "name": "Chybí vlastní Osoba",                 // string, povinné, vícejazyčné
    "description": "...",                          // string, volitelné, vícejazyčné
    "class": "Shipard\\Module\\Base\\Persons\\Checks\\MissingOwnPersonCheck",  // FQCN, povinné
    "severity": "warning",                         // info|warning|error; volitelné, default warning
    "interval": "1h",                              // string duration; viz níže; povinné
    "enabled": true,                               // bool, volitelné, default true
    "tags": ["setup"]                              // string[], volitelné
}
```

`interval` formát: zjednodušený suffix-based — `"5m"`, `"1h"`, `"30m"`, `"1d"`,
`"7d"`. Parser napiš jednoduchý helper `IntervalParser` v `src/Core/Alerts/`,
který vrací sekundy. Akceptované suffixy: `s`, `m`, `h`, `d`. Pokud nepasuje
regex, parser hodí výjimku.

**Validace v `ModuleDefinition::fromArray()`**:
- `id`, `name`, `class`, `interval` musí být přítomné a neprázdné
- `id` musí být `[a-z][a-z0-9_.]*` (stejná konvence jako moduly)
- `severity`, pokud uvedeno, musí být jeden z `info|warning|error`
- duplicity `id` napříč všemi `alertChecks` všech modulů detekuje `AlertCheckRegistry`
  při bootu (analogicky duplicitě `tableId`)

---

## 7. Backend — core třídy

Vše v namespace `Shipard\Core\Alerts\`.

### 7.1 `AlertCheckDefinition` (readonly value object)

Reprezentuje jeden parsovaný `alertChecks` záznam. Factory `fromArray()`.
Pole: `id`, `name`, `description`, `class`, `severity` (string), `interval`
(string raw), `intervalSeconds` (int, parsovaný), `enabled`, `tags`,
`moduleId` (string, doplněné registrem — odkud check pochází).

### 7.2 `AlertCheck` (abstract)

```php
namespace Shipard\Core\Alerts;

abstract class AlertCheck
{
    public function __construct(
        protected DataSourceConnection $db,
        protected ConfigRuntime $config,
        protected string $language,            // jazyk DS
    ) {}

    /** @return AlertFinding[] */
    abstract public function run(): array;
}
```

`AlertReconciler` checky instancuje s těmito třemi argumenty — DI je
explicitní, nehledat servisní lokátor.

### 7.3 `AlertFinding` (readonly)

```php
namespace Shipard\Core\Alerts;

final class AlertFinding
{
    public function __construct(
        public readonly string $findingKey,        // "" pro singleton check
        public readonly string $title,
        public readonly string $message = '',
        public readonly string $severity = 'warning',    // info|warning|error
        public readonly ?int $subjectTableId = null,
        public readonly ?int $subjectRowId = null,
        /** @var array<int, array<string, mixed>> */
        public readonly array $actions = [],
        public readonly ?array $context = null,
    ) {}
}
```

Validace v konstruktoru: `severity` patří do whitelistu, `title` neprázdný.

### 7.4 `AlertCheckRegistry`

Konstruktor dostane pole `ModuleDefinition[]`. Pro každý modul projde
`alertChecks` a postaví `AlertCheckDefinition[]` indexované přes `id`. Při
duplicitě hodí výjimku s názvy obou modulů. Lokalizaci `name`/`description`
nech na standardním mechanismu (jak je to teď u `viewers`/`forms` — pokud
to ConfigLocalizer při loadu modulu už řeší, dobře; pokud ne, doplň).

Veřejné metody:
- `getAll(): AlertCheckDefinition[]`
- `get(string $checkId): ?AlertCheckDefinition`
- `getEnabled(): AlertCheckDefinition[]` — vylučí `enabled: false` z JSONC

Registr se instancuje v `index.php` po `ModuleLoader` a propaguje do
controllerů a CLI (analogicky `ConfigRuntime`).

### 7.5 `AlertReconciler`

Srdce systému. Sjednocuje výsledek `$check->run()` s existujícími alerty
a aktualizuje `core_alerts_check_states`.

```php
namespace Shipard\Core\Alerts;

final class AlertReconciler
{
    public function __construct(
        private DataSourceConnection $db,
        private AlertCheckRegistry $registry,
        private ConfigRuntime $config,
        private string $language,
    ) {}

    /**
     * Spustí jeden check a sjednotí jeho výsledek s DB.
     * Vrací zprávu o běhu (počet new/updated/resolved alertů, status, error).
     */
    public function runCheck(string $checkId): AlertRunResult;

    /**
     * Vrátí seznam check_id, které jsou aktuálně due (next_run_at <= NOW
     * nebo dosud nikdy nespuštěné), seřazené nejstarší napřed.
     */
    public function getDueCheckIds(): array;
}
```

`AlertRunResult` je tenký readonly value object (`status`, `findingsCount`,
`newCount`, `updatedCount`, `resolvedCount`, `durationMs`, `?errorMessage`).

**Pseudo-algoritmus `runCheck`**:

1. Načti `AlertCheckDefinition` z registru. Pokud chybí → vyhoď výjimku
   (nebo vrať status `error`; zvol druhé — chybějící check není fatal pro
   ostatní).
2. Pokud `enabled: false` → vrať okamžitě bez akce, status `ok`,
   `findingsCount: 0`.
3. Načti/insertni řádek v `core_alerts_check_states` pro tento check_id.
4. Pokud `is_running == true` a `running_since` mladší než **5 minut** →
   přeskočit (someone else is running). Pokud starší → uvolnit zámek
   (warning do logu) a pokračovat.
5. Označit `is_running = true`, `running_since = NOW`.
6. **try**: instancuj třídu z `$definition->class`, zavolej `run()`,
   změř dobu trvání. Catch jakoukoliv `Throwable`:
   - Zapsat `last_run_status = error`, `last_run_error = $e->getMessage()`,
     `last_run_duration_ms`, `last_run_at = NOW`.
   - `next_run_at = NOW + intervalSeconds` (i u chyby zachovat schedule).
   - Existujících alertů **se nedotknout**.
   - Uvolnit zámek, vrátit `AlertRunResult(status=error, ...)`.
7. **Success path** (transakce):
   a. Načíst všechny existující alerty pro tento `check_id` ve stavu Open
      (`alert_state IN (10, 20)`), indexovat přes `finding_key`.
   b. Pro každý `AlertFinding` z výsledku:
      - Existuje matching alert? → UPDATE (`last_seen_at = NOW`,
        `seen_count++`, refresh `title`, `message`, `severity`, `actions`,
        `context`, `subject_*`). Pokud byl ve stavu 20 (Snoozed) a
        `snoozed_until < NOW` → přepni na 10 a vynuluj `snoozed_until`.
        Pokud byl ve stavu 20 a snooze stále platí → ponech 20.
      - Neexistuje → INSERT (state=10, `first_seen_at = last_seen_at = NOW`,
        `seen_count = 1`).
      - Označit ID jako "seen this run".
   c. Pro každý existující open alert, který NEBYL "seen this run":
      → UPDATE na state=70 (Resolved), `resolved_at = NOW`.
   d. Update `core_alerts_check_states`: `last_run_at = NOW`,
      `last_run_status = ok` nebo `found` (podle počtu findings),
      `last_run_findings = count(findings)`, `last_run_duration_ms`,
      `last_run_error = NULL`, `next_run_at = NOW + intervalSeconds`,
      `is_running = false`, `running_since = NULL`.
8. Vrátit `AlertRunResult(status=ok|found, ...)`.

**Idempotence**: Reconciler nesmí nikdy zapsat dvě paralelní řady pro tentýž
finding_key, ani když je pustíš dvakrát rychle za sebou. Lock přes `is_running`
+ 5min timeout je dostatečná ochrana pro náš use case (cron + occasional
manuální spuštění); plnohodnotný advisory lock v MariaDB neimplementujeme.

**Reconciler vs API snooze/dismiss**: snooze/dismiss endpointy nesahají na
reconciler — píší přímo do `core_alerts_alerts` přes `CrudController` /
dedikovaný kód v `AlertsController`. Reconciler jen reaguje na existující
stavy.

---

## 8. CLI příkazy

Žij ze stejných konvencí jako `DsUpgradeCommand` (`src/Command/DataSource/`).

### 8.1 `shpd-ds alerts-run`

```
shpd-ds alerts-run                       # všechny due checky
shpd-ds alerts-run --check=<id>          # konkrétní check, ignoruje schedule
shpd-ds alerts-run --all                 # ignoruje schedule, spustí všechny enabled
shpd-ds alerts-run -v                    # verbose výpis per check
```

Výstup (default):
```
Shipard alerts run

Running 3 due check(s):
  [OK]    base.persons.missing_own_person     (0 findings, 12ms)
  [FOUND] economy.docs.unsigned_drafts        (4 findings — 2 new, 2 updated, 18ms)
  [ERROR] iot.devices.low_battery             — SQLException: …

Summary: 2 ok, 1 error. 4 alerts found (2 new).
```

Exit code:
- `0` — všechny checky `ok` nebo `found`
- `1` — alespoň jeden `error`

Idempotence: pokud nic není due, příkaz tiše skončí s `0` ("No due checks").

### 8.2 `shpd-ds alerts-prune`

```
shpd-ds alerts-prune                    # default --days=90
shpd-ds alerts-prune --days=30
shpd-ds alerts-prune --dry-run
```

Smaže z `core_alerts_alerts` záznamy kde `alert_state IN (70, 80)` AND
(`resolved_at < NOW - days` nebo `dismissed_at < NOW - days`). Vypíše
kolik se smazalo.

---

## 9. API endpointy

Přidat do `Router` (`src/Api/Router.php`) — všechny pod prefix `_alerts/`
podobně jako `_attachments/`, `_meta`, `_auth`.

| Method | Path | Handler |
|--------|------|---------|
| GET    | `/_alerts/registry` | `AlertsController::registry` — seznam zaregistrovaných checků (id, name, description, severity, interval, tags, enabled, lastRunAt, lastRunStatus). Pro debugging / dashboard. |
| POST   | `/_alerts/checks/{checkId}/run` | `AlertsController::runCheck` — synchronní re-run, vrátí `AlertRunResult` + aktuální seznam alertů z tohoto checku. |
| POST   | `/_alerts/alerts/{id}/snooze` | `AlertsController::snooze` — body `{"duration": "PT1H"}` nebo `{"hours": 1}`; nastaví `alert_state = 20`, `snoozed_until = NOW + duration`. |
| POST   | `/_alerts/alerts/{id}/dismiss` | `AlertsController::dismiss` — nastaví `alert_state = 80`, `dismissed_at = NOW`. |
| POST   | `/_alerts/alerts/{id}/unsnooze` | `AlertsController::unsnooze` — vrátí na `alert_state = 10`, vynuluje `snoozed_until`. |

Standardní CRUD `/api/v1/core_alerts_alerts` ponechat (pro list view).

**Duration parsing** v `snooze`: akceptuj jak ISO 8601 (`PT1H`, `PT4H`,
`P1D`, `P7D`) tak `{"hours": N}` nebo `{"days": N}`. Validuj rozsah:
min 5 minut, max 365 dní.

**AuthN/AuthZ**: stejné jako u ostatních endpointů (Bearer token přes
`AuthMiddleware`). Speciální oprávnění zatím neřešíme.

---

## 10. Schéma `actions` v alertu

`actions` je JSON array. Každá akce:

```json
{
    "id": "create_own_person",
    "label": "Add own Person",
    "kind": "open_form",
    "target": {
        "table": "base_persons_persons",
        "mode": "create",
        "preset": {"is_own": 1}
    },
    "primary": true
}
```

Podporované `kind` v MVP:

- `open_form` — `target: {table, mode: "create"|"edit", id?, preset?}`
- `open_viewer` — `target: {viewerId}` (id z `module.jsonc` → `viewers[].id`)

`primary: true` označuje hlavní akci — frontend ji vyrenderuje jako primary
button. Maximálně jedna akce s `primary: true` per alert (validace v
`AlertFinding`).

`label` je již v jazyce DS (check ho generuje localizovaný; analogicky
title/message). Žádné `:cs`/`:en` v JSONu na úrovni alertu — to se řeší
jen v JSONC zdrojích.

Frontend implementace akcí je out of scope pro tento task (frontend zobrazí
buttony, klik zatím může jen zobrazit toast "Action {id} clicked" — to
doděláme později). Ale data structure musí být kompletní.

---

## 11. Konkrétní check: `base.persons.missing_own_person`

### 11.1 Co to dělá

Vlastní Osoba (právní subjekt firmy/živnostníka, pod jehož hlavičkou DS
funguje) by měla existovat v `base_persons_persons`. Po vytvoření čerstvého
DS žádná není.

**Detekce**: `base_persons_persons` má (nebo dostane v rámci tohoto tasku)
sloupec `is_own` (`boolean`, default `false`). Check selže pokud
`SELECT COUNT(*) FROM base_persons_persons WHERE is_own = TRUE AND alert_state ...`
— wait, `base_persons_persons` má `docState`. Filter: `docState IN (10, 40)`
(Koncept nebo V pořádku) AND `is_own = TRUE`. Pokud výsledek je 0, vytvoř
alert.

### 11.2 Přidání sloupce `is_own`

V `modules/base/persons/tables/base_persons_persons.jsonc` přidej sloupec:

```jsonc
{
    "id": "is_own",
    "name": "Own legal entity",
    "name:cs": "Vlastní právní subjekt",
    "name:en": "Own legal entity",
    "type": "boolean",
    "default": false,
    "group": "<existing-group-or-omit>"
}
```

V `PersonsForm` zařadit do vhodného tabu (např. základní info). V `PersonsViewer`
přidat badge "Vlastní" u řádků kde `is_own = true`.

Pokud `base.persons` modul má omezení (např. že může být jen jedna vlastní
osoba), tu kontrolu nedělej v tomto tasku — z hlediska MVP postačí, že
chybí. Druhotná kontrola "více než jedna vlastní osoba" může být další
check v budoucnu.

### 11.3 Třída checku

`modules/base/persons/src/Checks/MissingOwnPersonCheck.php`:

```php
namespace Shipard\Module\Base\Persons\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

final class MissingOwnPersonCheck extends AlertCheck
{
    public function run(): array
    {
        $count = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM base_persons_persons WHERE is_own = ? AND docState IN (10, 40)',
            true
        );

        if ($count > 0) {
            return [];
        }

        $title   = $this->language === 'cs'
            ? 'Chybí vlastní Osoba'
            : 'Own Person is missing';

        $message = $this->language === 'cs'
            ? 'Po vytvoření zdroje dat je třeba nastavit vlastní právní subjekt (firmu nebo živnostníka), pod jehož hlavičkou systém funguje.'
            : 'After creating a data source, you need to set up the own legal entity that the system runs on behalf of.';

        $actionLabel = $this->language === 'cs'
            ? 'Přidat vlastní Osobu'
            : 'Add own Person';

        return [
            new AlertFinding(
                findingKey: '',     // singleton check
                title: $title,
                message: $message,
                severity: 'warning',
                actions: [
                    [
                        'id'      => 'create_own_person',
                        'label'   => $actionLabel,
                        'kind'    => 'open_form',
                        'target'  => [
                            'table'  => 'base_persons_persons',
                            'mode'   => 'create',
                            'preset' => ['is_own' => true],
                        ],
                        'primary' => true,
                    ],
                ],
            ),
        ];
    }
}
```

### 11.4 Registrace v `base.persons/module.jsonc`

Přidat `alertChecks` blok:

```jsonc
"alertChecks": [
    {
        "id": "base.persons.missing_own_person",
        "name": "Own Person is missing",
        "name:cs": "Chybí vlastní Osoba",
        "name:en": "Own Person is missing",
        "description": "Detects missing own legal entity (set up after creating a new data source).",
        "description:cs": "Detekuje chybějící vlastní právní subjekt (nastavuje se po vytvoření nového zdroje dat).",
        "class": "Shipard\\Module\\Base\\Persons\\Checks\\MissingOwnPersonCheck",
        "severity": "warning",
        "interval": "1h",
        "tags": ["setup"]
    }
]
```

---

## 12. Viewer

`modules/core/alerts/src/AlertsViewer.php`:

- Bázová třída `TableViewer`
- Tabulka `core_alerts_alerts`
- Default sort: `severity DESC, last_seen_at DESC`
- **Nepoužívá** `docStatesCfgItem` — tab bar nebude
- Místo tabů přidat filter dropdown v `Viewer.svelte` (pokud bázový
  Viewer dropdown filter dnes nemá, implementer ho **nepřidává globálně** —
  místo toho v `AlertsViewer::renderRow()` rozliš stavy CSS třídou a default
  filter řeší přes query parameter `?filter[alert_state]=open`, kde "open"
  se mapuje na `IN (10, 20)`)
- Sloupce v listě (návrh):
  - Severity badge (ikona + barva)
  - Title
  - Subject — pokud `subject_table_id` + `subject_row_id`, render jako proklik
    (text z `displayPattern` cílové tabulky pokud jde rychle joinem získat,
    jinak `Tabulka #ID`)
  - State badge (Aktivní / Odložené (do datum) / Vyřešené / Zamítnuté)
  - Last seen (relativní čas: "před 5 minutami", "včera", "před 3 dny")
  - Check name (z registry, pomocí `check_id`)

### Frontend: tlačítka na řádku

V detail rozbalení řádku (pokud `ViewerRow` to umožňuje, jinak v hover menu)
zobrazit tři tlačítka pro alerty ve stavu 10/20:

- **Odložit** → dropdown (1h / 4h / 1d / 1w) → `POST /_alerts/alerts/{id}/snooze`
- **Zamítnout** → `POST /_alerts/alerts/{id}/dismiss` (s confirm dialogem)
- **Zkontrolovat znovu** → `POST /_alerts/checks/{check_id}/run`

Plus tlačítka pro custom akce z `actions` JSON.

---

## 13. Form (read-only)

Standardní JSONC form `modules/core/alerts/forms/core_alerts_alerts.jsonc`,
jeden tab "Detail" se všemi sloupci read-only. Žádné edit pole — alerty
se editují jen přes API endpointy (snooze/dismiss/run).

V `AlertDocument` (`modules/core/alerts/src/AlertDocument.php`) hooky nech
prázdné — validace ani transformace se neřeší přes Document, ale přes
reconciler.

`fullSize` na formu zatím nestaví; modální dialog stačí.

---

## 14. Dokumentace `docs/alerts.md`

Strukturovaný dokument navazující na ostatní v `docs/`. Návrh osnovy:

1. **Přehled** — k čemu Alerts slouží, oddělení Check vs Alert.
2. **Architektura** — diagram tříd (textový), tok dat při běhu reconcileru.
3. **JSONC schéma `alertChecks`** v `module.jsonc` — kompletní reference polí.
4. **PHP API** — `AlertCheck`, `AlertFinding`, contract, příklad
   `MissingOwnPersonCheck`.
5. **Identita nálezu** (`finding_key`) — proč, jak ji volit, příklady.
6. **Reconciliation logic** — kompletní popis (slovní + pseudo-algoritmus).
7. **Stavy alertů** — tabulka 10/20/70/80, semantics, kdy kterým směrem.
8. **Snooze** — pevné UI volby + ISO 8601 v API, expirace přes reconciler.
9. **`actions` JSON schema** — `kind`, `target`, `primary`, příklady.
10. **CLI** — `alerts-run`, `alerts-prune`, exit codes, příklady.
11. **API** — endpointy s request/response příklady.
12. **Cron** — doporučená cron config (`*/5 * * * *`), kdo má spouštět
    (user `shipard`), umístění v deployi (out of scope MVP, ale zmiň jako
    operational TODO).
13. **Otevřené body do dalších iterací** — `doc_state_changed_at` extension
    + `stale_in_repair` check, dashboard widget, per-user alert visibility,
    email digesty.

Aktualizovat `docs/README.md` — přidat řádek do hlavní tabulky:

```
| [alerts.md](alerts.md) | Systém upozornění — JSONC definice kontrol, PHP třídy checků, reconciliation, snooze/dismiss, CLI alerts-run |
```

---

## 15. Definition of done

- [ ] Modul `core.alerts` existuje, `ds-upgrade` projde bez chyb
- [ ] Tabulky vytvořené, indexy na místě
- [ ] cfgItems (`severities`, `alertStates`, `checkRunStatuses`) v compiled.cs/en.json
- [ ] `ModuleDefinition::fromArray()` zná `alertChecks`, validuje povinná pole
- [ ] `AlertCheckRegistry` v `index.php` boot pipeline, dostupný v controllerech
- [ ] `AlertReconciler::runCheck()` testovaný PHPUnit testem
  (3 scénáře: empty findings = resolve, new findings = insert, existing
  Snoozed s expirovaným snooze = re-activate)
- [ ] `IntervalParser` testovaný (`5m`, `1h`, `7d`, invalid)
- [ ] `shpd-ds alerts-run` funguje, exit codes správné
- [ ] `shpd-ds alerts-prune --dry-run` funguje
- [ ] API endpointy reagují na `curl` s validní + invalidní payload
- [ ] `base.persons` modul má `is_own` sloupec a `MissingOwnPersonCheck`
- [ ] V čerstvě vytvořeném DS (`shpd-server ds-create ... && shpd-ds ds-upgrade
      && shpd-ds alerts-run`) se objeví jeden alert "Chybí vlastní Osoba"
- [ ] Viewer `AlertsViewer` zobrazí alert v UI
- [ ] Klik na "Odložit 1h" v UI funguje, alert zmizí z default view
- [ ] `docs/alerts.md` napsaný, `docs/README.md` aktualizovaný
- [ ] `modules/core/alerts/README.md` napsaný (stručný; struktura modulu,
      odkaz na `docs/alerts.md`)

---

## 16. Out of scope (NEDĚLAT v tomto tasku)

- Sloupec `doc_state_changed_at` v `economy_docs_heads` a check
  `economy.docs.stale_in_repair` (další iterace)
- Bank transactions unmatched check
- IoT low battery check
- Dashboard widget s agregací alertů
- Per-user alert subscriptions / visibility
- Email digesty (denní/týdenní souhrn)
- Cron config v deploy/ (operational task)
- Frontend implementace akcí (klik na "Přidat vlastní Osobu" jen zobrazí
  toast / loguje do konzole; routing/preset až později)
- Tab bar pro viewer (filter dropdown stačí)
- Bulk operace (snooze/dismiss víc alertů najednou)
- Notifikace v reálném čase (WebSocket / SSE)

---

## 17. Pořadí práce (doporučené)

1. JSONC tabulky + `is_own` sloupec do `base_persons_persons`
2. cfgItems
3. `ModuleDefinition` rozšíření o `alertChecks` + validace + testy
4. `IntervalParser` + `AlertCheckDefinition` + `AlertFinding` + testy
5. `AlertCheckRegistry`
6. `AlertReconciler` + testy (toto je největší kus)
7. CLI `alerts-run` a `alerts-prune`
8. API kontroler + routing
9. `MissingOwnPersonCheck` + registrace v `base.persons/module.jsonc`
10. Viewer + form
11. End-to-end test: čistý DS → upgrade → run → viewer ukazuje alert
12. Dokumentace
13. Aktualizace README souborů
