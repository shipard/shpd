# Task: Dashboard — fáze 1 (MVP)

## Status / Cíl fáze

Přidat **Dashboard** jako home obrazovku aplikace — výchozí pohled po loginu
s přehledem nejdůležitějších informací dne. Pro MVP zahrnuje tři widgety
(Upozornění, Aktuální došlá pošta, Moje úkoly) a jeden agregační „AI shrnutí"
banner nahoře, který je v této fázi statický (počty z ostatních widgetů,
ikona robota, žádné skutečné AI volání).

Cílem je, aby uživatel po otevření aplikace na první pohled viděl, co
ho dnes čeká, bez nutnosti procházet jednotlivé viewery.

## Návaznost

- `tasks/alerts-01.md`, `alerts-02.md`, `alerts-03.md` — `core.alerts` modul
  s `IncomingMessagesViewer`-analogickým `AlertsViewer`, API endpointy
  `_alerts/*`, doc-state `core.alerts.alertStates`. Dashboard re-use existujícího
  vieweru a alert lifecycle.
- `tasks/mail-phase1.md` — `core.mail.incoming` viewer (`IncomingMessagesViewer`),
  doc-state `core.mail.docStatesIncoming`.
- `tasks/01-module-tasks.md` — `tasks.core` modul s `TasksViewer`,
  doc-state `tasks.core.docStatesTasks`.
- `tasks/frontend-phase3-app-sidebar.md` — `Sidebar.svelte`, `NavigationController`,
  formát navigation API odpovědi.
- `tasks/viewer-row-icons-and-numbers.md` — vzor pro re-use ikon z `module.jsonc`
  ve viewer řádcích; dashboard widget items mají analogický pattern.

Tento task **navazuje na hotový alerts/mail/tasks/sidebar systém** — nezavádí
žádnou novou abstrakci, jen agreguje existující data do nového pohledu.

## Před implementací přečti

- `docs/frontend.md` — celá architektura SPA, zejména sekce **4** (App shell,
  sidebar, mode systém), **7** (Viewer systém — `renderRow()`, formát řádku,
  doc-state pruh), **10** (Ikony — `icons.js`, `resolveIcon`, `iconMap`),
  **12** (i18n — `t()`, slovníky, lint)
- `docs/architecture.md` — sekce **2** (REST API pipeline, kontrolery)
- `docs/alerts.md` — sekce **7** (stavy alertů — Active/Snoozed/Resolved/Dismissed),
  sekce **11** (HTTP API — endpointy pro snooze/dismiss)
- `docs/design-system.md` — sekce **4** (Doc-state systém — `stateStyle`,
  `docState_*` třídy), sekce **5** (Badge systém — `--neutral/--primary/...`)
- `src/Api/Controller/ViewerController.php` — vzor pro instancování vieweru
  přes `ViewerLoader`/`ViewerRegistry`, volání `selectRows()` a `renderRow()`
- `src/Api/Controller/NavigationController.php` — kde a jak se sestavuje
  navigační strom, kam přidat root-level „Dashboard" položku
- `src/Core/Viewer/TableViewer.php` — signatura `selectRows()` a `renderRow()`
- `frontend/src/components/layout/ContentArea.svelte` — větvení podle
  `activeItem.type`, kam přidat `'dashboard'` case
- `frontend/src/stores/navigation.svelte.js` — store, default `activeItem`
  po loginu / inicializaci
- `frontend/src/components/viewer/ViewerRow.svelte` — reference pro
  vykreslení doc-state pruhu a layout řádku

## Scope

### V rozsahu

- **Backend**:
  - Nový `DashboardController` s endpointem `GET /_ui/dashboard`
  - Agregace dat ze tří existujících viewerů (`core.alerts.alerts`,
    `core.mail.incoming`, `tasks.core`) přes jejich `selectRows()` + `renderRow()`
  - COUNT dotazy na celkové počty otevřených záznamů per widget
- **Navigace**:
  - Nová root-level položka „Dashboard" v `NavigationController` (před první skupinou)
  - Nový typ `'dashboard'` v `ContentArea.svelte`
  - Po loginu / inicializaci výchozí `activeItem = 'dashboard'`
- **Frontend komponenty**:
  - `Dashboard.svelte` (top-level) s grid layoutem
  - `AiSummaryCard.svelte` (plně-šířková karta nahoře)
  - `WidgetCard.svelte` (sdílený shell pro 3 widgety)
  - `WidgetRow.svelte` (jeden řádek s doc-state pruhem)
  - `api/dashboard.js` (fetch wrapper)
- **Ikony**: `iconDashboard` (`faGauge`), `iconRobot` (`faRobot`),
  `iconRefresh` (`faRotateRight`), záznamy v `iconMap`
- **i18n**: ~12 nových klíčů v `cs.js`/`en.js`, lint pass
- **Dokumentace**: nový `docs/dashboard.md`, update `docs/frontend.md`,
  `docs/architecture.md`, `docs/README.md`, `CLAUDE.md`

### Mimo rozsah

- **Modulární widget systém** — moduly registrují vlastní widgety v `module.jsonc`.
  Fáze 2; pro MVP hardcoded sada tří widgetů v `DashboardController`.
- **Skutečná AI integrace** — `AiSummaryCard` je v této fázi statický
  (text složený z countů, ikona robota). API endpoint pro AI shrnutí, prompt
  engineering, asynchronní fetch — vše až s reálnou AI vrstvou.
- **Interaktivní akce přímo z widgetů** (snooze alertu, označení úkolu jako
  hotový) — klik na řádek vždy jen otevře cílový viewer s vybraným záznamem.
  Veškeré operace zůstávají v dedikovaných viewerech.
- **Auto-refresh / polling** — pouze fetch při mountu + manuální tlačítko.
- **Personalizace** (uživatel skrývá/řadí widgety) — fáze 2+.
- **Filtr „Moje úkoly" podle přihlášeného uživatele** — `tasks.core` zatím
  nemá assignee oddělené od autora. Widget zobrazuje **všechny aktivní úkoly**;
  pojmenování widgetu v MVP „Aktivní úkoly" (UI label), interní ID `tasks`.
- **Mobile-first responzivita** — grid používá `auto-fit minmax(280px, 1fr)`,
  na úzkém viewportu se přirozeně skládá do jednoho sloupce. Žádné speciální
  mobilní UI.
