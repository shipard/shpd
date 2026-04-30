<script>
  import { get } from '../../api/client.js';
  import { logout } from '../../api/auth.js';
  import { authStore } from '../../stores/auth.svelte.js';
  import { themeStore } from '../../stores/theme.svelte.js';
  import { onMount } from 'svelte';
  import Icon from '../ui/Icon.svelte';
  import {
    iconCollapse,
    iconExpand,
    iconLogout,
    iconChevronDown,
    iconChevronUp,
    iconChevronRight,
    iconSpinner,
    iconSettings,
    iconThemeLight,
    iconThemeDark,
    iconThemeAuto,
    iconConfirm,
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
    try {
      await logout();
    } catch (err) {
      console.warn('Logout API call failed (continuing):', err);
    }
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

  // --- User menu (dropdown nad uživatelským jménem v patce) ---
  let userMenuOpen = $state(false);
  let userMenuRoot = $state(null);

  function toggleUserMenu() {
    userMenuOpen = !userMenuOpen;
  }

  function closeUserMenu() {
    userMenuOpen = false;
  }

  function handleSettings() {
    closeUserMenu();
    // TODO: implementovat navigaci na nastavení účtu, až bude stránka existovat
  }

  // Položky vzhledu — záměrně nezavíráme menu po kliku, aby si uživatel
  // mohl rychle vyzkoušet více variant. Menu se zavře žě kliknutím mimo.
  const themeOptions = [
    { value: 'light', label: 'Světlý', icon: iconThemeLight },
    { value: 'dark',  label: 'Tmavý',  icon: iconThemeDark },
    { value: 'auto',  label: 'Auto',    icon: iconThemeAuto },
  ];

  function handleThemeChange(value) {
    themeStore.setMode(value);
  }

  function handleLogoutFromMenu() {
    // Záměrně nezavíráme menu předem — celý sidebar zmizí sám,
    // jakmile authStore.clearAuth() přepne aplikaci na LoginScreen.
    //
    // Proč ne dříve: closeUserMenu() v kombinaci s document click
    // listenerem způsobovalo, že click na "Odhlásit" se po render
    // flushi probublal jako klik mimo menu (target byl detached element,
    // contains() vrátilo false), listener z effectu se odregistroval
    // dříve než doběhl logout flow, a výsledek byl: menu se zavře,
    // logout se ztratí v půl cesty.
    handleLogout();
  }

  // Zavři menu při kliknutí mimo něj nebo při Escape.
  $effect(() => {
    if (!userMenuOpen) return;

    function onDocClick(e) {
      if (userMenuRoot && !userMenuRoot.contains(e.target)) {
        closeUserMenu();
      }
    }
    function onKeyDown(e) {
      if (e.key === 'Escape') closeUserMenu();
    }

    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('click', onDocClick);
      document.removeEventListener('keydown', onKeyDown);
    };
  });

  // Při sbalení sidebaru zavři otevřené menu (jinak by zůstalo viset).
  $effect(() => {
    if (collapsed && !hovered) closeUserMenu();
  });
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

  <div class="shpd-sidebar__footer" bind:this={userMenuRoot}>
    <button
      class="shpd-sidebar__user-button"
      class:shpd-sidebar__user-button--collapsed={!expanded_sidebar}
      class:shpd-sidebar__user-button--open={userMenuOpen}
      onclick={toggleUserMenu}
      title={authStore.user?.full_name ?? ''}
      aria-haspopup="menu"
      aria-expanded={userMenuOpen}
    >
      <span class="shpd-sidebar__avatar">
        {(authStore.user?.full_name ?? '?').charAt(0)}
      </span>
      {#if expanded_sidebar}
        <span class="shpd-sidebar__username">{authStore.user?.full_name ?? ''}</span>
        <span class="shpd-sidebar__user-chevron">
          <Icon icon={userMenuOpen ? iconChevronDown : iconChevronUp} size="xs" />
        </span>
      {/if}
    </button>

    {#if userMenuOpen}
      <div
        class="shpd-sidebar__user-menu"
        class:shpd-sidebar__user-menu--side={!expanded_sidebar}
        role="menu"
      >
        <button class="shpd-sidebar__user-menu-item" onclick={handleSettings} role="menuitem">
          <Icon icon={iconSettings} size="sm" />
          <span>Nastavení účtu</span>
        </button>

        <div class="shpd-sidebar__user-menu-divider"></div>
        <div class="shpd-sidebar__user-menu-label">Vzhled</div>
        {#each themeOptions as opt}
          <button
            class="shpd-sidebar__user-menu-item"
            class:shpd-sidebar__user-menu-item--active={themeStore.mode === opt.value}
            onclick={() => handleThemeChange(opt.value)}
            role="menuitemradio"
            aria-checked={themeStore.mode === opt.value}
          >
            <Icon icon={opt.icon} size="sm" />
            <span class="shpd-sidebar__user-menu-item-label">{opt.label}</span>
            {#if themeStore.mode === opt.value}
              <span class="shpd-sidebar__user-menu-item-check">
                <Icon icon={iconConfirm} size="xs" />
              </span>
            {/if}
          </button>
        {/each}

        <div class="shpd-sidebar__user-menu-divider"></div>
        <button class="shpd-sidebar__user-menu-item" onclick={handleLogoutFromMenu} role="menuitem">
          <Icon icon={iconLogout} size="sm" />
          <span>Odhlásit</span>
        </button>
      </div>
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
    border-bottom: 1px solid var(--shpd-color-bg-sidebar-border);
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
    position: relative; /* anchor pro user menu dropdown */
    padding: var(--shpd-space-sm);
    flex-shrink: 0;
    border-top: 1px solid var(--shpd-color-bg-sidebar-border);
  }

  /* User button — celá řádka v patce sidebaru je klikatelná.
   * Otevírá dropdown s položkami Nastavení účtu / Odhlásit.
   * Layout: avatar (32px) | jméno (flex 1) | chevron. */
  .shpd-sidebar__user-button {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    background: transparent;
    border: none;
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-sidebar);
    cursor: pointer;
    text-align: left;
    transition: background-color 0.15s;
  }

  .shpd-sidebar__user-button:hover,
  .shpd-sidebar__user-button--open {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  .shpd-sidebar__user-button--collapsed {
    justify-content: center;
    padding: var(--shpd-space-xs);
  }

  .shpd-sidebar__user-chevron {
    display: inline-flex;
    align-items: center;
    color: var(--shpd-color-text-sidebar-muted);
    flex-shrink: 0;
  }

  /* Dropdown menu — otevírá se nahoru z patky.
   * V rozbaleném sidebaru: full width nad tlačítkem.
   * Ve sbaleném: vedle sidebaru vpravo (varianta --side).
   *
   * Barva pozadí navazuje na sidebar (modrá), aby dropdown
   * vizuálně patřil k němu — jen o stupeň světlejší, aby šlo
   * poznat kde sidebar končí a dropdown začíná. */
  .shpd-sidebar__user-menu {
    position: absolute;
    bottom: calc(100% - 1px);
    left: var(--shpd-space-sm);
    right: var(--shpd-space-sm);
    background-color: var(--shpd-color-bg-sidebar-elevated);
    color: var(--shpd-color-text-sidebar);
    border: 1px solid var(--shpd-color-bg-sidebar-border);
    border-radius: var(--shpd-radius-md);
    box-shadow: var(--shpd-shadow-md);
    padding: var(--shpd-space-xs);
    z-index: 200;
  }

  .shpd-sidebar__user-menu--side {
    bottom: var(--shpd-space-sm);
    left: calc(100% - 4px);
    right: auto;
    min-width: 200px;
  }

  .shpd-sidebar__user-menu-item {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    background: transparent;
    border: none;
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-sidebar);
    font-size: var(--shpd-font-size-sm);
    text-align: left;
    cursor: pointer;
    transition: background-color 0.12s;
  }

  .shpd-sidebar__user-menu-item:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  /* Aktivní varianta položky (zatím používá jen sekce Vzhled).
   * Lehké zvýraznění pozadí + zatžítko vpravo říká "toto je vybraná volba". */
  .shpd-sidebar__user-menu-item--active {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  .shpd-sidebar__user-menu-item-label {
    flex: 1;
  }

  .shpd-sidebar__user-menu-item-check {
    display: inline-flex;
    align-items: center;
    color: var(--shpd-color-accent);
    flex-shrink: 0;
  }

  /* Sekce label — nadpis skupiny položek v dropdownu ("Vzhled"). */
  .shpd-sidebar__user-menu-label {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--shpd-color-text-sidebar-muted);
  }

  .shpd-sidebar__user-menu-divider {
    height: 1px;
    margin: var(--shpd-space-xs) 0;
    background-color: var(--shpd-color-bg-sidebar-border);
  }

  .shpd-sidebar__toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    color: var(--shpd-color-text-sidebar-muted);
    border-radius: var(--shpd-radius-sm);
    transition: color 0.15s, background-color 0.15s;
    flex-shrink: 0;
  }

  .shpd-sidebar__toggle:hover {
    color: var(--shpd-color-text-sidebar);
    background-color: var(--shpd-color-bg-sidebar-hover);
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
    background-color: var(--shpd-color-accent);
    border-radius: 50%;
    flex-shrink: 0;
  }

  .shpd-sidebar__username {
    flex: 1;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
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
    color: var(--shpd-color-text-sidebar-muted);
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
    color: var(--shpd-color-text-sidebar-muted);
    transition: color 0.15s;
  }

  .shpd-sidebar__subgroup-header:hover {
    color: var(--shpd-color-text-sidebar);
  }

  .shpd-sidebar__item {
    position: relative;
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar);
    text-align: left;
    border-radius: var(--shpd-radius-sm);
    transition: background-color 0.15s, opacity 0.15s;
    opacity: 0.85;
  }

  .shpd-sidebar__item:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
    opacity: 1;
  }

  .shpd-sidebar__item--active {
    background-color: var(--shpd-color-primary);
    opacity: 1;
    font-weight: 500;
  }

  .shpd-sidebar__item--active:hover {
    background-color: var(--shpd-color-primary-hover);
  }

  /* Levý oranžový proužek u aktivní položky — zvýrazňuje pozici
     uživatele a propojuje sidebar s brand accent barvou. */
  .shpd-sidebar__item--active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 4px;
    bottom: 4px;
    width: 3px;
    border-radius: 0 2px 2px 0;
    background-color: var(--shpd-color-accent);
  }

  .shpd-sidebar__status {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar-muted);
  }

  .shpd-sidebar__status--error {
    color: var(--shpd-color-danger, #ef4444);
  }
</style>
