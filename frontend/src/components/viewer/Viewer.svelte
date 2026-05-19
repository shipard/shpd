<script>
  import { get, post } from '../../api/client.js';
  import {
    runDueAlertChecks,
    snoozeAlert,
    dismissAlert,
    unsnoozeAlert,
    runAlertCheck,
  } from '../../api/alerts.js';
  import ViewerRow from './ViewerRow.svelte';
  import ViewerDetail from './ViewerDetail.svelte';
  import ViewerToolbar from './ViewerToolbar.svelte';
  import FormDialog from '../form/FormDialog.svelte';
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';

  let { tab } = $props();

  // --- Meta state ---
  let meta = $state(null);
  let loadingMeta = $state(true);

  // --- Doc state tabs ---
  let activeViewGroup = $state('active'); // 'active' | 'archive' | 'trash' | 'all'

  const VIEW_GROUP_LABEL_KEYS = {
    active:  'viewer.tab.active',
    archive: 'viewer.tab.archive',
    trash:   'viewer.tab.trash',
  };

  // Tabs to display: viewGroups from meta + always the "all" tab
  let viewTabs = $derived(() => {
    const groups = meta?.viewGroups ?? [];
    const tabs = groups.map(vg => ({
      id: vg,
      label: VIEW_GROUP_LABEL_KEYS[vg] ? t(VIEW_GROUP_LABEL_KEYS[vg]) : vg,
    }));
    tabs.push({ id: 'all', label: t('viewer.tab.all') });
    return tabs;
  });

  let hasViewGroups = $derived((meta?.viewGroups ?? []).length > 0);

  // --- Row list state ---
  let rows = $state([]);
  let hasMore = $state(false);
  let loadingRows = $state(false);
  let loadingMore = $state(false);
  let pageNumber = $state(0);

  // Active search term used for API calls (updated after debounce)
  let activeSearch = $state('');

  // --- Detail state ---
  let selectedRowId = $state(null);
  let detail = $state(null);
  let detailToolbar = $state([]);
  let detailLoading = $state(false);

  // --- Form dialog state ---
  let formOpen = $state(false);
  let editRecordId = $state(null);
  let formDefaultData = $state({});
  // Pokud non-null, FormDialog otevírá formulář pro tuto tabulku místo
  // `meta.table`. Používá se pro custom detail akce `kind: 'open_form'`,
  // kde detail.action.target.table cílí na jinou tabulku než viewer
  // (např. alert v core_alerts_alerts otevírá form pro base_persons_persons).
  let formTable = $state(null);

  // --- Search debounce ---
  let searchTimer = null;

  // --- Refs ---
  let listEl = $state(null);
  let searchInputEl = $state(null);

  // --- Derived ---
  let toolbarActions = $derived(
    selectedRowId != null ? detailToolbar : (meta?.toolbar ?? [])
  );

  // --- Data fetching ---

  async function fetchMeta(viewerId) {
    loadingMeta = true;
    const result = await get(`/_ui/viewer/${viewerId}/meta`);
    if (result?.success) {
      meta = result.data;
    }
    loadingMeta = false;
  }

  /**
   * Fetch rows from the API.
   * Takes explicit parameters to avoid reading $state inside $effect.
   */
  async function fetchRowsExplicit(viewerId, search, viewGroup, page, append = false) {
    if (append) {
      loadingMore = true;
    } else {
      loadingRows = true;
    }

    let path = `/_ui/viewer/${viewerId}/rows?page=${page}`;
    if (search) {
      path += `&search=${encodeURIComponent(search)}`;
    }
    // Send viewGroup filter unless "all" is selected
    if (viewGroup && viewGroup !== 'all') {
      path += `&filter[viewGroup]=${encodeURIComponent(viewGroup)}`;
    }

    const result = await get(path);

    if (result?.success) {
      if (append) {
        rows = [...rows, ...result.data.rows];
      } else {
        rows = result.data.rows;
      }
      hasMore = result.data.hasMore;
    }

    loadingRows = false;
    loadingMore = false;
  }

  /** Convenience wrapper — call from event handlers, NOT from $effect */
  function fetchRows(append = false) {
    fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, pageNumber, append);
  }

  async function fetchDetail(id) {
    detailLoading = true;
    detail = null;
    detailToolbar = meta?.toolbar ?? [];

    const result = await get(`/_ui/viewer/${tab.viewerId}/detail/${id}`);

    if (result?.success) {
      detail = result.data.detail;
      detailToolbar = result.data.toolbar ?? [];
    }

    detailLoading = false;
  }

  // --- Handlers ---

  function handleTabClick(viewGroup) {
    if (viewGroup === activeViewGroup) return;
    activeViewGroup = viewGroup;
    selectedRowId = null;
    detail = null;
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, activeSearch, viewGroup, 0);
  }

  function handleSearchInput(e) {
    const value = e.target.value;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      activeSearch = value;
      selectedRowId = null;
      detail = null;
      pageNumber = 0;
      fetchRowsExplicit(tab.viewerId, value, activeViewGroup, 0);
    }, 300);
  }

  function handleSearchClear() {
    if (searchInputEl) {
      searchInputEl.value = '';
    }
    clearTimeout(searchTimer);
    activeSearch = '';
    selectedRowId = null;
    detail = null;
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, '', activeViewGroup, 0);
  }

  function handleRowClick(row) {
    selectedRowId = row.id;
    fetchDetail(row.id);
  }

  function handleScroll() {
    if (!listEl || !hasMore || loadingMore || loadingRows) return;
    const { scrollTop, scrollHeight, clientHeight } = listEl;
    if (scrollHeight - scrollTop - clientHeight < 100) {
      pageNumber += 1;
      fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, pageNumber, true);
    }
  }

  // --- Reanalyze dialog state ---
  let reanalyzeDialogOpen = $state(false);
  let reanalyzeProfileNdx = $state('');
  let reanalyzeProfiles = $state([]);
  let reanalyzeSubmitting = $state(false);

  function handleToolbarAction(actionId) {
    if (actionId === 'create') {
      editRecordId = null;
      // Per-type viewers (e.g. issued/received invoices) expose
      // newRecordDefaults so the form can pre-fill doc_type and the form
      // class can derive a matching number_series default.
      formDefaultData = meta?.newRecordDefaults ?? {};
      formOpen = true;
    } else if (actionId === 'edit' && selectedRowId != null) {
      editRecordId = selectedRowId;
      formOpen = true;
    } else if (actionId === 'reanalyze' && selectedRowId != null) {
      // Najdi action a vytáhni z meta.profiles seznam profilů.
      const action = (toolbarActions ?? []).find(a => a.id === 'reanalyze');
      reanalyzeProfiles = action?.meta?.profiles ?? [];
      reanalyzeProfileNdx = '';
      reanalyzeDialogOpen = true;
    } else if (actionId === 'runDue') {
      handleRunDue();
    }
  }

  let runDueInProgress = $state(false);

  async function handleRunDue() {
    if (runDueInProgress) return;
    runDueInProgress = true;
    try {
      const result = await runDueAlertChecks();
      if (!result?.success) {
        alert(translateError(result?.error) || 'Alerts run failed');
        return;
      }
      const d = result.data ?? {};
      if (d.checksRun === 0) {
        alert('Žádné kontroly nejsou naplánované ke spuštění.');
      } else {
        const parts = [
          `Spuštěno ${d.checksRun} kontrol`,
          `${d.totalFindings} nálezů (${d.newFindings} nových)`,
        ];
        if ((d.stats?.error ?? 0) > 0) parts.push(`${d.stats.error} chyba`);
        alert(parts.join(', '));
      }
      // Refresh rows so any newly created alerts appear.
      pageNumber = 0;
      fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, 0);
      if (selectedRowId != null) {
        fetchDetail(selectedRowId);
      }
    } finally {
      runDueInProgress = false;
    }
  }

  async function submitReanalyze() {
    if (selectedRowId == null || reanalyzeSubmitting) return;
    reanalyzeSubmitting = true;
    try {
      const body = {};
      if (reanalyzeProfileNdx !== '' && Number(reanalyzeProfileNdx) > 0) {
        body.profile_override_ndx = Number(reanalyzeProfileNdx);
      }
      const result = await post(`/_mail/messages/${selectedRowId}/reanalyze`, body);
      if (result?.success) {
        reanalyzeDialogOpen = false;
        // Refresh detail i list — zpráva mohla změnit stav
        pageNumber = 0;
        fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, 0);
        fetchDetail(selectedRowId);
      } else {
        alert(t('viewer.reanalyze.failed', { msg: translateError(result?.error) }));
      }
    } finally {
      reanalyzeSubmitting = false;
    }
  }

  function closeReanalyzeDialog() {
    if (reanalyzeSubmitting) return;
    reanalyzeDialogOpen = false;
  }

  function handleDetailRefresh() {
    if (selectedRowId != null) {
      fetchDetail(selectedRowId);
      // Také refresh list — apply/reject mohlo přepnout stav zprávy 30→40
      pageNumber = 0;
      fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, 0);
    }
  }

  function refreshAfterAction() {
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, 0);
    if (selectedRowId != null) {
      fetchDetail(selectedRowId);
    }
  }

  async function handleDetailAction(actionId, action, value) {
    if (selectedRowId == null) return;
    const recordId = selectedRowId;

    // Vestavěné alerts akce — identifikujeme podle id. Záměrně neřešíme
    // viewer/tabulku: id je sdílený slovník („snooze", „dismiss", „recheck",
    // „unsnooze") a jiný viewer ho zatím nepoužívá. Až bude víc konzumentů
    // se stejnými id, přesuneme dispatch na backend přes action.kind/target.
    if (actionId === 'snooze') {
      if (!value) return;
      const result = await snoozeAlert(recordId, value);
      if (result?.success) refreshAfterAction();
      else alert(translateError(result?.error));
      return;
    }
    if (actionId === 'dismiss') {
      const result = await dismissAlert(recordId);
      if (result?.success) refreshAfterAction();
      else alert(translateError(result?.error));
      return;
    }
    if (actionId === 'unsnooze') {
      const result = await unsnoozeAlert(recordId);
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

    // Custom akce — generická obsluha podle kind.
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
      const targetViewerId = action.viewerId ?? action.target?.viewerId;
      const targetRecordId = action.recordId ?? action.target?.recordId ?? null;
      if (!targetViewerId) return;
      navigationStore.navigateToViewer(targetViewerId, targetRecordId);
      return;
    }

    console.warn('Unknown detail action', actionId, action);
  }

  function handleFormClose() {
    formOpen = false;
    editRecordId = null;
    formTable = null;
    formDefaultData = {};
  }

  function handleFormSaved() {
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, 0);
    if (selectedRowId != null) {
      fetchDetail(selectedRowId);
    }
    // Reset custom open_form state — uložení záznamu z detail akce nemá
    // důvod hned znovu otvírat stejný formulář, refresh listu/detailu stačí.
    formTable = null;
    formDefaultData = {};
  }

  // Re-initialize ONLY when the viewer tab changes.
  // IMPORTANT: this $effect must not read any $state other than tab.viewerId.
  $effect(() => {
    const viewerId = tab.viewerId;

    // Reset all state
    meta = null;
    rows = [];
    selectedRowId = null;
    detail = null;
    detailToolbar = [];
    activeSearch = '';
    activeViewGroup = 'active';
    pageNumber = 0;
    hasMore = false;

    if (searchInputEl) {
      searchInputEl.value = '';
    }

    // Vyzvedneme pending record (z dashboard widget klikání) — pokud existuje,
    // po načtení řádků nastavíme selectedRowId a fetchneme detail. Konzumace
    // hned vrátí store do nuly, aby se efekt neaplikoval podruhé.
    const pendingRecord = navigationStore.consumePendingRecordId();

    fetchMeta(viewerId);
    fetchRowsExplicit(viewerId, '', 'active', 0).then(() => {
      if (pendingRecord != null) {
        selectedRowId = pendingRecord;
        fetchDetail(pendingRecord);
      }
    });
  });
