<script>
  // Universal entity picker — search + select from any CRUD-exposed table.
  //
  // Phase 3a: built standalone, no production caller. Phase 3b will mount
  // it under canCreate / ambiguous status badges in DocumentExchangePreview
  // to drive `_resolve.*.userAction` decisions.
  //
  // Backend lookup: the existing CrudController list endpoint with a
  // single LIKE filter on `searchFields[0]`. The endpoint does not (yet)
  // OR multiple fields, so the picker focuses on the primary search
  // column — name in most cases. Adding multi-field OR is a backend
  // follow-up if 3b proves it useful.
  //
  // Props:
  //   open              boolean
  //   tableName         e.g. 'base_persons_persons'
  //   searchPlaceholder localized placeholder
  //   searchFields      e.g. ['full_name'] — first item drives the filter
  //   displayPattern    (row) => string  — how each result row renders
  //   onSelect          (row) => void    — called on click; caller decides close
  //   onClose           () => void

  import Modal from './Modal.svelte';
  import { get } from '../../api/client.js';
  import { t } from '../../i18n/index.js';

  let {
    open = false,
    tableName = '',
    searchPlaceholder = '',
    searchFields = ['name'],
    displayPattern = (row) => row.name ?? row.full_name ?? `#${row.id}`,
    onSelect = () => {},
    onClose = () => {},
  } = $props();

  let searchTerm = $state('');
  let results = $state([]);
  let loading = $state(false);
  let debounceTimer = $state(null);
  let lastError = $state(null);

  // Reset state every time the picker is reopened.
  $effect(() => {
    if (open) {
      searchTerm = '';
      results = [];
      lastError = null;
    } else if (debounceTimer !== null) {
      clearTimeout(debounceTimer);
      debounceTimer = null;
    }
  });

  // Debounced search whenever the term changes while open.
  $effect(() => {
    if (!open) return;
    const term = searchTerm;
    if (debounceTimer !== null) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      void doSearch(term);
    }, 300);
    return () => {
      if (debounceTimer !== null) {
        clearTimeout(debounceTimer);
        debounceTimer = null;
      }
    };
  });

  async function doSearch(term) {
    if (tableName === '') {
      results = [];
      return;
    }
    loading = true;
    lastError = null;
    try {
      const params = new URLSearchParams();
      params.set('limit', '10');
      // Empty term → list first 10 records without filter, so the picker
      // is useful for browsing too.
      const trimmed = term.trim();
      if (trimmed !== '' && searchFields.length > 0) {
        params.set(`filter[${searchFields[0]}]`, `like:${trimmed}`);
      }
      const result = await get(`/${tableName}?${params.toString()}`);
      if (result?.success) {
        // CrudController returns array directly in data, with pagination meta.
        results = Array.isArray(result.data) ? result.data : (result.data?.rows ?? []);
      } else {
        results = [];
        lastError = result?.error?.message ?? 'Unknown error';
      }
    } catch (e) {
      results = [];
      lastError = e instanceof Error ? e.message : String(e);
    } finally {
      loading = false;
    }
  }

  function handleSelect(row) {
    onSelect(row);
    onClose();
  }

  let placeholder = $derived(searchPlaceholder || t('picker.search.placeholder'));
</script>

<Modal title={t('picker.search.placeholder')} {open} {onClose} width="600px">
  <div class="shpd-picker">
    <input
      type="text"
      class="shpd-picker__input"
      placeholder={placeholder}
      bind:value={searchTerm}
    />

    {#if loading}
      <div class="shpd-picker__status">{t('picker.results.loading')}</div>
    {:else if lastError !== null}
      <div class="shpd-picker__status shpd-picker__status--error">{lastError}</div>
    {:else if results.length === 0}
      <div class="shpd-picker__status">{t('picker.results.empty')}</div>
    {:else}
      <ul class="shpd-picker__results" role="listbox">
        {#each results as row (row.id)}
          <li class="shpd-picker__item" role="option">
            <button
              type="button"
              class="shpd-picker__item-button"
              onclick={() => handleSelect(row)}
            >
              {displayPattern(row)}
            </button>
          </li>
        {/each}
      </ul>
    {/if}
  </div>

  {#snippet footer()}
    <button type="button" class="shpd-picker__cancel" onclick={onClose}>
      {t('picker.actions.cancel')}
    </button>
  {/snippet}
</Modal>

<style>
  .shpd-picker {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm);
    min-height: 320px;
  }

  .shpd-picker__input {
    width: 100%;
    padding: var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: 4px;
    font-size: 1rem;
    background: var(--shpd-color-surface);
    color: var(--shpd-color-text);
  }

  .shpd-picker__input:focus {
    outline: 2px solid var(--shpd-color-primary);
    outline-offset: -2px;
    border-color: var(--shpd-color-primary);
  }

  .shpd-picker__status {
    padding: var(--shpd-space-md);
    text-align: center;
    color: var(--shpd-color-text-muted);
    font-size: 0.875rem;
  }

  .shpd-picker__status--error {
    color: var(--shpd-color-danger);
  }

  .shpd-picker__results {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    border: 1px solid var(--shpd-color-border);
    border-radius: 4px;
    overflow: hidden;
  }

  .shpd-picker__item {
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-picker__item:last-child {
    border-bottom: 0;
  }

  .shpd-picker__item-button {
    display: block;
    width: 100%;
    text-align: left;
    background: transparent;
    border: 0;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    cursor: pointer;
    font-size: 0.875rem;
    color: var(--shpd-color-text);
  }

  .shpd-picker__item-button:hover,
  .shpd-picker__item-button:focus {
    background: var(--shpd-color-primary-soft);
    outline: none;
  }

  .shpd-picker__cancel {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    background: var(--shpd-color-surface);
    color: var(--shpd-color-text);
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.875rem;
  }

  .shpd-picker__cancel:hover {
    background: var(--shpd-color-primary-soft);
  }
</style>