- **Permissions** — dashboard vidí každý přihlášený uživatel, žádné filtrování
  widgetů podle práv.

## Architektura

```
                  GET /_ui/dashboard
                         │
                         ▼
              ┌──────────────────────┐
              │  DashboardController │
              │  ::index()           │
              └──────────┬───────────┘
                         │
        ┌────────────────┼────────────────┐
        ▼                ▼                ▼
   AlertsViewer    IncomingMessages    TasksViewer
   selectRows()    Viewer              selectRows()
   renderRow()     selectRows()        renderRow()
                   renderRow()
        │                │                │
        └────────────────┼────────────────┘
                         │
                         ▼  agregovaný JSON
                         ▼
               Dashboard.svelte
                         │
        ┌────────────────┼────────────────┐
        ▼                ▼                ▼
  AiSummaryCard      WidgetCard       WidgetCard
                     (alerts)         (mail / tasks)
                         │
                         ▼
                    WidgetRow × N
```

Re-use existujících viewerů má dvě výhody: (a) `renderRow()` už řeší
`stateStyle`, ikony, `t1`/`t2`/`t3` formátování; (b) když se viewer
změní, dashboard widget se aktualizuje zdarma. Cena: drobná duplikace
COUNT SQL pro celkové počty (viz Backend níže).

## API kontrakt

### `GET /_ui/dashboard`

**Auth**: Bearer token (stejně jako ostatní `_ui/*` endpointy).

**Request**: žádné parametry.

**Response — happy path**:

```json
{
  "success": true,
  "data": {
    "generatedAt": "2026-05-19T08:42:11Z",
    "summary": {
      "alertsCount": 2,
      "incomingMailCount": 8,
      "tasksCount": 5
    },
    "widgets": [
      {
        "id": "alerts",
        "type": "alerts",
        "title": "Upozornění",
        "icon": "alert",
        "count": 2,
        "items": [
          {
            "id": 17,
            "stateStyle": "edit",
            "title": "Chybí vlastní Osoba",
            "subtitle": "warning",
            "icon": "alert",
            "action": {
              "kind": "open_viewer",
              "viewerId": "core.alerts.alerts",
              "recordId": 17
            }
          }
        ],
        "openAllAction": {
          "viewerId": "core.alerts.alerts"
        }
      },
      {
        "id": "incoming_mail",
        "type": "mail",
        "title": "Aktuální došlá pošta",
        "icon": "mail",
        "count": 8,
        "items": [ /* posledních ~7 podle renderRow() */ ],
        "openAllAction": { "viewerId": "core.mail.incoming" }
      },
      {
        "id": "tasks",
        "type": "tasks",
        "title": "Aktivní úkoly",
        "icon": "list-check",
        "count": 5,
        "items": [ /* aktivní úkoly podle renderRow() */ ],
        "openAllAction": { "viewerId": "tasks.core" }
      }
    ]
  }
}
```

**Pole `summary`** — duplikuje `count` z widgetů. Důvod: `AiSummaryCard`
ho čte centrálně bez nutnosti iterovat widgety. Když je některý widget
vypnutý (mimo MVP, ale technicky to může nastat), `summary` má vždy
všechny tři klíče.

**Pole `count`** — celkový počet otevřených záznamů (Active stav), může
být **větší než `items.length`**. Items jsou ořezané server-side
na max 7. Frontend zobrazuje `count`, ne `items.length`.

**Pole `items[].title`** — string. Extrahuje se z `renderRow()` výstupu
pole `t1`:
- `t1` jako string → použít přímo
- `t1` jako `{text, class?}` → `text`
- `t1` jako pole objektů → konkatenace `text` hodnot oddělená mezerou
- chybějící / prázdné → fallback `"#{id}"`

**Pole `items[].subtitle`** — string nebo null. Z `renderRow()` výstupu
pole `t2`:
- stejná pravidla jako `t1`, ale pole objektů konkatenovat oddělené
  ` · ` (mezera + bullet + mezera) pro vizuální čitelnost
- chybějící → null

**Pole `items[].icon`** — string nebo null. Z `renderRow()` výstupu pole `icon`,
fallback na widget-level `icon` (= default z `module.jsonc`).

**Pole `items[].stateStyle`** — string z `renderRow()` výstupu (bez doc-state
prefixu, např. `"edit"`, ne `"docState_edit"`). Frontend si přidá prefix.

