<script>
  import { untrack } from 'svelte';
  import { get, post, put } from '../../api/client.js';
  import FormTab from './FormTab.svelte';
  import AttachmentPanel from './AttachmentPanel.svelte';
  import FormStateBar from './FormStateBar.svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  let {
    table,
    recordId = null,
    onClose,
    onSaved,
    onFormLoaded,
    onDirtyChange,
    defaultData = {},
    /** Jen k prohlížení (řádek read-only rodiče ze sub-tabulky): všechna pole
     *  vypnutá, nikdy dirty, FormStateBar bez Uložit a přechodů. */
    readOnly = false,
  } = $props();

  let formDef = $state(null);
  let formData = $state({});
  // Chyby vázané na konkrétní pole formuláře (column → hláška). Zobrazují se
  // vedle inputu, aktivují tabovou tečku a vykreslí se i v banneru s labelem.
  let fieldErrors = $state({});
  // Form-level chyby ze serveru (`_form`, neznámý/prázdný field). Nemají vazbu
  // na konkrétní pole — žijí jen v banneru nad tabbarem. {message, code}.
  let formErrors = $state([]);
  // Map column → {id, primary, secondary} pre-resolved lookup popisů.
  // Při (re)loadu a po recalculate/save nahrazujeme celým státem ze serveru
  // (server vrací autoritativní obraz pro všechna lookup pole — chybějící klíč
  // znamená, že dané pole v `data` nemá hodnotu, tj. resolved má zmizet).
  // Výběr v LookupInput aktualizuje per-column přes handleResolveChange.
  let dataResolved = $state({});
  let activeTabId = $state(null);
  let saving = $state(false);
  let recalculating = $state(false);
  let loadError = $state(null);
  // currentId sleduje aktuální ID záznamu — může se změnit po uložení nového záznamu
  let currentId = $state(null);
  // Snapshot dat po posledním načtení/uložení — slouží k detekci dirty stavu
  let loadedDataSnapshot = $state(null);
  // Header info ze serveru — aktualizuje se jen v loadForm (z formDef.header_info),
  // NE v handleTrigger. Tím hlavička modalu odráží uložená data, ne neuložené změny.
  let savedHeaderInfo = $state(null);

  const formTitle = $derived(
    currentId != null
      ? (formDef?.title ?? '')
      : (formDef?.title_new ?? t('form.titleNew'))
  );

  // Read-only = externí prop (prohlížení) NEBO read-only stav dokumentu.
  // isDisabled navíc zahrnuje probíhající save/recalculate — sub-tabulka
  // dostává obojí zvlášť, aby během ukládání rodiče nepřepínala Upravit/Smazat
  // na Zobrazit (jen je dočasně vypne).
  const isReadOnly = $derived(readOnly || (formDef?.doc_states?.read_only ?? false));
  const isDisabled = $derived(saving || recalculating || isReadOnly);

  // Notifikuje rodiče (FormDialog) o aktuálním titulku a stavu — header modalu
  // tak může zobrazit titulek, FormStateBadge a subtitle z header_info.
  $effect(() => {
    if (formDef) {
      onFormLoaded?.({
        title: formTitle,
        docStates: formDef.doc_states ?? null,
        headerInfo: savedHeaderInfo,
      });
    }
  });

  // Detekce dirty stavu — porovnání aktuálních dat se snapshotem po posledním
  // načtení / uložení. ReadOnly formuláře nikdy nejsou dirty (uživatel nemůže nic změnit).
  // Recalculate NEAKTUALIZUJE snapshot — přepočítaná data nejsou uložená v DB,
  // takže změna spuštěná triggerem zachová dirty stav (uživatel musí Uložit).
  const isDirty = $derived.by(() => {
    if (!loadedDataSnapshot) return false;
    if (isReadOnly) return false;
    return !shallowEqual(formData, loadedDataSnapshot);
  });

  // Propagace dirty stavu do rodiče (FormDialog) — používá se při pokusu o zavření.
  $effect(() => {
    onDirtyChange?.(isDirty);
  });

  function shallowEqual(a, b) {
    if (a === b) return true;
    if (!a || !b) return false;
    const keysA = Object.keys(a);
    const keysB = Object.keys(b);
    if (keysA.length !== keysB.length) return false;
    for (const k of keysA) {
      if (a[k] !== b[k]) {
        // Speciální případ: null vs '' z formuláře — nepovažujeme za změnu.
        // Server vrací null u nullable polí, formulář je interně reprezentuje jako ''.
        if ((a[k] == null || a[k] === '') && (b[k] == null || b[k] === '')) continue;
        return false;
      }
    }
    return true;
  }

  // ── Load ────────────────────────────────────────────────────────────────────

  // `keepTab`: po tichém reloadu (změna řádku sub-tabulky) zůstat na aktivním
  // tabu — jinak by každé přidání řádku vrátilo uživatele na první tab.
  async function loadForm(tbl, id, { keepTab = false } = {}) {
    loadError = null;
    let path = id != null
      ? `/_ui/form/${tbl}/meta/${id}`
      : `/_ui/form/${tbl}/meta`;
    // For new records propagate defaultData (e.g. doc_type from a per-type
    // viewer) to the server so server-side form code can compute coherent
    // initial values (e.g. pre-select a matching number_series).
    if (id == null && defaultData && Object.keys(defaultData).length > 0) {
      const qs = new URLSearchParams();
      for (const [k, v] of Object.entries(defaultData)) {
        if (v == null) continue;
        qs.append(`defaults[${k}]`, String(v));
      }
      const query = qs.toString();
      if (query) path += `?${query}`;
    }
    const res = await get(path);
    if (!res?.success) {
      loadError = res?.error ? translateError(res.error) : t('form.loadFailed');
      return;
    }
    formDef = res.data.formDefinition;
    // Header info ze serveru — null pro nový záznam, pro existující záznam
    // se aktualizuje (přepíše předchozí hodnotu) i po save → reload cyklu.
    savedHeaderInfo = formDef.header_info ?? null;
    // Sestav výchozí data: nejdřív prázdné stringy pro všechna pole,
    // pak přepiš skuteČnými daty ze serveru (včetně defaultů pro nový záznam)
    const defaults = buildDefaultData(res.data.formDefinition);
    formData = res.data.data ? { ...defaults, ...res.data.data } : defaults;
    // Pre-resolvované lookup hodnoty — server posílá map column → {id, primary, secondary}.
    // Při (re)loadu nahrazujeme celé, aby se zbavily starých keší pro pole,
    // která už nejsou v aktuálním FormDef.
    dataResolved = res.data.dataResolved ?? {};
    // Snapshot dat — po načtení formulář není dirty
    loadedDataSnapshot = { ...formData };
    const tabIds = formDef.tabs.map(t => t.id);
    if (!keepTab || !tabIds.includes(activeTabId)) {
      activeTabId = formDef.tabs[0]?.id ?? null;
    }
  }

  // Sub-tabulka nahlásila změnu řádku (přidání / úprava / smazání). Server
  // mohl přepočítat odvozené hodnoty rodiče (součty dokladu v
  // DocRowsDocument::recomputeHeader), které formulář drží v formData
  // a header_info — přenačteme je. Jen když rodič nemá neuložené změny:
  // reload by je zahodil; při dirty stavu se hodnoty obnoví po Uložit.
  async function handleSubtableChanged() {
    if (currentId == null || isDirty || saving || recalculating) return;
    await loadForm(table, currentId, { keepTab: true });
  }

  function buildDefaultData(def) {
    const data = { ...defaultData };
    for (const tab of def.tabs ?? []) {
      for (const el of tabFields(tab)) {
        if (el.column && !(el.column in data)) {
          data[el.column] = '';
        }
      }
    }
    return data;
  }

  $effect(() => {
    const tbl = table;
    const id = recordId;
    // Re-load jen na změnu (table, recordId). `untrack` zabraňuje tomu,
    // aby reaktivní reads uvnitř `loadForm` (typicky prop `defaultData`,
    // čtený synchronně před prvním `await`) přidaly do efektu skryté
    // závislosti. Bez `untrack` save nového záznamu způsobí race condition:
    // rodič po `onSaved` typicky resetuje `formDefaultData`, což spustí
    // re-run efektu, ten currentId přepíše zpět na recordId (null) a
    // souběžný `loadForm(table, null)` přepíše data uloženého záznamu
    // prázdným formulářem. Viz docs/edit-forms.md sekce 19.
    untrack(() => {
      currentId = id;
      loadForm(tbl, id);
    });
  });

  // ── Recalculate ─────────────────────────────────────────────────────────────

  async function handleTrigger(columnId) {
    recalculating = true;
    const res = await post(`/_ui/form/${table}/recalculate`, {
      id: currentId ?? null,
      changedColumn: columnId,
      data: sanitizeFormData(formData),
    });
    if (res?.success) {
      formDef = res.data.formDefinition;
      formData = res.data.data;
      // dataResolved nahradíme celý — server vrací autoritativní mapu pro všechna
      // lookup pole v aktuálním form-state. Klíče chybějící v response znamenají,
      // že dané pole je null (nebo lookup neresolvoval) — display popis musí
      // zmizet, jinak by zelo cascade reset (změna partnera vynuluje
      // partner_address) přežilo staromu displej v UI.
      dataResolved = res.data.dataResolved ?? {};
      // Snapshot se NEAKTUALIZUJE — recalculate neukládá do DB, takže přepočítaná data
      // jsou stále neuložená změna. Dirty stav zůstává true a uživatel musí explicitně Uložit.
      const tabIds = formDef.tabs.map(t => t.id);
      if (!tabIds.includes(activeTabId)) activeTabId = tabIds[0] ?? null;
    }
    recalculating = false;
  }

  // Lookup výběr / clear v `LookupInput` propaguje sem přes callback. Aktualizujeme
  // per-column keš dataResolved — během editace bez recalculate je tohle jediný
  // zdroj změn; následný recalculate / save pak keš přepne na serverový stav.
  function handleResolveChange(column, resolvedItem) {
    if (!column) return;
    if (resolvedItem === null) {
      const next = { ...dataResolved };
      delete next[column];
      dataResolved = next;
    } else {
      dataResolved = { ...dataResolved, [column]: resolvedItem };
    }
  }

  // ── Save ────────────────────────────────────────────────────────────────────

  async function handleSave() {
    saving = true;
    clearValidationErrors();
    loadError = null;
    const isNew = currentId == null;
    const res = isNew
      ? await post(`/_ui/form/${table}/save`, sanitizeFormData(formData))
      : await put(`/_ui/form/${table}/save/${currentId}`, sanitizeFormData(formData));

    if (res?.success) {
      onSaved?.(res.data);
      currentId = res.data?.id ?? currentId;
      await loadForm(table, currentId);  // Reload bez zavření
    } else if (res?.error?.code === 'VALIDATION_ERROR' && res?.error?.details) {
      applyValidationErrors(res.error.details);
    } else {
      loadError = res?.error ? translateError(res.error) : t('form.saveFailed');
    }
    saving = false;
  }

  // ── Doc state transition ────────────────────────────────────────────────────

  async function handleTransition(targetState, closeForm = false) {
    saving = true;
    clearValidationErrors();
    loadError = null;

    if (currentId == null) {
      // Nový záznam: ulož celý formulář s požadovaným stavem
      const data = { ...sanitizeFormData(formData), docState: targetState };
      const res = await post(`/_ui/form/${table}/save`, data);
      if (res?.success) {
        onSaved?.(res.data);
        if (closeForm) {
          // Zavření obejde dirty check — data byla právě uložena. Bypass je nutný,
          // protože Svelte 5 reaktivita je asynchronní a FormDialog ještě nevidí
          // aktualizovaný isDirty stav.
          onClose?.({ force: true });
        } else {
          currentId = res.data?.id ?? null;
          await loadForm(table, currentId);
        }
      } else if (res?.error?.code === 'VALIDATION_ERROR' && res?.error?.details) {
        applyValidationErrors(res.error.details);
      } else {
        loadError = res?.error ? translateError(res.error) : t('form.saveFailed');
      }
    } else {
      // Existující záznam: nejdřív ulož data, pak přechod stavu
      const saveRes = await put(`/_ui/form/${table}/save/${currentId}`, sanitizeFormData(formData));
      if (!saveRes?.success) {
        if (saveRes?.error?.code === 'VALIDATION_ERROR' && saveRes?.error?.details) {
          applyValidationErrors(saveRes.error.details);
        } else {
          loadError = saveRes?.error ? translateError(saveRes.error) : t('form.saveFailed');
        }
        saving = false;
        return;
      }
      // Přechod stavu (DRUHÝ PUT). Dřív tahle větev rozbalování `error.details`
      // přeskakovala — validace naostro (newState !== oldState) tak skončila jen
      // generickým „Validace selhala". Teď používá stejný helper jako ostatní větve.
      const res = await put(`/_ui/form/${table}/save/${currentId}`, { docState: targetState });
      if (res?.success) {
        onSaved?.(res.data);
        if (closeForm) {
          // Zavření obejde dirty check — data byla právě uložena.
          onClose?.({ force: true });
        } else {
          await loadForm(table, currentId);
        }
      } else if (res?.error?.code === 'VALIDATION_ERROR' && res?.error?.details) {
        applyValidationErrors(res.error.details);
      } else {
        loadError = res?.error ? translateError(res.error) : t('form.transitionFailed');
      }
    }

    saving = false;
  }

  // ── Tab error detection ─────────────────────────────────────────────────────

  // Form-level chyby, jejichž `field` odpovídá id nějakého tabu (typicky
  // subtable tab — např. `rows` → tab „Řádky"). Nemají column, ale patří
  // konkrétnímu tabu, takže ten tab zbarví tečkou i aktivují switchToErrorTab.
  function errorTabIds() {
    const tabIds = new Set((formDef?.tabs ?? []).map(t => t.id));
    const out = new Set();
    for (const err of formErrors) {
      if (err.field && tabIds.has(err.field)) out.add(err.field);
    }
    return out;
  }

  function tabHasError(tabId) {
    if (errorTabIds().has(tabId)) return true;
    const errCols = new Set(Object.keys(fieldErrors));
    if (errCols.size === 0) return false;
    const tab = formDef?.tabs?.find(t => t.id === tabId);
    if (!tab) return false;
    return tabFields(tab).some(el => el.column && errCols.has(el.column));
  }

  // Přepne na první tab (v pořadí tabů), který obsahuje field chybu NEBO
  // tab-level chybu (errorTabIds). Čte error state přímo — volá se až po jejich
  // nastavení v applyValidationErrors.
  function switchToErrorTab() {
    const colToTab = {};
    for (const tab of formDef?.tabs ?? []) {
      for (const el of tabFields(tab)) {
        if (el.column) colToTab[el.column] = tab.id;
      }
    }
    const fieldCols = Object.keys(fieldErrors);
    const tabIdsWithError = errorTabIds();
    for (const tab of formDef?.tabs ?? []) {
      if (tabIdsWithError.has(tab.id)) { activeTabId = tab.id; return; }
      if (fieldCols.some(col => colToTab[col] === tab.id)) { activeTabId = tab.id; return; }
    }
  }

  /**
   * Vrátí ploché pole field-elements pro daný tab (rozbalí inline groups).
   * Pro non-fields taby (subtable/attachments) vrací prázdné pole.
   */
  function tabFields(tab) {
    if (!tab || tab.type === 'subtable' || tab.type === 'attachments') return [];
    const out = [];
    for (const section of tab.sections ?? []) {
      for (const column of section.columns ?? []) {
        for (const el of column.elements ?? []) {
          if (el.type === 'inline') {
            for (const inner of el.elements ?? []) out.push(inner);
          } else {
            out.push(el);
          }
        }
      }
    }
    return out;
  }

  // Sestaví mapu column → element pro všechna pole ve všech tabech
  function buildElementMap() {
    const map = {};
    for (const tab of formDef?.tabs ?? []) {
      for (const el of tabFields(tab)) {
        if (el.column) map[el.column] = el;
      }
    }
    return map;
  }

  // ── Validation errors ─────────────────────────────────────────────────────

  /**
   * Rozbalí `error.details` z VALIDATION_ERROR response do field-level a
   * form-level chyb. Field je „field-level" jen pokud odpovídá nějakému
   * sloupci ve formuláři (přes buildElementMap). Vše ostatní (`_form`, `rows`,
   * prázdný field, neznámý sloupec) jde do formErrors. Čistá funkce — vrací
   * objekt, nesahá na state (testovatelná samostatně).
   */
  function extractValidationErrors(details) {
    const elMap = buildElementMap();
    const fieldErrs = {};
    const formErrs = [];
    for (const d of details ?? []) {
      if (d.field && elMap[d.field]) {
        fieldErrs[d.field] = d.message;
      } else {
        // `field` zachováváme — form-level chyba, jejíž field odpovídá id tabu
        // (typicky subtable, např. `rows`), zbarví ten tab (viz errorTabIds).
        formErrs.push({ message: d.message ?? '', code: d.code ?? '', field: d.field ?? '' });
      }
    }
    return { fieldErrors: fieldErrs, formErrors: formErrs };
  }

  // Nastaví oba error state ze serverové odpovědi a přepne na tab s field chybou.
  // Sdílí ho všechny save/transition větve — jediné místo, kde se details rozbaluje.
  function applyValidationErrors(details) {
    const extracted = extractValidationErrors(details);
    fieldErrors = extracted.fieldErrors;
    formErrors = extracted.formErrors;
    switchToErrorTab();
  }

  // Vyčistí oba error state — volá se na začátku každého save/transition pokusu.
  function clearValidationErrors() {
    fieldErrors = {};
    formErrors = [];
  }

  // Sestaví field-level položky banneru s labelem pole (fallback na column).
  function fieldEntriesForBanner() {
    const elMap = buildElementMap();
    const out = [];
    for (const [column, message] of Object.entries(fieldErrors)) {
      const el = elMap[column];
      const label = el?.label ?? column;
      out.push({ column, label, message });
    }
    return out;
  }

  // Sanitizuje data před odesláním:
  // - prázdný string → null pro non-varchar typy (date, number...)
  // - prázdný string → null pro nullable varchar pole
  // - string → number pro select s numerickými options
  function sanitizeFormData(data) {
    const elMap = buildElementMap();
    const result = {};
    for (const [key, value] of Object.entries(data)) {
      const el = elMap[key];
      if (el === undefined) {
        result[key] = value;
        continue;
      }
      // Select s numerickými options: převeď string na number
      if (el.type === 'select' && value !== null && value !== '') {
        const firstOpt = el.options?.[0];
        if (firstOpt && typeof firstOpt.value === 'number') {
          result[key] = Number(value);
          continue;
        }
      }
      // Prázdný string: pro non-text input_type a pro nullable pole → null
      if (value === '') {
        const isText = el.type === 'input' && (el.input_type === 'text' || el.input_type == null);
        if (!isText) {
          result[key] = null;
          continue;
        }
        // nullable varchar: prázdný string také jako null
        // (server akceptuje oba, ale null je čistší)
      }
      result[key] = value;
    }
    return result;
  }
