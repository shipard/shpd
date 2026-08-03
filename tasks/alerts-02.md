# Task: `detail.actions` v Vieweru — akce na detailu záznamu (alerts první konzument)

**Stav:** hotovo

Stávající `core.alerts` modul má všechny backendové endpointy pro snooze /
dismiss / unsnooze / re-check, frontendové helpery v `frontend/src/api/alerts.js`,
i `runDueAlertChecks` integrované v top toolbaru. **Chybí UI tlačítka per alert**:

- Vestavěné akce (snooze, dismiss, unsnooze, re-check) podle stavu alertu
- Custom akce z `alerts.actions` JSON sloupce (např. "Přidat vlastní Osobu")

Vytvoříme generickou infrastrukturu `detail.actions` v `ViewerDetail.svelte`,
kterou bude moci využít kterýkoliv viewer pro per-record akce. Alerty jsou
první konzument.

---

## 1. Co číst před začátkem

- `frontend/src/components/viewer/ViewerDetail.svelte` — sem se přidává render
  bloku akcí (nad taby, pod hlavičkou)
- `frontend/src/components/viewer/Viewer.svelte` — sem se přidává dispatcher
  `handleDetailAction(actionId, action)`
- `frontend/src/components/form/FormDialog.svelte` — už přijímá `table` prop,
  využijeme pro `open_form` akce
- `frontend/src/api/alerts.js` — helpery `snoozeAlert`, `dismissAlert`,
  `unsnoozeAlert`, `runAlertCheck`, `SNOOZE_PRESETS`
- `frontend/src/components/ui/Popover.svelte` — pro dropdown akce
- `frontend/src/components/ui/Button.svelte` — pro plain buttony
- `modules/core/alerts/src/AlertsViewer.php` — `renderDetail()` rozšířit
  o `actions` array
- `frontend/src/components/exchange/DocumentExchangePreview.svelte` — viz
  vzor inline action buttonů s confirm dialogem (extracted-documents pattern)

---

## 2. Schéma akce — `detail.actions` array

Backend vrátí v `detail` objektu nové pole `actions`:

```json
{
    "detail": {
        "title": "...",
        "badges": [...],
        "tabs": [...],
        "actions": [
            {
                "id": "snooze",
                "label": "Odložit",
                "kind": "dropdown",
                "variant": "secondary",
                "items": [
                    {"label": "1 h",     "value": "PT1H"},
                    {"label": "4 h",     "value": "PT4H"},
                    {"label": "1 den",   "value": "P1D"},
                    {"label": "1 týden", "value": "P7D"}
                ]
            },
            {
                "id": "dismiss",
                "label": "Zamítnout",
                "kind": "button",
                "variant": "danger",
                "confirm": "Opravdu zamítnout?"
            },
            {
                "id": "recheck",
                "label": "Zkontrolovat znovu",
                "kind": "button",
                "variant": "secondary",
                "meta": {"checkId": "base.persons.missing_own_person"}
            },
            {
                "id": "create_own_person",
                "label": "Přidat vlastní Osobu",
                "kind": "open_form",
                "variant": "primary",
                "target": {
                    "table": "base_persons_persons",
                    "mode": "create",
                    "preset": {"is_own": true}
                }
            }
        ]
    }
}
```

### Pole akce

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `id` | string | ano | Identifikátor akce — předá se do `onAction` callback |
| `label` | string | ano | Text tlačítka (již lokalizovaný backendem) |
| `kind` | string | ano | `button` / `dropdown` / `open_form` / `open_viewer` |
| `variant` | string | ne | Button variant — `primary` / `secondary` / `danger` / `success`. Default `secondary`. |
| `confirm` | string | ne | Pro `kind: button` — pokud uvedeno, před akcí se zobrazí `window.confirm()` s tímto textem |
| `items` | array | pro dropdown | Pole `{label, value}` — položky dropdownu |
| `target` | any | pro `open_*` | Specifické per kind (viz níže) |
| `meta` | object | ne | Libovolná data, předají se zpět do `onAction` payload |

### `kind: 'open_form'` — `target` schéma

```json
{
    "table": "base_persons_persons",
    "mode": "create",          // "create" | "edit"
    "id": 42,                  // jen pro mode=edit
    "preset": {"is_own": true} // jen pro mode=create — initial data formuláře
}
```