**Pole `items[].action.kind`** — pro MVP vždy `"open_viewer"`. Frontend
vyhledá viewer položku v sidebar navigation podle `viewerId`, `navigate()`
na ni, a pak nastaví vybraný záznam podle `recordId` (pokud sidebar item
nenajde, fallback — jen toast „Nelze otevřít"; nemělo by se stávat).

**Response — error**: standardní envelope `{ success: false, error: {...} }`
(stejně jako jiné endpointy).

## Změny souborů — backend

### 1. `src/Api/Router.php` — registrace routy

V seznamu speciálních `_ui/*` rout přidat:

```php
['GET', '/_ui/dashboard', DashboardController::class, 'index'],
```

(Přesný syntax — sleduj jak jsou registrované existující `_ui/navigation`,
`_ui/viewer/*`, atd.)

### 2. `src/Api/Controller/DashboardController.php` — **nový**

```php
<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Http\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Viewer\ViewerRegistry;
use Shipard\Core\Viewer\ViewerLoader;
use Shipard\Core\I18n\TextTranslator;   // pokud existuje; jinak hardcoded labely

final class DashboardController
{
    private const ITEMS_PER_WIDGET = 7;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ConfigRuntime $config,
        private readonly ViewerRegistry $viewerRegistry,
        private readonly ViewerLoader $viewerLoader,
        private readonly string $language,
    ) {}

    public function index(): Response
    {
        $alerts = $this->buildAlertsWidget();
        $mail   = $this->buildMailWidget();
        $tasks  = $this->buildTasksWidget();

        return Response::success([
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'summary' => [
                'alertsCount'       => $alerts['count'],
                'incomingMailCount' => $mail['count'],
                'tasksCount'        => $tasks['count'],
            ],
            'widgets' => [$alerts, $mail, $tasks],
        ]);
    }

    private function buildAlertsWidget(): array { /* … */ }
    private function buildMailWidget(): array   { /* … */ }
    private function buildTasksWidget(): array  { /* … */ }

    private function renderRowToWidgetItem(
        array $rendered,
        string $viewerId,
        ?string $widgetIcon,
    ): array {
        return [
            'id'         => (int) ($rendered['id'] ?? 0),
            'stateStyle' => $rendered['stateStyle'] ?? null,
            'title'      => $this->flattenTextField($rendered['t1'] ?? null, ' ')
                            ?: '#' . ($rendered['id'] ?? '?'),
            'subtitle'   => $this->flattenTextField($rendered['t2'] ?? null, ' · '),
            'icon'       => $rendered['icon'] ?? $widgetIcon,
            'action'     => [
                'kind'     => 'open_viewer',
                'viewerId' => $viewerId,
                'recordId' => (int) ($rendered['id'] ?? 0),
            ],
        ];
    }

    /**
     * Sploští `t1`/`t2` z renderRow() do jednoho stringu.
     * Akceptuje: null, string, {text,class}, list<{text,class}>.
     */
    private function flattenTextField(mixed $value, string $separator): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_string($value)) return $value;
        if (is_array($value) && isset($value['text'])) return (string) $value['text'];
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_string($item)) { $parts[] = $item; continue; }
                if (is_array($item) && isset($item['text'])) $parts[] = (string) $item['text'];
            }
            return $parts ? implode($separator, $parts) : null;
        }
        return null;
    }
}
```

**Klíčové detaily implementace** (každá `build*Widget()` metoda):

1. **Instancování vieweru**: stejně jako v `ViewerController::rows()`:
   ```php
   $def = $this->viewerRegistry->get('core.alerts.alerts');
   $viewer = $this->viewerLoader->instantiate($def, $this->db, $this->config, $this->language);
   ```

2. **Načtení dat**: zavolat `selectRows()` se stránkou 0 a filtrem `viewGroup`,
   který filtruje na otevřené záznamy:
   ```php
   $rawRows = $viewer->selectRows(search: null, filters: ['viewGroup' => 'active'], pageNumber: 0);
   ```

   **POZOR**: každý viewer má vlastní `docStatesCfgItem`, kde je definovaná
   `viewGroups` mapa. Hodnota `'active'` musí existovat:
   - `core.alerts.alerts` — používá `core.alerts.alertStates`. `viewGroup=active`
     filtruje na `alert_state IN (10, 20)` (Active + Snoozed). **Pro dashboard
     chceme jen Active (10)**, takže buď specifický filtr `filters: ['alert_state' => 10]`,
     nebo přídavný filtr nad active. **Rozhodnutí**: použij raw filter
     `['alert_state' => 10]` pro Alerts widget (snoozed = uživatel záměrně
     potlačil, dashboard ho neukazuje).
   - `core.mail.incoming` — používá `core.mail.docStatesIncoming`. `viewGroup=active`
     by mělo zahrnovat „nepřečtené" + „rozpracované" stavy.
   - `tasks.core` — `viewGroup=active` = „aktivní úkoly".

   Pokud konkrétní viewer nemá `viewGroup=active` definovaný (zjistíš v
   `modules/*/config/docStates*.jsonc`), zastav se a nahlas — buď to znamená
   přidat hodnotu do cfgItem, nebo přizpůsobit filtr.

3. **Limit items**:
   ```php
   $rawRows = array_slice($rawRows, 0, self::ITEMS_PER_WIDGET);
   ```
   `selectRows()` vrací pageSize+1 (kvůli detekci hasMore — viz frontend.md
   sekce 7), takže slice na 7 je bezpečný i kdyby pageSize byl menší.

4. **renderRow + transformace**:
   ```php
   $items = [];
   foreach ($rawRows as $rawRow) {
       $rendered = $viewer->renderRow($rawRow);
       $items[] = $this->renderRowToWidgetItem($rendered, 'core.alerts.alerts', 'alert');
   }
   ```

5. **COUNT — celkový počet otevřených**:
   ```php
   // Alerts: COUNT z core_alerts_alerts WHERE alert_state = 10
   $count = (int) $this->db->fetchSingle(
       'SELECT COUNT(*) FROM [core_alerts_alerts] WHERE [alert_state] = %i',
       10,
   );

   // Mail: COUNT z core_mail_incoming_messages podle 'active' viewGroup stavů.
   // Stavy „active" jsou definované v core.mail.docStatesIncoming → states[]
   // s isClosed=false. Pro MVP hardcoded SQL podle aktuálních hodnot:
   $activeMailStates = $this->resolveActiveDocStates('core.mail.docStatesIncoming');
   $count = (int) $this->db->fetchSingle(
       'SELECT COUNT(*) FROM [core_mail_incoming_messages] WHERE [docState] IN %in',
       $activeMailStates,
   );

   // Tasks: analogicky pro tasks.core.docStatesTasks
   ```

   **Helper** `resolveActiveDocStates(string $cfgItemId): array`:
   přečte `$this->config->cfgItem($cfgItemId)`, vrátí pole hodnot states[]
   kde `isClosed === false` (nebo ekvivalent — sleduj jak je definované
   v `docs/doc-states.md` a konkrétních `docStates*.jsonc` souborech).
   Pokud cfgItem neexistuje, vrátí prázdné pole a count bude 0 (graceful
   fallback, viz `docs/i18n` přístup).

6. **Návratová struktura widgetu**:
   ```php
   return [
       'id' => 'alerts',
       'type' => 'alerts',
       'title' => $this->language === 'cs' ? 'Upozornění' : 'Alerts',
       'icon' => 'alert',
       'count' => $count,
       'items' => $items,
       'openAllAction' => ['viewerId' => 'core.alerts.alerts'],
   ];
   ```

   **Lokalizace titulků**: pro MVP přímý language switch v PHP (analog
   s tím, jak to dělá `MissingOwnPersonCheck` v `docs/alerts.md` sekce 4).
   Když existuje generický i18n helper v PHP (zkontroluj
   `src/Core/I18n/*`), použij ho; jinak hardcoded switch.

### 3. `src/Api/Controller/NavigationController.php` — Dashboard položka

V metodě, která sestavuje navigation tree (sleduj existující kód), na začátek
výsledného pole prependovat:

```php
$dashboardItem = [
    'id'    => 'dashboard',
    'type'  => 'dashboard',
    'label' => $this->language === 'cs' ? 'Dashboard' : 'Dashboard',
    'icon'  => 'dashboard',
    // žádné 'children' — leaf item
];

array_unshift($result, $dashboardItem);
```

**Pozor**: pokud Sidebar.svelte předpokládá, že root-level položky mají
vždy `children` pole (skupiny), může být potřeba úprava. Sleduj `Sidebar.svelte`
a podle toho buď přidej `children: []`, nebo uprav rendering ve frontendu
(varianta 2 je čistší — leaf item bez children je sémanticky správně).

### 4. Dependency injection (kde to systém potřebuje)

`DashboardController` se konstruuje stejně jako jiné kontrolery v
`public/index.php` (sleduj jak je registrovaný `ViewerController` — má
podobnou sadu závislostí: `db`, `config`, viewer registry/loader, language).

## Změny souborů — frontend

### 5. `frontend/src/icons.js` — nové ikony

```js
import {
  faGauge,        // dashboard
  faRobot,        // AI summary
  faRotateRight,  // refresh button
} from '@fortawesome/free-solid-svg-icons';

export const iconDashboard = faGauge;
export const iconRobot = faRobot;
export const iconRefresh = faRotateRight;

// v iconMap:
const iconMap = {
  // ... existing ...
  'dashboard': iconDashboard,
  'robot': iconRobot,
  'refresh': iconRefresh,
};
```

### 6. `frontend/src/api/dashboard.js` — **nový**

```js
import { apiClient } from './client.js';

/**
 * Fetch dashboard data — agregát alerts/mail/tasks + AI summary counts.
 * Returns { generatedAt, summary, widgets } or null on failure.
 */
export async function fetchDashboard() {
  const res = await apiClient.get('/_ui/dashboard');
  return res?.success ? res.data : null;
}
```

### 7. `frontend/src/components/dashboard/Dashboard.svelte` — **nový**

Top-level komponenta. Fetch při mountu, grid layout, refresh tlačítko nahoře.

```svelte
<script>
  import { onMount } from 'svelte';
  import { t } from '../../i18n/index.js';
  import { fetchDashboard } from '../../api/dashboard.js';
  import { iconRefresh } from '../../icons.js';
  import Icon from '../ui/Icon.svelte';
  import Button from '../ui/Button.svelte';
  import AiSummaryCard from './AiSummaryCard.svelte';
  import WidgetCard from './WidgetCard.svelte';

  let data = $state(null);
  let loading = $state(true);
  let error = $state(null);

  async function load() {
    loading = true;
    error = null;
    try {
      const result = await fetchDashboard();
      if (result) {
        data = result;
      } else {
        error = t('dashboard.error.failed');
      }
    } catch (err) {
      error = t('dashboard.error.failed');
      console.error('Dashboard load failed:', err);
    } finally {
      loading = false;
    }
  }

  onMount(load);
</script>

<div class="shpd-dashboard">
  <header class="shpd-dashboard__header">
    <h1 class="shpd-dashboard__title">{t('dashboard.title')}</h1>
    <Button
      variant="ghost"
      icon={iconRefresh}
      label={t('dashboard.refresh')}
      onclick={load}
      disabled={loading}
    />
  </header>

  {#if loading && !data}
    <div class="shpd-dashboard__loading">{t('common.loading')}</div>
  {:else if error && !data}
    <div class="shpd-dashboard__error">{error}</div>
  {:else if data}
    <AiSummaryCard summary={data.summary} />

    <div class="shpd-dashboard__widgets">
      {#each data.widgets as widget (widget.id)}
        <WidgetCard {widget} />
      {/each}
    </div>
  {/if}
</div>

<style>
  .shpd-dashboard {
    padding: var(--shpd-space-lg);
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-lg);
  }

  .shpd-dashboard__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .shpd-dashboard__title {
    margin: 0;
    font-size: var(--shpd-font-size-xl);
    color: var(--shpd-color-text);
  }

  .shpd-dashboard__widgets {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: var(--shpd-space-md);
  }

  .shpd-dashboard__loading,
  .shpd-dashboard__error {
    padding: var(--shpd-space-xl);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }
</style>
```

### 8. `frontend/src/components/dashboard/AiSummaryCard.svelte` — **nový**

Plně-šířková karta nahoře. Ikona robota, generuje text z `summary` countů.

```svelte
<script>
  import { t } from '../../i18n/index.js';
  import { iconRobot } from '../../icons.js';
  import Icon from '../ui/Icon.svelte';

  let { summary } = $props();

  const summaryText = $derived.by(() => {
    const parts = [];
    if (summary.alertsCount > 0)
      parts.push(t('dashboard.aiSummary.alerts', { count: summary.alertsCount }));
    if (summary.incomingMailCount > 0)
      parts.push(t('dashboard.aiSummary.mail', { count: summary.incomingMailCount }));
    if (summary.tasksCount > 0)
      parts.push(t('dashboard.aiSummary.tasks', { count: summary.tasksCount }));

    if (parts.length === 0) return t('dashboard.aiSummary.empty');
    return t('dashboard.aiSummary.intro') + ' ' + parts.join(', ') + '.';
  });
</script>

<div class="shpd-ai-summary">
  <span class="shpd-ai-summary__icon">
    <Icon icon={iconRobot} size="lg" />
  </span>
  <div class="shpd-ai-summary__body">
    <div class="shpd-ai-summary__title">{t('dashboard.aiSummary.title')}</div>
    <div class="shpd-ai-summary__text">{summaryText}</div>
    <div class="shpd-ai-summary__hint">{t('dashboard.aiSummary.placeholder')}</div>
  </div>
</div>

<style>
  .shpd-ai-summary {
    background: var(--shpd-color-primary-soft);
    border: 1px solid var(--shpd-color-primary-soft-2);
    border-radius: var(--shpd-radius-md);
    padding: var(--shpd-space-md);
    display: flex;
    gap: var(--shpd-space-md);
    align-items: flex-start;
  }

  .shpd-ai-summary__icon {
    color: var(--shpd-color-primary);
    flex-shrink: 0;
  }

  .shpd-ai-summary__body { flex: 1; }

  .shpd-ai-summary__title {
    font-weight: 600;
    color: var(--shpd-color-primary);
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-ai-summary__text {
    color: var(--shpd-color-text);
    line-height: 1.5;
  }

  .shpd-ai-summary__hint {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    margin-top: var(--shpd-space-xs);
    font-style: italic;
  }
</style>
```

### 9. `frontend/src/components/dashboard/WidgetCard.svelte` — **nový**

Sdílený shell pro 3 widgety (alerts, mail, tasks).

```svelte
<script>
  import { t } from '../../i18n/index.js';
  import { resolveIcon } from '../../icons.js';
  import Icon from '../ui/Icon.svelte';
  import WidgetRow from './WidgetRow.svelte';
  import { navigationStore } from '../../stores/navigation.svelte.js';

  let { widget } = $props();

  function handleRowClick(item) {
    // Naviguj na cílový viewer a vyber záznam
    navigationStore.navigateToViewer(item.action.viewerId, item.action.recordId);
  }

  function handleOpenAll() {
    navigationStore.navigateToViewer(widget.openAllAction.viewerId);
  }

  // Empty state text podle typu widgetu
  const emptyKey = $derived(`dashboard.widget.${widget.type}.empty`);
</script>

<section class="shpd-widget-card">
  <header class="shpd-widget-card__header">
    <span class="shpd-widget-card__icon">
      <Icon icon={resolveIcon(widget.icon)} size="md" />
    </span>
    <h2 class="shpd-widget-card__title">{widget.title}</h2>
    <span class="shpd-widget-card__count">{widget.count}</span>
  </header>

  <div class="shpd-widget-card__body">
    {#if widget.items.length === 0}
      <div class="shpd-widget-card__empty">{t(emptyKey)}</div>
    {:else}
      <ul class="shpd-widget-card__list">
        {#each widget.items as item (item.id)}
          <WidgetRow {item} onclick={() => handleRowClick(item)} />
        {/each}
      </ul>
    {/if}
  </div>

  {#if widget.count > 0}
    <footer class="shpd-widget-card__footer">
      <button class="shpd-widget-card__open-all" onclick={handleOpenAll}>
        {t('dashboard.openAll')} →
      </button>
    </footer>
  {/if}
</section>

<style>
  .shpd-widget-card {
    background: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    display: flex;
    flex-direction: column;
    min-height: 200px;
  }

  .shpd-widget-card__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-widget-card__icon { color: var(--shpd-color-text-secondary); }

  .shpd-widget-card__title {
    flex: 1;
    margin: 0;
    font-size: var(--shpd-font-size-md);
    color: var(--shpd-color-text);
  }

  .shpd-widget-card__count {
    background: var(--shpd-color-primary-soft);
    color: var(--shpd-color-primary);
    padding: 2px 8px;
    border-radius: 999px;
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
  }

  .shpd-widget-card__body {
    flex: 1;
    overflow: hidden;
  }

  .shpd-widget-card__list {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .shpd-widget-card__empty {
    padding: var(--shpd-space-lg);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    font-style: italic;
  }

  .shpd-widget-card__footer {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-top: 1px solid var(--shpd-color-border);
  }

  .shpd-widget-card__open-all {
    background: none;
    border: none;
    color: var(--shpd-color-primary);
    cursor: pointer;
    padding: 0;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-widget-card__open-all:hover {
    text-decoration: underline;
  }
</style>
```

### 10. `frontend/src/components/dashboard/WidgetRow.svelte` — **nový**

Jeden řádek se `stateStyle` pruhem (4px, kompaktnější než 6px ve vieweru),
ikonou, titulkem a subtitle.

```svelte
<script>
  import { resolveIcon } from '../../icons.js';
  import Icon from '../ui/Icon.svelte';

  let { item, onclick } = $props();

  const stateClass = $derived(item.stateStyle ? `docState_${item.stateStyle}` : '');
</script>

<li class="shpd-widget-row {stateClass}" onclick={onclick} role="button" tabindex="0">
  <span class="shpd-widget-row__bar"></span>
  {#if item.icon}
    <span class="shpd-widget-row__icon">
      <Icon icon={resolveIcon(item.icon)} size="sm" />
    </span>
  {/if}
  <div class="shpd-widget-row__body">
    <div class="shpd-widget-row__title">{item.title}</div>
    {#if item.subtitle}
      <div class="shpd-widget-row__subtitle">{item.subtitle}</div>
    {/if}
  </div>
</li>

<style>
  .shpd-widget-row {
    /* CSS proměnná --shpd-row-bar — nastavují doc-state třídy v base.css */
    --shpd-row-bar: transparent;

    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    cursor: pointer;
    border-bottom: 1px solid var(--shpd-color-border);
    position: relative;
  }

  .shpd-widget-row:last-child { border-bottom: none; }

  .shpd-widget-row:hover { background: var(--shpd-color-bg-secondary); }

  .shpd-widget-row__bar {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: var(--shpd-row-bar);
  }

  .shpd-widget-row__icon {
    color: var(--shpd-color-text-secondary);
    flex-shrink: 0;
  }

  .shpd-widget-row__body {
    flex: 1;
    min-width: 0;
  }

  .shpd-widget-row__title {
    color: var(--shpd-color-text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-widget-row__subtitle {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
</style>
```

**Důležité k `docState_*` CSS**: třídy `docState_edit`, `docState_concept`, …
v `base.css` (nebo kde dnes nastavují `--shpd-row-bar` pro `ViewerRow.svelte`)
nastavují `--shpd-row-bar` CSS proměnnou. `WidgetRow` ji konzumuje přes
`.shpd-widget-row__bar { background: var(--shpd-row-bar) }`. Pokud
existující `docState_*` selektory jsou v `ViewerRow.svelte` scoped, musíme
buď duplikovat (rychlejší) nebo přesunout do `base.css` jako `:global`
(čistější). **Sleduj jak je to dnes a podle toho rozhodni** — preferuj
přesun do `base.css` jako globální (sekce „doc-state utility classes"),
ať se konvence aplikuje napříč komponentami.

### 11. `frontend/src/components/layout/ContentArea.svelte` — větev pro dashboard

Přidat case `'dashboard'` do switch/branch logiky:

```svelte
{#if activeItem?.type === 'dashboard'}
  <Dashboard />
{:else if activeItem?.type === 'table'}
  <TableBrowser table={activeItem.table} />
{:else if activeItem?.type === 'viewer'}
  <Viewer viewerId={activeItem.viewerId} />
{:else}
  <!-- empty state -->
{/if}
```

Import `Dashboard` z `'../dashboard/Dashboard.svelte'`.

### 12. `frontend/src/stores/navigation.svelte.js` — default activeItem + helper

a) **Default po inicializaci**: po načtení navigation tree zkontrolovat, jestli
existuje dashboard položka (top-level item s `type === 'dashboard'`). Pokud
ano a `activeItem` ještě není nastavený (čerstvý login), nastavit ho na
dashboard.

b) **Helper `navigateToViewer(viewerId, recordId?)`**: volá ho `WidgetCard`
když uživatel klikne na řádek. Najde sidebar item s daným `viewerId`,
zavolá `navigate(item)`, a pokud `recordId` je předaný, nastaví `pendingRecordId`
v navigation store (Viewer.svelte si ho přečte při mountu a vybere ten záznam).

