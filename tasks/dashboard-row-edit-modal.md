# Dashboard — edit z widget řádku (Tasks)

## Status / cíl

Klik na řádek v widgetu **Aktivní úkoly** dnes naviguje na Tasks viewer.
Tento task to změní: klik **otevře `FormDialog`** s úkolem rovnou nad
dashboardem. Uživatel může úkol editovat nebo (typicky) kliknout
**„Hotovo"** v `FormStateBar` — modal se sám zavře (`closeForm: 1`
u stavu 40 v `tasks.core.docStatesTasks`), úkol zmizí z widgetu
(Hotovo má `viewGroup: archive`), uživatel pokračuje s dalším úkolem.

Cíl: méně cmovéu kontextu, rychlejší dokončení, dashboard zůstává
hlavní obrazovkou.

**Pouze Tasks v této fázi.** Alerts a Mail ponechávají původní chování
(klik → navigace na viewer). Důvody:
- **Alerts** mají specifické akce (acknowledge, snooze, dismiss) přes
  vlastní endpointy, ne přes form save. Form modal by se zde míjel
  s doménou.
- **Mail** je převážně read-flow; edit forms pro `core_mail_incoming`
  primárně neexistují a uživatel s poštou typicky chce vidět víc detailu
  najednou (atachmenty, thread).

Pokud se po pár dnech používání ukáže, že u některého z nich chybí
i form modal, přidáme — ale teď start small.

## Návaznost

- **`dashboard-phase1.md`** — hlavní dashboard MVP. Tento task na něm
  staví.
- **`edit-forms-phase3.md`**, **`new-forms-01.md`** — `FormDialog`,
  `Modal` stack, `FormStateBar` s state transitions. Vše hotové.
- **`form-lookup-fields.md`** sekce 22 v `docs/edit-forms.md` —
  kanonický vzor pro otevírání `FormDialog` mimo standardní viewer
  toolbar. `LookupInput.svelte` dělá totéž (klik na tužku → modal),
  máme z čeho čerpat.
- **`01-module-tasks.md`** — definuje `tasks.core.docStatesTasks` se
  stavem 40 „Hotovo" (`closeForm: 1`, `viewGroup: archive`). Use case
  je předem připraven.

Žádný cross-repo task. Vše žije v hlavním repu.

## Scope

### V rozsahu

- API kontrakt — přidat druhou variantu `action.kind` (`"open_form"`)
  vedle existujícího `"open_viewer"`. Per-widget si server vybere.
- `DashboardController` — pro Tasks widget posílá
  `action.kind: "open_form"` s `table` a `recordId`. Alerts a Mail
  zůstávají na `"open_viewer"`.
- `Dashboard.svelte` — drží state otevřeného form modalu, mountuje
  `<FormDialog>`, řeší refresh logiku (refetch jen pokud `onSaved`
  proběhlo).
- `WidgetCard.svelte` — místo přímého volání `navigationStore`
  emituje akci nahoru přes `onItemAction` callback prop. Dashboard
  rozhoduje, co s akcí udělat.
- Testy `DashboardControllerTest` — upravit existující assertions na
  nový tvar action (přidat 2 nové test cases pro form variantu).
- Dokumentace — update `docs/dashboard.md` (sekce API kontrakt + frontend
  chování) a krátká poznámka v `docs/frontend.md`.

### Mimo rozsah

- Přepnutí Alerts a Mail na form modal (viz výše — záměrné rozhodnutí).
- „Otevřít všechny →" v footeru widgetu (zůstává viewer navigace).
- AI summary „Otevřít poštu/úkoly/upozornění" tlačítka (zůstávají
  viewer navigace, open-all sémantika).
- Optimistic update widgetu (skrýt úkol z widgetu hned po Hotovo, před
  refetch). Refetch je dostatečně rychlý; optimistic update přidá
  state machine bez velkého přínosu.
- Specifický mechanismus „uložit a otevřít další úkol" — uživatel
  prostě klikne na další řádek po close.
