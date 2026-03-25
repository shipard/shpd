<script>
  import { get } from '../../api/client.js';

  let { tab } = $props();

  // --- State ---
  let columns = $state([]);
  let rows = $state([]);
  let total = $state(0);
  let limit = $state(20);
  let offset = $state(0);
  let sortColumn = $state(null);
  let sortDirection = $state('asc');
  let loading = $state(false);
  let error = $state(null);

  // --- Derived ---
  let startRecord = $derived(total === 0 ? 0 : offset + 1);
  let endRecord = $derived(Math.min(offset + limit, total));
  let hasPrev = $derived(offset > 0);
  let hasNext = $derived(offset + limit < total);

  // --- Helpers ---

  function isNumericType(type) {
    return ['int', 'smallint', 'bigint', 'numeric'].includes(type);
  }

  function formatDate(str) {
    const d = new Date(str);
    if (isNaN(d.getTime())) return str;
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    return `${dd}.${mm}.${d.getFullYear()}`;
  }

  function formatDatetime(str) {
    const d = new Date(str);
    if (isNaN(d.getTime())) return str;
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const hh = String(d.getHours()).padStart(2, '0');
    const min = String(d.getMinutes()).padStart(2, '0');
    return `${dd}.${mm}.${d.getFullYear()} ${hh}:${min}`;
  }

  function formatCell(value, col) {
    if (value === null || value === undefined) return '—';
    switch (col.type) {
      case 'int':
      case 'smallint':
      case 'bigint':
        return String(value);
      case 'numeric':
        return typeof value === 'number'
          ? value.toFixed(col.scale ?? 2)
          : String(value);
      case 'boolean':
        return value ? 'Ano' : 'Ne';
      case 'date':
        return typeof value === 'string' ? formatDate(value) : String(value);
      case 'datetime':
        return typeof value === 'string' ? formatDatetime(value) : String(value);
      default:
        return String(value);
    }
  }

  function buildDataPath() {
    let path = `/${tab.table}?limit=${limit}&offset=${offset}`;
    if (sortColumn) path += `&sort=${sortColumn}:${sortDirection}`;
    if (tab.filter) {
      for (const [key, value] of Object.entries(tab.filter)) {
        path += `&filter[${key}]=${value}`;
      }
    }
    return path;
  }

  // --- Data fetching ---

  async function fetchData() {
    loading = true;
    error = null;

    const result = await get(buildDataPath());
    loading = false;

    if (!result?.success) {
      error = result?.error?.message ?? 'Chyba při načítání dat';
      return;
    }

    rows = result.data ?? [];
    total = result.meta?.total ?? 0;
  }

  async function init() {
    loading = true;
    error = null;
    columns = [];
    rows = [];
    total = 0;

    const metaResult = await get(`/_meta/tables/${tab.table}`);
    if (!metaResult?.success) {
      error = metaResult?.error?.message ?? 'Chyba při načítání metadat';
      loading = false;
      return;
    }

    // Filter out primary key, password_hash, and json columns
    columns = (metaResult.data?.columns ?? []).filter(col => {
      if (col.primaryKey) return false;
      if (col.id === 'password_hash') return false;
      if (col.type === 'json') return false;
      return true;
    });

    await fetchData();
  }

  // --- Interaction handlers ---

  function handleSort(colId) {
    if (sortColumn === colId) {
      sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
      sortColumn = colId;
      sortDirection = 'asc';
    }
    offset = 0;
    fetchData();
  }

  function handlePageSize(e) {
    limit = Number(e.target.value);
    offset = 0;
    fetchData();
  }

  function handlePrev() {
    offset = Math.max(0, offset - limit);
    fetchData();
  }

  function handleNext() {
    offset = offset + limit;
    fetchData();
  }

  // Re-initialize whenever the tab changes (different table or filter)
  $effect(() => {
    // Read tab fields to register reactive dependencies
    const _table = tab.table;
    const _filter = tab.filter;

    offset = 0;
    sortColumn = null;
    sortDirection = 'asc';
    init();
  });
</script>

