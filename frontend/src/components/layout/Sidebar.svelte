<script>
  import { get } from '../../api/client.js';
  import { logout } from '../../api/auth.js';
  import { authStore } from '../../stores/auth.svelte.js';
  import { onMount } from 'svelte';
  import Icon from '../ui/Icon.svelte';
  import {
    iconCollapse,
    iconExpand,
    iconLogout,
    iconChevronDown,
    iconChevronRight,
    iconSpinner,
    resolveIcon,
  } from '../../icons.js';

  let { onNavigate, activeId = null, onLogout } = $props();

  // Collapsed state
  let collapsed = $state(false);
  let hovered = $state(false);

  // Sidebar is visually expanded when not collapsed OR when hovered
  let expanded_sidebar = $derived(!collapsed || hovered);

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
    onNavigate?.({ id: item.id, label: item.label, type: item.type, table: item.table, viewerId: item.viewerId, filter: item.filter });
  }

  async function handleLogout() {
    await logout();
    authStore.clearAuth();
    onLogout?.();
  }

  function toggleCollapse() {
    collapsed = !collapsed;
    if (!collapsed) hovered = false;
  }

  function handleMouseEnter() {
    if (collapsed) hovered = true;
  }

  function handleMouseLeave() {
    if (collapsed) hovered = false;
  }
</script>

<nav
  class="shpd-sidebar"
  class:shpd-sidebar--collapsed={collapsed}
  class:shpd-sidebar--hover-expanded={collapsed && hovered}
  onmouseenter={handleMouseEnter}
  onmouseleave={handleMouseLeave}
>
  <div class="shpd-sidebar__header">
    {#if expanded_sidebar}
      <span class="shpd-sidebar__logo">Shipard</span>
    {/if}
    <button class="shpd-sidebar__toggle" onclick={toggleCollapse} title={collapsed ? 'Rozbalit menu' : 'Sbalit menu'}>
      <Icon icon={collapsed ? iconExpand : iconCollapse} size="sm" />
    </button>
  </div>

  <div class="shpd-sidebar__nav">
  {#if loading}
    <div class="shpd-sidebar__status">
      <Icon icon={iconSpinner} spin size="sm" />
      {#if expanded_sidebar}<span>Načítám…</span>{/if}
    </div>
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
        <span class="shpd-sidebar__chevron">
          <Icon icon={expanded.has(group.id) ? iconChevronDown : iconChevronRight} size="xs" />
        </span>
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
                  <span class="shpd-sidebar__chevron">
                    <Icon icon={expanded.has(child.id) ? iconChevronDown : iconChevronRight} size="xs" />
                  </span>
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
                          <Icon icon={resolveIcon(leaf.icon)} size="sm" />
                          <span>{leaf.label}</span>
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
                  <Icon icon={resolveIcon(child.icon)} size="sm" />
                  <span>{child.label}</span>
                </button>
              </li>
            {/if}
          {/each}
        </ul>
      {/if}
    </div>
    {/each}
  </div>

  <div class="shpd-sidebar__footer">
    {#if expanded_sidebar}
      <span class="shpd-sidebar__username">{authStore.user?.full_name ?? ''}</span>
      <button class="shpd-sidebar__logout" onclick={handleLogout} title="Odhlásit">
        <Icon icon={iconLogout} size="sm" />
        <span>Odhlásit</span>
      </button>
    {:else}
      <span class="shpd-sidebar__avatar" title={authStore.user?.full_name ?? ''}>
        {(authStore.user?.full_name ?? '?').charAt(0)}
      </span>
    {/if}
  </div>
</nav>

<style>
  .shpd-sidebar {
    display: flex;
    flex-direction: column;
    width: var(--shpd-sidebar-width);
    height: 100%;
    background-color: var(--shpd-color-bg-sidebar);
    color: var(--shpd-color-text-sidebar);
    flex-shrink: 0;
    transition: width 0.2s ease;
    overflow: hidden;
  }

  .shpd-sidebar--collapsed {
    width: var(--shpd-sidebar-width-collapsed);
  }

  .shpd-sidebar--hover-expanded {
    width: var(--shpd-sidebar-width);
    position: absolute;
    top: 0;
    left: 0;
    z-index: 100;
    box-shadow: var(--shpd-shadow-lg);
  }

  .shpd-sidebar__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: var(--shpd-header-height);
    padding: 0 var(--shpd-space-md);
    flex-shrink: 0;
    border-bottom: 1px solid rgb(255 255 255 / 0.08);
  }

  .shpd-sidebar--collapsed:not(.shpd-sidebar--hover-expanded) .shpd-sidebar__header {
    justify-content: center;
    padding: 0;
  }

  .shpd-sidebar__logo {
    font-size: var(--shpd-font-size-lg);
    font-weight: 700;
    color: var(--shpd-color-text-sidebar);
  }

  .shpd-sidebar__nav {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
  }

  .shpd-sidebar--collapsed:not(.shpd-sidebar--hover-expanded) .shpd-sidebar__nav {
    display: none;
  }

  .shpd-sidebar__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--shpd-space-md);
    flex-shrink: 0;
    border-top: 1px solid rgb(255 255 255 / 0.08);
  }

  .shpd-sidebar--collapsed:not(.shpd-sidebar--hover-expanded) .shpd-sidebar__footer {
    justify-content: center;
    padding: var(--shpd-space-sm);
  }

  .shpd-sidebar__toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    color: rgb(148 163 184);
    border-radius: var(--shpd-radius-sm);
    transition: color 0.15s, background-color 0.15s;
    flex-shrink: 0;
  }

  .shpd-sidebar__toggle:hover {
    color: var(--shpd-color-text-sidebar);
    background-color: rgb(255 255 255 / 0.07);
  }

  .shpd-sidebar__avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text-sidebar);
    background-color: rgb(255 255 255 / 0.12);
    border-radius: 50%;
  }

  .shpd-sidebar__username {
    font-size: var(--shpd-font-size-sm);
    color: rgb(148 163 184);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-sidebar__logout {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    color: rgb(148 163 184);
    border: 1px solid rgb(255 255 255 / 0.15);
    border-radius: var(--shpd-radius-sm);
    transition: color 0.15s, border-color 0.15s;
    flex-shrink: 0;
  }

  .shpd-sidebar__logout:hover {
    color: var(--shpd-color-danger);
    border-color: var(--shpd-color-danger);
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
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
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
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
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
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: rgb(148 163 184);
  }

  .shpd-sidebar__status--error {
    color: var(--shpd-color-danger, #ef4444);
  }
</style>