- Refactor `WidgetCard` na pure presentational komponentu (mimo handler
  routing). Touto změnou se to *de facto* stane, ale není to cíl.

## Architektura

### High-level flow

```
dashboard:
  user klikne na úkol "Připravit reporty"
      │
      ▼
  WidgetCard.handleRowClick(item)
      │   item.action = {kind: "open_form", table: "tasks_core_tasks", recordId: 17}
      │
      ▼
  onItemAction(item.action)        ← callback prop
      │
      ▼
  Dashboard.handleItemAction(action)
      │  if action.kind === "open_form":
      │     formModal = { open: true, table: action.table,
      │                   recordId: action.recordId, wasSaved: false }
      │
      ▼
  <FormDialog
      open={formModal.open}
      table={formModal.table}
      recordId={formModal.recordId}
      onSaved={() => formModal.wasSaved = true}
      onClose={handleFormClose}
  />
      │
      │  user klikne "Hotovo" → FormStateBar PUT /save/17 s docState=40
      │                       → onSaved trigger
      │                       → closeForm:1 → FormEditor volá onClose({force:true})
      ▼
  handleFormClose()
      │  if formModal.wasSaved: load()  ← refetch dashboard
      │  formModal = { open: false, ... }
      ▼
  widget se překreslí, úkol "Připravit reporty" zmizí (Hotovo →
    viewGroup: archive → nepatří do active filteru)
```

### Komponenty — nové soubory

Žádné nové soubory.

### Komponenty — modifikované soubory

```
src/Api/Controller/DashboardController.php                          ← action.kind per widget
tests/Unit/Api/Controller/DashboardControllerTest.php               ← 2 nové cases
frontend/src/components/dashboard/Dashboard.svelte                  ← form modal state, FormDialog
frontend/src/components/dashboard/WidgetCard.svelte                 ← onItemAction prop
docs/dashboard.md                                                   ← API contract + frontend section
docs/frontend.md                                                    ← krátká poznámka
```

`WidgetRow.svelte` se nemění — `item.action` zůstává neprůhlednou
strukturou, kterou volá nadřazený handler. To je správné rozdělení.

## API kontrakt — změny

### Před (současný stav)

```json
{
    "id": "tasks",
    "type": "tasks",
    "title": "Aktivní úkoly",
    "icon": "list-check",
    "count": 5,
    "items": [
        {
            "id": 17,
            "stateStyle": "concept",
            "title": "Připravit reporty",
            "subtitle": null,
            "icon": "list-check",
            "action": {
                "kind": "open_viewer",
                "viewerId": "tasks.core",
                "recordId": 17
            }
        }
    ],
    "openAllAction": { "viewerId": "tasks.core" }
}
```

### Po (cílový stav)

```json
{
    "id": "tasks",
    "type": "tasks",
    "title": "Aktivní úkoly",
    "icon": "list-check",
    "count": 5,
    "items": [
        {
            "id": 17,
            "stateStyle": "concept",
            "title": "Připravit reporty",
            "subtitle": null,
            "icon": "list-check",
            "action": {
                "kind": "open_form",
                "table": "tasks_core_tasks",
                "recordId": 17
            }
        }
    ],
    "openAllAction": { "viewerId": "tasks.core" }
}
```

**Jen u Tasks widgetu.** Alerts a Mail zůstávají s `"open_viewer"`.

### Typy `action.kind`

| Kind | Pole | Sémantika frontendu |
|------|------|----------------------|
| `"open_viewer"` | `viewerId`, `recordId` | `navigationStore.navigateToViewer(viewerId, recordId)` — naviguje do viewer záložky a pre-selectuje záznam (přes `pendingRecordId`). |
| `"open_form"` | `table`, `recordId` | Dashboard otevře `<FormDialog table recordId>` jako modal nad sebou. Po `onClose` se případně refetchne dashboard. |