<div class="shpd-browser">
  {#if loading && columns.length === 0}
    <!-- Initial load spinner -->
    <div class="shpd-browser__loading">
      <span class="shpd-browser__spinner" aria-label="Načítám…"></span>
      <span class="shpd-browser__loading-text">Načítám…</span>
    </div>

  {:else if error}
    <div class="shpd-browser__error">
      <span class="shpd-browser__error-icon">⚠</span>
      {error}
    </div>

  {:else}
    <!-- Table wrapper enables horizontal scroll -->
    <div class="shpd-browser__table-wrap">
      <table class="shpd-browser__table">
        <thead>
          <tr>
            {#each columns as col (col.id)}
              <th
                class="shpd-browser__th"
                class:shpd-browser__th--numeric={isNumericType(col.type)}
                class:shpd-browser__th--sorted={sortColumn === col.id}
                onclick={() => handleSort(col.id)}
                aria-sort={sortColumn === col.id ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'}
              >
                <span class="shpd-browser__th-label">{col.name}</span>
                {#if sortColumn === col.id}
                  <span class="shpd-browser__sort-icon" aria-hidden="true">
                    {sortDirection === 'asc' ? '▲' : '▼'}
                  </span>
                {/if}
              </th>
            {/each}
          </tr>
        </thead>

        <tbody>
          {#if loading}
            <!-- Reload spinner row shown during sort/pagination refetch -->
            <tr>
              <td colspan={columns.length} class="shpd-browser__loading-row">
                <span class="shpd-browser__spinner" aria-label="Načítám…"></span>
              </td>
            </tr>
          {:else if rows.length === 0}
            <tr>
              <td colspan={columns.length} class="shpd-browser__empty-row">
                Žádné záznamy
              </td>
            </tr>
          {:else}
            {#each rows as row}
              <tr class="shpd-browser__row">
                {#each columns as col (col.id)}
                  <td
                    class="shpd-browser__td"
                    class:shpd-browser__td--numeric={isNumericType(col.type)}
                  >
                    {formatCell(row[col.id], col)}
                  </td>
                {/each}
              </tr>
            {/each}
          {/if}
        </tbody>
      </table>
    </div>

    <!-- Pagination bar -->
    <div class="shpd-browser__pagination">
      <span class="shpd-browser__pagination-info">
        Zobrazeno {startRecord}–{endRecord} z {total} záznamů
      </span>

      <div class="shpd-browser__pagination-controls">
        <button
          class="shpd-browser__pagination-btn"
          disabled={!hasPrev || loading}
          onclick={handlePrev}
        >← Předchozí</button>

        <button
          class="shpd-browser__pagination-btn"
          disabled={!hasNext || loading}
          onclick={handleNext}
        >Další →</button>
      </div>

      <label class="shpd-browser__page-size-label">
        Na stránku:
        <select
          class="shpd-browser__page-size"
          value={limit}
          onchange={handlePageSize}
        >
          <option value="20">20</option>
          <option value="50">50</option>
          <option value="100">100</option>
        </select>
      </label>
    </div>
  {/if}
</div>

<style>
  .shpd-browser {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
  }

  /* Loading — initial full-area spinner */
  .shpd-browser__loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    flex: 1;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-browser__loading-text {
    color: var(--shpd-color-text-secondary);
  }

  /* CSS-only spinner */
  .shpd-browser__spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid var(--shpd-color-border);
    border-top-color: var(--shpd-color-primary);
    border-radius: 50%;
    animation: shpd-spin 0.7s linear infinite;
    flex-shrink: 0;
  }

  @keyframes shpd-spin {
    to { transform: rotate(360deg); }
  }

  /* Error */
  .shpd-browser__error {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-lg);
    color: var(--shpd-color-danger);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-browser__error-icon {
    font-size: var(--shpd-font-size-lg);
  }

  /* Table wrapper — horizontal scroll */
  .shpd-browser__table-wrap {
    flex: 1;
    overflow: auto;
  }

  .shpd-browser__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-sm);
    white-space: nowrap;
  }

  /* Header */
  .shpd-browser__th {
    position: sticky;
    top: 0;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    background-color: var(--shpd-color-bg);
    border-bottom: 2px solid var(--shpd-color-border);
    text-align: left;
    font-weight: 600;
    color: var(--shpd-color-text);
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
  }

  .shpd-browser__th:hover {
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-browser__th--sorted {
    color: var(--shpd-color-primary);
  }

  .shpd-browser__th--numeric {
    text-align: right;
  }

  .shpd-browser__th-label {
    margin-right: var(--shpd-space-xs);
  }

  .shpd-browser__sort-icon {
    font-size: 0.65rem;
    vertical-align: middle;
    color: var(--shpd-color-primary);
  }

  /* Data rows */
  .shpd-browser__row:hover {
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-browser__td {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    color: var(--shpd-color-text);
    vertical-align: middle;
  }

  .shpd-browser__td--numeric {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  /* Empty / loading row inside tbody */
  .shpd-browser__empty-row,
  .shpd-browser__loading-row {
    padding: var(--shpd-space-xl) var(--shpd-space-md);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-browser__loading-row {
    height: 80px;
    vertical-align: middle;
  }

  /* Pagination bar */
  .shpd-browser__pagination {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-top: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    flex-shrink: 0;
    flex-wrap: wrap;
  }

  .shpd-browser__pagination-info {
    flex: 1;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-browser__pagination-controls {
    display: flex;
    gap: var(--shpd-space-xs);
  }

  .shpd-browser__pagination-btn {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    transition: background-color 0.15s, border-color 0.15s;
  }

  .shpd-browser__pagination-btn:hover:not(:disabled) {
    background-color: var(--shpd-color-bg-secondary);
    border-color: var(--shpd-color-primary);
  }

  .shpd-browser__pagination-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }

  .shpd-browser__page-size-label {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-browser__page-size {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text);
  }
</style>