</script>

<div class="shpd-form-editor">

  <!-- Tab bar -->
  {#if formDef && formDef.tabs.length > 1}
    <div class="shpd-form-editor__tab-bar">
      {#each formDef.tabs as tab (tab.id)}
        <button
          class="shpd-form-editor__tab"
          class:shpd-form-editor__tab--active={activeTabId === tab.id}
          class:shpd-form-editor__tab--error={tabHasError(tab.id)}
          onclick={() => activeTabId = tab.id}
        >
          {tab.label}
          {#if tabHasError(tab.id)}
            <span class="shpd-form-editor__tab-error-dot" aria-hidden="true"></span>
          {/if}
        </button>
      {/each}
    </div>
  {/if}

  <!-- Validační banner — form-level i field-level chyby z VALIDATION_ERROR.
       Žije nad tab-content (mimo scrollovaný obsah) — je globální o formuláři,
       ne o aktuálním tabu. Form-level chyby holé, field-level s labelem pole. -->
  {#if formErrors.length > 0 || Object.keys(fieldErrors).length > 0}
    <div class="shpd-form-editor__validation-banner" role="alert">
      <div class="shpd-form-editor__validation-banner-title">
        {t('form.validation.bannerTitle')}
      </div>
      <ul class="shpd-form-editor__validation-banner-list">
        {#each formErrors as err}
          <li>{err.message}</li>
        {/each}
        {#each fieldEntriesForBanner() as { label, message }}
          <li><strong>{label}:</strong> {message}</li>
        {/each}
      </ul>
    </div>
  {/if}

  <!-- Content -->
  <div class="shpd-form-editor__content">
    {#if loadError}
      <div class="shpd-form-editor__error-banner">{loadError}</div>
    {/if}

    {#if !formDef}
      <div class="shpd-form-editor__loading">{t('common.loading')}</div>
    {:else}
      {#each formDef.tabs as tab (tab.id)}
        <div class="shpd-form-editor__tab-content" hidden={tab.id !== activeTabId}>
          {#if tab.type === 'attachments'}
            <AttachmentPanel
              tableId={tab.table_id}
              recordId={currentId}
              disabled={isDisabled}
              changeEndpoint={tab.change_endpoint}
            />
          {:else}
            <FormTab
              {tab}
              bind:formData
              {fieldErrors}
              {dataResolved}
              disabled={isDisabled}
              readOnly={isReadOnly}
              onTrigger={handleTrigger}
              onResolveChange={handleResolveChange}
              parentId={currentId}
              parentTable={table}
              onSubtableChanged={handleSubtableChanged}
            />
          {/if}
        </div>
      {/each}
    {/if}
  </div>

  <!-- Bottom toolbar -->
  {#if formDef}
    <FormStateBar
      docStates={formDef.doc_states ?? null}
      {saving}
      {readOnly}
      onSave={handleSave}
      onTransition={handleTransition}
    />
  {/if}

</div>

<style>
  .shpd-form-editor {
    display: flex;
    flex-direction: column;
    /* Vyplní celý dostupný prostor v Modal body. min-height: 0 je nutné,
       aby se flex children mohly správně přepočítat při overflow. */
    flex: 1;
    min-height: 0;
    overflow: hidden;
    background: var(--shpd-color-bg);
  }

  /* Tab bar */
  .shpd-form-editor__tab-bar {
    display: flex;
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
    overflow-x: auto;
  }

  .shpd-form-editor__tab {
    position: relative;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border: none;
    border-bottom: 2px solid transparent;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    white-space: nowrap;
    transition: color 0.12s, border-color 0.12s;
  }
  .shpd-form-editor__tab:hover { color: var(--shpd-color-text); }
  .shpd-form-editor__tab--active {
    color: var(--shpd-color-primary);
    border-bottom-color: var(--shpd-color-primary);
    font-weight: 600;
  }
  .shpd-form-editor__tab--error { color: var(--shpd-color-danger); }
  .shpd-form-editor__tab--error.shpd-form-editor__tab--active {
    border-bottom-color: var(--shpd-color-danger);
  }

  .shpd-form-editor__tab-error-dot {
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--shpd-color-danger);
    margin-left: 4px;
    vertical-align: middle;
  }

  /* Content */
  .shpd-form-editor__content {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
  }

  /* Tab obsahující fill sloupec (např. náhledy příloh) roztáhne obsah
     na celou výšku scroll containeru — sekce končí u spodní hrany těla. */
  .shpd-form-editor__tab-content:has(:global(.shpd-form-column--fill)) {
    display: flex;
    flex-direction: column;
    min-height: 100%;
  }

  /* display:flex výše by jinak přebil UA styl pro [hidden]. */
  .shpd-form-editor__tab-content[hidden] {
    display: none !important;
  }

  .shpd-form-editor__loading {
    padding: var(--shpd-space-lg);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-form-editor__error-banner {
    margin: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    background: var(--shpd-color-danger-soft);
    border: 1px solid var(--shpd-color-danger);
    border-radius: var(--shpd-radius-md);
    color: var(--shpd-color-danger);
    font-size: var(--shpd-font-size-sm);
  }

  /* Validační banner — sladěn s __error-banner (červené téma), ale samostatná
     třída, aby šel nezávisle styliovat. flex-shrink: 0 ho drží nad scrollovaným
     contentem. */
  .shpd-form-editor__validation-banner {
    margin: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    background: var(--shpd-color-danger-soft);
    border: 1px solid var(--shpd-color-danger);
    border-radius: var(--shpd-radius-md);
    color: var(--shpd-color-danger);
    font-size: var(--shpd-font-size-sm);
    flex-shrink: 0;
  }

  .shpd-form-editor__validation-banner-title {
    font-weight: 600;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-form-editor__validation-banner-list {
    margin: 0;
    padding-left: var(--shpd-space-lg);
    list-style: disc;
  }

  .shpd-form-editor__validation-banner-list li + li {
    margin-top: 2px;
  }

  .shpd-form-editor__validation-banner-list strong {
    font-weight: 600;
  }
</style>