Pokud `pendingRecordId` mechanismus dnes neexistuje, **implementuj minimální**:
- store má `$state` proměnnou `pendingRecordId`
- `navigate(item)` ji resetne na null
- `navigateToViewer(viewerId, recordId)` ji nastaví po `navigate()`
- `Viewer.svelte` v `$effect` při mountu: pokud `pendingRecordId` matchuje
  načtený `viewerId`, vybere ten záznam a vynuluje `pendingRecordId`

Pokud cesta `pendingRecordId` přidává moc komplexity, **fallback**: klik na
řádek otevře jen viewer (bez výběru záznamu). Lepší jednoduchost než
buggy předvýběr.

## Empty stavy

| Widget | `count = 0` | Text |
|---|---|---|
| Alerts | žádná aktivní upozornění | „Vše v pořádku ✓" |
| Mail | žádná nová pošta | „Žádné nové zprávy" |
| Tasks | žádné aktivní úkoly | „Žádné aktivní úkoly" |
| AI shrnutí | všechny counts = 0 | „Vše je v klidu, dnes nic nečeká." |

V češtině i angličtině, viz i18n klíče níže.

## i18n klíče

Přidat do **`frontend/src/i18n/cs.js`** a **`frontend/src/i18n/en.js`** (paritu
ověř přes `npm run check:i18n`).