### `kind: 'open_viewer'` — out of scope tohoto tasku

V tasku jen plumbing — pokud action má `kind: 'open_viewer'`, frontend log + toast
"Viewer navigation not yet implemented". Routing v navigation store doděláme
samostatně.

---

## 3. Backend — `AlertsViewer::renderDetail()`

V `modules/core/alerts/src/AlertsViewer.php` rozšířit `renderDetail()` aby vracelo
`actions` pole. Pořadí akcí:

1. **Custom akce z DB sloupce `actions`** — sloupcová hodnota je JSON pole;
   propsovat `primary: true` na `variant: 'primary'`, ostatní pole zachovat
   beze změny.
2. **Vestavěné akce per stav**:

| `alert_state` | Vestavěné akce (v pořadí) |
|---|---|
| 10 Active | `snooze` (dropdown), `dismiss` (button + confirm), `recheck` |
| 20 Snoozed | `unsnooze` (button), `dismiss` (button + confirm), `recheck` |
| 70 Resolved | `recheck` (button) |
| 80 Dismissed | `recheck` (button) |

**Snooze items** — pevné 4 položky: 1h / 4h / 1d / 1w jako ISO 8601 (`PT1H`,
`PT4H`, `P1D`, `P7D`).

**Recheck `meta.checkId`** — `(string) $row['check_id']`. Frontend ho použije
pro volání `runAlertCheck(checkId)`.

**Lokalizace labelů** — z `language` (DS jazyk). Pro MVP stačí inline mapa:
`'cs'` → "Odložit", "Zamítnout", "Zkontrolovat znovu", "Vrátit do aktivních";
`'en'` → "Snooze", "Dismiss", "Re-check", "Unsnooze". Žádný cfgItem pro to
zatím nezakládáme — pokud bude víc viewerů s podobnými akcemi, vyextrahujeme
později do `core.alerts.actionLabels` nebo do shared `core.viewer.actionLabels`.

Confirm text pro `dismiss` — `'cs'` → "Opravdu zamítnout?", `'en'` →
"Really dismiss?".

**Implementační detail** — udělej privátní helpery `snoozeDropdownAction()`,
`dismissAction()`, `recheckAction(string $checkId)`, `unsnoozeAction()`. Ať se
neopakuje stejný JSON literál čtyřikrát.

**Custom action JSON validace** — pokud `actions` v DB je nevalidní JSON,
log warning a custom akce přeskoč; vestavěné se přidají normálně. Reconciler
už validuje na zápisu, tohle je obrana proti ručnímu zápisu / starým datům.

---

## 4. Frontend — `ViewerDetail.svelte`

### Změny

1. Přidat prop `actions = []` a `onAction = null`:

   ```js
   let {
       detail = null,
       loading = false,
       onRefresh,
       onAction = null,
   } = $props();
   ```

2. Mezi `<!-- Header -->` blok a `<!-- Tab bar -->` přidat nový blok:

   ```svelte
   {#if (detail?.actions ?? []).length > 0}
     <div class="shpd-detail__actions">
       {#each detail.actions as action (action.id)}
         {@render renderAction(action)}
       {/each}
     </div>
   {/if}
   ```

3. Snippet `renderAction(action)` musí ošetřit tři varianty:

   - `kind === 'button'` nebo `kind === 'open_form'` nebo `kind === 'open_viewer'`
     → render `<Button>` který volá `handleAction(action)`. Pokud `action.confirm`,
     před voláním `window.confirm(action.confirm)`.
   - `kind === 'dropdown'` → render `<Popover>` s trigger buttonem (`{action.label} ▾`)
     a menu položek `action.items`. Po výběru: `handleAction(action, item.value)`.

4. `handleAction(action, value = null)`:

   ```js
   function handleAction(action, value = null) {
       if (action.confirm && !window.confirm(action.confirm)) return;
       onAction?.(action.id, action, value);
   }
   ```

   Předáváme celou `action` (frontend potřebuje vědět `kind`, `target`, `meta`)
   plus `value` pro dropdown.

### Styling

`.shpd-detail__actions` — řádek tlačítek s `gap: var(--shpd-space-sm)`, padding
analogický `.shpd-detail__header`, border-bottom (jako tabs to mají), zarovnání
buttonů doleva, wrap při velkém počtu akcí. Vizuálně to má vypadat jako spodní
`FormStateBar.svelte`, jen v hlavičce detailu.