`openAllAction` zůstává nezměněn (jen `{viewerId}` bez kindu) — open-all
sémantika nedává smysl pro form modal.

## Backend — úpravy

### `src/Api/Controller/DashboardController.php`

#### 1. `renderRowToWidgetItem` — přijímá action descriptor, ne hard-coded viewer

Aktuálně metoda vždy generuje `action.kind: "open_viewer"`. Refactor: action
se předává jako parametr, metoda už nerozhoduje o jejím tvaru.

```php
/**
 * Mapuje renderRow() výstup vieweru na widget-row tvar pro Dashboard
 * (kompaktnější než plný viewer řádek, neobsahuje i1/i2/t3).
 *
 * @param  array{kind:'open_viewer',viewerId:string}|array{kind:'open_form',table:string}  $actionTemplate
 *         Šablona action; recordId se vyplní z řádku (rendered['id']).
 * @internal Public pro účely testů — bez business logiky, čistá transformace.
 */
public function renderRowToWidgetItem(
    array $rendered,
    array $actionTemplate,
    ?string $widgetIcon,
): array {
    $id = (int) ($rendered['id'] ?? 0);
    return [
        'id'         => $id,
        'stateStyle' => $rendered['stateStyle'] ?? null,
        'title'      => $this->flattenTextField($rendered['t1'] ?? null, ' ')
                        ?: ('#' . $id),
        'subtitle'   => $this->flattenTextField($rendered['t2'] ?? null, ' · '),
        'icon'       => $rendered['icon'] ?? $widgetIcon,
        'action'     => array_merge($actionTemplate, ['recordId' => $id]),
    ];
}
```

#### 2. `fetchWidgetItems` — propaguje action template

```php
private function fetchWidgetItems(
    ViewerRegistry $registry,
    DataSourceConnection $db,
    ?ConfigRuntime $config,
    string $lang,
    string $viewerId,
    array $filters,
    string $widgetIcon,
    array $actionTemplate,            // ← new parameter
): array {
    $viewer = $registry->createViewer($viewerId, $db, $config, $lang);
    if ($viewer === null) {
        return [];
    }

    try {
        $rawRows = $viewer->selectRows(null, $filters, 0);
    } catch (\Throwable) {
        return [];
    }

    $rawRows = array_slice($rawRows, 0, self::ITEMS_PER_WIDGET);

    $items = [];
    foreach ($rawRows as $rawRow) {
        $rendered = $viewer->renderRow($rawRow);
        $items[] = $this->renderRowToWidgetItem($rendered, $actionTemplate, $widgetIcon);
    }
    return $items;
}
```

#### 3. `buildTasksWidget` — `open_form` action

```php
private function buildTasksWidget(
    ViewerRegistry $registry,
    DataSourceConnection $db,
    ?ConfigRuntime $config,
    string $lang,
): array {
    $viewerId = 'tasks.core';
    $items = $this->fetchWidgetItems(
        $registry, $db, $config, $lang, $viewerId,
        [['id' => 'viewGroup', 'value' => 'active']],
        'list-check',
        ['kind' => 'open_form', 'table' => 'tasks_core_tasks'],   // ← new
    );

    $count = $this->countActiveByDocState(
        $db, $registry, $config, $viewerId, 'tasks.core.docStatesTasks',
    );

    return [
        'id'            => 'tasks',
        'type'          => 'tasks',
        'title'         => $lang === 'cs' ? 'Aktivní úkoly' : 'Active tasks',
        'icon'          => 'list-check',
        'count'         => $count,
        'items'         => $items,
        'openAllAction' => ['viewerId' => $viewerId],
    ];
}
```

#### 4. `buildAlertsWidget`, `buildMailWidget` — `open_viewer` action

Beze změny chování, jen volání `fetchWidgetItems` přidá explicitní action
template místo aktuálního hard-coded `open_viewer` v `renderRowToWidgetItem`:

```php
// alerts
$items = $this->fetchWidgetItems(
    $registry, $db, $config, $lang, $viewerId,
    [['id' => 'alert_state', 'value' => 'active']],
    'alert',
    ['kind' => 'open_viewer', 'viewerId' => $viewerId],   // ← new
);

// mail
$items = $this->fetchWidgetItems(
    $registry, $db, $config, $lang, $viewerId,
    [['id' => 'viewGroup', 'value' => 'active']],
    'mail',
    ['kind' => 'open_viewer', 'viewerId' => $viewerId],   // ← new
);
```

### Proč `table`, ne `viewerId`, pro `open_form`?

Form endpoint (`/_ui/form/{table}/meta`) je table-keyed, ne viewer-keyed.
Jeden viewer pracuje s jednou tabulkou (currently), ale klíč je table.
Posíláme stranou nativní pro formuláře, ne pro viewery.

Pokud bychom v budoucnu chtěli odvozovat `table` z `viewerId` na frontendu
přes nový API endpoint, bylo by to navíc round-trip. Server `table` zná,
ať ho rovnou pošle.

### Testy — `tests/Unit/Api/Controller/DashboardControllerTest.php`

Pravděpodobné existující testy assertují na strukturu action s
`kind: 'open_viewer'`. Update:

1. **Tasks widget** — assertions na `action.kind === 'open_form'`,
   `action.table === 'tasks_core_tasks'`, `action.recordId === <id>`.
   Žádné `viewerId` v action u Tasks.
2. **Alerts/Mail widgety** — assertions stejné jako dnes (`kind === 'open_viewer'`).
3. **Nový test** `testRenderRowToWidgetItemWithFormAction()` — volá
   `renderRowToWidgetItem` přímo s action templatem
   `{kind: 'open_form', table: 'x'}` a ověří, že výsledek má správný
   tvar (kind, table, recordId, žádné viewerId).
4. **Nový test** `testRenderRowToWidgetItemWithViewerAction()` —
   analogicky pro `open_viewer`.

## Frontend — úpravy

### 1. `Dashboard.svelte` — form modal state + dispatch akcí

```svelte
<script>
  import { onMount } from 'svelte';
  import { t } from '../../i18n/index.js';
  import { fetchDashboard } from '../../api/dashboard.js';
  import { iconRefresh } from '../../icons.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import Button from '../ui/Button.svelte';
  import FormDialog from '../form/FormDialog.svelte';
  import AiSummaryCard from './AiSummaryCard.svelte';
  import WidgetCard from './WidgetCard.svelte';

  let data = $state(null);
  let loading = $state(true);
  let error = $state(null);

  // Form modal state. wasSaved se nastaví na true při onSaved callbacku
  // z FormDialog; v onClose se podle něj rozhodne, zda refetchnout dashboard.
  let formModal = $state({
    open: false,
    table: '',
    recordId: null,
    wasSaved: false,
  });

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

  function handleItemAction(action) {
    if (!action || !action.kind) return;

    if (action.kind === 'open_viewer') {
      navigationStore.navigateToViewer(action.viewerId, action.recordId ?? null);
      return;
    }

    if (action.kind === 'open_form') {
      formModal = {
        open: true,
        table: action.table,
        recordId: action.recordId ?? null,
        wasSaved: false,
      };
      return;
    }

    // Neznámý kind — graceful fallback (console warning, no-op pro uživatele).
    console.warn('Unknown widget action kind:', action.kind);
  }

  function handleOpenAllAction(action) {
    if (!action?.viewerId) return;
    navigationStore.navigateToViewer(action.viewerId);
  }

  function handleFormSaved() {
    formModal = { ...formModal, wasSaved: true };
  }

  function handleFormClose() {
    const shouldRefetch = formModal.wasSaved;
    formModal = { open: false, table: '', recordId: null, wasSaved: false };
    if (shouldRefetch) {
      load();
    }
  }

  onMount(load);
</script>

<div class="shpd-dashboard">
  <header class="shpd-dashboard__header">
    <h1 class="shpd-dashboard__title">{t('dashboard.title')}</h1>
    <Button
      variant="ghost"
      size="sm"
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
        <WidgetCard
          {widget}
          onItemAction={handleItemAction}
          onOpenAllAction={handleOpenAllAction}
        />
      {/each}
    </div>
  {/if}
</div>

{#if formModal.open}
  <FormDialog
    table={formModal.table}
    recordId={formModal.recordId}
    open={formModal.open}
    onSaved={handleFormSaved}
    onClose={handleFormClose}
  />
{/if}

<style>
  /* beze změny */
</style>
```