```js
// cs.js
'dashboard.title': 'Dashboard',
'dashboard.refresh': 'Obnovit',
'dashboard.openAll': 'Otevřít všechny',
'dashboard.error.failed': 'Načtení dashboardu selhalo.',
'dashboard.widget.alerts.empty': 'Vše v pořádku ✓',
'dashboard.widget.mail.empty': 'Žádné nové zprávy',
'dashboard.widget.tasks.empty': 'Žádné aktivní úkoly',
'dashboard.aiSummary.title': 'Dnešní shrnutí',
'dashboard.aiSummary.intro': 'Aktuálně máte:',
'dashboard.aiSummary.empty': 'Vše je v klidu, dnes nic nečeká.',
'dashboard.aiSummary.placeholder': 'AI asistent — připravujeme; zatím počítám z dat ostatních widgetů.',
'dashboard.aiSummary.alerts': '{count, plural, one {# upozornění} few {# upozornění} many {# upozornění} other {# upozornění}}',
'dashboard.aiSummary.mail': '{count, plural, one {# nová zpráva} few {# nové zprávy} many {# nových zpráv} other {# nových zpráv}}',
'dashboard.aiSummary.tasks': '{count, plural, one {# aktivní úkol} few {# aktivní úkoly} many {# aktivních úkolů} other {# aktivních úkolů}}',

// en.js
'dashboard.title': 'Dashboard',
'dashboard.refresh': 'Refresh',
'dashboard.openAll': 'Open all',
'dashboard.error.failed': 'Failed to load dashboard.',
'dashboard.widget.alerts.empty': 'All clear ✓',
'dashboard.widget.mail.empty': 'No new messages',
'dashboard.widget.tasks.empty': 'No active tasks',
'dashboard.aiSummary.title': 'Today\'s summary',
'dashboard.aiSummary.intro': 'You currently have:',
'dashboard.aiSummary.empty': 'All clear — nothing pending today.',
'dashboard.aiSummary.placeholder': 'AI assistant — coming soon; for now I aggregate counts from the other widgets.',
'dashboard.aiSummary.alerts': '{count, plural, one {# alert} other {# alerts}}',
'dashboard.aiSummary.mail': '{count, plural, one {# new message} other {# new messages}}',
'dashboard.aiSummary.tasks': '{count, plural, one {# active task} other {# active tasks}}',
```

