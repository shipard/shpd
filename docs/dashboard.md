# Dashboard

Home obrazovka aplikace — výchozí pohled po přihlášení s přehledem
nejdůležitějších informací dne. Tento dokument popisuje fázi 1 (MVP):
tři hardcoded widgety (Upozornění, Aktuální došlá pošta, Aktivní úkoly)
a statickou „AI shrnutí" kartu.

## 1. Přehled

Po loginu uvidí uživatel:

```
┌──────────────────────────────────────────────────────────────┐
│  Dashboard                                       [Obnovit ↻]  │
├──────────────────────────────────────────────────────────────┤
│  🤖 Dnešní shrnutí                                            │
│  Aktuálně máte: 2 upozornění, 8 nových zpráv, 5 úkolů.        │
│  AI asistent — připravujeme; zatím počítám z dat widgetů.     │
├──────────────────────────────────────────────────────────────┤
│  ┌───────────────┐ ┌────────────────┐ ┌──────────────────┐   │
│  │ 🔔 Upozornění │ │ 📧 Došlá pošta │ │ ✓ Aktivní úkoly │    │
│  │           (2) │ │            (8) │ │              (5) │   │
│  ├───────────────┤ ├────────────────┤ ├──────────────────┤   │
│  │ Item 1        │ │ Item 1         │ │ Item 1           │   │
│  │ Item 2        │ │ Item 2         │ │ Item 2           │   │
│  │ …             │ │ …              │ │ …                │   │
│  ├───────────────┤ ├────────────────┤ ├──────────────────┤   │
│  │ Otevřít všech │ │ Otevřít všech  │ │ Otevřít všechny  │   │
│  └───────────────┘ └────────────────┘ └──────────────────┘   │
└──────────────────────────────────────────────────────────────┘
```

Principy:

- **Přehled, ne přístupový bod.** Dashboard nesupluje viewery. Klik na řádek
  navigátorm do cílového vieweru s vybraným záznamem; veškeré akce (snooze,
  archive, edit) zůstávají v dedikovaných viewerech.
- **Jeden agregovaný endpoint.** `GET /_ui/dashboard` vrací všechny widgety
  v jedné odpovědi — atomická aktualizace, méně round-tripů.
- **Re-use existujících viewerů.** Položky widgetů se získávají voláním
  `selectRows()` + `renderRow()` na existujících viewer třídách; když se
  viewer změní, widget se aktualizuje zdarma.

## 2. Architektura

```
            GET /_ui/dashboard
                   │
                   ▼
        ┌──────────────────────┐
        │  DashboardController │
        │  ::dashboard()       │
        └──────────┬───────────┘
                   │
   ┌───────────────┼───────────────┐
   ▼               ▼               ▼
AlertsViewer  IncomingMess.   TasksViewer
selectRows()  Viewer          selectRows()
renderRow()   selectRows()    renderRow()
              renderRow()
   │               │               │
   └───────────────┼───────────────┘
                   │  agregovaný JSON
                   ▼
            Dashboard.svelte
                   │
   ┌───────────────┼───────────────┐
   ▼               ▼               ▼
AiSummaryCard  WidgetCard      WidgetCard
               (alerts)        (mail / tasks)
                   │
                   ▼
              WidgetRow × N
```

`DashboardController` neimplementuje vlastní SQL pro výběr řádků —
sahá přes `ViewerRegistry::createViewer()` na existující viewer
třídy. Pro celkové počty otevřených záznamů (`count`) ale použít
viewer nelze (vrací pageSize+1, ne agregát), proto controller dělá
**samostatný `SELECT COUNT(*)`** odděleně. Drobná duplikace SQL je
záměrná — výhody (jednotná render logika v `renderRow()`) převažují.