### 2. `WidgetCard.svelte` — emit akce, ne přímý routing

```svelte
<script>
  import { t } from '../../i18n/index.js';
  import { resolveIcon } from '../../icons.js';
  import Icon from '../ui/Icon.svelte';
  import WidgetRow from './WidgetRow.svelte';

  let { widget, onItemAction, onOpenAllAction } = $props();

  function handleRowClick(item) {
    onItemAction(item.action);
  }

  function handleOpenAll() {
    onOpenAllAction(widget.openAllAction);
  }

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

  {#if widget.count > 0 && widget.openAllAction}
    <footer class="shpd-widget-card__footer">
      <button class="shpd-widget-card__open-all" onclick={handleOpenAll} type="button">
        {t('dashboard.openAll')} →
      </button>
    </footer>
  {/if}
</section>

<style>
  /* beze změny */
</style>
```

Import `navigationStore` v `WidgetCard` zaniká — odstranit. Funkce
`handleRowClick` a `handleOpenAll` se zmenší na pouhé delegace.

### 3. `WidgetRow.svelte` — beze změny

`item.action` se v komponentě nepoužívá; klik jen volá `onclick` prop.
Komponenta nezná typ akce — to je správné.

## Refresh logika — detaily

### Kdy refetchnout

| Scénář | Refetch? |
|--------|----------|
| User klikne na úkol, modal se otevře, user klikne `×` bez změny | Ne |
| User edituje, klikne **Uložit**, pak `×` | Ano (onSaved proběhlo) |
| User klikne **Hotovo** (closeForm: 1, force close) | Ano (onSaved proběhlo) |
| User edituje a klikne **Esc** s rozpracovanými změnami → confirm → Storno | Ne (modal zůstává otevřen) |
| User edituje a klikne **Esc** → confirm → OK (ztratí změny) | Ne (onSaved neproběhlo, jen close) |

Klíčové: `wasSaved` se nastaví **pouze** v `onSaved`. Close handler kontroluje
ten flag — pokud true, refetch; jinak ne. Dirty kontrola v `FormDialog`
běží nezávisle — nás zajímá jen výsledek (`onSaved` ano/ne).

### Race condition — open dashboard refetch během načítání

Pokud uživatel klikne refresh ručně mezi `onSaved` a `onClose` (málo
pravděpodobné, ale možné), `load()` se zavolá dvakrát. Je to bezpečné —
druhý fetch nahradí první data. Nejde o data corruption, jen o zbytečné
volání. Není potřeba debounce.

### Reset `pendingRecordId`?

`open_form` action **nepoužívá** `navigationStore` ani `pendingRecordId`.
Modal žije nezávisle. Žádný reset state v navigation store není potřeba.

## Konvence k dodržení

- **Svelte 5 runes** — `$state`, `$derived`, `$props`, callback props (ne
  `createEventDispatcher`).
- **FormDialog API se nemění** — `table`, `recordId`, `open`, `onClose`,
  `onSaved`, `defaultData`. Pokud by se objevila chuť přidat new prop,
  diskuse před implementací.
- **`action.kind` enum hodnoty jsou snake_case** (`open_viewer`,
  `open_form`) — drží konzistenci s ostatními wire formáty
  (`docState`, `viewGroup`, `read_only`).