## Testy

### Backend — `tests/Unit/Api/Controller/DashboardControllerTest.php`

- Happy path: instancovat controller s mockovanými viewery (vracejí 2 raw
  rows), mockovaná DB COUNT vrátí 5 → response obsahuje 3 widgety, každý
  s `items.length === 2` a `count === 5`, `summary` agreguje.
- Empty path: viewer vrátí 0 rows, COUNT vrátí 0 → response obsahuje 3 widgety
  s prázdnými `items` a `count === 0`, `summary` všechny 0.
- `renderRowToWidgetItem` (private — pokud chceš testovat přes reflexi, nebo
  vystav jako `@internal` public): různé tvary `t1`/`t2` se splošťují správně.
- `flattenTextField`: null, string, `{text,class}`, list — všechno cesty.

### Backend — integrační test

Pokud `tests/Integration/` existuje s běžící testovací DB, přidej smoke test
celého `GET /_ui/dashboard` proti seedovaným datům. Pokud ne, jen unit testy.

### Frontend — manuální smoke test

V dev DS po `npm run build`:

1. Login → po loadu se zobrazí Dashboard jako výchozí pohled.
2. AI Summary banner nahoře, ikona robota, text obsahuje aktuální počty.
3. 3 widgety v gridu pod ním. Každý má header s ikonou, titulkem, počítadlem.
4. Řádky widgetu mají doc-state pruh vlevo (4px, barva podle stavu).
5. Klik na řádek → naviguje na cílový viewer, ten záznam je vybraný
   (pokud `pendingRecordId` mechanismus funguje; jinak jen otevře viewer).
