<script>
  import { get } from '../../api/client.js';
  import { onMount } from 'svelte';

  let { onNavigate, activeId = null } = $props();

  // Navigation tree loaded from server API
  let navTree = $state([]);
  let loading = $state(true);
  let error = $state(null);

  // Track expanded state per group id
  let expanded = $state(new Set());

  onMount(async () => {
    try {
      const response = await get('/_ui/navigation');
      if (response === null) {
        error = 'Nepřihlášen';
        return;
      }
      if (!response.success) {
        error = response.error?.message ?? 'Nepodařilo se načíst navigaci';
        return;
      }
      navTree = response.data;
      // Expand all top-level groups by default
      expanded = new Set(navTree.map(g => g.id));
    } catch {
      error = 'Nepodařilo se načíst navigaci';
    } finally {
      loading = false;
    }
  });

  function toggleGroup(id) {
    if (expanded.has(id)) {
      expanded.delete(id);
    } else {
      expanded.add(id);
    }
    // Trigger reactivity — reassign
    expanded = new Set(expanded);
  }

  function handleItemClick(item) {
    onNavigate?.({ id: item.id, label: item.label, type: item.type, table: item.table, filter: item.filter });
  }
</script>

<nav class="shpd-sidebar">
  {#if loading}
    <div class="shpd-sidebar__status">Načítám…</div>
  {:else if error}
    <div class="shpd-sidebar__status shpd-sidebar__status--error">{error}</div>
  {/if}

  {#each navTree as group}
    <div class="shpd-sidebar__group">
      <button
        class="shpd-sidebar__group-header"
        onclick={() => toggleGroup(group.id)}
      >
        <span class="shpd-sidebar__group-label">{group.label}</span>
        <span class="shpd-sidebar__chevron" class:shpd-sidebar__chevron--open={expanded.has(group.id)}>›</span>
      </button>

      {#if expanded.has(group.id)}
        <ul class="shpd-sidebar__list">
          {#each group.children as child}
            {#if child.children}
              <!-- Nested sub-group -->
              <li class="shpd-sidebar__subgroup">
                <button
                  class="shpd-sidebar__subgroup-header"
                  onclick={() => toggleGroup(child.id)}
                >
                  <span>{child.label}</span>
                  <span class="shpd-sidebar__chevron" class:shpd-sidebar__chevron--open={expanded.has(child.id)}>›</span>
                </button>

                {#if expanded.has(child.id)}
                  <ul class="shpd-sidebar__list shpd-sidebar__list--nested">
                    {#each child.children as leaf}
                      <li>
                        <button
                          class="shpd-sidebar__item"
                          class:shpd-sidebar__item--active={activeId === leaf.id}
                          onclick={() => handleItemClick(leaf)}
                        >
                          {leaf.label}
                        </button>
                      </li>
                    {/each}
                  </ul>
                {/if}
              </li>
            {:else}
              <li>
                <button
                  class="shpd-sidebar__item"
                  class:shpd-sidebar__item--active={activeId === child.id}
                  onclick={() => handleItemClick(child)}
                >
                  {child.label}
                </button>
              </li>
            {/if}
          {/each}
        </ul>
      {/if}
    </div>
  {/each}
</nav>

<style>
  .shpd-sidebar {
    width: var(--shpd-sidebar-width);
    height: 100%;
    background-color: var(--shpd-color-bg-sidebar);
    color: var(--shpd-color-text-sidebar);
    overflow-y: auto;
    flex-shrink: 0;
  }

  .shpd-sidebar__group {
    padding-top: var(--shpd-space-sm);
  }

  .shpd-sidebar__group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: rgb(148 163 184);
    transition: color 0.15s;
  }

  .shpd-sidebar__group-header:hover {
    color: var(--shpd-color-text-sidebar);
  }

  .shpd-sidebar__group-label {
    flex: 1;
    text-align: left;
  }

  .shpd-sidebar__chevron {
    display: inline-block;
    font-style: normal;
    transform: rotate(90deg);
    transition: transform 0.2s;
    font-size: 1rem;
    line-height: 1;
  }

  .shpd-sidebar__chevron--open {
    transform: rotate(270deg);
  }

  .shpd-sidebar__list {
    padding: var(--shpd-space-xs) 0;
  }

  .shpd-sidebar__list--nested {
    padding-left: var(--shpd-space-md);
  }

  .shpd-sidebar__subgroup-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: rgb(148 163 184);
    transition: color 0.15s;
  }

  .shpd-sidebar__subgroup-header:hover {
    color: var(--shpd-color-text-sidebar);
  }

  .shpd-sidebar__item {
    display: block;
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar);
    text-align: left;
    border-radius: var(--shpd-radius-sm);
    transition: background-color 0.15s;
    opacity: 0.8;
  }

  .shpd-sidebar__item:hover {
    background-color: rgb(255 255 255 / 0.07);
    opacity: 1;
  }

  .shpd-sidebar__item--active {
    background-color: var(--shpd-color-primary);
    opacity: 1;
  }

  .shpd-sidebar__item--active:hover {
    background-color: var(--shpd-color-primary-hover);
  }

  .shpd-sidebar__status {
    padding: var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: rgb(148 163 184);
  }

  .shpd-sidebar__status--error {
    color: var(--shpd-color-danger, #ef4444);
  }
</style>