</script>

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

<div class="shpd-viewer">
  <ViewerToolbar actions={toolbarActions} onAction={handleToolbarAction} />

  <div class="shpd-viewer__body">
    <!-- Left panel: tabs + search + row list -->
    <div class="shpd-viewer__list-panel">

      <!-- Doc state tab bar (only shown when viewer supports viewGroups) -->
      {#if hasViewGroups && viewTabs().length > 0}
        <div class="shpd-viewer__tabs">
          {#each viewTabs() as vt (vt.id)}
            <button
              class="shpd-viewer__tab"
              class:shpd-viewer__tab--active={activeViewGroup === vt.id}
              onclick={() => handleTabClick(vt.id)}
              type="button"
            >
              {vt.label}
            </button>
          {/each}
        </div>
      {/if}

      <!-- Search -->
      <div class="shpd-viewer__search">
        <input
          class="shpd-viewer__search-input"
          type="text"
          placeholder={t('viewer.search.placeholder')}
          oninput={handleSearchInput}
          bind:this={searchInputEl}
        />
        {#if activeSearch}
          <button class="shpd-viewer__search-clear" onclick={handleSearchClear} aria-label={t('viewer.search.clear')}>×</button>
        {/if}
      </div>

      <!-- Row list -->
      <div
        class="shpd-viewer__rows"
        bind:this={listEl}
        onscroll={handleScroll}
      >
        {#if loadingRows && rows.length === 0}
          <div class="shpd-viewer__status">
            <span class="shpd-viewer__spinner"></span>
            <span>{t('common.loading')}</span>
          </div>
        {:else if rows.length === 0}
          <div class="shpd-viewer__status">
            {t('common.empty')}
          </div>
        {:else}
          {#each rows as row, i (row.id)}
            <ViewerRow
              {row}
              index={i + 1}
              selected={selectedRowId === row.id}
              onclick={() => handleRowClick(row)}
            />
          {/each}

          {#if loadingMore}
            <div class="shpd-viewer__status">
              <span class="shpd-viewer__spinner"></span>
              <span>{t('common.loading')}</span>
            </div>
          {:else if !hasMore && rows.length > 0}
            <div class="shpd-viewer__status shpd-viewer__status--end">
              {t('viewer.endOfList')}
            </div>
          {/if}
        {/if}
      </div>
    </div>

    <!-- Right panel: detail -->
    <div class="shpd-viewer__detail-panel">
      {#if selectedRowId != null}
        <ViewerDetail
          {detail}
          loading={detailLoading}
          onRefresh={handleDetailRefresh}
          onAction={handleDetailAction}
        />
      {:else}
        <div class="shpd-viewer__detail-empty">
          {t('viewer.selectRecord')}
        </div>
      {/if}
    </div>
  </div>
</div>

<!-- Reanalyze dialog — sdílená Modal komponenta (../ui/Modal.svelte). -->
<Modal
  title={t('viewer.reanalyze.title')}
  open={reanalyzeDialogOpen}
  onClose={closeReanalyzeDialog}
  width="520px"
>
  <p>{t('viewer.reanalyze.body', { states: t('viewer.reanalyze.replaceableStates') })}</p>

  <label class="shpd-reanalyze__label">
    {t('viewer.reanalyze.profileLabel')}
    <select class="shpd-reanalyze__input" bind:value={reanalyzeProfileNdx}>
      <option value="">{t('viewer.reanalyze.defaultProfile')}</option>
      {#each reanalyzeProfiles as p (p.ndx)}
        <option value={String(p.ndx)}>{p.name} ({p.profile_id})</option>
      {/each}
    </select>
  </label>

  {#snippet footer()}
    <Button label={t('common.cancel')} variant="secondary" size="sm" disabled={reanalyzeSubmitting} onclick={closeReanalyzeDialog} />
    <Button
      label={reanalyzeSubmitting ? t('viewer.reanalyze.submitting') : t('viewer.reanalyze.submit')}
      variant="primary"
      size="sm"
      disabled={reanalyzeSubmitting}
      onclick={submitReanalyze}
    />
  {/snippet}
</Modal>

<style>
  .shpd-viewer {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
  }

  .shpd-viewer__body {
    display: flex;
    flex: 1;
    overflow: hidden;
  }

  /* Left panel */
  .shpd-viewer__list-panel {
    width: 400px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    border-right: 1px solid var(--shpd-color-border);
    overflow: hidden;
  }

  /* Doc state tabs */
  .shpd-viewer__tabs {
    display: flex;
    border-bottom: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    flex-shrink: 0;
  }

  .shpd-viewer__tab {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
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

  .shpd-viewer__tab:hover {
    color: var(--shpd-color-text);
  }

  .shpd-viewer__tab--active {
    color: var(--shpd-color-primary);
    border-bottom-color: var(--shpd-color-primary);
    font-weight: 600;
  }

  /* Search */
  .shpd-viewer__search {
    position: relative;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
  }

  .shpd-viewer__search-input {
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    padding-right: 28px;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  .shpd-viewer__search-input:focus {
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }

  .shpd-viewer__search-clear {
    position: absolute;
    right: calc(var(--shpd-space-md) + 4px);
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: none;
    background: none;
    font-size: 1rem;
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    border-radius: var(--shpd-radius-sm);
  }

  .shpd-viewer__search-clear:hover {
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-viewer__rows {
    flex: 1;
    overflow-y: auto;
  }

  /* Right panel */
  .shpd-viewer__detail-panel {
    flex: 1;
    overflow: hidden;
    background-color: var(--shpd-color-bg);
  }

  .shpd-viewer__detail-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  /* Status messages */
  .shpd-viewer__status {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-md);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-viewer__status--end {
    opacity: 0.6;
    font-style: italic;
  }

  /* Reanalyze dialog — jen styly form polí uvnitř.
     Modální shell (overlay, header, body, footer) je ve sdílené Modal komponentě. */
  .shpd-reanalyze__label {
    display: block;
    margin-top: var(--shpd-space-md);
    font-weight: 500;
  }

  .shpd-reanalyze__input {
    display: block;
    margin-top: 6px;
    padding: 6px 10px;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    min-width: 280px;
    max-width: 100%;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
  }

  /* Spinner */
  .shpd-viewer__spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid var(--shpd-color-border);
    border-top-color: var(--shpd-color-primary);
    border-radius: 50%;
    animation: shpd-viewer-spin 0.7s linear infinite;
    flex-shrink: 0;
  }

  @keyframes shpd-viewer-spin {
    to { transform: rotate(360deg); }
  }
</style>
