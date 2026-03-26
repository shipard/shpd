<script>
  let { detail = null, loading = false } = $props();

  let activeTabId = $state(null);

  // Auto-select first tab when detail changes
  $effect(() => {
    if (detail?.tabs?.length > 0) {
      activeTabId = detail.tabs[0].id;
    } else {
      activeTabId = null;
    }
  });

  let activeContent = $derived(
    detail?.tabs?.find(t => t.id === activeTabId)?.content ?? null
  );
</script>

<div class="shpd-detail">
  {#if loading}
    <div class="shpd-detail__loading">
      <span class="shpd-detail__spinner"></span>
      <span>Načítám...</span>
    </div>
  {:else if detail?.tabs?.length > 0}
    <!-- Tab bar -->
    <div class="shpd-detail__tabs">
      {#each detail.tabs as tab (tab.id)}
        <button
          class="shpd-detail__tab"
          class:shpd-detail__tab--active={activeTabId === tab.id}
          onclick={() => activeTabId = tab.id}
          type="button"
        >
          {tab.label}
        </button>
      {/each}
    </div>

    <!-- Tab content -->
    <div class="shpd-detail__content">
      {#if activeContent?.type === 'properties'}
        {#each activeContent.groups ?? [] as group}
          <div class="shpd-detail__group">
            <h4 class="shpd-detail__group-title">{group.title}</h4>
            <dl class="shpd-detail__props">
              {#each group.items ?? [] as item}
                <div class="shpd-detail__prop">
                  <dt class="shpd-detail__prop-label">{item.label}</dt>
                  <dd class="shpd-detail__prop-value">{item.value}</dd>
                </div>
              {/each}
            </dl>
          </div>
        {/each}

      {:else if activeContent?.type === 'table'}
        <div class="shpd-detail__table-wrap">
          <table class="shpd-detail__table">
            <thead>
              <tr>
                {#each activeContent.columns ?? [] as col (col.id)}
                  <th class="shpd-detail__th">{col.label}</th>
                {/each}
              </tr>
            </thead>
            <tbody>
              {#if (activeContent.rows ?? []).length === 0}
                <tr>
                  <td class="shpd-detail__empty-cell" colspan={activeContent.columns?.length ?? 1}>
                    Žádné záznamy
                  </td>
                </tr>
              {:else}
                {#each activeContent.rows ?? [] as row}
                  <tr>
                    {#each activeContent.columns ?? [] as col (col.id)}
                      <td class="shpd-detail__td">{row[col.id] ?? '—'}</td>
                    {/each}
                  </tr>
                {/each}
              {/if}
            </tbody>
          </table>
        </div>

      {:else if activeContent?.type === 'html'}
        <div class="shpd-detail__html">
          {@html activeContent.html}
        </div>
      {/if}
    </div>
  {:else}
    <div class="shpd-detail__empty">
      Žádné detaily
    </div>
  {/if}
</div>

<style>
  .shpd-detail {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
  }

  .shpd-detail__loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xl);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-detail__spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid var(--shpd-color-border);
    border-top-color: var(--shpd-color-primary);
    border-radius: 50%;
    animation: shpd-detail-spin 0.7s linear infinite;
  }

  @keyframes shpd-detail-spin {
    to { transform: rotate(360deg); }
  }

  /* Tabs */
  .shpd-detail__tabs {
    display: flex;
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
  }

  .shpd-detail__tab {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border: none;
    border-bottom: 2px solid transparent;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    transition: color 0.15s, border-color 0.15s;
  }

  .shpd-detail__tab:hover {
    color: var(--shpd-color-text);
  }

  .shpd-detail__tab--active {
    color: var(--shpd-color-primary);
    border-bottom-color: var(--shpd-color-primary);
    font-weight: 600;
  }

  /* Content area */
  .shpd-detail__content {
    flex: 1;
    overflow-y: auto;
    padding: var(--shpd-space-md);
  }

  .shpd-detail__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  /* Properties content */
  .shpd-detail__group {
    margin-bottom: var(--shpd-space-lg);
  }

  .shpd-detail__group-title {
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text);
    padding-bottom: var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-detail__props {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--shpd-space-xs) var(--shpd-space-md);
  }

  .shpd-detail__prop {
    display: contents;
  }

  .shpd-detail__prop-label {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    white-space: nowrap;
  }

  .shpd-detail__prop-value {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  /* Table content */
  .shpd-detail__table-wrap {
    overflow-x: auto;
  }

  .shpd-detail__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-detail__th {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    text-align: left;
    font-weight: 600;
    color: var(--shpd-color-text);
    border-bottom: 2px solid var(--shpd-color-border);
    white-space: nowrap;
  }

  .shpd-detail__td {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border);
    color: var(--shpd-color-text);
  }

  .shpd-detail__empty-cell {
    padding: var(--shpd-space-md);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  /* HTML content */
  .shpd-detail__html {
    font-size: var(--shpd-font-size-sm);
    line-height: 1.5;
  }
</style>
