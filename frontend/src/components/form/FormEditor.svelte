<script>
  import { get, post, put } from '../../api/client.js';
  import FormTab from './FormTab.svelte';
  import FormStateBadge from './FormStateBadge.svelte';
  import FormStateBar from './FormStateBar.svelte';

  let {
    table,
    recordId = null,
    onClose,
    onSaved,
    defaultData = {},
  } = $props();

  let formDef = $state(null);
  let formData = $state({});
  let fieldErrors = $state({});
  let activeTabId = $state(null);
  let saving = $state(false);
  let recalculating = $state(false);
  let loadError = $state(null);

  const formTitle = $derived(
    recordId != null
      ? (formDef?.title ?? '')
      : (formDef?.title_new ?? 'Nový záznam')
  );

  const isDisabled = $derived(
    saving || recalculating || (formDef?.doc_states?.read_only ?? false)
  );

  // ── Load ────────────────────────────────────────────────────────────────────

  async function loadForm(tbl, id) {
    loadError = null;
    const path = id != null
      ? `/_ui/form/${tbl}/meta/${id}`
      : `/_ui/form/${tbl}/meta`;
    const res = await get(path);
    if (!res?.success) {
      loadError = res?.error?.message ?? 'Nepodařilo se načíst formulář.';
      return;
    }
    formDef = res.data.formDefinition;
    formData = res.data.data ?? buildDefaultData(res.data.formDefinition);
    activeTabId = formDef.tabs[0]?.id ?? null;
  }

  function buildDefaultData(def) {
    const data = { ...defaultData };
    for (const tab of def.tabs ?? []) {
      for (const el of flatElements(tab.elements ?? [])) {
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
    loadForm(tbl, id);
  });

  // ── Recalculate ─────────────────────────────────────────────────────────────

  async function handleTrigger(columnId) {
    recalculating = true;
    const res = await post(`/_ui/form/${table}/recalculate`, {
      id: recordId ?? null,
      changedColumn: columnId,
      data: formData,
    });
    if (res?.success) {
      formDef = res.data.formDefinition;
      formData = res.data.data;
      const tabIds = formDef.tabs.map(t => t.id);
      if (!tabIds.includes(activeTabId)) activeTabId = tabIds[0] ?? null;
    }
    recalculating = false;
  }

  // ── Save ────────────────────────────────────────────────────────────────────

  async function handleSave() {
    saving = true;
    fieldErrors = {};
    loadError = null;
    const isNew = recordId == null;
    const res = isNew
      ? await post(`/_ui/form/${table}/save`, formData)
      : await put(`/_ui/form/${table}/save/${recordId}`, formData);

    if (res?.success) {
      onSaved?.(res.data);
      await loadForm(table, res.id ?? recordId);
    } else if (res?.error?.code === 'VALIDATION_ERROR' && res?.error?.details) {
      const errs = {};
      for (const e of res.error.details) {
        if (e.field) errs[e.field] = e.message;
      }
      fieldErrors = errs;
      switchToErrorTab(errs);
    } else {
      loadError = res?.error?.message ?? 'Nepodařilo se uložit záznam.';
    }
    saving = false;
  }

  // ── Doc state transition ────────────────────────────────────────────────────

  async function handleTransition(targetState) {
    saving = true;
    loadError = null;
    const res = await put(`/_ui/form/${table}/save/${recordId}`, { docState: targetState });
    if (res?.success) {
      await loadForm(table, recordId);
    } else {
      loadError = res?.error?.message ?? 'Nepodařilo se změnit stav.';
    }
    saving = false;
  }

  // ── Tab error detection ─────────────────────────────────────────────────────

  function tabHasError(tabId) {
    const errCols = new Set(Object.keys(fieldErrors));
    if (errCols.size === 0) return false;
    const tab = formDef?.tabs?.find(t => t.id === tabId);
    if (!tab) return false;
    return flatElements(tab.elements ?? []).some(el => el.column && errCols.has(el.column));
  }

  function switchToErrorTab(errs) {
    const colToTab = {};
    for (const tab of formDef?.tabs ?? []) {
      for (const el of flatElements(tab.elements ?? [])) {
        if (el.column) colToTab[el.column] = tab.id;
      }
    }
    for (const tab of formDef?.tabs ?? []) {
      for (const col of Object.keys(errs)) {
        if (colToTab[col] === tab.id) { activeTabId = tab.id; return; }
      }
    }
  }

  function flatElements(elements) {
    return elements.flatMap(el =>
      el.type === 'group' ? flatElements(el.elements ?? []) : [el]
    );
  }
</script>

<div class="shpd-form-editor">

  <!-- Header -->
  <div class="shpd-form-editor__header">
    <button class="shpd-form-editor__back" onclick={onClose} aria-label="Zpět">←</button>
    <h2 class="shpd-form-editor__title">{formTitle}</h2>
    {#if formDef?.doc_states}
      <FormStateBadge docStates={formDef.doc_states} />
    {/if}
  </div>

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

  <!-- Content -->
  <div class="shpd-form-editor__content">
    {#if loadError}
      <div class="shpd-form-editor__error-banner">{loadError}</div>
    {/if}

    {#if !formDef}
      <div class="shpd-form-editor__loading">Načítám…</div>
    {:else}
      {#each formDef.tabs as tab (tab.id)}
        <div class="shpd-form-editor__tab-content" hidden={tab.id !== activeTabId}>
          <FormTab
            {tab}
            bind:formData
            {fieldErrors}
            disabled={isDisabled}
            onTrigger={handleTrigger}
            parentId={recordId}
          />
        </div>
      {/each}
    {/if}
  </div>

  <!-- Bottom toolbar -->
  {#if formDef}
    <FormStateBar
      docStates={formDef.doc_states ?? null}
      {saving}
      onSave={handleSave}
      onTransition={handleTransition}
    />
  {/if}

</div>

<style>
  .shpd-form-editor {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
    background: var(--shpd-color-bg);
  }

  .shpd-form-editor__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-lg);
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
  }

  .shpd-form-editor__back {
    background: none;
    border: none;
    font-size: var(--shpd-font-size-lg);
    cursor: pointer;
    color: var(--shpd-color-text-secondary);
    padding: var(--shpd-space-xs);
    border-radius: var(--shpd-radius-sm);
  }
  .shpd-form-editor__back:hover {
    background: var(--shpd-color-bg-secondary);
    color: var(--shpd-color-text);
  }

  .shpd-form-editor__title {
    flex: 1;
    font-size: var(--shpd-font-size-lg);
    font-weight: 600;
    margin: 0;
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

  .shpd-form-editor__loading {
    padding: var(--shpd-space-lg);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-form-editor__error-banner {
    margin: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    background: #fef2f2;
    border: 1px solid var(--shpd-color-danger);
    border-radius: var(--shpd-radius-md);
    color: var(--shpd-color-danger);
    font-size: var(--shpd-font-size-sm);
  }
</style>
