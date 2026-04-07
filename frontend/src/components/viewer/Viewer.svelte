<script>
  import { get } from '../../api/client.js';
  import ViewerRow from './ViewerRow.svelte';
  import ViewerDetail from './ViewerDetail.svelte';
  import ViewerToolbar from './ViewerToolbar.svelte';
  import FormDialog from '../form/FormDialog.svelte';

  let { tab } = $props();

  // --- Meta state ---
  let meta = $state(null);
  let loadingMeta = $state(true);

  // --- Doc state tabs ---
  let activeViewGroup = $state('active'); // 'active' | 'archive' | 'trash' | 'all'

  const VIEW_GROUP_LABELS = {
    active:  'Aktivní',
    archive: 'Archív',
    trash:   'Koš',
  };

  // Tabs to display: viewGroups from meta + always "Vše"
  let viewTabs = $derived(() => {
    const groups = meta?.viewGroups ?? [];
    const tabs = groups.map(vg => ({ id: vg, label: VIEW_GROUP_LABELS[vg] ?? vg }));
    tabs.push({ id: 'all', label: 'Vše' });
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

  function handleToolbarAction(actionId) {
    if (actionId === 'create') {
      editRecordId = null;
      formOpen = true;
    } else if (actionId === 'edit' && selectedRowId != null) {
      editRecordId = selectedRowId;
      formOpen = true;
    }
  }

  function handleFormClose() {
    formOpen = false;
    editRecordId = null;
  }

  function handleFormSaved() {
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, 0);
    if (selectedRowId != null) {
      fetchDetail(selectedRowId);
    }
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

    fetchMeta(viewerId);
    fetchRowsExplicit(viewerId, '', 'active', 0);
  });
</script>

{#if meta?.table}
  <FormDialog
    table={meta.table}
    recordId={editRecordId}
    open={formOpen}
    onClose={handleFormClose}
    onSaved={handleFormSaved}
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
          placeholder="Hledat..."
          oninput={handleSearchInput}
          bind:this={searchInputEl}
        />
        {#if activeSearch}
          <button class="shpd-viewer__search-clear" onclick={handleSearchClear} aria-label="Vymazat hledání">×</button>
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
            <span>Načítám...</span>
          </div>
        {:else if rows.length === 0}
          <div class="shpd-viewer__status">
            Žádné záznamy
          </div>
        {:else}
          {#each rows as row (row.id)}
            <ViewerRow
              {row}
              selected={selectedRowId === row.id}
              onclick={() => handleRowClick(row)}
            />
          {/each}

          {#if loadingMore}
            <div class="shpd-viewer__status">
              <span class="shpd-viewer__spinner"></span>
              <span>Načítám...</span>
            </div>
          {:else if !hasMore && rows.length > 0}
            <div class="shpd-viewer__status shpd-viewer__status--end">
              To je všechno
            </div>
          {/if}
        {/if}
      </div>
    </div>

    <!-- Right panel: detail -->
    <div class="shpd-viewer__detail-panel">
      {#if selectedRowId != null}
        <ViewerDetail {detail} loading={detailLoading} />
      {:else}
        <div class="shpd-viewer__detail-empty">
          Vyberte záznam
        </div>
      {/if}
    </div>
  </div>
</div>

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
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
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
    background-color: var(--shpd-color-bg-secondary);
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