6. Klik na „Otevřít všechny →" → naviguje na plný viewer bez výběru.
7. Tlačítko Refresh v hlavičce → re-fetch, počty se aktualizují.
8. Prázdné stavy: vytvoř test scénář (vyřeš všechna aktivní upozornění,
   smaž poštu) → widgety zobrazí empty state texty.
9. Sidebar má „Dashboard" jako první root-level položku s ikonou.
10. Klik na jinou položku v sidebaru → activeItem se přepne, Dashboard
    zmizí, nový obsah se vykreslí. Klik zpět na Dashboard → vrátí se.
11. Přepnutí jazyka na EN → všechny texty anglicky, plurály správně.

## Dokumentace

### Nový `docs/dashboard.md`

Obsah:

1. **Přehled** — co dashboard je, kdy ho uživatel vidí, principy (přehled,
   ne přístupový bod)
2. **Architektura** — diagram (text), tok dat, re-use vieweru
3. **API kontrakt** — kompletní popis `GET /_ui/dashboard` (request, response,
   typy polí, edge cases)
4. **Widgety v MVP** — popis tří widgetů, jejich data source, doc-state
   konvence
5. **AI shrnutí** — co dělá v MVP, jak vypadá interface pro pozdější
   integraci skutečné AI
6. **Empty stavy a refresh strategie**
7. **Frontend komponenty** — diagram, popis souborů (`Dashboard`,
   `AiSummaryCard`, `WidgetCard`, `WidgetRow`)
8. **Budoucí rozšíření** (fáze 2+) — modulární widget systém přes
   `module.jsonc`, personalizace, AI integrace, interaktivní akce

### Update `docs/frontend.md`

Nová sekce **7.5 Dashboard** mezi sekcí 7 (Viewer systém) a 8 (UI API endpointy).
Krátký odkaz na `docs/dashboard.md` plus shrnutí struktury frontend komponent.

### Update `docs/architecture.md`

V tabulce kontrolerů (sekce 2.4 Kontrolery) přidat:

```
| `DashboardController` | `index` → agregovaný `/_ui/dashboard` (alerts, mail, tasks counts + items). Re-use existujících viewerů. |
```

### Update `docs/README.md`

V hlavní tabulce dokumentů přidat řádek:

```
| `dashboard.md` | Dashboard — home obrazovka, widget systém, API kontrakt, AI shrnutí |
```

### Update `CLAUDE.md`

V tabulce „Dokumentace" přidat řádek pro `dashboard.md`. V sekci „Frontend"
přidat krátkou zmínku:

```
### Frontend — Dashboard

- Home obrazovka aplikace, výchozí po loginu (`type: 'dashboard'` v navigaci)
- `GET /_ui/dashboard` vrací agregát alerts/mail/tasks z existujících viewerů
- AI shrnutí karta je v MVP statická (počty z widgetů, ikona robota);
  rozhraní připravené na pozdější AI integraci
- Modulární widget systém přes `module.jsonc` je out of scope MVP — fáze 2
- Detaily: `docs/dashboard.md`
```

## Doporučené pořadí

1. **Backend — DashboardController + routa** (kroky 1–4). Verifikace
   přes `curl` proti běžícímu dev DS.
2. **Backend — testy** (DashboardControllerTest). `vendor/bin/phpunit 2>&1`.
3. **Navigace — NavigationController + ContentArea + navigation store**
   (kroky 3, 11, 12). Verifikace: build prochází, login zobrazí prázdnou
   dashboard obrazovku s ikonou v sidebaru.
4. **Ikony + i18n** (kroky 5 + i18n sekce). `npm run check:i18n` musí projít.
5. **Frontend komponenty** (kroky 6–10). Verifikace: vidíš data, klikání funguje.
6. **`pendingRecordId` mechanismus** (krok 12b) — pokud zvládneš jednoduše;
   jinak fallback bez výběru záznamu (zdokumentuj v rozhodnutích).
7. **Manuální smoke test** (všech 11 scénářů).
8. **Dokumentace** — `docs/dashboard.md`, update `frontend.md`,
   `architecture.md`, `README.md`, `CLAUDE.md`.

## Konvence

- **Jazyk**: UI texty v `t()`, server-side titulky widgetů přes language
  switch v PHP (analog s tím, jak to dělá `MissingOwnPersonCheck` v
  `docs/alerts.md`). Kód a komentáře v PHP anglicky, ve Svelte mix
  (česky pro business logiku, anglicky pro low-level).
- **PHP 8.5** strict_types, readonly properties, `final` u konkrétních tříd.
- **Snake_case na drátě** **NE** — sleduj existující `_ui/*` endpointy,
  které používají camelCase (`generatedAt`, `viewerId`, `openAllAction`).
  Konzistence s existujícím API.
- **Svelte 5 runes** (`$state`, `$derived`, `$effect`, `$props`).
- **CSS tokeny** — žádné hardcoded hex hodnoty, vše přes `var(--shpd-...)`.
- Před patchováním Svelte komponent **přečíst celý soubor** — patch_file
  vyžaduje přesné whitespace.
- **Build verifikace po každém logickém kroku**:
  - `cd frontend && npm run build 2>&1` pro frontend
  - `vendor/bin/phpunit 2>&1` pro backend

## Rozhodnutí k designu (potvrzená)

- ✓ **Dashboard jako root-level položka v sidebaru, výchozí po loginu**.
  Ne kliknutím na logo, ne pevně zafixovaná „home" mimo sidebar — je to
  prostě první nav item, konzistentní s ostatními.
- ✓ **Jeden agregovaný endpoint `GET /_ui/dashboard`**, ne separátní endpointy
  per widget. Atomická aktualizace, méně network round-trips, snadné
  paralelizovat fetch na backendu.
- ✓ **Re-use existujících viewer tříd přes `selectRows()` + `renderRow()`**,
  ne přímé SQL ani nové dashboard-specific metody na vieweru. Drobná
  duplikace COUNT SQL je akceptovaná cena za jednotnou render logiku.
- ✓ **MVP: hardcoded sada tří widgetů**, modularita až ve fázi 2.
  Nezavádět widget abstrakci dřív, než budeme mít víc než jeden modul,
  který chce widget poslat.