Pokud detail nemá `actions`, blok se nerenderuje (žádná prázdná lišta).

---

## 5. Frontend — `Viewer.svelte`

### Nový state

```js
let formTable = $state(null);  // pokud null, použije se meta.table
```

### Předat `onAction` do `<ViewerDetail>`

```svelte
<ViewerDetail
    {detail}
    loading={detailLoading}
    onRefresh={handleDetailRefresh}
    onAction={handleDetailAction}
/>
```

### `handleDetailAction(actionId, action, value)`

```js
import {
    snoozeAlert,
    dismissAlert,
    unsnoozeAlert,
    runAlertCheck,
    runDueAlertChecks,
} from '../../api/alerts.js';

async function handleDetailAction(actionId, action, value) {
    if (selectedRowId == null) return;
    const alertId = selectedRowId;

    // Vestavěné alerts akce
    if (actionId === 'snooze') {
        const duration = value;  // z dropdown items.value
        if (!duration) return;
        const result = await snoozeAlert(alertId, duration);
        if (result?.success) refreshAfterAction();
        else alert(translateError(result?.error));
        return;
    }
    if (actionId === 'dismiss') {
        const result = await dismissAlert(alertId);
        if (result?.success) refreshAfterAction();
        else alert(translateError(result?.error));
        return;
    }
    if (actionId === 'unsnooze') {
        const result = await unsnoozeAlert(alertId);
        if (result?.success) refreshAfterAction();
        else alert(translateError(result?.error));
        return;
    }
    if (actionId === 'recheck') {
        const checkId = action.meta?.checkId;
        if (!checkId) return;
        const result = await runAlertCheck(checkId);
        if (result?.success) refreshAfterAction();
        else alert(translateError(result?.error));
        return;
    }

    // Custom akce
    if (action.kind === 'open_form') {
        const target = action.target ?? {};
        if (!target.table) return;
        formTable = target.table;
        editRecordId = target.mode === 'edit' ? (target.id ?? null) : null;
        formDefaultData = target.preset ?? {};
        formOpen = true;
        return;
    }
    if (action.kind === 'open_viewer') {
        console.warn('open_viewer not yet implemented', action);
        alert('Viewer navigation not yet implemented');
        return;
    }
}

function refreshAfterAction() {
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, 0);
    if (selectedRowId != null) {
        fetchDetail(selectedRowId);
    }
}
```

### Upravit `FormDialog` render

```svelte
{#if meta?.table || formTable}
  <FormDialog
    table={formTable ?? meta.table}
    recordId={editRecordId}
    open={formOpen}
    onClose={handleFormClose}
    onSaved={handleFormSaved}
    defaultData={formDefaultData}
  />
{/if}
```

A v `handleFormClose` vyresetuj `formTable`:

```js
function handleFormClose() {
    formOpen = false;
    editRecordId = null;
    formTable = null;
    formDefaultData = {};
}
```

`handleFormSaved` taky resetuj `formTable` a custom data — uložení ostatních
tabulek nemá důvod hned znovu otvírat stejný form, refresh listu/detailu stačí.

### Důležité — `recheck` může auto-resolvovat alert

Po `recheck`, který "vyřeší" problém (kontrola už ho nenajde), reconciler ho
přepne na Resolved (state 70). Detail by to měl ukázat — `refreshAfterAction()`
přenačte detail, který pak ukáže Resolved badge + jiný set akcí (jen recheck).
Frontend nemusí dělat nic extra; je to automatický důsledek refresh-after-action.

### `formTable !== null` při open_form pro `core_alerts_alerts`?