- **API envelope** `{success, data}` se nemění — všechno je uvnitř
  `data.widgets[].items[].action`.
- **CSS BEM `shpd-`** — žádné nové BEM bloky, jen modifikace existujícího
  `Dashboard.svelte` (přidání modal mount pointu *mimo* `.shpd-dashboard`).
- **Žádný globální state pro modal** — `formModal` žije v `Dashboard.svelte`.
  Když dashboard nesedí ve viewu (uživatel přejde do Settings), modal
  se přirozeně odmountuje. Pokud bychom v budoucnu chtěli modal přežívající
  navigaci, řešilo by se to v separátním tasku.

## Hotovo když

- `vendor/bin/phpunit 2>&1` projde, včetně updateovaných testů + 2
  nových cases pro `renderRowToWidgetItem`.
- `cd frontend && npm run build 2>&1` projde bez warningů.
- `cd frontend && npm run check:i18n` projde (žádné nové i18n klíče
  v tomto tasku, ale safety check).
- Po otevření dashboardu uvidím v aktivních úkolech několik řádků.
- Klik na úkol otevře **modal** přímo nad dashboardem (ne navigaci do
  Tasks vieweru); v hlavičce modalu je název úkolu, badge se stavem,
  `×` vpravo.
- Klikem na **Hotovo** v FormStateBar se modal sám zavře a úkol zmizí
  z widget seznamu (přefetchovaný dashboard ho už nezahrnuje).
- Klikem na **Uložit** v FormStateBar (po editaci popisu úkolu) modal
  zůstává otevřený. Klikem na `×` se zavře a dashboard se přefetchuje
  (případné změny počtu / titulu se projeví).
- Klikem na **×** bez editace se modal zavře a dashboard se **nepřefetchuje**
  (žádné zbytečné API volání).
- Klikem na řádek v **Upozornění** widgetu se chová stejně jako předtím —
  navigace do Alerts viewer s pre-selected záznamem.
- Klikem na řádek v **Aktuální došlá pošta** widgetu se chová stejně jako
  předtím — navigace do Mail viewer s pre-selected záznamem.
- Klikem na **„Otevřít všechny →"** v patce kteréhokoli widgetu jde
  do viewer mode (beze změny).
- Modal `FormDialog` nad dashboardem respektuje stack mechanismus z
  `Modal.svelte` — pokud by uživatel ve form modu otevřel sub-modal
  (např. lookup edit pro `assignedTo`), depth-shrink funguje
  automaticky.
- API odpověď `/_ui/dashboard` má pro Tasks widget v `items[].action`
  pole `kind: 'open_form'`, `table: 'tasks_core_tasks'`, `recordId: <int>`.
- API odpověď pro Alerts a Mail má `kind: 'open_viewer'` s `viewerId`
  a `recordId` (beze změny).
- `docs/dashboard.md` — aktualizovaná sekce API kontrakt (popis obou
  variant `action.kind`), aktualizovaná sekce „Frontend chování" (popis
  form modal flow + refresh logiky).
- `docs/frontend.md` sekce 7.5 *Dashboard* — krátká aktualizace
  (jeden odstavec, jak Tasks otevírá form modal).

## Co netřeba

- Per-uživatelské nastavení „chci form modal pro tento widget" — server
  rozhoduje, frontend renderuje.
- Optimistic update (skrýt úkol z widgetu hned po Hotovo, před refetch
  response).
- Animace přechodu mezi modalem a dashboardem (Modal komponenta má
  vlastní fade, postačí).
- Auto-fokus prvního pole ve form modalu po otevření — `FormEditor`
  to už dělá out-of-the-box.
- Memoizace navigation state nebo modal state přes navigaci sidebar
  (přechod do Settings ⇄ App).
- Customizace Modal headeru pro dashboard kontext — defaultní
  `FormStateBadge` + title z `FormHeaderInfo` postačuje.