## 3. API kontrakt

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
            "stateStyle": "concept",
            "title": "Chybí vlastní Osoba",
            "subtitle": "Warning · core.alerts.missing_own_person",
            "icon": "alert",
            "action": {
              "kind": "open_viewer",
              "viewerId": "core.alerts.alerts",
              "recordId": 17
            }
          }
        ],
        "openAllAction": { "viewerId": "core.alerts.alerts" }
      },
      {
        "id": "incoming_mail",
        "type": "mail",
        "title": "Aktuální došlá pošta",
        "icon": "mail",
        "count": 8,
        "items": [ /* posledních ~7 podle renderRow(), action.kind=open_viewer */ ],
        "openAllAction": { "viewerId": "core.mail.incoming" }
      },
      {
        "id": "tasks",
        "type": "tasks",
        "title": "Aktivní úkoly",
        "icon": "list-check",
        "count": 5,
        "items": [
          {
            "id": 33,
            "stateStyle": "concept",
            "title": "Připravit reporty",
            "subtitle": null,
            "icon": "list-check",
            "action": {
              "kind": "open_form",
              "table": "tasks_core_tasks",
              "recordId": 33
            }
          }
        ],
        "openAllAction": { "viewerId": "tasks.core" }
      }
    ]
  }
}
```

**Pole `summary`** — duplikuje `count` z widgetů. `AiSummaryCard` ho čte
centrálně bez nutnosti iterovat widgety. Pole má vždy tři klíče;
když je některý widget vypnutý (mimo MVP), jeho `*Count` zůstane 0.

**Pole `count`** — celkový počet otevřených záznamů (Active stav), může
být **větší než `items.length`**. Items jsou ořezané server-side
na **max 7** (`DashboardController::ITEMS_PER_WIDGET`). Frontend zobrazuje
`count`, ne `items.length`.

**Pole `items[].title`** — string. Extrahuje se z `renderRow()` pole `t1`:

- `t1` jako string → použít přímo
- `t1` jako `{text, class?}` → `text`
- `t1` jako list objektů → konkatenace `text` hodnot oddělená mezerou
- chybějící / prázdné → fallback `"#{id}"`

**Pole `items[].subtitle`** — string nebo null. Z `renderRow()` pole `t2`:

- stejná pravidla jako `t1`, ale list objektů konkatenovat oddělené ` · `
  (mezera + bullet + mezera) pro vizuální čitelnost
- chybějící → null

**Pole `items[].icon`** — string nebo null. Z `renderRow()` pole `icon`,
fallback na widget-level icon (= default z `module.jsonc`).

**Pole `items[].stateStyle`** — string bez `docState_` prefixu (např.
`"concept"`, ne `"docState_concept"`). Frontend si přidá prefix při
sestavování CSS třídy.

**Pole `items[].action.kind`** — určuje, jak frontend reaguje na klik na řádek.
Server volí typ akce per widget; recordId se vždy plní z `rendered.id`.

| Kind | Pole akce | Sémantika frontendu |
|------|-----------|---------------------|
| `"open_viewer"` | `viewerId`, `recordId` | `navigationStore.navigateToViewer(viewerId, recordId)` — přepne `activeItem` a uloží `pendingRecordId`. Viewer.svelte ho po loadu vyzvedne a předvybere záznam. |
| `"open_form"` | `table`, `recordId` | Dashboard otevře `<FormDialog table recordId>` jako modal přímo nad sebou. Po close se případně refetchne dashboard (jen pokud `onSaved` proběhlo). |

**Které widgety používají kterou variantu**:

- **Tasks** — `open_form`. Klik otevře editaci úkolu rovnou v modalu; klik
  na **Hotovo** v `FormStateBar` zavře modal (`closeForm:1` u stavu 40
  v `tasks.core.docStatesTasks`) a úkol zmizí z widgetu (`viewGroup: archive`).
- **Alerts** — `open_viewer`. Specifické akce (acknowledge, snooze, dismiss)
  nejdou přes form save, takže form modal nedává sémanticky smysl.
- **Mail** — `open_viewer`. Read-flow s atachmenty / threadem — uživatel
  typicky chce vidět víc detailu najednou.

`openAllAction` zůstává jednotně `{viewerId}` bez kindu — open-all sémantika
pro form modal nedává smysl.

**Proč `table` u `open_form`, ne `viewerId`?** Form endpoint
(`/_ui/form/{table}/meta`) je table-keyed. Server `table` zná, ať ho rovnou
pošle — odpadá round-trip pro odvození.

**Response — error**: standardní envelope `{ success: false, error: {...} }`.

## 4. Widgety v MVP

| Widget | `type` | Viewer | Filtr | Stavy v "Active" |
|--------|--------|--------|-------|------------------|
| Upozornění | `alerts` | `core.alerts.alerts` | `alert_state=active` | jen `alert_state = 10` (Active) — Snoozed se v dashboardu neukazuje |
| Aktuální došlá pošta | `mail` | `core.mail.incoming` | `viewGroup=active` | viewGroup `active` z `core.mail.docStatesIncoming` (10/20/30/40/70) |
| Aktivní úkoly | `tasks` | `tasks.core` | `viewGroup=active` | viewGroup `active` z `tasks.core.docStatesTasks` (10/20/30); 40=Done je v `archive` |

**Snoozed alerty** zobrazuje samostatný viewer, ne dashboard — uživatel je
záměrně potlačil, vidět by je znovu hned po snooze bylo matoucí.

**Per-user filtr „Moje úkoly"** zatím není (Tasks nemá assignee oddělené
od autora). Widget proto pojmenovaný „Aktivní úkoly", interní ID `tasks`.
Personalizace přijde se zavedením assignee sloupce.

## 5. AI shrnutí

V MVP **statický text z countů**, ikona robota. Klíče:

- `dashboard.aiSummary.title` — „Dnešní shrnutí" / „Today's summary"
- `dashboard.aiSummary.intro` — úvod „Aktuálně máte:"
- `dashboard.aiSummary.alerts/mail/tasks` — ICU plurály per kategorie
- `dashboard.aiSummary.empty` — pokud jsou všechny counts 0
- `dashboard.aiSummary.placeholder` — kurzivou pod textem, signalizuje
  budoucí AI integraci

Skutečná AI vrstva (LLM volání, prompt engineering, async fetch) je out
of scope MVP. Interface zůstává stejný — když přibude AI, jen se přepíše
obsah `AiSummaryCard.svelte` a backend přidá pole `summary.aiText` (nebo
samostatný endpoint, závisí na latenci).

## 6. Empty stavy a refresh

| Widget | `count = 0` | i18n klíč |
|---|---|---|
| Alerts | „Vše v pořádku ✓" | `dashboard.widget.alerts.empty` |
| Mail | „Žádné nové zprávy" | `dashboard.widget.mail.empty` |
| Tasks | „Žádné aktivní úkoly" | `dashboard.widget.tasks.empty` |
| AI summary | „Vše je v klidu, dnes nic nečeká." | `dashboard.aiSummary.empty` |

**Refresh** — fetch při mountu komponenty + manuální tlačítko v hlavičce.
Žádný polling / SSE / WebSocket — Shipard je interní operační nástroj,
ne real-time monitoring. Pokud bude potřeba, samostatný task.

## 7. Frontend komponenty

```
frontend/src/components/dashboard/
├── Dashboard.svelte       — Top-level: fetch, grid layout, refresh tlačítko
├── AiSummaryCard.svelte   — Plně-šířková karta s ikonou robota a textem
├── WidgetCard.svelte      — Sdílený shell pro 3 widgety (header + items + footer)
└── WidgetRow.svelte       — Jeden řádek s docState pruhem (4px)
```

API klient: `frontend/src/api/dashboard.js` — `fetchDashboard()` wrapper.

**Doc-state proužek**: třídy `.docState_concept`, `.docState_edit`, atd. jsou
**globální** v `styles/base.css` (sdílené mezi `ViewerRow` 6px proužkem
a `WidgetRow` 4px proužkem). Pruh konzumuje CSS proměnnou `--shpd-row-bar`,
kterou tyto třídy nastavují podle stavu.

**Navigace na cílový viewer** používá `navigationStore.navigateToViewer(
viewerId, recordId)`. Store si recordId uloží v `pendingRecordId`,
Viewer.svelte ho po loadu řádků vyzvedne (`consumePendingRecordId()`)
a předvybere záznam — automaticky se otevře jeho detail.

**Form modal nad dashboardem (Tasks widget).** `Dashboard.svelte` drží
state `formModal = {open, table, recordId, wasSaved}` a mountuje
`<FormDialog>` nezávisle na navigaci. `WidgetCard` emituje akci nahoru
přes `onItemAction` callback prop — Dashboard rozhoduje, co s ní dělat
(navigate vs. open modal). Refresh dashboardu po close je podmíněný:

| Scénář | Refetch? |
|---|---|
| Klik na úkol → close (×, Esc) bez editace | Ne |
| Edit → **Uložit** → close | Ano (`onSaved` proběhlo) |
| **Hotovo** v FormStateBar (closeForm: 1) | Ano (`onSaved` proběhlo) |
| Edit + Esc/× → confirm OK (ztratí změny) | Ne (`onSaved` neproběhlo) |

`wasSaved` se nastaví **pouze** v `onSaved` callbacku FormDialogu. Close
handler ho kontroluje a refetchne jen pokud true. Dirty kontrola
v `FormDialog` běží nezávisle.

Modal žije ve scope `Dashboard.svelte` — při přechodu do Settings se
přirozeně odmountuje. Žádný globální state, žádný `pendingRecordId`
reset (form modal navigation store nevyužívá). Modal stack mechanismus
z `Modal.svelte` funguje automaticky — sub-modaly (lookup edit, …) se
zanořují s depth-shrink animací.

## 8. Budoucí rozšíření (fáze 2+)

- **Modulární widget systém** přes `module.jsonc.dashboardWidgets[]`. Každý
  modul si registruje vlastní widget(y) s vlastním data source. Backend
  agreguje deklarace, frontend renderuje generickým `WidgetCard`. Cena:
  abstrakce nad widget API, prozatím není dost konzumentů.
- **Skutečné AI shrnutí** — async endpoint nebo SSE stream s LLM textem
  generovaným z všech datových zdrojů.
- **Interaktivní akce přímo z widgetů** — snooze alert, mark task done bez
  navigace do vieweru. Cena: duplikace UX patternů.
- **Personalizace** — uživatel skrývá / přeskupuje / přidává widgety.
- **Auto-refresh** — polling nebo SSE push pro real-time.
- **Permission filtr** — admin vs běžný uživatel vidí jiné widgety,
  navazuje na permission systém.
- **Per-DS přizpůsobení** — různé DS mají různé sady widgetů; modularita
  fáze 2 to umožní automaticky.
- **KPI / grafy** — Shipard není analytický nástroj, dashboard je
  operační. Pokud by se to změnilo, samostatná „Reporty" sekce.

---

[← README.md](../README.md) · [Frontend](frontend.md) · [Alerts](alerts.md)