Pro alerty se custom akce vždy týkají **jiné** tabulky než `core_alerts_alerts`
(uživatel by neměl mít důvod editovat alert přes form — vždy je to "spraviť
problém" → otevřít formulář cizí tabulky). Takže pro správnost `formTable`
musí být nastavený jen z `open_form` action. Pro běžné Create/Edit přes
toolbar zůstává `null` a fallback na `meta.table`. Code path zachovává
zpětnou kompatibilitu.

---

## 6. Edge cases & non-goals

**Empty actions** — když `detail.actions` chybí nebo je prázdné, lišta se
nerenderuje (`{#if (detail?.actions ?? []).length > 0}`).

**Action dispatch při missing `onAction`** — `ViewerDetail` nevyhodí chybu,
jen no-op (`onAction?.(...)` syntax).

**Custom akce s neznámým `kind`** — frontend `handleDetailAction` log warning
a no-op. Backend by neměl posílat neznámé kindy; defenzivně neházet error.

**Confirm na dropdown** — pokud `kind: 'dropdown'` má `confirm`, dispatcher
ho **ignoruje** — confirm dialog se hodí jen pro single-shot button. (Pro
dropdown je výběr položky sám o sobě explicitní akcí; další confirm je
zbytečný a otravný.)

**Re-check na Resolved/Dismissed alertu** — endpoint to zvládne; pokud
podmínka stále trvá, vznikne **nový** alert se stejným `(check_id, finding_key)`
ale jiným `id`. Stávající Resolved/Dismissed záznam zůstane v historii.
To je správné chování — viz původní task `task-core-alerts-mvp.md` sekce 2,
bod 6 a reconciler algoritmus.

**Out of scope tohoto tasku:**

- `kind: 'open_viewer'` — jen plumbing s warning toastem
- Lokalizace přes cfgItem (zatím inline mapa cs/en)
- Per-action ikony (`<Icon>` next to label) — doplníme až bude potřeba
- Keyboard shortcuts pro snooze (`s` → snooze, `d` → dismiss) — UX polish
- Hromadné akce (multi-select + snooze N alertů najednou)

---

## 7. Definition of done

- [ ] `AlertsViewer::renderDetail()` vrací `actions` array s vestavěnými
      i custom akcemi
- [ ] V čerstvém DS po `alerts-run` je v detailu alertu "Chybí vlastní Osoba"
      vidět **5 buttonů**: Odložit ▾ · Zamítnout · Zkontrolovat znovu ·
      Přidat vlastní Osobu *(primary)*
- [ ] Klik na **Odložit → 1 h** přepne alert na Snoozed, list se přefiltruje
      (default "Otevřené" filter alert ukáže, protože snooze ještě platí
      a `snoozed_until - NOW > 0`; ale `last_seen_at` se nemění při manuálním
      snooze, takže pozice ve výpisu zůstává podobně). Detail po refresh ukazuje
      "Odloženo do {datum}", action set je teď: Vrátit · Zamítnout · Zkontrolovat.
- [ ] Klik na **Zamítnout** zobrazí confirm dialog. Po potvrzení alert přejde
      na Dismissed (mizí z default Otevřené filtru, je vidět v "Zamítnuté").
- [ ] Klik na **Zkontrolovat znovu** spustí check synchronně. Pokud problém
      stále trvá, alert zůstane (případně updated last_seen_at); pokud je
      vyřešen, alert přejde na Resolved.
- [ ] Klik na **Přidat vlastní Osobu** otevře `FormDialog` pro
      `base_persons_persons` s preset `{is_own: true}`. Po uložení:
      `FormDialog` se zavře, alerts viewer se refreshne. (Alert sám se
      neautoresolvuje — to udělá cron nebo manuální re-check; ale je to OK,
      uživatel ho může ručně re-checknout.)
- [ ] Custom akce `kind: 'open_viewer'` na klik zobrazí toast/alert
      "Not yet implemented" (žádná chyba v konzoli).
- [ ] `ViewerDetail.svelte` lišta akcí se nezobrazí, pokud `detail.actions`
      chybí nebo je prázdné (zpětná kompatibilita pro ostatní vieweri).
- [ ] Žádný stávající viewer (persons, mail, docs) není rozbitý.

---

## 8. Pořadí práce

1. Backend `AlertsViewer::renderDetail()` — vrať `actions`. Otestuj curlem
   (`curl /_ui/viewer/core.alerts.alerts/detail/1` ukáže nové pole).
2. `ViewerDetail.svelte` — přidej rendering bloku akcí (přes button +
   Popover). Otestuj že lišta naskočí.
3. `Viewer.svelte` — přidej `handleDetailAction` a `formTable` state.
4. Vyzkoušej snooze (1h), dismiss, unsnooze, recheck v UI.
5. Vyzkoušej "Přidat vlastní Osobu" → form se otevře → uložení → refresh.
6. Smoke test ostatních viewerů (persons, mail) — že se nic neporušilo.