- Mobile-specific tweaky modalu (Modal už má `width`/`height` props,
  které u Tasks (`fullSize: false`) dají 720px width — na úzkých
  obrazovkách se zalomí podle viewportu, řeší Modal sám).

## Rozhodnutí potvrzená

✓ **Které widgety přepnout na form modal**: Pouze Tasks. Alerts a Mail
  ponechávají `open_viewer` chování.

✓ **Refresh strategie**: Refetch dashboardu po close modalu **jen
  pokud došlo k save** (sledováno přes `wasSaved` flag nastavovaný v
  `onSaved` callbacku z FormDialog).

✓ **Fallback když tabulka nemá form**: AutoFormBuilder defaultní
  chování. Žádná speciální detection vrstva v dashboardu —
  `FormController` vrátí auto-generated form, který se otevře normálně.
  Pokud by AutoFormBuilder selhal (tabulka neexistuje, špatná
  TableDefinition), `FormDialog` zobrazí error state přes svůj
  vlastní error handling.

## Otevřené body / future

- **Mail jako form modal** — pokud po pár dnech používání chybí. Bude
  vyžadovat: definici edit formu pro `core_mail_incoming` (pokud
  neexistuje), promyšlení action `kind: 'open_form'` vs read-only
  varianty `kind: 'open_form_readonly'`.
- **Alerts s vlastním modal flow** — alerts mají specifické akce
  (acknowledge, snooze, dismiss). Nešlo by to přes FormDialog (které
  je o editaci dat tabulky). Bylo by potřeba speciální komponentu
  `AlertActionDialog` nebo přidat `kind: 'open_alert_actions'` —
  vlastní task, pokud bude potřeba.
- **Auto-open next item** — po Hotovo v jednom úkolu automaticky
  otevřít další z widgetu. „Power user" feature. Vlastní task,
  pokud po dnech používání chybí.
- **Bulk operations** — vybrat víc úkolů (checkboxes na widget rows),
  hromadně Hotovo. Out of scope této fáze (single-row interaction
  je dominantní vzor).
- **Drag-and-drop priority** — drag úkol do widgetu „Dnes" / „Tento
  týden". Vyžaduje větší rework widget mechaniky a doménového modelu
  (priority, due dates).
- **Modal state v URL** — `/dashboard?openTask=17`. Sdílení odkazu,
  zachování stavu při reloadu. Možný enhancement, vyžaduje router.

## Pořadí kroků a commit strategie

### Doporučené pořadí

1. **Backend** — refactor `renderRowToWidgetItem` + `fetchWidgetItems`
   na action template parametr, update tří `buildXxxWidget` metod
   (Tasks na `open_form`, Alerts/Mail explicitně na `open_viewer`).
   Update testů. Verifikace: `vendor/bin/phpunit 2>&1`.
2. **Frontend** — `WidgetCard.svelte` refactor (callbacky místo
   `navigationStore`), `Dashboard.svelte` form modal state + FormDialog
   mount + refresh logika. Verifikace: `npm run build 2>&1`.
3. **Manuální test** — projít acceptance criteria v sekci „Hotovo
   když" v prohlížeči.
4. **Dokumentace** — `docs/dashboard.md` API contract sekce, frontend
   sekce; `docs/frontend.md` jeden odstavec.

### Commit strategie

Dva logické commity:

```
feat(dashboard): allow per-widget action kind in API contract

- renderRowToWidgetItem now takes action template parameter
- Tasks widget uses kind=open_form, Alerts/Mail use kind=open_viewer
- Update DashboardControllerTest for new action shape
- 2 new test cases for both action template variants
```

```
feat(dashboard): open form modal on task row click

- Dashboard.svelte mounts FormDialog with state machine
- WidgetCard.svelte delegates row clicks via onItemAction prop
- Refresh dashboard after close only if save occurred
- docs/dashboard.md, docs/frontend.md updated
```

---

**Status**: připraveno k implementaci. Inkrementální vylepšení
dashboardu — žádný refactor architektury, jen lepší UX pro Tasks
widget.