- ✓ **AI shrnutí v MVP: statická karta, ikona robota, text z countů**.
  Žádný placeholder „Připravujeme" — chceme, aby měla od první chvíle
  reálnou hodnotu, byť ne AI-generovanou.
- ✓ **Žádné interaktivní akce přímo z widgetu** — klik na řádek otevře
  cílový viewer s vybraným záznamem, snooze/dismiss/edit zůstávají
  v dedikovaných viewerech. Méně duplikace, jednotné UX.
- ✓ **Fetch při mountu + manuální refresh tlačítko, žádný polling**.
  Shipard je interní nástroj, ne real-time monitoring.
- ✓ **Alerts widget zobrazuje jen Active (state=10), ne Snoozed**.
  Snoozed = uživatel záměrně potlačil; v dashboardu by je viděl znovu
  hned po snooze, což je matoucí.
- ✓ **Mail/Tasks widget zobrazují všechny aktivní záznamy, ne per-user
  filtr**. Tasks nemá assignee separátně; pro MVP tedy „Aktivní úkoly".
  Per-user filtr v navazujícím tasku, až bude assignee oddělené.
- ✓ **Limit 7 items per widget server-side**. Slice v `DashboardController`,
  ne na frontendu. Šetří payload.
- ✓ **`count` může být větší než `items.length`** — UI zobrazuje
  `count`, ne items.length. „Otevřít všechny →" je always-on pro
  `count > 0`.
- ✓ **WidgetRow má 4px doc-state pruh** (kompaktnější než 6px ve
  ViewerRow). Dashboard widget je hustší, větší pruh by byl rušivý.
- ✓ **`docState_*` CSS třídy globálně v `base.css`** (pokud dnes nejsou),
  aby je mohly konzumovat ViewerRow i WidgetRow bez duplikace.
- ✓ **Empty stavy mají hodnotu, ne chybovou hlášku**: „Vše v pořádku ✓",
  „Žádné nové zprávy", „Vše je v klidu". Když je všechno hotovo, dashboard
  pořád informuje.
- ✓ **`pendingRecordId` mechanismus pro výběr záznamu po navigaci** —
  pokud zvládne implementér přidat čistě (přidat $state proměnnou do
  navigation store + 1 `$effect` v Vieweru). Když přidává moc komplexity,
  fallback: klik jen otevře viewer bez výběru. Lepší jednoduchost.
- ✓ **API odpovědi v camelCase** (`generatedAt`, `viewerId`, `openAllAction`),
  konzistentní s existujícími `_ui/*` endpointy. Ne snake_case.

## Mimo rozsah

- Modulární widget systém přes `module.jsonc.dashboardWidgets[]` — fáze 2
- Skutečné AI volání pro `AiSummaryCard` — počká na AI integraci jako celek
- Personalizace dashboardu (uživatel skrývá/řadí widgety) — fáze 3+
- Drag & drop reorder widgetů — nikdy v MVP, možná v personalizační fázi
- Auto-refresh / WebSocket push / SSE — pokud bude potřeba, samostatný task
- Bulk akce z widgetů (snooze 5 alertů najednou) — out of scope
- Dashboard widgety pro různé role (admin vs běžný uživatel) — počká na
  permission systém
- KPI / grafy / agregované statistiky — Shipard není analytický nástroj,
  dashboard je operační, ne reportní
- Per-DS přizpůsobení dashboardu (různé DS mají různé widgety) — fáze 2+
  modulárního systému to umožní automaticky

## Hotovo když

- [ ] `vendor/bin/phpunit 2>&1` — všechny testy procházejí, včetně nového
  `DashboardControllerTest`
- [ ] `cd frontend && npm run build 2>&1` — build prochází bez chyb
  a warningů
- [ ] `cd frontend && npm run check:i18n` — parita cs/en klíčů
- [ ] `GET /_ui/dashboard` vrací správnou strukturu (manuálně přes curl
  s Bearer tokenem)
- [ ] Login → Dashboard se zobrazí jako výchozí pohled
- [ ] AI Summary banner ukazuje počty (`X upozornění, Y nových zpráv, Z úkolů`)
- [ ] 3 widgety v gridu, každý s headerem (ikona, titulek, počítadlo)
- [ ] Doc-state pruh (4px) vlevo na každém řádku, barva odpovídá `stateStyle`
- [ ] Klik na řádek → otevře cílový viewer (s vybraným záznamem, pokud
  `pendingRecordId` mechanismus funguje)
- [ ] Klik na „Otevřít všechny →" → otevře cílový viewer
- [ ] Refresh tlačítko v hlavičce funguje, počty se aktualizují
- [ ] Empty stavy: prázdné widgety zobrazují konfigurovaný text
- [ ] Sidebar: „Dashboard" jako první root-level položka s `iconDashboard`
- [ ] Klik na jinou položku v sidebaru → Dashboard zmizí, vrátit lze
  klikem na Dashboard položku
- [ ] Přepnutí jazyka cs/en — všechny texty správně, plurály v AI Summary fungují
- [ ] `docs/dashboard.md` napsaný (sekce 1–8 podle Dokumentace výše)
- [ ] `docs/frontend.md` má novou sekci 7.5 Dashboard
- [ ] `docs/architecture.md` má `DashboardController` v tabulce kontrolerů
- [ ] `docs/README.md` zmiňuje `dashboard.md`
- [ ] `CLAUDE.md` má sekci „Frontend — Dashboard"

## Commit strategie

Tři commity logicky oddělené:

1. `feat(dashboard): add backend endpoint and navigation entry`
   — `DashboardController`, routa, `NavigationController` update,
   `ContentArea.svelte` větev, navigation store default + helper, testy.
2. `feat(dashboard): add frontend components and i18n`
   — `Dashboard.svelte`, `AiSummaryCard`, `WidgetCard`, `WidgetRow`,
   `api/dashboard.js`, ikony, i18n klíče, `docState_*` přesun do base.css
   pokud potřeba.
3. `docs(dashboard): add spec, update frontend/architecture docs`
   — `docs/dashboard.md`, update `frontend.md`, `architecture.md`,
   `docs/README.md`, `CLAUDE.md`.
